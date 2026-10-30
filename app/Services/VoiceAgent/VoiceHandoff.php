<?php

/** Tourfecto - AI Voice Agent: the short briefing the person hears when the AI hands a call over. Pure. */
class VoiceHandoff
{
    private const FRAME = [
        'en' => 'Call for %s from the AI assistant. ', 'ar' => 'مكالمة محوّلة من المساعد الذكي لـ %s. ', 'fr' => 'Appel transféré par l\'assistant IA de %s. ',
        'de' => 'Anruf vom KI-Assistenten von %s. ', 'es' => 'Llamada transferida por el asistente de IA de %s. ', 'it' => 'Chiamata trasferita dall\'assistente IA di %s. ',
        'ru' => 'Звонок от ИИ-ассистента %s. ', 'tr' => '%s yapay zeka asistanından aktarılan çağrı. ',
    ];
    private const LABEL = ['en' => ['Reason', 'Interested in', 'Wants to book'], 'ar' => ['السبب', 'مهتم بـ', 'يريد الحجز'], 'fr' => ['Motif', 'Intéressé par', 'Veut réserver'],
        'de' => ['Grund', 'Interesse an', 'Möchte buchen'], 'es' => ['Motivo', 'Interesado en', 'Quiere reservar'], 'it' => ['Motivo', 'Interessato a', 'Vuole prenotare'],
        'ru' => ['Причина', 'Интересуется', 'Хочет забронировать'], 'tr' => ['Neden', 'İlgilendiği', 'Rezervasyon istiyor']];

    /**
     * @param array{reason?:string, topic?:?string, interest?:string, booking?:array, verified?:bool} $f
     * Spoken to the person before the caller is connected: who, why, what they want. Short on purpose (ears, not eyes). Never includes payment details.
     */
    public static function whisperText(string $lang, string $business, array $f): string
    {
        $l = VoiceLanguages::normalize($lang);
        $t = sprintf(self::FRAME[$l], $business);
        $lab = self::LABEL[$l];
        if (!empty($f['reason'])) {
            $t .= $lab[0] . ': ' . $f['reason'] . '. ';
        }
        if (!empty($f['interest'])) {
            $t .= $lab[1] . ': ' . $f['interest'] . '. ';
        }
        if (!empty($f['booking']['date'])) {
            $t .= $lab[2] . ': ' . $f['booking']['date'] . ', ' . (int) ($f['booking']['guests'] ?? 0) . '. ';
        }
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($t))), 0, 300);
    }
}
