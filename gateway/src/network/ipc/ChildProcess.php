<?php
declare(strict_types=1);
namespace mpe\network\ipc;
/**
 * Supervise trusted local components of this Minecraft server (Rust, PHP plugins and codecs).
 * Callers supply argv arrays constructed by server code, never a shell command from a player.
 * Bounded non-blocking stdin/stdout IPC. This class is not a security sandbox.
 */
final class ChildProcess {
    private $process;
    private array $pipes=[];
    private string $pending='';
    private bool $closed=false;
    public readonly float $started;
    public function __construct(array $argv,string $cwd) {
        $this->started=microtime(true);
        $this->process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,$cwd,null,['bypass_shell'=>true]);
        if(!is_resource($this->process)){throw new \RuntimeException('Cannot start child process');}
        foreach($this->pipes as $pipe){stream_set_blocking($pipe,false);}
    }
    public static function phpCommand(string $file):array {
        return [PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','display_errors=stderr', '-d','memory_limit=256M',$file];
    }
    public function send(string $data):void {
        if(strlen($this->pending)+strlen($data)>8*1024*1024){throw new \OverflowException('Child queue backpressure');}
        $this->pending.=$data;$this->flush();
    }
    public function flush():void {
        if($this->closed||$this->pending===''){return;}
        $n=@fwrite($this->pipes[0],$this->pending);
        if($n===false){throw new \RuntimeException('Child stdin closed');}
        if($n>0){$this->pending=substr($this->pending,$n);}
    }
    public function read(int $fd=1,int $max=262144):string {
        if($this->closed){return '';}$s=@fread($this->pipes[$fd],$max);return $s===false?'':$s;
    }
    public function running():bool{return !$this->closed && proc_get_status($this->process)['running'];}
    public function close():void {
        if($this->closed){return;}$this->closed=true;
        if(!is_resource($this->process)){return;}
        foreach($this->pipes as $p){if(is_resource($p)){fclose($p);}}
        if(proc_get_status($this->process)['running']){
            proc_terminate($this->process);usleep(10000);
            if(proc_get_status($this->process)['running']){proc_terminate($this->process,9);}
        }
        proc_close($this->process);
    }
    public function __destruct(){$this->close();}
}
