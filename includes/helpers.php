<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Current local time for MySQL DATETIME/TIMESTAMP columns (uses APP_TIMEZONE from config).
 */
function app_now_sql(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

/**
 * Absolute worker URL for sms_gateway.py (same host as current request, /public/.../worker/sms_queue.php).
 */
function publicSmsWorkerQueueUrl(): string
{
    $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $origin = ($httpsOn ? 'https' : 'http') . '://' . ($httpHost !== '' ? $httpHost : 'localhost');
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
    $publicDir = dirname($scriptName);
    $path = ($publicDir === '/' ? '' : rtrim($publicDir, '/')) . '/worker/sms_queue.php';

    return $origin . $path;
}

function appUrl(string $path, array $params = []): string
{
    $url = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    if (function_exists('tenantSlug')) {
        $slug = tenantSlug();
        if ($slug !== '') {
            $params = array_merge(['tenant' => $slug], $params);
        }
    }
    $query = http_build_query($params);
    return $query !== '' ? ($url . '?' . $query) : $url;
}

function selected($actual, $expected): string
{
    return (string) $actual === (string) $expected ? 'selected' : '';
}

function roleLabel(string $role): string
{
    return ucfirst($role);
}

function flash(string $key, ?string $value = null): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }

    $data = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $data;
}

function userPhotoUrl(?string $photoPath): string
{
    $clean = trim((string) $photoPath);
    if ($clean === '') {
        return BASE_URL . '/assets/default-avatar.svg';
    }
    if (preg_match('/^https?:\/\//i', $clean)) {
        return $clean;
    }
    return BASE_URL . '/' . ltrim($clean, '/');
}

/** Public URL for an announcement image, or empty string if none. */
function announcementImageUrl(?string $relativePath): string
{
    $clean = trim((string) $relativePath);
    if ($clean === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $clean)) {
        return $clean;
    }
    return BASE_URL . '/' . ltrim($clean, '/');
}

function saveUploadedAnnouncementImage(string $fieldName, ?string &$error = null): ?string
{
    return saveUploadedImage($fieldName, 'uploads/announcements', 'ann_', 5 * 1024 * 1024, $error);
}

/**
 * Generic image upload handler.
 *
 * @param string      $fieldName    The $_FILES key to process.
 * @param string      $relativeDir  Upload sub-directory relative to public/ (e.g. 'uploads/users').
 * @param string      $filePrefix   Filename prefix (e.g. 'u_' or 'brand_').
 * @param int         $maxBytes     Maximum allowed file size in bytes.
 * @param string|null $error        Set to an error message on failure; null on success.
 */
function saveUploadedImage(
    string $fieldName,
    string $relativeDir,
    string $filePrefix,
    int $maxBytes,
    ?string &$error = null
): ?string {
    $error = null;
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Please try another image.';
        return null;
    }
    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        $error = 'Invalid uploaded file.';
        return null;
    }

    $mbLimit = round($maxBytes / (1024 * 1024));
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        $error = "Image is too large. Maximum size is {$mbLimit}MB.";
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, (string) $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        $error = 'Unsupported image type. Use JPG, PNG, WEBP, or GIF.';
        return null;
    }

    $ext       = $allowed[$mime];
    $projectRoot = dirname(__DIR__);
    $targetDir = $projectRoot . '/public/' . $relativeDir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        $error = 'Cannot create upload directory.';
        return null;
    }

    $fileName   = $filePrefix . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $fileName;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        $error = 'Failed to save uploaded image.';
        return null;
    }

    return $relativeDir . '/' . $fileName;
}

function saveUploadedUserPhoto(string $fieldName, ?string &$error = null): ?string
{
    return saveUploadedImage($fieldName, 'uploads/users', 'u_', 5 * 1024 * 1024, $error);
}

function saveUploadedBrandAsset(string $fieldName, ?string &$error = null): ?string
{
    return saveUploadedImage($fieldName, 'uploads/branding', 'brand_', 8 * 1024 * 1024, $error);
}

function dbColumnExists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/** Loads Composer + PhpSpreadsheet only when exporting XLSX (keeps normal pages fast). */
function requireVendorSpreadsheet(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/xlsx_helper.php';
    $loaded = true;
}
