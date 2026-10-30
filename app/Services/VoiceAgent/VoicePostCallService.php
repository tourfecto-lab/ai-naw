<?php

/**
 * Tourfecto - AI Voice Agent: automated post-call actions. For one finished call: summary, customer needs, next step, CRM contact/lead/note,
 * booking handling per the account's booking mode, an optional payment link, the follow-up message the caller asked for, a staff task when a
 * person must act, an owner notification and the CRM automation trigger.
 *
 * Safe to run twice: each action is recorded once per call (voice_post_call_actions), a failed step does not stop the others, and a re-run only
 * repeats what has not been done. Nothing is sent to anyone who opted out.
 */
class VoicePostCallService
{
    private VoiceDataSource $data;
    private VoicePostCallGateway $gw;
    /** @var callable */
    private $llm;
    private bool $failed = false;

    public function __construct(?VoiceDataSource $data = null, ?VoicePostCallGateway $gateway = null, ?callable $llm = null)
    {
        $this->data = $data ?? new VoiceDataStore();
        $this->gw = $gateway ?? new VoicePostCallCrmGateway();
        $this->llm = $llm ?? static function (string $prompt, array $opts): array {
            return (new AIOrchestrator())->generateContent($prompt, $opts);
        };
    }

    /** @return array<string,string> action => done|skipped|failed|already_done... */
    public function process(int $tenantId, int $callId, ?int $now = null): array
    {
        $now = $now ?? time();
        $call = $this->data->getCall($tenantId, $callId);
        if (!$call) {
            return ['error' => 'call_not_found'];
        }
        if (!empty($call['post_processed_at'])) {
            return ['skipped' => 'already_processed'];
        }
        $this->failed = false;
        $settings = $this->gw->settings($tenantId);
        $q = json_decode((string) ($call['qualification'] ?? ''), true) ?: [];
        $br = json_decode((string) ($call['booking_request'] ?? ''), true) ?: [];
        $sends = json_decode((string) ($call['send_requests'] ?? ''), true) ?: [];
        $turns = array_values(array_filter($this->data->recentTurns($tenantId, $callId, 300), static fn ($t) => $t['role'] !== 'tool'));
        $callerTurns = count(array_filter($turns, static fn ($t) => $t['role'] === 'caller'));
        $sig = VoicePostCallRules::signals($call, $q, $br, $sends);
        $lang = VoiceLanguages::normalize($call['language'] ?? 'en');
        $business = $this->data->businessName($tenantId);
        $number = VoiceAgentBrain::customerNumber($call);
        $verified = !empty($call['identity_verified']);
        $res = [];
        $st = ['contact_id' => $call['contact_id'] ? (int) $call['contact_id'] : null, 'lead_id' => $call['crm_lead_id'] ? (int) $call['crm_lead_id'] : null, 'booking_id' => $call['booking_id'] ? (int) $call['booking_id'] : null, 'pay_url' => null, 'delivery_failed' => false, 'message_text' => ''];

        // 1) summary, needs, next step
        $sum = $this->step($tenantId, $callId, $res, 'summary', function () use ($tenantId, $callId, $call, $q, $br, $turns, $callerTurns, $business) {
            $s = null;
            if ($callerTurns >= 2) {
                $r = ($this->llm)(VoicePostCallRules::summaryPrompt($turns, $business, (string) ($call['outcome'] ?? '')), ['task' => 'voice_postcall', 'user_id' => $tenantId, 'responseMimeType' => 'application/json', 'temperature' => 0.2, 'maxOutputTokens' => 500]);
                $s = !empty($r['success']) ? VoicePostCallRules::parseSummary((string) ($r['data'] ?? '')) : null;
                $this->data->updateCall($tenantId, $callId, ['ai_cost_usd_add' => (float) ($r['cost'] ?? 0)]);
            }
            $s = $s ?? VoicePostCallRules::fallbackSummary($call, $q, $br, $callerTurns);
            $this->data->updateCall($tenantId, $callId, ['summary' => $s['summary'], 'next_step' => $s['next_step'], 'needs' => json_encode($s['needs'], JSON_UNESCAPED_UNICODE)]);
            return ['status' => 'done', 'value' => $s, 'detail' => $s['follow_up_needed'] ? 'follow_up' : null];
        });
        $fresh = $this->data->getCall($tenantId, $callId) ?? $call;
        $summary = $sum['value'] ?? ['summary' => (string) ($fresh['summary'] ?? ''), 'needs' => json_decode((string) ($fresh['needs'] ?? ''), true) ?: [], 'next_step' => (string) ($fresh['next_step'] ?? ''), 'follow_up_needed' => ($fresh['next_step'] ?? '') !== '', 'urgency' => 'normal'];

        // 2) contact (never created for someone who opted out; existing contacts only get empty fields filled, and only once verified)
        $needsContact = $sig['sales'] || $sig['callback'] || $sig['send'] || $sig['booking'] || !empty($q['name']) || !empty($q['email']);
        $this->step($tenantId, $callId, $res, 'contact', function () use ($tenantId, $callId, &$st, $needsContact, $sig, $q, $number, $verified) {
            if ($sig['opted_out'] || (!$st['contact_id'] && !$needsContact)) {
                return ['status' => 'skipped', 'detail' => $sig['opted_out'] ? 'opted_out' : 'no_signal'];
            }
            if (!$st['contact_id']) {
                $ctx = $this->data->customerContext($tenantId, $number);
                $st['contact_id'] = $ctx['contact_id'] ?: $this->gw->createContact($tenantId, trim((string) ($q['name'] ?? '')) ?: 'Caller ' . $number, $number, VoicePostCallRules::validEmail($q['email'] ?? null), 'voice_ai');
            } elseif ($verified) {
                $this->gw->fillContactMissing($tenantId, $st['contact_id'], ['email' => VoicePostCallRules::validEmail($q['email'] ?? null), 'name' => $q['name'] ?? null]);
            }
            $this->data->updateCall($tenantId, $callId, ['contact_id' => $st['contact_id']]);
            return ['status' => 'done', 'ref' => (string) $st['contact_id']];
        });
        $contact = $st['contact_id'] ? $this->gw->contact($tenantId, $st['contact_id']) : null;

        // 3) lead
        $this->step($tenantId, $callId, $res, 'lead', function () use ($tenantId, $callId, &$st, $sig, $q, $br) {
            if (!$st['contact_id'] || !$sig['sales'] || $sig['opted_out']) {
                return ['status' => 'skipped', 'detail' => 'no_sales_signal'];
            }
            $st['lead_id'] = $this->gw->openLeadId($tenantId, $st['contact_id']);
            $reused = $st['lead_id'] !== null;
            if (!$reused) {
                $st['lead_id'] = $this->gw->createLead($tenantId, $st['contact_id'], [
                    'source' => 'voice_ai', 'interest' => mb_substr((string) ($q['interest'] ?? ''), 0, 200) ?: null,
                    'value' => isset($br['quoted_total']) ? (float) $br['quoted_total'] : null, 'currency' => $br['currency'] ?? 'USD', 'status' => 'new', 'next_follow_up_at' => date('Y-m-d H:i:s', time() + 86400),
                ]);
            }
            $this->data->updateCall($tenantId, $callId, ['crm_lead_id' => $st['lead_id']]);
            return ['status' => 'done', 'ref' => (string) $st['lead_id'], 'detail' => $reused ? 'existing_open_lead' : 'created'];
        });

        // 4) note on the contact
        $this->step($tenantId, $callId, $res, 'note', function () use ($tenantId, $callId, &$st, $summary, $sig) {
            if (!$st['contact_id']) {
                return ['status' => 'skipped', 'detail' => 'no_contact'];
            }
            $body = '[AI call #' . $callId . '] ' . $summary['summary'] . ($summary['next_step'] !== '' ? "\nNext step: " . $summary['next_step'] : '') . ($sig['opted_out'] ? "\nThe caller asked not to be called again (added to do-not-call)." : '');
            $this->gw->addNote($tenantId, $st['contact_id'], $body);
            return ['status' => 'done'];
        });

        // 5) booking request, per booking mode
        $decision = VoicePostCallRules::bookingDecision((string) ($settings['booking_mode'] ?? 'request_only'), isset($settings['booking_max_amount']) && $settings['booking_max_amount'] !== null ? (float) $settings['booking_max_amount'] : null, $br);
        $bookingAction = $decision['action'];
        $bName = trim((string) ($br['name'] ?? '')) ?: trim((string) ($q['name'] ?? '')) ?: (string) ($contact['name'] ?? '');
        if (in_array($bookingAction, ['create_pending', 'create_and_confirm'], true) && (!$st['contact_id'] || $bName === '' || strpos($bName, 'Caller ') === 0)) {
            $bookingAction = 'task_only'; // never create a booking without a customer record and a real name
        }
        $this->step($tenantId, $callId, $res, 'booking', function () use ($tenantId, $callId, &$st, &$bookingAction, $decision, $br, $bName, $q, $contact, $number, $now) {
            if (!in_array($bookingAction, ['create_pending', 'create_and_confirm'], true)) {
                return ['status' => 'skipped', 'detail' => $bookingAction === 'none' ? 'no_booking_request' : ($bookingAction === 'task_only' ? 'staff_will_confirm:' . $decision['reason'] : $decision['reason'])];
            }
            try {
                $b = $this->gw->createBooking($tenantId, [
                    'product_id' => (int) $br['product_id'], 'start_date' => (string) $br['date'], 'adults_count' => (int) $br['guests'], 'children_count' => 0,
                    'customer_id' => $st['contact_id'], 'customer_name' => $bName, 'customer_phone' => $number,
                    'customer_email' => VoicePostCallRules::validEmail($contact['email'] ?? null) ?? VoicePostCallRules::validEmail($q['email'] ?? null),
                    'source' => 'voice_ai', 'notes' => 'Taken by the AI voice assistant on call #' . $callId, 'expected_total' => (float) $br['quoted_total'],
                ]);
            } catch (Throwable $e) {
                $bookingAction = 'task_only'; // price changed, sold out, ...: a person takes over, so this is final and not retried
                return ['status' => 'skipped', 'detail' => 'booking_refused: ' . $e->getMessage()];
            }
            $st['booking_id'] = $b['id'];
            $this->data->updateCall($tenantId, $callId, ['booking_id' => $b['id']]);
            if ($bookingAction === 'create_and_confirm') {
                $this->gw->confirmBooking($tenantId, $b['id']);
            }
            return ['status' => 'done', 'ref' => (string) $b['id'], 'detail' => $b['reference']];
        });

        // 6) payment link (only when the account turned them on and a booking exists)
        $pct = (int) ($settings['payment_link_pct'] ?? 0);
        $pl = $this->step($tenantId, $callId, $res, 'payment_link', function () use ($tenantId, &$st, $pct, $sig) {
            if (!$st['booking_id'] || $pct < 5 || $sig['opted_out']) {
                return ['status' => 'skipped', 'detail' => !$st['booking_id'] ? 'no_booking' : 'payment_links_off'];
            }
            $url = $this->gw->createPaymentLink($tenantId, $st['booking_id'], $pct);
            return $url === null ? ['status' => 'skipped', 'detail' => 'account_cannot_collect_payments'] : ['status' => 'done', 'ref' => 'link', 'detail' => $url];
        });
        $st['pay_url'] = ($pl['status'] ?? '') === 'done' ? ($pl['detail'] ?? null) : null; // on a re-run the stored detail holds the URL

        // 7) the message the caller asked for (or the confirmation of the booking just taken)
        $ms = $this->step($tenantId, $callId, $res, 'message', function () use ($tenantId, $callId, &$st, $settings, $q, $br, $sends, $sig, $contact, $verified, $number, $lang, $business) {
            if ($sig['opted_out']) {
                return ['status' => 'skipped', 'detail' => 'opted_out'];
            }
            if (!$sends && !$st['booking_id']) {
                return ['status' => 'skipped', 'detail' => 'nothing_requested'];
            }
            $d = ['pay_url' => $st['pay_url']];
            $pid = (int) ($br['product_id'] ?? ($sends[0]['product_id'] ?? 0));
            if (!$pid) {
                foreach ($sends as $sr) {
                    $pid = $pid ?: (int) ($sr['product_id'] ?? 0);
                }
            }
            $trip = $pid ? $this->gw->trip($tenantId, $pid) : null;
            if ($trip) {
                $d['trip'] = $trip['name'];
                $d['currency'] = $trip['currency'];
                $d['unit_price'] = $trip['price'];
                if ($st['booking_id'] && !empty($br)) { // figures of the booking just created, not the catalogue
                    $d += ['date' => $br['date'] ?? null, 'guests' => (int) ($br['guests'] ?? 0), 'total' => (float) ($br['quoted_total'] ?? 0)];
                }
            }
            $msg = VoiceFollowUpMessages::compose($lang, $business, $d);
            if (!$msg) {
                return ['status' => 'skipped', 'detail' => 'no_content'];
            }
            $st['message_text'] = $msg['text'];
            $rcpt = VoicePostCallRules::recipients($settings, $contact, $q, $verified, $number);
            if (!$st['contact_id'] || (!$rcpt['whatsapp'] && !$rcpt['email'])) {
                $st['delivery_failed'] = true;
                return ['status' => 'skipped', 'detail' => 'no_channel_enabled'];
            }
            $emailFirst = $sends && count(array_filter($sends, static fn ($s) => $s['channel'] === 'email')) === count($sends);
            $order = $emailFirst ? ['email', 'whatsapp'] : ['whatsapp', 'email'];
            $last = '';
            foreach ($order as $ch) {
                if (!$rcpt[$ch]) {
                    continue;
                }
                $r = $ch === 'whatsapp'
                    ? $this->gw->sendWhatsApp($tenantId, $st['contact_id'], $msg['text'], (int) ($settings['whatsapp_website_id'] ?? 0))
                    : $this->gw->sendEmail($tenantId, $st['contact_id'], $msg['subject'], $msg['text']);
                if (!empty($r['success'])) {
                    return ['status' => 'done', 'ref' => $ch];
                }
                $last .= $ch . ': ' . (string) ($r['error'] ?? 'failed') . '; ';
            }
            $st['delivery_failed'] = true;
            return ['status' => 'skipped', 'detail' => 'not_delivered: ' . mb_substr($last, 0, 180)];
        });
        if (!empty($ms['reused'])) { // a re-run: remember whether delivery had failed so the staff task still carries the message
            $d = (string) ($ms['detail'] ?? '');
            $st['delivery_failed'] = strpos($d, 'not_delivered') === 0 || $d === 'no_channel_enabled';
        }

        // 8) staff task when a person must act
        $reasons = VoicePostCallRules::staffReasons($sig, $summary, $bookingAction, (bool) $st['delivery_failed']);
        $this->step($tenantId, $callId, $res, 'task', function () use ($tenantId, &$st, $reasons, $sig, $summary, $br, $callId, $now) {
            if (!$reasons) {
                return ['status' => 'skipped', 'detail' => 'no_person_needed'];
            }
            $prio = VoicePostCallRules::priority($sig);
            $desc = $summary['summary'] . ($summary['next_step'] !== '' ? "\nNext step: " . $summary['next_step'] : '') . "\nReasons: " . implode(', ', $reasons)
                . (!empty($br['product_id']) ? "\nBooking request: trip #" . (int) $br['product_id'] . ' on ' . ($br['date'] ?? '?') . ' for ' . (int) ($br['guests'] ?? 0) . ' guests, quoted total ' . ($br['quoted_total'] ?? '?') . ' ' . ($br['currency'] ?? '') : '')
                . ($st['delivery_failed'] && $st['message_text'] !== '' ? "\n\nMessage to send to the customer:\n" . $st['message_text'] : '');
            $id = $this->gw->createTask($tenantId, [
                'title' => 'AI call follow-up (#' . $callId . '): ' . str_replace('_', ' ', $reasons[0]), 'description' => mb_substr($desc, 0, 2000),
                'related_type' => $st['contact_id'] ? 'contact' : null, 'related_id' => $st['contact_id'], 'priority' => $prio, 'due_date' => VoicePostCallRules::dueDate($prio, $now),
            ]);
            return ['status' => 'done', 'ref' => (string) $id, 'detail' => implode(',', $reasons)];
        });

        // 9) tell the owner about what matters, 10) let CRM automations react
        $this->step($tenantId, $callId, $res, 'notify', function () use ($tenantId, $callId, $sig, $summary, $st, $reasons) {
            if (!$reasons && !$st['booking_id']) {
                return ['status' => 'skipped', 'detail' => 'nothing_urgent'];
            }
            $this->gw->notify($tenantId, $st['booking_id'] ? 'AI call: booking taken' : 'AI call needs your attention', mb_substr($summary['summary'], 0, 240));
            return ['status' => 'done'];
        });
        $this->step($tenantId, $callId, $res, 'automation', function () use ($tenantId, $callId, $st, $sig, $call) {
            $this->gw->triggerAutomation($tenantId, 'voice.call_completed', ['call_id' => $callId, 'contact_id' => $st['contact_id'], 'lead_id' => $st['lead_id'], 'booking_id' => $st['booking_id'], 'outcome' => $call['outcome'] ?? null, 'intent' => $sig['intent'], 'opted_out' => $sig['opted_out']]);
            return ['status' => 'done'];
        });

        $this->data->updateCall($tenantId, $callId, $this->failed
            ? ['post_call_status' => 'failed', 'post_call_attempts_add' => 1]
            : ['post_call_status' => 'done', 'post_processed_at' => date('Y-m-d H:i:s', $now), 'post_call_attempts_add' => 1]);
        return $res;
    }

    /**
     * Runs one action once. A step returns ['status'=>done|skipped, ref?, detail?, value?]; an exception is logged as failed and the call is retried later.
     * @return array{status:string, value?:mixed, reused:bool, detail?:?string}
     */
    private function step(int $tenantId, int $callId, array &$res, string $name, callable $fn): array
    {
        $prev = $this->gw->action($tenantId, $callId, $name);
        if ($prev && in_array($prev['status'], ['done', 'skipped'], true)) {
            $res[$name] = 'already_' . $prev['status'];
            return ['status' => $prev['status'], 'reused' => true, 'detail' => $prev['detail']];
        }
        try {
            $r = $fn();
            $this->gw->logAction($tenantId, $callId, $name, $r['status'], $r['ref'] ?? null, $r['detail'] ?? null);
            if ($r['status'] === 'failed') {
                $res[$name] = 'failed';
                return $r + ['reused' => false];
            }
            $res[$name] = $r['status'];
            return $r + ['reused' => false];
        } catch (Throwable $e) {
            $this->gw->logAction($tenantId, $callId, $name, 'failed', null, $e->getMessage());
            $res[$name] = 'failed';
            $this->failed = true;
            if (class_exists('Logger')) {
                Logger::error('Voice post-call step failed', ['step' => $name, 'call' => $callId, 'error' => $e->getMessage()]);
            }
            return ['status' => 'failed', 'reused' => false];
        }
    }

    /** Queue ended calls that have something to process; used by the status callback and the bridge. */
    public static function markPending(VoiceDataSource $data, int $tenantId, array $call): void
    {
        if (($call['post_call_status'] ?? 'none') === 'none' && ((int) ($call['turns'] ?? 0) > 0 || !empty($call['outcome']))) {
            $data->updateCall($tenantId, (int) $call['id'], ['post_call_status' => 'pending']);
        }
    }
}
