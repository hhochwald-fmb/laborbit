<?php
if (!defined('DB_HOST')) exit; // Protect direct access

// Fetch counts
$counts = [];
$tables = ['experiments' => 'Experimente', 'devices' => 'Geräte', 'classes' => 'Klassen', 'topics' => 'Themengebiete'];

foreach ($tables as $table => $label) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "$table");
    $counts[$table] = ['count' => $stmt->fetchColumn(), 'label' => $label];
}

// Fetch newest experiments (recently updated)
$stmt = $pdo->query("SELECT e.id, e.title, e.updated_at, u.full_name, u.username as creator_username FROM " . DB_PREFIX . "experiments e LEFT JOIN " . DB_PREFIX . "users u ON e.created_by = u.id ORDER BY e.updated_at DESC LIMIT 5");
$newExperiments = $stmt->fetchAll();
?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <?php foreach ($counts as $table => $data): ?>
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700 hover:shadow-md transition-shadow">
        <h3 class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-2"><?php echo htmlspecialchars($data['label']); ?></h3>
        <p class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo $data['count']; ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Zuletzt bearbeitete Experimente</h3>
    <?php if (count($newExperiments) > 0): ?>
        <ul class="divide-y divide-slate-200 dark:divide-slate-700">
            <?php foreach ($newExperiments as $exp): ?>
                <li class="py-3 flex justify-between items-center">
                    <div>
                        <a href="?page=experiments&action=view&id=<?php echo $exp['id']; ?>" class="font-medium text-primary hover:underline">
                            <?php echo htmlspecialchars($exp['title']); ?>
                        </a>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                            Zuletzt geändert am <?php echo date('d.m.Y H:i', strtotime($exp['updated_at'])); ?>
                            <?php if(isset($exp['creator_username'])): ?>
                                von <span class="font-semibold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($exp['full_name'] ?: $exp['creator_username']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="?page=experiments&action=view&id=<?php echo $exp['id']; ?>" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="text-slate-500 dark:text-slate-400">Noch keine Experimente vorhanden.</p>
    <?php endif; ?>
</div>
