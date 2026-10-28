<?php
/**
 * End-to-end check of Creative Studio Pro against a REAL MySQL/MariaDB TEST database.
 * Fake AI providers only (no paid calls). Refuses to run unless DB_NAME contains "test".
 * Run on staging/local:  php tools/e2e_creative_studio_pro.php
 */
require __DIR__.'/../cron/bootstrap.php';
if(!str_contains((string)DB_NAME,'test'))exit("Refusing: DB_NAME must be a test database.\n");

$pdo=Database::getInstance()->getConnection();
$U=990001;$passed=0;$cleanup=[];
function ok(bool $c,string $label): void { global $passed; if(!$c)throw new RuntimeException('FAIL: '.$label); $passed++; echo 'PASS '.$label.PHP_EOL; }
function png(int $w,int $h,array $rgb): string { $im=imagecreatetruecolor($w,$h);imagefill($im,0,0,imagecolorallocate($im,$rgb[0],$rgb[1],$rgb[2]));imagefilledellipse($im,(int)($w*0.4),(int)($h*0.4),(int)($w/3),(int)($h/3),imagecolorallocate($im,255-$rgb[0],200,40));ob_start();imagepng($im);$b=ob_get_clean();imagedestroy($im);return $b; }
function mp4(int $w,int $h,int $sec,string $color): string { $f=sys_get_temp_dir().'/e2e_'.bin2hex(random_bytes(5)).'.mp4';VideoEditing::process(['/usr/bin/ffmpeg','-v','error','-y','-f','lavfi','-i',"color=c=$color:s={$w}x{$h}:d=$sec",'-f','lavfi','-i',"sine=frequency=330:duration=$sec",'-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac','-shortest',$f],60);$b=file_get_contents($f);unlink($f);return $b; }
function row(int $id): array { return Database::getInstance()->query('SELECT * FROM media_items WHERE id=?',[$id])[0]; }
function meta(int $id): array { return json_decode((string)row($id)['generation_meta'],true)?:[]; }
function balance(int $u): float { return (float)Database::getInstance()->query("SELECT COALESCE(SUM(amount),0) b FROM wallet_transactions WHERE user_id=? AND status='completed'",[$u])[0]['b']; }

final class FakeImageClient { public array $prompts=[];public array $queue=[];
    public function generateImage(string $prompt,string $ratio,array $meta=[]): array{$this->prompts[]=$prompt;$next=array_shift($this->queue)??[1024,1536,[30,90,140]];return ['success'=>true,'image_base64'=>base64_encode(png($next[0],$next[1],$next[2])),'mime_type'=>'image/png','model'=>'fake-image'];} }
final class FakeVeo { public array $settings=[];public string $bytes='';
    public function startGeneration(string $p,string $r='16:9',int $d=8,array $s=[]): array{$this->settings=$s;return ['success'=>true,'operation_name'=>'models/veo-fake/operations/op123'];}
    public function checkOperation(string $o): array{return ['success'=>true,'done'=>true,'video_uri'=>'https://generativelanguage.googleapis.com/v1beta/files/x'];}
    public function downloadVideo(string $u): array{return ['success'=>true,'data'=>$this->bytes];} }

$reset=function()use($pdo,$U){
    foreach(Database::getInstance()->query('SELECT file_path,thumbnail_path,generation_meta FROM media_items WHERE user_id=?',[$U]) as $r)foreach([$r['file_path'],$r['thumbnail_path'],json_decode((string)$r['generation_meta'],true)['overlay']??null] as $p)if($p&&PrivateMedia::valid((string)$p))try{@unlink(PrivateMedia::resolve((string)$p));}catch(Throwable $e){}
    foreach(['DELETE FROM creative_reservations WHERE user_id=?','DELETE FROM media_items WHERE user_id=?','DELETE FROM wallet_transactions WHERE user_id=?','DELETE FROM video_scripts WHERE user_id=?','DELETE FROM social_posts WHERE user_id=?','DELETE FROM users WHERE id=?'] as $sql)$pdo->prepare($sql)->execute([$U]);
    $pdo->exec("DELETE FROM jobs WHERE payload LIKE '%media_item_id%'");
};
$reset();
$pdo->prepare("INSERT INTO users (id,email,password,company_name) VALUES (?,?,?,?)")->execute([$U,'e2e@studio.test','x','E2E Studio']);
$pdo->exec("DELETE FROM pay_per_use_pricing WHERE feature_key IN ('media_image','media_video')");
$pdo->exec("INSERT INTO pay_per_use_pricing (feature_key,label,price,currency,is_active) VALUES ('media_image','Image',0.50,'USD',1),('media_video','Video',2.00,'USD',1)");
$pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,currency,status,reference_note) VALUES (?,'deposit',20,'USD','completed','e2e')")->execute([$U]);
$svc=new MediaGenerationService();

try{
// 1) موافقة السعر إلزامية لأي خصم من المحفظة
try{$svc->requestGeneration($U,'social_image','A quiet turquoise bay with a wooden boat at sunrise','photo');ok(false,'wallet charge without quote rejected');}catch(InvalidArgumentException $e){ok(str_contains($e->getMessage(),'approved quote'),'wallet charge without approved quote is rejected');}
ok((int)Database::getInstance()->query('SELECT COUNT(*) n FROM media_items WHERE user_id=?',[$U])[0]['n']===0,'rejected request left no item or hold');

// 2) نسختين بإتجاهين مختلفين + حجز
$opts=['aspect_ratio'=>'9:16'];$quote=CreativePricing::quote($U,'image',$opts,2);
ok($quote['ok']&&$quote['wallet_units']===2&&abs($quote['total']-1.0)<0.001,'quote: 2 wallet units at 0.50');
$items=$svc->requestBatch($U,'image',['prompt'=>'A quiet turquoise bay with a wooden boat at sunrise','style'=>'photo','type'=>'story','options'=>$opts,'count'=>2],null,'e2ebatchkey0000000001',$quote['hash']);
ok(count($items)===2,'two variants enqueued');
[$a,$b]=[(int)$items[0]->getAttribute('id'),(int)$items[1]->getAttribute('id')];
ok(row($a)['final_prompt']!==row($b)['final_prompt']&&str_contains(row($b)['final_prompt'],'Alternative take'),'variant 2 prompt differs from variant 1');
ok(stripos(row($a)['final_prompt'],'Soft natural light')===false,'auto lighting adds no forced directive');
$held=Database::getInstance()->query("SELECT COUNT(*) n,SUM(amount) s FROM creative_reservations WHERE user_id=? AND status='held'",[$U])[0];ok((int)$held['n']===2&&abs((float)$held['s']-1.0)<0.001,'two wallet holds placed');
$again=$svc->requestBatch($U,'image',['prompt'=>'A quiet turquoise bay with a wooden boat at sunrise','style'=>'photo','type'=>'story','options'=>$opts,'count'=>2],null,'e2ebatchkey0000000001',$quote['hash']);
ok((int)$again[0]->getAttribute('id')===$a,'resubmitting same request key returns the same items (no duplicate charge)');

// 3) فحص الجودة: محاولة أولى ضعيفة → تصحيح تلقائي → تسليم بمقاس المنصة JPEG وخصم مرة واحدة
$fake=new FakeImageClient();$reviews=[['score'=>4,'issues'=>['garbled text on sign']],['score'=>9,'issues'=>[]]];
$gate=new CreativeQualityGate(function()use(&$reviews){$r=array_shift($reviews)??['score'=>9,'issues'=>[]];return ['success'=>true,'data'=>json_encode($r)];});
(new GenerateMediaJob(fn()=>$fake,$gate))->handle(['media_item_id'=>$a]);
$r=row($a);$m=meta($a);
ok($r['status']==='completed'&&count($fake->prompts)===2&&str_contains($fake->prompts[1],'Corrective re-render'),'QA rejected first render and corrected it once');
ok($m['qa']['attempts']===2&&$m['qa']['score']===9&&$m['qa']['passed'],'QA result stored');
ok((int)$r['width']===1080&&(int)$r['height']===1920&&str_ends_with($r['file_path'],'.jpg'),'story delivered at 1080x1920 JPEG');
ok(is_file(PrivateMedia::resolve($r['file_path']))&&getimagesize(PrivateMedia::resolve($r['file_path']))['mime']==='image/jpeg','file is a real JPEG in private storage');
ok(!empty($m['phash'])&&$m['output']['upscaled'],'phash and upscale recorded');
ok(abs(balance($U)-19.5)<0.001,'charged exactly 0.50 once after success');
ok(Database::getInstance()->query('SELECT status FROM creative_reservations WHERE media_item_id=?',[$a])[0]['status']==='captured','hold captured');
$pub=GenerationOptions::publicItem($r);ok($pub['settings']['qa']['score']===9&&$pub['file_path']==='/api/creative-studio/media/'.$a.'/content','public item exposes QA and private preview URL only');

// 4) نفس الصورة تاني = تنبيه تشابه
$fake2=new FakeImageClient();$gatePass=new CreativeQualityGate(fn()=>['success'=>true,'data'=>'{"score":9}']);
(new GenerateMediaJob(fn()=>$fake2,$gatePass))->handle(['media_item_id'=>$b]);
ok(row($b)['status']==='completed'&&(meta($b)['similar_to']['id']??0)===$a,'near-identical second result flagged as similar to the first');

// 5) رفض الجودة النهائي = فشل بدون خصم ورجوع الحجز
$q1=CreativePricing::quote($U,'image',[],1);$c=$svc->requestBatch($U,'image',['prompt'=>'Family building sandcastles at golden hour on a calm beach','style'=>'photo','type'=>'social_image','options'=>[],'count'=>1],null,'e2ebatchkey0000000002',$q1['hash'])[0];$cid=(int)$c->getAttribute('id');
$before=balance($U);(new GenerateMediaJob(fn()=>new FakeImageClient(),new CreativeQualityGate(fn()=>['success'=>true,'data'=>'{"score":2,"issues":["melted faces"]}'])))->handle(['media_item_id'=>$cid]);
$r=row($cid);ok($r['status']==='failed'&&$r['error_message']===GenerationLifecycle::PUBLIC_MESSAGES['quality'],'hard QA failure ends as failed with quality message');
ok(abs(balance($U)-$before)<0.001&&Database::getInstance()->query('SELECT status FROM creative_reservations WHERE media_item_id=?',[$cid])[0]['status']==='released','no charge and hold released on QA failure');
ok(GenerationOptions::publicItem($r)['error_message']===GenerationLifecycle::PUBLIC_MESSAGES['quality'],'client sees the real reason');

// 6) الحارس: طلب عالق بيتقفل ويرجع الحجز
$q2=CreativePricing::quote($U,'image',[],1);$d=$svc->requestBatch($U,'image',['prompt'=>'Desert safari jeep crossing golden dunes at sunset','style'=>'cinematic','type'=>'social_image','options'=>[],'count'=>1],null,'e2ebatchkey0000000003',$q2['hash'])[0];$did=(int)$d->getAttribute('id');
$pdo->prepare("UPDATE media_items SET generation_stage='processing',updated_at=DATE_SUB(NOW(),INTERVAL 3 HOUR) WHERE id=?")->execute([$did]);
$w=CreativeGenerationWatchdog::run();ok($w['failed']>=1&&row($did)['status']==='failed'&&row($did)['error_message']===GenerationLifecycle::PUBLIC_MESSAGES['stalled'],'watchdog closes stalled request with refund message');
ok(Database::getInstance()->query('SELECT status FROM creative_reservations WHERE media_item_id=?',[$did])[0]['status']==='released','watchdog released the hold');

// 7) فيديو: negativePrompt + أبعاد + غلاف + فيديو عالق في التنزيل مايقفلش العميل
$vq=CreativePricing::quote($U,'video',['avoid'=>'crowds'],1,8);
$v=$svc->requestBatch($U,'video',['prompt'=>'A felucca gliding on the Nile at sunrise with palm trees','style'=>'cinematic','platform'=>'instagram_reels','options'=>['avoid'=>'crowds'],'duration_seconds'=>8,'count'=>1],null,'e2ebatchkey0000000004',$vq['hash'])[0];$vid=(int)$v->getAttribute('id');
ok(!str_contains(row($vid)['final_prompt'],'crowds'),'video avoid list not in positive prompt');
$veo=new FakeVeo();$veo->bytes=mp4(720,1280,2,'orange');$job=new GenerateVideoJob(fn()=>$veo);
$job->handle(['media_item_id'=>$vid]);$job->handle(['media_item_id'=>$vid,'poll_attempt'=>(int)row($vid)['poll_attempts']]);
$r=row($vid);ok($r['status']==='completed'&&(int)$r['width']===720&&(int)$r['height']===1280,'video completed with real dimensions');
ok(str_contains($veo->settings['negative_prompt']??'','crowds'),'negativePrompt passed to Veo');
ok(!empty($r['thumbnail_path'])&&str_ends_with($r['thumbnail_path'],'.jpg')&&is_file(PrivateMedia::resolve($r['thumbnail_path'])),'video cover generated');
$pdo->prepare("INSERT INTO media_items (user_id,type,prompt,aspect_ratio,status,generation_stage,generation_meta) VALUES (?,'short_video','stuck','9:16','generating','download_failed','{}'),(?,'short_video','stuck','9:16','generating','download_failed','{}')")->execute([$U,$U]);
$q3=CreativePricing::quote($U,'image',[],1);$e=$svc->requestBatch($U,'image',['prompt'=>'Luxury hotel suite with sea view and morning light','style'=>'luxury','type'=>'social_image','options'=>[],'count'=>1],null,'e2ebatchkey0000000005',$q3['hash']);
ok(count($e)===1,'two download_failed videos no longer block new requests');
(new GenerateMediaJob(fn()=>new FakeImageClient(),$gatePass))->handle(['media_item_id'=>(int)$e[0]->getAttribute('id')]);

// 8) رفع مرجع: JPEG + نسبة صحيحة + منع التكرار
$up=png(1080,1350,[10,120,90]);$r1=ReferenceMedia::store($U,$up);$r2=ReferenceMedia::store($U,$up);
ok((int)$r1->getAttribute('id')===(int)$r2->getAttribute('id'),'same upload twice is not duplicated');
ok($r1->getAttribute('aspect_ratio')==='4:5'&&str_ends_with((string)$r1->getAttribute('file_path'),'.jpg'),'upload stored as 4:5 JPEG');

// 9) سجل موحّد: صورة مقال تدخل المكتبة مرة واحدة
$art=png(1600,900,[200,160,90]);$i1=CreativeAssetRegistry::register($U,$art,'article','Giza at dawn',['article_id'=>77]);$i2=CreativeAssetRegistry::register($U,$art,'article','Giza at dawn',['article_id'=>78]);
ok($i1&&$i1===$i2&&meta($i1)['source']==='article'&&row($i1)['aspect_ratio']==='16:9','article image registered once in the studio library');

// 10) سكربت → فيديو: حجز لكل مشهد + تجميع حقيقي
$scenes=[['time'=>'0-3s','visual'=>'هوك','voiceover'=>'a','visual_prompt_en'=>'Aerial reveal of a turquoise lagoon with a white yacht, slow forward drone move'],['time'=>'3-8s','visual'=>'تجربة','voiceover'=>'b','visual_prompt_en'=>'Snorkeler gliding above a colourful coral reef, sun rays through water'],['time'=>'8-12s','visual'=>'دعوة','voiceover'=>'c','visual_prompt_en'=>'Couple relaxing on a sunset deck with drinks, gentle dolly in']];
$pdo->prepare("INSERT INTO video_scripts (user_id,topic,platform,duration_seconds,script_text,scenes,status) VALUES (?,?,?,?,?,?,'completed')")->execute([$U,'Red Sea trip','instagram_reels',15,'text',json_encode($scenes,JSON_UNESCAPED_UNICODE)]);$sid=(int)$pdo->lastInsertId();
$sq=CreativePricing::quote($U,'video',[],3,4,true);ok($sq['ok']&&$sq['count']===3&&abs($sq['total']-6.0)<0.001,'storyboard quote for 3 scenes');
$clips=$svc->requestStoryboard($U,$sid,['scenes'=>[1,2,3],'platform'=>'instagram_reels','style'=>'cinematic','options'=>[],'duration_seconds'=>4],null,'e2estoryboardkey00001',$sq['hash']);
ok(count($clips)===3,'three scene clips enqueued despite the 2-active limit');
$group=meta((int)$clips[0]->getAttribute('id'))['storyboard']['group'];
ok(str_contains(row((int)$clips[1]->getAttribute('id'))['final_prompt'],'shot 2 of 3'),'scene prompt carries continuity direction');
foreach($clips as $i=>$cl){$veo=new FakeVeo();$veo->bytes=mp4(720,1280,2,['red','green','blue'][$i]);$j=new GenerateVideoJob(fn()=>$veo);$cid2=(int)$cl->getAttribute('id');$j->handle(['media_item_id'=>$cid2]);$j->handle(['media_item_id'=>$cid2,'poll_attempt'=>(int)row($cid2)['poll_attempts']]);ok(row($cid2)['status']==='completed','scene '.($i+1).' completed');}
$render=VideoEditing::enqueueStoryboard($U,$group,false,'e2erenderkey000000001');(new RenderStoryboardJob())->handle(['media_item_id'=>(int)$render->getAttribute('id')]);
$rr=row((int)$render->getAttribute('id'));$probe=VideoEditing::probe(PrivateMedia::resolve($rr['file_path']));
ok($rr['status']==='completed'&&abs($probe['duration']-6.0)<0.5&&VideoEditing::hasAudio(PrivateMedia::resolve($rr['file_path'])),'storyboard stitched into one ~6s video with audio');
ok(!empty($rr['thumbnail_path'])&&(int)$rr['usage_charged']===1&&empty(Database::getInstance()->query("SELECT 1 FROM wallet_transactions WHERE reference_note=?",['Creative media #'.$rr['id']])),'stitching has a cover and no generation charge');
try{CreativeLibrary::update($U,(int)$clips[0]->getAttribute('id'),['action'=>'trash']);ok(true,'scene can be trashed after render completed');}catch(Throwable $e){ok(false,'scene trash after render');}

// 11) رابط النشر الموقّع (الإعلانات بتستخدمه)
$url=PrivateMedia::publishUrl((new MediaItem())->find($a));ok(str_starts_with($url,'https://studio.test/api/creative-studio/publish-media/'.$a.'?expires='),'signed publish URL for ads/social');
parse_str((string)parse_url($url,PHP_URL_QUERY),$qs);ok(PrivateMedia::verify(row($a),$qs['expires'],$qs['signature']),'signature verifies');

echo PHP_EOL.'All '.$passed.' end-to-end checks passed.'.PHP_EOL;
}finally{$reset();$pdo->exec("DELETE FROM pay_per_use_pricing WHERE feature_key IN ('media_image','media_video')");}
