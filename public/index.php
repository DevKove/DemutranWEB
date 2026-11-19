<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$envFile=BASE_PATH.'/.env';
if(is_file($envFile)){
    foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$value]=explode('=',$line,2);$key=trim($key);
        if(getenv($key)===false)putenv($key.'='.trim(trim($value),chr(34).chr(39)));
    }
}
require BASE_PATH.'/app/helpers.php';
$app=require BASE_PATH.'/config/app.php';
date_default_timezone_set($app['timezone']);
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
if(session_status()!==PHP_SESSION_ACTIVE){
    session_name('demutranweb_session');
    session_set_cookie_params([
        'lifetime'=>max(5,$app['session_lifetime'])*60,
        'path'=>'/',
        'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),
        'httponly'=>true,
        'samesite'=>'Lax'
    ]);
    session_start();
}
$_SESSION['csrf']??=bin2hex(random_bytes(32));
require BASE_PATH.'/app/Bootstrap.php';
try{
    (new App\\Bootstrap($app))->run();
}catch(Throwable $e){
    error_log('[DemutranWEB] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(503);
    echo $app['debug']?'Falha interna. Consulte os logs do servidor.':'Sistema temporariamente indisponível. Tente novamente mais tarde.';
}
