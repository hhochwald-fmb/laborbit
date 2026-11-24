<?php
if (!defined('DB_HOST')) exit; // Protect direct access

if (!isAdmin()) {
    echo "<div class='p-6 bg-red-100 text-red-700 rounded-xl'>Zugriff verweigert. Nur für Manager.</div>";
    return;
}

require_once __DIR__ . '/../includes/updater.php';

$selfUrl   = '?page=updates';
$isGit     = updaterIsGitCheckout();
$hasZip    = updaterZipAvailable();
$blocked   = $isGit || !$hasZip;

// ── Aktionen (POST → Redirect → GET) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action'])) {
    requireAdmin();
    if (!csrfValid()) {
        setFlash('err', 'Sicherheitsprüfung fehlgeschlagen (Sitzung abgelaufen?). Bitte erneut versuchen.');
        redirectTo($selfUrl);
    }

    $action = (string) $_POST['update_action'];
    try {
        switch ($action) {
            case 'check':
                $check = updaterCheck($pdo);
                $_SESSION['update_check'] = $check;
                if (!$check['available']) {
                    setFlash('ok', 'LabOrbit ist aktuell (installiert: ' . APP_VERSION . ', neuestes Release: ' . $check['version'] . ').');
                }
                break;

            case 'install':
                if ($blocked) {
                    throw new RuntimeException('Online-Update ist auf diesem Server nicht möglich.');
                }
                $check = $_SESSION['update_check'] ?? null;
                if (!is_array($check) || empty($check['available']) || ($check['current'] ?? '') !== APP_VERSION) {
                    throw new RuntimeException('Bitte zuerst erneut nach Updates suchen.');
                }
                $zipPath = updaterDownload($check['download_url'], $check['repo']);
                $result = updaterInstall($zipPath);
                unset($_SESSION['update_check']);
                setFlash('ok', "LabOrbit {$result['version']} wurde installiert ({$result['files']} Dateien). "
                    . "Backup der alten Version: {$result['backup']}. Datenbank-Migrationen laufen beim nächsten Seitenaufruf automatisch.");
                break;

            case 'upload':
                if ($blocked) {
                    throw new RuntimeException('Update-Installation ist auf diesem Server nicht möglich.');
                }
                $file = $_FILES['update_zip'] ?? null;
                if (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $code = is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
                    throw new RuntimeException($code === UPLOAD_ERR_NO_FILE
                        ? 'Bitte eine ZIP-Datei auswählen.'
                        : "Upload fehlgeschlagen (Fehlercode $code – evtl. zu groß für upload_max_filesize).");
                }
                if ($file['size'] > UPDATER_MAX_DOWNLOAD) {
                    throw new RuntimeException('Die Datei ist größer als ' . round(UPDATER_MAX_DOWNLOAD / 1048576) . ' MB.');
                }
                $zipPath = updaterTempZipPath();
                if (!move_uploaded_file($file['tmp_name'], $zipPath)) {
                    throw new RuntimeException('Die hochgeladene Datei konnte nicht gespeichert werden (storage/tmp).');
                }
                $result = updaterInstall($zipPath);
                unset($_SESSION['update_check']);
                setFlash('ok', "Update-Paket {$result['version']} wurde installiert ({$result['files']} Dateien). "
                    . "Backup der alten Version: {$result['backup']}.");
                break;

            case 'save_repo':
                $repo = trim((string) ($_POST['update_repo'] ?? ''));
                if ($repo === '') {
                    $repo = UPDATER_DEFAULT_REPO;
                }
                if (!updaterValidRepo($repo)) {
                    throw new RuntimeException('Ungültiges Repository. Erwartet wird das Format "besitzer/repository".');
                }
                $stmt = $pdo->prepare("REPLACE INTO " . DB_PREFIX . "settings (setting_key, setting_value) VALUES ('update_repo', ?)");
                $stmt->execute([$repo]);
                unset($_SESSION['update_check']);
                setFlash('ok', "Update-Quelle gespeichert: $repo");
                break;
        }
    } catch (Throwable $e) {
        setFlash('err', $e->getMessage());
    }
    redirectTo($selfUrl);
}

updaterCleanupTmp();

$repo      = updaterGetRepo($pdo);
$check     = $_SESSION['update_check'] ?? null;
if (is_array($check) && (($check['current'] ?? '') !== APP_VERSION || ($check['repo'] ?? '') !== $repo)) {
    $check = null; // veraltetes Prüfergebnis (andere Version installiert oder Quelle geändert)
}
$backups   = updaterListBackups();
$flashes   = takeFlashes();
$maxUpload = ini_get('upload_max_filesize');

$btnPrimary = 'inline-flex items-center gap-2 px-5 py-2.5 bg-primary hover:bg-blue-600 text-white font-bold rounded-lg shadow-sm shadow-primary/20 transition-colors disabled:opacity-50 disabled:cursor-not-allowed';
$card       = 'bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700';
$fmtSize    = fn(int $b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';
?>

<div class="space-y-6 max-w-3xl">

    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">🔄 Updates</h2>

    <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="px-4 py-3 rounded-lg text-sm font-medium border
            <?php echo $type === 'ok'
                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
                : 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-300 border-red-200 dark:border-red-800'; ?>">
            <?php echo $type === 'ok' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endforeach; ?>

    <?php if ($isGit): ?>
        <div class="px-4 py-3 rounded-lg text-sm bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
            ℹ️ Diese Installation ist ein <strong>git-Checkout</strong>. Bitte auf dem Server mit
            <code class="px-1 rounded bg-blue-100 dark:bg-blue-900/50">git pull</code> aktualisieren – das Online-Update ist hier deaktiviert.
        </div>
    <?php endif; ?>
    <?php if (!$hasZip): ?>
        <div class="px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
            ⚠️ Die PHP-Erweiterung <strong>zip</strong> ist nicht installiert. Ohne sie können keine Updates installiert werden.
            Bitte beim Hoster aktivieren (z. B. in der PHP-Konfiguration).
        </div>
    <?php endif; ?>

    <!-- ── VERSION & PRÜFEN ── -->
    <div class="<?php echo $card; ?>">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wider">Installierte Version</div>
                <div class="text-3xl font-black text-primary"><?php echo htmlspecialchars(APP_VERSION); ?></div>
                <?php if ($check): ?>
                    <div class="text-xs text-slate-400 mt-1">Zuletzt geprüft: <?php echo date('d.m.Y H:i', (int) $check['checked_at']); ?></div>
                <?php endif; ?>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="update_action" value="check">
                <button type="submit" class="<?php echo $btnPrimary; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Nach Updates suchen
                </button>
            </form>
        </div>
    </div>

    <!-- ── VERFÜGBARES UPDATE ── -->
    <?php if ($check && $check['available']): ?>
        <div class="<?php echo $card; ?> border-primary/50 dark:border-primary/50 ring-1 ring-primary/20">
            <div class="flex items-start justify-between gap-4 mb-3">
                <h3 class="text-lg font-bold text-slate-800 dark:text-white">⬆️ Version <?php echo htmlspecialchars($check['version']); ?> ist verfügbar</h3>
                <?php if ($check['published_at']): ?>
                    <span class="text-xs text-slate-400 whitespace-nowrap"><?php echo date('d.m.Y', strtotime($check['published_at'])); ?></span>
                <?php endif; ?>
            </div>

            <?php if (trim($check['notes']) !== ''): ?>
                <div class="text-sm text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-700/40 rounded-lg p-4 mb-4 max-h-80 overflow-y-auto">
                    <?php echo updaterMarkdownToHtml($check['notes']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="install-form" data-version="<?php echo htmlspecialchars($check['version']); ?>">
                <?php echo csrfField(); ?>
                <input type="hidden" name="update_action" value="install">
                <button type="submit" id="install-btn" class="<?php echo $btnPrimary; ?>" <?php echo $blocked ? 'disabled' : ''; ?>>
                    Jetzt installieren
                </button>
                <?php if (preg_match('#^https://github\.com/#', $check['html_url'])): ?>
                    <a href="<?php echo htmlspecialchars($check['html_url']); ?>" target="_blank" rel="noopener noreferrer"
                       class="ml-3 text-sm text-primary hover:underline">Release auf GitHub ansehen</a>
                <?php endif; ?>
            </form>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-3">
                Vor der Installation wird automatisch ein Backup der ersetzten Dateien angelegt.
                <code>config.php</code>, <code>uploads/</code> und <code>storage/</code> werden nie verändert.
            </p>
        </div>
    <?php endif; ?>

    <!-- ── MANUELLER UPLOAD ── -->
    <div class="<?php echo $card; ?>">
        <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-1">Update-ZIP hochladen</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            Alternativ ein Update-Paket (z. B. das „Source code (zip)“ eines GitHub-Releases) von Hand einspielen.
            Es wird genauso geprüft und installiert. Max. <?php echo htmlspecialchars((string) $maxUpload); ?> laut PHP-Einstellung, höchstens 40 MB.
        </p>
        <form method="POST" enctype="multipart/form-data" id="upload-form" class="flex flex-wrap items-center gap-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="update_action" value="upload">
            <input type="file" name="update_zip" accept=".zip,application/zip" required <?php echo $blocked ? 'disabled' : ''; ?>
                   class="text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:px-4 file:py-2 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:text-slate-700 dark:file:text-slate-200 file:font-semibold">
            <button type="submit" id="upload-btn" class="<?php echo $btnPrimary; ?>" <?php echo $blocked ? 'disabled' : ''; ?>>
                Hochladen &amp; installieren
            </button>
        </form>
    </div>

    <!-- ── UPDATE-QUELLE ── -->
    <div class="<?php echo $card; ?>">
        <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-1">Update-Quelle</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            Öffentliches GitHub-Repository im Format <code>besitzer/repository</code>. Leer lassen für den Standard
            (<code><?php echo htmlspecialchars(UPDATER_DEFAULT_REPO); ?></code>).
        </p>
        <form method="POST" class="flex flex-wrap items-center gap-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="update_action" value="save_repo">
            <input type="text" name="update_repo" value="<?php echo htmlspecialchars($repo); ?>"
                   pattern="[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+" placeholder="<?php echo htmlspecialchars(UPDATER_DEFAULT_REPO); ?>"
                   class="flex-1 min-w-[16rem] px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-md font-mono text-sm dark:bg-slate-700 dark:text-white focus:ring-primary focus:border-primary">
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-800 dark:text-white font-semibold text-sm">
                Speichern
            </button>
        </form>
    </div>

    <!-- ── BACKUPS ── -->
    <div class="<?php echo $card; ?>">
        <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-1">Backups</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            Vor jedem Update gesicherte Dateien in <code>storage/backups/</code> (nicht über das Web abrufbar, Zugriff per FTP/SFTP).
        </p>
        <?php if (!$backups): ?>
            <p class="text-sm text-slate-400">Noch keine Backups vorhanden.</p>
        <?php else: ?>
            <div class="border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-700/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Datei</th>
                            <th class="px-4 py-2 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Datum</th>
                            <th class="px-4 py-2 text-right text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Größe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($b['name']); ?></td>
                                <td class="px-4 py-2 text-xs text-slate-500 dark:text-slate-400"><?php echo date('d.m.Y H:i', $b['time']); ?></td>
                                <td class="px-4 py-2 text-right text-xs text-slate-400 whitespace-nowrap"><?php echo $fmtSize($b['size']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function lockOnSubmit(form, button, question) {
        if (!form || !button) return;
        form.addEventListener('submit', function (e) {
            if (question && !confirm(question)) { e.preventDefault(); return; }
            button.disabled = true;
            button.textContent = 'Update läuft … bitte warten';
        });
    }
    var installForm = document.getElementById('install-form');
    lockOnSubmit(installForm, document.getElementById('install-btn'),
        installForm ? 'LabOrbit jetzt auf Version ' + installForm.dataset.version + ' aktualisieren?\n\nVorher wird automatisch ein Backup angelegt.' : null);
    lockOnSubmit(document.getElementById('upload-form'), document.getElementById('upload-btn'),
        'Das hochgeladene Paket prüfen und installieren?\n\nVorher wird automatisch ein Backup angelegt.');
})();
</script>
