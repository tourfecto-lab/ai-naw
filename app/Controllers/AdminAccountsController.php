<?php

/**
 * Tourfecto - التحكم الكامل في حسابات العملاء والفريق من ملف العميل 360
 *
 *  GET  /api/admin/insights/accounts/{id}           بيانات الحساب + إيه اللي مسموحلك تعمله عليه
 *  POST /api/admin/insights/accounts/{id}/update    تعديل الاسم/الإيميل/التليفون/الدور
 *  POST /api/admin/insights/accounts/{id}/password  رابط استعادة على إيميله، أو باسورد جديد مباشرة
 *  POST /api/admin/insights/accounts/{id}/delete    حذف الحساب (عادي أو نهائي)
 *
 * قواعد الصلاحيات:
 *  - المالك الأساسي (PLATFORM_OWNER_EMAIL أو أقدم Super Admin): يقدر يعمل كل حاجة على أي حساب،
 *    حتى الأدمن والـ Super Admin. ومحدش يقدر يلمس حسابه (حذف/إيقاف/تغيير دور/باسورد).
 *  - باقي الفريق: على العملاء بس، وحسب صلاحياتهم (users.edit / users.delete / users.reset_security).
 *    حسابات الأدمن والـ Super Admin والفريق = المالك الأساسي بس.
 *  - محدش يحذف نفسه من هنا.
 *
 * الحذف:
 *  - "حذف الحساب" (الافتراضي): الحساب بيتقفل ويختفي، الإيميل بيتفك (العميل يقدر يسجّل بيه تاني)،
 *    الاشتراكات بتتلغي، المواقع بتفصل من الأتمتة، الجلسات بتتقفل. الفواتير وحركات المحفظة بتفضل للحسابات.
 *  - "حذف نهائي": بيمسح الحساب وكل بياناته من قاعدة البيانات - مابيرجعش. لازم تكتب الإيميل للتأكيد.
 */
class AdminAccountsController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'super_admin'];
    private const ROLES = ['super_admin', 'admin', 'agency_owner', 'manager', 'agent', 'client', 'user'];

    /** GET /api/admin/insights/accounts/{id} */
    public function show(array $params = []): array
    {
        $t = $this->target($params);
        if (!$t) {
            return $this->error('الحساب مش موجود', 404);
        }
        $members = 0;
        try {
            $members = (int) (Database::getInstance()->query('SELECT COUNT(*) c FROM users WHERE owner_user_id = ? AND deleted_at IS NULL', [(int) $t['id']])[0]['c'] ?? 0);
        } catch (Throwable $e) {
        }
        return $this->success([
            'account' => [
                'id' => (int) $t['id'], 'email' => $t['email'], 'company_name' => $t['company_name'] ?? '', 'display_name' => $t['display_name'] ?? '',
                'phone' => $t['phone'] ?? '', 'role' => $t['role'], 'status' => $t['status'], 'members' => $members,
                'is_primary_owner' => AdminAccess::isPrimaryOwner($t), 'is_staff' => $this->isStaff($t), 'is_self' => (int) $t['id'] === (int) ($this->user['id'] ?? 0),
            ],
            'roles' => self::ROLES,
            'can' => [
                'edit' => $this->allowed($t, 'users.edit') === null,
                'edit_role' => $this->allowed($t, 'users.edit', true) === null,
                'password' => $this->allowed($t, 'users.reset_security') === null,
                'delete' => $this->allowed($t, 'users.delete') === null,
                'hard_delete' => $this->allowed($t, 'users.delete') === null && AdminAccess::isPrimaryOwner($this->user ?? []),
            ],
            'actor_is_primary_owner' => AdminAccess::isPrimaryOwner($this->user ?? []),
            'blocked_reason' => $this->allowed($t, 'users.delete'),
        ]);
    }

    /** POST /api/admin/insights/accounts/{id}/update {company_name, display_name, phone, email, role} */
    public function update(array $params = []): array
    {
        $t = $this->target($params);
        if (!$t) {
            return $this->error('الحساب مش موجود', 404);
        }
        $changingRole = $this->get('role') !== null && $this->get('role') !== $t['role'];
        if ($deny = $this->allowed($t, 'users.edit', $changingRole)) {
            return $this->error($deny, 403);
        }
        $db = Database::getInstance();
        $set = [];
        $vals = [];
        foreach (['company_name' => 255, 'display_name' => 120, 'phone' => 50] as $f => $max) {
            $v = $this->get($f);
            if ($v === null) {
                continue;
            }
            $v = trim((string) $v);
            if (mb_strlen($v) > $max) {
                return $this->error('القيمة طويلة جدًا', 422);
            }
            $set[] = "`{$f}` = ?";
            $vals[] = $v;
        }
        $email = $this->get('email');
        if ($email !== null) {
            $email = strtolower(trim((string) $email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
                return $this->error('البريد الإلكتروني مش صحيح', 422);
            }
            if ($email !== strtolower((string) $t['email'])) {
                if ($db->query('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, (int) $t['id']])) {
                    return $this->error('البريد ده مستخدم في حساب تاني', 409);
                }
                $set[] = '`email` = ?';
                $vals[] = $email;
            }
        }
        if ($changingRole) {
            $role = (string) $this->get('role');
            if (!in_array($role, self::ROLES, true)) {
                return $this->error('الدور مش صحيح', 422);
            }
            $set[] = '`role` = ?';
            $vals[] = $role;
        }
        if (!$set) {
            return $this->error('مفيش حاجة اتغيّرت', 422);
        }
        $vals[] = (int) $t['id'];
        $db->exec('UPDATE users SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?', $vals);
        if ($changingRole && !in_array((string) $this->get('role'), self::ADMIN_ROLES, true)) {
            try {
                $db->exec('DELETE FROM admin_staff WHERE user_id = ?', [(int) $t['id']]); // نزل من الفريق
            } catch (Throwable $e) {
            }
        }
        $this->audit('admin.account_updated', (int) $t['id'], ['fields' => array_keys(array_filter([
            'company_name' => $this->get('company_name'), 'display_name' => $this->get('display_name'), 'phone' => $this->get('phone'),
            'email' => $email, 'role' => $changingRole ? $this->get('role') : null,
        ], fn($v) => $v !== null)), 'old_email' => $t['email']]);
        return $this->success([], 'تم حفظ التعديلات');
    }

    /** POST /api/admin/insights/accounts/{id}/password {mode: link|set, password} */
    public function password(array $params = []): array
    {
        $t = $this->target($params);
        if (!$t) {
            return $this->error('الحساب مش موجود', 404);
        }
        if ($deny = $this->allowed($t, 'users.reset_security')) {
            return $this->error($deny, 403);
        }
        $db = Database::getInstance();
        $mode = (string) $this->get('mode', 'link');

        if ($mode === 'set') {
            $pw = (string) $this->get('password', '');
            if (mb_strlen($pw) < 8) {
                return $this->error('الباسورد لازم يكون 8 حروف على الأقل', 422);
            }
            $hash = password_hash($pw, defined('PASSWORD_HASH_ALGO') ? PASSWORD_HASH_ALGO : PASSWORD_DEFAULT, defined('PASSWORD_HASH_OPTIONS') ? PASSWORD_HASH_OPTIONS : []);
            $db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, (int) $t['id']]);
            $this->tryExec('UPDATE users SET password = ? WHERE id = ?', [$hash, (int) $t['id']]);
            $this->tryExec('UPDATE users SET password_changed_at = NOW(), login_attempts = 0, blocked_until = NULL WHERE id = ?', [(int) $t['id']]);
            $this->revokeSessions((int) $t['id']);
            $this->audit('admin.account_password_set', (int) $t['id']);
            return $this->success([], 'الباسورد اتغيّر وكل جلساته اتقفلت. ابعتهوله بطريقة آمنة.');
        }

        // رابط استعادة على إيميله (صالح ساعة)
        $raw = bin2hex(random_bytes(32));
        $db->exec('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())',
            [(int) $t['id'], hash('sha256', $raw), date('Y-m-d H:i:s', time() + 3600)]);
        $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/reset-password/' . $raw;
        $name = trim((string) (($t['first_name'] ?? '') ?: ($t['company_name'] ?? '')));
        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            return $this->error('البريد مش متظبط على المنصة - استخدم "باسورد جديد" بدل الرابط', 503);
        }
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name ?: 'عزيزي العميل', ENT_QUOTES, 'UTF-8');
        $html = '<div dir="rtl" style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px"><h2>مرحبًا ' . $safeName . '</h2>'
            . '<p style="line-height:1.8;color:#444">فريق الدعم بعتلك رابط تعيين كلمة مرور جديدة لحسابك. الرابط صالح لمدة ساعة.</p>'
            . '<p style="text-align:center;margin:28px 0"><a href="' . $safeUrl . '" style="background:#EFB05E;color:#14100a;padding:14px 32px;border-radius:30px;text-decoration:none;font-weight:bold">تعيين كلمة المرور</a></p>'
            . '<p style="color:#888;font-size:12px;word-break:break-all">' . $safeUrl . '</p></div>';
        $r = $mailer->context('password_reset', (int) $t['id'])->send((string) $t['email'], $name, 'تعيين كلمة المرور - ' . (defined('APP_NAME') ? APP_NAME : 'Tourfecto'), $html);
        if (empty($r['success'])) {
            return $this->error('الإيميل مااتبعتش: ' . ($r['error'] ?? ''), 502);
        }
        $this->audit('admin.account_password_link', (int) $t['id']);
        return $this->success([], 'اتبعت رابط تعيين الباسورد على ' . $t['email']);
    }

    /** POST /api/admin/insights/accounts/{id}/delete {mode: soft|hard, confirm, include_members} */
    public function delete(array $params = []): array
    {
        $t = $this->target($params);
        if (!$t) {
            return $this->error('الحساب مش موجود', 404);
        }
        if ($deny = $this->allowed($t, 'users.delete')) {
            return $this->error($deny, 403);
        }
        $mode = (string) $this->get('mode', 'soft');
        if (strtolower(trim((string) $this->get('confirm', ''))) !== strtolower((string) $t['email'])) {
            return $this->error('اكتب إيميل الحساب بالظبط عشان تأكّد الحذف', 422);
        }
        $id = (int) $t['id'];
        $db = Database::getInstance();
        $ids = [$id];
        if ($this->get('include_members')) {
            foreach ($db->query('SELECT id FROM users WHERE owner_user_id = ? AND deleted_at IS NULL', [$id]) as $m) {
                if (!AdminAccess::isPrimaryOwner($m)) {
                    $ids[] = (int) $m['id'];
                }
            }
        }

        if ($mode === 'hard') {
            if (!AdminAccess::isPrimaryOwner($this->user ?? [])) {
                return $this->error('الحذف النهائي متاح للمالك الأساسي بس - استخدم "حذف الحساب" العادي', 403);
            }
            $done = 0;
            $errors = [];
            foreach (array_reverse($ids) as $uid) {
                try {
                    $db->transaction(function()use($db,$uid){
                        $q=$db->getConnection()->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$uid]);
                        BusinessAccountClosureService::assertDeletionSafe($uid);
                        $this->cleanupBeforeDelete($uid);
                        $db->getConnection()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                    });
                    $done++;
                } catch (Throwable $e) {
                    $errors[] = '#' . $uid . ': ' . mb_substr($e->getMessage(), 0, 140);
                }
            }
            $this->audit('admin.account_hard_deleted', $id, ['email' => $t['email'], 'ids' => $ids, 'errors' => $errors]);
            if ($errors) {
                return $this->error('اتمسح ' . $done . ' من ' . count($ids) . '. فيه بيانات مرتبطة منعت الحذف النهائي - استخدم "حذف الحساب" العادي بدلها. (' . implode(' | ', $errors) . ')', 409);
            }
            return $this->success(['deleted' => $done], 'الحساب اتمسح نهائيًا بكل بياناته');
        }

        $emails = [];
        foreach ($db->query('SELECT id, email FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $row) {
            $emails[(string) $row['id']] = $row['email'];
        }
        foreach ($ids as $uid) {
            $this->softDelete($uid);
        }
        $this->audit('admin.account_deleted', $id, ['email' => $t['email'], 'ids' => $ids, 'emails' => $emails]);
        if (class_exists('ActivityLog')) {
            try {
                ActivityLog::record('admin', 'admin.user_deleted', ['user_id' => (int) $this->user['id'], 'subject_type' => 'users', 'subject_id' => $id]);
            } catch (Throwable $e) {
            }
        }
        return $this->success(['deleted' => count($ids)], 'الحساب اتحذف' . (count($ids) > 1 ? ' هو و' . (count($ids) - 1) . ' من فريقه' : '') . ' والإيميل بقى متاح للتسجيل من جديد');
    }

    /** GET /api/admin/insights/accounts-deleted - الحسابات المحذوفة (العادي) اللي ممكن ترجع */
    public function deletedList(array $params = []): array
    {
        $db = Database::getInstance();
        $rows = $db->query("SELECT id, email, company_name, role, owner_user_id, deleted_at FROM users
                             WHERE deleted_at IS NOT NULL AND email LIKE 'deleted+%' ORDER BY deleted_at DESC LIMIT 200");
        $orig = $this->originalEmails(array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $r) {
            $o = $orig[(string) $r['id']] ?? null;
            $out[] = [
                'id' => (int) $r['id'], 'company_name' => $r['company_name'], 'role' => $r['role'], 'deleted_at' => $r['deleted_at'],
                'original_email' => $o['email'] ?? null, 'deleted_by' => $o['by'] ?? null, 'is_member' => !empty($r['owner_user_id']),
                'email_taken' => $o && $o['email'] ? (bool) $db->query('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', [$o['email']]) : false,
            ];
        }
        return $this->success(['accounts' => $out]);
    }

    /** POST /api/admin/insights/accounts/{id}/restore {email?} */
    public function restore(array $params = []): array
    {
        $id = (int) ($params['id'] ?? 0);
        $db = Database::getInstance();
        $r = $db->query("SELECT * FROM users WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1", [$id]);
        if (!$r) {
            return $this->error('الحساب مش موجود في المحذوفين', 404);
        }
        $t = $r[0];
        $actorOwner = AdminAccess::isPrimaryOwner($this->user ?? []);
        if (!$actorOwner && ($this->isStaff($t) || !AdminAccess::can($this->user ?? [], 'users.delete'))) {
            return $this->error($this->isStaff($t) ? 'استرجاع حسابات الفريق للمالك الأساسي بس' : 'مش عندك صلاحية الإجراء ده', 403);
        }
        $email = strtolower(trim((string) ($this->get('email') ?: ($this->originalEmails([$id])[(string) $id]['email'] ?? ''))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('مش لاقيين الإيميل الأصلي - اكتب إيميل للحساب', 422);
        }
        if ($db->query('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $id])) {
            return $this->error('الإيميل ده (' . $email . ') اتسجّل بيه حساب تاني بعد الحذف - اكتب إيميل مختلف', 409);
        }
        $db->exec("UPDATE users SET email = ?, deleted_at = NULL WHERE id = ?", [$email, $id]);
        $this->tryExec("UPDATE users SET status = 'active' WHERE id = ?", [$id]);
        $this->audit('admin.account_restored', $id, ['email' => $email]);
        return $this->success(['email' => $email], 'الحساب رجع (' . $email . '). الباسورد اتغيّر وقت الحذف - ابعتله رابط تعيين باسورد من زرار "الباسورد"، والاشتراك محتاج يتجدد.');
    }

    /** الإيميل الأصلي لكل حساب محذوف من سجل التدقيق */
    private function originalEmails(array $ids): array
    {
        $out = [];
        if (!$ids) {
            return $out;
        }
        try {
            $rows = Database::getInstance()->query(
                "SELECT a.object_id, a.meta, COALESCE(NULLIF(u.display_name,''), u.email) actor FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                  WHERE a.action = 'admin.account_deleted' ORDER BY a.id DESC LIMIT 2000"
            );
        } catch (Throwable $e) {
            return $out;
        }
        $want = array_flip(array_map('strval', $ids));
        foreach ($rows as $row) {
            $m = json_decode((string) $row['meta'], true) ?: [];
            $map = $m['emails'] ?? [];
            if (!$map && !empty($m['email'])) {
                $map = [(string) $row['object_id'] => $m['email']];
            }
            foreach ($map as $uid => $em) {
                if (isset($want[(string) $uid]) && !isset($out[(string) $uid])) {
                    $out[(string) $uid] = ['email' => $em, 'by' => $row['actor']];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private function target(array $params): ?array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $r = Database::getInstance()->query('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$id]);
        return $r[0] ?? null;
    }

    private function isStaff(array $t): bool
    {
        if (in_array((string) $t['role'], self::ADMIN_ROLES, true)) {
            return true;
        }
        try {
            return (bool) Database::getInstance()->query('SELECT 1 x FROM admin_staff WHERE user_id = ? LIMIT 1', [(int) $t['id']]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return string|null سبب الرفض، أو null لو مسموح */
    private function allowed(array $t, string $perm, bool $roleChange = false): ?string
    {
        $actor = $this->user ?? [];
        $actorOwner = AdminAccess::isPrimaryOwner($actor);
        if ((int) $t['id'] === (int) ($actor['id'] ?? 0)) {
            if ($perm === 'users.delete') {
                return 'مينفعش تحذف حسابك من هنا';
            }
            if ($roleChange) {
                return 'مينفعش تغيّر دورك بنفسك';
            }
            return null; // تعديل بياناته أو الباسورد بتاعه عادي
        }
        if (AdminAccess::isPrimaryOwner($t)) {
            return 'ده حساب المالك الأساسي للمنصة - محدش يقدر يعدّله أو يحذفه';
        }
        if ($actorOwner) {
            return null; // المالك الأساسي يقدر على أي حد
        }
        if ($this->isStaff($t)) {
            return 'حسابات الأدمن والفريق مايقدرش يعدّلها أو يحذفها غير المالك الأساسي للمنصة';
        }
        if ($roleChange && in_array((string) $this->get('role'), self::ADMIN_ROLES, true)) {
            return 'ترقية حد لأدمن متاحة للمالك الأساسي بس';
        }
        if (!AdminAccess::can($actor, $perm)) {
            return 'مش عندك صلاحية الإجراء ده';
        }
        return null;
    }

    private function softDelete(int $uid): void
    {
        $db = Database::getInstance();
        $junk = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        // الإيميل بيتفك عشان صاحبه يقدر يسجّل تاني، والأصلي محفوظ في سجل التدقيق
        $db->exec("UPDATE users SET email = CONCAT('deleted+', id, '+', UNIX_TIMESTAMP(), '@deleted.invalid'), deleted_at = NOW(),
                   api_token = NULL, password_hash = ? WHERE id = ?", [$junk, $uid]);
        $this->tryExec("UPDATE users SET status = 'inactive' WHERE id = ?", [$uid]);
        // كل عمود في جملة لوحده: أعمدة قديمة زي password/remember_token مش
        // موجودة في كل السيرفرات، وفشلها كان بيمنع مسح التحقق بخطوتين.
        $this->tryExec('UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?', [$uid]);
        $this->tryExec('UPDATE users SET password = ? WHERE id = ?', [$junk, $uid]);
        $this->tryExec('UPDATE users SET remember_token = NULL WHERE id = ?', [$uid]);
        $this->revokeSessions($uid);
        // مايتجددش ولا يتخصم منه تاني
        $this->tryExec("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = 'account deleted by admin'
                        WHERE user_id = ? AND status IN ('active','trialing','past_due','pending')", [$uid]);
        // الكرونات ماتشتغلش على مواقعه تاني (تكلفة AI على الفاضي)
        $this->tryExec("UPDATE websites SET is_connected = 0 WHERE user_id = ?", [$uid]);
        $this->tryExec("UPDATE websites SET auto_pilot_mode = 'off' WHERE user_id = ?", [$uid]);
        $this->tryExec('DELETE FROM admin_staff WHERE user_id = ?', [$uid]);
        // الجدول مالوش عمود enabled: الإيقاف = وضع يدوي لكل الخدمات
        $this->tryExec("UPDATE automation_policies SET mode = 'manual', bucket_modes = NULL WHERE user_id = ?", [$uid]);
        $this->tryExec("UPDATE automation_policies SET graduated_buckets = NULL WHERE user_id = ?", [$uid]);
    }

    private function cleanupBeforeDelete(int $uid): void
    {
        $this->tryExec('DELETE FROM admin_staff WHERE user_id = ?', [$uid]);
        $this->tryExec('DELETE FROM admin_notes WHERE user_id = ?', [$uid]);
        $this->tryExec('DELETE FROM admin_user_tags WHERE user_id = ?', [$uid]);
        $this->tryExec('DELETE FROM email_verification_tokens WHERE user_id = ?', [$uid]);
        $this->tryExec('DELETE FROM plan_usage_events WHERE user_id = ?', [$uid]);
    }

    private function revokeSessions(int $uid): void
    {
        $this->tryExec('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$uid]);
        $this->tryExec('UPDATE users SET api_token = NULL WHERE id = ?', [$uid]);
    }

    private function tryExec(string $sql, array $p): void
    {
        try {
            Database::getInstance()->getConnection()->prepare($sql)->execute($p);
        } catch (Throwable $e) {
            // العمود/الجدول مش موجود على السيرفر ده - نكمّل
        }
    }

    private function audit(string $action, int $targetId, array $meta = []): void
    {
        try {
            Database::getInstance()->exec(
                'INSERT INTO audit_logs (user_id, action, object_type, object_id, result, meta, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [(int) ($this->user['id'] ?? 0), $action, 'users', (string) $targetId, 'success', json_encode($meta, JSON_UNESCAPED_UNICODE), $_SERVER['REMOTE_ADDR'] ?? null]
            );
        } catch (Throwable $e) {
        }
    }
}
