<?php
declare(strict_types=1);
namespace mpe\network\mcpe\auth;
/** Keys are never downloaded from a URL supplied by a player or JWT header. */
final class AuthKeyCache {
    public const BASE='https://authorization.franchise.minecraft-services.net';
    private static function fetch(string $url):array {
        $parts=parse_url($url);$host=$parts['host']??'';
        if(($parts['scheme']??'')!=='https'||($host!=='minecraft-services.net'&&!str_ends_with($host,'.minecraft-services.net'))||isset($parts['user'])||isset($parts['port'])&&$parts['port']!==443){throw new \RuntimeException('Untrusted auth service URL');}
        $context=stream_context_create(['http'=>['timeout'=>8,'follow_location'=>0,'header'=>"Accept: application/json\r\nUser-Agent: MPE-Core/0.1\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $bytes=@file_get_contents($url,false,$context,0,1048577);
        if($bytes===false||strlen($bytes)>1048576){throw new \RuntimeException('Cannot fetch Minecraft authentication keys (TLS/network)');}
        $data=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);if(!is_array($data)){throw new \RuntimeException('Invalid auth JSON');}return $data;
    }
    public static function load(string $path):array {
        if(!is_file($path)){throw new \RuntimeException('Auth key cache missing; run ./start.sh --refresh-auth');}
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(($data['fetched_at']??0)<time()-86400){throw new \RuntimeException('Auth keys older than 24h; run ./start.sh --refresh-auth');}
        if(!isset($data['issuer'],$data['keys'])){throw new \RuntimeException('Invalid auth cache');}return $data;
    }
    public static function refresh(string $path):void {
        $base=self::BASE;
        try{$discovery=self::fetch('https://client.discovery.minecraft-services.net/api/v1.0/discovery/MinecraftPE/builds/1.26.30');$base=$discovery['result']['serviceEnvironments']['auth']['prod']['serviceUri']??$base;}catch(\Throwable){}
        $config=self::fetch(rtrim($base,'/').'/.well-known/openid-configuration');
        if(!is_string($config['issuer']??null)||!is_string($config['jwks_uri']??null)){throw new \RuntimeException('Invalid OpenID configuration');}
        $jwks=self::fetch($config['jwks_uri']);$keys=[];
        foreach($jwks['keys']??[] as $key){if(($key['kty']??'')==='RSA'&&($key['use']??'sig')==='sig'&&isset($key['kid'])){$keys[$key['kid']]=$key;}}
        if($keys===[]){throw new \RuntimeException('No signing keys in JWKS');}
        @mkdir(dirname($path),0700,true);$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp,json_encode(['fetched_at'=>time(),'issuer'=>$config['issuer'],'keys'=>$keys],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($tmp,0600);
        if(!rename($tmp,$path)){throw new \RuntimeException('Cannot commit auth key cache');}
    }
}
