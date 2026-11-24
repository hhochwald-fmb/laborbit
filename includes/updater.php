<?php
/**
 * Online-Updater für LabOrbit.
 *
 * Ablauf: neuestes GitHub-Release abfragen → ZIP herunterladen → Paket vollständig prüfen
 * → Backup der zu ersetzenden Dateien → Dateien schreiben → opcache_reset().
 * Auf dem Server wird kein git benötigt. Datenbank-Migrationen laufen beim nächsten
 * Seitenaufruf automatisch (autoMigrate() in includes/db.php).
 */
if (!defined('DB_HOST')) exit;

const UPDATER_DEFAULT_REPO   = 'hhochwald-fmb/laborbit';
const UPDATER_REPO_PATTERN   = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/';
const UPDATER_ASSET_PATTERN  = '/^laborbit-.*\.zip$/i';
const UPDATER_VERSION_PATTERN = '/^\d{1,4}\.\d{1,4}(\.\d{1,4})?$/';
const UPDATER_ALLOWED_HOSTS  = [
    'api.github.com',
    'github.com',
    'codeload.github.com',
    'objects.githubusercontent.com',
    'release-assets.githubusercontent.com',
];
const UPDATER_MAX_DOWNLOAD   = 40 * 1024 * 1024;   // max. Größe des ZIP-Downloads
const UPDATER_MAX_UNPACKED   = 120 * 1024 * 1024;  // Schutz vor ZIP-Bomben
const UPDATER_TIMEOUT        = 120;
const UPDATER_USER_AGENT     = 'LabOrbit-Updater';
const UPDATER_MIN_FILES      = 20;

/** Dateien, die im Paket vorhanden sein und den jeweiligen Textschnipsel enthalten müssen. */
const UPDATER_REQUIRED_FILES = [
    'index.php'               => 'APP_VERSION',
    'includes/db.php'         => 'autoMigrate(',
    'includes/functions.php'  => 'function requireAdmin',
    'includes/migrations.php' => 'function runMigrations',
    'includes/updater.php'    => 'function updaterInstall',
    'pages/system.php'        => 'runMigrations',
    'pages/updates.php'       => 'updaterCheck',
    'pages/dashboard.php'     => '<?php',
];

/** Ordner, deren Inhalt ein Update überschreiben darf. */
const UPDATER_DIRS = ['includes/', 'pages/', 'assets/'];

/** Einzelne feste Dateien im Hauptordner, die ein Update überschreiben darf. */
const UPDATER_FIXED_FILES = ['VERSION', 'README.md', 'ENTWICKLUNG.md', '.htaccess'];

/**
 * PHP-Dateien im Hauptordner, die nur aktualisiert werden, wenn sie auf dem Server noch
 * existieren (setup.php soll nach der Installation gelöscht bleiben).
 */
const UPDATER_ONLY_IF_PRESENT = ['setup.php', 'patch.php', 'apply_prefixes.php'];

// ── Umgebung ────────────────────────────────────────────────────────────────

function updaterIsGitCheckout(): bool
{
    return file_exists(APP_ROOT . '/.git');
}

function updaterZipAvailable(): bool
{
    return class_exists('ZipArchive');
}

/** Legt storage/, storage/tmp und storage/backups samt Zugriffssperre an. */
function updaterStorageDir(string $sub = ''): string
{
    $base = APP_ROOT . '/storage';
    if (!is_dir($base) && !@mkdir($base, 0755, true)) {
        throw new RuntimeException('Der Ordner storage/ konnte nicht angelegt werden. Bitte Schreibrechte prüfen.');
    }
    $htaccess = $base . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
    if ($sub === '') {
        return $base;
    }
    $dir = $base . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException("Der Ordner storage/$sub konnte nicht angelegt werden. Bitte Schreibrechte prüfen.");
    }
    return $dir;
}

function updaterValidRepo(string $repo): bool
{
    return (bool) preg_match(UPDATER_REPO_PATTERN, $repo) && strpos($repo, '..') === false;
}

function updaterGetRepo(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM " . DB_PREFIX . "settings WHERE setting_key = 'update_repo'");
        $stmt->execute();
        $repo = trim((string) $stmt->fetchColumn());
    } catch (PDOException $e) {
        $repo = '';
    }
    return updaterValidRepo($repo) ? $repo : UPDATER_DEFAULT_REPO;
}

// ── HTTP ────────────────────────────────────────────────────────────────────

function updaterAssertAllowedUrl(string $url): void
{
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower($parts['host'] ?? '');
    if ($scheme !== 'https' || !in_array($host, UPDATER_ALLOWED_HOSTS, true) || isset($parts['user']) || isset($parts['port'])) {
        throw new RuntimeException('Download-Adresse nicht erlaubt: ' . ($host ?: $url));
    }
}

/**
 * GET-Anfrage mit eigener Weiterleitungs-Behandlung, damit jede Station gegen die
 * Host-Whitelist geprüft wird. Mit $saveTo wird der Body in die Datei geschrieben.
 *
 * @return array{status:int, body:string}
 */
function updaterHttpGet(string $url, ?string $saveTo = null, int $maxBytes = UPDATER_MAX_DOWNLOAD, string $accept = '*/*'): array
{
    for ($hop = 0; $hop <= 5; $hop++) {
        updaterAssertAllowedUrl($url);
        $res = function_exists('curl_init')
            ? updaterCurlOnce($url, $saveTo, $maxBytes, $accept)
            : updaterStreamOnce($url, $saveTo, $maxBytes, $accept);

        if (in_array($res['status'], [301, 302, 303, 307, 308], true) && $res['location'] !== '') {
            $location = $res['location'];
            if (strpos($location, '//') === false) {
                $p = parse_url($url);
                $location = 'https://' . $p['host'] . '/' . ltrim($location, '/');
            }
            $url = $location;
            continue;
        }
        return ['status' => $res['status'], 'body' => $res['body']];
    }
    throw new RuntimeException('Zu viele Weiterleitungen beim Herunterladen.');
}

/** @return array{status:int, location:string, body:string} */
function updaterCurlOnce(string $url, ?string $saveTo, int $maxBytes, string $accept): array
{
    $fh = null;
    if ($saveTo !== null && !($fh = @fopen($saveTo, 'wb'))) {
        throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden (storage/tmp).');
    }
    $body = '';
    $received = 0;
    $tooBig = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => UPDATER_TIMEOUT,
        CURLOPT_USERAGENT      => UPDATER_USER_AGENT,
        CURLOPT_HTTPHEADER     => ['Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$received, &$tooBig, $fh, $maxBytes) {
            $len = strlen($chunk);
            $received += $len;
            if ($received > $maxBytes) {
                $tooBig = true;
                return 0; // bricht den Transfer ab
            }
            if ($fh) {
                return fwrite($fh, $chunk);
            }
            $body .= $chunk;
            return $len;
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
    $error = curl_error($ch);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    if ($fh) {
        fclose($fh);
    }

    if ($tooBig) {
        throw new RuntimeException('Der Download ist größer als ' . round($maxBytes / 1048576) . ' MB und wurde abgebrochen.');
    }
    if ($ok === false) {
        throw new RuntimeException('Verbindung zu GitHub fehlgeschlagen: ' . $error);
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
}

/** Fallback ohne cURL: file_get_contents-Streams (braucht allow_url_fopen). */
function updaterStreamOnce(string $url, ?string $saveTo, int $maxBytes, string $accept): array
{
    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('Weder die PHP-Erweiterung curl noch allow_url_fopen ist verfügbar – Online-Update nicht möglich.');
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: " . UPDATER_USER_AGENT . "\r\nAccept: $accept\r\nX-GitHub-Api-Version: 2022-11-28\r\n",
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => UPDATER_TIMEOUT,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) {
        throw new RuntimeException('Verbindung zu GitHub fehlgeschlagen.');
    }
    $headers = stream_get_meta_data($in)['wrapper_data'] ?? [];
    $status = 0;
    $location = '';
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        }
    }

    $out = null;
    if ($saveTo !== null && !($out = @fopen($saveTo, 'wb'))) {
        fclose($in);
        throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden (storage/tmp).');
    }
    $body = '';
    $received = 0;
    while (!feof($in)) {
        $chunk = fread($in, 65536);
        if ($chunk === false) {
            break;
        }
        $received += strlen($chunk);
        if ($received > $maxBytes) {
            fclose($in);
            if ($out) fclose($out);
            throw new RuntimeException('Der Download ist größer als ' . round($maxBytes / 1048576) . ' MB und wurde abgebrochen.');
        }
        if ($out) {
            fwrite($out, $chunk);
        } else {
            $body .= $chunk;
        }
    }
    fclose($in);
    if ($out) fclose($out);
    return ['status' => $status, 'location' => $location, 'body' => $body];
}

function updaterHttpError(int $status, string $repo, string $what): RuntimeException
{
    if ($status === 404) {
        return new RuntimeException("Für $repo wurde noch kein Release gefunden. Entweder wurde noch keines veröffentlicht oder das Repository ist nicht öffentlich.");
    }
    if ($status === 403 || $status === 429) {
        return new RuntimeException('GitHub-Abfragelimit erreicht (60 Anfragen pro Stunde ohne Anmeldung). Bitte in einer Stunde erneut versuchen.');
    }
    return new RuntimeException("$what fehlgeschlagen: GitHub antwortete mit HTTP $status.");
}

// ── Prüfen & Herunterladen ──────────────────────────────────────────────────

/**
 * Fragt das neueste Release ab.
 *
 * @return array{repo:string, version:string, current:string, available:bool, notes:string,
 *               download_url:string, html_url:string, published_at:string, checked_at:int}
 */
function updaterCheck(PDO $pdo): array
{
    $repo = updaterGetRepo($pdo);
    $res = updaterHttpGet("https://api.github.com/repos/$repo/releases/latest", null, 2 * 1024 * 1024, 'application/vnd.github+json');
    if ($res['status'] !== 200) {
        throw updaterHttpError($res['status'], $repo, 'Update-Prüfung');
    }
    $data = json_decode($res['body'], true);
    if (!is_array($data) || empty($data['tag_name'])) {
        throw new RuntimeException('Unerwartete Antwort von GitHub.');
    }

    $version = ltrim((string) $data['tag_name'], 'vV');
    if (!preg_match(UPDATER_VERSION_PATTERN, $version)) {
        throw new RuntimeException('Das neueste Release hat keine gültige Versionsnummer: ' . $data['tag_name']);
    }

    $downloadUrl = (string) ($data['zipball_url'] ?? '');
    foreach ($data['assets'] ?? [] as $asset) {
        if (preg_match(UPDATER_ASSET_PATTERN, (string) ($asset['name'] ?? '')) && !empty($asset['browser_download_url'])) {
            $downloadUrl = (string) $asset['browser_download_url'];
            break;
        }
    }
    if ($downloadUrl === '') {
        throw new RuntimeException('Das Release enthält keine Download-Adresse.');
    }
    updaterAssertAllowedUrl($downloadUrl);

    $notes = (string) ($data['body'] ?? '');
    $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 4000) : substr($notes, 0, 4000);

    return [
        'repo'         => $repo,
        'version'      => $version,
        'current'      => APP_VERSION,
        'available'    => version_compare($version, APP_VERSION, '>'),
        'notes'        => $notes,
        'download_url' => $downloadUrl,
        'html_url'     => (string) ($data['html_url'] ?? ''),
        'published_at' => (string) ($data['published_at'] ?? ''),
        'checked_at'   => time(),
    ];
}

function updaterTempZipPath(): string
{
    return updaterStorageDir('tmp') . '/update-' . bin2hex(random_bytes(8)) . '.zip';
}

/** Lädt das Update-ZIP nach storage/tmp und gibt den Pfad zurück. */
function updaterDownload(string $url, string $repo): string
{
    $target = updaterTempZipPath();
    try {
        // Accept */*: der zipball auf api.github.com antwortet auf application/octet-stream mit HTTP 415.
        $res = updaterHttpGet($url, $target, UPDATER_MAX_DOWNLOAD, '*/*');
        if ($res['status'] !== 200) {
            throw updaterHttpError($res['status'], $repo, 'Download');
        }
        if (!is_file($target) || filesize($target) < 100) {
            throw new RuntimeException('Der Download ist leer oder unvollständig.');
        }
    } catch (Throwable $e) {
        @unlink($target);
        throw $e;
    }
    return $target;
}

// ── Paket prüfen ────────────────────────────────────────────────────────────

/** Lehnt Pfade mit "..", Backslash, führendem "/" oder Nullbyte ab. */
function updaterPathIsSafe(string $path): bool
{
    return $path !== ''
        && strpos($path, "\0") === false
        && strpos($path, '\\') === false
        && $path[0] !== '/'
        && !preg_match('#(^|/)\.\.(/|$)#', $path)
        && !preg_match('#^[A-Za-z]:#', $path);
}

/** Whitelist: darf das Update diese Datei (relativ zum App-Ordner) schreiben? */
function updaterIsUpdatablePath(string $rel): bool
{
    if (!updaterPathIsSafe($rel)) {
        return false;
    }
    // Nutzerdaten und Konfiguration niemals anfassen.
    if (basename($rel) === 'config.php' || preg_match('#^(uploads|storage)(/|$)#', $rel)) {
        return false;
    }
    if (in_array($rel, UPDATER_FIXED_FILES, true)) {
        return true;
    }
    if (strpos($rel, '/') === false) {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\.php$/', $rel);
    }
    foreach (UPDATER_DIRS as $dir) {
        if (strpos($rel, $dir) === 0) {
            return true;
        }
    }
    return false;
}

/** Findet den Paket-Ordner (GitHub-Zipballs haben einen Präfix wie owner-repo-abc123/). */
function updaterFindRoot(array $names): ?string
{
    $set = array_flip($names);
    $best = null;
    foreach ($names as $name) {
        if (basename($name) !== 'VERSION') {
            continue;
        }
        $prefix = substr($name, 0, -strlen('VERSION'));
        if (isset($set[$prefix . 'index.php']) && ($best === null || strlen($prefix) < strlen($best))) {
            $best = $prefix;
        }
    }
    return $best;
}

/**
 * Prüft das ZIP vollständig, bevor irgendetwas verändert wird.
 *
 * @return array{version:string, files:array<string,int>} files: relativer Zielpfad => ZIP-Index
 */
function updaterInspectZip(ZipArchive $zip): array
{
    $names = [];
    $unpacked = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            throw new RuntimeException('Das ZIP-Archiv ist beschädigt.');
        }
        $name = $stat['name'];
        $checkName = rtrim($name, '/');
        if (!updaterPathIsSafe($checkName)) {
            throw new RuntimeException('Das Paket enthält einen unzulässigen Pfad: ' . $name);
        }
        $unpacked += (int) $stat['size'];
        if ($unpacked > UPDATER_MAX_UNPACKED) {
            throw new RuntimeException('Das Paket ist entpackt zu groß.');
        }
        if (substr($name, -1) === '/') {
            continue; // Ordner-Eintrag
        }
        // Symlinks ablehnen (Unix-Dateityp in den oberen 16 Bit der externen Attribute).
        if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
            && ((($attr >> 16) & 0170000) === 0120000)) {
            throw new RuntimeException('Das Paket enthält einen symbolischen Link: ' . $name);
        }
        $names[$i] = $name;
    }

    $root = updaterFindRoot(array_values($names));
    if ($root === null) {
        throw new RuntimeException('Das ZIP ist kein LabOrbit-Paket (VERSION und index.php nicht gefunden).');
    }

    $version = trim((string) $zip->getFromName($root . 'VERSION'));
    if (!preg_match(UPDATER_VERSION_PATTERN, $version)) {
        throw new RuntimeException('Die VERSION-Datei im Paket ist ungültig.');
    }
    if (version_compare($version, APP_VERSION, '<')) {
        throw new RuntimeException("Das Paket enthält Version $version – älter als die installierte Version " . APP_VERSION . '. Abgebrochen.');
    }

    foreach (UPDATER_REQUIRED_FILES as $file => $snippet) {
        $content = $zip->getFromName($root . $file);
        if ($content === false) {
            throw new RuntimeException("Das Paket ist unvollständig: $file fehlt.");
        }
        if (strpos($content, $snippet) === false) {
            throw new RuntimeException("Das Paket ist unvollständig oder beschädigt: $file hat nicht den erwarteten Inhalt.");
        }
    }

    $files = [];
    foreach ($names as $index => $name) {
        if (strpos($name, $root) !== 0) {
            continue;
        }
        $rel = substr($name, strlen($root));
        if (!updaterIsUpdatablePath($rel)) {
            continue;
        }
        if (in_array($rel, UPDATER_ONLY_IF_PRESENT, true) && !file_exists(APP_ROOT . '/' . $rel)) {
            continue;
        }
        $files[$rel] = $index;
    }
    if (count($files) < UPDATER_MIN_FILES) {
        throw new RuntimeException('Das Paket enthält zu wenige Dateien (' . count($files) . ') und ist vermutlich unvollständig.');
    }

    // VERSION zuletzt schreiben: bricht etwas ab, bleibt die alte Versionsnummer stehen.
    uksort($files, fn($a, $b) => ($a === 'VERSION') <=> ($b === 'VERSION') ?: strcmp($a, $b));

    return ['version' => $version, 'files' => $files];
}

/** @return string[] Zieldateien ohne Schreibrecht */
function updaterUnwritable(array $relPaths): array
{
    $bad = [];
    foreach ($relPaths as $rel) {
        $target = APP_ROOT . '/' . $rel;
        if (file_exists($target)) {
            if (is_dir($target) || !is_writable($target)) {
                $bad[] = $rel;
            }
            continue;
        }
        $dir = dirname($target);
        while (!file_exists($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $bad[] = $rel;
        }
    }
    return $bad;
}

// ── Backup & Installation ───────────────────────────────────────────────────

/** Sichert alle vorhandenen Dateien, die gleich ersetzt werden. Gibt den Backup-Pfad zurück. */
function updaterBackup(array $relPaths): string
{
    $dir = updaterStorageDir('backups');
    $oldVersion = preg_replace('/[^0-9A-Za-z.]/', '', APP_VERSION) ?: 'unbekannt';
    $path = $dir . '/backup-' . $oldVersion . '-' . date('Y-m-d-His') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Backup konnte nicht angelegt werden (storage/backups). Update abgebrochen.');
    }
    $count = 0;
    foreach ($relPaths as $rel) {
        $file = APP_ROOT . '/' . $rel;
        if (is_file($file)) {
            if (!$zip->addFile($file, $rel)) {
                $zip->close();
                @unlink($path);
                throw new RuntimeException("Backup fehlgeschlagen bei $rel. Update abgebrochen.");
            }
            $count++;
        }
    }
    if ($count === 0) {
        $zip->addFromString('LEER.txt', 'Keine bestehenden Dateien zu sichern.');
    }
    if (!$zip->close() || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException('Backup konnte nicht geschrieben werden. Update abgebrochen.');
    }
    return $path;
}

/** Stellt nach einem Fehler beim Schreiben den alten Stand wieder her. */
function updaterRollback(string $backupPath, array $createdFiles): void
{
    $zip = new ZipArchive();
    if ($zip->open($backupPath) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'LEER.txt' || !updaterIsUpdatablePath($name)) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                @file_put_contents(APP_ROOT . '/' . $name, $data, LOCK_EX);
            }
        }
        $zip->close();
    }
    foreach ($createdFiles as $rel) {
        @unlink(APP_ROOT . '/' . $rel);
    }
}

/**
 * Prüft und installiert ein Update-ZIP. Das ZIP wird danach immer gelöscht.
 *
 * @return array{version:string, files:int, backup:string}
 */
function updaterInstall(string $zipPath): array
{
    if (updaterIsGitCheckout()) {
        throw new RuntimeException('Diese Installation ist ein git-Checkout. Bitte per "git pull" aktualisieren.');
    }
    if (!updaterZipAvailable()) {
        throw new RuntimeException('Die PHP-Erweiterung "zip" fehlt – Updates sind nicht möglich.');
    }

    @set_time_limit(300);
    ignore_user_abort(true);

    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Die Datei ist kein gültiges ZIP-Archiv.');
        }
        $opened = true;

        $package = updaterInspectZip($zip);
        $relPaths = array_keys($package['files']);

        $unwritable = updaterUnwritable($relPaths);
        if ($unwritable) {
            throw new RuntimeException('Keine Schreibrechte für: ' . implode(', ', array_slice($unwritable, 0, 8))
                . (count($unwritable) > 8 ? ' …' : '') . '. Es wurde nichts verändert.');
        }

        // Inhalte vorab vollständig lesen, damit Lesefehler nicht mitten im Schreiben auffallen.
        $contents = [];
        foreach ($package['files'] as $rel => $index) {
            $data = $zip->getFromIndex($index);
            if ($data === false) {
                throw new RuntimeException("Datei $rel konnte nicht aus dem Paket gelesen werden. Es wurde nichts verändert.");
            }
            $contents[$rel] = $data;
        }

        $backup = updaterBackup($relPaths);

        $created = [];
        foreach ($contents as $rel => $data) {
            $target = APP_ROOT . '/' . $rel;
            $existed = file_exists($target);
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                updaterRollback($backup, $created);
                throw new RuntimeException("Ordner für $rel konnte nicht angelegt werden. Alter Stand wurde wiederhergestellt.");
            }
            if (@file_put_contents($target, $data, LOCK_EX) !== strlen($data)) {
                updaterRollback($backup, $created);
                throw new RuntimeException("Schreiben von $rel fehlgeschlagen. Alter Stand wurde aus dem Backup wiederhergestellt.");
            }
            if (!$existed) {
                $created[] = $rel;
            }
        }
    } finally {
        if ($opened) {
            $zip->close();
        }
        @unlink($zipPath);
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    clearstatcache();

    return ['version' => $package['version'], 'files' => count($contents), 'backup' => basename($backup)];
}

/** @return array<int, array{name:string, size:int, time:int}> neueste zuerst */
function updaterListBackups(): array
{
    $dir = APP_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        return [];
    }
    $list = [];
    foreach (glob($dir . '/backup-*.zip') ?: [] as $file) {
        $list[] = ['name' => basename($file), 'size' => (int) filesize($file), 'time' => (int) filemtime($file)];
    }
    usort($list, fn($a, $b) => $b['time'] <=> $a['time']);
    return $list;
}

/** Löscht liegengebliebene Update-ZIPs (z. B. nach Abbruch), die älter als eine Stunde sind. */
function updaterCleanupTmp(): void
{
    $dir = APP_ROOT . '/storage/tmp';
    foreach (glob($dir . '/update-*.zip') ?: [] as $file) {
        if (filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
}

// ── Release-Notizen ─────────────────────────────────────────────────────────

/** Einfaches, sicheres Markdown → HTML: erst escapen, dann Überschriften, Listen, **fett**, `code`. */
function updaterMarkdownToHtml(string $markdown): string
{
    $inline = function (string $text): string {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/', '<code class="px-1 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-xs">$1</code>', $text);
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    };

    $html = '';
    $paragraph = [];
    $inList = false;
    $flush = function () use (&$html, &$paragraph) {
        if ($paragraph) {
            $html .= '<p class="mb-2">' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = function () use (&$html, &$inList) {
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
    };

    foreach (preg_split('/\R/', $markdown) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flush();
            $closeList();
        } elseif (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
            $flush();
            $closeList();
            $tag = strlen($m[1]) <= 2 ? 'h4' : 'h5';
            $size = $tag === 'h4' ? 'text-base' : 'text-sm';
            $html .= "<$tag class=\"$size font-bold mt-3 mb-1\">" . $inline($m[2]) . "</$tag>";
        } elseif (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
            $flush();
            if (!$inList) {
                $html .= '<ul class="list-disc pl-5 mb-2 space-y-0.5">';
                $inList = true;
            }
            $html .= '<li>' . $inline($m[1]) . '</li>';
        } else {
            $closeList();
            $paragraph[] = $inline($trimmed);
        }
    }
    $flush();
    $closeList();
    return $html;
}
