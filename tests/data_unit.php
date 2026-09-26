<?php
// Loaded by unit.php. All fixtures are local and deliberately NOT upstream palettes.
use mpe\data\ProfileGuard;
use mpe\data\AssetIntegrity;
function nativeProfile():array{return json_decode(file_get_contents(dirname(__DIR__).'/resources/protocols/1001.json'),true,64,JSON_THROW_ON_ERROR);}
test('Native data profiles pin codec/data references; 2193 has a separate pinned data contract',function(){
    $native=0;$blocked=0;$modern=0;
    foreach(glob(dirname(__DIR__).'/resources/protocols/*.json') as $file){
        $p=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if($p['protocol']<=1001){ProfileGuard::validate($p);$native++;}
        elseif($p['protocol']===2193){ProfileGuard::validate($p);$modern++;}
        else{rejects(fn()=>ProfileGuard::validate($p));$blocked++;}
    }
    same($native,28);same($blocked,2);same($modern,1);
});
test('Profiles reject paths, wrong codec and wrong world height',function(){
    foreach([['schema_version',3],['items','../escape'],['items','nested/path'],['items',"bad\0name"],['data_reference','master'],['min_section',0],['codec_base',671],['codec','prismarine-transcode']] as [$key,$value]){
        $p=nativeProfile();$p[$key]=$value;rejects(fn()=>ProfileGuard::validate($p));
    }
});
test('Accepted protocol list is exact, not a numeric interval',function(){
    ProfileGuard::accepted(671,[589,671,1001]);
    rejects(fn()=>ProfileGuard::accepted(670,[589,671,1001]));
    rejects(fn()=>ProfileGuard::accepted(671,['671']));
});
test('2193 contract rejects invented protocol, mismatched data and unsafe asset paths',function(){
    $p=json_decode(file_get_contents(dirname(__DIR__).'/resources/protocols/2193.json'),true,64,JSON_THROW_ON_ERROR);
    ProfileGuard::validate($p);ProfileGuard::accepted(2193,[1001]);
    rejects(fn()=>ProfileGuard::accepted(2193,[975]));rejects(fn()=>ProfileGuard::accepted(12193,[1001]));
    foreach([['protocol',12193],['data_reference',str_repeat('a',40)],['block_palette','canonical_block_states.nbt'],['items','../escape'],['codec_base',975]] as [$key,$value]){
        $bad=$p;$bad[$key]=$value;rejects(fn()=>ProfileGuard::validate($bad));
    }
});
test('Metadata order and NBT companion types are not coerced',function(){
    ProfileGuard::metadata([0,1,2],3);rejects(fn()=>ProfileGuard::metadata([0,1],3));
    rejects(fn()=>ProfileGuard::metadata([1=>0,2=>1],2));rejects(fn()=>ProfileGuard::metadata([true],1));
});
test('Item dictionary rejects duplicate IDs, booleans as IDs and strings as flags',function(){
    $a=['runtime_id'=>-42,'component_based'=>false];ProfileGuard::items(['minecraft:test'=>$a]);
    rejects(fn()=>ProfileGuard::items(['minecraft:a'=>$a,'minecraft:b'=>$a]));
    foreach([['runtime_id',true],['runtime_id',32768],['component_based','false'],['version','2']] as [$k,$v]){
        $b=$a;$b[$k]=$v;rejects(fn()=>ProfileGuard::items(['minecraft:test'=>$b]));
    }
});
test('Source references cannot silently mix different upstream snapshots',function(){
    AssetIntegrity::references(['a'=>'123'],['a'=>'123']);
    rejects(fn()=>AssetIntegrity::references(['a'=>'123'],['a'=>'124']));
    rejects(fn()=>AssetIntegrity::references(['a'=>'123'],[]));
});
test('Asset fingerprints commit atomically and reject subsequent data drift',function(){
    $dir=sys_get_temp_dir().'/mpe-assets-'.bin2hex(random_bytes(6));mkdir($dir);mkdir($dir.'/assets');
    $p=json_decode(file_get_contents(dirname(__DIR__).'/resources/protocols/975.json'),true,32,JSON_THROW_ON_ERROR);$refs=['nethergamesmc/bedrock-data'=>$p['data_reference'],'nethergamesmc/bedrock-protocol'=>$p['codec_reference']];
    try{
        foreach(ProfileGuard::ASSETS as $key){file_put_contents($dir.'/assets/'.$p[$key],'fixture-'.$key);}
        $a=new AssetIntegrity($dir,$refs);$rows=$a->profile($p,$dir.'/assets');same(count($rows),5);
        check(!is_file($dir.'/data/bedrock-assets.lock.json'));$a->commit();
        same($rows,(new AssetIntegrity($dir,$refs))->profile($p,$dir.'/assets'));
        file_put_contents($dir.'/assets/'.$p['items'],'modified');
        rejects(fn()=>(new AssetIntegrity($dir,$refs))->profile($p,$dir.'/assets'));
        unlink($dir.'/assets/'.$p['items']);rejects(fn()=>(new AssetIntegrity($dir,$refs))->profile($p,$dir.'/assets'));
    }finally{foreach(glob($dir.'/assets/*') as $file){unlink($file);}rmdir($dir.'/assets');foreach(glob($dir.'/data/*') as $file){unlink($file);}if(is_dir($dir.'/data'))rmdir($dir.'/data');rmdir($dir);}
});
test('Minecraft violation diagnostics never echo client text or tokens',function(){
    $msg="secret-token\nFAKE LOG";
    $s=\mpe\network\mcpe\ClientPacketNotice::summary(0,2,11,$msg);
    check(str_contains($s,'packet=0xb'));check(!str_contains($s,'secret'));check(!str_contains($s,"\n"));
    check(str_contains($s,hash('sha256',$msg)));
});
test('2193 biome guard rejects the old hash-consistent one-entry/id=1 registry',function(){
    rejects(fn()=>\mpe\data\ModernData::biomes(['minecraft:plains'=>['id'=>1]]));
    $fixture=['minecraft:plains'=>['id'=>65535]];
    for($i=1;$i<89;$i++){$fixture['minecraft:fixture_'.$i]=['id'=>65535];}
    \mpe\data\ModernData::biomes($fixture);
    $fixture['minecraft:plains']['id']=1;rejects(fn()=>\mpe\data\ModernData::biomes($fixture));
    $fixture['minecraft:plains']['id']='65535';rejects(fn()=>\mpe\data\ModernData::biomes($fixture));
});
