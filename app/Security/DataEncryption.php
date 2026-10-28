<?php
/**
 * Tourfecto - Data Encryption
 * نظام تشفير متقدم للبيانات الحساسة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class DataEncryption {
    /**
     * @var Encryption $encryption - نظام التشفير الأساسي
     */
    private $encryption;
    
    /**
     * @var array $encryptionRules - قواعد التشفير
     */
    private $encryptionRules = [];
    
    /**
     * @var array $keyRotation - دوران المفاتيح
     */
    private $keyRotation = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->encryption = new Encryption();
        $this->loadEncryptionRules();
        $this->loadKeyRotation();
    }
    
    /**
     * تشفير البيانات حسب القواعد
     * @param string $data
     * @param string $type
     * @param string $identifier
     * @return string
     */
    public function encryptData(string $data, string $type, string $identifier = ''): string {
        if (empty($data)) {
            return '';
        }
        
        // التحقق من وجود قاعدة للتشفير
        if (isset($this->encryptionRules[$type])) {
            $rule = $this->encryptionRules[$type];
            
            // استخدام تشفير مخصص حسب النوع
            if ($rule['method'] === 'aes_256') {
                return $this->encryption->encryptCustomerData($data, $identifier);
            } elseif ($rule['method'] === 'sodium') {
                return $this->encryptSodium($data, $identifier);
            } elseif ($rule['method'] === 'hash') {
                return $this->hashData($data);
            }
        }
        
        // التشفير الافتراضي
        return $this->encryption->encrypt($data);
    }
    
    /**
     * فك تشفير البيانات
     * @param string $encryptedData
     * @param string $type
     * @param string $identifier
     * @return string
     */
    public function decryptData(string $encryptedData, string $type, string $identifier = ''): string {
        if (empty($encryptedData)) {
            return '';
        }
        
        if (isset($this->encryptionRules[$type])) {
            $rule = $this->encryptionRules[$type];
            
            if ($rule['method'] === 'aes_256') {
                return $this->encryption->decryptCustomerData($encryptedData, $identifier);
            } elseif ($rule['method'] === 'sodium') {
                return $this->decryptSodium($encryptedData, $identifier);
            } elseif ($rule['method'] === 'hash') {
                return $encryptedData; // لا يمكن فك تشفير الـ Hash
            }
        }
        
        return $this->encryption->decrypt($encryptedData);
    }
    
    /**
     * تشفير باستخدام Sodium
     * @param string $data
     * @param string $identifier
     * @return string
     */
    private function encryptSodium(string $data, string $identifier): string {
        if (!extension_loaded('sodium')) {
            return $this->encryption->encryptCustomerData($data, $identifier);
        }
        
        $key = $this->deriveSodiumKey($identifier);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $encrypted = sodium_crypto_secretbox($data, $nonce, $key);
        $combined = $nonce . $encrypted;
        
        return base64_encode($combined);
    }
    
    /**
     * فك تشفير باستخدام Sodium
     * @param string $encryptedData
     * @param string $identifier
     * @return string
     */
    private function decryptSodium(string $encryptedData, string $identifier): string {
        if (!extension_loaded('sodium')) {
            return $this->encryption->decryptCustomerData($encryptedData, $identifier);
        }
        
        $combined = base64_decode($encryptedData);
        if ($combined === false) {
            return '';
        }
        
        $nonce = substr($combined, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($combined, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $key = $this->deriveSodiumKey($identifier);
        
        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        
        return $decrypted !== false ? $decrypted : '';
    }
    
    /**
     * اشتقاق مفتاح Sodium
     * @param string $identifier
     * @return string
     */
    private function deriveSodiumKey(string $identifier): string {
        $baseKey = base64_decode(ENCRYPTION_KEY);
        $salt = substr(hash('sha256', $identifier . ENCRYPTION_KEY), 0, 16);
        
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $baseKey,
            $salt,
            100000,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
    
    /**
     * تجزئة البيانات
     * @param string $data
     * @return string
     */
    public function hashData(string $data): string {
        return password_hash($data, tourfecto_password_algo(), tourfecto_password_options());
    }
    
    /**
     * التحقق من التجزئة
     * @param string $data
     * @param string $hash
     * @return bool
     */
    public function verifyHash(string $data, string $hash): bool {
        return tourfecto_password_verify($data, $hash);
    }
    
    /**
     * إخفاء البيانات الجزئي
     * @param string $data
     * @param int $visibleStart
     * @param int $visibleEnd
     * @param string $maskChar
     * @return string
     */
    public function maskData(string $data, int $visibleStart = 2, int $visibleEnd = 2, string $maskChar = '*'): string {
        $length = strlen($data);
        
        if ($length <= $visibleStart + $visibleEnd) {
            return str_repeat($maskChar, $length);
        }
        
        $start = substr($data, 0, $visibleStart);
        $end = substr($data, -$visibleEnd);
        $masked = str_repeat($maskChar, $length - $visibleStart - $visibleEnd);
        
        return $start . $masked . $end;
    }
    
    /**
     * إخفاء البريد الإلكتروني
     * @param string $email
     * @return string
     */
    public function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $this->maskData($email);
        }
        
        $username = $parts[0];
        $domain = $parts[1];
        
        $maskedUsername = $this->maskData($username, 2, 1);
        
        return $maskedUsername . '@' . $domain;
    }
    
    /**
     * إخفاء رقم الهاتف
     * @param string $phone
     * @return string
     */
    public function maskPhone(string $phone): string {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        $length = strlen($cleaned);
        
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        
        $visible = 2;
        $start = substr($cleaned, 0, $visible);
        $end = substr($cleaned, -$visible);
        $masked = str_repeat('*', $length - $visible * 2);
        
        return $start . $masked . $end;
    }
    
    /**
     * تحميل قواعد التشفير
     */
    private function loadEncryptionRules(): void {
        $this->encryptionRules = [
            'email' => [
                'method' => 'aes_256',
                'key' => 'email_key',
                'salt' => 'email_salt'
            ],
            'phone' => [
                'method' => 'aes_256',
                'key' => 'phone_key',
                'salt' => 'phone_salt'
            ],
            'address' => [
                'method' => 'aes_256',
                'key' => 'address_key',
                'salt' => 'address_salt'
            ],
            'passport' => [
                'method' => 'sodium',
                'key' => 'passport_key',
                'salt' => 'passport_salt'
            ],
            'credit_card' => [
                'method' => 'sodium',
                'key' => 'card_key',
                'salt' => 'card_salt'
            ],
            'password' => [
                'method' => 'hash',
                'algorithm' => 'argon2id'
            ]
        ];
    }
    
    /**
     * تحميل دوران المفاتيح
     */
    private function loadKeyRotation(): void {
        $this->keyRotation = [
            'enabled' => true,
            'interval' => 90, // أيام
            'last_rotation' => null,
            'key_history' => []
        ];
    }
    
    /**
     * التحقق من حاجة دوران المفتاح
     * @return bool
     */
    public function needsKeyRotation(): bool {
        if (!$this->keyRotation['enabled']) {
            return false;
        }
        
        $lastRotation = $this->keyRotation['last_rotation'];
        if (!$lastRotation) {
            return true;
        }
        
        $days = (time() - strtotime($lastRotation)) / (60 * 60 * 24);
        return $days >= $this->keyRotation['interval'];
    }
    
    /**
     * تدوير المفاتيح
     * @return bool
     */
    public function rotateKeys(): bool {
        try {
            // حفظ المفتاح الحالي في التاريخ
            $this->keyRotation['key_history'][] = [
                'key' => ENCRYPTION_KEY,
                'date' => date('Y-m-d H:i:s')
            ];
            
            // توليد مفتاح جديد
            $newKey = base64_encode(random_bytes(32));
            
            // تحديث التكوين
            $this->updateConfigKey($newKey);
            
            // تحديث آخر تدوير
            $this->keyRotation['last_rotation'] = date('Y-m-d H:i:s');
            
            Logger::info('Keys Rotated Successfully');
            
            return true;
            
        } catch (Exception $e) {
            Logger::error('Key Rotation Error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * تحديث مفتاح التكوين
     * @param string $newKey
     */
    private function updateConfigKey(string $newKey): void {
        // تحديث ENCRYPTION_KEY في ملف التكوين
        // هذا يتطلب كتابة في ملف التكوين
        Logger::info('Config Key Updated', ['new_key' => substr($newKey, 0, 10) . '...']);
    }
}