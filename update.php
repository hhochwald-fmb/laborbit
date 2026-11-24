<?php
// Notfall-Aufruf für die Datenbank-Migrationen (normalerweise laufen sie automatisch,
// siehe includes/migrations.php, oder per Admin → System → "Datenbank prüfen & updaten").
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isAdmin()) {
    die("Updates können nur von einem eingeloggten Manager-Account durchgeführt werden. Bitte logge dich zuerst ein.");
}

$messages = [];
$failed = false;
try {
    $messages = runMigrations($pdo);
} catch (Throwable $e) {
    $failed = true;
    $messages[] = "Fehler bei der Migration: " . $e->getMessage();
}
$updatesApplied = count($messages);
$currentVersion = APP_VERSION;
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
            <?php if (!$failed): ?><p>Dein System ist jetzt auf Version <?php echo htmlspecialchars($currentVersion); ?>!</p><?php endif; ?>
        <?php else: ?>
            <div class="info">Kein Update notwendig. Dein System ist bereits auf der neuesten Version (<?php echo htmlspecialchars($currentVersion); ?>).</div>
        <?php endif; ?>
        
        <br>
        <a href="index.php"><button>Zurück zum Dashboard</button></a>
    </div>
</body>
</html>
