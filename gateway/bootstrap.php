<?php
declare(strict_types=1);
require __DIR__.'/autoload.php';
use mpe\network\mcpe\auth\AuthKeyCache;
use mpe\utils\Config;
$root=dirname(__DIR__);
try {
    if(($argv[1]??'')==='--refresh-auth'){
        AuthKeyCache::refresh($root.'/data/auth-keys.json');echo "Minecraft signing keys refreshed.\n";exit(0);
    }
    $config=Config::load($root);
    if($config['online-mode']&&count(array_filter($config['protocols'],static fn($p)=>$p>=818))>0){
        try{AuthKeyCache::load($root.'/data/auth-keys.json');}
        catch(Throwable){
            fwrite(STDOUT,"Fetching trusted Minecraft authentication keys before binding UDP...\n");
            AuthKeyCache::refresh($root.'/data/auth-keys.json');
        }
    }
    (new \mpe\Server($root,$config))->run();
}catch(Throwable $e){
    fwrite(STDERR,"MPE-Core startup/runtime error: ".$e::class.': '.$e->getMessage()."\n");
    fwrite(STDERR,$e->getTraceAsString()."\n");exit(1);
}
