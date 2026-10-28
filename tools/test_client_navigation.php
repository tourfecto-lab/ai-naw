<?php
require_once __DIR__.'/../app/Core/ClientNavigation.php';
function icon_svg(string $key): string{return '<svg></svg>';}
function navCheck(bool $ok,string $name): void{if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
$groups=ClientNavigation::groups(true);navCheck(count($groups)===8,'eight service groups');
$html=ClientNavigation::sidebar('ai_keywords',true,[]);navCheck(str_contains($html,'href="/business-hub"'),'shared hub is first home entry');navCheck(!str_contains($html,'href="/auto-seo"'),'legacy SEO is not a separate sidebar tool');navCheck(str_contains($html,'href="/seo-expert" class="panel-nav-link active"'),'keyword page highlights search service');
$workspace=ClientNavigation::workspace('seo_expert',true,[]);navCheck(str_contains($workspace,'<details')&&str_contains($workspace,'href="/auto-seo"'),'legacy tools remain within service');
$html=ClientNavigation::sidebar('seo_expert',false,['seo_expert'=>false]);navCheck(!str_contains($html,'href="/seo-expert"'),'disabled primary feature not linked');navCheck(str_contains($html,'href="/seo-content"'),'enabled fallback service entry');
$workspace=ClientNavigation::workspace('seo_expert',false,['auto_seo'=>false]);navCheck(!str_contains($workspace,'href="/auto-seo"'),'workspace honors disabled tools');
navCheck(ClientNavigation::workspace('unknown',true,[])==='','unmapped pages preserved without injected navigation');
