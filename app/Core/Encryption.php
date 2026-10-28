<?php

/**
 * Tourfecto - Encryption Class
 * تشفير AES-256 مع دعم GDPR و HIPAA
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Encryption
{
    /**
     * @var string $key - مفتاح التشفير الرئيسي
     */
    private $key;

    /**
     * @var string $cipher - خوارزمية التشفير
     */
    private $cipher = 'AES-256-CBC';

    /**
     * @var int $iterations - عدد تكرارات التجزئة
     */
    private $iterations = 100000;

    /**
     * @var string $hashAlgo - خوارزمية التجزئة
     */
    private $hashAlgo = 'sha256';

    /**
     * @var bool $useSodium - استخدام Sodium بدلاً من OpenSSL
     */
    private $useSodium = false;

    /**
     * Constructor - تهيئة مفتاح التشفير
     */
    public function __construct()
    {
        // التحقق من توفر Sodium
        if (extension_loaded('sodium') && ENCRYPTION_METHOD === 'sodium') {
            $this->useSodium = true;
        }

        // تصحيح مؤكد من سجل الأخطاء الفعلي: "Encryption key must be 32
        // bytes long" كان بيفشل تسجيل الدخول بالكامل. السبب: .env.example
        // بيوضح صيغة المفتاح بـ prefix "base64:" (زي Laravel بالظبط)،
        // لكن الكود هنا كان بيعمل base64_decode() على القيمة كاملة
        // *شاملة* كلمة "base64:" نفسها، مش بس الجزء المُرمّز الفعلي.
        // النتيجة: فك تشفير غلط بطول مش 32 بايت أبدًا، مهما كان المفتاح
        // الحقيقي صح أو غلط.
        $rawKey = trim((string) ENCRYPTION_KEY);
        if (strpos($rawKey, 'base64:') === 0) {
            $rawKey = substr($rawKey, 7);
        }
        $rawKey = trim($rawKey);

        $this->key = base64_decode($rawKey);

        if ($this->key === false || strlen($this->key) !== 32) {
            throw new Exception('Encryption key must be 32 bytes long.');
        }
    }

    /**
     * تشفير البيانات
     * @param string $data - البيانات النصية
     * @param string $salt - ملح إضافي (اختياري)
     * @return string - البيانات المشفرة (base64)
     */
    public function encrypt(string $data, string $salt = ''): string
    {
        if (empty($data)) {
            return '';
        }

        try {
            if ($this->useSodium) {
                return $this->encryptSodium($data, $salt);
            }

            return $this->encryptOpenSSL($data, $salt);

        } catch (Exception $e) {
            Logger::error('Encryption Error', [
                'message' => $e->getMessage()
            ]);
            throw new Exception('Data encryption failed.');
        }
    }

    /**
     * فك تشفير البيانات
     * @param string $encryptedData - البيانات المشفرة (base64)
     * @param string $salt - ملح إضافي (اختياري)
     * @return string - البيانات النصية الأصلية
     */
    public function decrypt(string $encryptedData, string $salt = ''): string
    {
        if (empty($encryptedData)) {
            return '';
        }

        try {
            if ($this->useSodium) {
                return $this->decryptSodium($encryptedData, $salt);
            }

            return $this->decryptOpenSSL($encryptedData, $salt);

        } catch (Exception $e) {
            Logger::error('Decryption Error', [
                'message' => $e->getMessage()
            ]);
            throw new Exception('Data decryption failed.');
        }
    }

    /**
     * تشفير باستخدام OpenSSL
     * @param string $data
     * @param string $salt
     * @return string
     */
    private function encryptOpenSSL(string $data, string $salt): string
    {
        // إنشاء IV عشوائي
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // اشتقاق المفتاح مع الملح
        $key = $this->deriveKey($this->key, $salt);

        // تشفير البيانات
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception('OpenSSL encryption failed.');
        }

        // دمج IV مع البيانات المشفرة وتشفير Base64
        $combined = $iv . $encrypted;
        return base64_encode($combined);
    }

    /**
     * فك تشفير باستخدام OpenSSL
     * @param string $encryptedData
     * @param string $salt
     * @return string
     */
    private function decryptOpenSSL(string $encryptedData, string $salt): string
    {
        // فك تشفير Base64
        $combined = base64_decode($encryptedData, true);
        if ($combined === false) {
            throw new Exception('Invalid encrypted data format.');
        }

        // استخراج IV
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($combined, 0, $ivLength);
        $encrypted = substr($combined, $ivLength);

        if (strlen($iv) !== $ivLength) {
            throw new Exception('Invalid IV length.');
        }

        // اشتقاق المفتاح مع الملح
        $key = $this->deriveKey($this->key, $salt);

        // فك التشفير
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new Exception('OpenSSL decryption failed.');
        }

        return $decrypted;
    }

    /**
     * تشفير باستخدام Sodium (libsodium)
     * @param string $data
     * @param string $salt
     * @return string
     */
    private function encryptSodium(string $data, string $salt): string
    {
        $key = $this->deriveKeySodium($this->key, $salt);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $encrypted = sodium_crypto_secretbox(
            $data,
            $nonce,
            $key
        );

        $combined = $nonce . $encrypted;
        return base64_encode($combined);
    }

    /**
     * فك تشفير باستخدام Sodium (libsodium)
     * @param string $encryptedData
     * @param string $salt
     * @return string
     */
    private function decryptSodium(string $encryptedData, string $salt): string
    {
        $combined = base64_decode($encryptedData, true);
        if ($combined === false) {
            throw new Exception('Invalid encrypted data format.');
        }

        $nonce = substr($combined, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = substr($combined, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = $this->deriveKeySodium($this->key, $salt);

        $decrypted = sodium_crypto_secretbox_open(
            $encrypted,
            $nonce,
            $key
        );

        if ($decrypted === false) {
            throw new Exception('Sodium decryption failed.');
        }

        return $decrypted;
    }

    /**
     * اشتقاق مفتاح محسن من المفتاح الأساسي والملح (OpenSSL)
     * @param string $baseKey
     * @param string $salt
     * @return string
     */
    private function deriveKey(string $baseKey, string $salt): string
    {
        if (empty($salt)) {
            return $baseKey;
        }

        // استخدام PBKDF2 لتعزيز المفتاح
        return hash_pbkdf2(
            $this->hashAlgo,
            $baseKey,
            $salt,
            $this->iterations,
            32,
            true
        );
    }

    /**
     * اشتقاق مفتاح محسن (Sodium)
     * @param string $baseKey
     * @param string $salt
     * @return string
     */
    private function deriveKeySodium(string $baseKey, string $salt): string
    {
        if (empty($salt)) {
            return $baseKey;
        }

        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $baseKey,
            $salt,
            $this->iterations,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    /**
     * تشفير بيانات العميل (اسم، هاتف، بريد)
     * @param string $data
     * @param string $identifier
     * @return string
     */
    public function encryptCustomerData(string $data, string $identifier = ''): string
    {
        $salt = substr(hash('sha256', $identifier . ENCRYPTION_KEY), 0, 32);
        return $this->encrypt($data, $salt);
    }

    /**
     * فك تشفير بيانات العميل
     * @param string $encryptedData
     * @param string $identifier
     * @return string
     */
    public function decryptCustomerData(string $encryptedData, string $identifier = ''): string
    {
        $salt = substr(hash('sha256', $identifier . ENCRYPTION_KEY), 0, 32);
        return $this->decrypt($encryptedData, $salt);
    }

    /**
     * تشفير نص مع إضافة بصمة التحقق
     * @param string $data
     * @param string $salt
     * @return string
     */
    public function encryptWithSignature(string $data, string $salt = ''): string
    {
        // حساب بصمة التحقق
        $signature = hash_hmac('sha256', $data, $this->key);

        // تشفير البيانات مع البصمة
        $encrypted = $this->encrypt($data, $salt);

        // إضافة البصمة إلى النهاية
        return $encrypted . ':' . $signature;
    }

    /**
     * فك تشفير والتحقق من البصمة
     * @param string $encryptedData
     * @param string $salt
     * @return string
     */
    public function decryptWithSignature(string $encryptedData, string $salt = ''): string
    {
        // فصل البصمة عن البيانات
        $parts = explode(':', $encryptedData);
        if (count($parts) !== 2) {
            throw new Exception('Invalid data format.');
        }

        list($encrypted, $signature) = $parts;

        // فك التشفير
        $decrypted = $this->decrypt($encrypted, $salt);

        // التحقق من البصمة
        $expectedSignature = hash_hmac('sha256', $decrypted, $this->key);

        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Data integrity check failed.');
        }

        return $decrypted;
    }
}
