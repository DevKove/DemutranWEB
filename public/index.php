<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim(trim($value), '"\''));
    }
}
$app = require BASE_PATH . '/config/app.php';
date_default_timezone_set($app['timezone']);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('demutranweb_session');
    session_set_cookie_params([
        'lifetime' => $app['session_lifetime'] * 60, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
require BASE_PATH . '/app/Bootstrap.php';
$appKernel = new App\Bootstrap($app);
$appKernel->run();
