<?php
declare(strict_types=1);
namespace mpe\plugin;
final class PluginLogger {
    public function __construct(private \Closure $send){}
    public function info(string $message):void{($this->send)(['op'=>'log','level'=>'INFO','message'=>$message]);}
    public function warning(string $message):void{($this->send)(['op'=>'log','level'=>'WARN','message'=>$message]);}
}
