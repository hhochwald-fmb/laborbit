<?php
if (!defined('DB_HOST')) exit;

$isManager = ($_SESSION['role'] === 'manager');
if (!$isManager) {
    echo "<div class='p-6 bg-red-100 text-red-700 rounded-xl'>Zugriff verweigert. Nur für Manager.</div>";
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'];
        $role = $_POST['role'] ?? '';
        
        if (!in_array($role, ['manager', 'teacher', 'viewer'], true)) {
            $error = "Ungültige Rolle.";
        } elseif ($username === '') {
            $error = "Bitte einen Benutzernamen angeben.";
        } elseif (strlen($password) < 6) {
            $error = "Passwort muss mindestens 6 Zeichen lang sein.";
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = "Der Benutzername existiert bereits.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $full_name, $hash, $role]);
                $success = "Benutzer '" . htmlspecialchars($username) . "' erfolgreich angelegt.";
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        if ($_POST['id'] != $_SESSION['user_id']) { // Don't delete self
            $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "users WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success = "Benutzer gelöscht.";
        } else {
            $error = "Du kannst dich nicht selbst löschen.";
        }
    }
}

$stmt = $pdo->query("SELECT id, username, full_name, role, created_at FROM " . DB_PREFIX . "users ORDER BY role, username");
$users = $stmt->fetchAll();
?>

<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Benutzer verwalten</h2>
</div>

<?php if (isset($success)): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert"><?php echo $error; ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
            <thead class="bg-slate-50 dark:bg-slate-700/50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Benutzername</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Rolle</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Aktionen</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-slate-800 divide-y divide-slate-200 dark:divide-slate-700">
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900 dark:text-white">
                        <div class="flex items-center">
                            <?php echo htmlspecialchars($u['username']); ?>
                            <?php if($u['id'] === $_SESSION['user_id']): ?>
                                <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-300">Du</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 dark:text-slate-400">
                        <?php echo htmlspecialchars($u['full_name'] ?: '-'); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <?php
                        $roleColors = [
                            'manager' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300',
                            'teacher' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300',
                            'viewer' => 'bg-slate-100 text-slate-800 dark:bg-slate-700/50 dark:text-slate-300'
                        ];
                        $color = $roleColors[$u['role']];
                        ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $color; ?>">
                            <?php echo ucfirst($u['role']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <?php if($u['id'] !== $_SESSION['user_id']): ?>
                        <form method="POST" class="inline-block" onsubmit="return confirm('Benutzer wirklich löschen?');">
                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                            <button type="submit" name="delete_user" class="text-red-500 hover:text-red-700">Löschen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Neuen Benutzer anlegen</h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Benutzername</label>
                    <input type="text" name="username" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Klarname (optional)</label>
                    <input type="text" name="full_name" placeholder="Max Mustermann" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Passwort</label>
                    <input type="password" name="password" required minlength="6" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Rolle</label>
                    <select name="role" class="custom-select w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white bg-white">
                        <option value="viewer">Viewer (nur lesen)</option>
                        <option value="teacher">Lehrer (bearbeiten)</option>
                        <option value="manager">Manager (Admin)</option>
                    </select>
                </div>
                <button type="submit" name="add_user" class="w-full py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-600">
                    Anlegen
                </button>
            </form>
        </div>
    </div>
</div>
