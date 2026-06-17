<?php
if (!defined('DB_HOST')) exit;

$isManager = ($_SESSION['role'] === 'manager');
if (!$isManager) {
    echo "<div class='p-6 bg-red-100 text-red-700 rounded-xl'>Zugriff verweigert. Nur für Manager.</div>";
    return;
}

$dbVersion    = $pdo->query("SELECT setting_value FROM " . DB_PREFIX . "settings WHERE setting_key = 'db_version'")->fetchColumn() ?: '1.0';
$latestVersion = LABORBIT_VERSION;
$isUpToDate   = version_compare($dbVersion, $latestVersion, '>=');

$updateMessages = [];
$cleanupMessages = [];
$orphanedFiles  = [];

// ── DB UPDATE ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_update'])) {
    $cv = $dbVersion;

    $migrations = [
        '1.1' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "media MODIFY type VARCHAR(50) NOT NULL");
        },
        '1.2' => function() use ($pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "device_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN IF NOT EXISTS category_id INT NULL");
        },
        '1.3' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) NULL AFTER username");
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "experiments ADD COLUMN IF NOT EXISTS created_by INT NULL");
        },
        '1.4' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "experiments ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        },
        '1.5' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL AFTER description");
        },
        '1.6' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "topics ADD COLUMN IF NOT EXISTS parent_id INT NULL AFTER name");
        },
        '1.7' => function() use ($pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS " . DB_PREFIX . "locations (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN IF NOT EXISTS location_id INT NULL AFTER category_id");
        },
        '1.8' => function() use ($pdo) {
            $pdo->exec("ALTER TABLE " . DB_PREFIX . "devices ADD COLUMN IF NOT EXISTS manual_path VARCHAR(255) NULL AFTER image_path");
        },
    ];

    $applied = 0;
    foreach ($migrations as $target => $fn) {
        if (version_compare($cv, $target, '<')) {
            try {
                $fn();
                $pdo->exec("UPDATE " . DB_PREFIX . "settings SET setting_value = '$target' WHERE setting_key = 'db_version'");
                $cv = $target;
                $applied++;
                $updateMessages[] = ['ok', "✅ Migration auf $target erfolgreich."];
            } catch (Exception $e) {
                $updateMessages[] = ['err', "❌ Fehler bei Migration auf $target: " . $e->getMessage()];
                break;
            }
        }
    }

    if ($applied === 0) {
        $updateMessages[] = ['info', "Datenbank ist bereits auf dem neuesten Stand ($cv)."];
    }
    $dbVersion  = $cv;
    $isUpToDate = version_compare($dbVersion, $latestVersion, '>=');
}

// ── ORPHAN CLEANUP ───────────────────────────────────────────────────────────
$uploadsBase = __DIR__ . '/../uploads';

$dirMapping = [
    'devices'          => ['devices' => ['image_path', 'manual_path']],
    'photos'           => ['media'   => ['file_path', "type='photo'"]],
    'videos'           => ['media'   => ['file_path', "type='video'"]],
    'pdfs'             => ['media'   => ['file_path', "type='pdf'"]],
    'measurements'     => ['media'   => ['file_path', "type='measurement'"]],
    'risk_assessments' => ['media'   => ['file_path', "type='risk_assessment'"]],
];

function getDbFilesForDir(PDO $pdo, string $dir): array {
    $prefix = DB_PREFIX;
    $files = [];
    switch ($dir) {
        case 'devices':
            try {
                foreach ($pdo->query("SELECT image_path, manual_path FROM {$prefix}devices WHERE image_path IS NOT NULL OR manual_path IS NOT NULL")->fetchAll() as $row) {
                    if ($row['image_path']) $files[] = $row['image_path'];
                    if ($row['manual_path']) $files[] = $row['manual_path'];
                }
            } catch (PDOException $e) {
                // manual_path column may not exist yet — fall back to image_path only
                foreach ($pdo->query("SELECT image_path FROM {$prefix}devices WHERE image_path IS NOT NULL")->fetchAll() as $row) {
                    if ($row['image_path']) $files[] = $row['image_path'];
                }
            }
            break;
        case 'photos':
            $files = $pdo->query("SELECT file_path FROM {$prefix}media WHERE type='photo'")->fetchAll(PDO::FETCH_COLUMN);
            break;
        case 'videos':
            $files = $pdo->query("SELECT file_path FROM {$prefix}media WHERE type='video'")->fetchAll(PDO::FETCH_COLUMN);
            break;
        case 'pdfs':
            $files = $pdo->query("SELECT file_path FROM {$prefix}media WHERE type='pdf'")->fetchAll(PDO::FETCH_COLUMN);
            break;
        case 'measurements':
            $files = $pdo->query("SELECT file_path FROM {$prefix}media WHERE type='measurement'")->fetchAll(PDO::FETCH_COLUMN);
            break;
        case 'risk_assessments':
            $files = $pdo->query("SELECT file_path FROM {$prefix}media WHERE type='risk_assessment'")->fetchAll(PDO::FETCH_COLUMN);
            break;
    }
    return array_filter($files);
}

// Collect all orphans
$allOrphans = [];
foreach (array_keys($dirMapping) as $subdir) {
    $fullPath = $uploadsBase . '/' . $subdir;
    if (!is_dir($fullPath)) continue;
    $dbFiles = array_map('strtolower', getDbFilesForDir($pdo, $subdir));
    foreach (scandir($fullPath) as $file) {
        if ($file === '.' || $file === '..') continue;
        if (!in_array(strtolower($file), $dbFiles)) {
            $filePath = $fullPath . '/' . $file;
            $allOrphans[] = [
                'subdir' => $subdir,
                'file'   => $file,
                'path'   => $filePath,
                'size'   => filesize($filePath),
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_orphans'])) {
    $toDelete = $_POST['orphan_files'] ?? [];
    $deleted = 0;
    $errors  = 0;
    foreach ($toDelete as $encoded) {
        $filePath = $uploadsBase . '/' . base64_decode($encoded);
        // Safety: must stay inside uploads directory
        $real = realpath($filePath);
        $base = realpath($uploadsBase);
        if ($real && $base && strncmp($real, $base, strlen($base)) === 0) {
            if (@unlink($real)) $deleted++;
            else $errors++;
        }
    }
    $cleanupMessages[] = $deleted > 0
        ? ['ok',  "✅ $deleted Datei(en) erfolgreich gelöscht."]
        : ['info', "Nichts gelöscht."];
    if ($errors > 0) $cleanupMessages[] = ['err', "❌ $errors Datei(en) konnten nicht gelöscht werden."];
    // Refresh orphan list
    $allOrphans = array_filter($allOrphans, fn($o) => in_array($o['subdir'] . '/' . $o['file'], array_map(fn($e) => implode('/', [explode('/', base64_decode($e))[0], explode('/', base64_decode($e))[1]]), $toDelete)) === false && file_exists($o['path']));
    // Re-scan cleanly
    $allOrphans = [];
    foreach (array_keys($dirMapping) as $subdir) {
        $fullPath = $uploadsBase . '/' . $subdir;
        if (!is_dir($fullPath)) continue;
        $dbFiles = array_map('strtolower', getDbFilesForDir($pdo, $subdir));
        foreach (scandir($fullPath) as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!in_array(strtolower($file), $dbFiles)) {
                $filePath = $fullPath . '/' . $file;
                if (file_exists($filePath)) {
                    $allOrphans[] = ['subdir' => $subdir, 'file' => $file, 'path' => $filePath, 'size' => filesize($filePath)];
                }
            }
        }
    }
}

$totalOrphanSize = array_sum(array_column($allOrphans, 'size'));

function fmtBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<div class="space-y-6 max-w-3xl">

    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">🛠️ System</h2>

    <!-- ── VERSION ── -->
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
        <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-4">Version</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 text-center">
                <div class="text-2xl font-black text-primary"><?php echo htmlspecialchars($dbVersion); ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 font-medium uppercase tracking-wider">Installiert</div>
            </div>
            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 text-center">
                <div class="text-2xl font-black text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($latestVersion); ?></div>
                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1 font-medium uppercase tracking-wider">Aktuellste</div>
            </div>
            <div class="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 text-center col-span-2 sm:col-span-1">
                <?php if ($isUpToDate): ?>
                    <div class="text-2xl">✅</div>
                    <div class="text-xs text-emerald-600 dark:text-emerald-400 mt-1 font-bold uppercase tracking-wider">Aktuell</div>
                <?php else: ?>
                    <div class="text-2xl">⬆️</div>
                    <div class="text-xs text-amber-600 dark:text-amber-400 mt-1 font-bold uppercase tracking-wider">Update verfügbar</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── DB UPDATE ── -->
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
        <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-1">Datenbank-Update</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Prüft und wendet fehlende Datenbank-Migrationen an. Sicher mehrfach ausführbar.</p>

        <?php foreach ($updateMessages as [$type, $msg]): ?>
            <div class="mb-3 px-4 py-3 rounded-lg text-sm font-medium
                <?php echo $type === 'ok' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800'
                    : ($type === 'err' ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-300 border border-red-200 dark:border-red-800'
                    : 'bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 border border-blue-200 dark:border-blue-800'); ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>

        <form method="POST">
            <button type="submit" name="run_update"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-blue-600 text-white font-bold rounded-lg shadow-sm shadow-primary/20 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                Datenbank jetzt prüfen &amp; updaten
            </button>
        </form>
    </div>

    <!-- ── ORPHANED FILES ── -->
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
        <div class="flex items-start justify-between mb-1">
            <h3 class="text-lg font-bold text-slate-800 dark:text-white">Datei-Bereinigung</h3>
            <?php if (!empty($allOrphans)): ?>
                <span class="text-xs font-bold px-2.5 py-1 bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 rounded-full">
                    <?php echo count($allOrphans); ?> Datei(en) · <?php echo fmtBytes($totalOrphanSize); ?>
                </span>
            <?php endif; ?>
        </div>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Dateien in den Upload-Ordnern, die keinem Datensatz mehr zugeordnet sind.</p>

        <?php foreach ($cleanupMessages as [$type, $msg]): ?>
            <div class="mb-3 px-4 py-3 rounded-lg text-sm font-medium
                <?php echo $type === 'ok' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800'
                    : ($type === 'err' ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-300 border border-red-200 dark:border-red-800'
                    : 'bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 border border-blue-200 dark:border-blue-800'); ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endforeach; ?>

        <?php if (empty($allOrphans)): ?>
            <div class="flex items-center gap-3 px-4 py-3 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg text-sm text-emerald-800 dark:text-emerald-300 font-medium">
                ✅ Keine verwaisten Dateien gefunden. Alles sauber!
            </div>
        <?php else: ?>
            <form method="POST" onsubmit="return confirm('<?php echo count($allOrphans); ?> markierte Datei(en) wirklich löschen?');">
                <div class="border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-2 text-left">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" id="select-all" class="rounded border-slate-300 text-primary focus:ring-primary">
                                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Alle</span>
                                    </label>
                                </th>
                                <th class="px-4 py-2 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Datei</th>
                                <th class="px-4 py-2 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Ordner</th>
                                <th class="px-4 py-2 text-right text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Größe</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                            <?php foreach ($allOrphans as $o): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                                    <td class="px-4 py-2">
                                        <input type="checkbox" name="orphan_files[]"
                                            value="<?php echo htmlspecialchars(base64_encode($o['subdir'] . '/' . $o['file'])); ?>"
                                            class="orphan-checkbox rounded border-slate-300 text-red-500 focus:ring-red-400" checked>
                                    </td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-700 dark:text-slate-300 max-w-[260px] truncate" title="<?php echo htmlspecialchars($o['file']); ?>">
                                        <?php echo htmlspecialchars($o['file']); ?>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">
                                            <?php echo htmlspecialchars($o['subdir']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap">
                                        <?php echo fmtBytes($o['size']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="delete_orphans"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg shadow-sm transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    Markierte Dateien löschen
                </button>
            </form>

            <script>
            document.getElementById('select-all').addEventListener('change', function() {
                document.querySelectorAll('.orphan-checkbox').forEach(cb => cb.checked = this.checked);
            });
            </script>
        <?php endif; ?>
    </div>

</div>
