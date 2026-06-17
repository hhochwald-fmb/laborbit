<?php
if (!defined('DB_HOST')) exit;

/**
 * Handle and resize image uploads
 */
function handleImageUpload($fileInfo, $targetDir, $maxWidth = 1920, $maxHeight = 1080) {
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($fileInfo['type'], $allowedTypes)) return false;

    // Generate unique name
    $ext = pathinfo($fileInfo['name'], PATHINFO_EXTENSION);
    $filename = uniqid('img_') . '.' . $ext;
    $targetFile = $targetDir . '/' . $filename;

    // Resize logic
    list($origWidth, $origHeight) = getimagesize($fileInfo['tmp_name']);
    if (!$origWidth || !$origHeight) return false;

    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight, 1);
    $newWidth = (int)($origWidth * $ratio);
    $newHeight = (int)($origHeight * $ratio);

    $srcImg = null;
    if ($fileInfo['type'] === 'image/jpeg') $srcImg = imagecreatefromjpeg($fileInfo['tmp_name']);
    elseif ($fileInfo['type'] === 'image/png') $srcImg = imagecreatefrompng($fileInfo['tmp_name']);
    elseif ($fileInfo['type'] === 'image/webp') $srcImg = imagecreatefromwebp($fileInfo['tmp_name']);

    if (!$srcImg) return false;

    $dstImg = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG/WebP
    if ($fileInfo['type'] === 'image/png' || $fileInfo['type'] === 'image/webp') {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
        $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
        imagefilledrectangle($dstImg, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

    $success = false;
    if ($fileInfo['type'] === 'image/jpeg') $success = imagejpeg($dstImg, $targetFile, 85);
    elseif ($fileInfo['type'] === 'image/png') $success = imagepng($dstImg, $targetFile, 8);
    elseif ($fileInfo['type'] === 'image/webp') $success = imagewebp($dstImg, $targetFile, 85);

    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $success ? $filename : false;
}

/**
 * Handle PDF uploads
 */
function handlePdfUpload($fileInfo, $targetDir) {
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) return false;
    if ($fileInfo['type'] !== 'application/pdf') return false;

    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $baseName = pathinfo($fileInfo['name'], PATHINFO_FILENAME);
    $cleanName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $baseName);
    // Keep it short if it's too long
    if (strlen($cleanName) > 50) $cleanName = substr($cleanName, 0, 50);
    $filename = uniqid('pdf_') . '_' . $cleanName . '.' . $ext;
    $targetFile = $targetDir . '/' . $filename;

    if (move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
        return $filename;
    }
    return false;
}

/**
 * Handle CSV or other measurement file uploads
 */
function handleMeasurementUpload($fileInfo, $targetDir) {
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'text/plain', 'image/jpeg', 'image/png'];
    // Allow basic types for measurements
    
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $baseName = pathinfo($fileInfo['name'], PATHINFO_FILENAME);
    $cleanName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $baseName);
    if (strlen($cleanName) > 50) $cleanName = substr($cleanName, 0, 50);
    $filename = uniqid('meas_') . '_' . $cleanName . '.' . $ext;
    $targetFile = $targetDir . '/' . $filename;

    if (move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
        return $filename;
    }
    return false;
}

/**
 * Handle video file uploads
 */
function handleVideoUpload($fileInfo, $targetDir) {
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) return false;
    
    $allowedTypes = ['video/mp4', 'video/webm', 'video/quicktime'];
    if (!in_array($fileInfo['type'], $allowedTypes)) return false;
    
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $baseName = pathinfo($fileInfo['name'], PATHINFO_FILENAME);
    $cleanName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', $baseName);
    if (strlen($cleanName) > 50) $cleanName = substr($cleanName, 0, 50);
    $filename = uniqid('vid_') . '_' . $cleanName . '.' . $ext;
    $targetFile = $targetDir . '/' . $filename;

    if (move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
        return $filename;
    }
    return false;
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
?>
