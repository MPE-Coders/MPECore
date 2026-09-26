<?php
declare(strict_types=1);
namespace mpe\network\mcpe\convert;
final class ProtocolProfile {
    public readonly int $id;
    public readonly string $version;
    public readonly array $data;
    public function __construct(string $file){
        $this->data=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        \mpe\data\ProfileGuard::validate($this->data);
        $this->id=$this->data['protocol'];$this->version=$this->data['game_version'];
        if($this->data['min_section']!==-4||$this->data['max_section']!==19){throw new \RuntimeException('This milestone supports Overworld bounds only');}
    }
    public function asset(string $root,string $key):string {
        $file=$this->data[$key]??throw new \InvalidArgumentException('Unknown profile asset');
        if(basename($file)!==$file){throw new \RuntimeException('Invalid asset path');}
        $path=$root.'/'.$file;if(!is_file($path)){throw new \RuntimeException("Protocol {$this->id}: missing asset $file; no fallback is allowed");}return $path;
    }
}
