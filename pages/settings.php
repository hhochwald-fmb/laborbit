<?php
if (!defined('DB_HOST')) exit; // Protect direct access

$isManager = ($_SESSION['role'] === 'manager');
if (!$isManager) {
    echo "<div class='p-6 bg-red-100 text-red-700 rounded-xl'>Zugriff verweigert. Nur für Manager.</div>";
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settingsToSave = [
        'school_name' => trim($_POST['school_name']),
        'theme' => $_POST['theme']
    ];

    $stmt = $pdo->prepare("REPLACE INTO " . DB_PREFIX . "settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settingsToSave as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    $success = "Einstellungen erfolgreich gespeichert!";
    
    // Refresh settings in current scope to show them immediately
    $stmt2 = $pdo->query("SELECT setting_key, setting_value FROM " . DB_PREFIX . "settings");
    $settings = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);
}

?>

<div class="max-w-2xl bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
    <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">⚙️ Einstellungen</h2>
    
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
            <span class="block sm:inline"><?php echo $success; ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name der Schule</label>
            <input type="text" name="school_name" value="<?php echo htmlspecialchars($settings['school_name'] ?? 'Unsere Schule'); ?>" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Farbschema (Theme)</label>
            <select name="theme" class="custom-select bg-white w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:outline-none focus:ring-2 focus:ring-primary/50 dark:bg-slate-700 dark:text-white">
                <option value="light" <?php echo ($settings['theme']??'') === 'light' ? 'selected' : ''; ?>>Hell (Standard Blau)</option>
                <option value="dark" <?php echo ($settings['theme']??'') === 'dark' ? 'selected' : ''; ?>>Dunkel (Standard Blau)</option>
                <option value="light_emerald" <?php echo ($settings['theme']??'') === 'light_emerald' ? 'selected' : ''; ?>>Hell (Labor-Grün)</option>
                <option value="dark_amber" <?php echo ($settings['theme']??'') === 'dark_amber' ? 'selected' : ''; ?>>Dunkel (Bernstein)</option>
                <option value="dark_cyber" <?php echo ($settings['theme']??'') === 'dark_cyber' ? 'selected' : ''; ?>>Dunkel (Neon-Lila)</option>
            </select>
            <p class="mt-1 text-xs text-slate-500">Ein Neuladen der Seite ist erforderlich, damit das Theme greift.</p>
        </div>
        
        <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
            <button type="submit" name="save_settings" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-base font-bold text-white bg-primary hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors">
                Einstellungen speichern
            </button>
        </div>
    </form>
</div>
