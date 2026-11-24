<?php
/**
 * Datenbank-Migrationen.
 *
 * Alle Schritte sind idempotent: Sie prüfen vorher, ob Tabelle/Spalte/Fremdschlüssel
 * schon existiert, und dürfen deshalb beliebig oft laufen. Neue Schritte einfach
 * UNTEN in runMigrations() anhängen – niemals bestehende Schritte umsortieren.
 *
 * Wichtig: kein "ADD COLUMN IF NOT EXISTS" verwenden – das kann nur MariaDB, nicht MySQL.
 */
if (!defined('DB_HOST')) exit;

function migTable(string $name): string
{
    return DB_PREFIX . $name;
}

function migTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([migTable($table)]);
    return (int) $stmt->fetchColumn() > 0;
}

function migColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([migTable($table), $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function migColumnType(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([migTable($table), $column]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : strtolower((string) $type);
}

function migForeignKeyExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $stmt->execute([migTable($table), $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Fügt eine Spalte nur hinzu, wenn sie fehlt. Gibt true zurück, wenn etwas geändert wurde. */
function migAddColumn(PDO $pdo, string $table, string $column, string $definition): bool
{
    if (!migTableExists($pdo, $table) || migColumnExists($pdo, $table, $column)) {
        return false;
    }
    $pdo->exec("ALTER TABLE " . migTable($table) . " ADD COLUMN `$column` $definition");
    return true;
}

/** Legt einen Fremdschlüssel nur an, wenn auf der Spalte noch keiner liegt. */
function migAddForeignKey(PDO $pdo, string $table, string $column, string $refTable, string $onDelete): bool
{
    if (!migColumnExists($pdo, $table, $column) || !migTableExists($pdo, $refTable)
        || migForeignKeyExists($pdo, $table, $column)) {
        return false;
    }
    $pdo->exec("ALTER TABLE " . migTable($table) . " ADD FOREIGN KEY (`$column`) REFERENCES "
        . migTable($refTable) . "(id) ON DELETE $onDelete");
    return true;
}

function migSetSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("REPLACE INTO " . migTable('settings') . " (setting_key, setting_value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

/**
 * Führt alle Migrationsschritte aus und setzt danach db_version = APP_VERSION.
 *
 * @return string[] Beschreibungen der Schritte, die tatsächlich etwas geändert haben.
 */
function runMigrations(PDO $pdo): array
{
    $log = [];

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . migTable('settings') . " (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    )");

    // 1.1 – media.type als VARCHAR (für neue Typen wie 'measurement')
    $mediaType = migTableExists($pdo, 'media') ? migColumnType($pdo, 'media', 'type') : null;
    if ($mediaType !== null && $mediaType !== 'varchar') {
        $pdo->exec("ALTER TABLE " . migTable('media') . " MODIFY type VARCHAR(50) NOT NULL");
        $log[] = 'media.type auf VARCHAR umgestellt (1.1)';
    }

    // 1.2 – Geräte-Rubriken
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . migTable('device_categories') . " (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    if (migAddColumn($pdo, 'devices', 'category_id', 'INT NULL')) {
        $log[] = 'devices.category_id ergänzt (1.2)';
    }
    migAddForeignKey($pdo, 'devices', 'category_id', 'device_categories', 'SET NULL');

    // 1.3 – Klarnamen & Experiment-Autoren
    if (migAddColumn($pdo, 'users', 'full_name', 'VARCHAR(255) NULL AFTER username')) {
        $log[] = 'users.full_name ergänzt (1.3)';
    }
    if (migAddColumn($pdo, 'experiments', 'created_by', 'INT NULL')) {
        $log[] = 'experiments.created_by ergänzt (1.3)';
    }
    migAddForeignKey($pdo, 'experiments', 'created_by', 'users', 'SET NULL');

    // 1.4 – updated_at für Experimente
    if (migAddColumn($pdo, 'experiments', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at')) {
        $pdo->exec("UPDATE " . migTable('experiments') . " SET updated_at = created_at");
        $log[] = 'experiments.updated_at ergänzt (1.4)';
    }

    // 1.5 – Geräte-Bilder
    if (migAddColumn($pdo, 'devices', 'image_path', 'VARCHAR(255) NULL AFTER description')) {
        $log[] = 'devices.image_path ergänzt (1.5)';
    }

    // 1.6 – Themen-Hierarchie
    if (migAddColumn($pdo, 'topics', 'parent_id', 'INT NULL AFTER name')) {
        $log[] = 'topics.parent_id ergänzt (1.6)';
    }
    migAddForeignKey($pdo, 'topics', 'parent_id', 'topics', 'CASCADE');

    // 1.7 – Zentrale Standortverwaltung
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . migTable('locations') . " (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $addedLocation = migAddColumn($pdo, 'devices', 'location_id', 'INT NULL AFTER category_id');
    migAddForeignKey($pdo, 'devices', 'location_id', 'locations', 'SET NULL');
    if ($addedLocation && migColumnExists($pdo, 'devices', 'location_description')) {
        // Freitext-Standorte einmalig in die neue Tabelle übernehmen.
        $pdo->exec("INSERT INTO " . migTable('locations') . " (name)
            SELECT DISTINCT location_description FROM " . migTable('devices') . "
            WHERE location_description IS NOT NULL AND location_description != ''
            AND location_description NOT IN (SELECT name FROM " . migTable('locations') . ")");
        $pdo->exec("UPDATE " . migTable('devices') . " d JOIN " . migTable('locations') . " l
            ON d.location_description = l.name SET d.location_id = l.id WHERE d.location_id IS NULL");
        $log[] = 'Standorte in eigene Tabelle übernommen (1.7)';
    }

    // 1.8 – Separates Feld für Bedienungsanleitungen
    if (migAddColumn($pdo, 'devices', 'manual_path', 'VARCHAR(255) NULL AFTER image_path')) {
        $log[] = 'devices.manual_path ergänzt (1.8)';
    }

    // 1.9 – Online-Updater: Standard-Repository
    $stmt = $pdo->prepare("INSERT IGNORE INTO " . migTable('settings') . " (setting_key, setting_value) VALUES (?, ?)");
    $stmt->execute(['update_repo', 'hhochwald-fmb/laborbit']);

    // 1.9.1 – Login-Bremse gegen Passwort-Raten
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . migTable('login_attempts') . " (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL,
        INDEX idx_ip_time (ip, attempted_at)
    )");

    // 1.9.1 – Upload-Ordner gegen Skriptausführung absichern
    if (ensureUploadsProtection()) {
        $log[] = 'uploads/.htaccess angelegt (keine Skriptausführung im Upload-Ordner)';
    }

    // ── Neue Schritte hier unten anhängen ──────────────────────────────────

    migSetSetting($pdo, 'db_version', APP_VERSION);
    return $log;
}

/**
 * Wird bei jedem Seitenaufruf (aus db.php) aufgerufen. Weicht db_version von APP_VERSION ab,
 * laufen die Migrationen einmal. Fehler landen im error_log, die Seite läuft weiter.
 */
function autoMigrate(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM " . migTable('settings') . " WHERE setting_key = 'db_version'");
        $stmt->execute();
        $dbVersion = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // settings-Tabelle fehlt (Setup noch nicht abgeschlossen) – nichts tun.
        return;
    }

    if ($dbVersion === APP_VERSION) {
        return;
    }

    // Verhindert, dass zwei gleichzeitige Aufrufe parallel migrieren.
    $lockName = 'laborbit_migrate_' . DB_NAME;
    try {
        $locked = (int) $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 15)")->fetchColumn() === 1;
    } catch (PDOException $e) {
        $locked = false;
    }

    try {
        $log = runMigrations($pdo);
        if ($log) {
            error_log('[LabOrbit] Auto-Migration auf ' . APP_VERSION . ': ' . implode('; ', $log));
        }
    } catch (Throwable $e) {
        error_log('[LabOrbit] Auto-Migration auf ' . APP_VERSION . ' fehlgeschlagen: ' . $e->getMessage());
    } finally {
        if ($locked) {
            $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
        }
    }
}

/**
 * Legt uploads/.htaccess an bzw. aktualisiert sie (nur wenn sie von LabOrbit stammt).
 * uploads/ wird vom Updater nie angefasst, deshalb erledigt das die Migration.
 */
function ensureUploadsProtection(): bool
{
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) {
        return false;
    }
    $file = $dir . '/.htaccess';
    $content = <<<'HTACCESS'
# LabOrbit: Upload-Ordner absichern (automatisch erzeugt, wird bei Updates aktualisiert)
# Keine Skripte und kein aktives HTML aus dem Upload-Ordner ausführen bzw. ausliefern.
<FilesMatch "(?i)\.(php\d*|phtml|phar|pht|phps|inc|cgi|pl|py|sh|html?|xhtml|shtml|svgz?|js|mjs|htaccess|user\.ini)$">
    Require all denied
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>
HTACCESS;
    if (is_file($file)) {
        $current = (string) file_get_contents($file);
        if ($current === $content || strpos($current, '# LabOrbit:') !== 0) {
            return false; // aktuell oder vom Admin selbst angelegt – nicht überschreiben
        }
    }
    return @file_put_contents($file, $content, LOCK_EX) !== false;
}
