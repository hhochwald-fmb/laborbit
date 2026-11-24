<?php
if (!defined('DB_HOST')) exit;

/*
 * Upload-Sicherheit: Dateityp und Endung werden NIE aus den Angaben des Browsers
 * übernommen ($_FILES['type'] und der Dateiname sind frei fälschbar). Die Endung wird
 * aus dem tatsächlichen Dateiinhalt bestimmt bzw. gegen eine Whitelist geprüft.
 */

/** Dateiname ohne Endung, nur sichere Zeichen, OHNE Punkte (verhindert "x.php.csv"). */
function safeUploadBaseName(string $originalName): string {
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $base);
    $clean = trim(substr($clean, 0, 50), '_');
    return $clean !== '' ? $clean : 'datei';
}

/** Liest die ersten Bytes einer Datei (für die Erkennung des echten Dateityps). */
function readFileHead(string $path, int $bytes = 16): string {
    $fh = @fopen($path, 'rb');
    if (!$fh) return '';
    $head = (string) fread($fh, $bytes);
    fclose($fh);
    return $head;
}

function isUploadedOk($fileInfo): bool {
    return is_array($fileInfo) && ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && !empty($fileInfo['tmp_name']) && is_uploaded_file($fileInfo['tmp_name']);
}

/**
 * Handle and resize image uploads (JPEG, PNG, WebP). Das Bild wird neu kodiert,
 * die Endung ergibt sich aus dem erkannten Bildtyp.
 */
function handleImageUpload($fileInfo, $targetDir, $maxWidth = 1920, $maxHeight = 1080) {
    if (!isUploadedOk($fileInfo)) return false;

    $info = @getimagesize($fileInfo['tmp_name']);
    if (!$info) return false;
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $type = $info[2];
    if (!isset($types[$type])) return false;
    [$origWidth, $origHeight] = $info;
    if (!$origWidth || !$origHeight || $origWidth * $origHeight > 50000000) return false;

    $filename = 'img_' . str_replace('.', '', uniqid('', true)) . '.' . $types[$type];
    $targetFile = $targetDir . '/' . $filename;

    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1);
    $newWidth = max(1, (int)($origWidth * $ratio));
    $newHeight = max(1, (int)($origHeight * $ratio));

    $srcImg = null;
    if ($type === IMAGETYPE_JPEG) $srcImg = @imagecreatefromjpeg($fileInfo['tmp_name']);
    elseif ($type === IMAGETYPE_PNG) $srcImg = @imagecreatefrompng($fileInfo['tmp_name']);
    elseif ($type === IMAGETYPE_WEBP) $srcImg = @imagecreatefromwebp($fileInfo['tmp_name']);

    if (!$srcImg) return false;

    $dstImg = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG/WebP
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
        imagefilledrectangle($dstImg, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

    $success = false;
    if ($type === IMAGETYPE_JPEG) $success = imagejpeg($dstImg, $targetFile, 85);
    elseif ($type === IMAGETYPE_PNG) $success = imagepng($dstImg, $targetFile, 8);
    elseif ($type === IMAGETYPE_WEBP) $success = imagewebp($dstImg, $targetFile, 85);

    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $success ? $filename : false;
}

/** Speichert eine geprüfte Upload-Datei unter einem sicheren Namen mit fester Endung. */
function storeUpload(array $fileInfo, string $targetDir, string $prefix, string $ext) {
    $filename = $prefix . '_' . str_replace('.', '', uniqid('', true)) . '_' . safeUploadBaseName($fileInfo['name']) . '.' . $ext;
    if (move_uploaded_file($fileInfo['tmp_name'], $targetDir . '/' . $filename)) {
        @chmod($targetDir . '/' . $filename, 0644);
        return $filename;
    }
    return false;
}

/**
 * Handle PDF uploads – nur echte PDFs (Datei beginnt mit "%PDF-"), Endung immer .pdf
 */
function handlePdfUpload($fileInfo, $targetDir) {
    if (!isUploadedOk($fileInfo)) return false;
    if (strpos(readFileHead($fileInfo['tmp_name'], 1024), '%PDF-') === false) return false;
    return storeUpload($fileInfo, $targetDir, 'pdf', 'pdf');
}

/**
 * Handle measurement uploads: CSV/TXT (reiner Text) oder Bilder (werden neu kodiert).
 */
function handleMeasurementUpload($fileInfo, $targetDir) {
    if (!isUploadedOk($fileInfo)) return false;

    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return handleImageUpload($fileInfo, $targetDir, 2400, 2400);
    }
    if (!in_array($ext, ['csv', 'txt'], true)) return false;

    // Textdateien: keine Binärdaten, kein eingebettetes HTML/PHP.
    $head = readFileHead($fileInfo['tmp_name'], 65536);
    if (strpos($head, "\0") !== false || preg_match('/<\?php|<script|<html|<svg/i', $head)) return false;

    return storeUpload($fileInfo, $targetDir, 'meas', $ext);
}

/**
 * Handle video uploads – MP4/MOV (ISO-Container mit "ftyp") oder WebM (EBML-Header).
 */
function handleVideoUpload($fileInfo, $targetDir) {
    if (!isUploadedOk($fileInfo)) return false;

    $head = readFileHead($fileInfo['tmp_name'], 16);
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    if (substr($head, 4, 4) === 'ftyp') {
        $ext = $ext === 'mov' ? 'mov' : 'mp4';
    } elseif (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
        $ext = 'webm';
    } else {
        return false;
    }
    return storeUpload($fileInfo, $targetDir, 'vid', $ext);
}

/**
 * Returns human-readable filename by stripping the internal uniqid prefix
 */
function getHumanFilename($filepath) {
    // Matches pdf_12345_OriginalName.pdf or meas_... or vid_...
    if (preg_match('/^(?:pdf|meas|vid)_[a-f0-9]+_(.+)$/i', $filepath, $matches)) {
        return $matches[1];
    }
    return $filepath; // Fallback for images and old uploads
}

/**
 * CSRF-Schutz: Token pro Session, als verstecktes Feld in jedes POST-Formular.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfValid(): bool {
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? null) === 'manager';
}

/** Bricht mit 403 ab, wenn kein Manager (Admin) eingeloggt ist. */
function requireAdmin(): void {
    if (!isAdmin()) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        exit('Zugriff verweigert. Nur für Manager.');
    }
}

/** Flash-Meldung für den nächsten Seitenaufruf (nach Redirect). */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'][] = [$type, $message];
}

function takeFlashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Leitet um, auch wenn das Layout schon in den Ausgabepuffer geschrieben wurde. */
function redirectTo(string $url): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . $url);
    exit;
}

/**
 * Filtert HTML aus dem Trix-Editor auf eine sichere Whitelist (gegen gespeichertes XSS).
 * Erlaubt nur die Formatierungen, die Trix erzeugt; alle Attribute außer einem geprüften
 * href (http/https/mailto) werden entfernt.
 */
function sanitizeRichText(?string $html): string {
    $html = (string) $html;
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(strip_tags($html)));
    }

    $allowed = ['div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'strike', 'a',
                'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code', 'span'];
    $dropWithContent = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template',
                        'noscript', 'textarea', 'select', 'button', 'form', 'input', 'link', 'meta', 'base', 'title', 'head'];

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) return '';

    $clean = function (DOMNode $node) use (&$clean, $allowed, $dropWithContent) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) continue;
            if (!$child instanceof DOMElement) { $node->removeChild($child); continue; }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, $dropWithContent, true)) { $node->removeChild($child); continue; }

            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                // Unbekanntes Element: Inhalt behalten, Element selbst entfernen.
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) {
                $child->removeAttribute($attr->nodeName);
            }
            if ($tag === 'a') {
                if (preg_match('#^(https?://|mailto:)#i', $href)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
    };
    $clean($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}
