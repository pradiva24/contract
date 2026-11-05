<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Load Composer packages before application code uses Dompdf, QR Code or PHPMailer.
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException(
        'Composer autoload not found at ' . $composerAutoload . '. Run composer install from the project root.'
    );
}
require_once $composerAutoload;

require_once BASE_PATH . '/app/helpers.php';
require_once BASE_PATH . '/app/company.php';

$envPath = BASE_PATH . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim(trim($value), "\"'");
    }
}
date_default_timezone_set((string)config('APP_TIMEZONE', 'Asia/Makassar'));
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bta_certificate_session');
    session_set_cookie_params(['httponly'=>true, 'secure'=>!empty($_SERVER['HTTPS']), 'samesite'=>'Lax', 'path'=>'/']);
    session_start();
}
