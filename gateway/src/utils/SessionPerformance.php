<?php
declare(strict_types=1);
namespace mpe\utils;

/** Bounded per-session measurements. Queued bytes are NOT UDP wire bytes or ACKs. */
final class SessionPerformance {
    private int $packets=0,$bytes=0,$chunks=0,$updates=0,$accepted=0,$rejected=0;
    private array $samples=[];
    public function packet(string $kind,int $bytes):void {
        if($bytes<0){throw new \InvalidArgumentException('Negative packet size');}
        $this->packets++;$this->bytes+=$bytes;
        if($kind==='chunk'){$this->chunks++;}
        elseif($kind==='block'){$this->updates++;}
    }
    public function interaction(bool $ok,float $milliseconds):void {
        if(!is_finite($milliseconds)||$milliseconds<0){throw new \InvalidArgumentException('Invalid duration');}
        if($ok){$this->accepted++;}else{$this->rejected++;}
        $this->samples[]=$milliseconds;
        if(count($this->samples)>256){array_shift($this->samples);}
    }
    public function snapshot():array {
        $s=$this->samples;sort($s,SORT_NUMERIC);$n=count($s);
        $percentile=static fn(float $p):?float=>$n===0?null:round($s[(int)ceil($p*$n)-1],3);
        return ['queued_packets'=>$this->packets,'queued_bytes'=>$this->bytes,
            'level_chunks'=>$this->chunks,'block_updates'=>$this->updates,
            'accepted'=>$this->accepted,'rejected'=>$this->rejected,
            'engine_roundtrip_ms'=>['samples'=>$n,'p50'=>$percentile(0.50),'p95'=>$percentile(0.95),'max'=>$percentile(1.0)]];
    }
}
