<?php
declare(strict_types=1);
namespace mpe\player;
/** A session-bound remote handle, not a reference to mutable Rust memory. */
final class Player {
    public function __construct(private readonly array $data,private readonly \Closure $send){}
    public function getName():string{return $this->data['name'];}
    public function getUniqueId():string{return $this->data['uuid'];}
    public function isAuthenticated():bool{return $this->data['authenticated']??false;}
    public function getPosition():\mpe\math\Vector3{$p=$this->data['position']??[0.0,64.0,0.0];return new \mpe\math\Vector3((float)$p[0],(float)$p[1],(float)$p[2]);}
    public function sendMessage(string $message):void{($this->send)(['op'=>'message','sid'=>$this->data['sid'],'message'=>$message]);}
}
