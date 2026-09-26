<?php
declare(strict_types=1);
// No generated private key or derived session secret is printed or persisted.
try {
    while(openssl_error_string()!==false){}
    $options=['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'secp384r1'];
    $a=openssl_pkey_new($options);$b=openssl_pkey_new($options);
    if($a===false||$b===false){throw new RuntimeException('Cannot generate a P-384 key');}
    $ad=openssl_pkey_get_details($a);$bd=openssl_pkey_get_details($b);
    if(($ad['ec']['curve_name']??'')!=='secp384r1'||($bd['ec']['curve_name']??'')!=='secp384r1'){
        throw new RuntimeException('Wrong key curve');
    }
    $ab=openssl_pkey_derive($bd['key'],$a,48);$ba=openssl_pkey_derive($ad['key'],$b,48);
    if($ab===false||$ba===false||strlen($ab)!==48||!hash_equals($ab,$ba)){
        throw new RuntimeException('ECDH self-check failed');
    }
    $message='MPE runtime preflight';$signature='';
    if(!openssl_sign($message,$signature,$a,OPENSSL_ALGO_SHA384)||
        openssl_verify($message,$signature,$ad['key'],OPENSSL_ALGO_SHA384)!==1){
        throw new RuntimeException('ES384 self-check failed');
    }
    $key=random_bytes(32);$iv=random_bytes(16);
    $cipher=openssl_encrypt($message,'aes-256-ctr',$key,OPENSSL_RAW_DATA,$iv);
    if($cipher===false||openssl_decrypt($cipher,'aes-256-ctr',$key,OPENSSL_RAW_DATA,$iv)!==$message){
        throw new RuntimeException('AES-256-CTR self-check failed');
    }
    echo "PASS PHP crypto: P-384 / ECDH / ES384 / AES-256-CTR\n";
} catch(Throwable $e) {
    fwrite(STDERR,"CRYPTO PREFLIGHT FAILED: ".$e->getMessage().". Check OPENSSL_CONF and the selected PHP runtime.\n");
    for($i=0;$i<8 && ($error=openssl_error_string())!==false;$i++){
        fwrite(STDERR,"OpenSSL: ".$error."\n");
    }
    exit(1);
}
