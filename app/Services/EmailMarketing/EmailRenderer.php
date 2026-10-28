<?php

/**
 * Tourfecto - Email Marketing Renderer
 * @version 1.0.0
 *
 * المسؤول عن تجهيز HTML الإيميل قبل الإرسال لكل مستلم:
 *   - تخصيص المتغيرات ({{first_name}}, {{email}}, {{company_name}}...)
 *   - إعادة كتابة الروابط لتمر عبر راوت التتبع (click tracking)
 *   - حقن بكسل تتبع الفتح (open tracking)
 *   - حقن رابط إلغاء الاشتراك
 *
 * Additive خالص على البنية الحالية - لا يعدّل Mailer ولا أي موديول آخر.
 */
class EmailRenderer
{
    /**
     * قائمة المتغيرات المدعومة - تُعرض كدالة مساعدة في محرر القوالب.
     * @return array<string, string>
     */
    public static function variables(): array
    {
        return [
            '{{first_name}}' => 'الاسم الأول للمستلم',
            '{{name}}' => 'الاسم الكامل',
            '{{email}}' => 'البريد الإلكتروني',
            '{{company_name}}' => 'اسم شركتك',
            '{{campaign_name}}' => 'اسم الحملة',
            '{{unsubscribe_url}}' => 'رابط إلغاء الاشتراك (يُحقن تلقائيًا)',
        ];
    }

    /**
     * يستبدل متغيرات التخصيص في HTML/Subject ببيانات المستلم الفعلي.
     * البيانات المرشحة: name, first_name, email + أي مفاتيح من attributes.
     */
    public function personalize(string $content, array $data): string
    {
        $map = self::variables();
        $first = trim((string) ($data['first_name'] ?? ''));
        if ($first === '' && !empty($data['name'])) {
            $first = trim((string) $data['name']);
            $first = mb_substr($first, 0, mb_strpos($first . ' ', ' '));
        }

        $replace = [
            '{{first_name}}' => $first,
            '{{name}}' => (string) ($data['name'] ?? ''),
            '{{email}}' => (string) ($data['email'] ?? ''),
            '{{company_name}}' => (string) ($data['company_name'] ?? ''),
            '{{campaign_name}}' => (string) ($data['campaign_name'] ?? ''),
            '{{unsubscribe_url}}' => (string) ($data['unsubscribe_url'] ?? ''),
        ];

        // Attributes مخصصة من المستخدم (JSON على المشترك) - تتحلّ بأسبقية
        // أدنى من المتغيرات الأساسية عشان نقدر نضيف أي حقل مستقبلًا.
        foreach ((array) ($data['attributes'] ?? []) as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            if (!array_key_exists($placeholder, $replace) && (is_scalar($value) || $value === null)) {
                $replace[$placeholder] = (string) $value;
            }
        }

        foreach ($replace as $placeholder => $value) {
            $content = str_replace($placeholder, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $content);
        }

        return $content;
    }

    /**
     * يعيد كتابة كل <a href="..."> في الـ HTML لتمر عبر راوت الكليك.
     * الروابط التابعة للموقع نفسه (إلغاء اشتراك/تتبع) تُستثنى حتى لا
     * تُلفّ في حلقات لا نهائية.
     */
    public function rewriteLinks(string $html, string $clickToken, string $trackingBaseUrl): string
    {
        $protected = ['/api/email-marketing/'];
        $trackingBaseUrl = rtrim($trackingBaseUrl, '/');

        return preg_replace_callback(
            '/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>/i',
            function ($m) use ($clickToken, $trackingBaseUrl, $protected) {
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $trimmed = trim($href);
                if ($trimmed === '') {
                    return $m[0];
                }
                // الروابط النسبية (داخل الموقع) مش روابط كليك خارجية
                if (strpos($trimmed, 'http://') !== 0 && strpos($trimmed, 'https://') !== 0) {
                    return $m[0];
                }
                foreach ($protected as $needle) {
                    if (strpos($trimmed, $needle) !== false) {
                        return $m[0];
                    }
                }
                $target = rtrim(base64_encode($trimmed), '=');
                $trackUrl = $trackingBaseUrl . '/api/email-marketing/track/click/' . rawurlencode($clickToken) . '?u=' . rawurlencode($target);
                return preg_replace(
                    '/href\s*=\s*(["\']).*?\1/i',
                    'href="' . htmlspecialchars($trackUrl, ENT_QUOTES, 'UTF-8') . '"',
                    $m[0]
                );
            },
            $html
        ) ?? $html;
    }

    /** وسم HTML لبكسل تتبع الفتح (1x1 شفاف). */
    public function pixelHtml(string $openToken, string $trackingBaseUrl): string
    {
        $base = rtrim($trackingBaseUrl, '/');
        return '<img src="' . htmlspecialchars($base . '/api/email-marketing/track/open/' . rawurlencode($openToken) . '.gif', ENT_QUOTES, 'UTF-8') . '" '
            . 'width="1" height="1" alt="" style="display:none;" />';
    }

    /**
     * يحوّل قالب/جسم خام إلى HTML نهائي لـ HTML إيميل واحد:
     *   - يضيف هيكل HTML/body لو مش موجود
     *   - يعيد كتابة الروابط للكليك تتبع
     *   - يحقن بكسل الفتح + رابط إلغاء الاشتراك
     *
     * @return string الـ HTML الجاهز للإرسال
     */
    public function finalize(
        string $htmlBody,
        array $recipientData,
        string $openToken,
        string $clickToken,
        string $trackingBaseUrl,
        string $unsubscribeUrl
    ): string {
        $recipientData['unsubscribe_url'] = $unsubscribeUrl;
        $htmlBody = $this->personalize($htmlBody, $recipientData);
        $htmlBody = $this->rewriteLinks($htmlBody, $clickToken, $trackingBaseUrl);
        $tracking = "\n" . $this->pixelHtml($openToken, $trackingBaseUrl);

        $unsubBlock = '<div style="text-align:center;font-size:11px;color:#9ca3af;padding:16px 0;">'
            . 'لم تعد ترغب في استلام هذه الرسائل؟ '
            . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="color:#6b7280;">إلغاء الاشتراك</a></div>';
        $htmlBody = $this->appendInsideBody($htmlBody, $tracking . "\n" . $unsubBlock);

        if (stripos($htmlBody, '<html') === false) {
            $htmlBody = '<!DOCTYPE html><html lang="ar"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '</head><body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;">'
                . $htmlBody . '</body></html>';
        }

        return $htmlBody;
    }

    private function appendInsideBody(string $html, string $block): string
    {
        $position = strripos($html, '</body>');
        return $position === false ? $html . $block : substr($html, 0, $position) . $block . substr($html, $position);
    }

    /**
     * يحوّل قالب رسالة معاملات (transactional) إلى HTML نهائي:
     * تخصيص + تتبع فتح/كليك، لكن من غير رابط إلغاء اشتراك (رسائل
     * المعاملات زي كلمات المرور والفواتير ما بتتضمنش إلغاء اشتراك).
     */
    public function finalizeTransactional(
        string $htmlBody,
        array $recipientData,
        string $openToken,
        string $clickToken,
        string $trackingBaseUrl
    ): string {
        $htmlBody = $this->personalize($htmlBody, $recipientData);
        $htmlBody = $this->rewriteLinks($htmlBody, $clickToken, $trackingBaseUrl);
        $htmlBody = $this->appendInsideBody($htmlBody, "\n" . $this->pixelHtml($openToken, $trackingBaseUrl));

        if (stripos($htmlBody, '<html') === false) {
            $htmlBody = '<!DOCTYPE html><html lang="ar"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '</head><body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;">'
                . $htmlBody . '</body></html>';
        }

        return $htmlBody;
    }
}
