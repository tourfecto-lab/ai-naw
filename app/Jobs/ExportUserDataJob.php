<?php

/**
 * Tourfecto - Export User Data Job
 * @version 1.0.0
 *
 * نطاق التصدير: بيانات الحساب/الملف الشخصي الأساسية فقط (Core Account
 * Data) - مش "كل بيانات المستخدم" في المشروع كله. اقتصرت عمدًا على
 * الجداول اللي فحصتها وتأكدت من بنيتها الحقيقية بنفسي في هذه الجلسة:
 * users, login_history, oauth_accounts, user_refresh_tokens. لم أضمّن
 * جداول العمل الأخرى (websites, subscriptions, crm_leads, invoices,
 * chat...) لأنني لم أتحقق من بنيتها الفعلية بشكل مباشر، وهذا المشروع
 * أثبت بالفعل (راجع PROFILE_CHANGELOG.md) أن database/schema.sql
 * المرفق قد ينحرف عن الواقع الفعلي - تخمين أعمدة جداول لم تُتحقق منها
 * هو بالضبط نوع الخطأ اللي كنا بنتجنبه طول التسليم ده. توسعة النطاق
 * ليشمل بيانات الأعمال الكاملة يحتاج مراجعة منفصلة لكل جدول أولًا.
 *
 * لا تُصدَّر أي أسرار: لا password_hash، لا api_token، لا
 * two_factor_secret/recovery_codes، ولا أي Access/Refresh Token خام.
 */
class ExportUserDataJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $requestId = (int) ($payload['export_request_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);

        $db = Database::getInstance();
        $request = $db->query('SELECT * FROM data_export_requests WHERE id = ? LIMIT 1', [$requestId]);
        if (empty($request) || (int)$request[0]['user_id'] !== $userId) {
            throw new Exception("Data export request #{$requestId} غير موجود");
        }

        if($request[0]['status']==='ready' && !empty($request[0]['file_path']) && is_file($request[0]['file_path']))return;
        if(!$db->exec("UPDATE data_export_requests SET status = 'processing' WHERE id = ?", [$requestId]))throw new RuntimeException('Cannot claim data export');

        try {
            $userModel = new User();
            $user = $userModel->find($userId);
            if (!$user) {
                throw new Exception("User #{$userId} غير موجود");
            }

            // toArray() بيحذف الحقول المحمية تلقائيًا (راجع $hidden في
            // User.php: password_hash, api_token, two_factor_secret,
            // two_factor_recovery_codes) - نفس الحماية المستخدمة في كل
            // مكان تاني في المشروع، مش منطق جديد مختلف هنا.
            $profile = $user->toArray();

            $loginHistory = $db->query(
                'SELECT status, ip_address, device_type, browser, platform, country, city, created_at
                 FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 200',
                [$userId]
            );

            $connectedAccounts = $db->query(
                'SELECT provider, email, created_at FROM oauth_accounts WHERE user_id = ?',
                [$userId]
            );

            // فقط بيانات وصفية عن الجلسات (device_name/ip/تواريخ) - مش أي
            // توكن خام أو hash
            $sessions = $db->query(
                'SELECT device_name, ip_address, created_at, last_used_at, revoked_at, expires_at
                 FROM user_refresh_tokens WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            );

            // Business Control Center (Phase 15): بيان البيانات التجارية
            // (الـBusiness ومواقعه وخدماته وأسواقه وسياقه وهويته) - مش أي
            // أسرار: بدون مفاتيح API خام وبدون أي hash.
            $businessData = $this->collectBusinessData($userId);

            $exportData = [
                'export_generated_at' => date('c'),
                'export_scope_note' => 'Core account/profile data + Business Profile data (Business Control Center). Business API keys are exported as metadata only (prefix/scope/name) - never the raw key or hash.',
                'profile' => $profile,
                'login_history' => $loginHistory,
                'connected_accounts' => $connectedAccounts,
                'sessions_metadata' => $sessions,
                'businesses' => $businessData,
            ];

            $exportDir = TOURFECTO_STORAGE . '/exports';
            if (!is_dir($exportDir)) {
                if(!mkdir($exportDir, 0700, true)&&!is_dir($exportDir))throw new RuntimeException('Cannot create export directory');
            }

            // اسم ملف عشوائي غير قابل للتخمين - الملف بره public_html
            // أصلًا (TOURFECTO_STORAGE) فمش قابل للوصول المباشر عبر أي
            // رابط، لكن اسم عشوائي طبقة حماية إضافية لو انتقل الملف يومًا.
            $fileName = 'export_' . $userId . '_' . bin2hex(random_bytes(16)) . '.json';
            $filePath = $exportDir . '/' . $fileName;

            $json = json_encode($exportData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $temp = $filePath . '.tmp';
            if (file_put_contents($temp,$json,LOCK_EX) !== strlen($json) || !rename($temp,$filePath)) {
                @unlink($temp); throw new RuntimeException('Export file write failed');
            }
            if(!chmod($filePath,0600))throw new RuntimeException('Cannot protect export file');


            if(!$db->exec(
                "UPDATE data_export_requests SET status = 'ready', file_path = ?, completed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?",
                [$filePath, $requestId]
            ))throw new RuntimeException('Cannot complete data export');
        } catch (\Throwable $e) {
            if(isset($temp))@unlink($temp);
            if(isset($filePath))@unlink($filePath);
            $db->exec(
                "UPDATE data_export_requests SET status = 'failed', error_message = ? WHERE id = ?",
                [substr($e->getMessage(), 0, 500), $requestId]
            );
            if (class_exists('Logger')) {
                Logger::error('ExportUserDataJob Failed', ['request_id' => $requestId, 'user_id' => $userId, 'message' => $e->getMessage()]);
            }
            throw $e; // يخلي QueueManager يسجّلها fail ويعيد المحاولة حسب MAX_ATTEMPTS
        }
    }

    // ============================================
    // Business Control Center (Phase 15): تجميع بيانات الـBusiness
    // ============================================

    /**
     * تجميع كل بيانات الـBusiness اللي المستخدم مالكها (أو عضو فيها).
     * التصدير بيشمل الـBusinesses اللي هو مالكها + اللي عضو فيها.
     * بدون أي أسرار: مفاتيح API بتتصدّر كـ metadata (prefix/name/scope)
     * - المفتاح الخام مش متخزن أصلًا، والـhash مبيتصدّرش.
     *
     * @return array<int,array>
     */
    private function collectBusinessData(int $userId): array
    {
        $db = Database::getInstance();
        $businesses = [];

        $ownedRows = $db->query('SELECT id FROM businesses WHERE owner_user_id = ?', [$userId]);
        $ownedIds = array_map(fn ($r) => (int) $r['id'], $ownedRows ?: []);

        // الشركات اللي هو عضو نشط فيها (مالكها حد تاني)
        $memberRows = $db->query(
            "SELECT DISTINCT business_id FROM business_members WHERE user_id = ? AND status = 'active'",
            [$userId]
        );
        $memberIds = array_map(fn ($r) => (int) $r['business_id'], $memberRows ?: []);

        $businessIds = array_values(array_unique(array_merge($ownedIds, $memberIds)));
        foreach ($businessIds as $businessId) {
            $business = (new Business())->find($businessId);
            if (!$business) {
                continue;
            }

            $entry = [
                'business' => $business->toArray(),
                'locations' => array_map(fn ($m) => $m->toArray(), (new BusinessLocation())->where(['business_id' => $businessId])),
                'services' => array_map(fn ($m) => $m->toArray(), (new BusinessService())->where(['business_id' => $businessId])),
                'target_markets' => null,
                'ai_context' => null,
                'brand_settings' => null,
                'members' => [],
                'api_keys_metadata' => [],
            ];

            $targetMarket = (new BusinessTargetMarket())->where(['business_id' => $businessId], [], 1);
            if (!empty($targetMarket)) {
                $entry['target_markets'] = $targetMarket[0]->toArray();
            }

            $aiContext = (new BusinessAiContext())->where(['business_id' => $businessId], [], 1);
            if (!empty($aiContext)) {
                $entry['ai_context'] = $aiContext[0]->toArray();
            }

            $brand = (new BusinessBrandSettings())->where(['business_id' => $businessId], [], 1);
            if (!empty($brand)) {
                $entry['brand_settings'] = $brand[0]->toArray();
            }

            // الأعضاء (بدون أي بيانات حساسة غير الاسم/البريد/الدور)
            $memberModels = (new BusinessMember())->where(['business_id' => $businessId]);
            foreach ($memberModels as $member) {
                $email = (string) $member->getAttribute('invited_email');
                $memberUserId = $member->getAttribute('user_id');
                if ($memberUserId !== null) {
                    $user = (new User())->find((int) $memberUserId);
                    $email = $user ? (string) $user->getAttribute('email') : $email;
                }
                $entry['members'][] = [
                    'user_id' => $memberUserId !== null ? (int) $memberUserId : null,
                    'role' => (string) $member->getAttribute('role'),
                    'status' => (string) $member->getAttribute('status'),
                    'email' => $email,
                ];
            }

            // مفاتيح API كـ metadata فقط (بدون raw/hash)
            $keys = (new BusinessApiKey())->where(['business_id' => $businessId]);
            foreach ($keys as $key) {
                $entry['api_keys_metadata'][] = [
                    'name' => (string) $key->getAttribute('name'),
                    'scope' => (string) $key->getAttribute('scope'),
                    'key_prefix' => (string) $key->getAttribute('key_prefix'),
                    'created_at' => (string) $key->getAttribute('created_at'),
                    'last_used_at' => $key->getAttribute('last_used_at'),
                    'revoked' => (bool) $key->getAttribute('revoked_at'),
                ];
            }

            $businesses[] = $entry;
        }

        return $businesses;
    }
}
