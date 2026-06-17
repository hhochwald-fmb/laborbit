<?php
if (!defined('DB_HOST')) exit; // Protect direct access

$isManager = ($_SESSION['role'] === 'manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category']) && $isManager) {
    if (!empty(trim($_POST['name']))) {
        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "device_categories (name) VALUES (?)");
        $stmt->execute([trim($_POST['name'])]);
        $success = "Kategorie erfolgreich hinzugefügt!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category']) && $isManager) {
    if (!empty(trim($_POST['name']))) {
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "device_categories SET name = ? WHERE id = ?");
        $stmt->execute([trim($_POST['name']), $_POST['id']]);
        $success = "Kategorie erfolgreich aktualisiert!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category']) && $isManager) {
    $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "device_categories WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $success = "Kategorie erfolgreich gelöscht!";
}

// Sorting logic
$sort = $_GET['sort'] ?? 'name';
$orderBy = "name ASC";
if ($sort === 'name_desc') $orderBy = "name DESC";
elseif ($sort === 'newest') $orderBy = "id DESC";
elseif ($sort === 'oldest') $orderBy = "id ASC";

$categories = $pdo->query("SELECT * FROM " . DB_PREFIX . "device_categories ORDER BY $orderBy")->fetchAll();
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Geräte-Rubriken verwalten</h2>

    <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="page" value="device_categories">
        <label class="text-sm text-slate-500 dark:text-slate-400">Sortieren:</label>
        <select name="sort" class="custom-select px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white text-sm" onchange="this.form.submit()">
            <option value="name" <?php echo $sort == 'name' ? 'selected':''; ?>>Name (A-Z)</option>
            <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected':''; ?>>Name (Z-A)</option>
            <option value="newest" <?php echo $sort == 'newest' ? 'selected':''; ?>>Neueste zuerst</option>
            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected':''; ?>>Älteste zuerst</option>
        </select>
    </form>
</div>

<?php if (isset($success)): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 dark:bg-green-900/30 dark:border-green-800 dark:text-green-400" role="alert">
        <span class="block sm:inline"><?php echo $success; ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                <thead class="bg-slate-50 dark:bg-slate-700/50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-300 uppercase tracking-wider">Name</th>
                        <?php if ($isManager): ?>
                        <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 dark:text-slate-300 uppercase tracking-wider">Aktion</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-slate-800 divide-y divide-slate-200 dark:divide-slate-700">
                    <?php foreach ($categories as $c): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900 dark:text-white">
                            <?php if ($isManager && isset($_GET['edit']) && $_GET['edit'] == $c['id']): ?>
                                <form method="POST" action="?page=device_categories&sort=<?php echo $sort; ?>" class="flex gap-2">
                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($c['name']); ?>" class="px-2 py-1 border rounded bg-white dark:bg-slate-700 dark:text-white border-slate-300 dark:border-slate-600 text-sm">
                                    <button type="submit" name="edit_category" class="bg-primary text-white px-2 py-1 rounded text-xs">Speichern</button>
                                    <a href="?page=device_categories" class="text-slate-500 text-xs px-2 py-1">Abbrechen</a>
                                </form>
                            <?php else: ?>
                                <?php echo htmlspecialchars($c['name']); ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($isManager): ?>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="?page=device_categories&edit=<?php echo $c['id']; ?>" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3">Bearbeiten</a>
                            <form method="POST" class="inline-block" onsubmit="return confirm('Wirklich löschen? Alle zugewiesenen Geräte verlieren nur diese Kategorie, bleiben aber erhalten.');">
                                <input type="hidden" name="delete_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" name="delete_category" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Löschen</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="<?php echo $isManager ? '2' : '1'; ?>" class="px-6 py-4 text-center text-sm text-slate-500">Noch keine Rubriken angelegt.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div>
        <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Neue Rubrik</h3>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name (z.B. Netzteil, Messgerät)</label>
                    <input type="text" name="name" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <button type="submit" name="add_category" class="w-full bg-primary hover:bg-blue-600 text-white py-2 px-4 rounded-md font-medium transition-colors">Hinzufügen</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
