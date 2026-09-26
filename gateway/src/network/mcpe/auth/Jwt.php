<?php
declare(strict_types=1);
namespace mpe\network\mcpe\auth;
use mpe\utils\Binary;
final class Jwt {
    public static function parse(string $token):array {
        if(strlen($token)>2*1024*1024){throw new \LengthException('JWT too long');}
        $parts=explode('.',$token);if(count($parts)!==3){throw new \UnexpectedValueException('Malformed JWT');}
        $h=json_decode(Binary::unb64url($parts[0]),true,64,JSON_THROW_ON_ERROR);
        $p=json_decode(Binary::unb64url($parts[1]),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($h)||!is_array($p)){throw new \UnexpectedValueException('JWT objects required');}
        if(isset($h['crit'])){throw new \UnexpectedValueException('Unsupported critical JWT header');}
        return [$h,$p,$parts[0].'.'.$parts[1],Binary::unb64url($parts[2])];
    }
    public static function derLength(int $n):string {
        if($n<128){return chr($n);}$bytes='';while($n){$bytes=chr($n&255).$bytes;$n>>=8;}return chr(128|strlen($bytes)).$bytes;
    }
    public static function der(int $tag,string $data):string{return chr($tag).self::derLength(strlen($data)).$data;}
    public static function derInteger(string $n):string {
        $n=ltrim($n,"\0");if($n===''){$n="\0";}if((ord($n[0])&128)!==0){$n="\0".$n;}return self::der(2,$n);
    }
    public static function joseToDer(string $sig):string {
        if(strlen($sig)!==96){throw new \UnexpectedValueException('ES384 signature size');}
        return self::der(0x30,self::derInteger(substr($sig,0,48)).self::derInteger(substr($sig,48)));
    }
    public static function derToJose(string $der):string {
        $o=0;if(ord(Binary::take($der,$o,1))!==0x30){throw new \UnexpectedValueException('ECDSA DER sequence');}
        $n=self::readLength($der,$o);if($n!==strlen($der)-$o){throw new \UnexpectedValueException('ECDSA DER length');}
        $out='';for($i=0;$i<2;$i++){
            if(ord(Binary::take($der,$o,1))!==2){throw new \UnexpectedValueException('ECDSA DER integer');}
            $v=ltrim(Binary::take($der,$o,self::readLength($der,$o)),"\0");if(strlen($v)>48){throw new \UnexpectedValueException('ECDSA integer length');}
            $out.=str_pad($v,48,"\0",STR_PAD_LEFT);
        }
        if($o!==strlen($der)){throw new \UnexpectedValueException('ECDSA trailing data');}return $out;
    }
    private static function readLength(string $d,int &$o):int {
        $n=ord(Binary::take($d,$o,1));if($n<128){return $n;}$count=$n&127;if($count<1||$count>4){throw new \UnexpectedValueException('DER length');}
        $n=0;for($i=0;$i<$count;$i++){$n=($n<<8)|ord(Binary::take($d,$o,1));}return $n;
    }
    public static function pem(string $der):string{return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";}
    public static function publicDer(string $pem):string {
        $s=preg_replace('/-----[^-]+-----|\s/','',$pem);$d=base64_decode($s,true);if($d===false){throw new \UnexpectedValueException('PEM');}return $d;
    }
    public static function decodeKey(string $b64):string {
        $d=base64_decode($b64,true);if($d===false||strlen($d)>2048){throw new \UnexpectedValueException('Invalid identity public key');}return $d;
    }
    public static function verifyEc(string $jwt,string $der,bool $requireExpiry=false):array {
        [$h,$p,$signed,$sig]=self::parse($jwt);
        if(($h['alg']??'')!=='ES384'){throw new \UnexpectedValueException('Expected ES384');}
        $key=openssl_pkey_get_public(self::pem($der));$details=$key===false?false:openssl_pkey_get_details($key);
        if($details===false||($details['ec']['curve_name']??'')!=='secp384r1'){throw new \UnexpectedValueException('Expected P-384 public key');}
        if(openssl_verify($signed,self::joseToDer($sig),$key,OPENSSL_ALGO_SHA384)!==1){throw new \UnexpectedValueException('Invalid ES384 signature');}
        self::times($p,$requireExpiry);return $p;
    }
    public static function times(array $p,bool $required):void {
        $now=time();foreach(['nbf','iat','exp'] as $k){if(isset($p[$k])&&!is_int($p[$k])&&!is_float($p[$k])){throw new \UnexpectedValueException('Invalid JWT time');}}
        if($required&&!isset($p['exp'])){throw new \UnexpectedValueException('JWT expiry required');}
        if(isset($p['exp'])&&$p['exp']<$now-30){throw new \UnexpectedValueException('JWT expired');}
        if(isset($p['nbf'])&&$p['nbf']>$now+30){throw new \UnexpectedValueException('JWT not yet valid');}
        if(isset($p['iat'])&&$p['iat']>$now+60){throw new \UnexpectedValueException('JWT issued in future');}
    }
    public static function sign(array $payload,\OpenSSLAsymmetricKey $key):string {
        $details=openssl_pkey_get_details($key);if($details===false){throw new \RuntimeException('No key details');}
        $header=['alg'=>'ES384','x5u'=>base64_encode(self::publicDer($details['key']))];
        $signed=Binary::b64url(json_encode($header,JSON_THROW_ON_ERROR)).'.'.Binary::b64url(json_encode($payload,JSON_THROW_ON_ERROR));
        if(!openssl_sign($signed,$sig,$key,OPENSSL_ALGO_SHA384)){throw new \RuntimeException('JWT sign failed');}
        return $signed.'.'.Binary::b64url(self::derToJose($sig));
    }
    public static function rsaPem(array $jwk):string {
        if(($jwk['kty']??'')!=='RSA'||($jwk['use']??'sig')!=='sig'||isset($jwk['alg'])&&$jwk['alg']!=='RS256'){throw new \UnexpectedValueException('Wrong JWKS key type');}
        $n=Binary::unb64url($jwk['n']);$e=Binary::unb64url($jwk['e']);
        if(strlen($n)<256||strlen($n)>1024||strlen($e)>8){throw new \UnexpectedValueException('RSA key size');}
        $rsa=self::der(0x30,self::derInteger($n).self::derInteger($e));
        return self::pem(self::der(0x30,hex2bin('300d06092a864886f70d0101010500').self::der(3,"\0".$rsa)));
    }
}
