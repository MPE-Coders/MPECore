<?php
declare(strict_types=1);
namespace mpe\utils;
final class Binary {
    public static function take(string $s, int &$offset, int $n): string {
        if($n<0 || $n>strlen($s)-$offset){throw new \UnexpectedValueException('Truncated buffer');}
        $result=substr($s,$offset,$n);$offset+=$n;return $result;
    }
    public static function uvar(int $v): string {
        if($v<0){throw new \InvalidArgumentException('Unsigned varint < 0');}
        $r='';do{$b=$v&127;$v>>=7;$r.=chr($b|($v>0?128:0));}while($v>0);return $r;
    }
    public static function readUvar(string $s,int &$o,int $maxBytes=5):int {
        $v=0;for($i=0;$i<$maxBytes;$i++){
            $b=ord(self::take($s,$o,1));
            if($i===4 && $maxBytes===5 && ($b&0xf0)!==0){throw new \UnexpectedValueException('Varint overflow');}
            $v|=($b&127)<<($i*7);if(($b&128)===0){return $v;}
        }throw new \UnexpectedValueException('Varint too long');
    }
    public static function svar(int $v):string{return self::uvar(($v<<1)^($v>>31));}
    public static function readSvar(string $s,int &$o):int{$v=self::readUvar($s,$o);return ($v>>1)^(-($v&1));}
    public static function u64(int $v):string{return pack('P',$v);}
    public static function readU64(string $s,int &$o):int{return unpack('P',self::take($s,$o,8))[1];}
    public static function i32(int $v):string{return pack('V',$v);}
    public static function readI32(string $s,int &$o):int{$v=unpack('V',self::take($s,$o,4))[1];return $v>=0x80000000?$v-0x100000000:$v;}
    public static function f32(float $v):string{return pack('g',$v);}
    public static function readF32(string $s,int &$o):float{return unpack('g',self::take($s,$o,4))[1];}
    public static function str(string $s):string{return pack('V',strlen($s)).$s;}
    public static function readStr(string $s,int &$o,int $max=1048576):string{
        $n=unpack('V',self::take($s,$o,4))[1];if($n>$max){throw new \UnexpectedValueException('String too long');}return self::take($s,$o,$n);
    }
    public static function b64url(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
    public static function unb64url(string $s):string{
        if($s==='' || preg_match('/[^a-zA-Z0-9_-]/D',$s)){throw new \UnexpectedValueException('Invalid base64url');}
        $r=base64_decode(strtr($s,'-_','+/'),true);if($r===false){throw new \UnexpectedValueException('Invalid base64url');}return $r;
    }
    public static function clean(string $s,int $max=1024):string {
        $s=preg_replace('/[\x00-\x1f\x7f]/','',$s)??'';
        return substr($s,0,$max);
    }
}
