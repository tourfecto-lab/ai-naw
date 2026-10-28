<?php
require __DIR__.'/../app/Services/CreativeStudio/GeneratedMediaFiles.php';
require __DIR__.'/../app/Services/CreativeStudio/MediaGenerationService.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
check(function_exists('imagecreatefromstring'),'Enable PHP GD');
$im=imagecreatetruecolor(1024,1536);imagefill($im,0,0,imagecolorallocate($im,20,40,60));
ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);
$source=GeneratedMediaFiles::image(base64_encode($bytes));
foreach(['1:1','4:5','4:3','3:4','16:9','9:16'] as $ratio){
 $result=GeneratedMediaFiles::fitAspect($source,$ratio);[$a,$b]=array_map('intval',explode(':',$ratio));
 check($result['width']*$b===$result['height']*$a,'Incorrect ratio '.$ratio);
 check($result['width']<=1024&&$result['height']<=1536,'Unexpected upscaling');
 $decoded=imagecreatefromstring($result['data']);$color=imagecolorsforindex($decoded,imagecolorat($decoded,0,0));imagedestroy($decoded);
 check($color['red']===20&&$color['green']===40&&$color['blue']===60,'Color changed');
 check(GeneratedMediaFiles::fitAspect($result,$ratio)['data']===$result['data'],'Repeated processing changed exact output');
 echo 'PASS '.$ratio.' '.$result['width'].'x'.$result['height'].PHP_EOL;
}
try{GeneratedMediaFiles::fitAspect($source,'bad');throw new RuntimeException('Invalid ratio accepted');}catch(InvalidArgumentException $e){}
check(strpos(MediaGenerationService::stylePresets()['photo'],'4k')===false,'Misleading 4K direction');
echo 'All image processing checks passed.'.PHP_EOL;
