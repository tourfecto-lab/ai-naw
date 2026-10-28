<?php
/** No database or provider calls. Run with PHP 8.1+ and mbstring. */
define('ENCRYPTION_KEY',str_repeat('t',64));
require_once __DIR__.'/../app/Services/SeoExpert/SeoExpertService.php';
require_once __DIR__.'/../app/Services/AutoFix/FixValueBuilder.php';
function seCheck(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
function seReject(callable $fn,string $name): void{try{$fn();}catch(Throwable $e){echo 'PASS '.$name.PHP_EOL;return;}throw new RuntimeException('Expected rejection: '.$name);}
seCheck(SeoExpertService::path('https://travel.test/tours/')==='/tours','normalized exact page path');
seReject(fn()=>SeoExpertService::path('https://travel.test/tours/?lang=en'),'query-specific pages need an adapter');
seReject(fn()=>SeoExpertService::path('https://user:password@travel.test/'),'embedded URL credentials rejected');
$pages=[['url'=>'https://travel.test/','values'=>['title'=>[]],'page_path'=>'/']];
$plan=['summary'=>'Improve the confirmed travel service page with clear factual information.','changes'=>[['page_index'=>0,'field'=>'title','value'=>'Private yacht experiences in Dubai','reason'=>'Describes the actual supplied service.']],'client_tasks'=>[],'content_drafts'=>[]];
seCheck(count(SeoExpertService::validatePlan(json_encode($plan),$pages,'travel.test')['changes'])===1,'grounded metadata plan accepted');
$unsafe=$plan;$unsafe['changes'][0]['field']='robots';seReject(fn()=>SeoExpertService::validatePlan(json_encode($unsafe),$pages,'travel.test'),'model cannot grant itself indexing-control permission');
$unsafe=$plan;$unsafe['changes'][0]['page_index']=8;seReject(fn()=>SeoExpertService::validatePlan(json_encode($unsafe),$pages,'travel.test'),'uninspected target page rejected');
$unsafe=$plan;$unsafe['changes'][]=$unsafe['changes'][0];seReject(fn()=>SeoExpertService::validatePlan(json_encode($unsafe),$pages,'travel.test'),'duplicate action rejected');
$unsafe=$plan;$unsafe['changes'][0]['value']='Your company example.com';seReject(fn()=>SeoExpertService::validatePlan(json_encode($unsafe),$pages,'travel.test'),'placeholder values rejected');
$body=SeoExpertService::contentHtml('<script>alert(1)</script>' . "\n\n" . 'Real factual text.',['https://travel.test/tours/']);
seCheck(!str_contains($body,'<script>')&&str_contains($body,'&lt;script&gt;'),'article HTML is escaped');
seCheck(SeoExpertService::quoteHash(['source'=>'wallet','price'=>'1.50'])===SeoExpertService::quoteHash(['source'=>'wallet','price'=>1.5]),'budget quote normalization');

// Minimal WordPress host to execute the actual generated connector in isolation.
$GLOBALS['wp_transients']=[];$GLOBALS['wp_options']=[];$GLOBALS['wp_front']=true;$GLOBALS['wp_scheduled']=0;
$GLOBALS['fixture_rules']=['title'=>'Approved homepage title','page_rules'=>['/tours'=>['title'=>'Approved tours page title','description'=>'Actual tours page description supplied by its owner.','php'=>'must never execute']]];
function add_action(...$a){}function add_filter(...$a){}function do_action(...$a){}
function get_transient($key){return $GLOBALS['wp_transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['wp_transients'][$key]=$value;}
function delete_transient($key){unset($GLOBALS['wp_transients'][$key]);}
function get_option($key,$default=false){return $GLOBALS['wp_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['wp_options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['wp_options'][$key]);}
function home_url(){return 'https://travel.test';}function is_admin(){return true;}
function wp_remote_get($url,$options){return ['code'=>200,'body'=>json_encode(['data'=>['rules'=>$GLOBALS['fixture_rules'],'version'=>'test-version']])];}
function is_wp_error($r){return false;}function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
function wp_next_scheduled($key){return false;}function wp_schedule_single_event($time,$key){$GLOBALS['wp_scheduled']++;}
function is_front_page(){return $GLOBALS['wp_front'];}function is_paged(){return false;}
function wp_parse_url($url,$component){return parse_url($url,$component);}function wp_unslash($s){return $s;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_attr($s){return esc_html($s);}function esc_url($s){return esc_attr($s);}
$_SERVER['REQUEST_URI']='/';eval(EdgeRules::snippet(1,'https://app.travel.test'));
seCheck(tf_edge_page_rules()['title']==='Approved homepage title','legacy homepage rule retained');
$GLOBALS['wp_front']=false;$_SERVER['REQUEST_URI']='/tours/?utm_source=test';
seCheck(tf_edge_page_rules()['title']==='Approved tours page title','inner-page rule selected by exact normalized path');
seCheck(!isset(tf_edge_page_rules()['php']),'unknown remote fields excluded');
$head='<title>Old page title</title><meta name="description" content="Old description"><script type="application/ld+json">{"@type":"BreadcrumbList"}</script>';
$output=tf_edge_normalize_head($head,tf_edge_page_rules());
seCheck(substr_count($output,'<title>')===1&&str_contains($output,'Approved tours page title'),'one correct title after replacement');
seCheck(str_contains($output,'BreadcrumbList'),'unrelated schema preserved');
$output=tf_edge_normalize_head('<meta charset="utf-8">',tf_edge_page_rules());seCheck(str_contains($output,'<title>Approved tours page title</title>'),'missing title is actually inserted');
$_SERVER['REQUEST_URI']='/another-page';seCheck(tf_edge_page_rules()===[],'no homepage metadata leaked onto unrelated pages');
seCheck(tf_edge_normalize_head($head,tf_edge_page_rules())===$head,'unrelated page head remains intact');
$GLOBALS['wp_transients']=[];$GLOBALS['wp_options']['tf_edge_last_rules_v4']=$GLOBALS['fixture_rules'];$GLOBALS['wp_transients']['tf_edge_retry_v4']=true;
seCheck(tf_edge_rules()===$GLOBALS['fixture_rules'],'transport outage retains only previously approved rule data');
echo 'SEO Expert pure-function and generated-connector tests passed.'.PHP_EOL;
