<?php

/** Tourfecto - AI Voice Agent: language -> speech-recognition locale and default text-to-speech voice. Voices are defaults the agent profile can override. */
class VoiceLanguages
{
    private const MAP = [
        'en' => ['en-US', 'Polly.Joanna'], 'ar' => ['ar-EG', 'Polly.Zeina'], 'fr' => ['fr-FR', 'Polly.Celine'], 'de' => ['de-DE', 'Polly.Marlene'],
        'es' => ['es-ES', 'Polly.Conchita'], 'it' => ['it-IT', 'Polly.Carla'], 'ru' => ['ru-RU', 'Polly.Tatyana'], 'tr' => ['tr-TR', 'Polly.Filiz'],
    ];

    public static function normalize(?string $lang, string $fallback = 'en'): string
    {
        $l = strtolower(substr(trim((string) $lang), 0, 2));
        return isset(self::MAP[$l]) ? $l : $fallback;
    }

    public static function speechLocale(string $lang): string
    {
        return self::MAP[self::normalize($lang)][0];
    }

    public static function defaultVoice(string $lang): string
    {
        return self::MAP[self::normalize($lang)][1];
    }

    /** Only voices that look like a plain provider voice id are accepted (they end up inside TwiML attributes). */
    public static function safeVoice(?string $voice, string $lang): string
    {
        return $voice !== null && preg_match('/^[A-Za-z0-9._\-]{2,60}$/', $voice) ? $voice : self::defaultVoice($lang);
    }

    public static function fallbackPhrase(string $lang): string
    {
        $p = [
            'en' => 'Let me check that for you and have someone confirm the exact details.',
            'ar' => 'دعني أتأكد من التفاصيل وسيؤكدها لك أحد الزملاء.',
            'fr' => 'Je vérifie cela et un collègue vous confirmera les détails exacts.',
            'de' => 'Ich prüfe das und ein Kollege bestätigt Ihnen die genauen Details.',
            'es' => 'Lo reviso y un compañero le confirmará los detalles exactos.',
            'it' => 'Verifico e un collega le confermerà i dettagli esatti.',
            'ru' => 'Я уточню это, и коллега подтвердит вам точные детали.',
            'tr' => 'Bunu kontrol edeyim, tam ayrıntıları bir meslektaşım size doğrulasın.',
        ];
        return $p[self::normalize($lang)];
    }

    public static function handoffPhrase(string $lang): string
    {
        $p = [
            'en' => 'One moment, I am connecting you to a colleague.',
            'ar' => 'لحظة من فضلك، سأحوّلك إلى أحد الزملاء.',
            'fr' => 'Un instant, je vous passe un collègue.',
            'de' => 'Einen Moment, ich verbinde Sie mit einem Kollegen.',
            'es' => 'Un momento, le paso con un compañero.',
            'it' => 'Un attimo, la passo a un collega.',
            'ru' => 'Минуту, соединяю вас с коллегой.',
            'tr' => 'Bir saniye, sizi bir meslektaşıma bağlıyorum.',
        ];
        return $p[self::normalize($lang)];
    }

    public static function callbackPhrase(string $lang): string
    {
        $p = [
            'en' => 'Thank you for calling. Our team will call you back shortly. Goodbye.',
            'ar' => 'شكرًا لاتصالك. سيعاود فريقنا الاتصال بك قريبًا. مع السلامة.',
            'fr' => 'Merci de votre appel. Notre équipe vous rappellera très bientôt. Au revoir.',
            'de' => 'Danke für Ihren Anruf. Unser Team ruft Sie in Kürze zurück. Auf Wiedersehen.',
            'es' => 'Gracias por llamar. Nuestro equipo le devolverá la llamada en breve. Adiós.',
            'it' => 'Grazie per la chiamata. Il nostro team la richiamerà a breve. Arrivederci.',
            'ru' => 'Спасибо за звонок. Наша команда скоро перезвонит вам. До свидания.',
            'tr' => 'Aradığınız için teşekkürler. Ekibimiz sizi kısa süre içinde arayacak. Hoşça kalın.',
        ];
        return $p[self::normalize($lang)];
    }

    public static function repromptPhrase(string $lang): string
    {
        $p = [
            'en' => "Sorry, I didn't catch that. Could you say it again?", 'ar' => 'عذرًا، لم أسمعك جيدًا. هل يمكنك الإعادة؟',
            'fr' => "Désolé, je n'ai pas compris. Pouvez-vous répéter ?", 'de' => 'Entschuldigung, ich habe das nicht verstanden. Könnten Sie es wiederholen?',
            'es' => 'Perdone, no le he oído bien. ¿Puede repetirlo?', 'it' => 'Mi scusi, non ho capito. Può ripetere?',
            'ru' => 'Извините, я не расслышал. Можете повторить?', 'tr' => 'Üzgünüm, anlayamadım. Tekrar eder misiniz?',
        ];
        return $p[self::normalize($lang)];
    }

    public static function goodbyePhrase(string $lang): string
    {
        $p = [
            'en' => "I'm not hearing anything, so I'll end the call. Please call us again any time. Goodbye.", 'ar' => 'لا أسمع شيئًا، لذلك سأنهي المكالمة. يمكنك الاتصال بنا في أي وقت. مع السلامة.',
            'fr' => "Je n'entends rien, je vais donc terminer l'appel. N'hésitez pas à rappeler. Au revoir.", 'de' => 'Ich höre nichts und beende das Gespräch. Rufen Sie gern jederzeit wieder an. Auf Wiedersehen.',
            'es' => 'No oigo nada, así que terminaré la llamada. Llámenos cuando quiera. Adiós.', 'it' => 'Non sento nulla, quindi termino la chiamata. Ci richiami quando vuole. Arrivederci.',
            'ru' => 'Я ничего не слышу и завершаю звонок. Звоните нам в любое время. До свидания.', 'tr' => 'Bir şey duymuyorum, görüşmeyi sonlandırıyorum. Dilediğiniz zaman tekrar arayın. Hoşça kalın.',
        ];
        return $p[self::normalize($lang)];
    }
}
