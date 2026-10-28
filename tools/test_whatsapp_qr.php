<?php
/** Pure tests for the WhatsApp QR gateway adapter (no network, no DB). */
require_once __DIR__.'/../app/Services/Chat/WhatsAppQrGateway.php';
function wqCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL; }
$in=fn($jid,$fromMe,$msg)=>['event'=>'messages.upsert','data'=>['key'=>['remoteJid'=>$jid,'fromMe'=>$fromMe,'id'=>'ABC'],'pushName'=>'Sarah','message'=>$msg]];
$m=WhatsAppQrGateway::parseIncoming($in('201001234567@s.whatsapp.net',false,['conversation'=>' Hi! ']));
wqCheck($m['phone']==='201001234567'&&$m['text']==='Hi!'&&$m['id']==='ABC'&&$m['name']==='Sarah','customer text message parsed');
wqCheck(WhatsAppQrGateway::parseIncoming($in('201001234567@s.whatsapp.net',false,['extendedTextMessage'=>['text'=>'Link msg']]))['text']==='Link msg','extended text parsed');
wqCheck(WhatsAppQrGateway::parseIncoming($in('201001234567@s.whatsapp.net',false,['imageMessage'=>['caption'=>'Photo q']]))['text']==='Photo q','image caption parsed');
wqCheck(WhatsAppQrGateway::parseIncoming($in('201001234567@s.whatsapp.net',true,['conversation'=>'x']))===null,'own messages ignored');
wqCheck(WhatsAppQrGateway::parseIncoming($in('120363@g.us',false,['conversation'=>'x']))===null,'group messages ignored');
wqCheck(WhatsAppQrGateway::parseIncoming($in('status@broadcast',false,['conversation'=>'x']))===null,'status broadcasts ignored');
wqCheck(WhatsAppQrGateway::parseIncoming($in('201001234567@s.whatsapp.net',false,['audioMessage'=>[]]))===null,'non-text without caption ignored');
wqCheck(WhatsAppQrGateway::parseIncoming(['event'=>'MESSAGES_UPSERT','data'=>[['key'=>['remoteJid'=>'4477@s.whatsapp.net'],'message'=>['conversation'=>'x']]]])===null,'too-short numbers ignored');
wqCheck(WhatsAppQrGateway::parseIncoming(['event'=>'qrcode.updated','data'=>[]])===null,'other events ignored');
wqCheck(WhatsAppQrGateway::parseState(['instance'=>['state'=>'open']])==='open'&&WhatsAppQrGateway::parseState(['instance'=>['state'=>'close']])==='close'&&WhatsAppQrGateway::parseState(['instance'=>['state'=>'connecting']])==='connecting','connection states');
wqCheck(WhatsAppQrGateway::parseConnectionEvent(['event'=>'CONNECTION_UPDATE','data'=>['state'=>'close']])==='close','logout event');
$q=WhatsAppQrGateway::parseQr(['base64'=>'data:image/png;base64,iVBORw0KGgo=','pairingCode'=>'WZYE-H1YY']);
wqCheck($q['qr']==='data:image/png;base64,iVBORw0KGgo='&&$q['pairing_code']==='WZYE-H1YY','QR image and pairing code');
wqCheck(WhatsAppQrGateway::parseQr(['base64'=>'javascript:alert(1)'])['qr']===null,'unsafe QR payload rejected');
wqCheck(WhatsAppQrGateway::parseQr(['base64'=>'iVBORw0KGgo='])['qr']==='data:image/png;base64,iVBORw0KGgo=','raw base64 wrapped as PNG');
$p=WhatsAppQrGateway::parseProfile([['ownerJid'=>'201005556677@s.whatsapp.net','profileName'=>'Nile Tours']]);
wqCheck($p['phone']==='201005556677'&&$p['name']==='Nile Tours','profile number and name');
wqCheck(WhatsAppQrGateway::instanceName(7)!==WhatsAppQrGateway::instanceName(8)&&str_starts_with(WhatsAppQrGateway::instanceName(7),'tf7_'),'stable per-website session names');
$calls=[];$gw=new WhatsAppQrGateway('https://gw.example','k',function($m,$u,$b,$h)use(&$calls){$calls[]=[$m,$u,$b,$h];return ['code'=>201,'body'=>'{"key":{"id":"X1"}}'];});
$r=$gw->sendText('tf7_x','+20 100 123 4567','Hello');
wqCheck($r['ok']&&$calls[0][1]==='https://gw.example/message/sendText/tf7_x'&&$calls[0][2]['number']==='201001234567'&&in_array('apikey: k',$calls[0][3],true),'send uses digits-only number and platform key header');
wqCheck(!(new WhatsAppQrGateway('https://gw.example','k',fn()=>['code'=>401,'body'=>'']))->ping()['ok'],'rejected key reported');
echo 'All WhatsApp QR checks passed.'.PHP_EOL;
