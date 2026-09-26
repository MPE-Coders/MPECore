<?php
declare(strict_types=1);
// Standalone classes/tests work without Composer. Runtime libraries still require vendor/.
$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) { require_once $vendor; }
spl_autoload_register(static function (string $name): void {
    if (str_starts_with($name, 'mpe\\')) {
        $path=__DIR__.'/src/'.str_replace('\\','/',substr($name,4)).'.php';
        if(is_file($path)){require_once $path;}
    }
});
