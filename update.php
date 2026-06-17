<?php
session_start();
$configFile = __DIR__ . '/config.php';

if (!file_exists($configFile)) {
    die("Keine Konfigurationsdatei gefunden. Bitte führe zuerst das Setup aus.");
}

require_once $configFile;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
}

// Ensure the user is a manager (or at least logged in as one, but for updates we just do it if they run the script)
// To keep it simple but safe, let's just make it a public run script for the admin, but ideally it should check login.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'manager') {
    die("Updates können nur von einem eingeloggten Manager-Account durchgeführt werden. Bitte logge dich zuerst ein.");
}

// Get current DB Version
$stmt = $pdo->query("SELECT setting_value FROM " . DB_PREFIX . "settings WHERE setting_key = 'db_version'");
$currentVersion = $stmt->fetchColumn();

if (!$currentVersion) {
    $currentVersion = '1.0'; // Fallback if missing
}

$updatesApplied = 0;
$messages = [];

// ==========================================
// UPDATE ROUTINES
// ==========================================

// Update 1.0 -> 1.1
if (version_compare($currentVersion, '1.1', '<')) {
    try {
        // Change media.type from ENUM to VARCHAR to support new types like 'measurement'
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "media MODIFY type VARCHAR(50) NOT NULL");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.1' WHERE setting_key = 'db_version'");
        $currentVersion = '1.1';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.0 auf 1.1 geupdatet (Struktur für Messergebnis-Dateien).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.1: " . $e->getMessage();
    }
}

// Update 1.1 -> 1.2
if (version_compare($currentVersion, '1.2', '<')) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "device_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN category_id INT NULL");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD FOREIGN KEY (category_id) REFERENCES " . DB_PREFIX . "device_categories(id) ON DELETE SET NULL");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.2' WHERE setting_key = 'db_version'");
        $currentVersion = '1.2';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.1 auf 1.2 geupdatet (Geräte-Rubriken).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.2: " . $e->getMessage();
    }
}

// Update 1.2 -> 1.3
if (version_compare($currentVersion, '1.3', '<')) {
    try {
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN full_name VARCHAR(255) NULL AFTER username");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "experiments ADD COLUMN created_by INT NULL");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "experiments ADD FOREIGN KEY (created_by) REFERENCES " . DB_PREFIX . "users(id) ON DELETE SET NULL");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.3' WHERE setting_key = 'db_version'");
        $currentVersion = '1.3';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.2 auf 1.3 geupdatet (Benutzer-Klarnamen & Experiment-Autoren).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.3: " . $e->getMessage();
    }
}

// Update 1.3 -> 1.4
if (version_compare($currentVersion, '1.4', '<')) {
    try {
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "experiments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        $pdo->exec("UPDATE " . DB_PREFIX . "experiments SET updated_at = created_at");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.4' WHERE setting_key = 'db_version'");
        $currentVersion = '1.4';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.3 auf 1.4 geupdatet (updated_at Zeitstempel & Videos).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.4: " . $e->getMessage();
    }
}

// Update 1.4 -> 1.5
if (version_compare($currentVersion, '1.5', '<')) {
    try {
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN image_path VARCHAR(255) NULL AFTER description");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.5' WHERE setting_key = 'db_version'");
        $currentVersion = '1.5';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.4 auf 1.5 geupdatet (Geräte-Bilder).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.5: " . $e->getMessage();
    }
}



// Update 1.5 -> 1.6
if (version_compare($currentVersion, '1.6', '<')) {
    try {
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "topics ADD COLUMN parent_id INT NULL AFTER name");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "topics ADD FOREIGN KEY (parent_id) REFERENCES " . DB_PREFIX . "topics(id) ON DELETE CASCADE");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.6' WHERE setting_key = 'db_version'");
        $currentVersion = '1.6';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.5 auf 1.6 geupdatet (Themen-Hierarchie).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.6: " . $e->getMessage();
    }
}

// Update 1.6 -> 1.7
if (version_compare($currentVersion, '1.7', '<')) {
    try {
        // 1. Create locations table
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // 2. Add location_id to devices
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN location_id INT NULL AFTER category_id");
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD FOREIGN KEY (location_id) REFERENCES " . DB_PREFIX . "locations(id) ON DELETE SET NULL");
        
        // 3. Migrate existing location_description strings to the new locations table
        $pdo->exec("INSERT INTO " . DB_PREFIX . "locations (name) 
                    SELECT DISTINCT location_description 
                    FROM " . DB_PREFIX . "devices 
                    WHERE location_description IS NOT NULL AND location_description != ''
                    AND location_description NOT IN (SELECT name FROM " . DB_PREFIX . "locations)");
        
        // 4. Link devices to their new location IDs
        $pdo->exec("UPDATE " . DB_PREFIX . "devices d 
                    JOIN " . DB_PREFIX . "locations l ON d.location_description = l.name 
                    SET d.location_id = l.id 
                    WHERE d.location_id IS NULL");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.7' WHERE setting_key = 'db_version'");
        $currentVersion = '1.7';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.6 auf 1.7 geupdatet (Zentrale Standortverwaltung & Datenmigration).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.7: " . $e->getMessage();
    }
}

// Update 1.7 -> 1.8
if (version_compare($currentVersion, '1.8', '<')) {
    try {
        $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN manual_path VARCHAR(255) NULL AFTER image_path");
        
        $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '1.8' WHERE setting_key = 'db_version'");
        $currentVersion = '1.8';
        $updatesApplied++;
        $messages[] = "Erfolgreich von 1.7 auf 1.8 geupdatet (Separates Feld für Bedienungsanleitungen).";
    } catch (Exception $e) {
        $messages[] = "Fehler bei Update auf 1.8: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Update - PhysikSammlung</title>
    <style>
        :root { --primary: #3b82f6; --bg: #f8fafc; --surface: #ffffff; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .container { background: var(--surface); padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); width: 100%; max-width: 500px; text-align: center; }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; }
        .msg { background: #dcfce3; color: #166534; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; text-align: left; }
        .info { background: #e0f2fe; color: #0284c7; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem;}
        button { width: 100%; padding: 0.75rem; background: var(--primary); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Datenbank Update</h1>
        
        <?php if ($updatesApplied > 0): ?>
            <?php foreach ($messages as $m): ?>
                <div class="msg"><?php echo htmlspecialchars($m); ?></div>
            <?php endforeach; ?>
            <p>Dein System ist jetzt auf Version <?php echo $currentVersion; ?>!</p>
        <?php else: ?>
            <div class="info">Kein Update notwendig. Dein System ist bereits auf der neuesten Version (<?php echo $currentVersion; ?>).</div>
        <?php endif; ?>
        
        <br>
        <a href="index.php"><button>Zurück zum Dashboard</button></a>
    </div>
</body>
</html>
