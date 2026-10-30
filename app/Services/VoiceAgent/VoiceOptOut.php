<?php

/** Tourfecto - AI Voice Agent: deterministic "stop calling me" detection that runs BEFORE the model on every turn. Pure. */
class VoiceOptOut
{
    private const PHRASES = [
        'stop calling', 'do not call', "don't call", 'dont call', 'never call', 'remove me', 'take me off', 'unsubscribe', 'stop contacting', 'do not contact', "don't contact", 'opt out', 'remove my number', 'delete my number',
        'لا تتصل', 'لا تتصلوا', 'ما تتصل', 'متتصل', 'ما تتصلوا', 'متتصلوش', 'ماتكلمون', 'متكلمون', 'لا تكلم', 'لا تكلموا', 'احذف رقمي', 'شيل رقمي', 'امسح رقمي', 'توقف عن الاتصال', 'توقفوا عن الاتصال', 'لا اريد مكالمات', 'لا ابغى مكالمات',
        "ne m'appelez plus", 'ne m appelez plus', "arrêtez de m'appeler", 'arretez de m appeler', "ne plus m'appeler", 'supprimez mon numéro', 'supprimez mon numero',
        'nicht mehr anrufen', 'rufen sie mich nicht mehr an', 'keine anrufe mehr', 'löschen sie meine nummer', 'loeschen sie meine nummer',
        'no me llame', 'no me llamen', 'deja de llamar', 'dejen de llamar', 'borre mi número', 'borre mi numero',
        'non chiamatemi più', 'non chiamatemi piu', 'non mi chiamate più', 'non mi chiamate piu', 'smettete di chiamare', 'cancellate il mio numero',
        'не звоните', 'перестаньте звонить', 'удалите мой номер',
        'beni aramayın', 'beni arama', 'aramayı bırakın', 'numaramı silin',
    ];

    public static function normalize(string $s): string
    {
        $s = mb_strtolower(str_replace(['’', '‘', '`'], "'", $s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // tashkeel, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public static function detect(string $speech): bool
    {
        $t = self::normalize($speech);
        if ($t === '') {
            return false;
        }
        // "please don't stop calling" is the opposite of an opt-out
        if (preg_match("/(don't|do not|dont|never|please don't) (stop|quit)/u", $t)) {
            return false;
        }
        foreach (self::PHRASES as $p) {
            if (strpos($t, self::normalize($p)) !== false) {
                return true;
            }
        }
        return false;
    }
}
