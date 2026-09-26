<?php
declare(strict_types=1);
namespace mpe\utils;
final class RakLogger implements \Logger {
    public function __construct(private ConsoleLogger $logger){}
    public function emergency($message):void{$this->log('ERROR',$message);}
    public function alert($message):void{$this->log('ERROR',$message);}
    public function critical($message):void{$this->log('ERROR',$message);}
    public function error($message):void{$this->log('ERROR',$message);}
    public function warning($message):void{$this->log('WARN',$message);}
    public function notice($message):void{$this->log('INFO',$message);}
    public function info($message):void{$this->log('INFO',$message);}
    public function debug($message):void{
        $message=(string)$message;
        if(str_contains($message,' bytes): 0x')||str_starts_with($message,'#')){return;}
        $this->logger->debug($message);
    }
    public function log($level,$message):void{$this->logger->log(strtoupper((string)$level),(string)$message);}
    public function logException(\Throwable $e,$trace=null):void{$this->logger->error($e::class.': '.$e->getMessage());$this->logger->debug($e->getTraceAsString());}
}
