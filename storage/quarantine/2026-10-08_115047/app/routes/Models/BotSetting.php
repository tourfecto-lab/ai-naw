<?php
/**
 * Tourfecto - Bot Settings Model
 * نموذج إعدادات البوت الذكي
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class BotSetting extends Model {
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'bot_settings';
    
    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'user_id',
        'website_id',
        'platform',
        'is_enabled',
        'auto_pilot',
        'requires_approval',
        'ai_model',
        'ai_temperature',
        'ai_max_tokens',
        'ai_language',
        'greeting_message',
        'farewell_message',
        'fallback_message',
        'whatsapp_webhook_url',
        'whatsapp_api_key',
        'whatsapp_phone_number',
        'allowed_domains',
        'blocked_keywords',
        'business_hours_start',
        'business_hours_end',
        'timezone'
    ];
    
    /**
     * @var array $casts - تحويل البيانات
     */
    protected $casts = [
        'allowed_domains' => 'array',
        'blocked_keywords' => 'array'
    ];
    
    /**
     * Constructor - معالجة تحويل JSON
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        
        // تحويل JSON إلى مصفوفات
        foreach ($this->casts as $field => $type) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = json_decode($this->attributes[$field], true);
            }
        }
    }
    
    /**
     * إنشاء إعدادات بوت افتراضية
     * @param int $userId
     * @param int $websiteId
     * @param string $platform
     * @return BotSetting
     */
    public static function createDefault(int $userId, int $websiteId, string $platform = 'all'): BotSetting {
        $data = [
            'user_id' => $userId,
            'website_id' => $websiteId,
            'platform' => $platform,
            'is_enabled' => 1,
            'auto_pilot' => 0,
            'requires_approval' => 1,
            'ai_model' => 'gemini-flash-latest',
            'ai_temperature' => 0.70,
            'ai_max_tokens' => 2000,
            'ai_language' => 'auto',
            'greeting_message' => 'مرحباً بك! كيف يمكننا مساعدتك اليوم؟',
            'farewell_message' => 'شكراً لتواصلك معنا. نتمنى لك يوماً سعيداً!',
            'fallback_message' => 'شكراً لتواصلك معنا. أحد ممثلي خدمة العملاء سيتواصل معك قريباً.'
        ];
        
        $settings = new static($data);
        $settings->save();
        
        return $settings;
    }
    
    /**
     * الحصول على إعدادات البوت للمستخدم والموقع
     * @param int $userId
     * @param int $websiteId
     * @param string $platform
     * @return BotSetting|null
     */
    public static function getSettings(int $userId, int $websiteId, string $platform = 'all'): ?BotSetting {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM bot_settings 
                WHERE user_id = ? 
                AND website_id = ? 
                AND (platform = ? OR platform = 'all')
                ORDER BY platform DESC 
                LIMIT 1";
        
        $result = $db->query($sql, [$userId, $websiteId, $platform]);
        
        if (empty($result)) {
            // إنشاء إعدادات افتراضية إذا لم تكن موجودة
            return self::createDefault($userId, $websiteId, $platform);
        }
        
        return new static($result[0]);
    }
    
    /**
     * تحديث إعدادات البوت
     * @param array $settings
     * @return bool
     */
    public function updateSettings(array $settings): bool {
        foreach ($settings as $key => $value) {
            if (in_array($key, $this->fillable)) {
                // تحويل المصفوفات إلى JSON
                if (is_array($value) && in_array($key, ['allowed_domains', 'blocked_keywords'])) {
                    $value = json_encode($value);
                }
                $this->attributes[$key] = $value;
            }
        }
        
        return $this->save() !== false;
    }
    
    /**
     * التحقق من صلاحية البوت
     * @return bool
     */
    public function isEnabled(): bool {
        return (bool) ($this->attributes['is_enabled'] ?? false);
    }
    
    /**
     * التحقق من وضع الطيار الآلي
     * @return bool
     */
    public function isAutoPilot(): bool {
        return (bool) ($this->attributes['auto_pilot'] ?? false);
    }
    
    /**
     * التحقق من طلب الموافقة
     * @return bool
     */
    public function requiresApproval(): bool {
        return (bool) ($this->attributes['requires_approval'] ?? true);
    }
    
    /**
     * التحقق من أوقات العمل
     * @return bool
     */
    public function isBusinessHours(): bool {
        $start = $this->attributes['business_hours_start'] ?? '09:00:00';
        $end = $this->attributes['business_hours_end'] ?? '18:00:00';
        
        $currentTime = date('H:i:s');
        $timezone = $this->attributes['timezone'] ?? 'UTC';
        
        // تحويل الوقت إلى المنطقة الزمنية المحددة
        $date = new DateTime('now', new DateTimeZone($timezone));
        $currentTime = $date->format('H:i:s');
        
        return $currentTime >= $start && $currentTime <= $end;
    }
    
    /**
     * الحصول على رسالة الترحيب
     * @return string
     */
    public function getGreeting(): string {
        return $this->attributes['greeting_message'] ?? 'مرحباً بك! كيف يمكننا مساعدتك اليوم؟';
    }
    
    /**
     * الحصول على رسالة الوداع
     * @return string
     */
    public function getFarewell(): string {
        return $this->attributes['farewell_message'] ?? 'شكراً لتواصلك معنا. نتمنى لك يوماً سعيداً!';
    }
    
    /**
     * الحصول على رسالة الاحتياط
     * @return string
     */
    public function getFallback(): string {
        return $this->attributes['fallback_message'] ?? 'شكراً لتواصلك معنا. أحد ممثلي خدمة العملاء سيتواصل معك قريباً.';
    }
    
    /**
     * التحقق من أن النطاق مسموح
     * @param string $domain
     * @return bool
     */
    public function isDomainAllowed(string $domain): bool {
        $allowed = $this->attributes['allowed_domains'] ?? [];
        if (empty($allowed)) {
            return true; // السماح بجميع النطاقات إذا لم يتم تحديدها
        }
        
        return in_array($domain, $allowed);
    }
    
    /**
     * التحقق من أن الكلمة غير محظورة
     * @param string $text
     * @return bool
     */
    public function hasBlockedKeyword(string $text): bool {
        $blocked = $this->attributes['blocked_keywords'] ?? [];
        if (empty($blocked)) {
            return false;
        }
        
        foreach ($blocked as $keyword) {
            if (stripos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
}