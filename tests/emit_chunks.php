<?php
declare(strict_types=1);
/** Test fixture from the REAL PHP chunk serializer; consumed by an independent JS decoder. */
require dirname(__DIR__).'/gateway/autoload.php';
use mpe\network\mcpe\serializer\ChunkSerializer;
$sections=[];for($y=-4;$y<=4;$y++){$sections[$y]=str_repeat("\x00",4096);}
for($x=0;$x<16;$x++){for($z=0;$z<16;$z++){for($y=12;$y<16;$y++){$sections[3][($x<<8)|($z<<4)|$y]="\x01";}}}
$sections[4][(5<<8)|(6<<4)|0]="\x07";
$map=[];for($i=0;$i<12;$i++){$map[$i]=1000+$i*17;}
echo json_encode(['payload'=>base64_encode(ChunkSerializer::serialize($sections,$map)),'map'=>$map,'count'=>count($sections)],JSON_THROW_ON_ERROR),"\n";
