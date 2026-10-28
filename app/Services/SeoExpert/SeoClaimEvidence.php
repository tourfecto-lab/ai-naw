<?php
/** Conservative evidence checks for observed risky claims, not a general fact checker.
 * Keep complete sentences: a different tour's offer or a negated policy cannot
 * prove an affirmative, site-wide promise. Callers enforce profile ownership/revision.
 */
final class SeoClaimEvidence
{
    public static function snapshotFacts(array $snapshot): array
    {
        $profile=$snapshot['brief']['shared_profile']??[];
        return !empty($profile['version'])&&empty($profile['needs_reconfirmation'])&&is_array($profile['facts']??null)?$profile['facts']:[];
    }

    private static function sentences(string $text): array
    {
        $text=html_entity_decode(preg_replace('~<[^>]+>~u',"\n",$text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return preg_split('/(?<=[.!?؟])\s+|(?<=[。！？])|\R/u',$text)?:[];
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}%]+/u',' ',mb_strtolower($text)));
    }

    public static function review(array $parts,array $facts): array
    {
        // Audience, goals, competitors, imported pages and product-specific offers
        // do not confirm general booking policies. No semantic inference is made.
        $confirmed=[];
        foreach(self::sentences(is_string($facts['business_facts']??null)?$facts['business_facts']:'') as $fact){
            $fact=trim($fact);
            if(preg_match('/[?؟][*_\s]*$/u',$fact)||preg_match('/^[#*\s-]*(?:confirm|check|ask|verify|تأكد|اسأل|راجع)(?:\s|:)/iu',$fact))continue;
            $confirmed[]=self::normalize($fact);
        }
        $patterns=[
            'cancellation_policy'=>'/\b(?:free\s+cancell?ation|cancell?ation\s+(?:is\s+)?free|cancel\s+(?:for\s+free|free\s+of\s+charge))\b|إلغاء\s+مجاني|الإلغاء\s+مجاني/iu',
            'guide_credentials'=>'/\b(?:licensed|certified|accredited)\s+(?:(?:local|professional|expert|egyptologist|tour)\s+){0,3}(?:guides?|egyptologists?)\b|مرشد[\p{L}\s]{0,35}(?:مرخص|معتمد)/iu',
            'child_pricing'=>'/\b(?:child(?:ren)?|kids?|infants?)\b.{0,180}\b(?:free|discount|reduction|reduced|percent|per\s+cent|half[\s-]price)\b|\b(?:child(?:ren)?|kids?|infants?)\b.{0,180}[0-9]+\s*%|(?:الأطفال|الاطفال|أطفال|اطفال).{0,180}(?:مجان|خصم|تخفيض|بالمئة|بالمائة|%)/iu',
        ];
        // نفس الادعاءات بلغات الأسواق السياحية الكبيرة (الطيار الآلي بيكتب بلغة كل سوق، فالحماية لازم تفهمها كلها)
        $patterns['cancellation_policy'].='|(?:kostenlos\w*\s+(?:storn|absag)|storn\w*\s+(?:ist\s+)?(?:kostenlos|gebührenfrei|gratis)|gebührenfrei\w*\s+storn|annulation\s+(?:gratuite|sans\s+frais)|annul\w*\s+gratuitement|cancelaci[oó]n\s+(?:gratuita|gratis|sin\s+coste)|cancela\w*\s+gratis|cancellazione\s+gratuita|disdetta\s+gratuita|annullamento\s+gratuito|cancelamento\s+(?:gr[aá]tis|gratuito)|gratis\s+annuler|kosteloos\s+annuler|gratis\s+annulering|бесплатн\w*\s+отмен|отмен\w*\s+бесплатн|免费取消|無料キャンセル|キャンセル無料|무료\s*취소|ücretsiz\s+iptal|(?:bezpłatn|darmow)\w*\s+(?:anulowan|odwołan))/iu';
        $patterns['cancellation_policy']=str_replace('/iu|','|',$patterns['cancellation_policy']);
        $patterns['guide_credentials'].='|(?:(?:lizenziert|zertifiziert|staatlich\s+geprüft|diplomiert)\w*\s+(?:\p{L}+\s+){0,3}(?:reiseleiter|guide|führer|ägyptolog)|guides?\s+(?:\p{L}+\s+){0,2}(?:agréé|certifié|diplômé|licencié)|égyptologues?\s+(?:certifié|diplômé)|gu[ií]as?\s+(?:\p{L}+\s+){0,2}(?:licenciad|certificad|acreditad|oficial)|guid[ae]\s+(?:\p{L}+\s+){0,2}(?:autorizzat|certificat|abilitat|patentat|ufficial)|guias?\s+(?:\p{L}+\s+){0,2}(?:licenciad|certificad|credenciad)|(?:gecertificeerde|erkende|gediplomeerde)\s+(?:\p{L}+\s+){0,2}gids|(?:лицензированн|сертифицированн|аккредитованн|дипломированн)\w*\s+(?:\p{L}+\s+){0,2}гид|(?:持证|有执照|认证|持牌|官方)\S{0,6}导游|(?:公認|認定|資格を持つ)\S{0,6}ガイド|(?:공인|자격증)\S{0,6}가이드|(?:lisanslı|sertifikalı|kokartlı)\s+(?:\p{L}+\s+){0,2}rehber|(?:licencjonowan|certyfikowan)\w*\s+(?:\p{L}+\s+){0,2}przewodni)/iu';
        $patterns['guide_credentials']=str_replace('/iu|','|',$patterns['guide_credentials']);
        $patterns['child_pricing'].='|(?:kinder.{0,180}(?:kostenlos|gratis|rabatt|ermäßig|%)|enfants?.{0,180}(?:gratuit|réduction|remise|%)|niñ[oa]s?.{0,180}(?:gratis|gratuit|descuento|%)|bambini.{0,180}(?:gratis|gratuit|sconto|riduzion|%)|crianças?.{0,180}(?:gr[aá]tis|gratuit|desconto|%)|kinderen.{0,180}(?:gratis|korting|%)|дет\w*.{0,180}(?:бесплатн|скидк|%)|(?:儿童|孩子|小孩).{0,40}(?:免费|折扣|优惠|半价|%)|(?:子供|こども).{0,40}(?:無料|割引|%)|(?:어린이|아동).{0,40}(?:무료|할인|%)|çocuk.{0,180}(?:ücretsiz|indirim|%)|dzieci.{0,180}(?:bezpłatn|darmow|zniżk|%))/iu';
        $patterns['child_pricing']=str_replace('/iu|','|',$patterns['child_pricing']);
        $findings=[];$seen=[];
        foreach($parts as $field=>$text){
            if(!is_string($text))continue;
            foreach(self::sentences($text) as $sentence){
                $sentence=trim($sentence);if($sentence==='')continue;
                // Questions and requests for confirmation remain editorial guidance.
                $plain=ltrim($sentence,"#*- \t");
                if(preg_match('/^(?:confirm|check|ask|verify|تأكد|اسأل|راجع)(?:\s|:)/iu',$plain))continue;
                if(preg_match('/^(?:is|are|can|do|does|what|when|where|how|هل|ما|متى|كيف)\b/iu',$plain)&&preg_match('/[?؟][*_\s]*$/u',$plain))continue;
                foreach($patterns as $code=>$pattern){
                    if(!preg_match($pattern,$sentence)||in_array(self::normalize($sentence),$confirmed,true))continue;
                    $key=$field.'|'.$code.'|'.$sentence;if(isset($seen[$key]))continue;$seen[$key]=true;
                    $findings[]=['code'=>$code,'field'=>(string)$field,'quote'=>mb_substr($sentence,0,400)];
                    if(count($findings)>=20)break 3;
                }
            }
        }
        return ['state'=>$findings?'needs_confirmation':'no_flagged_claims','basis'=>'confirmed_business_facts_exact_sentence','findings'=>$findings,
            'reason'=>$findings?'Confirm the flagged policy or credential in business facts using the same complete sentence and scope, or remove it from the proposal before approval. Existing publication history is retained. / أكد السياسة أو المؤهل في معلومات النشاط بنفس الجملة الكاملة ونطاقها، أو احذف الادعاء من الاقتراح قبل الاعتماد. سجل النشر السابق محفوظ.':'No claim was flagged by these limited checks; editorial review is still required. / لم يرصد الفحص المحدود ادعاءً يحتاج تأكيدًا؛ المراجعة التحريرية ما زالت مطلوبة.'];
    }

    public static function task(array $task,array $facts): array
    {
        $parts=[];
        if(($task['kind']??'')==='metadata'&&in_array($task['field_name']??'',['title','description'],true))$parts[$task['field_name']]=(string)($task['expected_value']??'');
        if(($task['kind']??'')==='content'){
            $parts=['title'=>(string)($task['title']??''),'content'=>(string)($task['expected_value']??''),'meta_description'=>(string)($task['content_review']['meta_description']??'')];
            foreach($task['content_review']['faqs']??[] as $i=>$faq)if(is_array($faq))$parts['faq_answer_'.$i]=(string)($faq['answer']??'');
        }
        return self::review($parts,$facts);
    }
}
