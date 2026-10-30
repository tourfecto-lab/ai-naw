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

    public static function optOutPhrase(string $lang): string
    {
        $p = [
            'en' => "Understood. I've removed your number from our call list and you won't be called again. Goodbye.",
            'ar' => 'تم. حذفنا رقمك من قائمة الاتصال ولن نتصل بك مرة أخرى. مع السلامة.',
            'fr' => "Entendu. J'ai retiré votre numéro de notre liste d'appels, vous ne serez plus appelé. Au revoir.",
            'de' => 'Verstanden. Ihre Nummer wurde aus unserer Anrufliste entfernt, Sie werden nicht mehr angerufen. Auf Wiedersehen.',
            'es' => 'Entendido. He eliminado su número de nuestra lista de llamadas y no volverá a recibir llamadas. Adiós.',
            'it' => 'Capito. Ho rimosso il suo numero dal nostro elenco e non sarà più chiamato. Arrivederci.',
            'ru' => 'Понял. Я удалил ваш номер из списка обзвона, вам больше не будут звонить. До свидания.',
            'tr' => 'Anlaşıldı. Numaranızı arama listemizden çıkardım, bir daha aranmayacaksınız. Hoşça kalın.',
        ];
        return $p[self::normalize($lang)];
    }

    /** Fixed opening of an outbound call: who is calling, that it is an AI, who we want, and how to stop. %1$s agent %2$s business %3$s name */
    public static function outboundOpening(string $lang, string $agent, string $business, ?string $firstName): string
    {
        $who = [
            'en' => $firstName ? "Am I speaking with $firstName?" : 'Is this a good moment to talk?',
            'ar' => $firstName ? "هل أتحدث مع {$firstName}؟" : 'هل هذا وقت مناسب للحديث؟',
            'fr' => $firstName ? "Suis-je bien en ligne avec {$firstName} ?" : 'Est-ce le bon moment pour parler ?',
            'de' => $firstName ? "Spreche ich mit {$firstName}?" : 'Passt es gerade kurz?',
            'es' => $firstName ? "¿Hablo con {$firstName}?" : '¿Es un buen momento para hablar?',
            'it' => $firstName ? "Parlo con {$firstName}?" : 'È un buon momento per parlare?',
            'ru' => $firstName ? "Я говорю с {$firstName}?" : 'Вам сейчас удобно говорить?',
            'tr' => $firstName ? "{$firstName} ile mi görüşüyorum?" : 'Şu an konuşmak için uygun mu?',
        ];
        $head = [
            'en' => "Hello, this is {$agent}, an AI assistant calling from {$business}.", 'ar' => "مرحبًا، معك {$agent}، مساعد ذكاء اصطناعي من {$business}.",
            'fr' => "Bonjour, ici {$agent}, un assistant IA de {$business}.", 'de' => "Guten Tag, hier ist {$agent}, ein KI-Assistent von {$business}.",
            'es' => "Hola, le habla {$agent}, un asistente de IA de {$business}.", 'it' => "Buongiorno, sono {$agent}, un assistente IA di {$business}.",
            'ru' => "Здравствуйте, это {$agent}, ИИ-ассистент компании {$business}.", 'tr' => "Merhaba, ben {$business} şirketinden {$agent}, bir yapay zeka asistanıyım.",
        ];
        $stop = [
            'en' => 'You can say "stop calling" at any time and we will not call again.', 'ar' => 'يمكنك قول "لا تتصل" في أي وقت ولن نتصل بك مرة أخرى.',
            'fr' => 'Dites « ne m\'appelez plus » à tout moment et nous ne rappellerons pas.', 'de' => 'Sagen Sie jederzeit „nicht mehr anrufen“, dann rufen wir nicht wieder an.',
            'es' => 'Puede decir «no me llame» en cualquier momento y no volveremos a llamar.', 'it' => 'Può dire «non chiamatemi più» in qualsiasi momento e non richiameremo.',
            'ru' => 'Скажите «не звоните» в любой момент, и мы больше не позвоним.', 'tr' => 'İstediğiniz an "beni aramayın" derseniz bir daha aramayız.',
        ];
        $l = self::normalize($lang);
        return $head[$l] . ' ' . $who[$l] . ' ' . $stop[$l];
    }

    /** Voicemail message: says only who called and to call back. No reason, no booking or payment detail. */
    public static function voicemailPhrase(string $lang, string $business): string
    {
        $p = [
            'en' => "Hello, this is {$business} calling. We tried to reach you. Please call us back when you can. Thank you.",
            'ar' => "مرحبًا، هنا {$business}. حاولنا الاتصال بك. يرجى معاودة الاتصال بنا عند الإمكان. شكرًا لك.",
            'fr' => "Bonjour, ici {$business}. Nous avons essayé de vous joindre. Merci de nous rappeler dès que possible.",
            'de' => "Guten Tag, hier ist {$business}. Wir haben versucht, Sie zu erreichen. Bitte rufen Sie uns zurück. Danke.",
            'es' => "Hola, le llama {$business}. Intentamos contactarle. Por favor, llámenos cuando pueda. Gracias.",
            'it' => "Buongiorno, è {$business}. Abbiamo cercato di contattarla. La preghiamo di richiamarci. Grazie.",
            'ru' => "Здравствуйте, это {$business}. Мы пытались с вами связаться. Пожалуйста, перезвоните нам. Спасибо.",
            'tr' => "Merhaba, {$business} arıyor. Size ulaşmaya çalıştık. Lütfen bizi geri arayın. Teşekkürler.",
        ];
        return $p[self::normalize($lang)];
    }

    /** The languages one assistant may speak: its primary language plus the extra ones the company enabled. @return string[] */
    public static function allowedFor(array $agent): array
    {
        $out = [self::normalize($agent['language'] ?? 'en')];
        foreach (array_filter(array_map('trim', explode(',', (string) ($agent['languages'] ?? '')))) as $l) {
            $n = self::normalize($l, '');
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** What the assistant answers in: the language it detected if the company enabled it, otherwise its primary language. */
    public static function pick(?string $detected, array $agent): string
    {
        $allowed = self::allowedFor($agent);
        $d = $detected !== null ? self::normalize($detected, '') : '';
        return $d !== '' && in_array($d, $allowed, true) ? $d : $allowed[0];
    }

    public static function aiDisclosure(string $lang): string
    {
        $p = [
            'en' => "I'm an AI assistant.", 'ar' => 'أنا مساعد ذكاء اصطناعي.', 'fr' => 'Je suis un assistant IA.', 'de' => 'Ich bin ein KI-Assistent.',
            'es' => 'Soy un asistente de IA.', 'it' => 'Sono un assistente IA.', 'ru' => 'Я ИИ-ассистент.', 'tr' => 'Ben bir yapay zeka asistanıyım.',
        ];
        return $p[self::normalize($lang)];
    }

    public static function afterHoursPhrase(string $lang): string
    {
        $p = [
            'en' => 'Our office is closed right now, but I can take your details and have the team call you back.',
            'ar' => 'مكتبنا مغلق الآن، لكن يمكنني تسجيل بياناتك ليتصل بك الفريق.',
            'fr' => 'Nos bureaux sont fermés pour le moment, mais je peux noter vos coordonnées pour que l\'équipe vous rappelle.',
            'de' => 'Unser Büro ist gerade geschlossen, aber ich kann Ihre Daten aufnehmen, damit das Team Sie zurückruft.',
            'es' => 'Nuestra oficina está cerrada ahora, pero puedo tomar sus datos para que el equipo le llame.',
            'it' => 'Il nostro ufficio è chiuso al momento, ma posso prendere i suoi dati e farla richiamare dal team.',
            'ru' => 'Наш офис сейчас закрыт, но я могу записать ваши данные, и команда вам перезвонит.',
            'tr' => 'Ofisimiz şu an kapalı, ancak bilgilerinizi alıp ekibimizin sizi aramasını sağlayabilirim.',
        ];
        return $p[self::normalize($lang)];
    }
}
