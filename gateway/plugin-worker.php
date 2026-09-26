<?php
declare(strict_types=1);
require __DIR__.'/autoload.php';
use mpe\plugin\PluginBase;
use mpe\player\Player;
use mpe\event\player\{PlayerJoinEvent,PlayerQuitEvent,PlayerChatEvent};
$emit=static function(array $data):void{
    $s=json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    if(strlen($s)>65536){throw new LengthException('Plugin RPC limit');}
    fwrite(STDOUT,$s);fflush(STDOUT);
};
try{
    $dir=realpath($argv[1]??'');if($dir===false){throw new RuntimeException('Plugin folder missing');}
    $manifest=json_decode(file_get_contents($dir.'/plugin.json'),true,32,JSON_THROW_ON_ERROR);
    $entry=realpath($dir.'/'.$manifest['entry']);
    if($entry===false||!str_starts_with($entry,$dir.DIRECTORY_SEPARATOR)){throw new RuntimeException('Invalid plugin entry path');}
    $dataFolder=dirname(__DIR__).'/data/plugins/'.$manifest['name'];@mkdir($dataFolder,0700,true);
    require $entry;$class=$manifest['main'];
    if(!is_subclass_of($class,PluginBase::class)){throw new RuntimeException('Plugin must extend PluginBase');}
    /** @var PluginBase $plugin */
    $plugin=new $class($emit,$dataFolder);$plugin->onEnable();$emit(['op'=>'ready']);
    while(($line=fgets(STDIN,262145))!==false){
        if(!str_ends_with($line,"\n")){throw new LengthException('Plugin event too large');}
        $event=json_decode($line,true,32,JSON_THROW_ON_ERROR);$id=$event['id'];$kind=$event['event'];$data=$event['data']??[];
        if($kind==='disable'){$plugin->onDisable();$emit(['op'=>'ack','id'=>$id]);break;}
        $player=new Player($data,$emit);
        match($kind){
            'block_change'=>$plugin->onBlockChange(new \mpe\event\block\BlockChangeEvent($player,new \mpe\math\Vector3($data['x'],$data['y'],$data['z']),$data['block'],$data['previous'])),
            'join'=>$plugin->onJoin(new PlayerJoinEvent($player)),
            'quit'=>$plugin->onQuit(new PlayerQuitEvent($player)),
            'command'=>$plugin->onCommand(new \mpe\event\player\PlayerCommandEvent($player,$data['command'],$data['arguments'])),
            'chat'=>$plugin->onChat(new PlayerChatEvent($player,$data['message'])),
            default=>null
        };
        $emit(['op'=>'ack','id'=>$id]);
    }
}catch(Throwable $e){$emit(['op'=>'fatal','message'=>$e::class.': '.$e->getMessage()]);exit(1);}
