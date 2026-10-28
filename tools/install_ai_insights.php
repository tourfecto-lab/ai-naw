<?php
/**
 * One-time installer for AI insights. Edits the files that are ON THE SERVER in place, so it never
 * replaces a newer server file with an older copy. Each edit is anchored, idempotent and syntax-checked;
 * originals are copied to storage/backups/ai-insights-<time>/ first.
 *
 * Run once from CLI (Hostinger hPanel → Cron Jobs → "Run" or SSH):  php tools/install_ai_insights.php
 * Dry run (no writes):                                             php tools/install_ai_insights.php --dry-run
 * The report is printed and also saved to storage/logs/ai_insights_install.txt.
 */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);$dry=in_array('--dry-run',$argv??[],true);$stamp=gmdate('Ymd-His');$report=[];
$say=function(string $line)use(&$report){$report[]=$line;echo $line,PHP_EOL;};

function aiiParses(string $code): bool { try { token_get_all($code,TOKEN_PARSE); return true; } catch (ParseError $e) { return false; } }

/** @return array{0:string,1:string} [status, message]; status: done | already | skipped | failed */
$edit=function(string $rel,callable $change,bool $php=true)use($root,$dry,$stamp): array {
    $path=$root.'/'.$rel;
    if (!is_file($path)) return ['skipped','file not found'];
    $old=file_get_contents($path);if($old===false)return ['failed','cannot read'];
    [$state,$new]=$change($old);
    if ($state==='already') return ['already','already installed'];
    if ($state!=='ok' || $new===$old) return ['skipped','anchor not found; add it by hand (see README)'];
    if ($php && !aiiParses($new)) return ['failed','result would not parse; file left unchanged'];
    if ($dry) return ['done','would change (dry run)'];
    $backup=$root.'/storage/backups/ai-insights-'.$stamp.'/'.$rel;
    if (!is_dir(dirname($backup)) && !mkdir(dirname($backup),0750,true)) return ['failed','cannot create backup folder'];
    if (!copy($path,$backup)) return ['failed','cannot write backup'];
    if (file_put_contents($path,$new,LOCK_EX)===false) return ['failed','cannot write file'];
    return ['done','changed; backup at storage/backups/ai-insights-'.$stamp.'/'.$rel];
};

// Claude is registered only when the server's provider base class has the contract ClaudeVisibilityProvider extends.
$base=@file_get_contents($root.'/app/Services/AiVisibility/AiVisibilityProvider.php')?:'';
$compatible=preg_match('/abstract\s+class\s+AiVisibilityProvider/',$base)
    && preg_match('/function\s+__construct\s*\(\s*\?array\s+\$config\s*=\s*null\s*,\s*\?callable\s+\$transport\s*=\s*null\s*\)/',$base)
    && preg_match('/protected\s+function\s+context\s*\(\s*string\s+\$question\s*,\s*array\s+\$market\s*\)/',$base)
    && preg_match('/protected\s+static\s+function\s+cost\s*\(\s*array\s+\$usage\s*,\s*string\s+\$input\s*,\s*string\s+\$output\s*,\s*float\s+\$inRate\s*,\s*float\s+\$outRate\s*,\s*float\s+\$search\s*\)/',$base)
    && preg_match('/abstract\s+protected\s+function\s+request\s*\(\s*string\s+\$question\s*,\s*array\s+\$market\s*\)\s*:\s*array/',$base)
    && preg_match('/abstract\s+public\s+function\s+parse\s*\(\s*array\s+\$response\s*\)\s*:\s*array/',$base);

$steps=[
  ['app/routes/api.php','routes for /ai-insights',function(string $s){
      if (str_contains($s,'ai_insights.php')) return ['already',$s];
      return ['ok',rtrim($s)."\n\n// AI insights: cited sources, sentiment, AI crawler visits\nrequire __DIR__.'/ai_insights.php';\n"];
  }],
  ['app/Core/ClientNavigation.php','menu item under Search visibility',function(string $s){
      if (str_contains($s,"'ai_insights'")) return ['already',$s];
      $n=preg_replace("#^([ \t]*)('ai_visibility'\s*=>\s*\[[^\r\n]*'/ai-visibility'\s*\]\s*,)[ \t]*(\r?\n)#m","$1$2$3$1'ai_insights'=>[\$l('مصادر وانطباع الـAI','AI sources and sentiment'),'/ai-insights'],$3",$s,1,$c);
      return [$c?'ok':'none',$n];
  }],
];
if ($compatible) {
  $steps[]=['app/Services/AiVisibility/AiVisibilityService.php','Claude in the measured providers',function(string $s){
      if (str_contains($s,'new ClaudeVisibilityProvider(')) return ['already',$s];
      $n=preg_replace('/new\s+SonarVisibilityProvider\(\)/','new SonarVisibilityProvider(),new ClaudeVisibilityProvider()',$s,1,$c);
      return [$c?'ok':'none',$n];
  }];
  $steps[]=['app/Services/AiVisibility/AiVisibilityMetrics.php','Claude in the results summary',function(string $s){
      if (preg_match("/'perplexity'\s*,\s*'claude'/",$s)) return ['already',$s];
      $n=preg_replace("/\[\s*'openai'\s*,\s*'gemini'\s*,\s*'perplexity'\s*\]/","['openai','gemini','perplexity','claude']",$s,-1,$c);
      return [$c?'ok':'none',$n];
  }];
  $steps[]=['app/Services/AiVisibility/AiVisibilityPresentation.php','Claude label',function(string $s){
      if (preg_match("/'claude'\s*=>\s*'Claude'/",$s)) return ['already',$s];
      $n=preg_replace("/('perplexity'\s*=>\s*'Perplexity')/","$1,'claude'=>'Claude'",$s,1,$c);
      return [$c?'ok':'none',$n];
  }];
  $steps[]=['app/Views/AiVisibility/index.php','Claude label on the results page',function(string $s){
      if (preg_match("/claude\s*:\s*'Claude'/",$s)) return ['already',$s];
      $n=preg_replace("/(perplexity\s*:\s*'Perplexity')/","$1,claude:'Claude'",$s,-1,$c);
      return [$c?'ok':'none',$n];
  }];
}

$say('AI insights installer '.($dry?'(dry run) ':'').gmdate('Y-m-d H:i:s').' UTC');
foreach (['app/Controllers/AiInsightsController.php','app/routes/ai_insights.php','app/Services/AiInsights/AiCrawlerService.php','app/Views/AiInsights/index.php','app/Services/AiVisibility/ClaudeVisibilityProvider.php'] as $f)
    if (!is_file($root.'/'.$f)) { $say("STOP  missing $f: extract the ZIP into the project folder first");exit(1); }
if (!$compatible) $say('NOTE  Claude not registered: the server\'s AiVisibilityProvider differs from the version this update was built for. Everything else still installs.');
$failed=0;
foreach ($steps as [$rel,$what,$fn]) { [$st,$msg]=$edit($rel,$fn,true);if($st==='failed')$failed++;$say(str_pad(strtoupper($st),8).$rel.' — '.$what.': '.$msg); }
$log=$root.'/storage/logs/ai_insights_install.txt';if(!$dry && (is_dir(dirname($log)) || @mkdir(dirname($log),0750,true)))@file_put_contents($log,implode(PHP_EOL,$report).PHP_EOL,FILE_APPEND);
exit($failed?1:0);
