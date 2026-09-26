<?php
declare(strict_types=1);
namespace mpe\plugin;
use mpe\network\ipc\ChildProcess;
final class PluginWorker {
    private ChildProcess $child;
    private array $queue=[];
    private string $buffer='';
    private ?int $inflight=null;
    private int $sequence=0;
    private float $deadline;
    private bool $ready=false,$stopped=false,$disableSent=false;
    public bool $reloading=false;
    public function __construct(public readonly string $name,public readonly string $directory,string $root){
        $argv=ChildProcess::phpCommand($root.'/gateway/plugin-worker.php');$argv[]=$directory;
        $this->child=new ChildProcess($argv,$root);$this->deadline=microtime(true)+5;
    }
    public function event(string $kind,array $data):void {
        if($this->reloading||$this->stopped){return;}
        if(count($this->queue)>=128){throw new \OverflowException('Plugin event backlog exceeded');}
        $this->queue[]=['id'=>++$this->sequence,'event'=>$kind,'data'=>$data];
    }
    public function requestReload():void{$this->reloading=true;$this->queue=[];}
    /** @return list<array> */
    public function tick():array {
        if($this->stopped){return [];}
        $this->child->flush();$this->buffer.=$this->child->read();$messages=[];
        if(strlen($this->buffer)>262144){throw new \OverflowException('Plugin output limit');}
        $err=$this->child->read(2);if($err!==''){$messages[]=['op'=>'log','level'=>'WARN','message'=>$err];}
        while(($pos=strpos($this->buffer,"\n"))!==false){
            $line=substr($this->buffer,0,$pos);$this->buffer=substr($this->buffer,$pos+1);
            $m=json_decode($line,true,32,JSON_THROW_ON_ERROR);
            if(($m['op']??'')==='ready'){$this->ready=true;}
            elseif(($m['op']??'')==='ack'){
                if($m['id']!==$this->inflight){throw new \UnexpectedValueException('Plugin ACK mismatch');}$this->inflight=null;
                if($this->disableSent){$this->stop();break;}
            }elseif(($m['op']??'')==='fatal'){throw new \RuntimeException($m['message']??'Plugin fatal');}
            else{$messages[]=$m;}
        }
        if($this->stopped){return $messages;}
        if(!$this->child->running()){throw new \RuntimeException('Plugin worker exited');}
        if((!$this->ready||$this->inflight!==null)&&microtime(true)>$this->deadline){throw new \RuntimeException('Plugin callback timed out');}
        if($this->ready&&$this->inflight===null){
            if($this->reloading&&!$this->disableSent){$this->queue=[['id'=>++$this->sequence,'event'=>'disable','data'=>[]]];$this->disableSent=true;}
            if($this->queue!==[]){$m=array_shift($this->queue);$this->inflight=$m['id'];$this->deadline=microtime(true)+2;$this->child->send(json_encode($m,JSON_THROW_ON_ERROR)."\n");}
        }
        return $messages;
    }
    public function stopped():bool{return $this->stopped;}
    public function stop():void{if(!$this->stopped){$this->stopped=true;$this->child->close();}}
}
