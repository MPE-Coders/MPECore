<?php
declare(strict_types=1);
namespace mpe\network\ipc;
final class FrameStream {
    public const LIMIT=1048576;
    private string $buffer='';
    /** @return list<string> */
    public function feed(string $bytes):array {
        $this->buffer.=$bytes;$frames=[];
        while(strlen($this->buffer)>=4){
            $n=unpack('V',substr($this->buffer,0,4))[1];
            if($n<1 || $n>self::LIMIT){throw new \UnexpectedValueException('Invalid IPC length');}
            if(strlen($this->buffer)<4+$n){break;}
            $frames[]=substr($this->buffer,4,$n);$this->buffer=substr($this->buffer,4+$n);
        }
        if(strlen($this->buffer)>self::LIMIT+4){throw new \UnexpectedValueException('IPC buffering limit');}
        return $frames;
    }
    public static function frame(string $bytes):string {
        if($bytes===''||strlen($bytes)>self::LIMIT){throw new \LengthException('IPC frame limit');}return pack('V',strlen($bytes)).$bytes;
    }
    public function pending():int{return strlen($this->buffer);}
}
