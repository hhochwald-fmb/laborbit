<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$configFile = __DIR__ . '/../config.php';

if (!file_exists($configFile)) {
    header("Location: ../setup.php");
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
?>
