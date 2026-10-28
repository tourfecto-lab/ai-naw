<?php

/**
 * Tourfecto - Email Marketing Template Editor Service (المرحلة 2)
 * @version 1.0.0
 *
 * المسؤول عن:
 *   - معرض القوالب المدمجة (catalog) بمحرك بلوكات JSON
 *   - تحويل البلوكات إلى HTML إيميل متوافق (جداول table)
 *   - نسخ القوالب/الحملات (duplicate)
 *   - مشاركة القالب برابط عام (share token) واستيراده
 *
 * Additive خالص - لا يعدّل أي موديول آخر.
 */
class EmailTemplateEditorService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ Block Rendering ============================

    /**
     * أنواع البلوكات المدعومة في المحرر المرئي.
     * @return array<int, array{type:string,label:string,icon:string,default:array}>
     */
    public function blockTypes(): array
    {
        return [
            ['type' => 'text', 'label' => 'نص', 'icon' => '📝', 'default' => ['type' => 'text', 'content' => '<p>اكتب نصك هنا. يمكنك إدراج المتغيرات مثل {{first_name}}.</p>']],
            ['type' => 'heading', 'label' => 'عنوان', 'icon' => '🅷', 'default' => ['type' => 'heading', 'text' => 'عنوان رئيسي', 'level' => 'h2', 'align' => 'right']],
            ['type' => 'image', 'label' => 'صورة', 'icon' => '🖼', 'default' => ['type' => 'image', 'src' => '', 'alt' => '', 'width' => '600', 'url' => '']],
            ['type' => 'button', 'label' => 'زر', 'icon' => '🔘', 'default' => ['type' => 'button', 'text' => 'اضغط هنا', 'url' => 'https://example.com', 'bg' => '#2563eb', 'color' => '#ffffff']],
            ['type' => 'divider', 'label' => 'فاصل', 'icon' => '➖', 'default' => ['type' => 'divider', 'color' => '#e5e7eb', 'thickness' => '1']],
            ['type' => 'spacer', 'label' => 'مسافة', 'icon' => '⬜', 'default' => ['type' => 'spacer', 'height' => '24']],
            ['type' => 'social', 'label' => 'سوشيال', 'icon' => '🌐', 'default' => ['type' => 'social', 'networks' => ['facebook', 'twitter', 'instagram', 'linkedin']]],
            ['type' => 'video', 'label' => 'فيديو', 'icon' => '🎬', 'default' => ['type' => 'video', 'url' => '', 'thumbnail' => '', 'caption' => '']],
            ['type' => 'columns', 'label' => 'عمودين (كروت رحلات)', 'icon' => '🧳', 'default' => ['type' => 'columns', 'left_title' => 'رحلة 1', 'right_title' => 'رحلة 2']],
            ['type' => 'html', 'label' => 'كود HTML', 'icon' => '💻', 'default' => ['type' => 'html', 'html' => '<div style="background:#f3f4f6;padding:16px;border-radius:8px;">كود مخصص</div>']],
        ];
    }

    /**
     * تحويل مصفوفة بلوكات إلى HTML إيميل (جداول متوافقة مع عملاء البريد).
     * @param array<int, array> $blocks
     */
    public function blocksToHtml(array $blocks): string
    {
        $rows = '';
        foreach ($blocks as $block) {
            $rows .= $this->blockToHtml($block);
        }

        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '</head><body style="margin:0;padding:0;background-color:#f3f4f6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;padding:24px 0;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;">'
            . $rows
            . '</table></td></tr></table></body></html>';
    }

    /** تحويل بلوك واحد إلى HTML سطر جدول. */
    private function blockToHtml(array $block): string
    {
        $type = (string) ($block['type'] ?? 'text');
        switch ($type) {
            case 'text':
                return $this->wrapCell((string) ($block['content'] ?? ''), $block);
            case 'heading':
                $level = in_array((string) ($block['level'] ?? 'h2'), ['h1', 'h2', 'h3', 'h4'], true) ? $block['level'] : 'h2';
                $align = (string) ($block['align'] ?? 'right');
                $size = ['h1' => '28px', 'h2' => '24px', 'h3' => '20px', 'h4' => '17px'][$level];
                $html = "<{$level} style=\"text-align:{$align};font-size:{$size};line-height:1.3;margin:0 0 12px 0;color:#111827;font-family:Arial,Helvetica,sans-serif;\">"
                    . $this->safe((string) ($block['text'] ?? ''))
                    . "</{$level}>";
                return $this->wrapCell($html, $block);
            case 'image':
                $src = (string) ($block['src'] ?? '');
                if ($src === '') {
                    return $this->wrapCell(
                        '<div style="background:#e5e7eb;color:#6b7280;text-align:center;padding:24px;border:1px dashed #9ca3af;border-radius:8px;font-size:13px;">أضف رابط صورة من اللوحة الجانبية</div>',
                        $block
                    );
                }
                $alt = htmlspecialchars((string) ($block['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
                $width = (int) ($block['width'] ?? 600);
                $width = max(1, min(600, $width));
                $img = "<img src=\"{$src}\" alt=\"{$alt}\" width=\"{$width}\" style=\"max-width:100%;height:auto;display:block;border:0;\" />";
                $url = (string) ($block['url'] ?? '');
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $img = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none;">' . $img . '</a>';
                }
                return $this->wrapCell($img, $block);
            case 'button':
                $text = $this->safe((string) ($block['text'] ?? 'اضغط هنا'));
                $url = (string) ($block['url'] ?? '#');
                $bg = $this->safe((string) ($block['bg'] ?? '#2563eb'));
                $color = $this->safe((string) ($block['color'] ?? '#ffffff'));
                $btn = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td style="border-radius:8px;background-color:' . $bg . ';">'
                    . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:' . $color . ';text-decoration:none;border-radius:8px;background-color:' . $bg . ';">' . $text . '</a>'
                    . '</td></tr></table>';
                return $this->wrapCell($btn, $block);
            case 'divider':
                $color = $this->safe((string) ($block['color'] ?? '#e5e7eb'));
                $thickness = $this->safe((string) ($block['thickness'] ?? '1'));
                return $this->wrapCell(
                    '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:' . $thickness . 'px solid ' . $color . ';font-size:0;line-height:0;">&nbsp;</td></tr></table>',
                    $block
                );
            case 'spacer':
                $height = (int) ($block['height'] ?? 24);
                $height = max(1, min(200, $height));
                return $this->wrapCell('<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:0;">&nbsp;</div>', $block);
            case 'social':
                $networks = $block['networks'] ?? [];
                $icons = [
                    'facebook' => ['https://facebook.com', '#1877F2', 'f'],
                    'twitter' => ['https://twitter.com', '#000000', '𝕏'],
                    'instagram' => ['https://instagram.com', '#E4405F', '◉'],
                    'linkedin' => ['https://linkedin.com', '#0A66C2', 'in'],
                    'youtube' => ['https://youtube.com', '#FF0000', '▶'],
                    'whatsapp' => ['https://whatsapp.com', '#25D366', '✆'],
                ];
                $cells = '';
                foreach ($networks as $net) {
                    $net = (string) $net;
                    if (!isset($icons[$net])) {
                        continue;
                    }
                    [$url, $bg, $glyph] = $icons[$net];
                    // رابط صفحة الشركة نفسها لو اتحدد (قبل كده كان بيودّي للصفحة الرئيسية للشبكة)
                    $custom = (string) ($block['link_' . $net] ?? '');
                    if (preg_match('#^https?://#i', $custom)) {
                        $url = htmlspecialchars($custom, ENT_QUOTES, 'UTF-8');
                    }
                    $cells .= '<td align="center" style="padding:0 5px;"><a href="' . $url . '" style="display:inline-block;width:36px;height:36px;line-height:36px;border-radius:50%;background-color:' . $bg . ';color:#ffffff;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;text-decoration:none;">' . $glyph . '</a></td>';
                }
                return $this->wrapCell(
                    $cells === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr>' . $cells . '</tr></table>',
                    $block
                );
            case 'video':
                return $this->wrapCell(self::videoHtml($block), $block);
            case 'columns':
                return $this->wrapCell(self::columnsHtml($block), $block);
            case 'html':
                return $this->wrapCell((string) ($block['html'] ?? ''), $block);
            default:
                return '';
        }
    }

    /** معرف فيديو يوتيوب من أي شكل رابط، أو '' */
    public static function youtubeId(string $url): string
    {
        return preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : '';
    }

    /**
     * الإيميل ما بيشغّلش فيديو: صورة مصغرة عليها ▶ ومربوطة بالفيديو (نفس أسلوب Mailchimp).
     */
    public static function videoHtml(array $block): string
    {
        $url = (string) ($block['url'] ?? '');
        if (!preg_match('#^https?://#i', $url)) {
            return '<div style="background:#111827;color:#9ca3af;text-align:center;padding:40px;border-radius:8px;font-size:13px;">أضف رابط الفيديو من اللوحة الجانبية</div>';
        }
        $thumb = (string) ($block['thumbnail'] ?? '');
        if (!preg_match('#^https?://#i', $thumb)) {
            $id = self::youtubeId($url);
            $thumb = $id !== '' ? 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg' : '';
        }
        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $visual = $thumb !== ''
            ? '<img src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="▶" width="552" style="max-width:100%;height:auto;display:block;border:0;border-radius:8px;" />'
            : '<div style="background:#111827;height:260px;border-radius:8px;"></div>';
        $html = '<a href="' . $href . '" style="text-decoration:none;display:block;">' . $visual
            . '<div style="text-align:center;margin-top:-58px;margin-bottom:22px;"><span style="display:inline-block;background:rgba(17,24,39,.85);color:#ffffff;border-radius:999px;padding:10px 22px;font-size:15px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">▶ تشغيل الفيديو</span></div></a>';
        $caption = trim((string) ($block['caption'] ?? ''));
        if ($caption !== '') {
            $html .= '<p style="margin:8px 0 0;text-align:center;color:#6b7280;font-size:13px;">' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return $html;
    }

    /** عمودين (مثلًا كارتين رحلات) — بيتراصّوا تحت بعض على الموبايل (inline-block) */
    public static function columnsHtml(array $block): string
    {
        $cells = '';
        foreach (['left', 'right'] as $side) {
            $img = (string) ($block[$side . '_image'] ?? '');
            $title = htmlspecialchars((string) ($block[$side . '_title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars((string) ($block[$side . '_text'] ?? ''), ENT_QUOTES, 'UTF-8');
            $btn = htmlspecialchars((string) ($block[$side . '_button'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url = (string) ($block[$side . '_url'] ?? '');
            $href = preg_match('#^https?://#i', $url) ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : '';
            $inner = '';
            if (preg_match('#^https?://#i', $img)) {
                $imgTag = '<img src="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" width="252" style="max-width:100%;height:auto;display:block;border:0;border-radius:8px;" />';
                $inner .= $href !== '' ? '<a href="' . $href . '">' . $imgTag . '</a>' : $imgTag;
            }
            if ($title !== '') {
                $inner .= '<h3 style="margin:10px 0 6px;font-size:17px;color:#111827;">' . $title . '</h3>';
            }
            if ($text !== '') {
                $inner .= '<p style="margin:0 0 10px;font-size:14px;color:#4b5563;">' . $text . '</p>';
            }
            if ($btn !== '' && $href !== '') {
                $inner .= '<a href="' . $href . '" style="display:inline-block;background:#2563eb;color:#ffffff;padding:9px 18px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">' . $btn . '</a>';
            }
            $cells .= '<div style="display:inline-block;vertical-align:top;width:100%;max-width:262px;margin:0 4px 12px;text-align:start;">' . $inner . '</div>';
        }
        return '<div style="text-align:center;font-size:0;">' . str_replace('<div style="display:inline-block', '<div style="font-size:15px;display:inline-block', $cells) . '</div>';
    }

    private function wrapCell(string $inner, array $block = []): string
    {
        if ($inner === '') {
            return '';
        }
        $padding = isset($block['type']) && in_array($block['type'], ['divider', 'spacer'], true) ? '0' : '24px';
        return '<tr><td style="padding:' . $padding . ';font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#374151;">'
            . $inner . '</td></tr>';
    }

    private function safe(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    // ============================ Template Catalog (Gallery) ============================

    /**
     * معرض القوالب المدمجة (Brevo-style). كل قالب = كتل JSON قابلة للتعديل.
     * @return array<string, array>
     */
    public function catalog(): array
    {
        return [
            'welcome' => [
                'name' => 'ترحيب بالعميل الجديد',
                'category' => 'welcome',
                'subject' => 'أهلًا {{first_name}}! نرحب بك في {{company_name}}',
                'description' => 'رسالة ترحيب ودية للمشترك الجديد مع عرض قيمة وزر لبدء الاستخدام.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '32'],
                    ['type' => 'heading', 'text' => 'أهلًا بك!', 'level' => 'h1', 'align' => 'center'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;color:#6b7280;">نحن متحمسون جدًا لانضمامك إلينا، {{first_name}}. سنرسل لك أفضل العروض والأخبار أولًا بأول.</p>'],
                    ['type' => 'image', 'src' => 'https://placehold.co/600x260/2563eb/ffffff?text=Welcome', 'alt' => 'مرحبًا', 'width' => '600'],
                    ['type' => 'button', 'text' => 'ابدأ الآن', 'url' => 'https://example.com', 'bg' => '#2563eb', 'color' => '#ffffff'],
                    ['type' => 'spacer', 'height' => '16'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;font-size:13px;color:#9ca3af;">إذا كان لديك أي سؤال، تواصل معنا في أي وقت.</p>'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
            'newsletter' => [
                'name' => 'نشرة أخبار شهرية',
                'category' => 'newsletter',
                'subject' => 'نشرة {{company_name}} — كل جديد في الشهر',
                'description' => 'نشرة دورية بأحدث الأخبار والمقالات والموارد المفيدة.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '24'],
                    ['type' => 'heading', 'text' => 'نشرة هذا الشهر', 'level' => 'h2', 'align' => 'right'],
                    ['type' => 'text', 'content' => '<p>مرحبًا {{first_name}}، إليك أبرز ما حدث هذا الشهر:</p>'],
                    ['type' => 'heading', 'text' => '🚀 إطلاق ميزة جديدة', 'level' => 'h3', 'align' => 'right'],
                    ['type' => 'text', 'content' => '<p>أطلقنا الميزة التي طال انتظارها. جرّبها الآن واستمتع بتجربة أفضل.</p>'],
                    ['type' => 'button', 'text' => 'اقرأ المزيد', 'url' => 'https://example.com', 'bg' => '#16a34a', 'color' => '#ffffff'],
                    ['type' => 'divider', 'color' => '#e5e7eb', 'thickness' => '1'],
                    ['type' => 'heading', 'text' => '💡 نصائح سريعة', 'level' => 'h3', 'align' => 'right'],
                    ['type' => 'text', 'content' => '<ul><li>نصيحة أولى لتحسين أدائك</li><li>نصيحة ثانية توفّر وقتك</li><li>نصيحة ثالثة تزيد مبيعاتك</li></ul>'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
            'promo' => [
                'name' => 'عرض ترويجي (خصم)',
                'category' => 'promo',
                'subject' => 'خصم 20% لعملائنا الأعزاء {{first_name}} 🎉',
                'description' => 'حملة ترويجية قوية بكود خصم وزر تحويل واضح.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '32'],
                    ['type' => 'heading', 'text' => 'خصم خاص 20%', 'level' => 'h1', 'align' => 'center'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;">بمناسبة عيد ميلادنا العاشر، خصم 20% على كل المنتجات لفترة محدودة فقط.</p>'],
                    ['type' => 'image', 'src' => 'https://placehold.co/600x200/dc2626/ffffff?text=20%25+OFF', 'alt' => 'خصم 20%', 'width' => '600'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;background:#fef3c7;border:1px dashed #f59e0b;padding:14px;border-radius:8px;font-weight:700;font-size:20px;color:#92400e;">WELCOME20</p>'],
                    ['type' => 'button', 'text' => 'اطلب الآن', 'url' => 'https://example.com', 'bg' => '#dc2626', 'color' => '#ffffff'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;font-size:12px;color:#9ca3af;">ينتهي العرض نهاية الشهر. لا يفوتك!</p>'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
            'event' => [
                'name' => 'دعوة حدث / ندوة',
                'category' => 'event',
                'subject' => 'دعوة لحضور ندوة {{campaign_name}}',
                'description' => 'دعوة أنيقة لحدث أو ندوة مع تفاصيل الوقت والمكان وزر تسجيل.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '28'],
                    ['type' => 'heading', 'text' => 'ندوة حصرية', 'level' => 'h1', 'align' => 'center'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;">يسعدنا دعوتك لحضور ندوتنا القادمة حول أحدث اتجاهات السوق.</p>'],
                    ['type' => 'image', 'src' => 'https://placehold.co/600x240/7c3aed/ffffff?text=Live+Webinar', 'alt' => 'ندوة', 'width' => '600'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;font-weight:700;">📅 الأربعاء 15 أغسطس 2026<br>🕒 4:00 مساءً بتوقيت مكة</p>'],
                    ['type' => 'button', 'text' => 'احجز مقعدك', 'url' => 'https://example.com', 'bg' => '#7c3aed', 'color' => '#ffffff'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
            'transactional' => [
                'name' => 'تأكيد طلب / فاتورة',
                'category' => 'transactional',
                'subject' => 'تم استلام طلبك رقم #1001 — {{company_name}}',
                'description' => 'تأكيد عملية شراء بتفاصيل الطلب وزر تتبع الشحنة.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '24'],
                    ['type' => 'heading', 'text' => '✅ شكرًا لطلبك!', 'level' => 'h2', 'align' => 'right'],
                    ['type' => 'text', 'content' => '<p>أهلًا {{first_name}}، تم استلام طلبك بنجاح وسنبدأ تجهيزه فورًا.</p>'],
                    ['type' => 'image', 'src' => 'https://placehold.co/600x220/059669/ffffff?text=Order+Confirmed', 'alt' => 'تأكيد الطلب', 'width' => '600'],
                    ['type' => 'text', 'content' => '<p style="background:#ecfdf5;padding:16px;border-radius:8px;border:1px solid #a7f3d0;">رقم الطلب: <b>#1001</b><br>التاريخ: 22 أغسطس 2026<br>الحالة: <b>قيد التجهيز</b></p>'],
                    ['type' => 'button', 'text' => 'تتبع طلبك', 'url' => 'https://example.com', 'bg' => '#059669', 'color' => '#ffffff'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
            'holiday' => [
                'name' => 'تهنئة مناسبة / عيد',
                'category' => 'holiday',
                'subject' => 'كل عام وأنتم بخير 🌙 — {{company_name}}',
                'description' => 'تهنئة دافئة بمناسبة العيد مع لمسة احتفالية.',
                'blocks' => [
                    ['type' => 'spacer', 'height' => '32'],
                    ['type' => 'heading', 'text' => '🎉 كل عام وأنتم بخير', 'level' => 'h1', 'align' => 'center'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;">في هذه المناسبة الجميلة، نتمنى لكم ولعائلاتكم أيامًا سعيدة مليئة بالفرح والمحبة.</p>'],
                    ['type' => 'image', 'src' => 'https://placehold.co/600x260/f59e0b/ffffff?text=Eid+Mubarak', 'alt' => 'تهنئة', 'width' => '600'],
                    ['type' => 'text', 'content' => '<p style="text-align:center;font-weight:700;font-size:18px;">نسعد دائمًا بخدمتكم 🌙</p>'],
                    ['type' => 'spacer', 'height' => '24'],
                ],
            ],
        ];
    }

    /** تصنيفات المعرض للفلترة. */
    public function categories(): array
    {
        return [
            'welcome' => 'ترحيب',
            'newsletter' => 'نشرة إخبارية',
            'promo' => 'ترويجي',
            'event' => 'أحداث',
            'transactional' => 'معاملات',
            'holiday' => 'مناسبات',
        ];
    }

    /**
     * إنشاء نسخة من قالب المعرض للمستخدم.
     */
    public function createFromCatalog(int $userId, string $catalogKey): array
    {
        $catalog = $this->catalog();
        if (!isset($catalog[$catalogKey])) {
            return ['success' => false, 'error' => 'القالب غير موجود في المعرض'];
        }
        $item = $catalog[$catalogKey];
        $template = new EmailTemplate([
            'user_id' => $userId,
            'name' => $item['name'],
            'subject' => $item['subject'],
            'category' => $item['category'],
            'blocks' => json_encode($item['blocks'], JSON_UNESCAPED_UNICODE),
            'html_body' => $this->blocksToHtml($item['blocks']),
        ]);
        $id = (int) $template->save();
        if ($id <= 0) {
            return ['success' => false, 'error' => 'تعذر إنشاء القالب'];
        }
        return ['success' => true, 'id' => $id];
    }

    // ============================ Duplicate & Share ============================

    /**
     * نسخ قالب مملوك إلى قالب جديد "(نسخة)".
     */
    public function duplicateTemplate(int $userId, int $templateId): array
    {
        $template = $this->findOwned($userId, $templateId);
        if (!$template) {
            return ['success' => false, 'error' => 'القالب غير موجود'];
        }
        $copy = new EmailTemplate([
            'user_id' => $userId,
            'name' => trim((string) $template->getAttribute('name')) . ' (نسخة)',
            'subject' => (string) $template->getAttribute('subject'),
            'category' => $template->getAttribute('category'),
            'html_body' => (string) $template->getAttribute('html_body'),
            'blocks' => (string) $template->getAttribute('blocks'),
        ]);
        $id = (int) $copy->save();
        return $id > 0 ? ['success' => true, 'id' => $id] : ['success' => false, 'error' => 'تعذر النسخ'];
    }

    /**
     * نسخ حملة (ممسوودة/مجدولة فقط) مع قوالبها وجمهورها.
     */
    public function duplicateCampaign(int $userId, int $campaignId): array
    {
        $campaign = (new EmailCampaign())->find($campaignId);
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        if (in_array($campaign->getAttribute('status'), ['sending', 'sent'], true)) {
            return ['success' => false, 'error' => 'لا يمكن نسخ حملة أُرسلت أو قيد الإرسال'];
        }
        $audienceIds = (string) $campaign->getAttribute('audience_ids');
        $copy = new EmailCampaign([
            'user_id' => $userId,
            'name' => trim((string) $campaign->getAttribute('name')) . ' (نسخة)',
            'subject' => (string) $campaign->getAttribute('subject'),
            'from_name' => $campaign->getAttribute('from_name'),
            'from_email' => $campaign->getAttribute('from_email'),
            'template_id' => $campaign->getAttribute('template_id'),
            'list_id' => $campaign->getAttribute('list_id'),
            'audience_ids' => $audienceIds === '' || $audienceIds === null ? null : $audienceIds,
            'html_body' => (string) $campaign->getAttribute('html_body'),
            'status' => 'draft',
            'scheduled_at' => null,
        ]);
        $id = (int) $copy->save();
        return $id > 0 ? ['success' => true, 'id' => $id] : ['success' => false, 'error' => 'تعذر نسخ الحملة'];
    }

    /**
     * تفعيل/إلغاء المشاركة العامة لقالب.
     */
    public function setShared(int $userId, int $templateId, bool $enabled): array
    {
        $template = $this->findOwned($userId, $templateId);
        if (!$template) {
            return ['success' => false, 'error' => 'القالب غير موجود'];
        }
        if ($enabled) {
            if ($template->getAttribute('share_token') === null || $template->getAttribute('share_token') === '') {
                $template->setAttribute('share_token', $this->token());
            }
        } else {
            $template->setAttribute('share_token', null);
        }
        $template->save();
        return ['success' => true, 'share_token' => $template->getAttribute('share_token')];
    }

    /** جلب قالب عبر رمز المشاركة العام (دون تحقق ملكية). */
    public function byShareToken(string $token): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM email_templates WHERE share_token = ? LIMIT 1",
            [$token]
        );
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        $row['blocks'] = json_decode((string) ($row['blocks'] ?? 'null'), true) ?: [];
        return $row;
    }

    /**
     * استيراد قالب مشترك (public) إلى حساب المستخدم كنسخة جديدة.
     */
    public function importShared(int $userId, string $token): array
    {
        $shared = $this->byShareToken($token);
        if (!$shared) {
            return ['success' => false, 'error' => 'القالب المشترك غير موجود'];
        }
        $template = new EmailTemplate([
            'user_id' => $userId,
            'name' => (string) $shared['name'],
            'subject' => (string) $shared['subject'],
            'category' => $shared['category'],
            'html_body' => (string) $shared['html_body'],
            'blocks' => json_encode($shared['blocks'], JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) $template->save();
        return $id > 0 ? ['success' => true, 'id' => $id] : ['success' => false, 'error' => 'تعذر الاستيراد'];
    }

    private function findOwned(int $userId, int $templateId): ?EmailTemplate
    {
        $template = (new EmailTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $template;
    }

    private function token(): string
    {
        return bin2hex(random_bytes(24));
    }
}
