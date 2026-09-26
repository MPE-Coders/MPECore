<?php
declare(strict_types=1);
require __DIR__.'/autoload.php';
try{
    $line=fgets(STDIN,4*1024*1024+1);
    if($line===false||!str_ends_with($line,"\n")){throw new RuntimeException('Auth worker input limit');}
    $request=json_decode($line,true,64,JSON_THROW_ON_ERROR);
    $result=\mpe\network\mcpe\auth\LoginVerifier::verify($request);
}catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR)."\n");
