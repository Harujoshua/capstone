<?php
/**
 * get_photo.php — Serves a resized student photo for the ESP32 TFT display.
 *
 * Usage:  GET /neust_gatepass/get_photo.php?file=<filename>
 * Output: Raw JPEG binary, 160×160 px, quality 60.
 *         Returns HTTP 404 if the file doesn't exist or is invalid.
 *
 * The ESP32 downloads this image directly into a buffer, then renders it
 * on the ILI9341 240×320 TFT using the TJpg_Decoder library.
 */

define('STUDENT_UPLOAD_DIR', __DIR__ . '/uploads/students/');
define('FACULTY_UPLOAD_DIR', __DIR__ . '/uploads/faculty/');
define('PHOTO_W', 160);
define('PHOTO_H', 160);
define('JPEG_QUALITY', 60);

// ── 1. Validate input ─────────────────────────────────────────────────────────
$file = isset($_GET['file']) ? basename(trim($_GET['file'])) : '';

if ($file === '') {
    http_response_code(400);
    exit('Missing file parameter');
}

// Only allow alphanumeric, dots, underscores, and hyphens (no path traversal)
if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $file)) {
    http_response_code(400);
    exit('Invalid filename');
}

// Only allow image extensions
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
    http_response_code(400);
    exit('Unsupported file type');
}

// Check students folder first, then faculty folder
$filePath = STUDENT_UPLOAD_DIR . $file;
if (!file_exists($filePath)) {
    $filePath = FACULTY_UPLOAD_DIR . $file;
}

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Not found');
}

// ── 2. Load original image ────────────────────────────────────────────────────
$src = null;
switch ($ext) {
    case 'png':  $src = @imagecreatefrompng($filePath);  break;
    case 'jpg':
    case 'jpeg': $src = @imagecreatefromjpeg($filePath); break;
    case 'webp': $src = @imagecreatefromwebp($filePath); break;
}

if (!$src) {
    http_response_code(500);
    exit('Failed to load image');
}

// ── 3. Resize to 160×160 (square crop from center) ───────────────────────────
$origW = imagesx($src);
$origH = imagesy($src);

// Determine crop box (largest centered square)
if ($origW < $origH) {
    $cropSize = $origW;
    $cropX    = 0;
    $cropY    = (int)(($origH - $cropSize) / 2);
} else {
    $cropSize = $origH;
    $cropX    = (int)(($origW - $cropSize) / 2);
    $cropY    = 0;
}

$dst = imagecreatetruecolor(PHOTO_W, PHOTO_H);

// Fill background white (handles PNG transparency)
$white = imagecolorallocate($dst, 255, 255, 255);
imagefill($dst, 0, 0, $white);

imagecopyresampled(
    $dst, $src,
    0, 0,            // dst x, y
    $cropX, $cropY,  // src crop start
    PHOTO_W, PHOTO_H,
    $cropSize, $cropSize
);

imagedestroy($src);

// ── 4. Output as raw JPEG ─────────────────────────────────────────────────────
header('Content-Type: image/jpeg');
header('Cache-Control: max-age=86400, public'); // Cache for 1 day
imagejpeg($dst, null, JPEG_QUALITY);
imagedestroy($dst);
