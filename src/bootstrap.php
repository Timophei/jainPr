<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$cfgFile = APP_ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Нет config.php — скопируйте config.example.php в config.php и заполните.');
}
$GLOBALS['APP_CFG'] = require $cfgFile;

function cfg(string $key, $default = null)
{
    return $GLOBALS['APP_CFG'][$key] ?? $default;
}

spl_autoload_register(static function (string $class): void {
    $f = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($f)) {
        require $f;
    }
});

date_default_timezone_set((string)cfg('timezone', 'Europe/Moscow'));
mb_internal_encoding('UTF-8');

function data_dir(): string
{
    $d = rtrim((string)cfg('data_dir', APP_ROOT . '/data'), '/');
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
    return $d;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('rspz');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => !empty($_SERVER['HTTPS']),
            'gc_maxlifetime' => 60 * 60 * 24 * 14,
            'cookie_lifetime' => 60 * 60 * 24 * 14,
        ]);
    }
}

function is_authed(): bool
{
    if ((string)cfg('password', '') === '') {
        return true;
    }
    start_session();
    return !empty($_SESSION['auth']);
}
