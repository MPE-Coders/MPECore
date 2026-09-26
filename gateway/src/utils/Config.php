<?php
declare(strict_types=1);
namespace mpe\utils;
final class Config {
    public static function load(string $root):array {
        $defaults=json_decode(file_get_contents($root.'/server.example.json'),true,32,JSON_THROW_ON_ERROR);
        $file=getenv('MPE_CONFIG')?:$root.'/server.json';
        $values=is_file($file)?json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];
        if(!is_array($values)){throw new \InvalidArgumentException('server.json must be an object');}
        $c=array_replace($defaults,$values);
        if(!filter_var($c['host'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){throw new \InvalidArgumentException('host must be an IPv4 address');}
        foreach(['port'=>[1,65535],'max-players'=>[1,32],'view-distance'=>[2,8],'max-view-distance'=>[2,8],'chunks-per-tick'=>[1,8],'auth-timeout'=>[10,120],'spawn-timeout'=>[15,300]] as $key=>[$min,$max]){
            if(!is_int($c[$key])||$c[$key]<$min||$c[$key]>$max){throw new \InvalidArgumentException("Invalid $key ($min..$max)");}
        }
        if($c['view-distance']>$c['max-view-distance']){throw new \InvalidArgumentException('view-distance exceeds max-view-distance');}
        foreach(['online-mode','encryption','debug','packet-dump','test-mode','experimental-codecs','allow-building','playtest-log'] as $key){if(!is_bool($c[$key])){throw new \InvalidArgumentException("$key must be boolean");}}
        if(!is_array($c['protocols'])||$c['protocols']===[]){throw new \InvalidArgumentException('At least one profile required');}
        foreach($c['protocols'] as $id){if(!is_int($id)||!is_file($root.'/resources/protocols/'.$id.'.json')){throw new \InvalidArgumentException('Missing explicit protocol profile');}}
        if(count(array_unique($c['protocols']))!==count($c['protocols'])||!in_array($c['advertise-protocol'],$c['protocols'],true)){throw new \InvalidArgumentException('Invalid advertised/duplicate profile');}
        if(in_array(2193,$c['protocols'],true)&&!$c['experimental-codecs']){throw new \InvalidArgumentException('Protocol 2193 requires explicit experimental-codecs=true');}
        if(!is_string($c['server-name'])||strlen($c['server-name'])>128){throw new \InvalidArgumentException('Server name limit');}
        if($c['test-mode'] && $c['host']!=='127.0.0.1'){throw new \InvalidArgumentException('test-mode MUST bind 127.0.0.1, never a public/LAN interface');}
        if(!is_array($c['operators'])){throw new \InvalidArgumentException('operators must be UUID list');}
        foreach($c['operators'] as $uuid){if(!is_string($uuid)||!preg_match('/^[a-f0-9-]{36}$/i',$uuid)){throw new \InvalidArgumentException('Operator must be an explicit UUID');}}
        if(!is_string($c['world-directory'])||$c['world-directory']===''){throw new \InvalidArgumentException('Invalid world-directory');}
        foreach($c['protocols'] as $id){new \mpe\network\mcpe\convert\ProtocolProfile($root.'/resources/protocols/'.$id.'.json');}
        new \DateTimeZone($c['timezone']);
        return $c;
    }
}
