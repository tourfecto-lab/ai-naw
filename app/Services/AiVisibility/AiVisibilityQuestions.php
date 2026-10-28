<?php
/** Local generation from confirmed offers + provider keywords; generation incurs no extra paid AI call. */
final class AiVisibilityQuestions
{
    public static function validate(array $questions,array $aliases): array
    {
        if (count($questions)<10 || count($questions)>30) throw new InvalidArgumentException('راجع من 10 لـ30 سؤال لكل سوق');
        $out=[];$seen=[];
        foreach ($questions as $q) {
            if (!is_string($q) || mb_strlen(trim($q))<10 || mb_strlen(trim($q))>300 || preg_match('/[\x00-\x1f<>]/u',$q)) throw new InvalidArgumentException('راجع نص السؤال (10–300 حرف)');
            $q=trim($q);$key=AiVisibilityDetector::normalize($q);
            if (AiVisibilityDetector::firstMention($q,$aliases)!==null || preg_match('~https?://|\bwww\.|@~iu',$q)) throw new InvalidArgumentException('السؤال لازم يكون من غير اسم الشركة أو دومينها أو رابط');
            if (isset($seen[$key])) throw new InvalidArgumentException('فيه أسئلة مكررة');
            $seen[$key]=true;$out[]=$q;
        }
        return $out;
    }
    public static function generate(array $facts,array $keywords,string $language,array $aliases,int $count=20): array
    {
        $templates=[
            'en'=>['What should I compare before booking %s?','What is included in %s?','What published prices can I find for %s?','Which operators offer %s?','Where can I find genuine traveler reviews of %s?','What cancellation terms apply to %s?','How do I choose a reliable provider for %s?','What should a first-time traveler know about %s?','What pickup options are published for %s?','What questions should I ask before paying for %s?'],
            'ar'=>['أقارن إيه قبل حجز %s؟','إيه اللي شاملُه %s؟','إيه الأسعار المنشورة لـ %s؟','مين الشركات اللي بتقدم %s؟','فين ألاقي تجارب مسافرين حقيقية عن %s؟','إيه شروط إلغاء %s؟','إزاي أختار مقدم خدمة موثوق لـ %s؟','إيه اللي لازم أعرفه قبل تجربة %s لأول مرة؟','إيه اختيارات الانتقال المنشورة لـ %s؟','أسأل عن إيه قبل دفع حجز %s؟'],
            'de'=>['Was sollte ich vor der Buchung von %s vergleichen?','Was ist bei %s enthalten?','Welche veröffentlichten Preise gibt es für %s?','Welche Anbieter bieten %s an?','Wo finde ich echte Bewertungen zu %s?','Welche Stornobedingungen gelten für %s?','Wie finde ich einen zuverlässigen Anbieter für %s?','Was muss ich vor meiner ersten Buchung von %s wissen?','Welche Abholoptionen gibt es bei %s?','Was sollte ich vor der Bezahlung für %s fragen?'],
            'fr'=>['Que comparer avant de réserver %s ?','Que comprend %s ?','Quels prix publiés existe-t-il pour %s ?','Quels opérateurs proposent %s ?','Où lire des avis authentiques sur %s ?','Quelles conditions d’annulation pour %s ?','Comment choisir un prestataire fiable pour %s ?','Que savoir avant de réserver %s pour la première fois ?','Quelles options de prise en charge pour %s ?','Quelles questions poser avant de payer pour %s ?'],
            'es'=>['¿Qué debo comparar antes de reservar %s?','¿Qué incluye %s?','¿Qué precios publicados hay para %s?','¿Qué operadores ofrecen %s?','¿Dónde encuentro opiniones reales de viajeros sobre %s?','¿Qué condiciones de cancelación tiene %s?','¿Cómo elijo un proveedor fiable para %s?','¿Qué debe saber un viajero primerizo sobre %s?','¿Qué opciones de recogida hay para %s?','¿Qué debo preguntar antes de pagar %s?'],
            'it'=>['Cosa devo confrontare prima di prenotare %s?','Cosa include %s?','Quali prezzi pubblicati ci sono per %s?','Quali operatori offrono %s?','Dove trovo recensioni autentiche di viaggiatori su %s?','Quali condizioni di cancellazione valgono per %s?','Come scelgo un fornitore affidabile per %s?','Cosa deve sapere chi prenota %s per la prima volta?','Quali opzioni di prelievo ci sono per %s?','Cosa devo chiedere prima di pagare %s?'],
            'pt'=>['O que devo comparar antes de reservar %s?','O que está incluído em %s?','Que preços publicados existem para %s?','Quais operadores oferecem %s?','Onde encontro avaliações reais de viajantes sobre %s?','Quais são as condições de cancelamento de %s?','Como escolho um fornecedor confiável para %s?','O que um viajante de primeira viagem deve saber sobre %s?','Que opções de transporte existem para %s?','O que devo perguntar antes de pagar %s?'],
            'nl'=>['Wat moet ik vergelijken voordat ik %s boek?','Wat is inbegrepen bij %s?','Welke gepubliceerde prijzen zijn er voor %s?','Welke aanbieders bieden %s aan?','Waar vind ik echte reizigersreviews over %s?','Welke annuleringsvoorwaarden gelden voor %s?','Hoe kies ik een betrouwbare aanbieder voor %s?','Wat moet een beginnende reiziger weten over %s?','Welke ophaalopties zijn er voor %s?','Wat moet ik vragen voordat ik betaal voor %s?'],
            'ru'=>['Что сравнить перед бронированием %s?','Что входит в %s?','Какие цены указаны на %s?','Какие компании предлагают %s?','Где найти настоящие отзывы туристов о %s?','Какие условия отмены у %s?','Как выбрать надёжного организатора %s?','Что нужно знать новичку о %s?','Какие варианты трансфера есть для %s?','Что спросить перед оплатой %s?'],
            'zh'=>['预订%s之前应该比较哪些方面？','%s包含哪些内容？','%s公开的价格是多少？','哪些旅行社提供%s？','在哪里可以看到%s的真实游客评价？','%s的取消政策是什么？','如何选择靠谱的%s服务商？','第一次参加%s需要了解什么？','%s有哪些接送选项？','支付%s之前应该问哪些问题？'],
            'ja'=>['%sを予約する前に何を比較すべきですか？','%sには何が含まれていますか？','%sの公開されている料金はいくらですか？','%sを提供している会社はどこですか？','%sの本物の旅行者レビューはどこで見られますか？','%sのキャンセル条件は何ですか？','%sの信頼できる会社はどう選べばいいですか？','初めて%sに参加する人が知っておくべきことは？','%sにはどんな送迎オプションがありますか？','%sの支払い前に何を確認すべきですか？'],
            'ko'=>['%s 예약 전에 무엇을 비교해야 하나요?','%s에는 무엇이 포함되나요?','%s의 공개된 가격은 얼마인가요?','%s를 제공하는 업체는 어디인가요?','%s에 대한 실제 여행자 후기는 어디서 볼 수 있나요?','%s의 취소 규정은 어떻게 되나요?','%s의 믿을 만한 업체는 어떻게 고르나요?','처음 %s를 하는 여행자가 알아야 할 것은?','%s에는 어떤 픽업 옵션이 있나요?','%s 결제 전에 무엇을 물어봐야 하나요?'],
            'tr'=>['%s rezervasyonundan önce neleri karşılaştırmalıyım?','%s neleri kapsıyor?','%s için yayınlanmış fiyatlar nelerdir?','Hangi firmalar %s sunuyor?','%s hakkında gerçek gezgin yorumlarını nerede bulurum?','%s için iptal koşulları nelerdir?','%s için güvenilir bir firma nasıl seçilir?','İlk kez %s yapacak biri neleri bilmeli?','%s için hangi transfer seçenekleri var?','%s ödemesinden önce neler sormalıyım?'],
            'pl'=>['Co porównać przed rezerwacją %s?','Co obejmuje %s?','Jakie są opublikowane ceny %s?','Którzy organizatorzy oferują %s?','Gdzie znajdę prawdziwe opinie podróżnych o %s?','Jakie są warunki anulowania %s?','Jak wybrać rzetelnego organizatora %s?','Co powinien wiedzieć ktoś, kto pierwszy raz wybiera %s?','Jakie są opcje odbioru dla %s?','O co zapytać przed zapłatą za %s?']];
        $language=strtolower(explode('-',$language)[0]);
        if (!isset($templates[$language])) throw new InvalidArgumentException('اكتب وراجع الأسئلة يدويًا للغة السوق دي');
        $confirmed=AiVisibilityDetector::normalize(($facts['business_facts']??'').' '.($facts['offers']??''));
        if ($confirmed==='') throw new InvalidArgumentException('أكد تفاصيل الخدمة والعروض في بيانات الشركة الأول');
        $seeds=[];
        foreach ($keywords as $row) {
            if (($row['source']??'')!=='dataforseo' || !isset($row['volume'])) continue;
            $keyword=trim((string)($row['keyword']??''));$fresh=strtotime(($row['fetched_at']??'').' UTC');
            if (!$fresh || $fresh<time()-30*86400 || $fresh>time()+300 || mb_strlen($keyword)>130 || AiVisibilityDetector::firstMention($keyword,$aliases)!==null) continue;
            $overlap=false; foreach (explode(' ',AiVisibilityDetector::normalize($keyword)) as $word) if (mb_strlen($word)>=4 && str_contains($confirmed,$word)) { $overlap=true; break; }
            if ($overlap) $seeds[]=$keyword;
        }
        if (!$seeds) throw new InvalidArgumentException('محتاج كلمات DataForSEO حديثة مرتبطة بالخدمات المؤكدة؛ أو اكتب الأسئلة يدويًا');
        $out=[];
        foreach ($templates[$language] as $template) foreach ($seeds as $seed) {
            $q=sprintf($template,$seed);if (mb_strlen($q)<=300 && !in_array($q,$out,true)) $out[]=$q;
            if (count($out)>=max(10,min(30,$count))) break 2;
        }
        return self::validate($out,$aliases);
    }
}
