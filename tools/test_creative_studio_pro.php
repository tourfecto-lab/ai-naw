<?php
/**
 * Creative Studio Pro upgrade checks. No provider calls, credentials or database writes.
 * Run: php tools/test_creative_studio_pro.php
 */
$root=dirname(__DIR__);
if(!defined('ROOT_PATH'))define('ROOT_PATH',sys_get_temp_dir().'/tourfecto_csp_'.getmypid());
@mkdir(ROOT_PATH.'/storage/private',0700,true);
foreach(['GenerationOptions','GenerationLifecycle','GeneratedMediaFiles','BrandPromptBuilder','CreativeQualityGate','CreativeAssetRegistry','CreativeGenerationWatchdog','MediaGenerationService','VideoEditing','VideoScriptService','PrivateMedia','ReferenceMedia','CreativePricing'] as $c)require_once $root.'/app/Services/CreativeStudio/'.$c.'.php';
require_once $root.'/app/Services/SocialMedia/SiteImageImporter.php';
require_once $root.'/app/Services/SocialMedia/SocialMonthlyPlanService.php';
require_once $root.'/app/Services/AI/VeoClient.php';
require_once $root.'/app/Services/WebsiteBuilderService.php';

$passed=0;
function ok(bool $c,string $label): void { global $passed; if(!$c)throw new RuntimeException('FAIL: '.$label); $passed++; echo 'PASS '.$label.PHP_EOL; }
function rejects(callable $fn,string $label): void { try{$fn();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$label);return;} throw new RuntimeException('FAIL (no rejection): '.$label); }
function png(int $w,int $h,array $rgb=[20,40,60],bool $alpha=false): string { $im=imagecreatetruecolor($w,$h);imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,$alpha?imagecolorallocatealpha($im,$rgb[0],$rgb[1],$rgb[2],100):imagecolorallocate($im,$rgb[0],$rgb[1],$rgb[2]));imagefilledrectangle($im,(int)($w/4),(int)($h/4),(int)($w/2),(int)($h/2),imagecolorallocate($im,250,200,40));ob_start();imagepng($im);$b=ob_get_clean();imagedestroy($im);return $b; }

// 1) الإعدادات التلقائية: مفيش توجيه متعارض مع وصف العميل
$auto=GenerationOptions::normalize([]);
ok($auto['lighting']==='auto'&&$auto['composition']==='auto','image defaults are auto');
$dir=GenerationOptions::direction($auto);
ok(stripos($dir,'natural light')===false&&stripos($dir,'Balanced composition')===false,'auto adds no lighting/composition directive');
$v=GenerationOptions::normalize([],true);$vdir=GenerationOptions::direction($v,true);
ok($v['camera']==='auto'&&stripos($vdir,'locked-off')===false&&stripos($vdir,'ambient sound')===false,'video auto adds no camera/audio directive');
$explicit=GenerationOptions::normalize(['lighting'=>'night','composition'=>'wide','camera'=>'aerial','audio'=>'music'],true);$edir=GenerationOptions::direction($explicit,true);
ok(str_contains($edir,'night lighting')&&str_contains($edir,'Wide establishing')&&str_contains($edir,'aerial')&&str_contains($edir,'instrumental'),'explicit choices are honoured');
ok(GenerationOptions::normalize(['lighting'=>'natural'])['lighting']==='natural','legacy saved values still validate');
rejects(fn()=>GenerationOptions::normalize(['lighting'=>'disco']),'unknown lighting rejected');
$avoid=GenerationOptions::normalize(['avoid'=>'crowds, cars'],true);
ok(!str_contains(GenerationOptions::direction($avoid,true),'crowds'),'video avoid list stays out of the positive prompt');
ok(str_contains(GenerationOptions::videoNegativePrompt($avoid),'crowds, cars')&&str_contains(GenerationOptions::videoNegativePrompt($avoid),'watermark'),'video negative prompt carries avoid list and defect list');
ok(str_contains(GenerationOptions::direction(GenerationOptions::normalize(['avoid'=>'crowds'])),'Avoid these elements: crowds'),'image avoid list kept in prompt');
ok(GenerationOptions::variantDirection(1)===''&&strlen(GenerationOptions::variantDirection(2))>80&&GenerationOptions::variantDirection(2)!==GenerationOptions::variantDirection(1),'second variant gets a distinct direction');

// 2) رسائل الفشل للعميل
$m=GenerationLifecycle::PUBLIC_MESSAGES;
ok(GenerationLifecycle::publicFailure('No image in Gemini response (IMAGE_SAFETY)')===$m['safety'],'Gemini safety block classified');
ok(GenerationLifecycle::publicFailure('Image API Error (HTTP 400): Your request was rejected by the safety system. moderation_blocked')===$m['safety'],'OpenAI moderation classified');
ok(GenerationLifecycle::publicFailure('تم حجب الفيديو بواسطة فلاتر السلامة: x')===$m['safety'],'Veo safety filter classified');
ok(GenerationLifecycle::publicFailure('HTTP 429 RESOURCE_EXHAUSTED')===$m['quota'],'quota classified');
ok(GenerationLifecycle::publicFailure('CREATIVE_STALLED no progress')===$m['stalled'],'stalled classified');
ok(GenerationLifecycle::publicFailure('CREATIVE_VIDEO_EXPIRED')===$m['video_expired'],'expired video classified');
ok(GenerationLifecycle::publicFailure('CREATIVE_QA_REJECTED score 2')===$m['quality'],'quality rejection classified');
ok(GenerationLifecycle::publicFailure('Wallet reservation unavailable')===$m['generic'],'unknown failure is generic');
ok(GenerationOptions::publicError($m['quota'])===$m['quota'],'stored public message is shown');
ok(GenerationOptions::publicError('cURL Error: secret-token-xyz')===$m['generic'],'raw provider text is never shown');
ok(GenerationOptions::publicError('Video created; local download/storage requires retry')===$m['generic'],'internal English status is not shown');
foreach($m as $k=>$text)ok(str_contains($text,'لم يُخصم')||str_contains($text,'إرجاع الرصيد'),'message '.$k.' tells the client nothing was charged');

// 3) أسماء ألوان الهوية
ok(BrandPromptBuilder::colorName('#0F4C81')!==''&&str_contains(BrandPromptBuilder::colorName('#0F4C81'),'blue'),'navy hex becomes a blue name');
ok(str_contains(BrandPromptBuilder::colorName('#F5A623'),'yellow')||str_contains(BrandPromptBuilder::colorName('#F5A623'),'orange')||str_contains(BrandPromptBuilder::colorName('#F5A623'),'mustard'),'amber hex becomes warm name');
ok(BrandPromptBuilder::colorName('#FFFFFF')==='white'&&BrandPromptBuilder::colorName('#000000')==='black','neutrals named plainly');
ok(BrandPromptBuilder::colorName('nope')===''&&BrandPromptBuilder::colorName('#12')==='','invalid hex ignored');
ok(!preg_match('/#[0-9a-f]{6}/i',BrandPromptBuilder::colorName('#C81E3C')),'no hex code leaks into names');

// 4) مقاس المنصة + JPEG
$src=GeneratedMediaFiles::image(base64_encode(png(1024,1536)));
foreach(GeneratedMediaFiles::PLATFORM_MIN_WIDTH as $ratio=>$minW){
    $out=GeneratedMediaFiles::deliver($src,$ratio);[$a,$b]=array_map('intval',explode(':',$ratio));
    ok($out['width']*$b===$out['height']*$a,"exact ratio $ratio ({$out['width']}x{$out['height']})");
    ok($out['extension']==='jpg','opaque output is JPEG for Instagram '.$ratio);
    ok($out['width']>=min($minW,(int)floor(GeneratedMediaFiles::fitAspect($src,$ratio)['width']*2)/1),'platform width reached or 2x cap '.$ratio);
}
$story=GeneratedMediaFiles::deliver($src,'9:16');ok($story['width']>=1080&&$story['upscaled'],'story upscaled to >=1080 wide');
$wide=GeneratedMediaFiles::deliver(GeneratedMediaFiles::image(base64_encode(png(1536,1024))),'16:9');ok($wide['width']===1920&&$wide['height']===1080,'16:9 delivered at 1920x1080');
$big=GeneratedMediaFiles::deliver(GeneratedMediaFiles::image(base64_encode(png(2048,2048))),'1:1');ok(!$big['upscaled']&&$big['width']===2048,'large image is not resized');
$alpha=GeneratedMediaFiles::deliver(GeneratedMediaFiles::image(base64_encode(png(1200,1200,[0,0,0],true))),'1:1');ok($alpha['extension']==='png','transparent image stays PNG');
$gd=imagecreatefromstring(png(64,64));ok(!GeneratedMediaFiles::hasTransparency($gd),'opaque detection');imagedestroy($gd);
$tiny=GeneratedMediaFiles::deliver(GeneratedMediaFiles::image(base64_encode(png(300,300))),'1:1');ok($tiny['width']===600,'upscale capped at 2x');

// 5) فحص الجودة
$p=CreativeQualityGate::parse(['success'=>true,'data'=>"```json\n{\"score\":4,\"issues\":[\"garbled sign text\",\"six fingers\"],\"fix\":\"Remove all signage text\"}\n```"]);
ok($p['checked']&&$p['score']===4&&!$p['passed']&&!$p['reject']&&count($p['issues'])===2,'QA JSON parsed (moderate)');
ok(CreativeQualityGate::parse(['success'=>true,'data'=>'{"score":2,"issues":[]}'])['reject'],'QA hard reject at <=3');
ok(CreativeQualityGate::parse(['success'=>true,'data'=>'{"score":9}'])['passed'],'QA pass at >=7');
ok(!CreativeQualityGate::parse(['success'=>false])['checked']&&CreativeQualityGate::parse(['success'=>false])['passed'],'QA fails open when vision unavailable');
ok(!CreativeQualityGate::parse(['success'=>true,'data'=>'not json'])['checked'],'QA ignores malformed output');
ok(CreativeQualityGate::parse(['success'=>true,'data'=>'{"score":99}'])['score']===10,'QA score clamped');
$c=CreativeQualityGate::correction($p);ok(str_contains($c,'garbled sign text')&&str_contains($c,'Remove all signage text')&&str_contains($c,'no text'),'correction carries issues and fix');
$seen=null;$gate=new CreativeQualityGate(function($jpeg,$prompt)use(&$seen){$seen=[$jpeg,$prompt];return ['success'=>true,'data'=>'{"score":8,"issues":[]}'];});
$r=$gate->review(png(800,600),'Beach at sunset "quoted"');ok($r['passed']&&$seen&&substr($seen[0],0,2)==="\xFF\xD8"&&str_contains($seen[1],'Beach at sunset'),'reviewer receives a JPEG and the brief');
ok(!str_contains($seen[1],'"quoted"'),'brief quotes neutralised in reviewer instruction');

// 6) السجل الموحّد
ok(CreativeAssetRegistry::distance('ffffffff00000000','ffffffff00000000')===0,'identical phash distance 0');
ok(CreativeAssetRegistry::distance('ffffffff00000000','ffffffff00000001')===1,'one-bit distance');
ok(CreativeAssetRegistry::distance('bad','ffffffff00000000')===64,'invalid hash is maximally distant');
ok(CreativeAssetRegistry::closestRatio(1080,1350)==='4:5'&&CreativeAssetRegistry::closestRatio(1920,1080)==='16:9'&&CreativeAssetRegistry::closestRatio(1000,1000)==='1:1'&&CreativeAssetRegistry::closestRatio(720,1280)==='9:16','closest ratio for uploads');
$h1=CreativeAssetRegistry::fingerprint(png(400,400));$h2=CreativeAssetRegistry::fingerprint(GeneratedMediaFiles::deliver(GeneratedMediaFiles::image(base64_encode(png(400,400))),'1:1')['data']);
ok(is_string($h1)&&CreativeAssetRegistry::distance($h1,$h2)<=CreativeAssetRegistry::DUPLICATE_BITS,'re-encoded copy detected as duplicate');

// 7) حارس الطلبات العالقة
$row=fn($stage,$age,$type='social_image',$extra=[])=>['status'=>'generating','generation_stage'=>$stage,'type'=>$type,'age_minutes'=>$age,'generation_meta'=>json_encode($extra),'file_path'=>$extra['_file']??null];
ok(CreativeGenerationWatchdog::decide($row('queued',30),time())==='none','fresh queued untouched');
ok(CreativeGenerationWatchdog::decide($row('queued',121),time())==='stalled','old queued stalled');
ok(CreativeGenerationWatchdog::decide($row('processing',20),time())==='none','active image within window');
ok(CreativeGenerationWatchdog::decide($row('processing',46),time())==='stalled','stuck image stalled');
ok(CreativeGenerationWatchdog::decide($row('generating',120,'short_video'),time())==='none','video polling allowed longer');
ok(CreativeGenerationWatchdog::decide($row('generating',241,'short_video'),time())==='stalled','stuck video stalled');
ok(CreativeGenerationWatchdog::decide($row('download_failed',600,'short_video'),time())==='none','download recovery window kept');
ok(CreativeGenerationWatchdog::decide($row('download_failed',2161,'short_video'),time())==='video_expired','expired download closed');
ok(CreativeGenerationWatchdog::decide($row('billing',15,'social_image',['_file'=>'/uploads/media/a.jpg']),time())==='resume','written file is completed not discarded');
ok(CreativeGenerationWatchdog::decide($row('processing',50,'short_video',['source'=>'storyboard_render']),time())==='stalled','stuck storyboard render stalled');
ok(CreativeGenerationWatchdog::decide(['status'=>'completed','age_minutes'=>9999],time())==='none','completed items ignored');

// 8) خطة الشهر: صورة مختلفة لكل بوست
$assets=[['id'=>11,'video'=>false],['id'=>12,'video'=>false],['id'=>13,'video'=>false],['id'=>14,'video'=>true]];
$pick=SocialMonthlyPlanService::uniqueAssignments([0,0,0],$assets);ok(count(array_unique($pick))===3&&$pick[0]===11,'AI repeating one asset is spread across distinct photos');
$pick=SocialMonthlyPlanService::uniqueAssignments([2,1,0],$assets);ok($pick===[13,12,11],'distinct AI choices respected');
$pick=SocialMonthlyPlanService::uniqueAssignments([null,null],$assets,[11]);ok(!in_array(11,$pick,true),'recently published photo avoided when others exist');
$pick=SocialMonthlyPlanService::uniqueAssignments([0,0,0,0,0,0],$assets);$counts=array_count_values($pick);ok(max($counts)===2&&min($counts)===2,'repeats only when photos are fewer than posts, evenly');
$pick=SocialMonthlyPlanService::uniqueAssignments([3],$assets);ok($pick[0]!==14,'video index never used as image');
ok(SocialMonthlyPlanService::uniqueAssignments([0],[['id'=>9,'video'=>true]])===[null],'no images keeps null');

// 9) Veo negativePrompt
$payload=VeoClient::generationPayload('A felucca on the Nile at sunrise, slow dolly.','9:16',8,['resolution'=>'720p','model'=>'veo-3.1-fast-generate-preview','negative_prompt'=>'crowds, text']);
ok(($payload['parameters']['negativePrompt']??'')==='crowds, text','negativePrompt sent to Veo');
$payload=VeoClient::generationPayload('A felucca on the Nile at sunrise, slow dolly.','16:9',8,['resolution'=>'720p','model'=>'veo-3.1-fast-generate-preview']);
ok(!isset($payload['parameters']['negativePrompt']),'no empty negativePrompt');

// 10) الفيديو: الأبعاد والتجميع
ok(VideoEditing::nominalSize('9:16','720p')===['width'=>720,'height'=>1280]&&VideoEditing::nominalSize('16:9','1080p')===['width'=>1920,'height'=>1080]&&VideoEditing::nominalSize('16:9','4k')===['width'=>3840,'height'=>2160],'Veo nominal sizes');
$f=VideoEditing::concatFilter([8,8,6],720,1280,true);
ok(substr_count($f,',fade=t=in')===3&&substr_count($f,'afade=t=in')===3&&str_contains($f,'concat=n=3:v=1:a=1[v][a]')&&str_contains($f,'st=5.750'),'concat filter with audio and fades');
ok(str_contains(VideoEditing::concatFilter([4,4],1280,720,false),'concat=n=2:v=1:a=0[v]')&&!str_contains(VideoEditing::concatFilter([4,4],1280,720,false),'[0:a]'),'concat filter without audio');
if(is_executable('/usr/bin/ffmpeg')&&is_executable('/usr/bin/ffprobe')&&function_exists('proc_open')){
    $tmp=sys_get_temp_dir().'/csp_'.getmypid();@mkdir($tmp);$clips=[];
    foreach([['red',2,true],['blue',3,true]] as $i=>[$color,$dur,$aud]){$file="$tmp/c$i.mp4";$args=['/usr/bin/ffmpeg','-v','error','-y','-f','lavfi','-i',"color=c=$color:s=360x640:d=$dur"];if($aud)array_push($args,'-f','lavfi','-i',"sine=frequency=440:duration=$dur");array_push($args,'-c:v','libx264','-pix_fmt','yuv420p','-shortest',$file);VideoEditing::process($args,60);$clips[]=$file;}
    $durs=array_map(fn($c)=>VideoEditing::probe($c)['duration'],$clips);ok(VideoEditing::hasAudio($clips[0]),'audio stream detected');
    $out="$tmp/out.mp4";$args=['/usr/bin/ffmpeg','-nostdin','-v','error','-y'];foreach($clips as $c)array_push($args,'-protocol_whitelist','file,pipe','-f','mov','-enable_drefs','0','-use_absolute_path','0','-i',$c);
    array_push($args,'-filter_complex',VideoEditing::concatFilter($durs,360,640,true),'-map','[v]','-map','[a]','-c:a','aac','-c:v','libx264','-preset','veryfast','-movflags','+faststart',$out);VideoEditing::process($args,120);
    $probe=VideoEditing::probe($out);ok(abs($probe['duration']-array_sum($durs))<0.35&&$probe['width']===360&&$probe['height']===640,'real FFmpeg storyboard render: duration '.round($probe['duration'],2).'s at 360x640');
    $poster=VideoEditing::poster($out,7);ok(is_string($poster)&&PrivateMedia::valid($poster)&&str_ends_with($poster,'.jpg')&&is_file(PrivateMedia::resolve($poster)),'video cover frame written to private media');
    @unlink(PrivateMedia::resolve($poster));
    array_map('unlink',glob("$tmp/*"));@rmdir($tmp);
}else echo "SKIP FFmpeg render (binary unavailable)\n";

// 11) مشاهد السكربت
$clean=VideoScriptService::cleanScenes([['time'=>'0-3s','visual'=>'<b>مشهد</b>','voiceover'=>'جملة','visual_prompt_en'=>'A felucca at sunrise'],'junk',['time'=>'x'],['visual'=>str_repeat('a',900)]]);
ok(count($clean)===2&&$clean[0]['visual']==='مشهد'&&$clean[0]['on_screen_text']===''&&mb_strlen($clean[1]['visual'])===600,'scenes sanitised and bounded');
ok(count(VideoScriptService::cleanScenes(array_fill(0,12,['visual'=>'scene text here'])))===8,'max eight scenes');
ok(VideoScriptService::cleanScenes('nope')===[],'non-array scenes ignored');

// 12) صور المواقع: زاوية مختلفة لكل عنصر
$a=WebsiteBuilderService::itemImagePrompt(['name'=>'Nile Cruise','short_description'=>'4 nights'],'Tours in Egypt','tours',0);
$b=WebsiteBuilderService::itemImagePrompt(['name'=>'Nile Cruise','short_description'=>'4 nights'],'Tours in Egypt','tours',1);
ok($a!==$b&&str_contains($a,'Nile Cruise'),'website item prompts vary by index');
ok(str_contains(WebsiteBuilderService::itemImagePrompt(['name'=>'Deluxe Suite'],'Hotel','hotel'),'hotel room'),'hotel items use a room prompt');
ok(!str_contains($a,'<')&&str_contains($a,'no text'),'website prompt is sanitised and text-free');

// 13) اسم العلامة مايتكتبش في وصف الصورة (الموديل كان بيرسمه على الشراع)
require_once $root.'/app/Core/Model.php';require_once $root.'/app/Models/BrandProfile.php';
final class OfflineBrandProfile extends BrandProfile { public function __construct(array $a){$this->attributes=$a;} }
$bp=new OfflineBrandProfile(['id'=>5,'brand_name'=>'Tourfecto Travel','profile_json'=>json_encode(['business_type'=>'Egypt tour operator']),'palette_json'=>json_encode(['primary'=>'#0F4C81'])]);
$bpPrompt=BrandPromptBuilder::build('A felucca on the Nile at sunrise','photo',$bp,'1:1');
ok(stripos($bpPrompt,'Tourfecto')===false,'brand name never sent to the image model');
ok(str_contains($bpPrompt,'Egypt tour operator')&&str_contains($bpPrompt,'Do not write any brand name'),'business type kept with a no-text instruction');

echo PHP_EOL.'All '.$passed.' Creative Studio Pro checks passed.'.PHP_EOL;
