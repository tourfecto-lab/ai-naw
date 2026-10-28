<?php
/** CLI-only release builder. Rebuild after adding/renaming application classes. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if (!function_exists('token_get_all')) throw new RuntimeException('Enable the PHP tokenizer extension for this CLI build tool');
$root=dirname(__DIR__);
$target=$root.'/app/Config/project_classmap.php';
$previous=is_file($target)?require $target:[];
$types=[T_CLASS,T_INTERFACE,T_TRAIT];
if (defined('T_ENUM')) $types[]=constant('T_ENUM');
$found=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app',FilesystemIterator::SKIP_DOTS)) as $f){
    $path=str_replace('\\','/',$f->getPathname());
    if (!$f->isFile() || $f->getExtension()!=='php' || str_contains($path,'~')
        || preg_match('#/(Views|Lang|database)/#',$path)) continue;
    $tokens=token_get_all(file_get_contents($path));
    // Vendor and namespaced application classes remain owned by Composer.
    $namespaced=false;
    foreach($tokens as $t) if(is_array($t)&&$t[0]===T_NAMESPACE){$namespaced=true;break;}
    if($namespaced)continue;
    foreach($tokens as $i=>$t){
        if(!is_array($t)||!in_array($t[0],$types,true))continue;
        $j=$i+1;
        while(isset($tokens[$j])&&is_array($tokens[$j])&&in_array($tokens[$j][0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$j++;
        if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_STRING)$found[$tokens[$j][1]][]=$path;
    }
}
$score=function($path)use($root){
    $rel=substr($path,strlen($root)+1);
    $priority=str_starts_with($rel,'app/Services/')?0:(str_starts_with($rel,'app/Core/')?1:2);
    return [$priority,-substr_count($rel,'/'),$rel];
};
$lines=[];ksort($found);
foreach($found as $class=>$paths){
    $selected=$previous[$class]??null;
    if(!$selected||!in_array($selected,$paths,true)){
        usort($paths,fn($a,$b)=>$score($a)<=>$score($b));$selected=$paths[0];
    }
    $relative=substr($selected,strlen($root));
    $lines[]='    '.var_export($class,true)." => dirname(__DIR__,2).".var_export($relative,true).',';
}
$content="<?php\n// Generated release map. Rebuild via tools/build_project_classmap.php after code changes.\nreturn [\n".implode("\n",$lines)."\n];\n";
$tmp=$target.'.tmp';
if(file_put_contents($tmp,$content)===false || !rename($tmp,$target)) throw new RuntimeException('Cannot write release map');
echo 'Canonical application classes: '.count($found).PHP_EOL;
