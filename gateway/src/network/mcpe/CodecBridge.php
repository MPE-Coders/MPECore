<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use mpe\network\ipc\ChildProcess;
/** Opt-in codec worker only; RakNet, session encryption, authentication and game state stay local. */
final class CodecBridge {
    private static ?self $instance=null;
    private ChildProcess $worker;
    private int $sequence=0;
    private string $buffer='';
    public function __construct(string $root){
        if(!is_dir($root.'/node_modules/bedrock-protocol')){throw new \RuntimeException('Modern protocols require ./tools/node-install.sh');}
        $this->worker=new ChildProcess([getenv('MPE_NODE')?:'node',$root.'/codec/worker.cjs'],$root);
        $this->rpc(['method'=>'capabilities'],30.0);
    }
    public static function enable(string $root):void{self::$instance??=new self($root);}
    public static function convert(string $bytes,int $from,int $to):string{
        if(self::$instance===null){throw new \RuntimeException('Experimental codec bridge is not enabled');}
        $r=self::$instance->rpc(['method'=>'convert','from'=>$from,'to'=>$to,'data'=>base64_encode($bytes)],2.0);
        $out=base64_decode($r['data']??'',true);if($out===false||$out===''){throw new \RuntimeException('Invalid codec response');}return $out;
    }
    private function rpc(array $request,float $timeout):array{
        $id=++$this->sequence;$this->worker->send(json_encode(['id'=>$id]+$request,JSON_THROW_ON_ERROR)."\n");$deadline=microtime(true)+$timeout;
        while(microtime(true)<$deadline){
            $this->worker->flush();$this->buffer.=$this->worker->read();$this->worker->read(2);
            if(strlen($this->buffer)>6*1024*1024){throw new \OverflowException('Codec response limit');}
            if(($i=strpos($this->buffer,"\n"))!==false){
                $line=substr($this->buffer,0,$i);$this->buffer=substr($this->buffer,$i+1);$result=json_decode($line,true,64,JSON_THROW_ON_ERROR);
                if(($result['id']??null)!==$id){throw new \UnexpectedValueException('Codec request/response mismatch');}
                if(!($result['ok']??false)){throw new \RuntimeException('Codec bridge: '.($result['error']??'unknown error'));}return $result;
            }
            if(!$this->worker->running()){throw new \RuntimeException('Codec worker stopped');}usleep(500);
        }
        $this->worker->close();throw new \RuntimeException('Codec worker timeout; modern sessions require server restart');
    }
}
