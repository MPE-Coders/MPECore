<?php
declare(strict_types=1);
namespace mpe\network\mcpe\serializer;
use mpe\utils\Binary;
/** Full Overworld LevelChunk payload: v8 subchunks, network palettes, 24 biome storages. */
final class ChunkSerializer {
    /** @param array<int,string> $sections section Y => 4096 canonical block bytes */
    public static function serialize(array $sections,array $runtimeMap,int $biomeId=1):string {
        if(count($sections)<8||count($sections)>24||array_keys($sections)!==range(-4,count($sections)-5)){throw new \UnexpectedValueException('Expected contiguous sections beginning at -4, 8..24 sections');}
        $out='';foreach($sections as $blocks){$out.="\x08\x01".self::storage($blocks,$runtimeMap);}
        for($i=0;$i<24;$i++){$out.="\x01".Binary::svar($biomeId);}
        return $out."\x00";
    }
    public static function storage(string $blocks,array $runtimeMap):string {
        if(strlen($blocks)!==4096){throw new \LengthException('A section must contain 4096 blocks');}
        $ids=[];$map=[];
        for($i=0;$i<4096;$i++){$id=ord($blocks[$i]);if(!array_key_exists($id,$runtimeMap)){throw new \OutOfBoundsException('Missing runtime mapping');}if(!isset($map[$id])){$map[$id]=count($ids);$ids[]=$id;}}
        $n=count($ids);
        if($n===1){return "\x01".Binary::svar($runtimeMap[$ids[0]]);}
        $bits=match(true){$n<=2=>1,$n<=4=>2,$n<=8=>3,$n<=16=>4,$n<=32=>5,$n<=64=>6,$n<=256=>8,default=>16};
        $perWord=intdiv(32,$bits);$out=chr(($bits<<1)|1);
        for($i=0;$i<4096;){$word=0;for($slot=0;$slot<$perWord&&$i<4096;$slot++,$i++){$word|=$map[ord($blocks[$i])]<<($slot*$bits);}$out.=pack('V',$word);}
        $out.=Binary::svar($n);foreach($ids as $id){$out.=Binary::svar($runtimeMap[$id]);}return $out;
    }
}
