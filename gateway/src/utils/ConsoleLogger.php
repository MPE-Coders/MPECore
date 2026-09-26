<?php
declare(strict_types=1);
namespace mpe\utils;
/** Console layout inspired by MPE-Proxy; implemented independently. */
final class ConsoleLogger {
    private $file;
    private bool $colour;
    public function __construct(string $path,private bool $debug=false){
        @mkdir(dirname($path),0700,true);$this->file=fopen($path,'ab');
        if($this->file===false){throw new \RuntimeException('Cannot open log file');}
        $this->colour=function_exists('stream_isatty')&&stream_isatty(STDOUT)&&getenv('NO_COLOR')===false;
    }
    public function banner():void{
        $this->info('=======================================================');
        $this->info(' MPE-Core 0.4.0-alpha | Rust world + RakLib/PHP gateway');
        $this->info(' Persistent flat world | experimental protocol profiles');
        $this->info(' help — commands | stop — graceful shutdown');
        $this->info('=======================================================');
    }
    public function info(string $s):void{$this->log('INFO',$s);}
    public function warning(string $s):void{$this->log('WARN',$s);}
    public function error(string $s):void{$this->log('ERROR',$s);}
    public function debug(string $s):void{if($this->debug){$this->log('DEBUG',$s);}}
    public function log(string $level,string $s):void{
        $s=Binary::clean($s,8192);$level=Binary::clean($level,16);
        $line='['.date('H:i:s').'][MPE-Core]['.$level.'] '.$s;
        $colour=match($level){'ERROR','CRITICAL'=>'31','WARN','WARNING'=>'33','DEBUG'=>'90',default=>'36'};
        fwrite(STDOUT,($this->colour?"\033[{$colour}m":'').$line.($this->colour?"\033[0m":'').PHP_EOL);
        fwrite($this->file,preg_replace('/§[0-9a-z]/iu','',$line).PHP_EOL);
    }
}
