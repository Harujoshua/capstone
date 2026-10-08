<?php
/**
 * export_sd_package.php
 * Generates an offline package (.zip) for the ESP32 TFT SD Card.
 * Contains:
 *   - students.csv (UID,Name,Course,Status,PhotoFile)
 *   - photos/ (Pre-cropped & resized 160x160 JPEGs for ultra-fast ESP32 decoding)
 *   - README.txt
 */

require_once 'auth.php';

// Set limits for batch image processing
ini_set('memory_limit', '256M');
set_time_limit(180);

$conn = new mysqli("localhost", "root", "", "neust_gatepass_v3");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Ensure temp directory exists
$tmpZip = tempnam(sys_get_temp_dir(), 'sd_pkg_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Cannot create temporary zip archive");
}

$uploadDir = __DIR__ . '/../uploads/students/';
$photoW = 160;
$photoH = 160;
$jpegQuality = 65;

// Fetch all students who have an RFID UID assigned
$query = "SELECT id, name, rfid_uid, course, status, photo FROM students WHERE rfid_uid IS NOT NULL AND TRIM(rfid_uid) != '' ORDER BY name ASC";
$result = $conn->query($query);

$csvLines = [];
$csvLines[] = "UID,Name,Course,Status,Photo";

$zip->addEmptyDir('photos');

$processedPhotos = [];

while ($row = $result->fetch_assoc()) {
    $uid = strtoupper(trim($row['rfid_uid']));
    $name = trim($row['name']);
    $course = trim($row['course'] ?? '');
    $status = trim($row['status'] ?? 'ACTIVE');
    $photoRaw = trim($row['photo'] ?? '');

    $targetPhotoName = '';

    if (!empty($photoRaw)) {
        $sourceFile = $uploadDir . $photoRaw;
        if (file_exists($sourceFile)) {
            // Generate standard JPEG name for the SD card
            $ext = strtolower(pathinfo($photoRaw, PATHINFO_EXTENSION));
            $baseName = pathinfo($photoRaw, PATHINFO_FILENAME);
            $targetPhotoName = $baseName . '.jpg';

            // Avoid processing same photo twice if shared
            if (!isset($processedPhotos[$targetPhotoName])) {
                $src = null;
                switch ($ext) {
                    case 'png':  $src = @imagecreatefrompng($sourceFile); break;
                    case 'jpg':
                    case 'jpeg': $src = @imagecreatefromjpeg($sourceFile); break;
                    case 'webp': $src = @imagecreatefromwebp($sourceFile); break;
                }

                if ($src) {
                    $origW = imagesx($src);
                    $origH = imagesy($src);

                    // Square crop from center
                    if ($origW < $origH) {
                        $cropSize = $origW;
                        $cropX = 0;
                        $cropY = (int)(($origH - $cropSize) / 2);
                    } else {
                        $cropSize = $origH;
                        $cropX = (int)(($origW - $cropSize) / 2);
                        $cropY = 0;
                    }

                    $dst = imagecreatetruecolor($photoW, $photoH);
                    $white = imagecolorallocate($dst, 255, 255, 255);
                    imagefill($dst, 0, 0, $white);

                    imagecopyresampled(
                        $dst, $src,
                        0, 0,
                        $cropX, $cropY,
                        $photoW, $photoH,
                        $cropSize, $cropSize
                    );

                    // Capture JPEG to string buffer
                    ob_start();
                    imagejpeg($dst, null, $jpegQuality);
                    $jpgData = ob_get_clean();

                    imagedestroy($src);
                    imagedestroy($dst);

                    if (!empty($jpgData)) {
                        $zip->addFromString('photos/' . $targetPhotoName, $jpgData);
                        $processedPhotos[$targetPhotoName] = true;
                    }
                }
            }
        }
    }

    // Sanitize CSV fields (strip internal newlines/commas or enclose in quotes)
    $cleanName = str_replace('"', '""', $name);
    $cleanCourse = str_replace('"', '""', $course);

    $csvLines[] = sprintf(
        '%s,"%s","%s",%s,%s',
        $uid,
        $cleanName,
        $cleanCourse,
        $status,
        $targetPhotoName
    );
}

// Fetch all faculty who have an RFID UID assigned
$fac_query = "SELECT id, name, rfid_uid, department, status, photo FROM faculty WHERE rfid_uid IS NOT NULL AND TRIM(rfid_uid) != '' ORDER BY name ASC";
$fac_result = $conn->query($fac_query);
$facUploadDir = __DIR__ . '/../uploads/faculty/';

if ($fac_result) {
    while ($frow = $fac_result->fetch_assoc()) {
        $f_uid = strtoupper(trim($frow['rfid_uid']));
        $f_name = trim($frow['name']);
        $f_dept = trim($frow['department'] ?? 'Faculty');
        $f_status = trim($frow['status'] ?? 'ACTIVE');
        $f_photoRaw = trim($frow['photo'] ?? '');

        $f_targetPhotoName = '';

        if (!empty($f_photoRaw)) {
            $f_sourceFile = $facUploadDir . $f_photoRaw;
            if (file_exists($f_sourceFile)) {
                $ext = strtolower(pathinfo($f_photoRaw, PATHINFO_EXTENSION));
                $baseName = pathinfo($f_photoRaw, PATHINFO_FILENAME);
                $f_targetPhotoName = $baseName . '.jpg';

                if (!isset($processedPhotos[$f_targetPhotoName])) {
                    $src = null;
                    switch ($ext) {
                        case 'png':  $src = @imagecreatefrompng($f_sourceFile); break;
                        case 'jpg':
                        case 'jpeg': $src = @imagecreatefromjpeg($f_sourceFile); break;
                        case 'webp': $src = @imagecreatefromwebp($f_sourceFile); break;
                    }

                    if ($src) {
                        $w = imagesx($src);
                        $h = imagesy($src);

                        $cropSize = min($w, $h);
                        $cropX = (int)(($w - $cropSize) / 2);
                        $cropY = (int)(($h - $cropSize) / 2);

                        $dst = imagecreatetruecolor($photoW, $photoH);
                        imagecopyresampled(
                            $dst, $src,
                            0, 0,
                            $cropX, $cropY,
                            $photoW, $photoH,
                            $cropSize, $cropSize
                        );

                        ob_start();
                        imagejpeg($dst, null, $jpegQuality);
                        $jpgData = ob_get_clean();

                        imagedestroy($src);
                        imagedestroy($dst);

                        if (!empty($jpgData)) {
                            $zip->addFromString('photos/' . $f_targetPhotoName, $jpgData);
                            $processedPhotos[$f_targetPhotoName] = true;
                        }
                    }
                }
            }
        }

        $cleanFName = str_replace('"', '""', $f_name);
        $cleanFDept = str_replace('"', '""', $f_dept);

        $csvLines[] = sprintf(
            '%s,"%s","%s",%s,%s',
            $f_uid,
            $cleanFName,
            $cleanFDept,
            $f_status,
            $f_targetPhotoName
        );
    }
}

// Add students.csv (contains all authorized gatepass cardholders)
$zip->addFromString('students.csv', implode("\r\n", $csvLines) . "\r\n");

// Add instructions README
$readme = "NEUST GATEPASS - SD CARD SETUP INSTRUCTIONS\r\n"
        . "============================================\r\n\r\n"
        . "1. Format your MicroSD card as FAT32.\r\n"
        . "2. Extract the contents of this ZIP directly to the root of your SD card.\r\n"
        . "   The root should look like this:\r\n"
        . "     [SD Card Root]/\r\n"
        . "       ├── students.csv\r\n"
        . "       ├── photos/\r\n"
        . "       │     ├── 1789726299_6aad0e5bc9b34.jpg\r\n"
        . "       │     └── ...\r\n"
        . "       └── logs/ (automatically created by ESP32 for offline scans)\r\n\r\n"
        . "3. Insert the MicroSD card into the TFT display's SD slot.\r\n"
        . "4. Power on or reset the ESP32 gatepass device.\r\n"
        . "5. When offline, student photos and names will display instantly upon RFID tap!\r\n";
$zip->addFromString('README.txt', $readme);

$zip->close();
$conn->close();

// Download output
$filename = "neust_sd_package_" . date('Ymd_His') . ".zip";
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmpZip);
@unlink($tmpZip);
exit;
