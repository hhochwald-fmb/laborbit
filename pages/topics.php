<?php
if (!defined('DB_HOST')) exit; // Protect direct access

$isManager = ($_SESSION['role'] === 'manager');

if ($isManager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_topic'])) {
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "topics (name, parent_id) VALUES (?, ?)");
        $stmt->execute([trim($_POST['name']), $parentId]);
        $success = "Themengebiet hinzugefügt.";
    } elseif (isset($_POST['delete_topic'])) {
        $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "topics WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $success = "Themengebiet gelöscht.";
    }
}

// Sorting logic
$sort = $_GET['sort'] ?? 'struct';
if ($sort === 'name') {
    $orderBy = "t.name ASC";
} elseif ($sort === 'name_desc') {
    $orderBy = "t.name DESC";
} elseif ($sort === 'newest') {
    $orderBy = "t.id DESC";
} elseif ($sort === 'oldest') {
    $orderBy = "t.id ASC";
} else {
    // default: Hierarchical
    $orderBy = "COALESCE(t.parent_id, t.id), t.parent_id IS NOT NULL, t.name ASC";
}

$stmt = $pdo->query("SELECT t.*, p.name as parent_name FROM " . DB_PREFIX . "topics t LEFT JOIN " . DB_PREFIX . "topics p ON t.parent_id = p.id ORDER BY $orderBy");
$topics = $stmt->fetchAll();

// Get parent candidates for the add form
$parentsStmt = $pdo->query("SELECT * FROM " . DB_PREFIX . "topics WHERE parent_id IS NULL ORDER BY name ASC");
$parentCandidates = $parentsStmt->fetchAll();
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Themengebiete verwalten</h2>

    <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="page" value="topics">
        <label class="text-sm text-slate-500 dark:text-slate-400">Sortieren:</label>
        <select name="sort" class="custom-select px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white text-sm" onchange="this.form.submit()">
            <option value="struct" <?php echo $sort == 'struct' ? 'selected':''; ?>>Strukturiert (Standard)</option>
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
    <div class="md:col-span-2 bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
            <thead class="bg-slate-50 dark:bg-slate-700/50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">ID</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Name des Themas</th>
                    <?php if ($isManager): ?>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Aktionen</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-slate-800 divide-y divide-slate-200 dark:divide-slate-700">
                <?php foreach ($topics as $t): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 dark:text-slate-400"><?php echo $t['id']; ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900 dark:text-white">
                        <div class="flex items-center <?php echo ($sort === 'struct' && $t['parent_id']) ? 'ml-6 border-l pl-2 border-slate-300 dark:border-slate-600' : ''; ?>">
                            <?php if ($sort === 'struct' && $t['parent_id']): ?><span class="text-slate-400 mr-2">↳</span><?php endif; ?>
                            <a href="?page=experiments&topic_id=<?php echo $t['id']; ?>" class="text-primary hover:underline" title="Alle Experimente in diesem Thema anzeigen">
                                <?php echo htmlspecialchars($t['name']); ?>
                                <?php if($sort !== 'struct' && $t['parent_name']): ?>
                                    <span class="text-[10px] text-slate-400 ml-2 font-normal">(in <?php echo htmlspecialchars($t['parent_name']); ?>)</span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </td>
                    <?php if ($isManager): ?>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <form method="POST" class="inline-block" onsubmit="return confirm('Wirklich löschen?');">
                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                            <button type="submit" name="delete_topic" class="text-red-500 hover:text-red-700">Löschen</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if(empty($topics)): ?>
            <div class="p-6 text-center text-slate-500">Keine Themengebiete vorhanden.</div>
        <?php endif; ?>
    </div>

    <?php if ($isManager): ?>
    <div>
        <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Neues Thema</h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name (z.B. Mechanik)</label>
                    <input type="text" name="name" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Oberthema (Optional)</label>
                    <select name="parent_id" class="custom-select w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white bg-white">
                        <option value="">-- Als Hauptthema anlegen --</option>
                        <?php foreach($parentCandidates as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_topic" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                    Hinzufügen
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
