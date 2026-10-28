<?php
/**
 * Tourfecto - User Model
 * نموذج المستخدم مع إدارة المصادقة والصلاحيات
 * @version 1.0.3
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * تصحيح نهائي (2026-07-12) — تم التحقق من بنية جدول users الحقيقية مباشرة
 * من phpMyAdmin (وليس من ملف database/schema.sql المرفق، لأنه غير مطابق
 * للقاعدة الفعلية المنشورة). الأعمدة الحقيقية المهمة هنا:
 *   - password_hash     (وليس password)
 *   - country_code      (وليس country)
 *   - status             enum('active','suspended','cancelled','pending',...)
 *                         (وليس is_active)
 *   - email_verified_at  timestamp (وليس email_verified)
 *   - first_name, last_name  موجودان فعليًا
 *   - role                enum('super_admin','admin','manager','agent')
 *                         ملاحظة: لا توجد قيمة 'user' في enum الأدوار!
 *   - لا يوجد أي عمود اسمه remember_token في الجدول إطلاقًا — تمت إزالة كل
 *     استخدام له من هذا الملف (كان بيكسر create() و logout()).
 */

class User extends Model {
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'users';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة (مطابقة لبنية الجدول الفعلية)
     */
    protected $fillable = [
        'email',
        'password_hash',
        'api_token',
        'first_name',
        'last_name',
        'company_name',
        'avatar_url',
        'phone',
        'country_code',
        'timezone',
        'language',
        'role',
        'status',
        'email_verified_at',
        'gdpr_consent',
        'gdpr_consent_at',
        'notify_email',
        'notify_chat',
        'notify_reviews',
    ];

    /**
     * @var array $hidden - الحقول المخفية
     */
    protected $hidden = [
        'password_hash',
        'api_token'
    ];

    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

    /**
     * Constructor
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        $this->encryption = new Encryption();
    }

    /**
     * إنشاء مستخدم جديد
     * @param array $data يجب أن يحتوي على 'password' (نص عادي) وسيتم تشفيره تلقائيًا
     * @return User|false
     */
    public static function create(array $data) {
        try {
            // تشفير كلمة المرور وتخزينها في العمود الصحيح password_hash
            if (isset($data['password'])) {
                $data['password_hash'] = password_hash(
                    $data['password'],
                    PASSWORD_ARGON2ID,
                    PASSWORD_HASH_OPTIONS
                );
                unset($data['password']);
            }

            // إنشاء توكن API
            $data['api_token'] = self::generateApiToken();

            $user = new static($data);
            $id = $user->save();

            if ($id) {
                return $user->find($id);
            }

            return false;

        } catch (Exception $e) {
            Logger::error('User creation failed', [
                'email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * توليد توكن API
     * @return string
     */
    public static function generateApiToken(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * التحقق من كلمة المرور
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $password): bool {
        return password_verify($password, $this->attributes['password_hash'] ?? '');
    }

    /**
     * تحديث كلمة المرور
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(string $newPassword): bool {
        $this->attributes['password_hash'] = password_hash(
            $newPassword,
            PASSWORD_ARGON2ID,
            PASSWORD_HASH_OPTIONS
        );
        return $this->save() !== false;
    }

    /**
     * الحصول على الاشتراك النشط
     * @return Subscription|null
     */
    public function getActiveSubscription(): ?Subscription {
        // تصحيح: expiry_date مش اسم العمود الحقيقي في قاعدة البيانات المنشورة،
        // فبنستخدم Subscription::expiryColumn() لاكتشاف الاسم الصحيح بدل ما
        // نفترضه، وإلا الاستعلام يفشل بالكامل ("Unknown column").
        $expiryCol = Subscription::expiryColumn();
        $expiryClause = $expiryCol ? "AND (`{$expiryCol}` IS NULL OR `{$expiryCol}` > NOW())" : '';

        $sql = "SELECT * FROM subscriptions 
                WHERE user_id = ? 
                AND status = 'active' 
                {$expiryClause}
                ORDER BY id DESC LIMIT 1";

        $result = $this->db->query($sql, [$this->attributes['id']]);

        if (empty($result)) {
            return null;
        }

        return new Subscription($result[0]);
    }

    /**
     * الحصول على جميع المواقع
     * @return array
     */
    public function getWebsites(): array {
        $sql = "SELECT * FROM websites WHERE user_id = ? ORDER BY created_at DESC";
        $result = $this->db->query($sql, [$this->attributes['id']]);

        return array_map(function($data) {
            return new Website($data);
        }, $result);
    }

    /**
     * الحصول على عدد المواقع
     * @return int
     */
    public function getWebsitesCount(): int {
        $sql = "SELECT COUNT(*) as count FROM websites WHERE user_id = ?";
        $result = $this->db->query($sql, [$this->attributes['id']]);
        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * الحصول على التقارير
     * @param int $limit
     * @return array
     */
    public function getReports(int $limit = 10): array {
        $sql = "SELECT * FROM ai_reports 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";

        $result = $this->db->query($sql, [
            $this->attributes['id'],
            $limit
        ]);

        return array_map(function($data) {
            return new AIReport($data);
        }, $result);
    }

    /**
     * التحقق من صلاحية المستخدم
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool {
        // تعريف صلاحيات الأدوار (مطابقة لقيم enum الفعلية في الجدول:
        // super_admin, admin, manager, agent — لا توجد قيمة 'user')
        $rolePermissions = [
            'super_admin' => ['*'],
            'admin' => ['view_dashboard', 'manage_users', 'manage_subscriptions', 'view_reports'],
            'manager' => ['view_dashboard', 'manage_websites', 'manage_reviews', 'view_reports'],
            'agent' => ['view_dashboard', 'manage_chat', 'view_reports'],
        ];

        $role = $this->attributes['role'] ?? 'agent';

        if (!isset($rolePermissions[$role])) {
            return false;
        }

        $permissions = $rolePermissions[$role];

        // صلاحية النجم تعني كل الصلاحيات
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }

    /**
     * تحديث آخر نشاط
     * @return bool
     */
    public function updateLastActivity(): bool {
        $sql = "UPDATE users SET updated_at = NOW() WHERE id = ?";
        return $this->db->query($sql, [$this->attributes['id']]) !== false;
    }

    /**
     * تسجيل الخروج
     * @return bool
     */
    public function logout(): bool {
        // لا يوجد عمود remember_token في الجدول، فيتم إبطال api_token فقط
        $sql = "UPDATE users SET api_token = NULL WHERE id = ?";
        return $this->db->query($sql, [$this->attributes['id']]) !== false;
    }

    /**
     * البحث عن مستخدم بالبريد الإلكتروني
     * @param string $email
     * @return User|null
     */
    public static function findByEmail(string $email): ?User {
        $db = Database::getInstance();
        $sql = "SELECT * FROM users WHERE email = ? LIMIT 1";
        $result = $db->query($sql, [$email]);

        if (empty($result)) {
            return null;
        }

        return new static($result[0]);
    }

    /**
     * البحث عن مستخدم بالتوكن
     * @param string $token
     * @return User|null
     */
    public static function findByApiToken(string $token): ?User {
        $db = Database::getInstance();
        $sql = "SELECT * FROM users WHERE api_token = ? AND status = 'active' LIMIT 1";
        $result = $db->query($sql, [$token]);

        if (empty($result)) {
            return null;
        }

        return new static($result[0]);
    }

    /**
     * الحصول على إحصائيات المستخدم
     * @return array
     */
    public function getStats(): array {
        $stats = [
            'total_websites' => $this->getWebsitesCount(),
            'total_reports' => 0,
            'total_reviews' => 0,
            'total_chat_messages' => 0
        ];

        // التقارير
        $sql = "SELECT COUNT(*) as count FROM ai_reports WHERE user_id = ?";
        $result = $this->db->query($sql, [$this->attributes['id']]);
        $stats['total_reports'] = (int) ($result[0]['count'] ?? 0);

        // المراجعات
        $sql = "SELECT COUNT(*) as count FROM reviews WHERE user_id = ?";
        $result = $this->db->query($sql, [$this->attributes['id']]);
        $stats['total_reviews'] = (int) ($result[0]['count'] ?? 0);

        // رسائل الشات
        $sql = "SELECT COUNT(*) as count FROM chat_messages WHERE user_id = ?";
        $result = $this->db->query($sql, [$this->attributes['id']]);
        $stats['total_chat_messages'] = (int) ($result[0]['count'] ?? 0);

        return $stats;
    }

    /**
     * تحويل إلى مصفوفة مع إخفاء البيانات الحساسة
     * @return array
     */
    public function toArray(): array {
        $data = parent::toArray();

        // إزالة الحقول الحساسة
        unset($data['password_hash']);

        return $data;
    }
}