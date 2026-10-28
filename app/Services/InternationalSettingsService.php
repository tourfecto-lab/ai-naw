<?php
/** Account locale and website audience are independent; no automatic money conversion. */
class InternationalSettingsService
{
    public static function countries(): array {return require dirname(__DIR__).'/Config/international_countries.php';}
    public static function languages(): array {return ['en'=>'English','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch','es'=>'Español','it'=>'Italiano','pt'=>'Português','nl'=>'Nederlands','ru'=>'Русский','tr'=>'Türkçe','zh'=>'中文','ja'=>'日本語','ko'=>'한국어','hi'=>'हिन्दी','id'=>'Bahasa Indonesia','th'=>'ไทย','vi'=>'Tiếng Việt','pl'=>'Polski','sv'=>'Svenska','da'=>'Dansk','no'=>'Norsk','fi'=>'Suomi','el'=>'Ελληνικά','cs'=>'Čeština','ro'=>'Română','hu'=>'Magyar','uk'=>'Українська','he'=>'עברית','fa'=>'فارسی','ur'=>'اردو','bn'=>'বাংলা','ms'=>'Bahasa Melayu','sw'=>'Kiswahili','ta'=>'தமிழ்','te'=>'తెలుగు'];}
    public static function validLanguage(string $language): bool {return array_key_exists($language,self::languages());}
    public static function currencies(): array {return UserController::isoCurrencies();}
    public static function accountValues(array $input): array {
        $out=[];
        foreach(['country_code','language','timezone','currency'] as $field){
            if(!array_key_exists($field,$input))continue;
            if(!is_string($input[$field]))throw new InvalidArgumentException('Invalid '.$field);
            $v=trim($input[$field]);
            if(in_array($field,['country_code','currency'],true))$v=strtoupper($v);
            if($field==='country_code' && $v!=='' && !isset(self::countries()[$v]))throw new InvalidArgumentException('Invalid company country');
            if($field==='language' && !in_array($v,defined('UI_SUPPORTED_LANGUAGES')?UI_SUPPORTED_LANGUAGES:['en','ar','fr','de'],true))throw new InvalidArgumentException('Unsupported dashboard language');
            if($field==='timezone' && !in_array($v,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true) && $v!=='UTC')throw new InvalidArgumentException('Invalid timezone');
            if($field==='currency' && !isset(self::currencies()[$v]))throw new InvalidArgumentException('Unsupported display currency');
            $out[$field]=$v;
        }
        return $out;
    }
    public static function localTimestamp(string $input,string $timezone): int {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D',$input))throw new InvalidArgumentException('Invalid scheduled time');
        $hasOffset=(bool)preg_match('/Z$|[+-]\d{2}:\d{2}$/D',$input);
        try{$date=new DateTimeImmutable($input,new DateTimeZone($hasOffset?'UTC':$timezone));}catch(Throwable $e){throw new InvalidArgumentException('Invalid scheduled time');}
        $errors=DateTimeImmutable::getLastErrors();if($errors && ($errors['warning_count']||$errors['error_count']))throw new InvalidArgumentException('Invalid scheduled time');
        if(!$hasOffset && $date->format('Y-m-d\TH:i')!==str_replace(' ','T',substr($input,0,16)))throw new InvalidArgumentException('This local time does not exist in the selected timezone');
        return $date->getTimestamp();
    }
    public static function scheduledTimestamp(int $uid,string $input): int {
        $q=Database::getInstance()->getConnection()->prepare('SELECT timezone FROM users WHERE id=?');$q->execute([$uid]);$zone=$q->fetchColumn()?:'UTC';
        return self::localTimestamp($input,$zone);
    }
    private static function own(int $uid,int $wid): void {
        $q=Database::getInstance()->getConnection()->prepare('SELECT id FROM websites WHERE id=? AND user_id=?');$q->execute([$wid,$uid]);
        if(!$q->fetchColumn())throw new RuntimeException('Website not found');
    }
    public static function audience(int $uid,int $wid): array {
        self::own($uid,$wid);$q=Database::getInstance()->getConnection()->prepare('SELECT * FROM website_audience_preferences WHERE website_id=? AND user_id=?');$q->execute([$wid,$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return ['target_countries'=>$row?json_decode($row['target_countries_json'],true):[], 'target_languages'=>$row?json_decode($row['target_languages_json'],true):[], 'selling_currency'=>$row['selling_currency']??'USD','configured'=>(bool)$row,'needs_setup'=>!$row || !json_decode($row['target_languages_json'],true)];
    }
    public static function validateAudience(array $input): array {
        $countries=$input['target_countries']??[];$languages=$input['target_languages']??[];$currency=$input['selling_currency']??'USD';
        if(!is_array($countries)||!is_array($languages)||count($countries)>249||count($languages)>35||!is_string($currency))throw new InvalidArgumentException('Invalid audience settings');
        $countries=array_values(array_unique(array_map(function($v){if(!is_string($v))throw new InvalidArgumentException('Invalid audience country');return strtoupper(trim($v));},$countries)));
        foreach($languages as $v)if(!is_string($v))throw new InvalidArgumentException('Invalid audience language');
        $languages=array_values(array_unique($languages));
        foreach($countries as $v)if(!isset(self::countries()[$v]))throw new InvalidArgumentException('Unknown audience country');
        foreach($languages as $v)if(!is_string($v)||!self::validLanguage($v))throw new InvalidArgumentException('Unsupported content language');
        $currency=strtoupper(trim($currency));if(!isset(self::currencies()[$currency]))throw new InvalidArgumentException('Unsupported selling currency');
        return ['target_countries'=>$countries,'target_languages'=>$languages,'selling_currency'=>$currency];
    }
    public static function saveAudience(int $uid,int $wid,array $input): array {
        self::own($uid,$wid);$v=self::validateAudience($input);
        Database::getInstance()->transaction(function()use($uid,$wid,$v){
            $pdo=Database::getInstance()->getConnection();$q=$pdo->prepare('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE');$q->execute([$wid,$uid]);if(!$q->fetchColumn())throw new RuntimeException('Website not found');
            $q=$pdo->prepare('INSERT INTO website_audience_preferences(website_id,user_id,target_countries_json,target_languages_json,selling_currency,updated_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE target_countries_json=VALUES(target_countries_json),target_languages_json=VALUES(target_languages_json),selling_currency=VALUES(selling_currency),updated_at=NOW()');
            if(!$q->execute([$wid,$uid,json_encode($v['target_countries']),json_encode($v['target_languages']),$v['selling_currency']]))throw new RuntimeException('Unable to save audience');
            $q=$pdo->prepare("INSERT INTO website_content_policies(website_id,user_id,output_language,competitor_auto_apply,updated_at) VALUES(?,?,'auto',0,NOW()) ON DUPLICATE KEY UPDATE output_language='auto',updated_at=NOW()");if(!$q->execute([$wid,$uid]))throw new RuntimeException('Unable to update content language');
        });return self::audience($uid,$wid);
    }
}
