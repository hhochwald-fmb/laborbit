<?php
if (!defined('DB_HOST')) exit; // Protect direct access

$canEdit = in_array($_SESSION['role'], ['manager', 'teacher']);
$action = $_GET['action'] ?? 'list';
$expId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ==========================================
// FORM SUBMISSION (ADD / EDIT) // (Truncated to save space, assuming unchanged except for file uploading which works)
// ==========================================
if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_experiment'])) {
    $editModeId = isset($_POST['experiment_id']) ? (int)$_POST['experiment_id'] : 0;
    
    $title = trim($_POST['title']);
    $description = strip_tags(trim($_POST['description']));
    $setup = sanitizeRichText(trim($_POST['setup_instructions'] ?? ''));
    $tips = sanitizeRichText(trim($_POST['tips'] ?? ''));
    $measurements = strip_tags(trim($_POST['measurements']));
    
    if ($editModeId > 0) {
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "experiments SET title=?, description=?, setup_instructions=?, tips=?, measurements=? WHERE id=?");
        $stmt->execute([$title, $description, $setup, $tips, $measurements, $editModeId]);
        $targetId = $editModeId;
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "experiment_class WHERE experiment_id=?")->execute([$targetId]);
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "experiment_topic WHERE experiment_id=?")->execute([$targetId]);
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "experiment_device WHERE experiment_id=?")->execute([$targetId]);
    } else {
        $creatorId = $_SESSION['user_id'] ?? null;
        $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "experiments (title, description, setup_instructions, tips, measurements, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $setup, $tips, $measurements, $creatorId]);
        $targetId = $pdo->lastInsertId();
    }

    if (!empty($_POST['classes'])) { $sc = $pdo->prepare("INSERT INTO " . DB_PREFIX . "experiment_class (experiment_id, class_id) VALUES (?, ?)"); foreach ($_POST['classes'] as $cid) $sc->execute([$targetId, $cid]); }
    if (!empty($_POST['topics'])) { $st = $pdo->prepare("INSERT INTO " . DB_PREFIX . "experiment_topic (experiment_id, topic_id) VALUES (?, ?)"); foreach ($_POST['topics'] as $tid) $st->execute([$targetId, $tid]); }
    if (!empty($_POST['devices'])) { $sd = $pdo->prepare("INSERT INTO " . DB_PREFIX . "experiment_device (experiment_id, device_id) VALUES (?, ?)"); foreach ($_POST['devices'] as $did) $sd->execute([$targetId, $did]); }

    if (!empty($_FILES['photos']['name'][0])) {
        foreach ($_FILES['photos']['name'] as $key => $name) {
            $fi = ['name'=>$_FILES['photos']['name'][$key], 'type'=>$_FILES['photos']['type'][$key], 'tmp_name'=>$_FILES['photos']['tmp_name'][$key], 'error'=>$_FILES['photos']['error'][$key], 'size'=>$_FILES['photos']['size'][$key]];
            if($sn = handleImageUpload($fi, __DIR__ . '/../uploads/photos')) $pdo->prepare("INSERT INTO " . DB_PREFIX . "media (experiment_id, type, file_path) VALUES (?, 'photo', ?)")->execute([$targetId, $sn]);
        }
    }
    if (!empty($_FILES['videos']['name'][0])) {
         foreach ($_FILES['videos']['name'] as $key => $name) {
            $fi = ['name'=>$_FILES['videos']['name'][$key], 'type'=>$_FILES['videos']['type'][$key], 'tmp_name'=>$_FILES['videos']['tmp_name'][$key], 'error'=>$_FILES['videos']['error'][$key], 'size'=>$_FILES['videos']['size'][$key]];
            if($sn = handleVideoUpload($fi, __DIR__ . '/../uploads/videos')) $pdo->prepare("INSERT INTO " . DB_PREFIX . "media (experiment_id, type, file_path) VALUES (?, 'video', ?)")->execute([$targetId, $sn]);
        }
    }
    if (!empty($_FILES['pdfs']['name'][0])) {
         foreach ($_FILES['pdfs']['name'] as $key => $name) {
            $fi = ['name'=>$_FILES['pdfs']['name'][$key], 'type'=>$_FILES['pdfs']['type'][$key], 'tmp_name'=>$_FILES['pdfs']['tmp_name'][$key], 'error'=>$_FILES['pdfs']['error'][$key], 'size'=>$_FILES['pdfs']['size'][$key]];
            if($sn = handlePdfUpload($fi, __DIR__ . '/../uploads/pdfs')) $pdo->prepare("INSERT INTO " . DB_PREFIX . "media (experiment_id, type, file_path) VALUES (?, 'pdf', ?)")->execute([$targetId, $sn]);
        }
    }
    if (!empty($_FILES['measurements_files']['name'][0])) {
         foreach ($_FILES['measurements_files']['name'] as $key => $name) {
            $fi = ['name'=>$_FILES['measurements_files']['name'][$key], 'type'=>$_FILES['measurements_files']['type'][$key], 'tmp_name'=>$_FILES['measurements_files']['tmp_name'][$key], 'error'=>$_FILES['measurements_files']['error'][$key], 'size'=>$_FILES['measurements_files']['size'][$key]];
            if($sn = handleMeasurementUpload($fi, __DIR__ . '/../uploads/measurements')) $pdo->prepare("INSERT INTO " . DB_PREFIX . "media (experiment_id, type, file_path) VALUES (?, 'measurement', ?)")->execute([$targetId, $sn]);
        }
    }
    
    if (!empty($_FILES['risk_assessment']['name'])) {
        $fi = ['name'=>$_FILES['risk_assessment']['name'], 'type'=>$_FILES['risk_assessment']['type'], 'tmp_name'=>$_FILES['risk_assessment']['tmp_name'], 'error'=>$_FILES['risk_assessment']['error'], 'size'=>$_FILES['risk_assessment']['size']];
        $isImg = preg_match('/\.(jpg|jpeg|png|webp)$/i', $fi['name']);
        $uploaded = $isImg ? handleImageUpload($fi, __DIR__ . '/../uploads/risk_assessments') : handlePdfUpload($fi, __DIR__ . '/../uploads/risk_assessments');
        if($uploaded) $pdo->prepare("INSERT INTO " . DB_PREFIX . "media (experiment_id, type, file_path) VALUES (?, 'risk_assessment', ?)")->execute([$targetId, $uploaded]);
    }

    exit(header("Location: ?page=experiments&action=view&id=" . $targetId));
}

// DELETE action
if ($canEdit && isset($_POST['delete_experiment'])) {
    $stmt = $pdo->prepare("SELECT type, file_path FROM " . DB_PREFIX . "media WHERE experiment_id = ?");
    $stmt->execute([$_POST['delete_id']]);
    foreach ($stmt->fetchAll() as $m) {
        $dir = [
            'photo' => 'uploads/photos/',
            'video' => 'uploads/videos/',
            'measurement' => 'uploads/measurements/',
            'pdf' => 'uploads/pdfs/',
            'risk_assessment' => 'uploads/risk_assessments/'
        ][$m['type']] ?? 'uploads/photos/';
        $p = __DIR__ . '/../' . $dir . $m['file_path'];
        if(file_exists($p)) @unlink($p);
    }
    $pdo->prepare("DELETE FROM " . DB_PREFIX . "experiments WHERE id = ?")->execute([$_POST['delete_id']]);
    exit(header("Location: ?page=experiments"));
}

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['delete_media']) && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT type, file_path FROM " . DB_PREFIX . "media WHERE id = ? AND experiment_id = ?");
    $stmt->execute([(int)$_GET['delete_media'], $expId]);
    if ($m = $stmt->fetch()) {
        $dir = [
            'photo' => 'uploads/photos/',
            'video' => 'uploads/videos/',
            'measurement' => 'uploads/measurements/',
            'pdf' => 'uploads/pdfs/',
            'risk_assessment' => 'uploads/risk_assessments/'
        ][$m['type']] ?? 'uploads/photos/';
        $p = __DIR__ . '/../' . $dir . $m['file_path'];
        if(file_exists($p)) @unlink($p);
        $pdo->prepare("DELETE FROM " . DB_PREFIX . "media WHERE id = ?")->execute([(int)$_GET['delete_media']]);
    }
    exit(header("Location: ?page=experiments&action=edit&id=" . $expId));
}

// ==========================================
// VIEW: ADD / EDIT FORM
// ==========================================
if (($action === 'add' || $action === 'edit') && $canEdit) {
    if ($action === 'edit' && $expId <= 0) exit("Invalid ID");
    
    $exp = ['id'=>0, 'title'=> '', 'description'=> '', 'setup_instructions'=> '', 'tips'=> '', 'measurements'=> ''];
    $selectedClasses = []; $selectedTopics = []; $selectedDevices = []; $mediaFiles = [];
    
    if ($action === 'edit') {
        $exp = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "experiments WHERE id = ?"); $exp->execute([$expId]); $exp = $exp->fetch() ?: $exp;
        $sc = $pdo->prepare("SELECT class_id FROM " . DB_PREFIX . "experiment_class WHERE experiment_id = ?"); $sc->execute([$expId]); $selectedClasses = $sc->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare("SELECT topic_id FROM " . DB_PREFIX . "experiment_topic WHERE experiment_id = ?"); $st->execute([$expId]); $selectedTopics = $st->fetchAll(PDO::FETCH_COLUMN);
        $sd = $pdo->prepare("SELECT device_id FROM " . DB_PREFIX . "experiment_device WHERE experiment_id = ?"); $sd->execute([$expId]); $selectedDevices = $sd->fetchAll(PDO::FETCH_COLUMN);
        $mediaFiles = $pdo->query("SELECT * FROM " . DB_PREFIX . "media WHERE experiment_id = $expId")->fetchAll();
    }

    $classes = $pdo->query("SELECT * FROM " . DB_PREFIX . "classes ORDER BY name ASC")->fetchAll();
    $topics = $pdo->query("SELECT * FROM " . DB_PREFIX . "topics ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, name ASC")->fetchAll();
    $devices = $pdo->query("SELECT d.*, c.id as cat_id, c.name as cat_name, l.name as location_name FROM " . DB_PREFIX . "devices d LEFT JOIN " . DB_PREFIX . "device_categories c ON d.category_id = c.id LEFT JOIN " . DB_PREFIX . "locations l ON d.location_id = l.id ORDER BY d.name ASC")->fetchAll();
    $deviceCategories = $pdo->query("SELECT * FROM " . DB_PREFIX . "device_categories ORDER BY name ASC")->fetchAll();
    ?>
    <div class="mb-4"><a href="?page=experiments<?php echo $action==='edit' ? '&action=view&id='.$expId : ''; ?>" class="text-primary hover:underline">&larr; Zurück</a></div>
    
    <form method="POST" enctype="multipart/form-data" class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
        <input type="hidden" name="experiment_id" value="<?php echo $exp['id']; ?>">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6"><?php echo $action === 'edit' ? 'Experiment bearbeiten' : 'Neues Experiment anlegen'; ?></h2>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-6">
                <div><label class="block text-sm font-medium dark:text-slate-300 mb-1">Titel des Experiments</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($exp['title']); ?>" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-primary dark:bg-slate-700 dark:text-white"></div>
                
                <div><label class="block text-sm font-medium dark:text-slate-300 mb-1">Kurze Beschreibung (Einfacher Text)</label>
                <textarea name="description" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-primary dark:bg-slate-700 dark:text-white"><?php echo htmlspecialchars($exp['description']); ?></textarea></div>
                
                <div><label class="block text-sm font-medium dark:text-slate-300 mb-1">Aufbaubeschreibung</label>
                <input id="x_setup" type="hidden" name="setup_instructions" value="<?php echo htmlspecialchars($exp['setup_instructions']); ?>">
                <trix-editor input="x_setup" class="trix-content w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg dark:bg-slate-700 dark:text-white bg-white min-h-[100px]"></trix-editor></div>
                
                <div><label class="block text-sm font-medium dark:text-slate-300 mb-1">Tipps & Hinweise</label>
                <input id="x_tips" type="hidden" name="tips" value="<?php echo htmlspecialchars($exp['tips']); ?>">
                <trix-editor input="x_tips" class="trix-content w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg dark:bg-slate-700 dark:text-white bg-white min-h-[100px]"></trix-editor></div>
                
                <div><label class="block text-sm font-medium dark:text-slate-300 mb-1">Messergebnisse (Einfacher Text)</label>
                <textarea name="measurements" rows="3" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-primary dark:bg-slate-700 dark:text-white"><?php echo htmlspecialchars($exp['measurements']); ?></textarea></div>
                
                <div class="p-4 bg-slate-50 dark:bg-slate-700/50 rounded-lg border border-slate-200 dark:border-slate-600 space-y-4">
                    <div class="flex items-center gap-2 mb-4">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Medien hochladen</h3>
                        <div class="relative group cursor-help z-10 w-5 h-5">
                            <span class="w-5 h-5 flex items-center justify-center rounded-full bg-slate-200 dark:bg-slate-600 text-slate-500 dark:text-slate-300 text-xs font-bold font-serif">?</span>
                            <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-64 p-3 bg-slate-800 dark:bg-slate-900 text-white text-xs rounded-lg opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity shadow-xl">
                                <strong>Upload-Limits:</strong> Max. 500 MB pro Datei.<br><br>
                                <strong>Erlaubte Formate:</strong><br>
                                • Fotos: JPG, PNG, WEBP<br>
                                • Videos: MP4, WebM, Quicktime<br>
                                • PDFs: PDF<br>
                                • Messungen: CSV, TXT, Bilder
                                <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-slate-800 dark:border-t-slate-900"></div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold uppercase text-slate-500 mb-1">Aufbau-Fotos</label>
                            <input type="file" name="photos[]" multiple accept="image/*" class="multi-upload block w-full text-xs text-slate-500 file:py-1 file:px-2 file:rounded file:border-0 file:bg-primary file:text-white hover:file:bg-blue-600" data-type="photo">
                            <div class="file-list text-[10px] text-slate-500 space-y-1" data-type="photo"></div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold uppercase text-slate-500 mb-1">Videos</label>
                            <input type="file" name="videos[]" multiple accept="video/mp4,video/webm,video/quicktime" class="multi-upload block w-full text-xs text-slate-500 file:py-1 file:px-2 file:rounded file:border-0 file:bg-purple-200 file:text-purple-800 hover:file:bg-purple-300" data-type="video">
                            <div class="file-list text-[10px] text-slate-500 space-y-1" data-type="video"></div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold uppercase text-slate-500 mb-1">PDFs</label>
                            <input type="file" name="pdfs[]" multiple accept="application/pdf" class="multi-upload block w-full text-xs text-slate-500 file:py-1 file:px-2 file:rounded file:border-0 file:bg-slate-200 file:text-slate-700 hover:file:bg-slate-300" data-type="pdf">
                            <div class="file-list text-[10px] text-slate-500 space-y-1" data-type="pdf"></div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold uppercase text-slate-500 mb-1">Messergebnisse</label>
                            <input type="file" name="measurements_files[]" multiple accept=".csv, .txt, image/*" class="multi-upload block w-full text-xs text-slate-500 file:py-1 file:px-2 file:rounded file:border-0 file:bg-emerald-200 file:text-emerald-800 hover:file:bg-emerald-300" data-type="meas">
                            <div class="file-list text-[10px] text-slate-500 space-y-1" data-type="meas"></div>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-red-50 dark:bg-red-900/10 rounded-lg border border-red-200 dark:border-red-900/30">
                    <label class="block text-sm font-bold text-red-800 dark:text-red-400 mb-2 flex items-center">
                        ⚠️ Gefährdungsbeurteilung hochladen
                    </label>
                    <input type="file" name="risk_assessment" accept="application/pdf, image/*" class="block w-full text-xs text-slate-500 file:py-2 file:px-4 file:rounded file:border-0 file:bg-red-100 dark:file:bg-red-900/50 file:text-red-800 dark:file:text-red-300 hover:file:bg-red-200">
                    <p class="mt-1 text-[10px] text-red-600 dark:text-red-500 italic">Hinweis: Falls bereits eine Beurteilung existiert, wird diese durch den neuen Upload ergänzt.</p>
                </div>

                <script>
                // Multiple File Accumulator Logic
                const fileAccumulators = {
                    photo: new DataTransfer(),
                    video: new DataTransfer(),
                    pdf: new DataTransfer(),
                    meas: new DataTransfer()
                };

                document.querySelectorAll('.multi-upload').forEach(input => {
                    input.addEventListener('change', function(e) {
                        const type = this.getAttribute('data-type');
                        const accumulator = fileAccumulators[type];
                        const listDiv = document.querySelector(`.file-list[data-type="${type}"]`);
                        
                        // Add new files to accumulator
                        Array.from(this.files).forEach(file => {
                            // Basic check if already in list to avoid duplicates? (Optional)
                            accumulator.items.add(file);
                        });

                        // Important: Update the input's file list to include ALL accumulated files
                        this.files = accumulator.files;

                        // Render list
                        renderFileList(type);
                    });
                });

                function renderFileList(type) {
                    const accumulator = fileAccumulators[type];
                    const listDiv = document.querySelector(`.file-list[data-type="${type}"]`);
                    listDiv.innerHTML = '';
                    
                    if (accumulator.files.length > 0) {
                        const header = document.createElement('div');
                        header.className = 'font-bold mb-1';
                        header.innerText = `${accumulator.files.length} Datei(en) ausgewählt:`;
                        listDiv.appendChild(header);

                        Array.from(accumulator.files).forEach((file, index) => {
                            const item = document.createElement('div');
                            item.className = 'flex items-center justify-between bg-slate-100 dark:bg-slate-600 p-1 rounded';
                            item.innerHTML = `<span class="truncate pr-2">${escHtml(file.name)} (${(file.size/1024).toFixed(0)} KB)</span>`;
                            
                            const removeBtn = document.createElement('button');
                            removeBtn.type = 'button';
                            removeBtn.className = 'text-red-500 font-bold px-1 hover:bg-red-100 rounded';
                            removeBtn.innerText = '×';
                            removeBtn.onclick = () => {
                                // DataTransfer doesn't support easy removal, we have to recreate it
                                const newDT = new DataTransfer();
                                Array.from(accumulator.files).forEach((f, i) => {
                                    if(i !== index) newDT.items.add(f);
                                });
                                fileAccumulators[type] = newDT;
                                document.querySelector(`.multi-upload[data-type="${type}"]`).files = newDT.files;
                                renderFileList(type);
                            };
                            
                            item.appendChild(removeBtn);
                            listDiv.appendChild(item);
                        });
                    }
                }
                </script>

                <?php if ($action === 'edit' && count($mediaFiles) > 0): ?>
                <div class="bg-red-50 dark:bg-red-900/10 p-4 rounded-lg border border-red-200 dark:border-red-900/30">
                    <h3 class="font-semibold text-red-800 dark:text-red-400 mb-2 text-sm uppercase">Vorhandene Medien löschen</h3>
                    <ul class="space-y-2">
                        <?php foreach($mediaFiles as $m): ?>
                            <li class="flex justify-between text-sm bg-white dark:bg-slate-800 p-2 rounded shadow-sm">
                                <span class="truncate dark:text-slate-300 mr-2">[<?php echo strtoupper($m['type']); ?>] <?php echo htmlspecialchars(getHumanFilename($m['file_path'])); ?></span>
                                <button type="submit" formaction="?page=experiments&action=edit&id=<?php echo (int)$expId; ?>&delete_media=<?php echo (int)$m['id']; ?>" formnovalidate class="text-red-500 hover:underline flex-shrink-0" onclick="return confirm('Datei löschen?')">Löschen</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <div class="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                    <label class="block font-semibold dark:text-white mb-2">Klassen</label>
                    <div class="max-h-40 overflow-y-auto space-y-1">
                        <?php foreach($classes as $c): ?>
                            <label class="flex items-center space-x-2 text-sm dark:text-slate-300">
                                <input type="checkbox" name="classes[]" value="<?php echo $c['id']; ?>" <?php echo in_array($c['id'], $selectedClasses) ? 'checked' : ''; ?> class="rounded border-slate-300 text-primary"><span><?php echo htmlspecialchars($c['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                    <label class="block font-semibold dark:text-white mb-2">Themen</label>
                    <div class="max-h-40 overflow-y-auto space-y-1">
                        <?php foreach($topics as $t): ?>
                            <label class="flex items-center space-x-2 text-sm dark:text-slate-300 <?php echo $t['parent_id'] ? 'ml-4' : ''; ?>">
                                <?php if ($t['parent_id']): ?><span class="text-slate-400">↳</span><?php endif; ?>
                                <input type="checkbox" name="topics[]" value="<?php echo $t['id']; ?>" <?php echo in_array($t['id'], $selectedTopics) ? 'checked' : ''; ?> class="rounded border-slate-300 text-primary"><span><?php echo htmlspecialchars($t['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                 <div class="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-lg border border-slate-200 dark:border-slate-600">
                    <label class="block font-semibold dark:text-white mb-3 flex justify-between items-center">
                        Geräte
                        <button type="button" onclick="openDeviceModal()" class="text-xs bg-primary hover:bg-blue-600 text-white px-2 py-1 rounded transition-colors">+ Hinzufügen</button>
                    </label>
                    
                    <div id="selected-devices-list" class="space-y-2">
                        <!-- Dynamically populated -->
                        <?php if(empty($selectedDevices)): ?>
                            <p class="text-xs text-slate-500 italic no-devices-msg">Keine Geräte ausgewählt.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Hidden inputs for form submission -->
                    <div id="hidden-device-inputs">
                        <?php foreach($selectedDevices as $did): ?>
                            <input type="hidden" name="devices[]" value="<?php echo $did; ?>" data-device-id="<?php echo $did; ?>">
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Device Selection Modal -->
                <div id="device-modal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden border border-slate-200 dark:border-slate-700 flex flex-col transform transition-all animate-in fade-in zoom-in duration-200">
                        <!-- Modal Header -->
                        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50/50 dark:bg-slate-700/30">
                            <div>
                                <h3 class="font-bold text-xl dark:text-white">Geräte auswählen</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Wähle die benötigten Geräte für dieses Experiment aus.</p>
                            </div>
                            <button type="button" onclick="closeDeviceModal()" class="p-2 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-full transition-colors">
                                <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>

                        <!-- Modal Filters -->
                        <div class="p-6 border-b border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800">
                            <div class="flex flex-wrap gap-4">
                                <div class="flex-1 min-w-[200px] relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                                    <input type="text" id="modal-dev-search" placeholder="Gerät suchen..." class="w-full pl-10 pr-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white outline-none transition-all">
                                </div>
                                <select id="modal-dev-cat" class="custom-select px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white text-sm min-w-[180px]">
                                    <option value="">Alle Rubriken</option>
                                    <?php foreach($deviceCategories as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="modal-dev-sort" class="custom-select px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg shadow-sm focus:ring-2 focus:ring-primary/20 focus:border-primary dark:bg-slate-700 dark:text-white text-sm min-w-[180px]">
                                    <option value="az">Alphabetisch (A-Z)</option>
                                    <option value="za">Alphabetisch (Z-A)</option>
                                    <option value="newest">Neueste zuerst</option>
                                    <option value="oldest">Älteste zuerst</option>
                                </select>
                            </div>
                        </div>

                        <!-- Modal Device Grid -->
                        <div class="p-6 overflow-y-auto flex-1 bg-slate-50 dark:bg-slate-900/50">
                            <div id="modal-device-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                <!-- Populated by JS -->
                            </div>
                            <div id="modal-no-results" class="hidden py-20 text-center">
                                <div class="text-4xl mb-4">🔍</div>
                                <p class="text-slate-500 font-medium">Keine Geräte gefunden.</p>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-700 bg-white dark:bg-slate-800 flex justify-between items-center">
                            <div class="text-sm text-slate-500">
                                <span id="modal-selected-count">0</span> Geräte ausgewählt
                            </div>
                            <div class="flex gap-3">
                                <button type="button" onclick="closeDeviceModal()" class="px-5 py-2 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-white rounded-lg font-bold hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors">Abbrechen</button>
                                <button type="button" onclick="confirmDeviceSelection()" class="px-5 py-2 bg-primary text-white rounded-lg font-bold hover:bg-blue-600 shadow-lg shadow-primary/20 transition-all">Übernehmen</button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                const allDevices = <?php 
                    // Prepare devices data for JS
                    $jsDevices = [];
                    foreach($devices as $d) {
                        $jsDevices[] = [
                            'id' => (int)$d['id'],
                            'name' => $d['name'],
                            'cat_id' => (int)$d['category_id'],
                            'cat_name' => $d['cat_name'],
                            'location' => $d['location_name'] ?: ($d['location_description'] ?: '-'),
                            'image' => $d['image_path'] ? 'uploads/devices/' . $d['image_path'] : null
                        ];
                    }
                    echo json_encode($jsDevices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); 
                ?>;
                
                let selectedDeviceIds = <?php echo json_encode(array_map('intval', $selectedDevices)); ?>;
                let modalTemporarySelection = [];

                function openDeviceModal() {
                    modalTemporarySelection = [...selectedDeviceIds];
                    document.getElementById('device-modal').classList.remove('hidden');
                    document.getElementById('device-modal').classList.add('flex');
                    document.body.style.overflow = 'hidden';
                    renderModalDevices();
                    updateModalSelectionCount();
                }

                function closeDeviceModal() {
                    document.getElementById('device-modal').classList.add('hidden');
                    document.getElementById('device-modal').classList.remove('flex');
                    document.body.style.overflow = '';
                }

                function updateModalSelectionCount() {
                    document.getElementById('modal-selected-count').innerText = modalTemporarySelection.length;
                }

                function toggleModalDevice(id) {
                    const index = modalTemporarySelection.indexOf(id);
                    if (index === -1) {
                        modalTemporarySelection.push(id);
                    } else {
                        modalTemporarySelection.splice(index, 1);
                    }
                    
                    // Update UI state of the tile
                    const tile = document.querySelector(`.device-tile[data-id="${id}"]`);
                    if (tile) {
                        tile.classList.toggle('ring-2');
                        tile.classList.toggle('ring-primary');
                        tile.classList.toggle('bg-primary/5');
                        const checkbox = tile.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = !checkbox.checked;
                    }
                    
                    updateModalSelectionCount();
                }

                function renderModalDevices() {
                    const grid = document.getElementById('modal-device-grid');
                    const searchText = document.getElementById('modal-dev-search').value.toLowerCase();
                    const catFilter = document.getElementById('modal-dev-cat').value;
                    const sortVal = document.getElementById('modal-dev-sort').value;

                    let filtered = allDevices.filter(d => {
                        const matchSearch = d.name.toLowerCase().includes(searchText);
                        const matchCat = !catFilter || d.cat_id == catFilter;
                        return matchSearch && matchCat;
                    });

                    // Sorting
                    filtered.sort((a, b) => {
                        if (sortVal === 'az') return a.name.localeCompare(b.name);
                        if (sortVal === 'za') return b.name.localeCompare(a.name);
                        if (sortVal === 'newest') return b.id - a.id;
                        if (sortVal === 'oldest') return a.id - b.id;
                        return 0;
                    });

                    grid.innerHTML = '';
                    if (filtered.length === 0) {
                        document.getElementById('modal-no-results').classList.remove('hidden');
                    } else {
                        document.getElementById('modal-no-results').classList.add('hidden');
                        filtered.forEach(d => {
                            const isSelected = modalTemporarySelection.includes(d.id);
                            const div = document.createElement('div');
                            div.className = `device-tile relative group cursor-pointer bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden transition-all hover:shadow-md ${isSelected ? 'ring-2 ring-primary bg-primary/5' : ''}`;
                            div.setAttribute('data-id', d.id);
                            div.onclick = () => toggleModalDevice(d.id);
                            
                            div.innerHTML = `
                                <div class="aspect-square bg-slate-100 dark:bg-slate-700 flex items-center justify-center overflow-hidden border-b border-slate-100 dark:border-slate-700">
                                    ${d.image ? `<img src="${escHtml(d.image)}" class="w-full h-full object-cover">` : `<span class="text-3xl opacity-20">🔌</span>`}
                                </div>
                                <div class="p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="text-xs font-bold text-slate-900 dark:text-white line-clamp-2 leading-tight">${escHtml(d.name)}</div>
                                        <input type="checkbox" ${isSelected ? 'checked' : ''} class="rounded border-slate-300 text-primary mt-0.5" onclick="event.stopPropagation()">
                                    </div>
                                    <div class="text-[10px] text-slate-500 mt-1 truncate">📍 ${escHtml(d.location)}</div>
                                    ${d.cat_name ? `<div class="mt-1"><span class="inline-block px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-[9px] font-bold text-slate-500 uppercase tracking-tighter">${escHtml(d.cat_name)}</span></div>` : ''}
                                </div>
                            `;
                            grid.appendChild(div);
                        });
                    }
                }

                function confirmDeviceSelection() {
                    selectedDeviceIds = [...modalTemporarySelection];
                    updateSidebarList();
                    closeDeviceModal();
                }

                function updateSidebarList() {
                    const list = document.getElementById('selected-devices-list');
                    const hiddenInputs = document.getElementById('hidden-device-inputs');
                    
                    list.innerHTML = '';
                    hiddenInputs.innerHTML = '';
                    
                    if (selectedDeviceIds.length === 0) {
                        list.innerHTML = '<p class="text-xs text-slate-500 italic no-devices-msg">Keine Geräte ausgewählt.</p>';
                    } else {
                        selectedDeviceIds.forEach(id => {
                            const device = allDevices.find(d => d.id === id);
                            if (device) {
                                // Add to visible list
                                const div = document.createElement('div');
                                div.className = 'flex items-center justify-between bg-slate-100 dark:bg-slate-800 p-2 rounded border border-slate-200 dark:border-slate-700 group';
                                div.innerHTML = `
                                    <div class="flex items-center gap-2 overflow-hidden">
                                        ${device.image ? `<img src="${escHtml(device.image)}" class="w-6 h-6 rounded object-cover">` : `<span class="text-xs">🔌</span>`}
                                        <div class="truncate">
                                            <div class="text-xs font-bold dark:text-white truncate">${escHtml(device.name)}</div>
                                            <div class="text-[9px] text-slate-500 truncate">📍 ${escHtml(device.location)}</div>
                                        </div>
                                    </div>
                                    <button type="button" onclick="removeDevice(${id})" class="text-slate-400 hover:text-red-500 p-1 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                `;
                                list.appendChild(div);
                                
                                // Add hidden input
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'devices[]';
                                input.value = id;
                                input.setAttribute('data-device-id', id);
                                hiddenInputs.appendChild(input);
                            }
                        });
                    }
                }

                function removeDevice(id) {
                    selectedDeviceIds = selectedDeviceIds.filter(did => did !== id);
                    updateSidebarList();
                }

                // Initial render of sidebar list
                document.addEventListener('DOMContentLoaded', updateSidebarList);

                // Modal Event Listeners
                document.getElementById('modal-dev-search').addEventListener('input', renderModalDevices);
                document.getElementById('modal-dev-cat').addEventListener('change', renderModalDevices);
                document.getElementById('modal-dev-sort').addEventListener('change', renderModalDevices);
                
                // Close modal on Esc
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') closeDeviceModal();
                });
                </script>
                
                <button type="submit" name="save_experiment" class="w-full py-3 px-4 border border-transparent rounded-lg shadow-md font-bold text-white bg-primary hover:bg-blue-600">Speichern</button>
            </div>
        </div>
    </form>
    <?php
} 
// ==========================================
// VIEW: DETAIL
// ==========================================
elseif ($action === 'view' && $expId > 0) {
    $stmt = $pdo->prepare("SELECT e.*, u.full_name, u.username as creator_username FROM " . DB_PREFIX . "experiments e LEFT JOIN " . DB_PREFIX . "users u ON e.created_by = u.id WHERE e.id = ?");
    $stmt->execute([$expId]);
    $exp = $stmt->fetch();
    if (!$exp) exit("Experiment nicht gefunden.");

    $classes = $pdo->prepare("SELECT c.id, c.name FROM " . DB_PREFIX . "classes c JOIN " . DB_PREFIX . "experiment_class ec ON c.id = ec.class_id WHERE ec.experiment_id = ?");
    $classes->execute([$expId]); $classList = $classes->fetchAll();

    $topics = $pdo->prepare("SELECT t.id, t.name FROM " . DB_PREFIX . "topics t JOIN " . DB_PREFIX . "experiment_topic et ON t.id = et.topic_id WHERE et.experiment_id = ?");
    $topics->execute([$expId]); $topicList = $topics->fetchAll();

    $devices = $pdo->prepare("SELECT d.*, c.name as category_name, l.name as location_name FROM " . DB_PREFIX . "devices d JOIN " . DB_PREFIX . "experiment_device ed ON d.id = ed.device_id LEFT JOIN " . DB_PREFIX . "device_categories c ON d.category_id = c.id LEFT JOIN " . DB_PREFIX . "locations l ON d.location_id = l.id WHERE ed.experiment_id = ?");
    $devices->execute([$expId]); $deviceList = $devices->fetchAll();

    $mediaFiles = $pdo->query("SELECT * FROM " . DB_PREFIX . "media WHERE experiment_id = $expId ORDER BY type, created_at")->fetchAll();
    $photos = array_filter($mediaFiles, fn($m) => $m['type'] === 'photo');
    $videos = array_filter($mediaFiles, fn($m) => $m['type'] === 'video');
    $pdfs = array_filter($mediaFiles, fn($m) => $m['type'] === 'pdf');
    $measurementsMedia = array_filter($mediaFiles, fn($m) => $m['type'] === 'measurement');
    $riskAssessments = array_filter($mediaFiles, fn($m) => $m['type'] === 'risk_assessment');
    
    ?>
    <style>
    .lb-overlay { display:none; position:fixed; z-index:9999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(5px); }
    .lb-content { position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); max-width:90vw; max-height:90vh; }
    .lb-img { border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.5); max-height:85vh; max-width:85vw; object-fit:contain; }
    .lb-btn { position:absolute; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.1); color:#fff; border:none; padding:15px; cursor:pointer; font-size:24px; border-radius:50%; width:60px; height:60px; display:flex; align-items:center; justify-content:center; transition:background 0.2s;}
    .lb-btn:hover { background:rgba(255,255,255,0.3); }
    .lb-prev { left:20px; } .lb-next { right:20px; }
    .lb-close { position:absolute; top:20px; right:30px; font-size:40px; color:#fff; cursor:pointer; font-weight:100; transition:color 0.2s; }
    .lb-close:hover { color:#ccc; }
    </style>
    
    <div id="lb-modal" class="lb-overlay">
        <span class="lb-close" onclick="closeLb()">&times;</span>
        <button class="lb-btn lb-prev" onclick="changeLb(-1)">&#10094;</button>
        <div class="lb-content"><img id="lb-image" class="lb-img" src=""></div>
        <button class="lb-btn lb-next" onclick="changeLb(1)">&#10095;</button>
    </div>

    <script>
    const lbImages = [<?php foreach($photos as $p) echo "'" . 'uploads/photos/'.htmlspecialchars($p['file_path']) . "',"; ?>];
    let lbCurrent = 0;
    function openLb(index) { if(!lbImages.length) return; lbCurrent = index; document.getElementById('lb-image').src = lbImages[lbCurrent]; document.getElementById('lb-modal').style.display = 'block'; }
    function closeLb() { document.getElementById('lb-modal').style.display = 'none'; }
    function changeLb(dir) { lbCurrent += dir; if(lbCurrent >= lbImages.length) lbCurrent = 0; if(lbCurrent < 0) lbCurrent = lbImages.length-1; document.getElementById('lb-image').src = lbImages[lbCurrent]; }
    document.addEventListener('keydown', e => { if(document.getElementById('lb-modal').style.display==='block'){ if(e.key==='Escape') closeLb(); if(e.key==='ArrowRight') changeLb(1); if(e.key==='ArrowLeft') changeLb(-1); } });
    </script>
    
    <div class="mb-4 flex justify-between items-center">
        <a href="?page=experiments" class="text-primary hover:underline">&larr; Zurück zur Übersicht</a>
        <?php if ($canEdit): ?>
            <div class="flex gap-3">
                <a href="?page=experiments&action=edit&id=<?php echo $exp['id']; ?>" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 px-4 py-2 rounded-md text-sm font-medium">Bearbeiten</a>
                <form method="POST" class="inline" onsubmit="return confirm('Wirklich löschen?');">
                    <input type="hidden" name="delete_id" value="<?php echo $exp['id']; ?>">
                    <button type="submit" name="delete_experiment" class="bg-red-100 text-red-700 hover:bg-red-200 px-4 py-2 rounded-md text-sm font-medium">Löschen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
                <div class="flex gap-2 flex-wrap mb-4">
                    <?php foreach($classList as $c): ?>
                        <a href="?page=experiments&class_id=<?php echo $c['id']; ?>" class="px-3 py-1 bg-blue-100 hover:bg-blue-200 text-blue-800 dark:bg-blue-900/50 dark:hover:bg-blue-900 dark:text-blue-300 rounded-full text-xs font-semibold cursor-pointer"><?php echo htmlspecialchars($c['name']); ?></a>
                    <?php endforeach; ?>
                    <?php foreach($topicList as $t): ?>
                        <a href="?page=experiments&topic_id=<?php echo $t['id']; ?>" class="px-3 py-1 bg-indigo-100 hover:bg-indigo-200 text-indigo-800 dark:bg-indigo-900/50 dark:hover:bg-indigo-900 dark:text-indigo-300 rounded-full text-xs font-semibold cursor-pointer"><?php echo htmlspecialchars($t['name']); ?></a>
                    <?php endforeach; ?>
                </div>
                
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white mb-2"><?php echo htmlspecialchars($exp['title']); ?></h1>
                
                <div class="flex items-center text-sm text-slate-500 font-medium mb-6">
                    <span class="mr-3">📅 Zuletzt geändert: <?php echo date('d.m.Y H:i', strtotime($exp['updated_at'])); ?></span>
                    <?php if(isset($exp['creator_username'])): ?>
                    <span>👤 Angelegt von: <?php echo htmlspecialchars($exp['full_name'] ?: $exp['creator_username']); ?></span>
                    <?php endif; ?>
                </div>

                <?php if($exp['description']): ?><div class="text-slate-700 dark:text-slate-300 text-lg mb-6 leading-relaxed whitespace-pre-wrap"><?php echo htmlspecialchars($exp['description']); ?></div><?php endif; ?>
                
                <?php if(!empty($riskAssessments)): ?>
                    <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg border-2 border-red-200 dark:border-red-900/50 mb-6 group">
                        <h4 class="font-bold text-red-800 dark:text-red-500 mb-3 flex items-center gap-2">
                            <span class="animate-pulse">⚠️</span> GEFÄHRDUNGSBEURTEILUNG
                        </h4>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach($riskAssessments as $ra): ?>
                                <a href="uploads/risk_assessments/<?php echo htmlspecialchars($ra['file_path']); ?>" target="_blank" class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-red-900/50 border border-red-200 dark:border-red-800 rounded-lg hover:shadow-md transition-all group-hover:scale-[1.02]">
                                    <span class="text-2xl"><?php echo preg_match('/\.(jpg|jpeg|png|webp)$/i', $ra['file_path']) ? '🖼️' : '📄'; ?></span>
                                    <div>
                                        <div class="text-sm font-bold text-red-900 dark:text-red-200"><?php echo htmlspecialchars(getHumanFilename($ra['file_path'])); ?></div>
                                        <div class="text-[10px] text-red-600 dark:text-red-400">Klicken zum Öffnen</div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if($exp['setup_instructions']): ?>
                    <h3 class="text-xl font-semibold dark:text-white mt-8 mb-3">Aufbaubeschreibung</h3>
                    <div class="trix-content prose dark:prose-invert max-w-none text-slate-700 dark:text-slate-300 bg-slate-50 dark:bg-slate-900 p-4 rounded-lg border border-slate-100 dark:border-slate-800">
                        <?php echo sanitizeRichText($exp['setup_instructions']); ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 gap-6 mt-8">
                    <?php if($exp['tips']): ?>
                    <div class="bg-amber-50 dark:bg-amber-900/20 p-5 rounded-lg border border-amber-200 dark:border-amber-900/50">
                        <h4 class="font-bold text-amber-800 dark:text-amber-500 mb-2 flex items-center">💡 Tipps & Hinweise</h4>
                        <div class="trix-content text-amber-900 dark:text-amber-200 text-sm"><?php echo sanitizeRichText($exp['tips']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($exp['measurements'] || !empty($measurementsMedia)): ?>
                    <div class="bg-emerald-50 dark:bg-emerald-900/20 p-5 rounded-lg border border-emerald-200 dark:border-emerald-900/50">
                        <h4 class="font-bold text-emerald-800 dark:text-emerald-500 mb-2 flex items-center">📊 Messergebnisse & Dateien</h4>
                        <?php if($exp['measurements']): ?><div class="text-emerald-900 dark:text-emerald-200 text-sm mb-4 whitespace-pre-wrap"><?php echo htmlspecialchars($exp['measurements']); ?></div><?php endif; ?>
                        
                        <?php if(!empty($measurementsMedia)): ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                                <?php foreach($measurementsMedia as $m): 
                                    $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $m['file_path']);
                                ?>
                                    <a href="uploads/measurements/<?php echo htmlspecialchars($m['file_path']); ?>" target="_blank" class="flex items-center p-2 rounded bg-white hover:bg-emerald-100 dark:bg-slate-800 dark:border-emerald-700 transition">
                                        <span class="mr-2 text-xl"><?php echo $isImg ? '🖼️' : '📄'; ?></span>
                                        <span class="text-xs font-semibold text-emerald-800 dark:text-emerald-300 truncate" title="<?php echo htmlspecialchars(getHumanFilename($m['file_path'])); ?>"><?php echo htmlspecialchars(getHumanFilename($m['file_path'])); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(!empty($photos)): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
                <h3 class="text-xl font-semibold dark:text-white mb-4">Fotos vom Aufbau</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <?php $idx=0; foreach($photos as $p): ?>
                        <div onclick="openLb(<?php echo $idx++; ?>)" class="cursor-pointer block aspect-video bg-slate-100 dark:bg-slate-700 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-600 hover:opacity-80 transition relative group">
                            <img src="uploads/photos/<?php echo htmlspecialchars($p['file_path']); ?>" class="w-full h-full object-cover">
                            <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition"><div class="bg-black/50 text-white rounded-full p-2 backdrop-blur-sm">🔍</div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($videos)): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-6 shadow-sm border border-slate-200 dark:border-slate-700 mt-6">
                <h3 class="text-xl font-semibold dark:text-white mb-4">Videos</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach($videos as $v): ?>
                        <div class="bg-slate-50 dark:bg-slate-900 rounded-lg overflow-hidden border border-slate-200 dark:border-slate-700">
                            <video controls class="w-full h-auto aspect-video">
                                <source src="uploads/videos/<?php echo htmlspecialchars($v['file_path']); ?>" type="video/<?php echo htmlspecialchars(pathinfo($v['file_path'], PATHINFO_EXTENSION)); ?>">
                                Dein Browser unterstützt keine Videos.
                            </video>
                            <div class="p-2 text-xs font-semibold text-slate-600 dark:text-slate-400 text-center truncate">
                                <?php echo htmlspecialchars(getHumanFilename($v['file_path'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
                <div class="bg-slate-50 dark:bg-slate-700/50 px-5 py-3 border-b rounded-t-xl"><h3 class="font-bold text-sm uppercase">Benötigte Geräte</h3></div>
                <div class="p-5">
                    <?php if(empty($deviceList)): ?><p class="text-sm text-slate-500">Keine Geräte hinterlegt.</p><?php else: ?>
                        <ul class="space-y-4">
                        <?php foreach($deviceList as $d): ?>
                            <li class="flex items-start">
                                <?php if(isset($d['image_path']) && $d['image_path']): ?>
                                    <div class="relative group mr-3 z-10 shrink-0 cursor-pointer">
                                        <div class="w-10 h-10 rounded shadow object-cover bg-slate-100 dark:bg-slate-700 overflow-hidden border border-slate-200 dark:border-slate-600">
                                            <img src="uploads/devices/<?php echo htmlspecialchars($d['image_path']); ?>" class="w-full h-full object-cover">
                                        </div>
                                        <div class="absolute left-full top-0 ml-2 hidden group-hover:block shadow-2xl rounded-lg overflow-hidden border border-slate-200 dark:border-slate-600 z-[99] bg-white w-64">
                                            <img src="uploads/devices/<?php echo htmlspecialchars($d['image_path']); ?>" class="w-full h-auto block">
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="bg-primary/10 text-primary rounded-full p-2 mr-3 shrink-0">🔌</span>
                                <?php endif; ?>
                                <div>
                                    <span class="block font-semibold dark:text-white"><?php echo htmlspecialchars($d['name']); ?></span>
                                    <span class="block text-sm text-slate-500">
                                        📍 <?php echo htmlspecialchars($d['location_name'] ?: ($d['location_description'] ?: '-')); ?>
                                        <?php if(isset($d['category_name']) && $d['category_name']) echo " • <span class='text-[10px] text-slate-400 font-medium uppercase'>🏷️ " . htmlspecialchars($d['category_name']) . "</span>"; ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(!empty($pdfs)): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b"><h3 class="font-bold text-sm uppercase">PDFs</h3></div>
                <div class="p-5"><ul class="space-y-3">
                    <?php foreach($pdfs as $p): ?>
                        <li><a href="uploads/pdfs/<?php echo htmlspecialchars($p['file_path']); ?>" target="_blank" class="flex p-3 rounded-lg border hover:bg-slate-50 dark:border-slate-600 dark:hover:bg-slate-700/50 transition"><span class="text-red-500 mr-2">📄</span><span class="text-sm font-medium text-slate-800 dark:text-slate-200 truncate"><?php echo htmlspecialchars(getHumanFilename($p['file_path'])); ?></span></a></li>
                    <?php endforeach; ?>
                </ul></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
} 
// ==========================================
// VIEW: LIST
// ==========================================
else {
    $search = $_GET['search'] ?? '';
    $classFilter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $topicFilter = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
    $sort = $_GET['sort'] ?? 'newest';
    
    $where = []; $params = [];
    if($search) { $where[] = "(e.title LIKE ? OR e.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if($classFilter) { $where[] = "e.id IN (SELECT experiment_id FROM " . DB_PREFIX . "experiment_class WHERE class_id = ?)"; $params[] = $classFilter; }
    if($topicFilter) { $where[] = "e.id IN (SELECT experiment_id FROM " . DB_PREFIX . "experiment_topic WHERE topic_id = ?)"; $params[] = $topicFilter; }
    
    $whereSql = count($where) ? "WHERE " . implode(' AND ', $where) : "";
    
    $orderBy = "e.updated_at DESC";
    if ($sort === 'az') $orderBy = "e.title ASC";
    elseif ($sort === 'za') $orderBy = "e.title DESC";
    elseif ($sort === 'oldest') $orderBy = "e.id ASC";
    elseif ($sort === 'newest') $orderBy = "e.id DESC";
    
    $stmt = $pdo->prepare("SELECT e.* FROM " . DB_PREFIX . "experiments e $whereSql ORDER BY $orderBy");
    $stmt->execute($params);
    $experiments = $stmt->fetchAll();

    $allClasses = $pdo->query("SELECT * FROM " . DB_PREFIX . "classes ORDER BY name")->fetchAll();
    $allTopics = $pdo->query("SELECT * FROM " . DB_PREFIX . "topics ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, name ASC")->fetchAll();
    ?>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Experimente Übersicht</h2>
        <?php if ($canEdit): ?><a href="?page=experiments&action=add" class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-md font-medium text-sm">+ Neu</a><?php endif; ?>
    </div>

    <div class="mb-6 bg-white dark:bg-slate-800 p-4 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700">
        <form method="GET" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="page" value="experiments">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Titel suchen..." class="flex-1 px-3 py-2 border rounded-md dark:bg-slate-700 dark:text-white dark:border-slate-600">
            
            <select name="class_id" class="custom-select bg-white px-3 py-2 border rounded-md dark:bg-slate-700 dark:text-white dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary/50" onchange="this.form.submit()">
                <option value="">Alle Klassen</option>
                <?php foreach($allClasses as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $c['id'] == $classFilter ? 'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <select name="topic_id" class="custom-select bg-white px-3 py-2 border rounded-md dark:bg-slate-700 dark:text-white dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary/50" onchange="this.form.submit()">
                <option value="">Alle Themen</option>
                <?php foreach($allTopics as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo $t['id'] == $topicFilter ? 'selected':''; ?>>
                        <?php echo $t['parent_id'] ? '&nbsp;&nbsp;&nbsp;↳ ' : ''; ?><?php echo htmlspecialchars($t['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="sort" class="custom-select bg-white px-3 py-2 border rounded-md dark:bg-slate-700 dark:text-white dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary/50" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort == 'newest' ? 'selected':''; ?>>Neueste zuerst</option>
                <option value="oldest" <?php echo $sort == 'oldest' ? 'selected':''; ?>>Älteste zuerst</option>
                <option value="az" <?php echo $sort == 'az' ? 'selected':''; ?>>Alphabetisch (A-Z)</option>
                <option value="za" <?php echo $sort == 'za' ? 'selected':''; ?>>Alphabetisch (Z-A)</option>
            </select>
            
            <button type="submit" class="bg-slate-200 hover:bg-slate-300 dark:bg-slate-600 dark:text-white px-4 py-2 rounded-md">Filtern</button>
            <?php if($search || $classFilter || $topicFilter): ?><a href="?page=experiments" class="flex items-center text-slate-500 hover:underline mx-2 text-sm">Zurücksetzen</a><?php endif; ?>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach($experiments as $exp): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow border border-slate-200 dark:border-slate-700 p-6 flex flex-col hover:shadow-lg transition">
                <h3 class="text-xl font-bold dark:text-white mb-2"><?php echo htmlspecialchars($exp['title']); ?></h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4 flex-1"><?php echo htmlspecialchars(substr(strip_tags($exp['description']), 0, 120)).(strlen(strip_tags($exp['description']))>120?'...':''); ?></p>
                <div class="flex justify-between items-center mt-4 pt-4 border-t dark:border-slate-700">
                    <div class="flex flex-col text-xs text-slate-400">
                        <span>Geändert: <?php echo date('d.m.Y', strtotime($exp['updated_at'])); ?></span>
                        <?php if(isset($exp['creator_username'])): ?>
                            <span class="mt-0.5">👤 <?php echo htmlspecialchars($exp['full_name'] ?: $exp['creator_username']); ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="?page=experiments&action=view&id=<?php echo $exp['id']; ?>" class="text-primary font-semibold text-sm">Ansehen &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if(empty($experiments)): ?><div class="col-span-full p-8 text-center text-slate-500">Keine Experimente gefunden.</div><?php endif; ?>
    </div>
<?php } ?>
