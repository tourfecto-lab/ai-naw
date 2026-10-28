<?php

/**
 * Tourfecto - صفحة مراجعة الخطة الشهرية للعميل (/review/{token}) - عامة من غير تسجيل دخول.
 * الأمان: التوكن السري في الرابط (48 hex) + مدة صلاحية + إمكانية الإلغاء. ما بيظهرش أي بيانات داخلية.
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialPlanShare.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
class SocialReviewController extends Controller
{
    private function link(array $params): ?array
    {
        return (new SocialPlanShare())->resolve((string) ($params['token'] ?? ''));
    }

    private function lang(array $link): string
    {
        $l = (string) ($this->db->query('SELECT language FROM social_monthly_plans WHERE id = ?', [(int) $link['plan_id']])[0]['language'] ?? 'ar');
        return $l === 'en' ? 'en' : 'ar';
    }

    /** GET /review/{token} */
    public function show(array $params = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $link = $this->link($params);
        if (!$link) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link expired</title><body style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;padding:0 16px;text-align:center;line-height:1.8"><h2>الرابط انتهى أو اتلغى</h2><p>اطلب رابط جديد من الشركة اللي بعتتهولك.<br><span style="color:#888">This review link has expired or was revoked.</span></p></body>';
            exit;
        }
        $ar = $this->lang($link) === 'ar';
        $v = (new SocialPlanShare())->publicView($link);
        echo self::render($v, $ar, (string) $params['token']);
        exit;
    }

    /** GET /review/{token}/media/{item} - صورة/فيديو المنشور للمعاينة */
    public function media(array $params = []): void
    {
        $link = $this->link($params);
        $m = $link ? (new SocialPlanShare())->mediaPath($link, (int) ($params['item'] ?? 0)) : null;
        if (!$m || !is_file($m['file'])) {
            http_response_code(404);
            exit;
        }
        $mime = (function_exists('mime_content_type') ? mime_content_type($m['file']) : null) ?: ($m['video'] ? 'video/mp4' : 'image/jpeg');
        if (!preg_match('#^(image|video)/#', $mime)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($m['file']));
        header('Cache-Control: private, max-age=600');
        header('X-Content-Type-Options: nosniff');
        readfile($m['file']);
        exit;
    }

    /** POST /review/{token}/feedback  body: {decision: comment|changes|approved, message, item_id?, name} */
    public function feedback(array $params = []): array
    {
        $link = $this->link($params);
        if (!$link) {
            return $this->error('الرابط انتهى أو اتلغى', 404);
        }
        if ($rate = $this->rateLimitGuard('ip', 'social_review_feedback', 30, 600)) {
            return $rate;
        }
        try {
            $item = (int) $this->get('item_id', 0);
            $r = (new SocialPlanShare())->submit($link, (string) $this->get('decision', 'comment'), (string) $this->get('message', ''), $item > 0 ? $item : null, (string) $this->get('name', ''));
            return $this->success($r);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private static function render(array $v, bool $ar, string $token): string
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $a, string $b): string => htmlspecialchars($ar ? $a : $b, ENT_QUOTES, 'UTF-8');
        $plat = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'];
        $fb = [];
        foreach ($v['feedback'] as $f) {
            $fb[(int) ($f['item_id'] ?? 0)][] = $f;
        }
        $items = '';
        foreach ($v['items'] as $i) {
            $when = str_replace('T', ' ', $i['when']);
            $notes = '';
            foreach ($fb[$i['id']] ?? [] as $f) {
                $notes .= '<div class="note"><b>' . $e($f['author_name']) . ':</b> ' . nl2br($e($f['message'] ?? '')) . '</div>';
            }
            $media = $i['has_media'] ? '<div class="media"><img loading="lazy" src="/review/' . $e($token) . '/media/' . (int) $i['id'] . '" alt="" onerror="this.parentNode.innerHTML=\'<video src=&quot;/review/' . $e($token) . '/media/' . (int) $i['id'] . '&quot; controls muted playsinline></video>\'"></div>' : '';
            $items .= '<article class="item" id="i' . (int) $i['id'] . '"><header><span class="pl">' . $e($plat[$i['platform']] ?? $i['platform']) . '</span> <b>' . $e($i['page']) . '</b><span class="when">🗓 ' . $e($when) . '</span></header>'
                . $media . '<div class="body">' . ($i['theme'] ? '<h3>' . $e($i['theme']) . '</h3>' : '') . ($i['headline'] ? '<div class="hl">' . $t('على التصميم: ', 'On the design: ') . $e($i['headline']) . '</div>' : '')
                . '<p class="cap">' . nl2br($e($i['content'])) . '</p>' . $notes
                . '<details><summary>' . $t('💬 ملاحظة على المنشور ده', '💬 Comment on this post') . '</summary><textarea rows="3" maxlength="2000" data-item="' . (int) $i['id'] . '"></textarea><button type="button" class="btn" data-send="' . (int) $i['id'] . '">' . $t('إرسال الملاحظة', 'Send comment') . '</button></details></div></article>';
        }
        $general = '';
        foreach ($fb[0] ?? [] as $f) {
            $label = ['approved' => $t('✅ اعتمد', '✅ Approved'), 'changes' => $t('✏️ طلب تعديلات', '✏️ Requested changes'), 'comment' => '💬'][$f['decision']] ?? '';
            $general .= '<div class="note">' . $label . ' <b>' . $e($f['author_name']) . '</b> ' . nl2br($e($f['message'] ?? '')) . ' <span class="muted">' . $e($f['created_at']) . '</span></div>';
        }
        $status = ['draft' => $t('في انتظار مراجعتك', 'Awaiting your review'), 'active' => $t('معتمدة وبتتنشر', 'Approved & publishing'), 'paused' => $t('متوقفة مؤقتًا', 'Paused'), 'completed' => $t('اتنشرت بالكامل', 'Fully published')][$v['status']] ?? $e($v['status']);
        $actions = $v['status'] === 'draft'
            ? '<div class="actions">' . ($v['can_approve'] ? '<button type="button" class="btn primary" id="approve">✅ ' . $t('اعتماد الخطة للنشر', 'Approve plan for publishing') . '</button>' : '') . '<button type="button" class="btn" id="changes">✏️ ' . $t('طلب تعديلات', 'Request changes') . '</button></div>'
            : '';
        $dir = $ar ? 'rtl' : 'ltr';
        $js = 'const T=' . json_encode(['name' => $ar ? 'اسمك' : 'Your name', 'sent' => $ar ? 'اتبعت ✔ شكرًا' : 'Sent ✔ thank you', 'err' => $ar ? 'تعذر الإرسال' : 'Could not send', 'changes' => $ar ? 'اكتب التعديلات المطلوبة:' : 'Describe the changes you need:', 'confirm' => $ar ? 'تأكيد اعتماد الخطة؟ المنشورات هتتجدول للنشر في مواعيدها.' : 'Approve the plan? Posts will be scheduled to publish on their dates.', 'approved' => $ar ? 'اتعتمدت الخطة ✔' : 'Plan approved ✔', 'pending' => $ar ? 'اتسجلت موافقتك ✔ والشركة هتكمّل الاعتماد' : 'Your approval was recorded ✔ the company will finalise it'], JSON_UNESCAPED_UNICODE) . ';'
            . 'const base=location.pathname.replace(/\/$/,"");const nameEl=document.getElementById("who");'
            . 'async function send(body){body.name=nameEl.value.trim();try{const r=await fetch(base+"/feedback",{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(body)});const j=await r.json();if(!j.success){alert(j.error||T.err);return null;}return j.data;}catch(e){alert(T.err);return null;}}'
            . 'document.addEventListener("click",async e=>{const b=e.target.closest("[data-send]");if(b){const ta=document.querySelector("textarea[data-item=\""+b.dataset.send+"\"]");if(!ta.value.trim())return;b.disabled=true;const d=await send({decision:"comment",item_id:+b.dataset.send,message:ta.value});b.disabled=false;if(d){alert(T.sent);location.reload();}}});'
            . 'const ap=document.getElementById("approve");if(ap)ap.onclick=async()=>{if(!confirm(T.confirm))return;ap.disabled=true;const d=await send({decision:"approved",message:document.getElementById("gen").value});ap.disabled=false;if(d){alert(d.approved?T.approved:T.pending);location.reload();}};'
            . 'const ch=document.getElementById("changes");if(ch)ch.onclick=async()=>{const m=document.getElementById("gen").value.trim()||prompt(T.changes);if(!m)return;const d=await send({decision:"changes",message:m});if(d){alert(T.sent);location.reload();}};'
            . 'try{nameEl.value=localStorage.getItem("tf_review_name")||nameEl.value;nameEl.onchange=()=>localStorage.setItem("tf_review_name",nameEl.value);}catch(e){}';
        return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $t('مراجعة خطة السوشيال', 'Social plan review') . ' ' . $e($v['month']) . '</title>'
            . '<style>:root{--a:#0f766e;--ink:#1f2937;--muted:#6b7280;--line:#e5e7eb;--bg:#f6f7f9}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}'
            . '.wrap{max-width:860px;margin:0 auto;padding:20px 16px 60px}.top{background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 14px rgba(0,0,0,.05)}h1{margin:0 0 4px;font-size:22px}.muted{color:var(--muted);font-size:13px}.badge{display:inline-block;background:#ecfdf5;color:var(--a);border-radius:999px;padding:2px 10px;font-size:13px;font-weight:600}'
            . '.strategy{margin-top:10px;font-size:14px}.item{background:#fff;border-radius:16px;margin-top:16px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,.05)}.item header{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--line);font-size:14px}.pl{background:#eef2ff;color:#3730a3;border-radius:6px;padding:1px 8px;font-size:12px}.when{margin-inline-start:auto;color:var(--muted)}'
            . '.media{background:#000}.media img,.media video{display:block;width:100%;max-height:520px;object-fit:contain}.body{padding:12px 16px 16px}.body h3{margin:0 0 6px;font-size:16px}.hl{font-size:13px;color:var(--a);margin-bottom:6px}.cap{white-space:normal;margin:0 0 8px}'
            . 'details summary{cursor:pointer;color:var(--a);font-size:14px}textarea,input{width:100%;border:1px solid var(--line);border-radius:10px;padding:8px 10px;font:inherit;margin-top:8px}.btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 16px;font:inherit;cursor:pointer;margin-top:8px}.btn.primary{background:var(--a);border-color:var(--a);color:#fff;font-weight:600}'
            . '.note{background:#f9fafb;border-radius:10px;padding:6px 10px;margin:6px 0;font-size:13.5px}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.box{background:#fff;border-radius:16px;padding:16px 20px;margin-top:16px;box-shadow:0 2px 14px rgba(0,0,0,.05)}</style></head><body><main class="wrap">'
            . '<section class="top"><div class="muted">' . $e($v['company']) . '</div><h1>' . $t('خطة السوشيال ميديا لشهر', 'Social media plan for') . ' ' . $e($v['month']) . '</h1><span class="badge">' . $status . '</span> <span class="muted">' . count($v['items']) . ' ' . $t('منشور', 'posts') . ' · ' . $t('المواعيد بتوقيت ', 'Times in ') . $e($v['timezone']) . '</span>'
            . ($v['strategy'] ? '<div class="strategy">' . nl2br($e($v['strategy'])) . '</div>' : '')
            . '<label class="muted" style="display:block;margin-top:12px">' . $t('اسمك', 'Your name') . '<input id="who" maxlength="120" value="' . $e($v['client_name']) . '"></label>'
            . '<textarea id="gen" rows="2" maxlength="2000" placeholder="' . $t('ملاحظة عامة على الخطة (اختياري)', 'General note on the plan (optional)') . '"></textarea>' . $actions . $general . '</section>'
            . $items . '</main><script>' . $js . '</script></body></html>';
    }
}
