<?php

/**
 * Tourfecto - لينكات الدفع (عربون / المبلغ كله)
 *
 * لصاحب الشركة (بتسجيل دخول):
 *   GET  /api/bookings/{id}/payment-links      اللينكات اللي اتعملت للحجز
 *   POST /api/bookings/{id}/payment-links      لينك جديد {kind: deposit_pct|deposit_fixed|full|balance, value, hours}
 *   POST /api/payment-links/{id}/cancel        إلغاء لينك
 *   GET  /api/chat/customer-bookings?phone=&email=   حجوزات العميل (لزرار "لينك دفع" في الشات)
 * للعميل (عام):
 *   GET  /pay/{token}            صفحة الدفع
 *   POST /pay/{token}/checkout   تحويل لـ Stripe
 *   POST /pay/{token}/paid       «دفعت» بطريقة يدوي (InstaPay / تحويل / PayPal) - تنبيه للشركة
 */
class PaymentLinkController extends Controller
{
    private function ownerId(): int
    {
        $id = (int) ($this->user['id'] ?? 0);
        // Staff added from Business Center work on the owner's bookings too.
        if (class_exists('TeamAccess') && ($owner = TeamAccess::ownerFor($this->user ?? [])) !== null) {
            return $owner;
        }
        try {
            $o = (int) ($this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$id])[0]['owner_user_id'] ?? 0);
            return $o > 0 ? $o : $id;
        } catch (Throwable $e) {
            return $id;
        }
    }

    private function isViewer(): bool
    {
        if (class_exists('TeamAccess') && ($owner = TeamAccess::ownerFor($this->user ?? [])) !== null) {
            return in_array(TeamAccess::inboxRole($owner, (int) ($this->user['id'] ?? 0)), [null, 'viewer'], true);
        }
        try {
            $r = $this->db->query('SELECT owner_user_id, workspace_role FROM users WHERE id = ? LIMIT 1', [(int) ($this->user['id'] ?? 0)]);
            return !$r || ((int) $r[0]['owner_user_id'] > 0 && !in_array(($r[0]['workspace_role'] ?? ''), ['admin', 'manager', 'sales', 'support'], true));
        } catch (Throwable $e) {
            return true;
        }
    }

    public function listForBooking(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $owner = $this->ownerId();
        return $this->success([
            'links' => (new PaymentLinkService())->listForBooking($owner, (int) ($params['id'] ?? 0)),
            'online_payments' => class_exists('OperatorPayments') && OperatorPayments::canCollect($owner),
            'card_payments' => class_exists('OperatorPayments') && OperatorPayments::stripeFor($owner) !== null,
        ]);
    }

    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($this->isViewer()) {
            return $this->error('صلاحيتك مشاهدة بس', 403);
        }
        if ($rl = $this->rateLimitGuard('user', 'payment_link_create', 30, 600)) {
            return $rl;
        }
        $r = (new PaymentLinkService())->create(
            $this->ownerId(),
            (int) $this->user['id'],
            (int) ($params['id'] ?? 0),
            (string) $this->get('kind', 'deposit_pct'),
            (float) $this->get('value', 30),
            (int) $this->get('hours', 48)
        );
        if (!$r['ok']) {
            return $this->error($r['error'], 422, !empty($r['needs_stripe']) ? ['needs_stripe' => true] : []);
        }
        return $this->success(['link' => $r['link']], 'اتعمل لينك الدفع', 201);
    }

    public function cancel(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($this->isViewer()) {
            return $this->error('صلاحيتك مشاهدة بس', 403);
        }
        (new PaymentLinkService())->cancel($this->ownerId(), (int) ($params['id'] ?? 0));
        return $this->success([], 'اتلغى اللينك');
    }

    /** حجوزات عميل المحادثة (بالموبايل أو الإيميل) - عشان زرار "لينك دفع" في صندوق الشات */
    public function customerBookings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $phone = preg_replace('/\D/', '', (string) $this->get('phone', ''));
        $email = strtolower(trim((string) $this->get('email', '')));
        if (strlen($phone) < 7 && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->success(['bookings' => []]);
        }
        $tail = strlen($phone) >= 7 ? substr($phone, -9) : '###';
        $rows = $this->db->query(
            "SELECT b.id, b.booking_reference, b.start_date, b.status, b.total_amount, b.amount_paid, b.currency, p.name AS product_name
               FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id
              WHERE b.user_id = ? AND b.status IN ('pending','confirmed')
                AND (REPLACE(REPLACE(REPLACE(COALESCE(b.customer_phone,''),'+',''),' ',''),'-','') LIKE ? OR LOWER(COALESCE(b.customer_email,'')) = ?)
              ORDER BY b.start_date ASC LIMIT 10",
            [$this->ownerId(), '%' . $tail, $email !== '' ? $email : '###']
        );
        return $this->success(['bookings' => $rows]);
    }

    // ------------------------------------------------------------------ الصفحة العامة

    public function page(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $svc = new PaymentLinkService();
        $justPaid = isset($_GET['paid']) || isset($_GET['session_id']);
        $v = $svc->publicView($token, !$justPaid);
        if ($v && $justPaid && $v['link']['status'] === 'active' && class_exists('OperatorPayments')) {
            // حساب Stripe بتاع الشركة مش بيبعتلنا Webhook - بنتحقق بنفسنا أول ما العميل يرجع
            try {
                OperatorPayments::verifyBooking((int) $v['link']['booking_id']);
            } catch (Throwable $e) {
            }
            $v = $svc->publicView($token);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo $this->renderPayPage($token, $v, $justPaid);
        exit;
    }

    public function checkout(array $params = []): array
    {
        if ($rl = $this->rateLimitGuard('ip', 'pay_checkout', 20, 600)) {
            return $rl;
        }
        $r = (new PaymentLinkService())->checkoutUrl((string) ($params['token'] ?? ''));
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success(['url' => $r['url']]);
    }

    /** POST /pay/{token}/paid - السائح بيقول إنه دفع بطريقة يدوي، فبننبّه الشركة تراجع */
    public function paid(array $params = []): array
    {
        if ($rl = $this->rateLimitGuard('ip', 'pay_claim', 5, 600)) {
            return $rl;
        }
        $r = (new PaymentLinkService())->notifyPaid((string) ($params['token'] ?? ''), (string) $this->get('method', 'other'), (string) $this->get('ref', ''));
        return $r['ok'] ? $this->success([]) : $this->error($r['error'], 422);
    }

    /** وقت قاعدة البيانات (UTC) بتوقيت الشركة */
    private function localTime(string $utc): string
    {
        try {
            $tz = new DateTimeZone(function_exists('env') ? (string) (env('CRON_TIMEZONE') ?: 'Africa/Cairo') : 'Africa/Cairo');
            return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return substr($utc, 0, 16);
        }
    }

    /** لغة صفحة الدفع: ?lang= أو لغة متصفح السائح (السياح من برّه مايفهموش عربي) */
    private function payLang(): string
    {
        $ok = ['ar', 'en', 'de', 'fr', 'es', 'it'];
        $q = strtolower(substr((string) ($_GET['lang'] ?? ''), 0, 2));
        if (in_array($q, $ok, true)) {
            return $q;
        }
        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $c = strtolower(substr(trim($part), 0, 2));
            if (in_array($c, $ok, true)) {
                return $c;
            }
        }
        return 'en';
    }

    private static function payStrings(string $lang): array
    {
        $T = [
            'en' => [
                'invalid_t' => 'This link is not valid', 'invalid_b' => 'Check the link or ask the company for a new one.',
                'trip' => 'Trip', 'date' => 'Date', 'name' => 'Name', 'people' => 'Travellers', 'total' => 'Booking total', 'ref' => 'Booking code',
                'paid_t' => 'Payment received — your booking is confirmed', 'paid_b' => 'You paid {amount}. {company} will send you a confirmation.',
                'rest_note' => 'The remaining {amount} is paid as agreed with the company.',
                'inactive_t' => 'This link is no longer active', 'inactive_b' => 'Ask {company} for a new payment link.',
                'cancel_t' => 'This booking was cancelled', 'cancel_b' => 'Contact {company} if you need help.',
                'h_dep' => 'Pay the deposit to confirm your booking', 'h_full' => 'Pay for your booking',
                'l_dep' => 'Deposit due', 'l_full' => 'Amount due', 'after_dep' => 'Remaining after the deposit: {amount}',
                'wait' => 'If you just paid, wait a minute and refresh this page.',
                'card' => 'Pay by card', 'card_note' => 'Secure payment by Stripe. Your card details never reach us.',
                'until' => 'This link is valid until {date}', 'ways' => 'Ways to pay', 'more_ways' => 'Other ways to pay',
                'paypal' => 'Pay with PayPal', 'paypal_note' => 'Opens PayPal with the amount filled in.', 'paypal_note2' => 'Send {amount} (or the equivalent in your currency).',
                'instapay' => 'InstaPay', 'wallet' => 'Mobile wallet', 'bank' => 'Bank transfer', 'other' => 'Other',
                'copy' => 'Copy', 'copied' => 'Copied', 'ref_hint' => 'Write your booking code {ref} in the transfer note.',
                'claim_t' => 'Already paid?', 'claim_by' => 'I paid by', 'claim_ref' => 'Transfer number or sender name (optional)',
                'claim_btn' => 'I have paid — tell {company}', 'claim_done' => 'Thank you! We told {company}. Your booking is confirmed as soon as they see the payment.',
                'claim_seen' => 'You told {company} that you paid. They will confirm your booking as soon as they see the payment.',
                'err' => 'Something went wrong, please try again.', 'err_net' => 'Connection problem, please try again.', 'preparing' => 'Preparing the payment page...',
                'title' => 'Booking payment', 'none' => 'Contact {company} to arrange the payment.',
            ],
            'ar' => [
                'invalid_t' => 'اللينك ده مش صحيح', 'invalid_b' => 'اتأكد من الرابط أو اطلب لينك جديد من الشركة.',
                'trip' => 'الرحلة', 'date' => 'التاريخ', 'name' => 'الاسم', 'people' => 'الأفراد', 'total' => 'إجمالي الحجز', 'ref' => 'كود الحجز',
                'paid_t' => 'تم الدفع - حجزك اتأكد', 'paid_b' => 'دفعت {amount}. هيوصلك تأكيد من {company}.',
                'rest_note' => 'الباقي {amount} بيتدفع حسب اتفاقك مع الشركة.',
                'inactive_t' => 'اللينك ده مابقاش شغّال', 'inactive_b' => 'اطلب لينك دفع جديد من {company}.',
                'cancel_t' => 'الحجز ده اتلغى', 'cancel_b' => 'كلّم {company} لو محتاج مساعدة.',
                'h_dep' => 'ادفع العربون وأكّد حجزك', 'h_full' => 'ادفع حجزك',
                'l_dep' => 'العربون المطلوب', 'l_full' => 'المبلغ المطلوب', 'after_dep' => 'الباقي بعد العربون: {amount}',
                'wait' => 'لو لسه دافع حالًا، استنى دقيقة وحدّث الصفحة.',
                'card' => 'ادفع بالكارت', 'card_note' => 'الدفع عن طريق Stripe - بيانات الكارت مابتوصلناش.',
                'until' => 'اللينك صالح لحد {date}', 'ways' => 'طرق الدفع', 'more_ways' => 'طرق دفع تانية',
                'paypal' => 'ادفع بـ PayPal', 'paypal_note' => 'بيفتح PayPal والمبلغ مكتوب.', 'paypal_note2' => 'ابعت {amount} (أو ما يعادلها بعملتك).',
                'instapay' => 'إنستاباي InstaPay', 'wallet' => 'محفظة موبايل', 'bank' => 'تحويل بنكي', 'other' => 'طريقة تانية',
                'copy' => 'انسخ', 'copied' => 'اتنسخ', 'ref_hint' => 'اكتب كود الحجز {ref} في ملاحظة التحويل.',
                'claim_t' => 'دفعت خلاص؟', 'claim_by' => 'دفعت عن طريق', 'claim_ref' => 'رقم التحويل أو اسم اللي حوّل (اختياري)',
                'claim_btn' => 'دفعت - بلّغ {company}', 'claim_done' => 'شكرًا! بلّغنا {company}، وحجزك هيتأكد أول ما يشوفوا الفلوس.',
                'claim_seen' => 'إنت بلّغت {company} إنك دفعت. هيأكدوا حجزك أول ما يشوفوا الفلوس.',
                'err' => 'حصلت مشكلة، جرّب تاني.', 'err_net' => 'حصلت مشكلة في الاتصال، جرّب تاني.', 'preparing' => 'بنجهّز صفحة الدفع...',
                'title' => 'دفع الحجز', 'none' => 'كلّم {company} عشان تتفقوا على طريقة الدفع.',
            ],
            'de' => [
                'invalid_t' => 'Dieser Link ist ungültig', 'invalid_b' => 'Prüfen Sie den Link oder fordern Sie beim Anbieter einen neuen an.',
                'trip' => 'Ausflug', 'date' => 'Datum', 'name' => 'Name', 'people' => 'Reisende', 'total' => 'Gesamtbetrag', 'ref' => 'Buchungscode',
                'paid_t' => 'Zahlung erhalten – Ihre Buchung ist bestätigt', 'paid_b' => 'Sie haben {amount} bezahlt. {company} sendet Ihnen eine Bestätigung.',
                'rest_note' => 'Der Restbetrag von {amount} wird wie mit dem Anbieter vereinbart bezahlt.',
                'inactive_t' => 'Dieser Link ist nicht mehr aktiv', 'inactive_b' => 'Bitten Sie {company} um einen neuen Zahlungslink.',
                'cancel_t' => 'Diese Buchung wurde storniert', 'cancel_b' => 'Kontaktieren Sie {company}, wenn Sie Hilfe brauchen.',
                'h_dep' => 'Anzahlung leisten und Buchung bestätigen', 'h_full' => 'Buchung bezahlen',
                'l_dep' => 'Fällige Anzahlung', 'l_full' => 'Fälliger Betrag', 'after_dep' => 'Restbetrag nach der Anzahlung: {amount}',
                'wait' => 'Wenn Sie gerade bezahlt haben, warten Sie eine Minute und laden Sie die Seite neu.',
                'card' => 'Mit Karte bezahlen', 'card_note' => 'Sichere Zahlung über Stripe. Ihre Kartendaten erreichen uns nie.',
                'until' => 'Dieser Link ist gültig bis {date}', 'ways' => 'Zahlungsmöglichkeiten', 'more_ways' => 'Weitere Zahlungsmöglichkeiten',
                'paypal' => 'Mit PayPal bezahlen', 'paypal_note' => 'Öffnet PayPal mit dem Betrag.', 'paypal_note2' => 'Senden Sie {amount} (oder den Gegenwert in Ihrer Währung).',
                'instapay' => 'InstaPay', 'wallet' => 'Mobile Wallet', 'bank' => 'Banküberweisung', 'other' => 'Sonstiges',
                'copy' => 'Kopieren', 'copied' => 'Kopiert', 'ref_hint' => 'Geben Sie Ihren Buchungscode {ref} im Verwendungszweck an.',
                'claim_t' => 'Schon bezahlt?', 'claim_by' => 'Bezahlt per', 'claim_ref' => 'Überweisungsnummer oder Name des Absenders (optional)',
                'claim_btn' => 'Ich habe bezahlt – {company} informieren', 'claim_done' => 'Danke! Wir haben {company} informiert. Ihre Buchung wird bestätigt, sobald die Zahlung eingegangen ist.',
                'claim_seen' => 'Sie haben {company} mitgeteilt, dass Sie bezahlt haben. Die Buchung wird bestätigt, sobald die Zahlung eingegangen ist.',
                'err' => 'Etwas ist schiefgelaufen, bitte erneut versuchen.', 'err_net' => 'Verbindungsproblem, bitte erneut versuchen.', 'preparing' => 'Zahlungsseite wird vorbereitet...',
                'title' => 'Zahlung der Buchung', 'none' => 'Kontaktieren Sie {company}, um die Zahlung zu vereinbaren.',
            ],
            'fr' => [
                'invalid_t' => 'Ce lien n’est pas valide', 'invalid_b' => 'Vérifiez le lien ou demandez-en un nouveau à l’agence.',
                'trip' => 'Excursion', 'date' => 'Date', 'name' => 'Nom', 'people' => 'Voyageurs', 'total' => 'Total de la réservation', 'ref' => 'Code de réservation',
                'paid_t' => 'Paiement reçu — votre réservation est confirmée', 'paid_b' => 'Vous avez payé {amount}. {company} vous enverra une confirmation.',
                'rest_note' => 'Le solde de {amount} se règle comme convenu avec l’agence.',
                'inactive_t' => 'Ce lien n’est plus actif', 'inactive_b' => 'Demandez un nouveau lien de paiement à {company}.',
                'cancel_t' => 'Cette réservation a été annulée', 'cancel_b' => 'Contactez {company} si vous avez besoin d’aide.',
                'h_dep' => 'Payez l’acompte pour confirmer votre réservation', 'h_full' => 'Payez votre réservation',
                'l_dep' => 'Acompte à payer', 'l_full' => 'Montant à payer', 'after_dep' => 'Reste après l’acompte : {amount}',
                'wait' => 'Si vous venez de payer, attendez une minute puis actualisez la page.',
                'card' => 'Payer par carte', 'card_note' => 'Paiement sécurisé par Stripe. Vos données de carte ne nous parviennent jamais.',
                'until' => 'Ce lien est valable jusqu’au {date}', 'ways' => 'Moyens de paiement', 'more_ways' => 'Autres moyens de paiement',
                'paypal' => 'Payer avec PayPal', 'paypal_note' => 'Ouvre PayPal avec le montant rempli.', 'paypal_note2' => 'Envoyez {amount} (ou l’équivalent dans votre devise).',
                'instapay' => 'InstaPay', 'wallet' => 'Portefeuille mobile', 'bank' => 'Virement bancaire', 'other' => 'Autre',
                'copy' => 'Copier', 'copied' => 'Copié', 'ref_hint' => 'Indiquez votre code de réservation {ref} dans le libellé du virement.',
                'claim_t' => 'Déjà payé ?', 'claim_by' => 'Payé par', 'claim_ref' => 'Numéro de virement ou nom de l’expéditeur (facultatif)',
                'claim_btn' => 'J’ai payé — prévenir {company}', 'claim_done' => 'Merci ! Nous avons prévenu {company}. Votre réservation sera confirmée dès réception du paiement.',
                'claim_seen' => 'Vous avez indiqué à {company} que vous avez payé. La réservation sera confirmée dès réception du paiement.',
                'err' => 'Un problème est survenu, réessayez.', 'err_net' => 'Problème de connexion, réessayez.', 'preparing' => 'Préparation de la page de paiement...',
                'title' => 'Paiement de la réservation', 'none' => 'Contactez {company} pour organiser le paiement.',
            ],
            'es' => [
                'invalid_t' => 'Este enlace no es válido', 'invalid_b' => 'Revisa el enlace o pide uno nuevo a la agencia.',
                'trip' => 'Excursión', 'date' => 'Fecha', 'name' => 'Nombre', 'people' => 'Viajeros', 'total' => 'Total de la reserva', 'ref' => 'Código de reserva',
                'paid_t' => 'Pago recibido: tu reserva está confirmada', 'paid_b' => 'Has pagado {amount}. {company} te enviará una confirmación.',
                'rest_note' => 'El resto, {amount}, se paga según lo acordado con la agencia.',
                'inactive_t' => 'Este enlace ya no está activo', 'inactive_b' => 'Pide a {company} un nuevo enlace de pago.',
                'cancel_t' => 'Esta reserva fue cancelada', 'cancel_b' => 'Contacta con {company} si necesitas ayuda.',
                'h_dep' => 'Paga el depósito para confirmar tu reserva', 'h_full' => 'Paga tu reserva',
                'l_dep' => 'Depósito a pagar', 'l_full' => 'Importe a pagar', 'after_dep' => 'Resto tras el depósito: {amount}',
                'wait' => 'Si acabas de pagar, espera un minuto y actualiza la página.',
                'card' => 'Pagar con tarjeta', 'card_note' => 'Pago seguro con Stripe. Los datos de tu tarjeta nunca nos llegan.',
                'until' => 'Este enlace es válido hasta el {date}', 'ways' => 'Formas de pago', 'more_ways' => 'Otras formas de pago',
                'paypal' => 'Pagar con PayPal', 'paypal_note' => 'Abre PayPal con el importe ya puesto.', 'paypal_note2' => 'Envía {amount} (o el equivalente en tu moneda).',
                'instapay' => 'InstaPay', 'wallet' => 'Monedero móvil', 'bank' => 'Transferencia bancaria', 'other' => 'Otra',
                'copy' => 'Copiar', 'copied' => 'Copiado', 'ref_hint' => 'Escribe tu código de reserva {ref} en el concepto de la transferencia.',
                'claim_t' => '¿Ya has pagado?', 'claim_by' => 'Pagué con', 'claim_ref' => 'Número de transferencia o nombre del remitente (opcional)',
                'claim_btn' => 'Ya pagué: avisar a {company}', 'claim_done' => '¡Gracias! Hemos avisado a {company}. Tu reserva se confirmará en cuanto vean el pago.',
                'claim_seen' => 'Avisaste a {company} de que pagaste. Confirmarán tu reserva en cuanto vean el pago.',
                'err' => 'Algo salió mal, inténtalo de nuevo.', 'err_net' => 'Problema de conexión, inténtalo de nuevo.', 'preparing' => 'Preparando la página de pago...',
                'title' => 'Pago de la reserva', 'none' => 'Contacta con {company} para acordar el pago.',
            ],
            'it' => [
                'invalid_t' => 'Questo link non è valido', 'invalid_b' => 'Controlla il link o chiedine uno nuovo all’agenzia.',
                'trip' => 'Escursione', 'date' => 'Data', 'name' => 'Nome', 'people' => 'Viaggiatori', 'total' => 'Totale prenotazione', 'ref' => 'Codice prenotazione',
                'paid_t' => 'Pagamento ricevuto: la tua prenotazione è confermata', 'paid_b' => 'Hai pagato {amount}. {company} ti invierà una conferma.',
                'rest_note' => 'Il saldo di {amount} si paga come concordato con l’agenzia.',
                'inactive_t' => 'Questo link non è più attivo', 'inactive_b' => 'Chiedi a {company} un nuovo link di pagamento.',
                'cancel_t' => 'Questa prenotazione è stata annullata', 'cancel_b' => 'Contatta {company} se hai bisogno di aiuto.',
                'h_dep' => 'Paga l’acconto per confermare la prenotazione', 'h_full' => 'Paga la tua prenotazione',
                'l_dep' => 'Acconto da pagare', 'l_full' => 'Importo da pagare', 'after_dep' => 'Saldo dopo l’acconto: {amount}',
                'wait' => 'Se hai appena pagato, attendi un minuto e aggiorna la pagina.',
                'card' => 'Paga con carta', 'card_note' => 'Pagamento sicuro con Stripe. I dati della carta non ci arrivano mai.',
                'until' => 'Questo link è valido fino al {date}', 'ways' => 'Modalità di pagamento', 'more_ways' => 'Altre modalità di pagamento',
                'paypal' => 'Paga con PayPal', 'paypal_note' => 'Apre PayPal con l’importo già inserito.', 'paypal_note2' => 'Invia {amount} (o l’equivalente nella tua valuta).',
                'instapay' => 'InstaPay', 'wallet' => 'Portafoglio mobile', 'bank' => 'Bonifico bancario', 'other' => 'Altro',
                'copy' => 'Copia', 'copied' => 'Copiato', 'ref_hint' => 'Scrivi il codice prenotazione {ref} nella causale del bonifico.',
                'claim_t' => 'Hai già pagato?', 'claim_by' => 'Ho pagato con', 'claim_ref' => 'Numero del bonifico o nome di chi ha pagato (facoltativo)',
                'claim_btn' => 'Ho pagato: avvisa {company}', 'claim_done' => 'Grazie! Abbiamo avvisato {company}. La prenotazione sarà confermata appena vedranno il pagamento.',
                'claim_seen' => 'Hai comunicato a {company} di aver pagato. Confermeranno la prenotazione appena vedranno il pagamento.',
                'err' => 'Qualcosa è andato storto, riprova.', 'err_net' => 'Problema di connessione, riprova.', 'preparing' => 'Preparazione della pagina di pagamento...',
                'title' => 'Pagamento prenotazione', 'none' => 'Contatta {company} per accordarvi sul pagamento.',
            ],
        ];
        return $T[$lang] ?? $T['en'];
    }

    private function renderPayPage(string $token, ?array $v, bool $justPaid): string
    {
        $lang = $this->payLang();
        $S = self::payStrings($lang);
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');
        $money = fn ($n, $c) => '<span dir="ltr">' . $e($num($n) . ' ' . $c) . '</span>';
        // النص مترجم ومتأمّن، والمتغيرات بتتحط بعد التأمين
        $t = function (string $k, array $vars = []) use ($S, $e) {
            $out = $e($S[$k] ?? $k);
            foreach ($vars as $name => $html) {
                $out = str_replace('{' . $name . '}', $html, $out);
            }
            return $out;
        };
        $company = 'Tourfecto';
        $methodsHtml = '';
        $claimHtml = '';
        if (!$v || !$v['booking']) {
            $body = '<div class="icon">🔗</div><h1>' . $t('invalid_t') . '</h1><p class="muted">' . $t('invalid_b') . '</p>';
        } else {
            $l = $v['link'];
            $b = $v['booking'];
            $company = $v['company'] !== '' ? $v['company'] : 'Tourfecto';
            $co = '<b>' . $e($company) . '</b>';
            $people = (int) $b['adults_count'] + (int) ($b['children_count'] ?? 0);
            $remainingAfter = max(0, (float) $b['total_amount'] - (float) $b['amount_paid'] - ($l['status'] === 'paid' ? 0 : (float) $l['amount']));
            $rows = '<div class="row"><span>' . $t('trip') . '</span><b>' . $e($b['product_name'] ?: '-') . '</b></div>'
                . '<div class="row"><span>' . $t('date') . '</span><b dir="ltr">' . $e($b['start_date']) . ($b['start_time'] ? ' ' . $e(substr((string) $b['start_time'], 0, 5)) : '') . '</b></div>'
                . '<div class="row"><span>' . $t('name') . '</span><b>' . $e($b['customer_name']) . '</b></div>'
                . '<div class="row"><span>' . $t('people') . '</span><b>' . $people . '</b></div>'
                . '<div class="row"><span>' . $t('total') . '</span><b>' . $money($b['total_amount'], $b['currency']) . '</b></div>'
                . '<div class="row"><span>' . $t('ref') . '</span><b dir="ltr">' . $e($b['booking_reference']) . '</b></div>';
            if ($l['status'] === 'paid') {
                $body = '<div class="icon ok">✓</div><h1>' . $t('paid_t') . '</h1>'
                    . '<p class="muted">' . $t('paid_b', ['amount' => $money($l['amount'], $l['currency']), 'company' => $co]) . '</p>' . $rows
                    . ($remainingAfter > 0 ? '<p class="note">' . $t('rest_note', ['amount' => $money($remainingAfter, $b['currency'])]) . '</p>' : '');
            } elseif ($l['status'] !== 'active') {
                $body = '<div class="icon">⌛</div><h1>' . $t('inactive_t') . '</h1><p class="muted">' . $t('inactive_b', ['company' => $co]) . '</p>' . $rows;
            } elseif (!in_array($b['status'], ['pending', 'confirmed'], true)) {
                $body = '<div class="icon">⚠️</div><h1>' . $t('cancel_t') . '</h1><p class="muted">' . $t('cancel_b', ['company' => $co]) . '</p>';
            } else {
                $uid = (int) $l['user_id'];
                $hasStripe = class_exists('OperatorPayments') && OperatorPayments::stripeFor($uid) !== null;
                $m = class_exists('OperatorPayments') ? OperatorPayments::manualMethods($uid) : [];
                $ref = $e($b['booking_reference']);
                $body = '<h1>' . ($l['kind'] === 'deposit' ? $t('h_dep') : $t('h_full')) . '</h1>'
                    . '<p class="muted">' . $e($company) . '</p>' . $rows
                    . '<div class="amount"><span>' . ($l['kind'] === 'deposit' ? $t('l_dep') : $t('l_full')) . '</span><b>' . $money($l['amount'], $l['currency']) . '</b></div>'
                    . ($remainingAfter > 0 ? '<p class="note">' . $t('after_dep', ['amount' => $money($remainingAfter, $b['currency'])]) . '</p>' : '')
                    . ($justPaid ? '<p class="note">' . $t('wait') . '</p>' : '');
                if ($hasStripe) {
                    $body .= '<button id="payBtn" type="button">💳 ' . $t('card') . '</button><p id="payErr" class="err"></p>'
                        . '<p class="muted small">' . $t('card_note') . '</p>';
                }
                // طرق الدفع المحلية
                $cards = [];
                $copyRow = function (string $label, string $value) use ($e, $t) {
                    return '<div class="cp"><code dir="ltr">' . $e($value) . '</code><button type="button" class="mini" data-copy="' . $e($value) . '">' . $t('copy') . '</button></div>';
                };
                $claimOpts = [];
                if (($m['paypal'] ?? '') !== '') {
                    $ppCur = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];
                    $cur = strtoupper((string) $l['currency']);
                    $supported = in_array($cur, $ppCur, true);
                    $amt = $cur === 'JPY' || $cur === 'HUF' || $cur === 'TWD' ? (string) (int) round((float) $l['amount']) : number_format((float) $l['amount'], 2, '.', '');
                    $ppUrl = 'https://paypal.me/' . rawurlencode($m['paypal']) . ($supported ? '/' . $amt . $cur : '');
                    $cards[] = '<div class="pm"><div class="pm-h">🅿️ PayPal</div><a class="btn pp" target="_blank" rel="noopener" href="' . $e($ppUrl) . '">' . $t('paypal') . '</a>'
                        . '<p class="muted small">' . ($supported ? $t('paypal_note') : $t('paypal_note2', ['amount' => $money($l['amount'], $l['currency'])])) . '</p></div>';
                    $claimOpts['paypal'] = 'PayPal';
                }
                if (($m['instapay'] ?? '') !== '') {
                    $cards[] = '<div class="pm"><div class="pm-h">⚡ ' . $t('instapay') . '</div>' . $copyRow($t('instapay'), $m['instapay']) . '</div>';
                    $claimOpts['instapay'] = $S['instapay'];
                }
                if (($m['wallet_number'] ?? '') !== '') {
                    $wl = $m['wallet_name'] !== '' ? $e($m['wallet_name']) : $t('wallet');
                    $cards[] = '<div class="pm"><div class="pm-h">📱 ' . $wl . '</div>' . $copyRow($wl, $m['wallet_number']) . '</div>';
                    $claimOpts['wallet'] = $m['wallet_name'] !== '' ? $m['wallet_name'] : $S['wallet'];
                }
                if (($m['bank'] ?? '') !== '') {
                    $cards[] = '<div class="pm"><div class="pm-h">🏦 ' . $t('bank') . '</div><pre dir="auto">' . $e($m['bank']) . '</pre><button type="button" class="mini" data-copy="' . $e($m['bank']) . '">' . $t('copy') . '</button></div>';
                    $claimOpts['bank'] = $S['bank'];
                }
                if (($m['other'] ?? '') !== '') {
                    $cards[] = '<div class="pm"><div class="pm-h">💬 ' . $t('other') . '</div><pre dir="auto">' . $e($m['other']) . '</pre></div>';
                    $claimOpts['other'] = $S['other'];
                }
                if ($cards) {
                    $needsRef = count($claimOpts) > (isset($claimOpts['paypal']) ? 1 : 0);
                    $methodsHtml = '<h2>' . ($hasStripe ? $t('more_ways') : $t('ways')) . '</h2>' . implode('', $cards)
                        . ($needsRef ? '<p class="note">' . $t('ref_hint', ['ref' => '<b dir="ltr">' . $ref . '</b>']) . '</p>' : '');
                    $claim = class_exists('PaymentLinkService') ? PaymentLinkService::claim((int) $l['id']) : null;
                    if ($claim) {
                        $claimHtml = '<div class="done">✓ ' . $t('claim_seen', ['company' => $co]) . '</div>';
                    } else {
                        $opts = '';
                        foreach ($claimOpts as $k => $label) {
                            $opts .= '<option value="' . $e($k) . '">' . $e($label) . '</option>';
                        }
                        $claimHtml = '<div class="claim" id="claimBox"><h2>' . $t('claim_t') . '</h2>'
                            . '<label>' . $t('claim_by') . '<select id="clMethod">' . $opts . '</select></label>'
                            . '<label>' . $t('claim_ref') . '<input id="clRef" maxlength="120" dir="auto"></label>'
                            . '<button type="button" id="clBtn" class="sec">' . $t('claim_btn', ['company' => $e($company)]) . '</button><p id="clErr" class="err"></p></div>';
                    }
                } elseif (!$hasStripe) {
                    $methodsHtml = '<p class="note">' . $t('none', ['company' => $co]) . '</p>';
                }
                $body .= $methodsHtml . $claimHtml
                    . '<p class="muted small">' . $t('until', ['date' => '<span dir="ltr">' . $e($this->localTime((string) $l['expires_at'])) . '</span>']) . '</p>';
            }
        }
        $langs = ['en' => 'English', 'ar' => 'العربية', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano'];
        $switch = '';
        foreach ($langs as $code => $label) {
            $switch .= $code === $lang ? '<b>' . $e($label) . '</b>' : '<a href="?lang=' . $code . '">' . $e($label) . '</a>';
        }
        $js = json_encode([
            'token' => $token, 'card' => '💳 ' . $S['card'], 'preparing' => '⏳ ' . $S['preparing'], 'err' => $S['err'], 'errNet' => $S['err_net'],
            'copied' => $S['copied'], 'copy' => $S['copy'], 'done' => str_replace('{company}', $company, $S['claim_done']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $title = $e($company) . ' - ' . $e($S['title']);
        return <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{$title}</title>
<style>
:root{--bg:#f4f6fb;--card:#fff;--text:#0f1b2d;--muted:#5d687c;--accent:#1f4fff;--ok:#0e9f6e;--line:#e6e9f0;--soft:#f7f8fc}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#121a2b;--text:#e8edf7;--muted:#9aa6bd;--line:#223049;--soft:#0f1727}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,"Segoe UI",Arial,sans-serif;display:flex;flex-direction:column;align-items:center;padding:24px 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;max-width:460px;width:100%;padding:22px 20px;box-shadow:0 18px 40px -24px rgba(11,27,51,.35)}
h1{font-size:20px;margin:0 0 6px}h2{font-size:15px;margin:22px 0 10px}.muted{color:var(--muted);margin:0 0 14px;font-size:14px}.small{font-size:12px;margin-top:10px}
.row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}.row span{color:var(--muted)}
.amount{display:flex;justify-content:space-between;align-items:center;margin:16px 0 6px;padding:14px;border-radius:12px;background:color-mix(in srgb,var(--accent) 9%,transparent)}
.amount b{font-size:22px}.note{font-size:13px;color:var(--muted)}
button,.btn{display:block;text-align:center;text-decoration:none;width:100%;margin-top:14px;padding:14px;border:0;border-radius:12px;background:var(--accent);color:#fff;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit}
button:disabled{opacity:.6}.err{color:#d33;font-size:13px;min-height:18px;margin:6px 0 0}
.btn.pp{background:#ffc439;color:#111;margin-top:6px}button.sec{background:transparent;color:var(--accent);border:1.5px solid var(--accent)}
.pm{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;background:var(--soft)}.pm-h{font-weight:700;font-size:14px;margin-bottom:8px}
.cp{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px}.cp span{color:var(--muted)}.cp code{font-size:15px;font-weight:700;flex:1;word-break:break-all}
pre{white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:13.5px;margin:0 0 8px;line-height:1.7}
button.mini{display:inline-block;width:auto;margin:0;padding:6px 12px;font-size:12.5px;border-radius:8px;background:var(--card);color:var(--accent);border:1px solid var(--line)}
.claim label{display:block;font-size:13px;color:var(--muted);margin-top:8px}.claim select,.claim input{display:block;width:100%;margin-top:4px;padding:10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--text);font:inherit}
.done{margin-top:18px;padding:12px;border-radius:12px;background:color-mix(in srgb,var(--ok) 12%,transparent);color:var(--ok);font-size:14px;line-height:1.7}
.icon{font-size:40px;text-align:center;margin-bottom:6px}.icon.ok{color:var(--ok);font-weight:700}
.langs{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:14px;font-size:12.5px;color:var(--muted)}.langs a{color:var(--muted)}
</style></head><body><div class="card">{$body}</div><nav class="langs">{$switch}</nav>
<script>
(function(){var C={$js};
document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){var v=b.getAttribute('data-copy');var ok=function(){b.textContent=C.copied;setTimeout(function(){b.textContent=C.copy;},1500);};
if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v).then(ok,ok);}else{var t=document.createElement('textarea');t.value=v;document.body.appendChild(t);t.select();try{document.execCommand('copy');}catch(e){}t.remove();ok();}});});
var b=document.getElementById('payBtn');if(b){b.addEventListener('click',async function(){
b.disabled=true;b.textContent=C.preparing;var er=document.getElementById('payErr');er.textContent='';
try{var r=await fetch('/pay/'+encodeURIComponent(C.token)+'/checkout',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json'},body:'{}'});
var d=await r.json();if(d.success&&d.data&&d.data.url){location.href=d.data.url;return;}er.textContent=C.err;}
catch(e){er.textContent=C.errNet;}b.disabled=false;b.textContent=C.card;});}
var cb=document.getElementById('clBtn');if(cb){cb.addEventListener('click',async function(){
cb.disabled=true;var er=document.getElementById('clErr');er.textContent='';
try{var r=await fetch('/pay/'+encodeURIComponent(C.token)+'/paid',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({method:document.getElementById('clMethod').value,ref:document.getElementById('clRef').value})});
var d=await r.json();if(d.success){var box=document.getElementById('claimBox');var dn=document.createElement('div');dn.className='done';dn.textContent='✓ '+C.done;box.replaceWith(dn);return;}er.textContent=C.err;}
catch(e){er.textContent=C.errNet;}cb.disabled=false;});}
})();
</script></body></html>
HTML;
    }
}
