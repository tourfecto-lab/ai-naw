<?php

/**
 * Tourfecto - AI Voice Agent: the message sent after a call. Fixed templates (no model), eight languages.
 * Only facts passed in are printed, so every figure and link comes from the catalogue/booking, never from the conversation.
 */
class VoiceFollowUpMessages
{
    private const T = [
        'en' => ['Thank you for calling %s.', 'Trip: %s.', 'Date: %s.', 'Guests: %d.', 'From %s per person.', 'Total: %s.', 'Secure payment link: %s', 'Reply to this message if you need anything.'],
        'ar' => ['شكرًا لاتصالك بـ %s.', 'الرحلة: %s.', 'التاريخ: %s.', 'عدد الضيوف: %d.', 'ابتداءً من %s للفرد.', 'الإجمالي: %s.', 'رابط الدفع الآمن: %s', 'رد على هذه الرسالة إذا احتجت أي مساعدة.'],
        'fr' => ['Merci d\'avoir appelé %s.', 'Excursion : %s.', 'Date : %s.', 'Voyageurs : %d.', 'À partir de %s par personne.', 'Total : %s.', 'Lien de paiement sécurisé : %s', 'Répondez à ce message si vous avez besoin de quoi que ce soit.'],
        'de' => ['Danke für Ihren Anruf bei %s.', 'Reise: %s.', 'Datum: %s.', 'Gäste: %d.', 'Ab %s pro Person.', 'Gesamt: %s.', 'Sicherer Zahlungslink: %s', 'Antworten Sie auf diese Nachricht, wenn Sie Hilfe brauchen.'],
        'es' => ['Gracias por llamar a %s.', 'Excursión: %s.', 'Fecha: %s.', 'Viajeros: %d.', 'Desde %s por persona.', 'Total: %s.', 'Enlace de pago seguro: %s', 'Responda a este mensaje si necesita algo.'],
        'it' => ['Grazie per aver chiamato %s.', 'Escursione: %s.', 'Data: %s.', 'Ospiti: %d.', 'Da %s a persona.', 'Totale: %s.', 'Link di pagamento sicuro: %s', 'Risponda a questo messaggio se ha bisogno di qualcosa.'],
        'ru' => ['Спасибо за звонок в %s.', 'Поездка: %s.', 'Дата: %s.', 'Гостей: %d.', 'От %s за человека.', 'Итого: %s.', 'Безопасная ссылка для оплаты: %s', 'Ответьте на это сообщение, если вам что-то нужно.'],
        'tr' => ['%s şirketini aradığınız için teşekkürler.', 'Tur: %s.', 'Tarih: %s.', 'Misafir sayısı: %d.', 'Kişi başı %s\'den başlayan fiyatlarla.', 'Toplam: %s.', 'Güvenli ödeme bağlantısı: %s', 'Bir şeye ihtiyacınız olursa bu mesaja yanıt verin.'],
    ];

    private static function money($amount, string $cur): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.') . ' ' . strtoupper(substr($cur, 0, 3));
    }

    /**
     * @param array{trip?:string, date?:string, guests?:int, unit_price?:float, total?:float, currency?:string, pay_url?:string} $d
     * @return array{subject:string, text:string}|null null = nothing worth sending
     */
    public static function compose(string $lang, string $business, array $d): ?array
    {
        if (empty($d['trip']) && empty($d['pay_url'])) {
            return null;
        }
        $t = self::T[VoiceLanguages::normalize($lang)];
        $cur = (string) ($d['currency'] ?? 'USD');
        $lines = [sprintf($t[0], $business)];
        if (!empty($d['trip'])) {
            $lines[] = sprintf($t[1], $d['trip']);
            if (!empty($d['date'])) {
                $lines[] = sprintf($t[2], $d['date']);
            }
            if (!empty($d['guests'])) {
                $lines[] = sprintf($t[3], (int) $d['guests']);
            }
            if (!empty($d['total'])) {
                $lines[] = sprintf($t[5], self::money($d['total'], $cur));
            } elseif (!empty($d['unit_price'])) {
                $lines[] = sprintf($t[4], self::money($d['unit_price'], $cur));
            }
        }
        if (!empty($d['pay_url'])) {
            $lines[] = sprintf($t[6], $d['pay_url']);
        }
        $lines[] = $t[7];
        return ['subject' => $business . ' - ' . ($d['trip'] ?? ''), 'text' => implode("\n", $lines)];
    }
}
