<?php
declare(strict_types=1);
namespace mpe\network\mcpe\convert;
/** Pure typed-state lookup, deliberately independent of NBT implementation details. */
final class CanonicalBlockRegistry {
    public static function resolve(array $states,array $definitions):array {
        $result=[];
        foreach($definitions as $definition){
            $found=[];
            foreach($definition['variants'] as $variant){
                $properties=$variant['states'];ksort($properties,SORT_STRING);
                $key=$variant['name'].'|'.json_encode($properties===[]?[]:$properties,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                if(array_key_exists($key,$states)){$found[]=$states[$key];}
            }
            $found=array_values(array_unique($found));
            if(count($found)!==1){throw new \UnexpectedValueException('Canonical block '.$definition['name'].' has '.count($found).' exact matches; update this version profile explicitly');}
            $result[$definition['id']]=$found[0];
        }
        if(array_keys($result)!==range(0,count($result)-1)){throw new \UnexpectedValueException('Canonical IDs must be contiguous');}
        return $result;
    }
}
