<?php

/**
 * Tourfecto - AI Chat Platform
 * Business Hours Service - ساعات عمل الشركة وإدراكها في أتمتة المحادثات.
 *
 * لماذا هذا مهم (تحليل تنافسي): المنصات الرائدة في المحادثات المؤتمتة
 * (Intercom, respond.io, ManyChat, SleekFlow) لا ترسل متابعات تلقائية
 * في منتصف الليل - إرسال رسالة متابعة خارج ساعات العمل يرفع معدل
 * الانسحاب (opt-out) ويدمر التجربة. هذا الكلاس يقرأ ساعات عمل الشركة من
 * Knowledge Base (قسم `business_hours` الموجود بالفعل)، ويحوّل أي لحظة
 * "مستحقة" إلى أقرب لحظة عمل فعلية.
 *
 * المدخلات المدعومة لقسم business_hours:
 *   1. structured_data بصيغة JSON:
 *        {"monday":["09:00-18:00"],"tuesday":["09:00-18:00"],"saturday":["10:00-14:00"]}
 *        أو {"monday":{"open":"09:00","close":"18:00"}, ...}
 *        أو مفاتيح رقمية 1..7 (حيث 1=الاثنين .. 7=الأحد بتنسيق PHP date('N')).
 *   2. نص حر (content) مثل:
 *        "Mon-Fri 9:00-18:00, Sat 10:00-14:00"
 *        "السبت والأحد مغلق"  /  "الاثنين للجمعة من 9 ص حتى 6 م"
 *        "9:00-18:00" (بدون أيام = يطبق على كل أيام الأسبوع)
 *
 * القيمة الافتراضية عند عدم وجود ساعات عمل مهيأة = 24/7 (لا قيد) - أي أن
 * سلوك النظام القديم لا يتغير إطلاقًا إذا لم تملأ الشركة هذا القسم.
 *
 * @version 1.0.0
 */

class BusinessHoursService
{
    /** أيام إنجليزية (اسم مختصر/كامل) -> رقم PHP date('N') حيث 1=الاثنين */
    private const DAYS_EN = [
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
        'sunday' => 7, 'sun' => 7,
    ];

    /** أيام عربية -> رقم PHP date('N') (الأسبوع العربي يبدأ بالسبت) */
    private const DAYS_AR = [
        'السبت' => 6, 'الاحد' => 7, 'الاثنين' => 1, 'الثلاثاء' => 2,
        'الاربعاء' => 3, 'الخميس' => 4, 'الجمعة' => 5,
    ];

    /** دقائق اليوم الكامل */
    private const MINUTES_PER_DAY = 1440;

    /**
     * بناء جدول أسبوعي من صفوف Knowledge Base (قسم business_hours).
     *
     * @param array $rows كل صف به ['content' => ?string, 'structured_data' => ?string]
     * @return array|null الجدول بصيغة [dayOfWeekN => [[startMin, endMin], ...]]،
     *                    أو null لو لا يوجد أي ساعات عمل قابلة للفهم (يعني 24/7).
     */
    public static function fromEntries(array $rows): ?array
    {
        $schedule = null;

        foreach ($rows as $row) {
            $structured = !empty($row['structured_data']) ? json_decode((string) $row['structured_data'], true) : null;
            if (is_array($structured)) {
                $parsed = self::parseStructured($structured);
            } else {
                $parsed = self::parseFreeText((string) ($row['content'] ?? ''));
            }

            if (empty($parsed)) {
                continue;
            }

            if ($schedule === null) {
                $schedule = [];
            }
            foreach ($parsed as $dow => $ranges) {
                $schedule[$dow] = array_merge($schedule[$dow] ?? [], $ranges);
            }
        }

        return $schedule;
    }

    /**
     * هل اللحظة الحالية داخل ساعات العمل؟ (schedule=null يعني دائمًا مفتوح)
     * @param int $timestamp
     * @param array|null $schedule
     * @param DateTimeZone|null $timezone
     * @return bool
     */
    public static function isOpenAt(int $timestamp, ?array $schedule, ?DateTimeZone $timezone = null): bool
    {
        if ($schedule === null) {
            return true;
        }
        $minute = (int) self::localDateTime($timestamp, $timezone)->format('G') * 60
            + (int) self::localDateTime($timestamp, $timezone)->format('i');
        $dow = (int) self::localDateTime($timestamp, $timezone)->format('N');

        // نطاقات اليوم الحالي
        foreach ($schedule[$dow] ?? [] as $range) {
            [$start, $end] = $range;
            if (self::minuteInRange($minute, $start, $end)) {
                return true;
            }
        }

        // نطاق "يعبر منتصف الليل" من اليوم السابق (مثال: Fri 22:00-06:00)
        // يستمر إلى ساعات أول اليوم الحالي - نتحقق منه أيضًا.
        $prevDow = $dow === 1 ? 7 : $dow - 1;
        foreach ($schedule[$prevDow] ?? [] as $range) {
            [$start, $end] = $range;
            if ($end <= $start && $minute < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * أقرب لحظة عمل تالية. لو الوقت الحالي داخل ساعات العمل، تُرجع نفس
     * اللحظة دون تغيير. لو schedule=null (24/7)، تُرجع نفس اللحظة.
     * @param int $timestamp
     * @param array|null $schedule
     * @param DateTimeZone|null $timezone
     * @return int
     */
    public static function nextOpenTime(int $timestamp, ?array $schedule, ?DateTimeZone $timezone = null): int
    {
        if ($schedule === null || self::isOpenAt($timestamp, $schedule, $timezone)) {
            return $timestamp;
        }

        $timezone = $timezone ?: self::defaultTimezone();
        $now = self::localDateTime($timestamp, $timezone);
        $currentMinute = (int) $now->format('G') * 60 + (int) $now->format('i');

        // ابحث في اليوم الحالي + الأيام الـ7 التالية عن أول فتح
        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $candidateDate = $dayOffset === 0
                ? $now
                : $now->modify("+{$dayOffset} day");
            $dow = (int) $candidateDate->format('N');

            foreach ($schedule[$dow] ?? [] as $range) {
                $start = $range[0];
                // اليوم الحالي: نقبل فقط نطاق يبدأ بعد اللحظة الحالية
                if ($dayOffset === 0 && $start <= $currentMinute) {
                    continue;
                }
                $candidate = $candidateDate->setTime(intdiv($start, 60), $start % 60, 0);
                return (int) $candidate->getTimestamp();
            }
        }

        return $timestamp;
    }

    /**
     * تحليل structured_data بصيغ متعددة إلى جدول أسبوعي.
     * @param array $data
     * @return array|null
     */
    private static function parseStructured(array $data): ?array
    {
        $schedule = [];
        foreach ($data as $key => $value) {
            $dow = self::dayKeyToNumber($key);
            if ($dow === null) {
                continue;
            }

            $ranges = self::extractRangesFromValue($value);
            if (!empty($ranges)) {
                $schedule[$dow] = $ranges;
            }
        }
        return empty($schedule) ? null : $schedule;
    }

    /**
     * استخراج قائمة نطاقات وقت من قيمة day في structured_data.
     * @param mixed $value
     * @return array
     */
    private static function extractRangesFromValue($value): array
    {
        if (is_string($value)) {
            return self::parseRangesFromText($value);
        }

        if (is_array($value)) {
            // صيغة {"open":"09:00","close":"18:00"}
            if (isset($value['open']) && isset($value['close'])) {
                return [self::rangeToMinutes((string) $value['open'], (string) $value['close'])];
            }
            // صيغة ["09:00-18:00", ...] أو ["9:00-13:00", "16:00-20:00"]
            $ranges = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    foreach (self::parseRangesFromText($item) as $r) {
                        $ranges[] = $r;
                    }
                } elseif (is_array($item) && isset($item['open'], $item['close'])) {
                    $ranges[] = self::rangeToMinutes((string) $item['open'], (string) $item['close']);
                }
            }
            return $ranges;
        }

        return [];
    }

    /**
     * تحليل نص حر لسطر/سلسلة ساعات عمل.
     * @param string $text
     * @return array|null
     */
    public static function parseFreeText(string $text): ?array
    {
        $text = self::normalizeArabic((string) $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($text === '') {
            return null;
        }

        // "24/7" = مفتوح طوال الأسبوع
        if (preg_match('/24\s*\/\s*7|24h|طوال الاسبوع|يوميا/i', $text)) {
            return self::allWeekRanges();
        }

        $schedule = [];
        $segments = preg_split('/[;\n\r]+/u', $text);

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            $closed = (bool) preg_match('/مغلق|closed|اجازة|عطلة|holiday/i', $segment);

            // نطاق أيام مثل "Mon-Fri" أو "الاثنين - الجمعة"
            $dayRange = self::extractDayRange($segment);
            // نطاقات زمنية داخل هذا السطر
            $ranges = $closed ? [] : self::parseRangesFromText($segment);

            // أوقات بلا أيام = تنطبق على كل أيام الأسبوع
            if (empty($dayRange) && !empty($ranges)) {
                $dayRange = range(1, 7);
            }

            foreach ($dayRange as $dow) {
                if (!isset($schedule[$dow])) {
                    $schedule[$dow] = [];
                }
                foreach ($ranges as $range) {
                    $schedule[$dow][] = $range;
                }
            }
        }

        return empty($schedule) ? null : $schedule;
    }

    /**
     * استخراج قائمة أيام من نص (بما فيها نطاقات Mon-Fri).
     * @param string $segment
     * @return int[]
     */
    private static function extractDayRange(string $segment): array
    {
        $days = [];
        $lower = mb_strtolower($segment, 'UTF-8');

        // أولاً: نطاقات أيام EN مثل "mon-fri" أو "monday to friday"
        if (preg_match('/\b(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b\s*[-–—:toإلىال]\s*\b(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $segment, $m)) {
            $from = self::DAYS_EN[strtolower($m[1])] ?? null;
            $to = self::DAYS_EN[strtolower($m[2])] ?? null;
            if ($from !== null && $to !== null) {
                $days = self::expandDayRange($from, $to);
            }
        }

        // نطاقات أيام AR مثل "الاثنين للجمعة" أو "السبت - الأحد"
        if (empty($days) && preg_match('/\b(السبت|الاحد|الاثنين|الثلاثاء|الاربعاء|الخميس|الجمعة)\b\s*[-–—:إلىالالي]\s*\b(السبت|الاحد|الاثنين|الثلاثاء|الاربعاء|الخميس|الجمعة)\b/u', $segment, $m)) {
            $from = self::DAYS_AR[$m[1]] ?? null;
            $to = self::DAYS_AR[$m[2]] ?? null;
            if ($from !== null && $to !== null) {
                $days = self::expandDayRange($from, $to);
            }
        }

        // بعدين: أيام مفردة مذكورة في النص
        foreach (self::DAYS_AR as $arName => $dow) {
            if (empty($days) && mb_strpos($segment, $arName) !== false) {
                $days[] = $dow;
            }
        }
        if (empty($days)) {
            foreach (self::DAYS_EN as $enName => $dow) {
                if (strpos($lower, $enName) !== false && !preg_match('/\b' . preg_quote($enName, '/') . 'day\b/', $lower . 'day')) {
                    $days[] = $dow;
                }
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * توسيع نطاق أيام مع التعامل مع الالتفاف عبر نهاية الأسبوع
     * (مثال: sat-thu = 6,7,1,2,3,4).
     * @param int $from
     * @param int $to
     * @return int[]
     */
    private static function expandDayRange(int $from, int $to): array
    {
        $days = [];
        $d = $from;
        while (true) {
            $days[] = $d;
            if ($d === $to) {
                break;
            }
            $d = $d >= 7 ? 1 : $d + 1;
            if (count($days) > 7) {
                break; // حماية من حلقة لا نهائية
            }
        }
        return $days;
    }

    /**
     * استخراج نطاقات وقت من نص (يدعم 24h وam/pm وArabic).
     * @param string $text
     * @return array قائمة [startMin, endMin]
     */
    private static function parseRangesFromText(string $text): array
    {
        // 24 ساعة مفتوح
        if (preg_match('/24\s*(?:ساعة|h|hrs)?/i', $text) && !preg_match('/\d{1,2}[:.]\d{2}\s*[-–—]\s*\d{1,2}[:.]\d{2}/', $text)) {
            return [[0, self::MINUTES_PER_DAY]];
        }

        $ranges = [];
        // صيغ متعددة للنطاق: 9:00-18:00, 9:00am - 6:00pm, 9 - 18, 9ص إلى 6م
        $pattern = '/(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm|ص|م|صباحا|مساءا|صباحاً|مساءً)?\s*[-–—:إلىاليإلى]\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm|ص|م|صباحا|مساءا|صباحاً|مساءً)?/i';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $start = self::timeToMinutes((int) $m[1], (int) ($m[2] ?? 0), self::amPmFromToken($m[3] ?? ''));
                $end = self::timeToMinutes((int) $m[4], (int) ($m[5] ?? 0), self::amPmFromToken($m[6] ?? ''));
                if ($end <= $start) {
                    $end += self::MINUTES_PER_DAY; // نطاق يعبر منتصف الليل (مثال 22:00-06:00)
                }
                $ranges[] = [$start, $end > self::MINUTES_PER_DAY ? $end - self::MINUTES_PER_DAY : $end];
            }
        }

        return $ranges;
    }

    /**
     * @param string $token
     * @return string 'am'|'pm'|''
     */
    private static function amPmFromToken(string $token): string
    {
        $token = mb_strtolower($token, 'UTF-8');
        if (in_array($token, ['am'], true)) {
            return 'am';
        }
        if (in_array($token, ['pm', 'م', 'مساءا', 'مساءً'], true)) {
            return 'pm';
        }
        return '';
    }

    /**
     * تحويل ساعة/دقيقة + مؤشر am/pm إلى دقائق منذ منتصف الليل.
     * @param int $hour
     * @param int $minute
     * @param string $amPm
     * @return int
     */
    private static function timeToMinutes(int $hour, int $minute, string $amPm): int
    {
        if ($amPm === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($amPm === 'am' && $hour === 12) {
            $hour = 0;
        }
        return max(0, min(self::MINUTES_PER_DAY - 1, $hour * 60 + $minute));
    }

    /**
     * @param string $openText
     * @param string $closeText
     * @return array [startMin, endMin]
     */
    private static function rangeToMinutes(string $openText, string $closeText): array
    {
        $ranges = self::parseRangesFromText($openText . '-' . $closeText);
        return $ranges[0] ?? [0, self::MINUTES_PER_DAY];
    }

    /**
     * هل دقيقة معينة داخل نطاق (مع دعم النطاق الذي يعبر منتصف الليل)؟
     * @param int $minute
     * @param int $start
     * @param int $end
     * @return bool
     */
    private static function minuteInRange(int $minute, int $start, int $end): bool
    {
        if ($end <= $start) {
            return $minute >= $start || $minute < $end; // يعبر منتصف الليل
        }
        return $minute >= $start && $minute < $end;
    }

    /**
     * @return array جدول "كل الأيام مفتوح 24/7"
     */
    private static function allWeekRanges(): array
    {
        $all = [];
        for ($dow = 1; $dow <= 7; $dow++) {
            $all[$dow] = [[0, self::MINUTES_PER_DAY]];
        }
        return $all;
    }

    /**
     * تحويل مفتاح يوم (رقم، إنجليزي، عربي) إلى رقم PHP date('N').
     * @param mixed $key
     * @return int|null
     */
    private static function dayKeyToNumber($key): ?int
    {
        if (is_int($key) || ctype_digit((string) $key)) {
            $n = (int) $key;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }
        $lower = mb_strtolower((string) $key, 'UTF-8');
        if (isset(self::DAYS_EN[$lower])) {
            return self::DAYS_EN[$lower];
        }
        if (isset(self::DAYS_AR[$lower])) {
            return self::DAYS_AR[$lower];
        }
        return null;
    }

    /**
     * إزالة التشكيل العربي وتوحيد أشكال الألف/التاء المربوطة لتحسين المطابقة.
     * @param string $text
     * @return string
     */
    private static function normalizeArabic(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text);
        $text = preg_replace('/[أإآ]/u', 'ا', $text);
        return $text;
    }

    /**
     * DateTime محلي للطابع الزمني.
     * @param int $timestamp
     * @param DateTimeZone|null $timezone
     * @return DateTimeImmutable
     */
    private static function localDateTime(int $timestamp, ?DateTimeZone $timezone): DateTimeImmutable
    {
        $dt = new DateTimeImmutable('@' . $timestamp);
        return $dt->setTimezone($timezone ?: self::defaultTimezone());
    }

    private static function defaultTimezone(): DateTimeZone
    {
        $tz = date_default_timezone_get();
        try {
            return new DateTimeZone($tz);
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }
}
