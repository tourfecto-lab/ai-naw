<?php

/**
 * Customer-facing support: open a ticket (problem, question, complaint, suggestion), follow the answers, close and rate.
 * Staff side lives in AdminSupportController.
 */
class SupportController extends Controller
{
    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    /** write endpoints: CSRF + a rate limit so one account cannot flood the queue */
    private function guardWrite(): ?array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        return $this->rateLimitGuard('user', 'support_ticket_write', 30, 60);
    }

    /** GET /support */
    public function page(array $params = []): array
    {
        $css = htmlspecialchars(asset_v('/assets/css/support-tickets.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/support-tickets.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="supportRoot" class="st" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('support', 'الدعم والمساعدة', 'عندك مشكلة أو سؤال أو شكوى؟ افتح تذكرة وفريق الدعم هيرد عليك هنا', $body, 'window.SupportTickets&&window.SupportTickets.init(window.Panel,{mode:"customer"});');
        exit;
    }

    /** GET /api/support/tickets?status= */
    public function index(array $params = []): array
    {
        header('Cache-Control: private, no-store');
        return $this->success([
            'tickets' => SupportTicketService::listForUser($this->uid(), (string) $this->get('status', '')),
            'categories' => SupportTicketService::CATEGORIES, 'statuses' => SupportTicketService::STATUSES, 'priorities' => SupportTicketService::PRIORITIES,
            'csrf_token' => Csrf::token(),
        ]);
    }

    /** POST /api/support/tickets {subject, message, category, priority} */
    public function create(array $params = []): array
    {
        if ($e = $this->guardWrite()) {
            return $e;
        }
        try {
            $id = SupportTicketService::create($this->uid(), (string) $this->get('subject', ''), (string) $this->get('message', ''), (string) $this->get('category', 'question'), (string) $this->get('priority', 'normal'));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Support ticket create failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر فتح التذكرة، حاول تاني', 500);
        }
        $this->log('Support Ticket Created', ['ticket_id' => $id]);
        return $this->success(['id' => $id], 'تم فتح التذكرة. هنرد عليك في أقرب وقت');
    }

    /** GET /api/support/tickets/{id} */
    public function show(array $params = []): array
    {
        header('Cache-Control: private, no-store');
        $d = SupportTicketService::getForUser($this->uid(), (int) ($params['id'] ?? 0));
        return $d ? $this->success($d + ['csrf_token' => Csrf::token()]) : $this->error('التذكرة مش موجودة', 422);
    }

    /** POST /api/support/tickets/{id}/reply {message} */
    public function reply(array $params = []): array
    {
        if ($e = $this->guardWrite()) {
            return $e;
        }
        try {
            SupportTicketService::customerReply($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('message', ''));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'تم إرسال ردك');
    }

    /** POST /api/support/tickets/{id}/close  and  /reopen */
    public function close(array $params = []): array
    {
        return $this->setClosed($params, true);
    }

    public function reopen(array $params = []): array
    {
        return $this->setClosed($params, false);
    }

    private function setClosed(array $params, bool $closed): array
    {
        if ($e = $this->guardWrite()) {
            return $e;
        }
        $ok = SupportTicketService::customerSetClosed($this->uid(), (int) ($params['id'] ?? 0), $closed);
        return $ok ? $this->success([], $closed ? 'تم قفل التذكرة' : 'تم إعادة فتح التذكرة') : $this->error('مش ممكن تنفيذ ده على التذكرة دي', 422);
    }

    /** POST /api/support/tickets/{id}/rate {rating, comment} */
    public function rate(array $params = []): array
    {
        if ($e = $this->guardWrite()) {
            return $e;
        }
        try {
            SupportTicketService::rate($this->uid(), (int) ($params['id'] ?? 0), (int) $this->get('rating', 0), (string) $this->get('comment', ''));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([], 'شكرًا على تقييمك');
    }
}
