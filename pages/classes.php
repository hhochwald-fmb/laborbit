<?php
if (!defined('DB_HOST')) exit; // Protect direct access

// CRUD Logic (Only for manager)
$isManager = ($_SESSION['role'] === 'manager');

if ($isManager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_class'])) {
        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "classes (name) VALUES (?)");
        $stmt->execute([trim($_POST['name'])]);
        $success = "Klasse hinzugefügt.";
    } elseif (isset($_POST['edit_class'])) {
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "classes SET name = ? WHERE id = ?");
        $stmt->execute([trim($_POST['name']), $_POST['id']]);
        $success = "Klasse aktualisiert.";
    } elseif (isset($_POST['delete_class'])) {
        $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "classes WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $success = "Klasse gelöscht.";
    }
}

// Sorting logic
$sort = $_GET['sort'] ?? 'name';
$orderBy = "name ASC";
if ($sort === 'name_desc') $orderBy = "name DESC";
elseif ($sort === 'newest') $orderBy = "id DESC";
elseif ($sort === 'oldest') $orderBy = "id ASC";

// Fetch all classes
$stmt = $pdo->query("SELECT * FROM " . DB_PREFIX . "classes ORDER BY $orderBy");
$classes = $stmt->fetchAll();
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Klassen verwalten</h2>
    
    <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="page" value="classes">
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
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
        <span class="block sm:inline"><?php echo $success; ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- List -> Manager + Viewer/Teacher can see but maybe not edit -->
    <div class="md:col-span-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
            <thead class="bg-slate-50 dark:bg-slate-700/50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Name der Klasse</th>
                    <?php if ($isManager): ?>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Aktionen</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-slate-800 divide-y divide-slate-200 dark:divide-slate-700">
                <?php foreach ($classes as $c): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900 dark:text-white">
                        <a href="?page=experiments&class_id=<?php echo $c['id']; ?>" class="text-primary hover:underline" title="Alle Experimente dieser Klasse anzeigen">
                            <?php echo htmlspecialchars($c['name']); ?>
                        </a>
                    </td>
                    <?php if ($isManager): ?>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <form method="POST" class="inline-block" onsubmit="return confirm('Wirklich löschen? Alle Verknüpfungen mit Experimenten verschwinden.');">
                            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                            <button type="submit" name="delete_class" class="text-red-500 hover:text-red-700">Löschen</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if(empty($classes)): ?>
            <div class="p-6 text-center text-slate-500">Keine Klassen vorhanden.</div>
        <?php endif; ?>
    </div>

    <!-- Add Form (Only for Manager) -->
    <?php if ($isManager): ?>
    <div>
        <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Neue Klasse</h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name (z.B. Klasse 7)</label>
                    <input type="text" name="name" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <button type="submit" name="add_class" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                    Hinzufügen
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
