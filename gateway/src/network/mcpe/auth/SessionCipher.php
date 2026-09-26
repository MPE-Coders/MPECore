<?php
declare(strict_types=1);
namespace mpe\network\mcpe\auth;
use mpe\utils\Binary;
/** Stateful Bedrock AES-256-CTR stream with per-batch 8-byte checksum. */
final class SessionCipher {
    private int $encryptOffset=0,$decryptOffset=0,$encryptCounter=0,$decryptCounter=0;
    public function __construct(private readonly string $key){if(strlen($key)!==32){throw new \InvalidArgumentException('AES key must be 32 bytes');}}
    private function transform(string $data,int &$offset):string {
        $block=intdiv($offset,16);$skip=$offset%16;
        if($block+intdiv(strlen($data)+$skip+15,16)>=0xfffffffd){throw new \OverflowException('Cipher exhausted');}
        $iv=substr($this->key,0,12).pack('N',2+$block);
        $out=openssl_encrypt(str_repeat("\0",$skip).$data,'aes-256-ctr',$this->key,OPENSSL_RAW_DATA,$iv);
        if($out===false){throw new \RuntimeException('AES failure');}
        $offset+=strlen($data);return substr($out,$skip);
    }
    public function encrypt(string $plain):string {
        $sum=substr(hash('sha256',Binary::u64($this->encryptCounter++).$plain.$this->key,true),0,8);
        return $this->transform($plain.$sum,$this->encryptOffset);
    }
    public function decrypt(string $cipher):string {
        if(strlen($cipher)<8){throw new \UnexpectedValueException('Ciphertext too short');}
        $decoded=$this->transform($cipher,$this->decryptOffset);$plain=substr($decoded,0,-8);
        $expected=substr(hash('sha256',Binary::u64($this->decryptCounter++).$plain.$this->key,true),0,8);
        if(!hash_equals($expected,substr($decoded,-8))){throw new \UnexpectedValueException('Invalid encrypted batch checksum');}
        return $plain;
    }
}
