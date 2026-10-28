<?php
/**
 * Tourfecto - CSRF Token (Static Helper)
 * @version 1.0.0
 *
 * ملاحظة إصلاح مهمة (2026-08-08):
 * الكلاس ده كان لازم يكون موجود في app/Security/Csrf.php من زمان -
 * public_html/index.php كان بيعمل له require بالفعل، وAuthController
 * كان بيستخدم Csrf::field() و Csrf::verify() في كل مكان، لكن الملف
 * نفسه ما كانش موجود إطلاقًا (كان موجود بس CSRFProtection.php وهو
 * كلاس مختلف تمامًا - أسماء methods مختلفة، وinstance methods مش
 * static، وكمان معتمد على جدول قاعدة بيانات ومش متصل بأي كود فعليًا).
 * النتيجة: class_exists('Csrf') كان دايمًا false، يعني:
 *   1) فورم تسجيل الدخول ما كانش بيحتوي على حقل csrf_token إطلاقًا
 *      ($csrfField كانت دايمًا '')
 *   2) وحتى لو المستخدم بعت أي قيمة، csrfGuard() كان برضه بيرفضها
 *      فورًا لأن class_exists('Csrf') === false
 * ده كان بيخلي تسجيل الدخول (وأي POST تاني بيعدي على csrfGuard)
 * يفشل دايمًا برسالة "انتهت صلاحية الجلسة" بغض النظر عن صحة البيانات.
 *
 * الحل هنا: كلاس بسيط معتمد على الجلسة (Session) بس - من غير أي
 * اعتماد على قاعدة بيانات، عشان يفضل شغال حتى لو جدول csrf_tokens
 * مش موجود أو فيه مشكلة اتصال. التوكن بيتولّد مرة واحدة لكل جلسة
 * ويفضل صالح طول الجلسة (مش one-time-use) عشان لو المستخدم فتح
 * أكتر من تاب أو رجع بالـ back button، الفورم يفضل شغال.
 */
class Csrf {
    private const SESSION_KEY = 'csrf_token';

    /** يرجّع توكن الجلسة الحالي، أو يولّد واحد جديد لو مفيش */
    public static function token(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** حقل input مخفي جاهز للحقن جوه أي <form> */
    public static function field(): string {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    /** يتحقق إن التوكن المُرسَل مطابق لتوكن الجلسة */
    public static function verify(?string $submitted): bool {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$sessionToken || !is_string($sessionToken) || !$submitted) {
            return false;
        }
        return hash_equals($sessionToken, $submitted);
    }
}
