<?php
ob_start();
require_once 'includes/db.php'; // definiert APP_VERSION (aus der Datei VERSION) und migriert bei Bedarf
require_once 'includes/functions.php';

const LOGIN_MAX_FAILS   = 10;      // Fehlversuche pro IP …
const LOGIN_WINDOW_MIN  = 15;      // … innerhalb von so vielen Minuten
const SESSION_IDLE_SECS = 8 * 3600; // automatische Abmeldung nach 8 Stunden Inaktivität

function loginClientIp(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function loginIsBlocked(PDO $pdo): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . DB_PREFIX . "login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MIN . " MINUTE)");
        $stmt->execute([loginClientIp()]);
        return (int) $stmt->fetchColumn() >= LOGIN_MAX_FAILS;
    } catch (PDOException $e) {
        return false;
    }
}

function loginRecordFailure(PDO $pdo): void {
    try {
        $pdo->prepare("INSERT INTO " . DB_PREFIX . "login_attempts (ip, attempted_at) VALUES (?, NOW())")->execute([loginClientIp()]);
        $pdo->exec("DELETE FROM " . DB_PREFIX . "login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    } catch (PDOException $e) {
        // Tabelle fehlt (Migration noch nicht gelaufen) – Login trotzdem möglich.
    }
}

function loginSucceed(PDO $pdo, array $user): void {
    try {
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "login_attempts WHERE ip = ?")->execute([loginClientIp()]);
    } catch (PDOException $e) {
    }
    session_regenerate_id(true); // Schutz vor Session Fixation
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'] ?? null;
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    redirectTo('index.php');
}

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    redirectTo('index.php');
}

// Automatische Abmeldung nach längerer Inaktivität
if (isset($_SESSION['user_id'])) {
    if (time() - (int) ($_SESSION['last_activity'] ?? time()) > SESSION_IDLE_SECS) {
        $_SESSION = [];
        session_regenerate_id(true);
        $error = "Du wurdest wegen Inaktivität abgemeldet. Bitte melde dich erneut an.";
    } else {
        $_SESSION['last_activity'] = time();
    }
}

// Login Check
if (!isset($_SESSION['user_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $password = (string) $_POST['password'];

        if (loginIsBlocked($pdo)) {
            $error = "Zu viele fehlgeschlagene Anmeldeversuche. Bitte in " . LOGIN_WINDOW_MIN . " Minuten erneut versuchen.";
        } else {
            if (defined('EMERGENCY_ADMIN_PASS') && EMERGENCY_ADMIN_PASS !== '' && hash_equals((string) EMERGENCY_ADMIN_PASS, $password)) {
                $user = $pdo->query("SELECT * FROM " . DB_PREFIX . "users WHERE role = 'manager' ORDER BY id ASC LIMIT 1")->fetch();
                if ($user) {
                    // Notfallpasswort direkt als neues Passwort speichern!
                    $pdo->prepare("UPDATE " . DB_PREFIX . "users SET password_hash = ? WHERE id = ?")
                        ->execute([password_hash(EMERGENCY_ADMIN_PASS, PASSWORD_DEFAULT), $user['id']]);
                    loginSucceed($pdo, $user);
                }
            }

            $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE username = ?");
            $stmt->execute([(string) $_POST['username']]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                loginSucceed($pdo, $user);
            }
            loginRecordFailure($pdo);
            $error = "Ungültiger Benutzername oder Passwort.";
        }
    }
}

// CSRF-Schutz für ALLE Formulare eingeloggter Nutzer: Jede POST-Anfrage braucht das Token.
// Fehlt es, wird die Anfrage verworfen, bevor eine Seite sie verarbeiten kann.
$csrfMessage = null;
if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValid()) {
    $tooLarge = empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    $csrfMessage = $tooLarge
        ? "Die Anfrage war zu groß (z. B. zu große Datei, Limit: " . ini_get('post_max_size') . "). Es wurde nichts gespeichert."
        : "Sicherheitsprüfung fehlgeschlagen (Sitzung abgelaufen?). Bitte die Seite neu laden und erneut versuchen.";
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

// Das CSRF-Token wird automatisch in jedes <form method="post"> eingefügt.
$csrfHiddenField = csrfField();
ob_start(function (string $buffer) use ($csrfHiddenField): string {
    return preg_replace('/<form\b[^>]*\bmethod\s*=\s*["\']?post\b[^>]*>/i', '$0' . $csrfHiddenField, $buffer);
});

// Fetch settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM " . DB_PREFIX . "settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$schoolName = $settings['school_name'] ?? '';
$appName = 'LabOrbit';
$displayTitle = $appName . ($schoolName ? " - " . $schoolName : "");

$theme = $settings['theme'] ?? 'light';
$isDark = in_array($theme, ['dark', 'dark_amber', 'dark_cyber']);

// Primary Color Switching
$primaryColor = '#3b82f6'; // Default Blue
if ($theme === 'light_emerald') $primaryColor = '#10b981'; // Emerald
if ($theme === 'dark_amber') $primaryColor = '#f59e0b'; // Amber
if ($theme === 'dark_cyber') $primaryColor = '#a855f7'; // Purple
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($displayTitle); ?></title>
    <link rel="stylesheet" type="text/css" href="assets/css/trix.css">
    <script type="text/javascript" src="assets/js/trix.umd.min.js"></script>
    <!-- Modern Tailwind UI (lokoal) -->
    <script src="assets/js/tailwindcss.js"></script>
    <script>
        // HTML-Sonderzeichen maskieren, bevor Daten per innerHTML eingefügt werden (Schutz vor XSS).
        function escHtml(v) {
            return String(v ?? '').replace(/[&<>"'`]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'}[c]));
        }
    </script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', 'sans-serif'],
                    },
                    colors: {
                        primary: '<?php echo $primaryColor; ?>',
                    }
                }
            }
        }
    </script>
    <style>
        .custom-select {
            appearance: none;
            background-color: transparent;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.6rem center;
            background-size: 1.1em;
            padding-right: 2.2rem !important;
        }
        .dark .custom-select {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23e2e8f0' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        }
        trix-toolbar [data-trix-button-group="file-tools"] { display: none; }
        trix-editor { min-height: 120px; }
        .trix-content a { color: #3b82f6; text-decoration: underline; }
        .dark trix-toolbar .trix-button { background-color: transparent; }
        .dark trix-toolbar .trix-button::before { filter: invert(1); opacity: 0.8; }
        .dark trix-toolbar .trix-button.trix-active { background-color: rgba(255,255,255,0.1); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 <?php echo $isDark ? 'dark bg-slate-900 text-slate-100' : ''; ?>">

<?php if (!isset($_SESSION['user_id'])): ?>
    <div class="min-h-screen flex text-slate-900 dark:text-slate-100">
        <!-- Left Side: Graphic / Branding -->
        <div class="hidden lg:flex lg:w-1/2 flex-col justify-center items-center relative overflow-hidden bg-slate-900">
            <!-- Animated abstract shapes -->
            <div class="absolute w-[40rem] h-[40rem] bg-primary/20 rounded-full blur-3xl -top-32 -left-32 animate-pulse" style="animation-duration: 8s;"></div>
            <div class="absolute w-[40rem] h-[40rem] shadow-2xl bg-[#a855f7]/20 rounded-full blur-3xl bottom-10 right-10 animate-pulse" style="animation-duration: 12s; animation-delay: 2s;"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-slate-900/60 z-0 pointer-events-none"></div>
            
            <div class="z-10 text-center px-12 flex flex-col items-center">
                <div class="bg-white/10 p-6 rounded-[2rem] backdrop-blur-md border border-white/10 shadow-2xl mb-10 transform hover:scale-105 transition-transform duration-500">
                    <svg class="w-24 h-24 text-white opacity-95" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" fill="currentColor" opacity="0.3"></circle>
                        <circle cx="12" cy="12" r="2"></circle>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(30 12 12)"></ellipse>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(150 12 12)"></ellipse>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(90 12 12)"></ellipse>
                    </svg>
                </div>
                <h1 class="text-6xl font-extrabold text-white tracking-tight mb-6 drop-shadow-xl">LabOrbit</h1>
                <p class="text-2xl text-blue-100/90 font-light max-w-lg mx-auto leading-snug drop-shadow">
                    Dein digitaler Physik-Sammlungsmanager.
                </p>
                <?php if($schoolName): ?>
                    <div class="mt-12 px-6 py-2 rounded-full border border-white/20 bg-white/5 backdrop-blur-sm shadow-xl text-sm font-semibold tracking-widest text-slate-300 uppercase">
                        <?php echo htmlspecialchars($schoolName); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Side: Login Widget -->
        <div class="w-full lg:w-1/2 flex items-center justify-center p-8 sm:p-12 bg-slate-50 dark:bg-slate-900 border-l border-slate-200 dark:border-slate-800 shadow-2xl lg:shadow-none relative z-10">
            <div class="w-full max-w-md space-y-10">
                <!-- Mobile Branding -->
                <div class="lg:hidden text-center mb-10">
                    <svg class="w-16 h-16 mx-auto text-primary mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3" fill="currentColor" opacity="0.2"></circle>
                        <circle cx="12" cy="12" r="2"></circle>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(30 12 12)"></ellipse>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(150 12 12)"></ellipse>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(90 12 12)"></ellipse>
                    </svg>
                    <h2 class="text-4xl font-extrabold text-slate-900 dark:text-white mb-2 tracking-tight">LabOrbit</h2>
                    <?php if($schoolName): ?>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?php echo htmlspecialchars($schoolName); ?></p>
                    <?php endif; ?>
                </div>
                
                <div>
                    <h2 class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">Willkommen zurück</h2>
                    <p class="mt-2 text-base text-slate-600 dark:text-slate-400">
                        Bitte melde dich an, um auf die Sammlung zuzugreifen.
                    </p>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="bg-red-50 border border-red-200 border-l-4 border-l-red-500 p-4 rounded-r-xl rounded-l-md dark:bg-red-900/30 dark:border-red-900/50 dark:border-l-red-500 flex items-start shadow-sm" role="alert">
                        <span class="text-red-500 mr-3 text-xl">🛡️</span>
                        <span class="text-red-800 dark:text-red-300 text-sm font-medium pt-0.5"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" method="POST">
                    <div class="space-y-5">
                        <div class="relative">
                            <label for="username" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2 cursor-pointer">Benutzername</label>
                            <input id="username" name="username" type="text" required class="block w-full px-4 py-3.5 border border-slate-300 dark:border-slate-600 placeholder-slate-400 text-slate-900 dark:text-white bg-white dark:bg-slate-800/80 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition duration-200 shadow-sm" placeholder="z. B. max.mustermann">
                        </div>
                        <div class="relative">
                            <label for="password" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2 cursor-pointer">Passwort</label>
                            <input id="password" name="password" type="password" required class="block w-full px-4 py-3.5 border border-slate-300 dark:border-slate-600 placeholder-slate-400 text-slate-900 dark:text-white bg-white dark:bg-slate-800/80 rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition duration-200 shadow-sm" placeholder="••••••••">
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full flex justify-center py-4 px-4 rounded-xl shadow-lg shadow-primary/30 text-base font-bold text-white bg-primary hover:bg-blue-600 hover:shadow-primary/40 focus:outline-none focus:ring-4 focus:ring-primary/50 transition-all duration-200 active:scale-[0.98]">
                            Sicher Anmelden
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Dashboard -->
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <div class="w-64 bg-white dark:bg-slate-800 shadow-lg hidden md:block">
            <div class="h-16 flex flex-col justify-center px-6 border-b border-slate-200 dark:border-slate-700">
                <span class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <svg class="w-6 h-6 text-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="2"></circle>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(30 12 12)"></ellipse>
                        <ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(150 12 12)"></ellipse>
                    </svg>
                    <?php echo htmlspecialchars($appName); ?>
                </span>
                <?php if($schoolName): ?><span class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5 truncate"><?php echo htmlspecialchars($schoolName); ?></span><?php endif; ?>
            </div>
            <nav class="p-4 space-y-2">
                <?php 
                $activePage = $_GET['page'] ?? 'dashboard';
                $navItem = function($p, $icon, $label) use ($activePage) {
                    $c = $activePage === $p ? 'bg-slate-100 dark:bg-slate-700 text-primary font-medium' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700';
                    echo "<a href=\"?page=$p\" class=\"block px-4 py-2 rounded-lg $c\">$icon $label</a>";
                };
                $navItem('dashboard', '🏫', 'Dashboard');
                $navItem('experiments', '🔬', 'Experimente');
                $navItem('devices', '🔌', 'Geräte');
                $navItem('device_categories', '🏷️', 'Geräte-Rubriken');
                $navItem('locations', '📍', 'Standorte / Schränke');
                $navItem('classes', '👥', 'Klassen');
                $navItem('topics', '📚', 'Themen');
                ?>
                
                <?php if($_SESSION['role'] === 'manager'): ?>
                    <div class="pt-4 mt-4 border-t border-slate-200 dark:border-slate-700">
                        <span class="px-4 text-xs font-semibold text-slate-400 uppercase tracking-wider">Admin</span>
                        <?php 
                        $navItem('users', '👤', 'Benutzer');
                        $navItem('settings', '⚙️', 'Einstellungen');
                        $navItem('system', '🛠️', 'System');
                        $navItem('updates', '🔄', 'Updates');
                        ?>
                    </div>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <header class="h-16 bg-white dark:bg-slate-800 shadow-sm border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-6">
                <h1 class="text-xl font-semibold dark:text-white">Willkommen, <?php echo htmlspecialchars($_SESSION['full_name'] ?: $_SESSION['username']); ?></h1>
                <div class="flex items-center space-x-4">
                    <a href="?page=profile" class="text-sm font-medium text-slate-600 hover:text-primary dark:text-slate-300 dark:hover:text-primary transition">Mein Profil</a>
                    <a href="?logout=1" class="text-sm font-medium text-red-600 hover:text-red-800 transition">Abmelden</a>
                </div>
            </header>
            
            <main class="flex-1 p-6 bg-slate-50 dark:bg-slate-900 overflow-y-auto">
                <?php if ($csrfMessage): ?>
                    <div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium bg-red-50 text-red-800 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800">❌ <?php echo htmlspecialchars($csrfMessage); ?></div>
                <?php endif; ?>
                <?php 
                    $page = $_GET['page'] ?? 'dashboard';
                    $allowedPages = ['dashboard', 'experiments', 'devices', 'device_categories', 'locations', 'classes', 'topics', 'users', 'settings', 'profile', 'system', 'updates'];
                    if (in_array($page, $allowedPages)) {
                        $pageFile = __DIR__ . '/pages/' . $page . '.php';
                        if (file_exists($pageFile)) {
                            require_once $pageFile;
                        } else {
                            echo "<div class='p-6 bg-white dark:bg-slate-800 rounded-xl shadow-sm'>Seite im Aufbau: <b>" . htmlspecialchars($page) . "</b></div>";
                        }
                    } else {
                        echo "<div class='p-6 bg-red-100 text-red-700 rounded-xl'>Seite nicht gefunden.</div>";
                    }
                ?>
            </main>
            <footer class="bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 p-4 text-center shrink-0">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Ein Projekt des <span class="font-semibold text-slate-700 dark:text-slate-300">Stadtmedienzentrum Stuttgart</span><br>
                    <a href="mailto:hello@fastmovingbytes.com" class="text-primary hover:underline">hello@fastmovingbytes.com</a>
                    <span class="mx-2 text-slate-300 dark:text-slate-600">|</span>
                    <a href="#" class="text-primary hover:underline">Impressum</a>
                    <span class="mx-2 text-slate-300 dark:text-slate-600">|</span>
                    <span class="text-slate-400 dark:text-slate-500">LabOrbit v<?php echo htmlspecialchars(APP_VERSION); ?></span>
                </p>
            </footer>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
