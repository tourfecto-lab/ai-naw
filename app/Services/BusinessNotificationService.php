<?php

/**
 * Tourfecto - Business Notification Service
 * Business Control Center Phase 24 - Notifications expansion
 * @version 1.0.0
 *
 * إشعارات داخل التطبيق لأحداث الـBusiness (دعوات الفريق، المفاتيح،
 * تغيير الأدوار...). مقسومة لنصفين:
 *
 *   1) builders (pure logic): بيبنوا [user_id, type, title, body, link]
 *      من بيانات بسيطة - منطق خالص قابل للاختبار offline من غير DB.
 *   2) notify* (thin wrappers): بينادوا Notification::notify لو الكلاس
 *      موجود (مش حتمي في كل البيئات)، وبيتجاهلوا أي فشل بهدوء.
 *
 * الهدف: أي حدث مشغّل من Service (مش Controller) عشان يضمن إن الإشعار
 * بيحصل حتى لو نفس العملية اتندى من أي نقطة دخول (API/Job/CLI).
 */
class BusinessNotificationService
{
    // ============================================
    // Builders - pure logic (لا DB، قابل للاختبار)
    // ============================================

    /** مستخدم انضم للفريق فورًا (مسجل بالفعل) */
    public static function memberAdded(int $userId, string $businessName, string $actorName, string $role): array
    {
        return [
            'user_id' => $userId,
            'type' => 'business_team_added',
            'title' => 'انضممت لفريق ' . $businessName,
            'body' => $actorName . ' أضافك كعضو بدور ' . $role,
            'link' => '/business-center',
        ];
    }

    /** دعوة معلقة لمستخدم غير مسجل - ننبّه المالك أن الدعوة اتبعتت */
    public static function inviteSent(int $ownerUserId, string $businessName, string $invitedEmail, string $role): array
    {
        return [
            'user_id' => $ownerUserId,
            'type' => 'business_team_invite_sent',
            'title' => 'دعوة فريق جديدة لـ' . $businessName,
            'body' => 'أُرسلت دعوة بدور ' . $role . ' إلى ' . $invitedEmail,
            'link' => '/business-center',
        ];
    }

    /** عضو قبل الدعوة - ننبّه المالك */
    public static function inviteAccepted(int $ownerUserId, string $businessName, string $memberName): array
    {
        return [
            'user_id' => $ownerUserId,
            'type' => 'business_team_invite_accepted',
            'title' => $memberName . ' انضم لفريق ' . $businessName,
            'body' => 'قبل دعوة الانضمام وأصبح عضوًا نشطًا في الفريق',
            'link' => '/business-center',
        ];
    }

    /** تمت إزالة العضو من الفريق */
    public static function memberRemoved(int $userId, string $businessName): array
    {
        return [
            'user_id' => $userId,
            'type' => 'business_team_removed',
            'title' => 'أُزيلت عضويتك من ' . $businessName,
            'body' => 'لم تعد عضوًا في فريق هذا النشاط التجاري',
            'link' => '/business-center',
        ];
    }

    /** تم تغيير دور العضو */
    public static function roleChanged(int $userId, string $businessName, string $newRole): array
    {
        return [
            'user_id' => $userId,
            'type' => 'business_team_role_changed',
            'title' => 'تغير دورك في ' . $businessName,
            'body' => 'أصبح دورك الآن ' . $newRole,
            'link' => '/business-center',
        ];
    }

    /** مفتاح API جديد اتcreate */
    public static function apiKeyCreated(int $ownerUserId, string $businessName, string $keyName): array
    {
        return [
            'user_id' => $ownerUserId,
            'type' => 'business_api_key_created',
            'title' => 'مفتاح API جديد لـ' . $businessName,
            'body' => 'تم إنشاء المفتاح "' . $keyName . '" - احتفظ به في مكان آمن',
            'link' => '/business-center',
        ];
    }

    /** مفتاح API اتلغى */
    public static function apiKeyRevoked(int $ownerUserId, string $businessName, string $keyName): array
    {
        return [
            'user_id' => $ownerUserId,
            'type' => 'business_api_key_revoked',
            'title' => 'تم إيقاف مفتاح API لـ' . $businessName,
            'body' => 'المفتاح "' . $keyName . '" لم يعد صالحًا',
            'link' => '/business-center',
        ];
    }

    // ============================================
    // Thin wrappers - تفعيل فعلًا (بتتجاهل الفشل بهدوء)
    // ============================================

    public static function push(array $payload): void
    {
        if (empty($payload['user_id']) || !class_exists('Notification')) {
            return;
        }
        Notification::notify(
            (int) $payload['user_id'],
            (string) $payload['type'],
            (string) $payload['title'],
            (string) ($payload['body'] ?? ''),
            (string) ($payload['link'] ?? '')
        );
    }
}
