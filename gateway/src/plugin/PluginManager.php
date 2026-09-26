<?php
declare(strict_types=1);
namespace mpe\plugin;
use mpe\Server;
use mpe\utils\Binary;
final class PluginManager {
    private array $workers=[],$commands=[];
    public function __construct(private readonly Server $server){}
    public function loadAll():void {
        foreach(glob($this->server->root.'/plugins/*/plugin.json') as $file){
            try{$this->load(dirname($file));}catch(\Throwable $e){$this->server->logger->error('Plugin load: '.$e->getMessage());}
        }
    }
    private function load(string $directory):void {
        $m=json_decode(file_get_contents($directory.'/plugin.json'),true,32,JSON_THROW_ON_ERROR);$name=$m['name']??'';
        if(!preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$name)||!in_array($m['api']??'',['0.1','0.2','0.3'],true)){throw new \UnexpectedValueException('Invalid plugin name/API');}
        if(isset($this->workers[$name])){throw new \RuntimeException('Duplicate plugin name');}
        $commands=$m['commands']??[];
        if(!is_array($commands)){throw new \UnexpectedValueException('commands must be a list');}
        foreach($commands as $command){
            if(!is_string($command)||!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$command)||isset($this->commands[$command])||in_array($command,['help','version','pos','blocks','setblock','mpe'],true)){throw new \UnexpectedValueException('Invalid/conflicting plugin command');}
        }
        $this->workers[$name]=new PluginWorker($name,$directory,$this->server->root);$this->server->logger->info('Loading PHP plugin '.$name);
        foreach($commands as $command){$this->commands[$command]=$name;}
    }
    private function removeCommands(string $name):void{$this->commands=array_filter($this->commands,static fn($owner)=>$owner!==$name);}
    public function command(\mpe\network\mcpe\NetworkSession $session,string $command,array $arguments):bool {
        $name=$this->commands[$command]??null;if($name===null){return false;}
        try{$this->workers[$name]->event('command',['sid'=>$session->sid,'name'=>$session->name,'uuid'=>$session->uuid,'authenticated'=>$session->authenticated,'position'=>$session->feet,'command'=>$command,'arguments'=>$arguments]);}
        catch(\Throwable $e){$this->fail($name,$e);$session->message('Plugin command failed');}return true;
    }
    public function names():array{return array_keys($this->workers);}
    public function event(string $name,array $data):void {
        foreach($this->workers as $plugin=>$w){try{$w->event($name,$data);}catch(\Throwable $e){$this->fail($plugin,$e);}}
    }
    public function reload(string $name):void {
        if(!isset($this->workers[$name])){$this->server->logger->warning('Unknown plugin: '.$name);return;}
        $this->workers[$name]->requestReload();$this->server->logger->info('Reload requested: '.$name);
    }
    public function tick():void {
        foreach($this->workers as $name=>$w){
            try{
                foreach($w->tick() as $m){
                    if(($m['op']??'')==='log'){$this->server->logger->log($m['level']??'INFO','['.$name.'] '.Binary::clean((string)($m['message']??'')));}
                    elseif(($m['op']??'')==='message'&&!$w->reloading){$this->server->messagePlayer((int)($m['sid']??-1),Binary::clean((string)($m['message']??'')));}
                }
                if($w->stopped()&&$w->reloading){unset($this->workers[$name]);$this->removeCommands($name);$this->load($w->directory);}
            }catch(\Throwable $e){$reload=$w->reloading;$dir=$w->directory;$this->fail($name,$e);if($reload){try{$this->load($dir);}catch(\Throwable $ignored){$this->server->logger->error($ignored->getMessage());}}}
        }
    }
    private function fail(string $name,\Throwable $error):void {
        ($this->workers[$name]??null)?->stop();unset($this->workers[$name]);$this->removeCommands($name);$this->server->logger->error("Plugin $name stopped: ".$error->getMessage());
    }
    public function shutdown():void {
        foreach($this->workers as $w){$w->requestReload();}
        $end=microtime(true)+0.5;
        while(microtime(true)<$end){$live=false;foreach($this->workers as $w){try{$w->tick();}catch(\Throwable){}$live=$live||!$w->stopped();}if(!$live){break;}usleep(1000);}
        foreach($this->workers as $w){$w->stop();}$this->workers=[];
    }
}
