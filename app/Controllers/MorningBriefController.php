<?php

/**
 * Tourfecto - إعدادات ملخص الصبح (من الإعدادات ← الإشعارات)
 *  GET  /api/morning-brief            الإعدادات + شكل رسالة النهارده
 *  POST /api/morning-brief            حفظ
 *  POST /api/morning-brief/send-now   ابعتلي الملخص دلوقتي (تجربة)
 */
class MorningBriefController extends Controller
{
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $svc = new MorningBriefService();
        $uid = (int) $this->user['id'];
        $st = $svc->settings($uid);
        $b = $svc->build($uid, $st['language']);
        return $this->success(['settings' => $st, 'preview' => $b['text'], 'preview_ar' => $svc->build($uid, 'ar')['text'], 'preview_en' => $svc->build($uid, 'en')['text'], 'has_activity' => $b['has_activity']]);
    }

    public function save(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $r = (new MorningBriefService())->save((int) $this->user['id'], [
            'enabled' => $this->get('enabled', 0),
            'send_hour' => $this->get('send_hour', 8),
            'channel' => (string) $this->get('channel', 'auto'),
            'phone' => (string) $this->get('phone', ''),
            'skip_quiet_days' => $this->get('skip_quiet_days', 1),
            'language' => (string) $this->get('language', 'ar'),
        ]);
        return $r['ok'] ? $this->success([], 'اتحفظت إعدادات ملخص الصبح') : $this->error($r['error'], 422);
    }

    public function sendNow(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rl = $this->rateLimitGuard('user', 'morning_brief_now', 5, 3600)) {
            return $rl;
        }
        $r = (new MorningBriefService())->sendNow((int) $this->user['id']);
        if (!$r['ok']) {
            return $this->error($r['error'] ?: 'تعذر الإرسال', 422);
        }
        return $this->success(['channel' => $r['channel']], $r['channel'] === 'whatsapp' ? 'اتبعت الملخص على واتساب ✓' : 'اتبعت الملخص على إيميلك ✓');
    }
}
