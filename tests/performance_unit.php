<?php
// These are measurement regressions, NOT benchmark claims.
require dirname(__DIR__).'/gateway/autoload.php';
$failed=0;$passed=0;
function same(mixed $a,mixed $b):void {if($a!==$b){throw new RuntimeException('Unexpected metrics');}}
function rejects(callable $f):void {try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection');}
function test(string $name,callable $f):void {
    global $passed,$failed;
    try{$f();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;fwrite(STDERR,"FAIL $name: ".$e->getMessage()."\n");}
}
use mpe\utils\SessionPerformance;
test('Session metrics distinguish queued chunks from single-block packets',function(){
    $m=new \mpe\utils\SessionPerformance();$m->packet('chunk',4096);$m->packet('block',20);$m->packet('other',12);
    $s=$m->snapshot();same($s['queued_packets'],3);same($s['level_chunks'],1);same($s['block_updates'],1);same($s['queued_bytes'],4128);
    same($s['engine_roundtrip_ms']['p95'],null);
    rejects(fn()=>$m->packet('chunk',-1));
});
test('Interaction percentiles are bounded to the last 256 completions',function(){
    $m=new \mpe\utils\SessionPerformance();for($i=1;$i<=300;$i++){$m->interaction($i%2===0,(float)$i);}
    $s=$m->snapshot();same($s['accepted'],150);same($s['rejected'],150);
    same($s['engine_roundtrip_ms'],['samples'=>256,'p50'=>172.0,'p95'=>288.0,'max'=>300.0]);
    foreach([NAN,INF,-1.0] as $v){rejects(fn()=>$m->interaction(true,$v));}
});
echo "$passed performance regressions passed; $failed failed. Not a benchmark.\n";
exit($failed===0?0:1);
