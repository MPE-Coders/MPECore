<?php
declare(strict_types=1);
require dirname(__DIR__).'/gateway/autoload.php';
use mpe\utils\Binary as B;
use mpe\network\ipc\FrameStream;
use mpe\network\mcpe\BatchCodec;
use mpe\network\mcpe\serializer\ChunkSerializer;
use mpe\network\mcpe\auth\{SessionCipher,Jwt,LoginVerifier};
use mpe\plugin\PluginWorker;
$passed=0;$failed=0;
function check(bool $condition,string $message='assertion failed'):void{if(!$condition){throw new RuntimeException($message);}}
function same(mixed $a,mixed $b):void{check($a===$b,'Not equal: '.var_export($a,true).' vs '.var_export($b,true));}
function rejects(callable $fn):void{try{$fn();}catch(Throwable){return;}throw new RuntimeException('Expected rejection');}
function test(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";}}
function keypair():OpenSSLAsymmetricKey{return openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'secp384r1']);}
function der(OpenSSLAsymmetricKey $key):string{return Jwt::publicDer(openssl_pkey_get_details($key)['key']);}
function waitWorker(PluginWorker $w,callable $done):array{
    $all=[];$until=microtime(true)+3;
    while(microtime(true)<$until){$all=array_merge($all,$w->tick());if($done($all)){return $all;}usleep(1000);}
    throw new RuntimeException('Worker test timed out');
}
test('Unsigned and signed varints',function(){
    foreach([0,1,127,128,16384,2147483647,4294967295] as $v){$o=0;same(B::readUvar(B::uvar($v),$o),$v);}
    foreach([-2147483648,-12,-1,0,1,2147483647] as $v){$o=0;same(B::readSvar(B::svar($v),$o),$v);}
});
test('Malformed varints fail closed',function(){rejects(function(){$o=0;B::readUvar("\xff\xff\xff\xff\xff",$o);});rejects(function(){$o=0;B::readUvar("\x80",$o);});});
test('Fixed little-endian IPC primitives',function(){
    $data=B::u64(0x123456789).B::i32(-123).B::f32(64.5).B::str('Привет');$o=0;
    same(B::readU64($data,$o),0x123456789);same(B::readI32($data,$o),-123);same(B::readF32($data,$o),64.5);same(B::readStr($data,$o),'Привет');same($o,strlen($data));
});
test('Fragmented and coalesced IPC frames',function(){
    $wire=FrameStream::frame("\x01abc").FrameStream::frame("\x02defg");$f=new FrameStream();$out=[];
    foreach(str_split($wire,1) as $b){$out=array_merge($out,$f->feed($b));}same($out,["\x01abc","\x02defg"]);same($f->pending(),0);
});
test('IPC oversized frame rejected',function(){rejects(fn()=>(new FrameStream())->feed(pack('V',FrameStream::LIMIT+1)));rejects(fn()=>FrameStream::frame(''));});
test('Compressed and uncompressed batch roundtrips',function(){
    foreach([false,true] as $c){foreach([["\x01a"],["\x02".str_repeat('x',50000),"\x03test"]] as $p){same(BatchCodec::decode(BatchCodec::encode($p,$c),$c),$p);}}
});
test('Bad compression and packet framing rejected',function(){
    rejects(fn()=>BatchCodec::decode("\x01invalid",true));rejects(fn()=>BatchCodec::decode("\x00not-deflate",true));rejects(fn()=>BatchCodec::decode("\x05ab",false));rejects(fn()=>BatchCodec::decode("\x00",false));
});
test('Singleton palette has no palette-length field',function(){same(ChunkSerializer::storage(str_repeat("\0",4096),[0=>321]),"\x01".B::svar(321));});
test('Grass palette uses Y-fastest, X/Z/Y index order',function(){
    $blocks=str_repeat("\0",4096);for($x=0;$x<16;$x++){for($z=0;$z<16;$z++){for($y=12;$y<16;$y++){$blocks[($x<<8)|($z<<4)|$y]="\x01";}}}
    $s=ChunkSerializer::storage($blocks,[0=>123,1=>4567]);same(ord($s[0]),3);same(unpack('V',substr($s,1,4))[1],0xf000f000);
    $o=513;same(B::readSvar($s,$o),2);same(B::readSvar($s,$o),123);same(B::readSvar($s,$o),4567);same($o,strlen($s));
});
test('Multi-bit palettes pack without word-straddling',function(){
    foreach([3,7,13,25,51,128,256] as $types){
        $blocks='';$map=[];for($i=0;$i<4096;$i++){$blocks.=chr($i%$types);$map[$i%$types]=100+$i%$types;}
        $s=ChunkSerializer::storage($blocks,$map);$bits=ord($s[0])>>1;$per=intdiv(32,$bits);$words=unpack('V*',substr($s,1,(int)ceil(4096/$per)*4));
        for($i=0;$i<4096;$i++){same(($words[intdiv($i,$per)+1]>>(($i%$per)*$bits))&((1<<$bits)-1),$i%$types);}
    }
});
test('Full chunk includes min Y and 24 biome storages',function(){
    $sections=[];for($y=-4;$y<=3;$y++){$sections[$y]=str_repeat("\0",4096);}
    $s=ChunkSerializer::serialize($sections,[0=>0,1=>1]);same($s,str_repeat("\x08\x01\x01\x00",8).str_repeat("\x01\x02",24)."\x00");
    rejects(fn()=>ChunkSerializer::serialize([3=>str_repeat("\0",4096)],[0=>0]));
    rejects(fn()=>ChunkSerializer::storage(str_repeat("\x02",4096),[0=>0]));
});
test('AES-CTR/checksum matches independent Python fixtures',function(){
    $v=json_decode(file_get_contents(__DIR__.'/fixtures/cipher.json'),true,32,JSON_THROW_ON_ERROR);$key=hex2bin($v['key']);$a=new SessionCipher($key);$b=new SessionCipher($key);
    foreach($v['batches'] as $batch){$p=hex2bin($batch['plain']);$c=hex2bin($batch['cipher']);same($a->encrypt($p),$c);same($b->decrypt($c),$p);}
});
test('Cipher rejects tamper, truncation and replay',function(){
    $key=random_bytes(32);$a=new SessionCipher($key);$cipher=$a->encrypt('hello');
    $b=new SessionCipher($key);same($b->decrypt($cipher),'hello');rejects(fn()=>$b->decrypt($cipher));
    $cipher[1]=chr(ord($cipher[1])^1);rejects(fn()=>(new SessionCipher($key))->decrypt($cipher));rejects(fn()=>(new SessionCipher($key))->decrypt('123'));
});
test('ES384 sign/verify and JOSE/DER conversion',function(){
    $k=keypair();$token=Jwt::sign(['hello'=>'мир','exp'=>time()+300],$k);same(Jwt::verifyEc($token,der($k),true)['hello'],'мир');
    [$h,$p,$signed,$sig]=Jwt::parse($token);same(Jwt::derToJose(Jwt::joseToDer($sig)),$sig);
    $sig[0]=chr(ord($sig[0])^1);rejects(fn()=>Jwt::verifyEc($signed.'.'.B::b64url($sig),der($k)));
});
test('JWT wrong curve, expiry and algorithm are rejected',function(){
    $k=keypair();rejects(fn()=>Jwt::verifyEc(Jwt::sign(['exp'=>time()-100],$k),der($k),true));
    rejects(fn()=>Jwt::verifyEc(Jwt::sign(['nbf'=>time()+200],$k),der($k)));
    $wrong=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);rejects(fn()=>Jwt::verifyEc(Jwt::sign(['a'=>1],$k),der($wrong)));
    $token=B::b64url('{"alg":"none"}').'.'.B::b64url('{}').'.'.B::b64url(str_repeat('x',96));rejects(fn()=>Jwt::verifyEc($token,der($k)));
});
test('ECDH agreement agrees both directions',function(){
    $a=keypair();$b=keypair();same(openssl_pkey_derive(Jwt::pem(der($a)),$b,48),openssl_pkey_derive(Jwt::pem(der($b)),$a,48));
});
$identity=['displayName'=>'UnitPlayer','identity'=>'12345678-1234-4234-9234-123456789abc','XUID'=>'999999'];
$clientKey=keypair();$clientDer=der($clientKey);$skin=Jwt::sign(['GameVersion'=>'1.20.80'],$clientKey);
$chainToken=Jwt::sign(['identityPublicKey'=>base64_encode($clientDer),'extraData'=>$identity,'exp'=>time()+300,'nbf'=>time()-1],$clientKey);
$request=['protocol'=>671,'online'=>false,'auth_json'=>json_encode(['chain'=>[$chainToken]]),'client_jwt'=>$skin,'key_cache'=>'/not/used'];
test('Legacy self-signed login remains OFFLINE; key agreement proven',function()use($request,$clientKey){
    $result=LoginVerifier::verify($request);same($result['name'],'UnitPlayer');same($result['authenticated'],false);same($result['xuid'],'');
    [$h,$p]=Jwt::parse($result['handshake']);$serverDer=Jwt::decodeKey($h['x5u']);Jwt::verifyEc($result['handshake'],$serverDer);
    $secret=openssl_pkey_derive(Jwt::pem($serverDer),$clientKey,48);same(base64_decode($result['key']),hash('sha256',base64_decode($p['salt']).$secret,true));
});
test('Online mode refuses a cryptographically valid offline identity',function()use($request){$request['online']=true;rejects(fn()=>LoginVerifier::verify($request));});
test('Client JWT must prove possession of identity key',function()use($request){$request['client_jwt']=Jwt::sign(['GameVersion'=>'1.20.80'],keypair());rejects(fn()=>LoginVerifier::verify($request));});
test('Modern 975 self-signed token route',function()use($clientKey,$clientDer,$skin,$identity){
    $token=Jwt::sign(['leguuid'=>$identity['identity'],'xname'=>'UnitPlayer','cpk'=>base64_encode($clientDer),'exp'=>time()+300],$clientKey);
    $r=['protocol'=>975,'online'=>false,'auth_json'=>json_encode(['AuthenticationType'=>2,'Token'=>$token]),'client_jwt'=>$skin,'key_cache'=>''];
    same(LoginVerifier::verify($r)['authenticated'],false);$r['online']=true;rejects(fn()=>LoginVerifier::verify($r));
});
test('Modern RS256 online JWT, issuer/audience/signature validation',function()use($clientDer,$skin){
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);$details=openssl_pkey_get_details($key);
    $cache=tempnam(sys_get_temp_dir(),'mpe-keys-');$issuer='https://test.invalid';$kid='unit-key';
    $jwk=['kty'=>'RSA','use'=>'sig','alg'=>'RS256','kid'=>$kid,'n'=>B::b64url($details['rsa']['n']),'e'=>B::b64url($details['rsa']['e'])];
    file_put_contents($cache,json_encode(['fetched_at'=>time(),'issuer'=>$issuer,'keys'=>[$kid=>$jwk]]));
    $sign=static function(array $claims)use($key,$kid):string{$s=B::b64url(json_encode(['alg'=>'RS256','kid'=>$kid])).'.'.B::b64url(json_encode($claims));openssl_sign($s,$sig,$key,OPENSSL_ALGO_SHA256);return $s.'.'.B::b64url($sig);};
    $claims=['iss'=>$issuer,'aud'=>LoginVerifier::AUDIENCE,'exp'=>time()+300,'xid'=>'1234','xname'=>'UnitPlayer','cpk'=>base64_encode($clientDer)];
    $r=['protocol'=>1001,'online'=>true,'client_jwt'=>$skin,'key_cache'=>$cache];
    try{
        $r['auth_json']=json_encode(['AuthenticationType'=>0,'Token'=>$sign($claims)]);$result=LoginVerifier::verify($r);same($result['authenticated'],true);same($result['xuid'],'1234');
        $claims['aud']='other';$r['auth_json']=json_encode(['AuthenticationType'=>0,'Token'=>$sign($claims)]);rejects(fn()=>LoginVerifier::verify($r));
        $claims['aud']=LoginVerifier::AUDIENCE;$claims['iss']='https://evil.invalid';$r['auth_json']=json_encode(['AuthenticationType'=>0,'Token'=>$sign($claims)]);rejects(fn()=>LoginVerifier::verify($r));
    }finally{unlink($cache);}
});
test('Player UUID conversion is deterministic',function(){same(LoginVerifier::uuidFromXuid('1'),LoginVerifier::uuidFromXuid('1'));check(LoginVerifier::uuidFromXuid('1')!==LoginVerifier::uuidFromXuid('2'));check(LoginVerifier::uuidFromXuid('1')[14]==='3');});
test('PHP Welcome host dispatches Join and clean Disable',function(){
    $root=dirname(__DIR__);$w=new PluginWorker('Welcome',$root.'/plugins/Welcome',$root);
    try{
        $w->event('join',['sid'=>12,'name'=>'UnitPlayer','uuid'=>'12345678-1234-4234-9234-123456789abc']);
        $messages=waitWorker($w,static fn($rows)=>count(array_filter($rows,static fn($m)=>($m['op']??'')==='message'))>=2);
        $texts=array_values(array_filter($messages,static fn($m)=>($m['op']??'')==='message'));same($texts[0]['sid'],12);check(str_contains($texts[0]['message'],'UnitPlayer'));
        $w->requestReload();waitWorker($w,static fn($rows)=>$w->stopped());
    }finally{$w->stop();}
});
test('Hot reload creates a fresh PHP class definition in a new process',function(){
    $root=dirname(__DIR__);$dir=sys_get_temp_dir().'/mpe-plugin-'.bin2hex(random_bytes(5));mkdir($dir);$w=null;
    file_put_contents($dir.'/plugin.json',json_encode(['name'=>'UnitReload','api'=>'0.1','entry'=>'Main.php','main'=>'ReloadUnit']));
    $source=static fn($v)=>'<?php class ReloadUnit extends \\mpe\\plugin\\PluginBase { public function onJoin(\\mpe\\event\\player\\PlayerJoinEvent $e):void{$e->getPlayer()->sendMessage("'.$v.'");} }';
    try{
        foreach(['before','after'] as $version){
            file_put_contents($dir.'/Main.php',$source($version));$w=new PluginWorker('UnitReload',$dir,$root);$w->event('join',['sid'=>1,'name'=>'Test','uuid'=>'a']);
            $rows=waitWorker($w,static fn($rows)=>count(array_filter($rows,static fn($m)=>($m['op']??'')==='message'))>0);
            $texts=array_values(array_filter($rows,static fn($m)=>($m['op']??'')==='message'));same($texts[0]['message'],$version);$w->stop();
        }
    }finally{$w?->stop();unlink($dir.'/plugin.json');unlink($dir.'/Main.php');rmdir($dir);}
});
test('Configuration validation and control-character sanitization',function(){
    $c=\mpe\utils\Config::load(dirname(__DIR__));check(is_int($c['port'])&&$c['port']>=1&&$c['port']<=65535);same(B::clean("abc\033\n\0"),'abc');
});
test('All 31 authentication profiles: offline identities never become authenticated',function()use($clientKey,$clientDer,$skin,$chainToken,$identity){
    $catalog=json_decode(file_get_contents(dirname(__DIR__).'/resources/protocol-catalog.json'),true,64,JSON_THROW_ON_ERROR);
    foreach($catalog as $v){
        $id=$v['protocol'];$info=['chain'=>[$chainToken]];
        if($id>=818){$info=['AuthenticationType'=>2,'Certificate'=>json_encode($info),'Token'=>''];}
        if($id>=944){$info['Token']=Jwt::sign(['cpk'=>base64_encode($clientDer),'xname'=>'UnitPlayer','leguuid'=>$identity['identity'],'exp'=>time()+300],$clientKey);}
        $r=['protocol'=>$id,'online'=>false,'auth_json'=>json_encode($info),'client_jwt'=>$skin,'key_cache'=>'unused'];
        $result=LoginVerifier::verify($r);same($result['name'],'UnitPlayer');same($result['authenticated'],false);
        $r['online']=true;rejects(fn()=>LoginVerifier::verify($r));
    }
});
test('Canonical mappings are typed, explicit, and reject ambiguous variants',function(){
    $defs=[['id'=>0,'name'=>'test','variants'=>[['name'=>'a','states'=>['v'=>[1,1]]]]]];
    same(\mpe\network\mcpe\convert\CanonicalBlockRegistry::resolve(['a|{"v":[1,1]}'=>19],$defs),[19]);
    rejects(fn()=>\mpe\network\mcpe\convert\CanonicalBlockRegistry::resolve(['a|{"v":[3,1]}'=>19],$defs));
    $defs[0]['variants'][]=['name'=>'b','states'=>[]];
    rejects(fn()=>\mpe\network\mcpe\convert\CanonicalBlockRegistry::resolve(['a|{"v":[1,1]}'=>19,'b|[]'=>20],$defs));
});
test('All protocol profiles use explicit safe filenames and separate item/block aliases',function(){
    $root=dirname(__DIR__);$files=glob($root.'/resources/protocols/*.json');same(count($files),31);
    foreach($files as $f){$raw=json_decode(file_get_contents($f),true);if($raw['protocol']>1001){rejects(fn()=>new \mpe\network\mcpe\convert\ProtocolProfile($f));continue;}$p=new \mpe\network\mcpe\convert\ProtocolProfile($f);foreach(['block_palette','block_meta','items'] as $k){same(basename($p->data[$k]),$p->data[$k]);}}
    $p=new \mpe\network\mcpe\convert\ProtocolProfile($root.'/resources/protocols/800.json');
    same($p->data['block_palette'],'canonical_block_states-1.21.93.nbt');same($p->data['items'],'required_item_list-1.21.80.json');
});
test('Extended chunks preserve contiguous sections and reject holes',function(){
    $sections=[];for($i=-4;$i<=19;$i++){$sections[$i]=str_repeat("\0",4096);}check(strlen(ChunkSerializer::serialize($sections,[0=>777]))>0);
    unset($sections[0]);rejects(fn()=>ChunkSerializer::serialize($sections,[0=>777]));
});
test('Public test-mode and silently enabled experimental protocols are refused',function(){
    $root=dirname(__DIR__);$file=tempnam(sys_get_temp_dir(),'mpe-config-');$old=getenv('MPE_CONFIG');
    try{putenv('MPE_CONFIG='.$file);file_put_contents($file,json_encode(['test-mode'=>true,'host'=>'0.0.0.0']));rejects(fn()=>\mpe\utils\Config::load($root));
        file_put_contents($file,json_encode(['protocols'=>[2193],'advertise-protocol'=>2193]));rejects(fn()=>\mpe\utils\Config::load($root));
        file_put_contents($file,json_encode(['test-mode'=>true,'host'=>'127.0.0.1']));same(\mpe\utils\Config::load($root)['test-mode'],true);
    }finally{unlink($file);$old===false?putenv('MPE_CONFIG'):putenv('MPE_CONFIG='.$old);}
});
test('PHP command plugin runs in a real isolated worker with position snapshot',function(){
    $root=dirname(__DIR__);$w=new PluginWorker('Commands',$root.'/plugins/Commands',$root);
    try{$w->event('command',['sid'=>5,'name'=>'Unit','uuid'=>'test','position'=>[1.25,64.0,3.5],'command'=>'whereami','arguments'=>[]]);
        $rows=waitWorker($w,static fn($rows)=>count(array_filter($rows,static fn($m)=>($m['op']??'')==='message'))>0);
        $messages=array_values(array_filter($rows,static fn($m)=>($m['op']??'')==='message'));check(str_contains($messages[0]['message'],'1.25 64.00 3.50'));
    }finally{$w->stop();}
});
test('Creative inventory seeds eleven blocks and validates hotbar selection',function(){
    $i=new \mpe\inventory\PlayerInventory();same(count($i->contents()),36);same($i->held()->block,1);same($i->get(10)->block,11);same($i->get(11)->count,0);
    check(!$i->select(9));check(!$i->select(-1));check($i->select(6));same($i->held()->block,7);
});
test('Inventory move uses authoritative stack IDs and preserves total count',function(){
    $i=new \mpe\inventory\PlayerInventory();$id=$i->get(0)->networkId;
    $r=$i->request(-1,[['type'=>'move','source'=>[28,0,$id],'destination'=>[29,11,0],'count'=>20]],true);
    same($i->get(0)->count,44);same($i->get(11)->count,20);same($i->get(11)->block,1);same(count($r),2);
});
test('Inventory stale IDs and request replay do not change slots',function(){
    $i=new \mpe\inventory\PlayerInventory();$id=$i->get(0)->networkId;
    $actions=[['type'=>'move','source'=>[28,0,$id],'destination'=>[29,11,0],'count'=>20]];
    $i->request(-1,$actions,true);$before=$i->contents();rejects(fn()=>$i->request(-1,$actions,true));same($i->contents(),$before);
    rejects(fn()=>$i->request(-2,$actions,true));same($i->contents(),$before);
});
test('Inventory atomic rollback includes multi-action failures',function(){
    $i=new \mpe\inventory\PlayerInventory();$before=$i->contents();
    rejects(fn()=>$i->request(-1,[['type'=>'move','source'=>[28,0,1],'destination'=>[59,0,0],'count'=>64],['type'=>'unsupported']],true));
    same($i->contents(),$before);same($i->cursor()->count,0);
    $i->request(-2,[['type'=>'move','source'=>[28,0,1],'destination'=>[59,0,0],'count'=>64]],true);same($i->cursor()->networkId,12);
});
test('Inventory supports predicted request IDs across cursor transfers',function(){
    $i=new \mpe\inventory\PlayerInventory();
    $i->request(-1,[['type'=>'move','source'=>[28,0,1],'destination'=>[59,0,0],'count'=>64]],true);
    $i->request(-2,[['type'=>'move','source'=>[59,0,-1],'destination'=>[29,12,0],'count'=>64]],true);
    same($i->get(12)->block,1);same($i->get(12)->count,64);same($i->cursor()->count,0);
});
test('Creative catalog request and same-request output transfer',function(){
    $i=new \mpe\inventory\PlayerInventory();
    $i->request(-10,[['type'=>'creative','block'=>7],['type'=>'output','index'=>0],['type'=>'move','source'=>[60,50,-10],'destination'=>[29,12,0],'count'=>64]],true);
    same($i->get(12)->block,7);same($i->get(12)->count,64);
});
test('Creative creation cannot inject an unregistered canonical item',function(){
    $i=new \mpe\inventory\PlayerInventory();$before=$i->contents();
    foreach([0,12,255,-1] as $block){rejects(fn()=>$i->request(-1,[['type'=>'creative','block'=>$block]],true));}
    rejects(fn()=>$i->request(-1,[['type'=>'creative','block'=>7]],false));same($i->contents(),$before);
});
test('Inventory rejects overflow, mismatched merge, invalid UI container and slot',function(){
    $i=new \mpe\inventory\PlayerInventory();$before=$i->contents();
    rejects(fn()=>$i->request(-1,[['type'=>'move','source'=>[28,0,1],'destination'=>[28,1,2],'count'=>1]],true));
    rejects(fn()=>$i->request(-1,[['type'=>'move','source'=>[28,0,1],'destination'=>[29,12,0],'count'=>65]],true));
    foreach([[5,0],[28,9],[29,36],[59,1],[60,0]] as [$c,$s]){rejects(fn()=>\mpe\inventory\PlayerInventory::key($c,$s));}
    same($i->contents(),$before);
});
test('Creative inventory swap and delete clear network ID on empty stacks',function(){
    $i=new \mpe\inventory\PlayerInventory();
    $i->request(-1,[['type'=>'swap','source'=>[28,0,1],'destination'=>[28,1,2]]],true);same($i->get(0)->block,2);
    $i->request(-2,[['type'=>'destroy','source'=>[28,0,-1],'count'=>64]],true);same($i->get(0)->networkId,0);
    rejects(fn()=>$i->request(-3,[['type'=>'destroy','source'=>[28,1,-1],'count'=>1]],false));
});
test('Item maps keep item and block runtime namespaces separate and allow signed item IDs',function(){
    $defs=[['id'=>1,'name'=>'grass','item_names'=>['minecraft:grass_block','minecraft:grass'],'item_meta'=>0]];
    $m=new \mpe\network\mcpe\convert\ItemMap(['minecraft:grass'=>['runtime_id'=>-42]],$defs);same($m->get(1)['id'],-42);same($m->get(1)['meta'],0);
    rejects(fn()=>new \mpe\network\mcpe\convert\ItemMap([],$defs));rejects(fn()=>$m->get(2));
});
test('All nonair canonical blocks have explicit item candidates',function(){
    $defs=json_decode(file_get_contents(dirname(__DIR__).'/resources/blocks.json'),true,64,JSON_THROW_ON_ERROR);
    foreach($defs as $d){if($d['id']!==0){check(count($d['item_names'])>=1);check(is_int($d['item_meta']));}}
});
test('Playtest journal rotates, excludes raw packet fields and bounds strings',function(){
    $dir=sys_get_temp_dir().'/mpe-journal-'.bin2hex(random_bytes(5));mkdir($dir);$file=$dir.'/playtest.jsonl';
    try{$j=new \mpe\utils\PlaytestJournal($file,128);$j->append(1,1001,'play','movement_accepted',str_repeat('a',1024));
        $row=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);same(strlen($row['detail']),512);same(array_keys($row),['at','run','sid','protocol','phase','event','detail']);
        $j->append(1,1001,'play','interaction_committed','game_1 2,64,0=7');check(is_file($file.'.1'));rejects(fn()=>$j->append(1,1,'play','bad\nevent','x'));
    }finally{foreach(glob($dir.'/*') as $p){unlink($p);}rmdir($dir);}
});
test('PHP block change notification dispatches in a real worker AFTER-commit API',function(){
    $root=dirname(__DIR__);$dir=sys_get_temp_dir().'/mpe-block-plugin-'.bin2hex(random_bytes(5));mkdir($dir);$w=null;
    file_put_contents($dir.'/plugin.json',json_encode(['name'=>'UnitBlock','api'=>'0.1','entry'=>'Main.php','main'=>'UnitBlock']));
    file_put_contents($dir.'/Main.php','<?php class UnitBlock extends \\mpe\\plugin\\PluginBase { public function onBlockChange(\\mpe\\event\\block\\BlockChangeEvent $e):void{$e->getPlayer()->sendMessage("committed:".$e->getBlockId().":".$e->getPosition()->x);} }');
    try{$w=new PluginWorker('UnitBlock',$dir,$root);$w->event('block_change',['sid'=>7,'name'=>'Test','uuid'=>'u','x'=>2,'y'=>64,'z'=>0,'block'=>7,'previous'=>0]);
        $rows=waitWorker($w,static fn($r)=>count(array_filter($r,static fn($m)=>($m['op']??'')==='message'))>0);
        $messages=array_values(array_filter($rows,static fn($m)=>($m['op']??'')==='message'));same($messages[0]['message'],'committed:7:2');
    }finally{$w?->stop();unlink($dir.'/Main.php');unlink($dir.'/plugin.json');rmdir($dir);}
});
require __DIR__.'/data_unit.php';
echo "\n$passed passed; $failed failed. Dependencies/native client integration are separate tests.\n";
exit($failed===0?0:1);
