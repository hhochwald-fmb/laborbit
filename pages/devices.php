<?php
if (!defined('DB_HOST')) exit; // Protect direct access

$canEdit = in_array($_SESSION['role'], ['manager', 'teacher']);
$action = $_GET['action'] ?? 'list';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_device'])) {
        $name = trim($_POST['name']);
        $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
        $locationRemark = trim($_POST['location_description']);
        $description = trim($_POST['description']);
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        
        
        $imagePath = null;
        if (!empty($_FILES['image']['tmp_name'])) {
            $fi = ['name'=>$_FILES['image']['name'], 'type'=>$_FILES['image']['type'], 'tmp_name'=>$_FILES['image']['tmp_name'], 'error'=>$_FILES['image']['error'], 'size'=>$_FILES['image']['size']];
            if ($sn = handleImageUpload($fi, __DIR__ . '/../uploads/devices', 800, 800)) $imagePath = $sn;
        }

        $manualPath = null;
        if (!empty($_FILES['manual']['tmp_name'])) {
            $fi = ['name'=>$_FILES['manual']['name'], 'type'=>$_FILES['manual']['type'], 'tmp_name'=>$_FILES['manual']['tmp_name'], 'error'=>$_FILES['manual']['error'], 'size'=>$_FILES['manual']['size']];
            if ($sn = handlePdfUpload($fi, __DIR__ . '/../uploads/devices')) $manualPath = $sn;
        }

        if (isset($_POST['id']) && $_POST['id'] > 0) {
            $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "devices SET name=?, location_id=?, location_description=?, description=?, category_id=?, image_path=IFNULL(?, image_path), manual_path=IFNULL(?, manual_path) WHERE id=?");
            $stmt->execute([$name, $locationId, $locationRemark, $description, $categoryId, $imagePath, $manualPath, $_POST['id']]);
            $success = "Gerät aktualisiert.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "devices (name, location_id, location_description, description, category_id, image_path, manual_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $locationId, $locationRemark, $description, $categoryId, $imagePath, $manualPath]);
            $success = "Gerät hinzugefügt.";
        }
        $action = 'list';
    } elseif (isset($_POST['delete_device'])) {
        $oldStmt = $pdo->prepare("SELECT image_path, manual_path FROM " . DB_PREFIX . "devices WHERE id = ?");
        $oldStmt->execute([$_POST['id']]);
        if ($old = $oldStmt->fetch()) {
            if ($old['image_path']) @unlink(__DIR__ . '/../uploads/devices/' . $old['image_path']);
            if ($old['manual_path']) @unlink(__DIR__ . '/../uploads/devices/' . $old['manual_path']);
        }

        $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "devices WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $success = "Gerät gelöscht.";
        $action = 'list';
    }
}

if ($action === 'edit' || $action === 'add') {
    $device = ['id' => 0, 'name' => '', 'location_id' => null, 'location_description' => '', 'description' => '', 'category_id' => null, 'image_path' => null, 'manual_path' => null];
    if ($action === 'edit' && $editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "devices WHERE id = ?");
        $stmt->execute([$editId]);
        $device = $stmt->fetch() ?: $device;
    }
    $categories = $pdo->query("SELECT * FROM " . DB_PREFIX . "device_categories ORDER BY name ASC")->fetchAll();
    $locationsList = $pdo->query("SELECT * FROM " . DB_PREFIX . "locations ORDER BY name ASC")->fetchAll();
    ?>
    <div class="mb-4">
        <a href="?page=devices" class="text-primary hover:underline">&larr; Zurück zur Übersicht</a>
    </div>
    
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700 max-w-2xl">
        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4"><?php echo $action === 'add' ? 'Neues Gerät' : 'Gerät bearbeiten'; ?></h3>
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="id" value="<?php echo $device['id']; ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name des Geräts</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($device['name']); ?>" required class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Rubrik (Kategorie)</label>
                <select name="category_id" class="custom-select w-full bg-white px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                    <option value="">Keine Rubrik</option>
                    <?php foreach($categories as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $device['category_id'] == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Standort / Schrank</label>
                <select name="location_id" class="custom-select w-full bg-white px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                    <option value="">Kein fester Standort</option>
                    <?php foreach($locationsList as $l): ?>
                        <option value="<?php echo $l['id']; ?>" <?php echo $device['location_id'] == $l['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Bemerkung zum Standort (Optional)</label>
                <input type="text" name="location_description" value="<?php echo htmlspecialchars($device['location_description'] ?? ''); ?>" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                <p class="mt-1 text-sm text-slate-500">Z.B. "Ganz hinten links" oder "Aktuell verliehen"</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Beschreibung / Details</label>
                <textarea name="description" rows="4" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md shadow-sm focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white"><?php echo htmlspecialchars($device['description']); ?></textarea>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Foto des Geräts (Optional)</label>
                <?php if($device['image_path']): ?>
                    <div class="mb-2">
                        <img src="uploads/devices/<?php echo htmlspecialchars($device['image_path']); ?>" class="h-24 w-auto rounded shadow border border-slate-200 dark:border-slate-600">
                    </div>
                <?php endif; ?>
                <input type="file" name="image" accept="image/*" class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-blue-600 dark:file:bg-slate-700 dark:hover:file:bg-slate-600">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Anleitung / Dokument (PDF, Optional)</label>
                <?php if($device['manual_path']): ?>
                    <div class="mb-2">
                        <div class="flex items-center p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded">
                            <span class="text-2xl mr-2">📄</span>
                            <span class="text-xs font-bold text-red-800 dark:text-red-400">PDF Anleitung vorhanden</span>
                        </div>
                    </div>
                <?php endif; ?>
                <input type="file" name="manual" accept="application/pdf" class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-blue-600 dark:file:bg-slate-700 dark:hover:file:bg-slate-600">
            </div>
            <button type="submit" name="save_device" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                Speichern
            </button>
        </form>
    </div>
    <?php
} else {
    // List logic
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? 'az';
    $catFilter = isset($_GET['cat_id']) && $_GET['cat_id'] !== '' ? (int)$_GET['cat_id'] : 0;
    $locFilter = isset($_GET['loc_id']) && $_GET['loc_id'] !== '' ? (int)$_GET['loc_id'] : 0;
    
    // View mode handling
    $view = $_GET['view'] ?? $_SESSION['devices_view'] ?? 'list';
    if (isset($_GET['view'])) {
        $_SESSION['devices_view'] = $_GET['view'];
    }

    $orderBy = "d.name ASC";
    if ($sort === 'za') $orderBy = "d.name DESC";
    elseif ($sort === 'cat_asc') $orderBy = "category_name ASC, d.name ASC";
    elseif ($sort === 'cat_desc') $orderBy = "category_name DESC, d.name ASC";
    elseif ($sort === 'loc_asc') $orderBy = "location_name ASC, d.name ASC";
    elseif ($sort === 'loc_desc') $orderBy = "location_name DESC, d.name ASC";
    elseif ($sort === 'newest') $orderBy = "d.id DESC";
    elseif ($sort === 'oldest') $orderBy = "d.id ASC";

    $where = [];
    $params = [];
    if ($search) {
        $where[] = "(d.name LIKE ? OR d.location_description LIKE ? OR c.name LIKE ? OR l.name LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    }
    if ($catFilter) {
        $where[] = "d.category_id = ?";
        $params[] = $catFilter;
    }
    if ($locFilter) {
        $where[] = "d.location_id = ?";
        $params[] = $locFilter;
    }
    $whereSql = count($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT d.*, c.name as category_name, l.name as location_name FROM " . DB_PREFIX . "devices d LEFT JOIN " . DB_PREFIX . "device_categories c ON d.category_id = c.id LEFT JOIN " . DB_PREFIX . "locations l ON d.location_id = l.id $whereSql ORDER BY $orderBy");
    $stmt->execute($params);
    $devices = $stmt->fetchAll();

    $allCategories = $pdo->query("SELECT * FROM " . DB_PREFIX . "device_categories ORDER BY name ASC")->fetchAll();
    $allLocations = $pdo->query("SELECT * FROM " . DB_PREFIX . "locations ORDER BY name ASC")->fetchAll();
    
    // Fetch experiment usages
    $usageStmt = $pdo->query("SELECT ed.device_id, e.id as exp_id, e.title as exp_title FROM " . DB_PREFIX . "experiment_device ed JOIN " . DB_PREFIX . "experiments e ON ed.experiment_id = e.id ORDER BY e.title ASC");
    $usages = [];
    foreach ($usageStmt->fetchAll() as $row) {
        $usages[$row['device_id']][] = $row;
    }

    // Helper for keeping filters when switching view
    if (!function_exists('getQueryWith')) {
        function getQueryWith($newParams) {
            $params = $_GET;
            foreach($newParams as $k => $v) $params[$k] = $v;
            return '?' . http_build_query($params);
        }
    }
    ?>
    
    <!-- Usage Modal -->
    <div id="usage-modal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target === this) closeUsageModal()">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-700 transform transition-all animate-in fade-in zoom-in duration-200" onclick="event.stopPropagation()">
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50/50 dark:bg-slate-700/30">
                <h3 class="font-bold text-lg dark:text-white" id="modal-device-name">Geräte-Nutzung</h3>
                <button onclick="closeUsageModal()" class="p-1 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors">
                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 max-h-[60vh] overflow-y-auto" id="modal-content">
                <!-- Content injected via JS -->
            </div>
            <div class="p-4 border-t border-slate-100 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-700/30 text-right">
                <button onclick="closeUsageModal()" class="px-5 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-white rounded-lg font-bold hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors">Schließen</button>
            </div>
        </div>
    </div>

    <script>
    function openUsageModal(deviceName, experimentsJson) {
        const experiments = JSON.parse(experimentsJson);
        document.getElementById('modal-device-name').innerText = 'Experimente mit ' + deviceName;
        const content = document.getElementById('modal-content');
        content.innerHTML = '<ul class="space-y-3"></ul>';
        const ul = content.querySelector('ul');
        
        experiments.forEach(exp => {
            const li = document.createElement('li');
            li.innerHTML = `<a href="?page=experiments&action=view&id=${encodeURIComponent(exp.exp_id)}" class="flex items-center gap-3 p-3 rounded-xl border border-slate-100 dark:border-slate-700 hover:bg-primary/5 hover:border-primary/20 transition-all group">
                <div class="w-10 h-10 flex items-center justify-center rounded-lg bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 text-xl">🔬</div>
                <div class="flex-1">
                    <div class="font-bold text-slate-700 dark:text-slate-300 group-hover:text-primary transition-colors leading-snug">${escHtml(exp.exp_title)}</div>
                    <div class="text-[10px] text-slate-400 font-medium uppercase mt-0.5">Zum Experiment details &rarr;</div>
                </div>
            </a>`;
            ul.appendChild(li);
        });
        
        const modal = document.getElementById('usage-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }

    function closeUsageModal() {
        const modal = document.getElementById('usage-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeUsageModal();
    });
    </script>

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Geräteverwaltung</h2>
        <div class="flex items-center gap-3">
            <div class="flex bg-slate-200 dark:bg-slate-700 p-1 rounded-lg">
                <a href="<?php echo getQueryWith(['view' => 'list']); ?>" class="p-1.5 rounded-md transition-all <?php echo $view === 'list' ? 'bg-white dark:bg-slate-600 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>" title="Listenansicht">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </a>
                <a href="<?php echo getQueryWith(['view' => 'grid']); ?>" class="p-1.5 rounded-md transition-all <?php echo $view === 'grid' ? 'bg-white dark:bg-slate-600 shadow-sm text-primary' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>" title="Kachelansicht">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                </a>
            </div>
            <?php if ($canEdit): ?>
                <a href="?page=devices&action=add" class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold transition-all shadow-md shadow-primary/20">
                    + Neues Gerät
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-xl mb-6 flex items-center gap-3">
            <span class="text-xl">✅</span>
            <span class="font-medium"><?php echo $success; ?></span>
        </div>
    <?php endif; ?>

    <div class="mb-6 bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
        <form method="GET" class="flex flex-wrap gap-3">
            <input type="hidden" name="page" value="devices">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            
            <div class="flex-1 min-w-[240px] relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Gerät suchen..." class="w-full pl-10 pr-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white outline-none transition-all">
            </div>
            
            <select name="cat_id" class="custom-select px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white text-sm" onchange="this.form.submit()">
                <option value="">Alle Rubriken</option>
                <?php foreach($allCategories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $catFilter == $c['id'] ? 'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="loc_id" class="custom-select px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white text-sm" onchange="this.form.submit()">
                <option value="">Alle Standorte</option>
                <?php foreach($allLocations as $l): ?>
                    <option value="<?php echo $l['id']; ?>" <?php echo $locFilter == $l['id'] ? 'selected':''; ?>><?php echo htmlspecialchars($l['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="sort" class="custom-select px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white text-sm" onchange="this.form.submit()">
                <option value="az" <?php echo $sort == 'az' ? 'selected':''; ?>>Name (A-Z)</option>
                <option value="za" <?php echo $sort == 'za' ? 'selected':''; ?>>Name (Z-A)</option>
                <option value="cat_asc" <?php echo $sort == 'cat_asc' ? 'selected':''; ?>>Rubrik (A-Z)</option>
                <option value="cat_desc" <?php echo $sort == 'cat_desc' ? 'selected':''; ?>>Rubrik (Z-A)</option>
                <option value="loc_asc" <?php echo $sort == 'loc_asc' ? 'selected':''; ?>>Standort (A-Z)</option>
                <option value="loc_desc" <?php echo $sort == 'loc_desc' ? 'selected':''; ?>>Standort (Z-A)</option>
                <option value="newest" <?php echo $sort == 'newest' ? 'selected':''; ?>>Neueste zuerst</option>
                <option value="oldest" <?php echo $sort == 'oldest' ? 'selected':''; ?>>Älteste zuerst</option>
            </select>

            <button type="submit" class="bg-primary hover:bg-blue-600 text-white px-5 py-2 rounded-lg font-medium transition-colors shadow-sm">Suchen</button>
            <?php if($search || $catFilter || $locFilter || $sort !== 'az'): ?>
                <a href="?page=devices<?php echo isset($_GET['view']) ? '&view='.$_GET['view']:''; ?>" class="text-slate-500 hover:text-primary inline-flex items-center ml-1 text-sm font-medium transition-colors">Zurücksetzen</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($view === 'grid'): ?>
        <!-- Grid View -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($devices as $d): 
                $deviceUsages = $usages[$d['id']] ?? [];
                $usageJson = htmlspecialchars(json_encode($deviceUsages));
            ?>
                <div class="group bg-white dark:bg-slate-800 rounded-2xl shadow-sm hover:shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden transition-all duration-300 flex flex-col">
                    <!-- Info ABOVE image -->
                    <div class="p-4 bg-slate-50/50 dark:bg-slate-700/30 border-b border-slate-100 dark:border-slate-700">
                        <h3 class="font-bold text-slate-900 dark:text-white leading-tight mb-1 line-clamp-2 min-h-[3rem]" title="<?php echo htmlspecialchars($d['name']); ?>">
                            <?php echo htmlspecialchars($d['name']); ?>
                        </h3>
                        <div class="flex items-center justify-between">
                            <?php if($d['category_name']): ?>
                                <span class="inline-block px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-600 text-[10px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-tighter">
                                    <?php echo htmlspecialchars($d['category_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-block px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-tighter">
                                    Keine Rubrik
                                </span>
                            <?php endif; ?>

                            <?php if(count($deviceUsages) > 0): ?>
                                <button onclick='openUsageModal(<?php echo json_encode($d["name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode(json_encode($deviceUsages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-[10px] font-bold text-primary hover:underline uppercase tracking-tighter">
                                    🔬 <?php echo count($deviceUsages); ?> Instanzen
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Large Image -->
                    <div class="relative aspect-[4/3] bg-slate-100 dark:bg-slate-900/50 flex items-center justify-center overflow-hidden">
                        <?php if($d['image_path']): ?>
                            <img src="uploads/devices/<?php echo htmlspecialchars($d['image_path']); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                        <?php else: ?>
                            <div class="flex flex-col items-center opacity-20">
                                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span class="text-xs font-bold mt-2">Kein Bild</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($d['manual_path']): ?>
                            <a href="uploads/devices/<?php echo htmlspecialchars($d['manual_path']); ?>" target="_blank" class="absolute top-3 right-3 bg-red-600 hover:bg-red-700 text-white text-[10px] font-bold px-2 py-1 rounded-md shadow-lg transition-colors flex items-center gap-1 z-10">
                                <span class="text-xs">📄</span> PDF
                            </a>
                        <?php endif; ?>

                        <!-- Overlay actions on hover -->
                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 flex items-center justify-center gap-3 transition-opacity duration-300">
                             <?php if ($canEdit): ?>
                                <a href="?page=devices&action=edit&id=<?php echo $d['id']; ?>" class="bg-white text-slate-900 px-4 py-2 rounded-lg text-sm font-bold hover:bg-primary hover:text-white transition-all shadow-xl">Bearbeiten</a>
                             <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer Details -->
                    <div class="p-4 flex-1 flex flex-col bg-white dark:bg-slate-800">
                        <div class="mb-3">
                            <?php if($d['location_name']): ?>
                                <div class="flex items-start gap-2 text-blue-600 dark:text-blue-400 mb-2">
                                    <span class="mt-0.5">📍</span>
                                    <div>
                                        <div class="text-xs font-bold"><?php echo htmlspecialchars($d['location_name']); ?></div>
                                        <?php if($d['location_description']): ?>
                                            <div class="text-[10px] text-slate-500 dark:text-slate-400 leading-tight italic"><?php echo htmlspecialchars($d['location_description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if($d['description']): ?>
                                <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-3 leading-relaxed">
                                    <?php echo htmlspecialchars($d['description']); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <?php if ($canEdit): ?>
                            <div class="mt-auto pt-3 border-t border-slate-100 dark:border-slate-700 flex justify-between items-center">
                                <form method="POST" class="w-full" onsubmit="return confirm('Wirklich löschen?');">
                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                    <button type="submit" name="delete_device" class="text-[10px] font-bold text-red-400 hover:text-red-600 uppercase tracking-widest transition-colors flex items-center gap-1">
                                        Löschen
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <!-- List View -->
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-700/50">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Name</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Rubrik</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Standort</th>
                            <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-slate-800 divide-y divide-slate-200 dark:divide-slate-700">
                        <?php foreach ($devices as $d): 
                            $deviceUsages = $usages[$d['id']] ?? [];
                        ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <?php if($d['image_path']): ?>
                                        <div class="relative group mr-4 z-10 shrink-0 cursor-pointer">
                                            <div class="w-12 h-12 rounded-lg shadow-sm object-cover bg-slate-100 dark:bg-slate-700 overflow-hidden border border-slate-200 dark:border-slate-600">
                                                <img src="uploads/devices/<?php echo htmlspecialchars($d['image_path']); ?>" class="w-full h-full object-cover">
                                            </div>
                                            <!-- Tooltip Zoom -->
                                            <div class="absolute left-full top-0 ml-3 hidden group-hover:block shadow-2xl rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-600 z-[99] bg-white w-72">
                                                <img src="uploads/devices/<?php echo htmlspecialchars($d['image_path']); ?>" class="w-full h-auto block">
                                                <div class="p-3 bg-white dark:bg-slate-800 border-t border-slate-100 dark:border-slate-700">
                                                    <div class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($d['name']); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center mr-4 text-xl opacity-30">🔌</div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <div class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($d['name']); ?></div>
                                            <?php if($d['manual_path']): ?>
                                                <a href="uploads/devices/<?php echo htmlspecialchars($d['manual_path']); ?>" target="_blank" class="px-1.5 py-0.5 bg-red-100 dark:bg-red-900/40 text-[9px] font-black rounded text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800" title="PDF Anleitung öffnen">PDF</a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-3 mt-1">
                                            <?php if ($d['description']): ?>
                                                <div class="text-xs text-slate-500 dark:text-slate-400 line-clamp-1 max-w-sm"><?php echo htmlspecialchars($d['description']); ?></div>
                                            <?php endif; ?>
                                            <?php if(count($deviceUsages) > 0): ?>
                                                <button onclick='openUsageModal(<?php echo json_encode($d["name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo json_encode(json_encode($deviceUsages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-[10px] font-bold text-primary hover:underline whitespace-nowrap">
                                                    🔬 In <?php echo count($deviceUsages); ?> Experimenten
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if($d['category_name']): ?>
                                    <span class="bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-300 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider"><?php echo htmlspecialchars($d['category_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-slate-300 dark:text-slate-600 italic text-xs">Keine Rubrik</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if($d['location_name']): ?>
                                    <div class="flex flex-col">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300 w-fit">
                                            <?php echo htmlspecialchars($d['location_name']); ?>
                                        </span>
                                        <?php if($d['location_description']): ?>
                                            <div class="text-[10px] text-slate-400 mt-1 italic"><?php echo htmlspecialchars($d['location_description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-300 dark:text-slate-600 italic text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <?php if ($canEdit): ?>
                                    <div class="flex justify-end gap-4">
                                        <a href="?page=devices&action=edit&id=<?php echo $d['id']; ?>" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Bearbeiten</a>
                                        <form method="POST" class="inline-block" onsubmit="return confirm('Wirklich löschen?');">
                                            <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" name="delete_device" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Löschen</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-400 text-xs italic">Nur lesen</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if(empty($devices)): ?>
                <div class="p-12 text-center">
                    <div class="text-4xl mb-4">🔍</div>
                    <div class="text-slate-500 font-medium">Keine Geräte gefunden.</div>
                    <p class="text-xs text-slate-400 mt-1">Versuche es mit anderen Suchbegriffen oder Filtern.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
}
?>
