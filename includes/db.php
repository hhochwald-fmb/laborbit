<?php
if (session_status() === PHP_SESSION_NONE) {
    // Session-Cookie absichern: nicht per JavaScript lesbar, nicht bei fremden Seiten mitgesendet,
    // unter HTTPS nur verschlüsselt. Strict Mode verhindert untergeschobene Session-IDs.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_VERSION')) {
    // Single source of truth for the app version: the VERSION file in the repo root.
    $versionFile = APP_ROOT . '/VERSION';
    define('APP_VERSION', is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0');
}

$configFile = APP_ROOT . '/config.php';

if (!file_exists($configFile)) {
    header("Location: setup.php");
    exit;
}

require_once $configFile;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

require_once __DIR__ . '/migrations.php';
autoMigrate($pdo);
