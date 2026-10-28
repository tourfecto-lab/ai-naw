<?php

/**
 * Tourfecto - Edge Rules
 * القواعد اللي كود Tourfecto Edge (على موقع WordPress بتاع العميل) بيسحبها وينفّذها.
 *
 * مبدأ الأمان الأساسي: السيرفر بيبعت **بيانات** مش كود. كل حقل له مدقّق صارم هنا، والـ snippet بيراجع تاني قبل ما ينفّذ.
 * يعني حتى لو حد اخترق Tourfecto ما يقدرش يشغّل PHP أو JavaScript على مواقع العملاء، أقصى حاجة يغيّر عنوان أو وصف.
 *
 * التوكن: "{websiteId}.{توقيع HMAC}" مش متخزّن في الداتابيزاو وبيتحقق منه توقيع.
 *
 * @version 1.0.0
 */
require_once dirname(__DIR__).'/Seo/GuideContent.php';

class EdgeRules
{
    private const FIELDS = ['title', 'description', 'canonical', 'robots', 'viewport', 'lang', 'og', 'jsonld', 'hsts', 'llms_txt', 'related_links'];

    // ------------------------------------------------------------------ التوكن

    private static function secret(): string
    {
        $k = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-edge-secret';
        return hash('sha256', 'tourfecto-edge-v1|' . $k, true);
    }

    public static function token(int $websiteId): string
    {
        return $websiteId . '.' . substr(hash_hmac('sha256', 'edge|' . $websiteId, self::secret()), 0, 32);
    }

    public static function parseToken(string $token): ?int
    {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', 'edge|' . $m[1], self::secret()), 0, 32);
        return hash_equals($expected, $m[2]) ? (int) $m[1] : null;
    }

    // ------------------------------------------------------------------ المدققات

    /**
     * @param string $field   واحد من FIELDS
     * @param string $raw     القيمة كما اتخزّنت
     * @param string $siteHost دومين الموقع (للـ canonical والروابط)
     * @return string|array|null  القيمة النظيفة أو null لو مرفوضة
     */
    public static function sanitize(string $field, string $raw, string $siteHost = '')
    {
        $raw = trim($raw);
        switch ($field) {
            case 'title':
                $v = self::line($raw);
                $n = mb_strlen($v);
                return ($n >= 10 && $n <= 120) ? $v : null;
            case 'description':
                $v = self::line($raw);
                $n = mb_strlen($v);
                return ($n >= 20 && $n <= 320) ? $v : null;
            case 'canonical':
                if (!preg_match('#^https://[^\s<>"\']+$#i', $raw)) {
                    return null;
                }
                $h = strtolower((string) parse_url($raw, PHP_URL_HOST));
                return ($siteHost !== '' && preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', strtolower($siteHost))) ? $raw : null;
            case 'robots':
                return preg_match('/^(index|noindex)(, ?(follow|nofollow))?$/i', $raw) ? strtolower(str_replace(' ', '', $raw)) : null;
            case 'viewport':
                return preg_match('/^width=device-width, ?initial-scale=1(\.0)?$/i', $raw) ? 'width=device-width, initial-scale=1' : null;
            case 'lang':
                return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $raw) ? $raw : null;
            case 'hsts':
                return preg_match('/^max-age=\d{1,10}(; ?includeSubDomains)?(; ?preload)?$/', $raw) ? $raw : null;
            case 'og':
                $j = json_decode($raw, true);
                if (!is_array($j)) {
                    return null;
                }
                $out = [];
                foreach ($j as $k => $v) {
                    if (!is_string($k) || !is_string($v) || !preg_match('/^(og|twitter):[a-z_:]{2,30}$/', $k)) {
                        continue;
                    }
                    $v = self::line($v);
                    if ($v === '' || mb_strlen($v) > 500) {
                        continue;
                    }
                    if (in_array($k, ['og:image', 'og:url'], true) && !preg_match('#^https://[^\s<>"\']+$#i', $v)) {
                        continue;
                    }
                    $out[$k] = $v;
                }
                return count($out) >= 2 ? $out : null;
            case 'jsonld':
                $j = json_decode($raw, true);
                // نوع واحد (Organization...) أو @graph فيه Schema السياحة (TouristTrip / Breadcrumb / Offer ...)
                if (!is_array($j) || strlen($raw) > 20000 || stripos((string) ($j['@context'] ?? ''), 'schema.org') === false) {
                    return null;
                }
                require_once dirname(__DIR__) . '/SeoExpert/SeoSchemaBuilder.php';
                if (!SeoSchemaBuilder::typesAllowed($j)) {
                    return null;
                }
                return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            case 'related_links':
                // روابط داخلية لصفحات من نفس الموقع (لحد 4) - نص وروابط بس
                $j = json_decode($raw, true);
                if (!is_array($j) || !array_is_list($j) || count($j) < 1 || count($j) > 4) {
                    return null;
                }
                $out = [];
                foreach ($j as $l) {
                    $u = is_array($l) ? (string) ($l['url'] ?? '') : '';
                    $t = is_array($l) ? self::line((string) ($l['title'] ?? '')) : '';
                    if ($t === '' || mb_strlen($t) > 90 || !preg_match('#^https?://[^\s<>"\']+$#i', $u) || strtolower((string) parse_url($u, PHP_URL_HOST)) !== strtolower($siteHost)) {
                        return null;
                    }
                    $out[] = ['url' => $u, 'title' => $t];
                }
                return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'link_fix':
                // روابط مكسورة جوه الصفحة: الرابط القديم → صفحة شغالة من نفس الموقع، أو "to" فاضي = الرابط يتشال ويفضل نصه
                $j = json_decode($raw, true);
                if (!is_array($j) || !array_is_list($j) || count($j) < 1 || count($j) > 10) {
                    return null;
                }
                $out = [];
                foreach ($j as $l) {
                    $from = is_array($l) ? (string) ($l['from'] ?? '') : '';
                    $to = is_array($l) ? (string) ($l['to'] ?? '') : '';
                    foreach ([$from, $to] as $i => $u) {
                        if ($i === 1 && $u === '') {
                            continue;
                        }
                        if (strlen($u) > 500 || !preg_match('#^https?://[^\s<>"\']+$#i', $u) || strtolower((string) parse_url($u, PHP_URL_HOST)) !== strtolower($siteHost)) {
                            return null;
                        }
                    }
                    $out[] = ['from' => $from, 'to' => $to];
                }
                return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'llms_txt':
                return (strlen($raw) >= 20 && strlen($raw) <= 20000 && strpos($raw, '<') === false) ? $raw : null;
        }
        return null;
    }

    private static function line(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    }

    // ------------------------------------------------------------------ payload

    /**
     * أحدث قاعدة فعّالة لكل حقل. @return array<string, string|array>
     */
    public static function rulesFor($db, int $websiteId, string $siteHost): array
    {
        $rows = $db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes WHERE website_id = ? AND is_active = 1 ORDER BY id ASC",
            [$websiteId]
        );
        $reverse = array_flip(FixCapability::DB_FIELD);
        $rules = [];
        foreach ($rows as $r) {
            $field = $reverse[$r['field_name']] ?? null;
            if ($field === null) {
                continue;
            }
            $clean = self::sanitize($field, (string) $r['injected_code'], $siteHost);
            if ($clean !== null) {
                $rules[$field] = $clean;      // الأحدث بيكسب (ORDER BY id ASC)
            } else {
                unset($rules[$field]);
            }
        }
        require_once dirname(__DIR__).'/Seo/ContentQualityService.php';
        $articleSources=[];$exists=$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_expert_tasks'");
        if($exists){
            $pageRows=$db->query("SELECT page_path,field_name,expected_value FROM seo_expert_tasks WHERE website_id=? AND kind='metadata' AND is_active=1 AND status IN ('active','verified','needs_client') ORDER BY id",[$websiteId]);
            $pageRules=[];
            foreach($pageRows as $r){$path=(string)$r['page_path'];$field=(string)$r['field_name'];if(!in_array($field,['title','description','canonical','viewport','lang','jsonld','related_links','link_fix'],true)||!str_starts_with($path,'/')||str_contains($path,'?')||str_contains($path,chr(92))||preg_match('/[\x00-\x20]/',$path))continue;$clean=self::sanitize($field,(string)$r['expected_value'],$siteHost);if($clean!==null)$pageRules[$path][$field]=in_array($field,['related_links','link_fix'],true)?json_decode($clean,true):$clean;}
            if($pageRules)$rules['page_rules']=$pageRules;
            // وصف تلقائي للصور اللي من غير alt (من اسم الصورة أو عنوان الصفحة) - للمواقع اللي مفعّلة خدمة SEO
            $sx=$db->query("SELECT policy_json FROM seo_expert_sites WHERE website_id=? AND status IN ('active','draft') LIMIT 1",[$websiteId]);
            if($sx){$pol=json_decode((string)$sx[0]['policy_json'],true)?:[];if(($pol['auto_alt']??true)!==false)$rules['auto_alt']=1;}
            // مقالات معتمدة بتتنشر من خلال ملف الربط في مواقع PHP (/guides/{slug}) - نص بس
            $articles=[];
            $knownPages=null;
            foreach($db->query("SELECT id,title,expected_value,published_url,updated_at,before_json FROM seo_expert_tasks WHERE website_id=? AND kind='content' AND is_active=1 AND status IN ('active','verified','needs_client') AND (published_url LIKE '%/guides/%' OR published_url LIKE '%tf_article=%') ORDER BY id DESC LIMIT 50",[$websiteId]) as $a){
                // الرابط ممكن يكون /guides/{slug} أو البديل ?tf_article={slug}
                parse_str((string)parse_url((string)$a['published_url'],PHP_URL_QUERY),$q);
                $slug=isset($q['tf_article'])&&is_string($q['tf_article'])?$q['tf_article']:basename((string)parse_url((string)$a['published_url'],PHP_URL_PATH));
                if(!preg_match('/^[a-z0-9][a-z0-9-]{0,120}$/D',$slug)||isset($articles[$slug]))continue;
                $title=self::line((string)$a['title']);$body=trim(str_replace("\r",'',strip_tags((string)$a['expected_value'])));
                if($title===''||$body==='')continue;
                $articleSources[$slug]='task:'.(int)$a['id'];
                $before=json_decode((string)$a['before_json'],true)?:[];$publishedDate=(string)($before['article_published_at']??substr((string)$a['updated_at'],0,10));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$publishedDate))$publishedDate=substr((string)$a['updated_at'],0,10);
                $articles[$slug]=['url'=>(string)$a['published_url'],'title'=>mb_substr($title,0,150),'description'=>mb_substr(self::line(preg_replace('/\*\*/','',$body)),0,155),'body'=>mb_substr($body,0,12000),'published_at'=>$publishedDate];
                $before=json_decode((string)$a['before_json'],true)?:[];
                $links=$before['article_links']??[];
                if(!$links){
                    if($knownPages===null){
                        $snapshots=$db->query('SELECT snapshot_json FROM seo_expert_runs WHERE website_id=? AND snapshot_json IS NOT NULL ORDER BY id DESC LIMIT 1',[$websiteId]);
                        $knownPages=array_values(array_filter(json_decode($snapshots[0]['snapshot_json']??'{}',true)['pages']??[],static fn($p)=>empty($p['error'])&&(!isset($p['ok'])||$p['ok'])));
                    }
                    $pillar=ContentQualityService::cluster($title,$knownPages,'https://'.$siteHost);
                    if($pillar)$links=[$pillar];
                }
                $cleanLinks=$links?self::sanitize('related_links',json_encode($links),$siteHost):null;
                if($cleanLinks!==null)$articles[$slug]['related_links']=json_decode($cleanLinks,true);
                if($cleanLinks!==null && ContentQualityService::publicationPath((string)$a['published_url'],$siteHost)!==null){
                    foreach(json_decode($cleanLinks,true) as $link){
                        $pillarPath=ContentQualityService::pagePath($link['url'],$siteHost);if(!$pillarPath)continue;
                        $backLinks=$rules['page_rules'][$pillarPath]['related_links']??[];
                        if(!in_array($a['published_url'],array_column($backLinks,'url'),true)&&count($backLinks)<4)$backLinks[]=['url'=>$a['published_url'],'title'=>mb_substr($title,0,90)];
                        $rules['page_rules'][$pillarPath]['related_links']=$backLinks;
                    }
                }
                $img=$before['article_image']??null;
                if(is_array($img)&&preg_match('#^https://[^\s"<>]{8,490}$#',(string)($img['src']??''))){$articles[$slug]['image']=(string)$img['src'];$articles[$slug]['image_alt']=mb_substr(self::line((string)($img['alt']??'')),0,150);}
            }
            if($articles)$rules['articles']=$articles;
        }
        require_once dirname(__DIR__).'/Seo/ContentQualityService.php';
        if ($db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_content_quality'")) {
            $qualityRows=$db->query('SELECT q.* FROM seo_content_quality q JOIN websites w ON w.id=q.website_id AND w.user_id=q.user_id WHERE q.website_id=? AND q.published_at IS NOT NULL ORDER BY q.id',[$websiteId]);
            $currentQuality=[];foreach($qualityRows as &$row){
                $path=(string)parse_url((string)($row['page_url']??''),PHP_URL_PATH);
                $slug=basename($path);
                if(str_starts_with($path,'/guides/') && isset($rules['articles'][$slug]['url']))$row['page_url']=$rules['articles'][$slug]['url'];
                parse_str((string)parse_url((string)($row['page_url']??''),PHP_URL_QUERY),$articleQuery);
                $articleSlug=isset($articleQuery['tf_article'])&&is_string($articleQuery['tf_article'])?$articleQuery['tf_article']:$slug;
                if(isset($articleSources[$articleSlug])&&$articleSources[$articleSlug]!==($row['source_key']??''))continue;
                $currentQuality[]=$row;
                if(isset($rules['articles'][$articleSlug])&&($articleSources[$articleSlug]??null)===($row['source_key']??'')){
                    $reviewedDescription=self::line((string)($row['description']??''));
                    if($reviewedDescription!==''&&mb_strlen($reviewedDescription)<=500)$rules['articles'][$articleSlug]['description']=$reviewedDescription;
                }
            }
            unset($row);
            $pageRules=ContentQualityService::rules($currentQuality,$siteHost,$rules['page_rules']??[]);
            foreach ($pageRules as $path=>&$fields) foreach ($fields as $field=>&$value) {
                $clean=self::sanitize($field,is_array($value)?json_encode($value):$value,$siteHost);
                if ($clean!==null) $value=in_array($field,['related_links','link_fix'],true)?json_decode($clean,true):$clean;
            }
            unset($fields,$value);
            if ($pageRules) $rules['page_rules']=$pageRules;
        }
        $hasKey=$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='websites' AND column_name='indexnow_key'");
        $keyRows=$hasKey?$db->query('SELECT indexnow_key,indexnow_enabled FROM websites WHERE id=?',[$websiteId]):[];
        $key=(string)($keyRows[0]['indexnow_key']??'');
        if(!empty($keyRows[0]['indexnow_enabled'])&&preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key))$rules['indexnow_key']=$key;
        ksort($rules);
        return $rules;
    }

    /** دوال الجسم المشتركة (WordPress + PHP): وصف الصور + بلوك الروابط الداخلية */
    public static function bodyHelpers(): string
    {
        return <<<'PHPH'
if ( ! function_exists( 'tf_edge_alt_from' ) ) {
function tf_edge_alt_from( $src, $fallback ) {
    $name = strtolower( (string) pathinfo( (string) parse_url( (string) $src, PHP_URL_PATH ), PATHINFO_FILENAME ) );
    $name = preg_replace( '/-?\d{2,4}x\d{2,4}$|-scaled$|-e\d{10,}$/', '', $name );
    $words = trim( preg_replace( '/[^a-z\x{0600}-\x{06FF}]+/u', ' ', str_replace( array( '-', '_', '+' ), ' ', urldecode( $name ) ) ) );
    $generic = preg_match( '/^(img|image|dsc|dscn|pxl|photo|pic|screenshot|whatsapp|wa|unnamed|untitled|download|copy|banner|slide|bg|background|hero|placeholder|logo)(\s|$)/', $words ) || str_word_count( $words ) < 2;
    $alt = $generic ? trim( (string) $fallback ) : ucfirst( $words );
    return mb_substr( $alt, 0, 120 );
}
}
if ( ! function_exists( 'tf_edge_fill_alt' ) ) {
function tf_edge_fill_alt( $html, $fallback ) {
    return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( $fallback ) {
        $tag = $m[0];
        if ( preg_match( '/\balt\s*=\s*(["\'])\s*\S[^"\']*\1/i', $tag ) ) { return $tag; }
        if ( preg_match( '/\brole\s*=\s*(["\'])presentation\1|aria-hidden\s*=\s*(["\'])true\2/i', $tag ) ) { return $tag; }
        $src = preg_match( '/\bsrc\s*=\s*(["\'])([^"\']+)\1/i', $tag, $s ) ? $s[2] : '';
        $alt = tf_edge_alt_from( $src, $fallback );
        if ( $alt === '' ) { return $tag; }
        $tag = preg_replace( '/\salt\s*=\s*(["\'])\s*\1/i', '', $tag );
        return preg_replace( '/^<img\b/i', '<img alt="' . htmlspecialchars( $alt, ENT_QUOTES, 'UTF-8' ) . '"', $tag );
    }, (string) $html );
}
}
if ( ! function_exists( 'tf_edge_fix_links' ) ) {
function tf_edge_fix_links( $html, $fixes ) {
    $key = function ( $u ) {
        $p = parse_url( html_entity_decode( trim( (string) $u ), ENT_QUOTES, 'UTF-8' ) );
        if ( ! is_array( $p ) ) { return ''; }
        $host = isset( $p['host'] ) ? preg_replace( '/^www\./', '', strtolower( $p['host'] ) ) : '';
        return $host . '|' . rtrim( isset( $p['path'] ) ? $p['path'] : '', '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    };
    $map = array();
    foreach ( (array) $fixes as $f ) {
        if ( ! is_array( $f ) || empty( $f['from'] ) ) { continue; }
        $k = $key( $f['from'] ); $map[ $k ] = isset( $f['to'] ) ? (string) $f['to'] : '';
        $map[ '|' . substr( $k, strpos( $k, '|' ) + 1 ) ] = $map[ $k ];
    }
    if ( ! $map ) { return $html; }
    return preg_replace_callback( '#<a\b([^>]*?)\bhref\s*=\s*(["\'])([^"\']*)\2([^>]*)>(.*?)</a>#is', function ( $m ) use ( $map, $key ) {
        $k = $key( $m[3] );
        if ( ! isset( $map[ $k ] ) ) { return $m[0]; }
        if ( $map[ $k ] === '' ) { return $m[5]; }
        return '<a' . $m[1] . 'href=' . $m[2] . htmlspecialchars( $map[ $k ], ENT_QUOTES, 'UTF-8' ) . $m[2] . $m[4] . '>' . $m[5] . '</a>';
    }, (string) $html );
}
}
if ( ! function_exists( 'tf_edge_related_html' ) ) {
function tf_edge_related_html( $links ) {
    $h = '';
    foreach ( array_slice( (array) $links, 0, 4 ) as $l ) {
        if ( ! is_array( $l ) || empty( $l['url'] ) || empty( $l['title'] ) ) { continue; }
        $h .= '<li><a href="' . htmlspecialchars( (string) $l['url'], ENT_QUOTES, 'UTF-8' ) . '">' . htmlspecialchars( (string) $l['title'], ENT_QUOTES, 'UTF-8' ) . '</a></li>';
    }
    if ( $h === '' ) { return ''; }
    $ar = isset( $GLOBALS['tf_edge_lang'] ) && strpos( (string) $GLOBALS['tf_edge_lang'], 'ar' ) === 0;
    return '<nav class="tf-related" data-tf-related="1" style="margin:28px 0;padding:16px 18px;border:1px solid rgba(0,0,0,.1);border-radius:12px"><strong style="display:block;margin-bottom:8px">' . ( $ar ? 'رحلات ممكن تعجبك' : 'You may also like' ) . '</strong><ul style="margin:0;padding-inline-start:18px;line-height:1.9">' . $h . '</ul></nav>';
}
}

PHPH;
    }

    /** WordPress: وصف الصور + روابط مشابهة في آخر محتوى الصفحة */
    private static function wpBodyHooks(): string
    {
        return <<<'PHPH'

add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
    $r = tf_edge_rules();
    if ( empty( $r['auto_alt'] ) || ( isset( $attr['alt'] ) && trim( (string) $attr['alt'] ) !== '' ) ) { return $attr; }
    $alt = tf_edge_alt_from( isset( $attr['src'] ) ? $attr['src'] : '', get_the_title( $attachment ) ?: get_the_title() );
    if ( $alt !== '' ) { $attr['alt'] = $alt; }
    return $attr;
}, 99, 2 );
add_filter( 'the_content', function ( $content ) {
    if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) { return $content; }
    $r = tf_edge_rules();
    if ( ! empty( $r['auto_alt'] ) ) { $content = tf_edge_fill_alt( $content, get_the_title() ); }
    $page = tf_edge_page_rules();
    if ( ! empty( $page['link_fix'] ) && is_array( $page['link_fix'] ) ) { $content = tf_edge_fix_links( $content, $page['link_fix'] ); }
    if ( ! empty( $page['related_links'] ) && is_array( $page['related_links'] ) && strpos( $content, 'data-tf-related' ) === false ) {
        $GLOBALS['tf_edge_lang'] = get_bloginfo( 'language' );
        $content .= tf_edge_related_html( $page['related_links'] );
    }
    return $content;
}, 99 );
PHPH;
    }

    public static function version(array $rules): string
    {
        return substr(md5(json_encode($rules, JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    // ------------------------------------------------------------------ كود WordPress

    /**
     * كود PHP جاهز يتلصق في Code Snippets / WPCode (من غير <?php).
     * بيسحب القواعد كل 10 دقايق (cache) بمهلة 3 ثواني، وبيفشل بصمت (لو Tourfecto وقع الموقع بيشتغل عادي).
     */
    public static function snippet(int $websiteId, string $endpoint): string
    {
        $token = self::token($websiteId);
        $endpoint = rtrim($endpoint, '/') . '/index.php?__tf_api=%2Fapi%2Fedge%2Frules';
        return self::bodyHelpers() . <<<PHP
/* Tourfecto Edge v4 - Page-scoped approved SEO updates. Data only; no remote code execution. */
if ( defined( 'TF_EDGE_RUNTIME_V4' ) ) { return; }
if ( function_exists( 'tf_edge_rules' ) ) { add_action( 'admin_notices', function () { if(current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>Tourfecto: disable the old Tourfecto snippet before using connector v4.</p></div>'; } ); return; }
define( 'TF_EDGE_RUNTIME_V4', true );
if ( ! defined( 'TF_EDGE_RUNTIME_V3' ) ) { define( 'TF_EDGE_RUNTIME_V3', true ); }
if ( ! function_exists( 'tf_edge_rules' ) ) {
    function tf_edge_rules() {
        \$c = get_transient( 'tf_edge_rules_v4' );
        if ( is_array( \$c ) ) { return \$c; }
        \$last = get_option( 'tf_edge_last_rules_v4', array() );
        if ( get_transient( 'tf_edge_retry_v4' ) ) { return is_array(\$last) ? \$last : array(); }
        if ( is_array(\$last) && count(\$last)>0 && !is_admin() && !(defined('DOING_CRON') && DOING_CRON) && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ) {
            if(!wp_next_scheduled('tf_edge_refresh_v4'))wp_schedule_single_event(time()+1,'tf_edge_refresh_v4');
            set_transient('tf_edge_rules_v4',\$last,30);return \$last;
        }
        \$rules = array(); \$valid = false;
        \$r = wp_remote_get( '{$endpoint}&token={$token}&cv=4&site=' . rawurlencode( home_url() ), array( 'timeout' => 2, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
        if ( ! is_wp_error( \$r ) && 200 === wp_remote_retrieve_response_code( \$r ) ) {
            \$d = json_decode( wp_remote_retrieve_body( \$r ), true );
            if ( is_array( \$d ) && isset( \$d['data']['rules'] ) && is_array( \$d['data']['rules'] ) ) {
                \$valid = true; delete_option( 'tf_edge_last_error' ); delete_transient( 'tf_edge_retry_v4' );
                \$rules = \$d['data']['rules'];
                \$v = isset( \$d['data']['version'] ) ? (string) \$d['data']['version'] : '';
                if ( \$v !== get_option( 'tf_edge_version' ) ) {
                    update_option( 'tf_edge_version', \$v, false );
                    do_action( 'litespeed_purge_all' );
                    if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
                }
            }
        }
        if ( ! \$valid ) { set_transient( 'tf_edge_retry_v4', true, 15 ); update_option( 'tf_edge_last_error', is_wp_error( \$r ) ? 'Network request failed. Check outbound HTTPS access.' : 'Connection failed. HTTP ' . wp_remote_retrieve_response_code( \$r ) . '. Check the site connection in Tourfecto.', false ); }
        if ( \$valid ) { set_transient( 'tf_edge_rules_v4', \$rules, 120 ); update_option('tf_edge_last_rules_v4',\$rules,false); }
        return \$valid ? \$rules : (is_array(\$last) ? \$last : array());
    }
    /* Homepage SEO values apply only to the audited homepage, never every page. */
    function tf_edge_is_home() {
        return function_exists( 'is_front_page' ) && is_front_page() && ! is_paged();
    }
    function tf_edge_current_path() {
        \$path = isset( \$_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( \$_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/';
        return rtrim( (string) \$path, '/' ) ?: '/';
    }
    function tf_edge_page_rules() {
        \$all = tf_edge_rules(); \$page = array();
        if ( tf_edge_is_home() ) { foreach (array('title','description','canonical','robots','viewport','lang','og','jsonld') as \$key) { if(isset(\$all[\$key]))\$page[\$key]=\$all[\$key]; } }
        \$path = tf_edge_current_path();
        if ( isset(\$all['page_rules'][\$path]) && is_array(\$all['page_rules'][\$path]) ) {
            foreach(array('title','description','canonical','viewport','lang','jsonld','related_links','link_fix') as \$key)if(isset(\$all['page_rules'][\$path][\$key]))\$page[\$key]=\$all['page_rules'][\$path][\$key];
        }
        return \$page;
    }
    function tf_edge_has_page_rules() { return tf_edge_is_home() || count(tf_edge_page_rules())>0; }
    function tf_edge_seo_plugin() {
        return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
    }
}
add_action('tf_edge_refresh_v4',function(){delete_transient('tf_edge_rules_v4');tf_edge_rules();});
add_action( 'admin_init', function () { tf_edge_rules(); } );
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    \$error = get_option( 'tf_edge_last_error', '' );
    if ( \$error ) { echo '<div class="notice notice-warning"><p>Tourfecto: ' . esc_html( \$error ) . '</p></div>'; }
} );
add_action( 'send_headers', function () {
    \$r = tf_edge_rules();
    if ( ! empty( \$r['hsts'] ) && preg_match( '/^max-age=\\d{1,10}(; ?includeSubDomains)?(; ?preload)?\$/', \$r['hsts'] ) && is_ssl() ) {
        header( 'Strict-Transport-Security: ' . \$r['hsts'] );
    }
} );
add_filter( 'pre_get_document_title', function ( \$t ) {
    \$r = tf_edge_page_rules();
    return ( tf_edge_has_page_rules() && ! empty( \$r['title'] ) ) ? \$r['title'] : \$t;
}, 99 );
add_filter( 'language_attributes', function ( \$out ) {
    \$r = tf_edge_page_rules();
    if ( ! empty( \$r['lang'] ) && preg_match( '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\$/', \$r['lang'] ) ) {
        \$out = preg_replace( '/\\blang=["\\x27][^"\\x27]*["\\x27]/i', '', \$out );
        \$out .= ' lang="' . esc_attr( \$r['lang'] ) . '"';
    }
    return \$out;
} );

/* Replace only explicitly approved homepage values and retain all other head markup. */
if ( ! function_exists( 'tf_edge_normalize_head' ) ) {
function tf_edge_normalize_head( \$head, \$rules ) {
    if ( ! tf_edge_has_page_rules() || empty( \$rules ) ) { return \$head; }

    \$protected = array();
    if ( ! empty( \$rules['jsonld'] ) ) { \$head = preg_replace( '/<script\\b[^>]*\\bid=["\\x27]tourfecto-schema["\\x27][^>]*>.*?<\\/script>/is', '', \$head ); }
    \$head = preg_replace_callback( '/<(script|style)\\b[^>]*>.*?<\\/\\1>/is', function ( \$match ) use ( &\$protected ) {
        \$key = '<!--tf-protected-' . count( \$protected ) . '-->'; \$protected[\$key] = \$match[0]; return \$key;
    }, \$head );
    \$extra = '';
    if ( ! empty( \$rules['title'] ) ) {
        \$head = preg_replace( '/<title\\b[^>]*>.*?<\\/title>/is', '', \$head );
        \$extra .= '<title>' . esc_html( \$rules['title'] ) . '</title>' . "\\n";
    }
    foreach ( array( 'description', 'robots', 'viewport' ) as \$field ) {
        if ( empty( \$rules[\$field] ) ) { continue; }
        \$head = preg_replace_callback( '/<meta\\b[^>]*>/i', function ( \$match ) use ( \$field ) {
            return preg_match( '/\\bname\\s*=\\s*(["\\x27])' . preg_quote( \$field, '/' ) . '\\1/i', \$match[0] ) ? '' : \$match[0];
        }, \$head );
        \$extra .= '<meta name="' . esc_attr( \$field ) . '" content="' . esc_attr( \$rules[\$field] ) . '">' . "\\n";
    }
    if ( ! empty( \$rules['canonical'] ) ) {
        \$head = preg_replace_callback( '/<link\\b[^>]*>/i', function ( \$match ) {
            return preg_match( '/\\brel\\s*=\\s*(["\\x27])canonical\\1/i', \$match[0] ) ? '' : \$match[0];
        }, \$head );
        \$extra .= '<link rel="canonical" href="' . esc_url( \$rules['canonical'] ) . '">' . "\\n";
    }
    if ( ! empty( \$rules['og'] ) && is_array( \$rules['og'] ) ) {
        foreach ( \$rules['og'] as \$key => \$value ) {
            \$head = preg_replace_callback( '/<meta\\b[^>]*>/i', function ( \$match ) use ( \$key ) {
                return preg_match( '/\\b(?:name|property)\\s*=\\s*(["\\x27])' . preg_quote( \$key, '/' ) . '\\1/i', \$match[0] ) ? '' : \$match[0];
            }, \$head );
            \$extra .= '<meta ' . ( strpos( \$key, 'twitter:' ) === 0 ? 'name' : 'property' ) . '="' . esc_attr( \$key ) . '" content="' . esc_attr( \$value ) . '">' . "\\n";
        }
    }
    if ( ! empty( \$rules['jsonld'] ) ) {
        // Keep other schemas. Replace only this connector's own schema block.
        \$head = preg_replace( '/<script\\b[^>]*\\bid=["\\x27]tourfecto-schema["\\x27][^>]*>.*?<\\/script>/is', '', \$head );
        \$extra .= '<script id="tourfecto-schema" type="application/ld+json">' . \$rules['jsonld'] . '</script>' . "\\n";
    }
    return strtr( \$head, \$protected ) . \$extra;
}
}
add_action( 'wp_head', function () { echo '<meta name="tourfecto-connector" content="4"><meta name="tourfecto-connector-features" content="body links">'; }, -100000 );
add_action( 'wp_head', function () {
    if ( tf_edge_has_page_rules() ) {
        \$rules = tf_edge_page_rules();
        ob_start( function ( \$head ) use ( \$rules ) { return tf_edge_normalize_head( \$head, \$rules ); } );
        \$GLOBALS['tf_edge_head_level'] = ob_get_level();
    }
}, -99999 );
add_action( 'wp_head', function () {
    if ( isset( \$GLOBALS['tf_edge_head_level'] ) && ob_get_level() === \$GLOBALS['tf_edge_head_level'] ) { ob_end_flush(); unset( \$GLOBALS['tf_edge_head_level'] ); }
}, PHP_INT_MAX );
add_action( 'init', function () {
    \$path = isset( \$_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( \$_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    if ( '/llms.txt' !== \$path ) { return; }
    \$r = tf_edge_rules();
    if ( ! empty( \$r['llms_txt'] ) ) {
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo \$r['llms_txt'];
        exit;
    }
} );
PHP
        . self::wpBodyHooks();
    }

    /**
     * ملف ربط لمواقع PHP المبرمجة خصيصًا (مش WordPress): نفس عمل إضافة
     * WordPress - بيسحب نفس القواعد المعتمدة (بيانات بس، مش كود) ويطبّقها
     * على <head> كل صفحة، وبيطلع نفس علامة tourfecto-connector=4 عشان
     * خبير SEO يعرف إن الربط شغّال. بيتحط مرة واحدة في ملف مشترك بين
     * الصفحات (زي inc.php / header.php) أو auto_prepend_file.
     * الكود كله إنجليزي عمدًا: بيتحمّل كملف ومش لازم يعدّي على الترجمة.
     */
    public static function phpConnector(int $websiteId, string $endpoint): string
    {
        $token = self::token($websiteId);
        $endpoint = rtrim($endpoint, '/') . '/index.php?__tf_api=%2Fapi%2Fedge%2Frules';
        $code = <<<'PHPCODE'
<?php
/**
 * Tourfecto Connect for PHP websites (protocol v4)
 *
 * Applies SEO updates you approved in Tourfecto (title, description,
 * canonical, robots, viewport, language, Open Graph, JSON-LD, HSTS,
 * llms.txt). The server sends data only, never code. If Tourfecto is
 * unreachable your site keeps working exactly as before.
 *
 * Install (choose one):
 *   A) First line of a file every page loads (e.g. inc.php, header.php):
 *        require_once __DIR__ . '/tourfecto-connect.php';
 *   B) In .user.ini or php.ini:
 *        auto_prepend_file = /full/path/to/tourfecto-connect.php
 */
if (defined('TF_EDGE_RUNTIME_V4') || PHP_SAPI === 'cli') {
    return;
}
define('TF_EDGE_RUNTIME_V4', true);
TF_GUIDE_CONTENT_CLASS
define('TF_EDGE_ENDPOINT', '__ENDPOINT__');
define('TF_EDGE_TOKEN', '__TOKEN__');

function tf_edge_cache_file()
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/tourfecto-edge-' . md5(TF_EDGE_TOKEN) . '.json';
}

function tf_edge_site()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    return ($https ? 'https' : 'http') . '://' . $host;
}

function tf_edge_fetch($url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'follow_location' => 0, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $ok = isset($http_response_header[0]) && strpos($http_response_header[0], ' 200') !== false;
    return ($ok && is_string($body)) ? $body : null;
}

/** Approved rules, cached 2 minutes; on failure keep the last good rules and retry after 15 seconds. */
function tf_edge_rules()
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }
    $file = tf_edge_cache_file();
    $cache = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $cache = is_array($cache) ? $cache : ['t' => 0, 'f' => 0, 'rules' => []];
    $last = is_array($cache['rules'] ?? null) ? $cache['rules'] : [];
    $now = time();
    if ($now - (int) ($cache['t'] ?? 0) < 120 || $now - (int) ($cache['f'] ?? 0) < 15) {
        return $rules = $last;
    }
    $body = tf_edge_fetch(TF_EDGE_ENDPOINT . '&token=' . rawurlencode(TF_EDGE_TOKEN) . '&cv=4&site=' . rawurlencode(tf_edge_site()));
    $data = $body !== null ? json_decode($body, true) : null;
    if (is_array($data) && isset($data['data']['rules']) && is_array($data['data']['rules'])) {
        $rules = $data['data']['rules'];
        @file_put_contents($file, json_encode(['t' => $now, 'f' => 0, 'rules' => $rules]), LOCK_EX);
        return $rules;
    }
    $cache['f'] = $now;
    @file_put_contents($file, json_encode($cache), LOCK_EX);
    return $rules = $last;
}

function tf_edge_path()
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}

/** Homepage values apply only to the homepage; page_rules apply to their exact path. */
function tf_edge_page_rules()
{
    $all = tf_edge_rules();
    $path = tf_edge_path();
    $slug=tf_edge_article_slug();
    if($slug!==null){
        $key=isset($_GET['tf_article'])?'/?tf_article='.$slug:'/guides/'.$slug;
        return $all['page_rules'][$key]??$all['page_rules']['/guides/'.$slug]??[];
    }
    if(($_GET['tf_guides']??null)==='1')return $all['page_rules']['/guides']??[];
    $page = [];
    if ($path === '/' || $path === '/index.php') {
        foreach (['title', 'description', 'canonical', 'robots', 'viewport', 'lang', 'og', 'jsonld'] as $k) {
            if (isset($all[$k])) {
                $page[$k] = $all[$k];
            }
        }
    }
    if (isset($all['page_rules'][$path]) && is_array($all['page_rules'][$path])) {
        foreach (['title', 'description', 'canonical', 'viewport', 'lang', 'jsonld', 'related_links', 'link_fix'] as $k) {
            if (isset($all['page_rules'][$path][$k])) {
                $page[$k] = $all['page_rules'][$path][$k];
            }
        }
    }
    return $page;
}

function tf_edge_e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function tf_edge_apply_head($head, array $r)
{
    $protected = [];
    if (!empty($r['jsonld'])) {
        $head = preg_replace('/<script\b[^>]*\bid=["\']tourfecto-schema["\'][^>]*>.*?<\/script>/is', '', $head);
    }
    $head = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1>/is', function ($m) use (&$protected) {
        $key = '<!--tf-protected-' . count($protected) . '-->';
        $protected[$key] = $m[0];
        return $key;
    }, $head);
    $extra = '';
    if (!empty($r['title']) && is_string($r['title'])) {
        $head = preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $head);
        $extra .= '<title>' . tf_edge_e($r['title']) . "</title>\n";
    }
    foreach (['description', 'robots', 'viewport'] as $field) {
        if (empty($r[$field]) || !is_string($r[$field])) {
            continue;
        }
        $head = preg_replace_callback('/<meta\b[^>]*>/i', function ($m) use ($field) {
            return preg_match('/\bname\s*=\s*(["\'])' . preg_quote($field, '/') . '\1/i', $m[0]) ? '' : $m[0];
        }, $head);
        $extra .= '<meta name="' . $field . '" content="' . tf_edge_e($r[$field]) . "\">\n";
    }
    if (!empty($r['canonical']) && is_string($r['canonical']) && preg_match('#^https?://#i', $r['canonical'])) {
        $head = preg_replace_callback('/<link\b[^>]*>/i', function ($m) {
            return preg_match('/\brel\s*=\s*(["\'])canonical\1/i', $m[0]) ? '' : $m[0];
        }, $head);
        $extra .= '<link rel="canonical" href="' . tf_edge_e($r['canonical']) . "\">\n";
    }
    if (!empty($r['og']) && is_array($r['og'])) {
        foreach ($r['og'] as $key => $value) {
            if (!preg_match('/^(og|twitter|article):[a-z_:]{1,40}$/', (string) $key) || !is_scalar($value)) {
                continue;
            }
            $head = preg_replace_callback('/<meta\b[^>]*>/i', function ($m) use ($key) {
                return preg_match('/\b(?:name|property)\s*=\s*(["\'])' . preg_quote((string) $key, '/') . '\1/i', $m[0]) ? '' : $m[0];
            }, $head);
            $attr = strpos((string) $key, 'twitter:') === 0 ? 'name' : 'property';
            $extra .= '<meta ' . $attr . '="' . tf_edge_e($key) . '" content="' . tf_edge_e($value) . "\">\n";
        }
    }
    if (!empty($r['jsonld']) && is_string($r['jsonld']) && is_array(json_decode($r['jsonld'], true))) {
        $extra .= '<script id="tourfecto-schema" type="application/ld+json">' . str_replace('</', '<\/', $r['jsonld']) . "</script>\n";
    }
    return strtr($head, $protected) . $extra;
}

TF_EDGE_BODY_HELPERS

function tf_edge_buffer($html, $override = null)
{
    if (isset($GLOBALS['tf_edge_guide_page']) && is_string($html)) {
        $phase=is_int($override)?$override:PHP_OUTPUT_HANDLER_FINAL;
        $buffer=$GLOBALS['tf_edge_guide_buffer']??'';
        if(strlen($buffer)+strlen($html)<=2000000)$buffer.=$html;else $GLOBALS['tf_edge_guide_overflow']=true;
        $GLOBALS['tf_edge_guide_buffer']=$buffer;
        if(($phase & PHP_OUTPUT_HANDLER_FINAL)===0)return '';
        $html=$buffer;unset($GLOBALS['tf_edge_guide_buffer']);
        if(!headers_sent()){header_remove('Content-Length');header_remove('ETag');header_remove('Last-Modified');header_remove('Location');header('Cache-Control: no-store');}
        $guide=$GLOBALS['tf_edge_guide_page'];
        unset($GLOBALS['tf_edge_guide_page']);
        $themed=empty($GLOBALS['tf_edge_guide_overflow'])?GuideContent::theme($html,$guide['main']):null;
        unset($GLOBALS['tf_edge_guide_overflow']);
        if ($themed!==null && http_response_code()<300) {
            $html=$themed;$override=$guide['fields'];
            if(!headers_sent())header('Content-Type: text/html; charset=utf-8');
        } else {
            // Keep approved content available when the site's route cannot supply a theme.
            http_response_code(200);
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            $html=tf_edge_fallback_page($guide['title'],$guide['description'],$guide['canonical'],$guide['main'],$guide['jsonld'],$guide['image']);
            $override=$guide['fields'];
        }
    }
    if (is_string($html) && preg_match('~/(?:[^/]*sitemap[^/]*)\.xml$~i',tf_edge_path()) && !empty(tf_edge_rules()['articles'])) {
        $loc=tf_edge_site().'/?tf_guides_sitemap=1';
        if (preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<sitemapindex\b/i',$html) && stripos($html,'</sitemapindex>')!==false) {
            if (strpos($html,$loc)===false) $html=str_ireplace('</sitemapindex>','<sitemap><loc>'.htmlspecialchars($loc,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></sitemap></sitemapindex>',$html);
            return $html;
        }
        if (preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<urlset\b/i',$html) && stripos($html,'</urlset>')!==false) {
            $extra='';
            foreach (tf_edge_rules()['articles'] as $slug=>$article) {
                if (!preg_match('/^[a-z0-9][a-z0-9-]{0,120}$/',$slug)) continue;
                $url=htmlspecialchars(tf_edge_article_url($slug,$article),ENT_XML1|ENT_QUOTES,'UTF-8');
                if (strpos($html,'<loc>'.$url.'</loc>')===false) $extra.='<url><loc>'.$url.'</loc></url>';
            }
            return str_ireplace('</urlset>',$extra.'</urlset>',$html);
        }
    }
    if (!is_string($html) || stripos($html, '<head') === false) {
        return $html;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    try {
        $r = is_array($override) ? $override : tf_edge_page_rules();
        if (!empty($r['lang']) && is_string($r['lang']) && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $r['lang'])) {
            $html = preg_replace_callback('/<html\b[^>]*>/i', function ($m) use ($r) {
                $tag = preg_replace('/\slang\s*=\s*(["\'])[^"\']*\1/i', '', $m[0]);
                return substr($tag, 0, -1) . ' lang="' . $r['lang'] . '">';
            }, $html, 1);
        }
        // Hide scripts/styles first so "</head>" inside JavaScript is never mistaken for the real one.
        $hidden = [];
        $masked = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1>/is', function ($m) use (&$hidden) {
            $key = '<!--tf-page-' . count($hidden) . '-->';
            $hidden[$key] = $m[0];
            return $key;
        }, $html);
        if (!is_string($masked)) {
            return $html;
        }
        if (!preg_match('/<head\b[^>]*>/i', $masked, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $open = $m[0][1] + strlen($m[0][0]);
        $close = stripos($masked, '</head>', $open);
        if ($close === false) {
            return $html;
        }
        $head = strtr(substr($masked, $open, $close - $open), $hidden);
        $head=preg_replace('/<meta\b[^>]*\bname=["\']tourfecto-connector(?:-features)?["\'][^>]*>/i','',$head);
        $marker = "\n<meta name=\"tourfecto-connector\" content=\"4\"><meta name=\"tourfecto-connector-features\" content=\"body links\">\n";
        if(!empty(tf_edge_rules()['articles']) && stripos($head,'rel="sitemap"')===false)$marker.='<link rel="sitemap" type="application/xml" href="'.tf_edge_e(tf_edge_site().'/?tf_guides_sitemap=1').'">';
        $newHead = $marker . ($r ? tf_edge_apply_head($head, $r) : $head);
        $rest = substr($masked, $close);
        // الجسم: وصف الصور الناقص + روابط رحلات مشابهة (من غير لمس الـ scripts)
        $all = tf_edge_rules();
        if (!empty($all['auto_alt'])) {
            $rest = tf_edge_fill_alt($rest, isset($r['title']) ? (string) $r['title'] : '');
        }
        if (!empty($r['link_fix']) && is_array($r['link_fix'])) {
            $rest = tf_edge_fix_links($rest, $r['link_fix']);
        }
        if (!empty($r['related_links']) && is_array($r['related_links']) && strpos($rest,'data-tf-related')===false) {
            $block = tf_edge_related_html($r['related_links']);
            if (preg_match('#</main>#i', $rest)) {
                $rest = preg_replace('#</main>#i', $block . '</main>', $rest, 1);
            } elseif (preg_match('#<footer\b#i', $rest)) {
                $rest = preg_replace('#<footer\b#i', $block . '<footer', $rest, 1);
            } else {
                $rest = preg_replace('#</body>#i', $block . '</body>', $rest, 1);
            }
        }
        return strtr(substr($masked, 0, $open), $hidden) . $newHead . strtr($rest, $hidden);
    } catch (\Throwable $e) {
        return $html;
    }
}

/** Approved article slug from /guides/{slug} or the fallback ?tf_article={slug}. */
function tf_edge_article_url($slug,$article)
{
    $url=(string)($article['url']??'');$p=parse_url($url);$site=parse_url(tf_edge_site());
    if($p && ($p['scheme']??'')===($site['scheme']??'') && ($p['host']??'')===($site['host']??'') && ($p['port']??null)===($site['port']??null) && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])){
        if(($p['path']??'')==='/guides/'.$slug && !isset($p['query']))return $url;
        if(in_array($p['path']??'/',['/','/index.php'],true) && ($p['query']??'')==='tf_article='.$slug)return $url;
    }
    return tf_edge_site().'/guides/'.$slug;
}

function tf_edge_article_slug()
{
    if (preg_match('#^/guides/([a-z0-9][a-z0-9\-]{0,120})$#', tf_edge_path(), $m)) {
        return $m[1];
    }
    $q = $_GET['tf_article'] ?? null;
    return (is_string($q) && preg_match('/^[a-z0-9][a-z0-9\-]{0,120}$/', $q)) ? $q : null;
}

/** Plain text to safe HTML: paragraphs, "- " lists, **bold**, and bold-only lines as headings. */
function tf_edge_article_html($text)
{
    return GuideContent::html((string)$text);
}

function tf_edge_page($title, $description, $canonical, $main, $jsonld = '', $image = '', $useTheme = true)
{
    $host = preg_replace('/^www\./', '', parse_url(tf_edge_site(), PHP_URL_HOST) ?: '');
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
    }
    // Optional local theme bridge. These paths are configured by the site owner,
    // never supplied through rules or remote article data.
    if (defined('TF_EDGE_ARTICLE_HEADER') && defined('TF_EDGE_ARTICLE_FOOTER')) {
        $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
        $header = realpath(TF_EDGE_ARTICLE_HEADER);
        $footer = realpath(TF_EDGE_ARTICLE_FOOTER);
        if ($root && $header && $footer && str_starts_with($header, $root . DIRECTORY_SEPARATOR) && str_starts_with($footer, $root . DIRECTORY_SEPARATOR) && is_readable($header) && is_readable($footer)) {
            ob_start();
            $tourfecto_article = ['title'=>$title,'description'=>$description,'canonical'=>$canonical];
            require $header;
            echo '<main data-tourfecto-guide>' . $main . '</main>';
            require $footer;
            $layout = ob_get_clean();
            $layout=preg_replace('~</head>~i','<meta name="tourfecto-guide-layout" content="configured">'.GuideContent::style().'</head>',$layout,1);
            $fields=tf_edge_page_rules();$fields['title']=$title;$fields['description']=$description;$fields['canonical']=$canonical;
            if ($jsonld!=='') $fields['jsonld']=$jsonld;
            $layout=tf_edge_buffer($layout,$fields);
            echo $layout;
            exit;
        }
    }
    if ($useTheme) {
        $fields=tf_edge_page_rules();
        $fields['title']=$title;$fields['description']=$description;$fields['canonical']=$canonical;
        $fields['og']=['og:type'=>'article','og:title'=>$title,'og:description'=>$description,'og:url'=>$canonical];
        if($image!=='')$fields['og']['og:image']=$image;
        if($jsonld!=='')$fields['jsonld']=$jsonld;
        $GLOBALS['tf_edge_guide_page']=compact('title','description','canonical','main','jsonld','image','fields');
        return;
    }
    echo tf_edge_fallback_page($title,$description,$canonical,$main,$jsonld,$image);
    exit;
}

function tf_edge_fallback_page($title,$description,$canonical,$main,$jsonld='',$image='')
{
    $host=preg_replace('/^www\./','',parse_url(tf_edge_site(),PHP_URL_HOST)?:'');
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="tourfecto-guide-layout" content="standalone"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="tourfecto-connector" content="4"><title>' . tf_edge_e($title) . '</title>'
        . '<meta name="description" content="' . tf_edge_e($description) . '"><link rel="canonical" href="' . tf_edge_e($canonical) . '">'
        . '<meta property="og:type" content="article"><meta property="og:title" content="' . tf_edge_e($title) . '"><meta property="og:description" content="' . tf_edge_e($description) . '">'
        . ($image !== '' ? '<meta property="og:image" content="' . tf_edge_e($image) . '"><meta name="twitter:card" content="summary_large_image">' : '')
        . ($jsonld !== '' ? '<script id="tourfecto-schema" type="application/ld+json">' . str_replace('</', '<\/', $jsonld) . '</script>' : '')
        . '<style>body{margin:0;font:17px/1.75 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2433;background:#fbfaf7}'
        . 'header,main,footer{max-width:760px;margin:0 auto;padding:0 20px}header{display:flex;justify-content:space-between;align-items:center;padding-top:22px;padding-bottom:22px;border-bottom:1px solid #e7e2d8}'
        . 'header a{color:#1d2433;text-decoration:none;font-weight:700}nav a{font-weight:500;margin-left:16px;color:#5b6475}h1{font-size:2.1rem;line-height:1.25;margin:34px 0 8px}'
        . 'figure{margin:0 0 26px}figure img{width:100%;height:auto;max-height:440px;object-fit:cover;border-radius:12px;display:block}'
        . 'h2{font-size:1.35rem;margin:30px 0 6px}.meta{color:#7a8291;font-size:.9rem;margin-bottom:24px}main a{color:#a35a14}ul{padding-left:22px}'
        . '.list a{display:block;padding:18px 0;border-bottom:1px solid #e7e2d8;text-decoration:none;color:#1d2433}.list span{display:block;color:#5b6475;font-size:.95rem}'
        . 'footer{color:#7a8291;font-size:.9rem;padding-top:40px;padding-bottom:40px}</style></head><body>'
        . '<header><a href="/">' . tf_edge_e($host) . '</a><nav><a href="/">Home</a><a href="/?tf_guides=1">Guides</a></nav></header>'
        . '<main data-tourfecto-guide>' . $main . '</main><footer>&copy; ' . date('Y') . ' ' . tf_edge_e($host) . '</footer></body></html>';
}

function tf_edge_articles_route()
{
    $key=(string)(tf_edge_rules()['indexnow_key']??'');
    if(preg_match('/^[a-zA-Z0-9-]{8,128}$/D',$key) && tf_edge_path()==='/'.$key.'.txt'){
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){http_response_code(405);exit;}
        http_response_code(200);header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=120');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $key;exit;
    }
    $articles = tf_edge_rules()['articles'] ?? [];
    $requestedSlug=tf_edge_article_slug();
    if (!is_array($articles) || !$articles) {
        if($requestedSlug!==null){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('Retry-After: 120');echo 'Guide temporarily unavailable. Please try again later.';exit;}
        return;
    }
    $base = tf_edge_site();
    if (tf_edge_path() === '/tourfecto-guides-sitemap.xml' || (in_array(tf_edge_path(),['/','/index.php'],true) && ($_GET['tf_guides_sitemap']??null)==='1')) {
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($articles as $slug=>$article) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,120}$/',$slug)) continue;
            echo '<url><loc>'.htmlspecialchars(tf_edge_article_url($slug,$article),ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';
        }
        echo '</urlset>';exit;
    }
    if (tf_edge_path() === '/guides' || (in_array(tf_edge_path(),['/','/index.php'],true) && ($_GET['tf_guides']??null)==='1')) {
        $list = '';
        foreach ($articles as $slug => $a) {
            $list .= '<a href="' . tf_edge_e(tf_edge_article_url($slug,$a)) . '"><strong>' . tf_edge_e($a['title'] ?? '') . '</strong><span>' . tf_edge_e($a['description'] ?? '') . '</span></a>';
        }
        tf_edge_page('Travel guides', 'Travel guides and tips.', $base . '/?tf_guides=1', '<h1>Travel guides</h1><div class="list">' . $list . '</div>');
        return;
    }
    $slug = tf_edge_article_slug();
    if ($slug === null) return;
    if (!isset($articles[$slug]) || !is_array($articles[$slug])) {
        http_response_code(404);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo 'Guide not found.';exit;
    }
    $a = $articles[$slug];
    $title = (string) ($a['title'] ?? '');
    $canonical = isset($_GET['tf_article']) ? $base.'/?tf_article='.rawurlencode($slug) : $base . '/guides/' . $slug;
    if(!empty($a['url']))$canonical=tf_edge_article_url($slug,$a);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['published_at'] ?? '')) ? $a['published_at'] : '';
    $image = (is_string($a['image'] ?? null) && preg_match('#^https://[^\s"<>]+$#', $a['image'])) ? $a['image'] : '';
    $figure = $image !== '' ? '<figure><img src="' . tf_edge_e($image) . '" alt="' . tf_edge_e($a['image_alt'] ?? $title) . '" loading="eager"></figure>' : '';
    $jsonld = json_encode(array_filter(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'description' => (string) ($a['description'] ?? ''), 'image' => $image ?: null,
        'mainEntityOfPage' => $canonical, 'datePublished' => $date ?: null, 'publisher' => ['@type' => 'Organization', 'name' => parse_url($base, PHP_URL_HOST), 'url' => $base]],
        function ($v) { return $v !== null && $v !== ''; }), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pageRules=tf_edge_page_rules();
    if (!empty($pageRules['jsonld'])) $jsonld=$pageRules['jsonld'];
    $related=tf_edge_related_html($pageRules['related_links']??$a['related_links']??[]);
    tf_edge_page($title, (string) ($a['description'] ?? ''), $canonical,
        '<article><h1>' . tf_edge_e($title) . '</h1>' . ($date ? '<div class="meta">' . tf_edge_e($date) . '</div>' : '') . $figure
        . tf_edge_article_html((string) ($a['body'] ?? '')) . $related . '</article><p><a href="/?tf_guides=1">&larr; All guides</a></p>', (string) $jsonld, $image);
}

// فحص سريع لصاحب الموقع: https://your-site.com/?tf_check=1
if (($_GET['tf_check'] ?? '') === '1') {
    $body = tf_edge_fetch(TF_EDGE_ENDPOINT . '&token=' . rawurlencode(TF_EDGE_TOKEN) . '&cv=4&site=' . rawurlencode(tf_edge_site()));
    $ok = $body !== null && is_array(json_decode($body, true));
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Tourfecto Connect v4: the file is installed and running (website #__SITE__).\n";
    echo "Guide presentation: 2026-10-17 (site theme and query sitemap).\n";
    echo $ok ? "Connection to Tourfecto: OK\n" : "Connection to Tourfecto: FAILED - your server cannot reach tourfecto.pro (check outgoing connections / firewall).\n";
    exit;
}

if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    tf_edge_articles_route();
    if (tf_edge_path() === '/llms.txt') {
        $r = tf_edge_rules();
        if (!empty($r['llms_txt']) && is_string($r['llms_txt'])) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $r['llms_txt'];
            exit;
        }
    }
    $r = tf_edge_rules();
    if (!empty($r['hsts']) && is_string($r['hsts']) && preg_match('/^max-age=\d{1,10}(; ?includeSubDomains)?(; ?preload)?$/', $r['hsts'])
        && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && !headers_sent()) {
        header('Strict-Transport-Security: ' . $r['hsts']);
    }
    ob_start('tf_edge_buffer');
}
PHPCODE;
        return strtr($code, ['__ENDPOINT__' => addslashes($endpoint), '__TOKEN__' => addslashes($token), '__SITE__' => (string) $websiteId, 'TF_EDGE_BODY_HELPERS' => self::bodyHelpers(), 'TF_GUIDE_CONTENT_CLASS'=>preg_replace('/^<\?php\s*/','',file_get_contents(dirname(__DIR__).'/Seo/GuideContent.php'))]);
    }

    /**
     * نفس الكود بس كإضافة WordPress كاملة (تترفع من Plugins ← Add New ← Upload)
     * بدل ما العميل يحتاج يسطّب Code Snippets ويلصق كود.
     * @return array{php:string, readme:string}
     */
    public static function pluginFiles(int $websiteId, string $endpoint, string $siteLabel = ''): array
    {
        $label = preg_replace('/[^A-Za-z0-9 .\-]/', '', $siteLabel);
        $php = "<?php\n"
            . "/**\n"
            . " * Plugin Name: Tourfecto SEO\n"
            . " * Plugin URI: https://tourfecto.pro\n"
            . " * Description: Applies the SEO fixes you approve in Tourfecto (title, description, schema, Open Graph, llms.txt...) server-side. Data only - no remote code is executed." . ($label !== '' ? ' Site: ' . $label . '.' : '') . "\n"
            . " * Version: 2.0.0\n"
            . " * Requires at least: 5.6\n"
            . " * Requires PHP: 7.2\n"
            . " * Author: Tourfecto\n"
            . " * License: GPLv2 or later\n"
            . " */\n"
            . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
            . self::snippet($websiteId, $endpoint) . "\n\n"
            . "register_deactivation_hook( __FILE__, function () { delete_transient( 'tf_edge_rules_v4' ); delete_option( 'tf_edge_last_rules_v4' ); delete_option( 'tf_edge_version' ); wp_clear_scheduled_hook('tf_edge_refresh_v4'); } );\n"
            . "add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( \$links ) {\n"
            . "    \$links[] = '<a href=\"' . esc_url( admin_url( 'index.php?tf_edge_refresh=1' ) ) . '\">Refresh now</a>';\n"
            . "    return \$links;\n"
            . "} );\n"
            . "add_action( 'admin_init', function () {\n"
            . "    if ( isset( \$_GET['tf_edge_refresh'] ) && current_user_can( 'manage_options' ) ) { delete_transient( 'tf_edge_rules_v4' ); tf_edge_rules(); }\n"
            . "} );\n";
        $readme = "=== Tourfecto SEO ===\nContributors: tourfecto\nRequires at least: 5.6\nStable tag: 2.0.0\nLicense: GPLv2 or later\n\n"
            . "Applies the SEO fixes you approve in your Tourfecto dashboard, server-side.\n\n"
            . "== Description ==\nThe plugin fetches validated data (never code) from Tourfecto every two minutes and applies it. If Tourfecto is unreachable the site keeps its last approved rule data; the platform still verifies the current public response. Refreshes use WP-Cron when available, with a two-second bounded fallback. "
            . "Homepage legacy rules and exact-path SEO Expert rules replace only explicitly approved metadata. Other pages and unrelated markup are preserved. Disable old Tourfecto snippets before activation. Check the public-site verification report after applying changes.\n\n"
            . "== Installation ==\n1. Plugins > Add New > Upload Plugin, choose this zip.\n2. Activate.\n3. Back in Tourfecto press \"Check connection\".\n";
        return ['php' => $php, 'readme' => $readme];
    }
}
