<?php
declare(strict_types=1);
namespace mpe\network\mcpe\auth;
/** Signed identity verification; executed in a short-lived, timeout-limited child. */
final class LoginVerifier {
    public const MOJANG_ROOT='MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAECRXueJeTDqNRRgJi/vlRufByu/2G0i2Ebt6YMar5QX/R0DIIyrJMcUpruK4QveTfJSTp3Shlq4Gk34cD/4GUWwkv0DVuzeuB+tXija7HBxii03NHDbPAD0AKnLr2wdAp';
    public const AUDIENCE='api://auth-minecraft-services/multiplayer';
    public static function verify(array $request):array {
        $protocol=(int)$request['protocol'];$info=json_decode($request['auth_json'],true,64,JSON_THROW_ON_ERROR);
        $authenticated=false;$xuid='';
        if($protocol>=818&&($info['AuthenticationType']??-1)===0){
            $token=$info['Token']??'';[$header,$claims,$signed,$sig]=Jwt::parse($token);
            if(($header['alg']??'')!=='RS256'||!is_string($header['kid']??null)){throw new \UnexpectedValueException('Invalid online auth JWT header');}
            $cache=AuthKeyCache::load($request['key_cache']);$jwk=$cache['keys'][$header['kid']]??null;
            if($jwk===null){throw new \UnexpectedValueException('Unknown Minecraft signing key; refresh auth keys');}
            if(openssl_verify($signed,$sig,Jwt::rsaPem($jwk),OPENSSL_ALGO_SHA256)!==1){throw new \UnexpectedValueException('Invalid Minecraft online signature');}
            Jwt::times($claims,true);
            $aud=$claims['aud']??null;
            if(($claims['iss']??'')!==$cache['issuer']||!(is_array($aud)?in_array(self::AUDIENCE,$aud,true):$aud===self::AUDIENCE)){throw new \UnexpectedValueException('Wrong auth issuer or audience');}
            $xuid=(string)($claims['xid']??'');if(!preg_match('/^[0-9]{1,20}$/D',$xuid)){throw new \UnexpectedValueException('Invalid XUID');}
            $name=$claims['xname']??'';$uuid=self::uuidFromXuid($xuid);$key=Jwt::decodeKey($claims['cpk']??'');$authenticated=true;
        }elseif($protocol>=944&&($info['AuthenticationType']??-1)===2&&($info['Token']??'')!==''){
            [, $claims]=Jwt::parse($info['Token']??'');$key=Jwt::decodeKey($claims['cpk']??'');
            $claims=Jwt::verifyEc($info['Token'],$key,true);
            $name=$claims['xname']??'';$uuid=$claims['leguuid']??'';
        }else{
            if($protocol>=818){
                if(($info['AuthenticationType']??-1)!==2){throw new \UnexpectedValueException('Unsupported authentication type');}
                $info=json_decode($info['Certificate']??'',true,64,JSON_THROW_ON_ERROR);
            }
            $chain=$info['chain']??null;if(!is_array($chain)||count($chain)<1||count($chain)>4){throw new \UnexpectedValueException('Invalid certificate chain');}
            $key=null;$identity=null;$root=Jwt::decodeKey(self::MOJANG_ROOT);
            foreach($chain as $index=>$jwt){
                [$h]=Jwt::parse($jwt);$signing=$key??Jwt::decodeKey($h['x5u']??'');
                $claims=Jwt::verifyEc($jwt,$signing,$authenticated||hash_equals($root,$signing));
                if(hash_equals($root,$signing)){$authenticated=true;$identity=null;}
                if(isset($claims['extraData'])){
                    if($index!==count($chain)-1){throw new \UnexpectedValueException('Identity must be in final chain link');}
                    $identity=$claims['extraData'];
                }
                $key=Jwt::decodeKey($claims['identityPublicKey']??'');
            }
            if(!is_array($identity)){throw new \UnexpectedValueException('Missing final identity');}
            $name=$identity['displayName']??'';$uuid=$identity['identity']??'';
            $xuid=$authenticated?(string)($identity['XUID']??''):'';
            if($authenticated&&!preg_match('/^[0-9]{1,20}$/D',$xuid)){throw new \UnexpectedValueException('Missing signed XUID');}
        }
        if(($request['online']??true)&&!$authenticated){throw new \UnexpectedValueException('Xbox/Minecraft sign-in is required (online-mode=true)');}
        if(!is_string($name)||!preg_match('/^[A-Za-z0-9_ ][A-Za-z0-9_ ]{0,31}$/D',$name)||trim($name)!==$name){throw new \UnexpectedValueException('Invalid player name');}
        if(!is_string($uuid)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$uuid)){throw new \UnexpectedValueException('Invalid player UUID');}
        $clientData=Jwt::verifyEc($request['client_jwt'],$key);
        $private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'secp384r1']);
        if($private===false){throw new \RuntimeException('Cannot create server P-384 key');}
        $secret=openssl_pkey_derive(Jwt::pem($key),$private,48);
        if($secret===false){throw new \RuntimeException('ECDH key agreement failed');}
        $salt=random_bytes(16);$sessionKey=hash('sha256',$salt.$secret,true);
        return ['ok'=>true,'name'=>$name,'uuid'=>strtolower($uuid),'xuid'=>$xuid,'authenticated'=>$authenticated,
            'key'=>base64_encode($sessionKey),'handshake'=>Jwt::sign(['salt'=>base64_encode($salt)],$private),
            'client_version'=>substr((string)($clientData['GameVersion']??''),0,64)];
    }
    public static function uuidFromXuid(string $xuid):string {
        $bytes=md5('pocket-auth-1-xuid:'.$xuid,true);$bytes[6]=chr((ord($bytes[6])&15)|48);$bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
