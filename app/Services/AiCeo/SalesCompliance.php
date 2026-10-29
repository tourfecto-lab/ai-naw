<?php

/**
 * TOURFECTO AI CEO (المرحلة 4) — هل مسموح نبعت أول رسالة تسويق لشركة؟ منطق نقي، متحفظ، وبيشرح السبب.
 *
 * ده مش استشارة قانونية؛ ده بوابة متحفظة بتمنع الإرسال لما يكون فيه شك، وصاحب المنصة بيأكد الأساس لكل منطقة.
 * المناطق: us · ca · eu (الاتحاد الأوروبي + EEA + بريطانيا + سويسرا) · gcc · other
 * قواعد عامة لكل المناطق: لازم إيميل، والشركة مش معترضة/ملغية/محذوفة، وعنوان الشركة الفعلي موجود في الإيميل (CAN-SPAM).
 *   us:  الإرسال لشركات مسموح بإلغاء اشتراك واضح وعنوان فعلي (CAN-SPAM) + تأكيد صاحب المنصة للمنطقة.
 *   ca:  CASL بيشترط موافقة: لازم opt_in مسجّل.
 *   eu:  GDPR + ePrivacy: لازم تأكيد صاحب المنصة لتقييم "المصلحة المشروعة" للمنطقة، وإيميل عام للشركة (info@، booking@...) مش إيميل شخص.
 *        ألمانيا والنمسا: لازم opt_in (أشد من الباقي في الإيميل التسويقي حتى بين الشركات).
 *   gcc: لازم تأكيد صاحب المنصة للمنطقة + إيميل عام للشركة.
 *   other: لازم تأكيد صاحب المنصة للمنطقة (مثلًا قانون حماية البيانات المصري) + إيميل عام للشركة.
 * opt_in مسجّل (إيميل أو كل القنوات) بيعدّي أي منطقة. واتساب: مفيش تسويق غير بـ opt_in على واتساب.
 */
final class SalesCompliance
{
    public const REGIONS = ['us', 'ca', 'eu', 'gcc', 'other'];
    private const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO', 'GB', 'UK', 'CH'];
    private const GCC = ['AE', 'SA', 'QA', 'KW', 'BH', 'OM'];
    private const OPT_IN_ONLY = ['DE', 'AT'];
    /** إيميلات عامة للشركة (مش لشخص بعينه) */
    private const ROLE = ['info', 'contact', 'hello', 'sales', 'booking', 'bookings', 'reservations', 'reservation', 'office', 'mail', 'enquiries', 'enquiry', 'inquiries', 'inquiry',
        'travel', 'tours', 'tour', 'support', 'admin', 'team', 'marketing', 'service', 'customerservice', 'welcome', 'holidays', 'trips', 'excursions', 'events', 'groups'];

    public static function region(?string $country): string
    {
        $c = strtoupper((string) $country);
        if ($c === 'US') return 'us';
        if ($c === 'CA') return 'ca';
        if (in_array($c, self::EU, true)) return 'eu';
        if (in_array($c, self::GCC, true)) return 'gcc';
        return 'other';
    }

    public static function isRoleEmail(?string $email): bool
    {
        $local = strtolower((string) strstr((string) $email, '@', true));
        $local = preg_replace('/[^a-z]/', '', $local);
        return $local !== '' && in_array($local, self::ROLE, true);
    }

    /**
     * @param array $lead  صف platform_leads (email, country, status)
     * @param array $basis ['us' => bool, 'eu' => bool, ...] تأكيدات صاحب المنصة
     * @param array $consent أحداث tbi_consent_events للشركة دي
     * @param bool  $hasAddress عنوان الشركة الفعلي موجود في إعدادات الإيميل
     * @return array{ok:bool, region:string, basis:?string, reasons:string[]}
     */
    public static function evaluate(array $lead, array $basis, array $consent, bool $hasAddress): array
    {
        $region = self::region($lead['country'] ?? null);
        $reasons = [];
        $events = array_column($consent, 'event');
        $optIn = (bool) array_filter($consent, static fn ($e) => ($e['event'] ?? '') === 'opt_in' && in_array($e['channel'] ?? '', ['email', 'all'], true));
        if (empty($lead['email'])) $reasons[] = 'no_email';
        if (in_array($lead['status'] ?? '', ['unsubscribed', 'bounced', 'invalid', 'paused'], true)) $reasons[] = 'status_' . $lead['status'];
        if (array_intersect($events, ['objection', 'unsubscribe', 'do_not_contact', 'erasure'])) $reasons[] = 'objected';
        if (!$hasAddress) $reasons[] = 'no_postal_address';
        if ($reasons) return ['ok' => false, 'region' => $region, 'basis' => null, 'reasons' => $reasons];
        if ($optIn) return ['ok' => true, 'region' => $region, 'basis' => 'consent', 'reasons' => []];

        $country = strtoupper((string) ($lead['country'] ?? ''));
        if ($region === 'ca') return ['ok' => false, 'region' => $region, 'basis' => null, 'reasons' => ['casl_needs_opt_in']];
        if (in_array($country, self::OPT_IN_ONLY, true)) return ['ok' => false, 'region' => $region, 'basis' => null, 'reasons' => ['country_needs_opt_in']];
        if (empty($basis[$region])) $reasons[] = 'region_basis_not_confirmed';
        if ($region !== 'us' && !self::isRoleEmail($lead['email'] ?? '')) $reasons[] = 'personal_email_needs_opt_in';
        if ($reasons) return ['ok' => false, 'region' => $region, 'basis' => null, 'reasons' => $reasons];
        return ['ok' => true, 'region' => $region, 'basis' => $region === 'us' ? 'can_spam_opt_out' : 'legitimate_interest', 'reasons' => []];
    }

    /** ملاءمة الشركة للعميل المثالي (0-100): سوق مستهدف، شركة رحلات فعلًا، درجة التحليل، وموقع */
    public static function icpFit(array $lead, array $targets): int
    {
        $fit = 0;
        if (in_array(self::region($lead['country'] ?? null), $targets, true)) $fit += 40;
        $cat = strtolower((string) ($lead['category'] ?? ''));
        if (preg_match('/tour|travel agen|excursion|safari|cruise|trip|holiday/', $cat)) $fit += 20;
        if (isset($lead['score']) && $lead['score'] !== null) $fit += (int) round(min(100, max(0, (int) $lead['score'])) * 0.3);
        if (!empty($lead['website'])) $fit += 10;
        return min(100, $fit);
    }

    public static function reasonText(string $r): string
    {
        return [
            'no_email' => 'No email address', 'objected' => 'Objected / asked to stop', 'no_postal_address' => 'Company postal address missing in outreach settings (required in every email)',
            'casl_needs_opt_in' => 'Canada (CASL): needs recorded opt-in', 'country_needs_opt_in' => 'Germany/Austria: needs recorded opt-in',
            'region_basis_not_confirmed' => 'Lawful basis for this region not confirmed by the owner', 'personal_email_needs_opt_in' => 'Personal (named) email outside the US: needs opt-in',
        ][$r] ?? (str_starts_with($r, 'status_') ? 'Status: ' . substr($r, 7) : $r);
    }
}
