<?php
/** Customer-facing output follows the audience, never the dashboard locale. */
class ContentLanguageService
{
    private const TASKS = ['competitor_analysis','competitor_site_fix','competitive_insights','website_analysis','autofix_values','marketing_assistant','social_content','article_generation','website_builder','ad_copy','ad_keywords','ad_market','ad_competitor','landing_analysis','content_generation','gbp_content','review_content','creative_plan','video_script','strategic_seo_plan','seo_keywords','gbp_insights','brand_dna','brand_learning'];

    public static function fromAudience(array $site, array $market = [], string $override = 'auto'): string
    {
        if (InternationalSettingsService::validLanguage($override)) return $override;
        foreach ([$site['target_languages'] ?? [], $market['languages'] ?? []] as $languages) {
            if (is_string($languages)) $languages = json_decode($languages,true) ?: [];
            if (!is_array($languages)) continue;
            foreach ($languages as $language) {
                $code = is_array($language) ? ($language['code'] ?? '') : $language;
                $code = strtolower(explode('-', (string)$code)[0]);
                if (preg_match('/^[a-z]{2}$/D', $code)) return $code;
            }
        }
        // Legacy target_language=ar was a UI default, not proof of an Arabic audience.
        return 'en';
    }

    public static function forWebsite(int $websiteId, ?int $userId = null): string
    {
        $pdo=Database::getInstance()->getConnection();
        $q=$pdo->prepare('SELECT * FROM websites WHERE id=?');$q->execute([$websiteId]);$site=$q->fetch(PDO::FETCH_ASSOC);
        if (!$site || ($userId !== null && (int)$site['user_id'] !== $userId)) throw new RuntimeException('Website not found');
        $q=$pdo->prepare('SELECT output_language FROM website_content_policies WHERE website_id=? AND user_id=?');$q->execute([$websiteId,$site['user_id']]);$override=$q->fetchColumn() ?: 'auto';
        $market=[];
        if (!empty($site['business_id'])) {
            $q=$pdo->prepare('SELECT target_languages,target_countries FROM business_target_markets WHERE business_id=? LIMIT 1');$q->execute([$site['business_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if ($row) $market=['languages'=>$row['target_languages'],'countries'=>$row['target_countries']];
        }
        $audience=InternationalSettingsService::audience((int)$site['user_id'],$websiteId);
        if ($audience['configured']) { $site['target_languages']=$audience['target_languages']; $market=[]; }
        return self::fromAudience($site,$market,$override);
    }

    public static function forUser(int $userId, ?int $websiteId = null): string
    {
        if ($websiteId) return self::forWebsite($websiteId,$userId);
        $q=Database::getInstance()->getConnection()->prepare('SELECT id FROM websites WHERE user_id=? LIMIT 2');$q->execute([$userId]);$sites=$q->fetchAll(PDO::FETCH_COLUMN);
        return count($sites)===1 ? self::forWebsite((int)$sites[0],$userId) : 'en';
    }

    public static function instruction(string $language): string
    {
        $names=['de'=>'German','fr'=>'French','it'=>'Italian','es'=>'Spanish','nl'=>'Dutch','pt'=>'Portuguese','ru'=>'Russian','uk'=>'Ukrainian','pl'=>'Polish','cs'=>'Czech','tr'=>'Turkish'];
        $name=$language==='ar'?'Arabic for the explicitly selected Arabic-speaking audience':($language==='en'?'English for international travelers':(isset($names[$language])?$names[$language].' (native, natural wording for travelers from that market)':$language));
        return "\n[Tourfecto audience output policy]\nWrite all generated customer-facing text, recommendations, titles, descriptions, captions and CTA labels in {$name}. Dashboard language, the operator's location and Arabic source notes must not change the output language. Translate source facts faithfully; never invent offers, prices, ratings or business claims. Keep JSON keys and machine identifiers unchanged. This output-language policy overrides legacy prompt defaults.\n";
    }

    public static function apply(string $prompt, array $options): string
    {
        if (!in_array($options['task'] ?? '',self::TASKS,true) || str_contains($prompt,'[Tourfecto audience output policy]')) return $prompt;
        $uid=(int)($options['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $wid=(int)($options['website_id'] ?? 0);
        if(!$wid && $uid){$sites=Database::getInstance()->query('SELECT id FROM websites WHERE user_id=? LIMIT 2',[$uid]);if(count($sites)===1)$wid=(int)$sites[0]['id'];}
        $language=$options['output_language'] ?? null;
        if (!$language) {
            $language=$wid?self::forWebsite($wid,$uid?:null):($uid?self::forUser($uid):'en');
        }
        if (!is_string($language) || !preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/D',$language)) throw new InvalidArgumentException('Invalid content language');
        $context='';
        if(!empty($wid)){
            $audience=InternationalSettingsService::audience($uid ?: (int)(Database::getInstance()->query('SELECT user_id FROM websites WHERE id=?',[$wid])[0]['user_id']??0),$wid);
            $context='\n[Website market context] Audience country codes: '.json_encode($audience['target_countries']).'. Selling currency for new draft guidance: '.$audience['selling_currency'].'. Preserve actual source prices and their currencies. Do not invent, relabel or convert prices; ad budget and subscription billing currencies are independent.';
        }
        return $prompt.self::instruction($language).$context;
    }
}
