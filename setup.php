<?php
session_start();

$configFile = __DIR__ . '/config.php';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$message = '';

if (file_exists($configFile)) {
    require_once $configFile;
    try {
        $checkPdo = new PDO("mysql:host=" . (defined('DB_HOST') ? DB_HOST : 'localhost') . ";dbname=" . (defined('DB_NAME') ? DB_NAME : 'psm_db') . ";charset=utf8mb4", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '');
        $tableExists = $checkPdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
        if ($tableExists) {
            $managerCount = $checkPdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "users WHERE role = 'manager'")->fetchColumn();
            if ($managerCount > 0) {
                die("<!DOCTYPE html><html lang='de'><head><meta charset='UTF-8'><title>Setup Gesperrt</title><style>body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0;} .box { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; max-width: 400px; text-align: center; } h1 { color: #dc2626; margin-top:0;}</style></head><body><div class='box'><h1>Sicherheits-Sperre</h1><p>Das Setup wurde bereits abgearbeitet und das System besitzt einen Administrator.</p><p>Aus Sicherheitsgründen wurde <code>setup.php</code> gesperrt. Bitte lösche diese Datei von deinem Server.</p><a href='index.php' style='display:inline-block; margin-top:1rem; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px;'>Zum Login</a></div></body></html>");
            }
        }
    } catch (Exception $e) {}
}

// Step 1: Database Setup
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbUser = $_POST['db_user'] ?? 'root';
    $dbPass = $_POST['db_pass'] ?? 'root';
    $dbName = $_POST['db_name'] ?? 'psm_db';
    $dbPrefix = $_POST['db_prefix'] ?? '';

    // Test connection
    try {
        // First approach: try connecting to the specific database directly (perfect for shared hosting where DB is manually created)
        try {
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $eDirect) {
            // Second approach: connecting to root server and attempting to create the DB (perfect for local/VPS)
            $pdo = new PDO("mysql:host=$dbHost", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        
        $configContent = "<?php\n"
            . "define('DB_HOST', '$dbHost');\n"
            . "define('DB_USER', '$dbUser');\n"
            . "define('DB_PASS', '$dbPass');\n"
            . "define('DB_NAME', '$dbName');\n"
            . "define('DB_PREFIX', '$dbPrefix');\n\n"
            . "// ==========================================\n"
            . "// NOTFALL-LOGIN FÜR ADMINS\n"
            . "// ==========================================\n"
            . "// Falls du das Passwort für den Manager-Account vergessen hast,\n"
            . "// entferne die zwei Schrägstriche (//) in der nächsten Zeile\n"
            . "// und ändere das Passwort auf ein neues deiner Wahl.\n"
            . "// Logge dich dann auf der Login-Seite mit einem beliebigen Benutzernamen\n"
            . "// und exakt diesem neuen Passwort ein.\n"
            . "// WICHTIG: NACH DEM LOGIN DIESE ZEILE WIEDER AUSKOMMENTIEREN!\n"
            . "// define('EMERGENCY_ADMIN_PASS', 'DeinNeuesPasswort123!');\n"
            . "?>";
        
        if (file_put_contents($configFile, $configContent)) {
            header('Location: ?step=2');
            exit;
        } else {
            $message = "Konnte 'includes/config.php' nicht schreiben. Bitte überprüfe die Ordnerrechte.";
        }
    } catch (PDOException $e) {
        $message = "Datenbankverbindung fehlgeschlagen: " . $e->getMessage();
    }
}

// Step 2: Table Creation and Admin User
if ($step === 2) {
    if (!file_exists($configFile)) {
        header('Location: ?step=1');
        exit;
    }
    require_once $configFile;

    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $adminUser = $_POST['admin_user'] ?? 'admin';
            $adminPass = $_POST['admin_pass'] ?? '';

            if (strlen($adminPass) < 6) {
                $message = "Das Passwort muss mindestens 6 Zeichen lang sein.";
            } else {
                // RUN SQL SCHEMA SCRIPTS
                
                // Settings
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "settings (
                    setting_key VARCHAR(50) PRIMARY KEY,
                    setting_value TEXT
                )");

                // Users
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    full_name VARCHAR(100),
                    password_hash VARCHAR(255) NOT NULL,
                    role ENUM('manager', 'teacher', 'viewer') DEFAULT 'viewer',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                // Classes
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "classes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) NOT NULL
                )");

                // Topics
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "topics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    parent_id INT DEFAULT NULL,
                    FOREIGN KEY (parent_id) REFERENCES " . DB_PREFIX . "topics(id) ON DELETE CASCADE
                )");

                // Device Categories
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "device_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL
                )");

                // Locations
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "locations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                // Devices
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "devices (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(150) NOT NULL,
                    location_id INT DEFAULT NULL,
                    location_description TEXT,
                    description TEXT,
                    category_id INT DEFAULT NULL,
                    image_path VARCHAR(255) DEFAULT NULL,
                    manual_path VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (category_id) REFERENCES " . DB_PREFIX . "device_categories(id) ON DELETE SET NULL,
                    FOREIGN KEY (location_id) REFERENCES " . DB_PREFIX . "locations(id) ON DELETE SET NULL
                )");

                // Experiments
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "experiments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(200) NOT NULL,
                    description TEXT,
                    setup_instructions TEXT,
                    tips TEXT,
                    measurements TEXT,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (created_by) REFERENCES " . DB_PREFIX . "users(id) ON DELETE SET NULL
                )");

                // Relations
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "experiment_device (
                    experiment_id INT,
                    device_id INT,
                    PRIMARY KEY(experiment_id, device_id),
                    FOREIGN KEY(experiment_id) REFERENCES " . DB_PREFIX . "experiments(id) ON DELETE CASCADE,
                    FOREIGN KEY(device_id) REFERENCES " . DB_PREFIX . "devices(id) ON DELETE CASCADE
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "experiment_class (
                    experiment_id INT,
                    class_id INT,
                    PRIMARY KEY(experiment_id, class_id),
                    FOREIGN KEY(experiment_id) REFERENCES " . DB_PREFIX . "experiments(id) ON DELETE CASCADE,
                    FOREIGN KEY(class_id) REFERENCES " . DB_PREFIX . "classes(id) ON DELETE CASCADE
                )");

                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "experiment_topic (
                    experiment_id INT,
                    topic_id INT,
                    PRIMARY KEY(experiment_id, topic_id),
                    FOREIGN KEY(experiment_id) REFERENCES " . DB_PREFIX . "experiments(id) ON DELETE CASCADE,
                    FOREIGN KEY(topic_id) REFERENCES " . DB_PREFIX . "topics(id) ON DELETE CASCADE
                )");

                // Media
                $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "media (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    experiment_id INT,
                    type VARCHAR(50) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY(experiment_id) REFERENCES " . DB_PREFIX . "experiments(id) ON DELETE CASCADE
                )");

                // Create Upload Folders if missing
                $dirs = ['uploads/photos', 'uploads/videos', 'uploads/pdfs', 'uploads/measurements', 'uploads/devices', 'uploads/risk_assessments'];
                foreach ($dirs as $d) {
                    if (!file_exists(__DIR__ . '/' . $d)) {
                        mkdir(__DIR__ . '/' . $d, 0777, true);
                    }
                }

                // Insert App Defaults
                $stmt = $pdo->prepare("INSERT IGNORE INTO " . DB_PREFIX . "settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute(['app_name', 'PhysikSammlung Manager']);
                $stmt->execute(['school_name', 'Unsere Schule']);
                $stmt->execute(['theme', 'dark']);
                $stmt->execute(['db_version', '1.8']);

                // Create Admin
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT IGNORE INTO " . DB_PREFIX . "users (username, password_hash, role) VALUES (?, ?, 'manager')");
                $stmt->execute([$adminUser, $hash]);

                header('Location: ?step=3');
                exit;
            }
        }
    } catch (PDOException $e) {
        $message = "Setup-Fehler: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - PhysikSammlung</title>
    <style>
        :root { --primary: #3b82f6; --bg: #f8fafc; --surface: #ffffff; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .container { background: var(--surface); padding: 2rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); width: 100%; max-width: 400px; }
        h1 { margin-top: 0; font-size: 1.5rem; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 0.75rem; background: var(--primary); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #2563eb; }
        .error { background: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .success { background: #dcfce3; color: #166534; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; text-align: center;}
    </style>
</head>
<body>
    <div class="container">
        <h1>Installation</h1>
        <?php if ($message): ?>
            <div class="error"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <p style="text-size:0.9rem; text-align:center; color:#64748b;">Schritt 1: Datenbank-Verbindung konfigurieren</p>
            <form method="POST">
                <div class="form-group">
                    <label>MySQL Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>MySQL Benutzer</label>
                    <input type="text" name="db_user" value="root" required>
                </div>
                <div class="form-group">
                    <label>MySQL Passwort</label>
                    <input type="password" name="db_pass" value="root">
                </div>
                <div class="form-group">
                    <label>Datenbankname</label>
                    <input type="text" name="db_name" value="psm_db" required>
                </div>
                <div class="form-group">
                    <label>Tabellen-Präfix (Optional)</label>
                    <input type="text" name="db_prefix" value="" placeholder="z.B. LO_">
                </div>
                <button type="submit">Verbinden und Installieren</button>
            </form>
        <?php elseif ($step === 2): ?>
            <p style="text-size:0.9rem; text-align:center; color:#64748b;">Schritt 2: Manager-Account anlegen</p>
            <form method="POST">
                <div class="form-group">
                    <label>Manager Benutzername</label>
                    <input type="text" name="admin_user" value="admin" required>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="admin_pass" required>
                </div>
                <button type="submit">Installieren</button>
            </form>
        <?php elseif ($step === 3): ?>
            <div class="success">Installation erfolgreich abgeschlossen!</div>
            <a href="index.php"><button>Zum Login</button></a>
            <p style="margin-top: 1rem; font-size: 0.8rem; text-align: center; color: #64748b;">Bitte lösche aus Sicherheitsgründen die setup.php, sobald alles läuft.</p>
        <?php endif; ?>
    </div>
</body>
</html>
