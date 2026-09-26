<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use mpe\utils\Binary;
final class BatchCodec {
    public const MAX_BATCH=8*1024*1024;
    /** @param list<string> $packets */
    public static function encode(array $packets,bool $compression):string {
        $s='';foreach($packets as $p){$s.=Binary::uvar(strlen($p)).$p;}
        if(strlen($s)>self::MAX_BATCH){throw new \LengthException('Batch limit');}
        if(!$compression){return $s;}
        return strlen($s)<256?"\xff".$s:"\x00".gzdeflate($s,6);
    }
    /** @return list<string> */
    public static function decode(string $s,bool $compression):array {
        if(strlen($s)>self::MAX_BATCH){throw new \LengthException('Batch limit');}
        if($compression){
            if($s===''){throw new \UnexpectedValueException('Missing compression header');}
            $algorithm=ord($s[0]);$s=substr($s,1);
            if($algorithm===0){$s=@gzinflate($s,self::MAX_BATCH);if($s===false){throw new \UnexpectedValueException('Invalid or oversized deflate batch');}}
            elseif($algorithm!==255){throw new \UnexpectedValueException('Unnegotiated compression algorithm');}
        }
        $o=0;$out=[];
        while($o<strlen($s)){
            if(count($out)>=512){throw new \LengthException('Too many packets in batch');}
            $n=Binary::readUvar($s,$o);if($n===0){throw new \UnexpectedValueException('Empty game packet');}
            $out[]=Binary::take($s,$o,$n);
        }return $out;
    }
}
