<?php
// pages/profile.php

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordRepeat = $_POST['new_password_repeat'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Update user data
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET full_name = ? WHERE id = ?");
        $stmt->execute([$fullName, $userId]);
        
        // Update session
        $_SESSION['full_name'] = $fullName;
        
        // Update password if provided
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) {
                $error = "Das neue Passwort muss mindestens 6 Zeichen lang sein.";
            } elseif ($newPassword !== $newPasswordRepeat) {
                $error = "Die neuen Passwörter stimmen nicht überein.";
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $userId]);
            }
        }
        
        if (empty($error)) {
            $pdo->commit();
            $message = "Dein Profil wurde erfolgreich aktualisiert.";
        } else {
            $pdo->rollBack();
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "Fehler beim Speichern der Daten.";
    }
}

$stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
?>

<div class="max-w-2xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-slate-800 dark:text-white">Mein Profil</h2>
    </div>

    <?php if ($message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white dark:bg-slate-800 shadow rounded-lg overflow-hidden">
        <div class="p-6">
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Benutzername</label>
                    <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 cursor-not-allowed">
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Der Benutzername kann nicht geändert werden.</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Anzeigename (Klarname)</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" placeholder="z. B. Max Mustermann" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                </div>

                <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                    <h3 class="text-lg font-medium text-slate-900 dark:text-white mb-4">Sicherheit</h3>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Neues Passwort</label>
                        <input type="password" name="new_password" placeholder="Leer lassen, um es nicht zu ändern" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mindestens 6 Zeichen. Wenn du das Passwort behalten willst, lass dieses Feld leer.</p>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Neues Passwort bestätigen</label>
                        <input type="password" name="new_password_repeat" placeholder="Nochmals eingeben" class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-md focus:ring-primary focus:border-primary dark:bg-slate-700 dark:text-white">
                    </div>
                </div>

                <div class="pt-6">
                    <button type="submit" class="w-full sm:w-auto px-6 py-3 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary">
                        Profil speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
