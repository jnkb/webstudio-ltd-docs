<?php
require __DIR__ . '/access_guard.php';

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  Webstudio Docs — api.php                                ║
 * ║  Open-source self-hosted documentation platform          ║
 * ║  Built with ♥ by webstudio.ltd                           ║
 * ║  https://github.com/webstudio-ltd/docs                   ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Stores data in JSON files, images in images/
 */

// ── Configuration ──────────────────────────
define('DATA_DIR',        __DIR__ . '/data');
define('PAGES_DIR',       __DIR__ . '/data/pages');
define('TRASH_DIR',       __DIR__ . '/data/trash');
define('PAGES_TRASH_DIR', __DIR__ . '/data/trash/pages');
define('IMAGES_DIR',      __DIR__ . '/images');

// ── Session / auth (same as auth.php) ──
define('SESSION_NAME', 'docs_auth');
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 3600 * 8,
    'path'     => '/',
    'secure'   => docsIsHttpsRequest(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Powered-By: Webstudio Docs — webstudio.ltd');

$authed = !empty($_SESSION['authed']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helper functions ────────────────────────
function ensureDirs() {
    foreach ([DATA_DIR, PAGES_DIR, TRASH_DIR, PAGES_TRASH_DIR, IMAGES_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            err('Failed to initialize storage directories', 500);
        }
    }
    // Protect data directory from direct web access
    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        if (@file_put_contents($htaccess, "# Deny all direct access to data files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n") === false) {
            err('Failed to protect the data directory', 500);
        }
    }
    // Also add index.php to prevent directory listing as fallback
    $index = DATA_DIR . '/index.php';
    if (!file_exists($index)) {
        if (@file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');") === false) {
            err('Failed to protect the data directory', 500);
        }
    }
}

function ensureProtectedDirectoryIndex($dir) {
    $index = rtrim($dir, '/\\') . '/index.php';
    if (!file_exists($index)) {
        if (@file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');") === false) {
            err('Failed to protect the data directory', 500);
        }
    }
}

function jsonRead($path, $default = null) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    return $raw ? json_decode($raw, true) : $default;
}

function jsonWrite($path, $data) {
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function uploadErrorMessage($code) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File too large',
        UPLOAD_ERR_FORM_SIZE => 'File too large',
        UPLOAD_ERR_PARTIAL => 'Upload incomplete',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing',
        UPLOAD_ERR_CANT_WRITE => 'Upload directory is not writable',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension',
    ];

    return $messages[$code] ?? 'Upload error';
}

function detectUploadedImageMime($path) {
    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $candidates = [];

    if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                $candidates[] = $mime;
            }
        }
    }

    if (function_exists('getimagesize')) {
        $imageInfo = @getimagesize($path);
        if (is_array($imageInfo) && !empty($imageInfo['mime']) && is_string($imageInfo['mime'])) {
            $candidates[] = $imageInfo['mime'];
        }
    }

    $snippet = @file_get_contents($path, false, null, 0, 2048);
    if (is_string($snippet) && preg_match('/<svg\b/i', $snippet)) {
        $candidates[] = 'image/svg+xml';
    }

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $allowed, true)) {
            return $candidate;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function pageFilePath($id) {
    return PAGES_DIR . "/{$id}.json";
}

function pageRatingsPath($id) {
    return PAGES_DIR . "/{$id}_ratings.csv";
}

function pageRatingSummaryPath($id) {
    return PAGES_DIR . "/{$id}_rating.json";
}

function sanitizePageId($id) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
}

function sanitizePageIds($ids) {
    if (!is_array($ids)) return [];

    $clean = [];
    $seen = [];
    foreach ($ids as $rawId) {
        $id = sanitizePageId($rawId);
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $clean[] = $id;
    }

    return $clean;
}

function buildTrashTargetPath($filename) {
    $target = PAGES_TRASH_DIR . '/' . $filename;
    if (!file_exists($target)) return $target;

    $info = pathinfo($filename);
    $name = $info['filename'] ?? $filename;
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $suffix = 2;

    do {
        $target = PAGES_TRASH_DIR . '/' . $name . '__dup' . $suffix . $ext;
        $suffix++;
    } while (file_exists($target));

    return $target;
}

function moveDeletedArtifactToTrash($path, $batchPrefix, $pageCounter = null) {
    if (!is_file($path)) return;

    $prefix = $batchPrefix;
    if ($pageCounter !== null) {
        $prefix .= '_' . str_pad((string)$pageCounter, 3, '0', STR_PAD_LEFT);
    }

    $targetPath = buildTrashTargetPath($prefix . '_' . basename($path));
    if (@rename($path, $targetPath)) return;

    if (!@copy($path, $targetPath) || !@unlink($path)) {
        err('Failed to move deleted page files to trash', 500);
    }
}

function trashPageArtifacts($id, $batchPrefix, $pageCounter = null) {
    foreach ([pageFilePath($id), pageRatingsPath($id), pageRatingSummaryPath($id)] as $path) {
        moveDeletedArtifactToTrash($path, $batchPrefix, $pageCounter);
    }
}

function trashPagesById($ids) {
    $pageIds = sanitizePageIds($ids);
    if (!$pageIds) return;

    $batchPrefix = date('Y-m-d_H-i-s');
    $useCounter = count($pageIds) > 1;

    foreach (array_values($pageIds) as $index => $id) {
        trashPageArtifacts($id, $batchPrefix, $useCounter ? $index + 1 : null);
    }
}

function defaultRatingSummary() {
    return ['-1' => 0, '0' => 0, '1' => 0];
}

function sanitizeRatingSummary($summary) {
    if (!is_array($summary)) return null;

    $clean = defaultRatingSummary();
    foreach (array_keys($clean) as $key) {
        $clean[$key] = max(0, (int)($summary[$key] ?? 0));
    }

    return $clean;
}

function normalizeRatingValue($rating) {
    $value = is_string($rating) ? trim($rating) : (string)$rating;
    $map = [
        '1' => '1',
        '0' => '0',
        '-1' => '-1',
        '👍' => '1',
        '😐' => '0',
        '👎' => '-1',
    ];

    return $map[$value] ?? null;
}

function ratingSummaryTotal($summary) {
    return (int)$summary['-1'] + (int)$summary['0'] + (int)$summary['1'];
}

function ratingSummaryAverage($summary) {
    $total = ratingSummaryTotal($summary);
    if ($total <= 0) return null;

    return (((int)$summary['1']) - ((int)$summary['-1'])) / $total;
}

function rebuildRatingSummary($id) {
    $summary = defaultRatingSummary();
    $csvPath = pageRatingsPath($id);

    if (file_exists($csvPath)) {
        $handle = fopen($csvPath, 'rb');
        if ($handle !== false) {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rating = normalizeRatingValue($row[1] ?? '');
                if ($rating !== null) $summary[$rating]++;
            }
            fclose($handle);
        }
    }

    jsonWrite(pageRatingSummaryPath($id), $summary);
    return $summary;
}

function loadRatingSummary($id) {
    $summary = sanitizeRatingSummary(jsonRead(pageRatingSummaryPath($id), null));
    if ($summary !== null) return $summary;
    return rebuildRatingSummary($id);
}

function pageJsonFiles() {
    return array_values(array_filter(glob(PAGES_DIR . '/*.json') ?: [], function ($path) {
        return !preg_match('/_rating\.json$/', basename($path));
    }));
}

function getClientIp() {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim(explode(',', $candidate)[0]);
        if ($candidate !== '') return $candidate;
    }

    return '';
}

function anonymizeIp($ip) {
    if (!$ip) return 'unknown';

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($ip);
        if ($packed === false) return 'unknown';
        $masked = substr($packed, 0, 8) . str_repeat("\0", 8);
        $anon = @inet_ntop($masked);
        return $anon !== false ? $anon : 'unknown';
    }

    return 'unknown';
}

function appendRating($id, $rating, $anonIp) {
    $path = pageRatingsPath($id);
    $handle = fopen($path, 'ab');
    if ($handle === false) return false;

    $ok = false;
    if (flock($handle, LOCK_EX)) {
        $ok = fputcsv($handle, [gmdate('c'), $rating, $anonIp], ',', '"', '\\') !== false;
        flock($handle, LOCK_UN);
    }

    fclose($handle);
    return $ok;
}

function ok($data = []) {
    echo json_encode(['ok' => true, '_ws' => 'webstudio.ltd'] + $data);
    exit;
}

function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function requireAuth() {
    global $authed;
    if (!$authed) err('Unauthorized', 401);
}

ensureDirs();
ensureProtectedDirectoryIndex(TRASH_DIR);
ensureProtectedDirectoryIndex(PAGES_TRASH_DIR);

// ════════════════════════════════════════════
switch ($action) {

// ── LOAD — load everything (spaces + pages meta + settings) ──
case 'load':
    $spaces   = jsonRead(DATA_DIR . '/spaces.json', []);
    $settings = jsonRead(DATA_DIR . '/settings.json', []);

    // Load all pages (meta only, content is loaded separately)
    $pages = [];
    if (is_dir(PAGES_DIR)) {
        foreach (pageJsonFiles() as $file) {
            $page = jsonRead($file);
            if ($page) $pages[] = $page;
        }
    }
    ok(['spaces' => $spaces, 'pages' => $pages, 'settings' => $settings]);

// ── LOAD PAGE — load content of a single page ──
case 'load_page':
    global $authed;
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    if (!$id) err('Missing id');
    $page = jsonRead(pageFilePath($id));
    if (!$page) err('Page not found', 404);
    $response = ['page' => $page];
    if ($authed) {
        $summary = loadRatingSummary($id);
        $response['ratingStats'] = $summary;
        $response['ratingAverage'] = ratingSummaryAverage($summary);
        $response['ratingCount'] = ratingSummaryTotal($summary);
        $response['ratingCsvAvailable'] = file_exists(pageRatingsPath($id));
    }
    ok($response);

// ── SAVE RATING — public feedback for readers ──
case 'save_rating':
    $body = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['id'] ?? '');
    $rating = normalizeRatingValue($body['rating'] ?? '');
    if (!$id) err('Missing id');
    if ($rating === null) err('Invalid rating');
    if (!file_exists(pageFilePath($id))) err('Page not found', 404);

    $summary = loadRatingSummary($id);
    $anonIp = anonymizeIp(getClientIp());
    if (!appendRating($id, $rating, $anonIp)) err('Failed to save rating', 500);
    $summary[$rating]++;
    if (!jsonWrite(pageRatingSummaryPath($id), $summary)) err('Failed to update rating summary', 500);
    ok([
        'ratingStats' => $summary,
        'ratingAverage' => ratingSummaryAverage($summary),
        'ratingCount' => ratingSummaryTotal($summary),
    ]);

// ── DOWNLOAD RATINGS CSV — editor only ──
case 'download_ratings':
    requireAuth();
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    if (!$id) err('Missing id');
    $path = pageRatingsPath($id);
    if (!file_exists($path)) err('Ratings not found', 404);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $id . '_ratings.csv"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;

// ── SAVE SPACES ──
case 'save_spaces':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['spaces'])) err('Missing spaces');
    jsonWrite(DATA_DIR . '/spaces.json', $body['spaces']);
    ok();

// ── SAVE SETTINGS ──
case 'save_settings':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!isset($body['settings'])) err('Missing settings');
    jsonWrite(DATA_DIR . '/settings.json', $body['settings']);
    ok();

// ── SAVE PAGE — save a single page to its own file ──
case 'save_page':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $page = $body['page'] ?? null;
    if (!$page || empty($page['id'])) err('Missing page or id');
    $id = sanitizePageId($page['id']);
    jsonWrite(pageFilePath($id), $page);
    ok();

// ── SAVE ALL PAGES — bulk save (on reorder, delete, etc.) ──
case 'save_pages':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $pages = $body['pages'] ?? null;
    if (!is_array($pages)) err('Missing pages');

    // Find existing files and delete pages that are no longer in the list
    $existingIds = [];
    foreach (pageJsonFiles() as $f) {
        $existingIds[] = basename($f, '.json');
    }
    $newIds = sanitizePageIds(array_column($pages, 'id'));
    $removedIds = [];
    foreach ($existingIds as $eid) {
        if (!in_array($eid, $newIds, true)) {
            $removedIds[] = $eid;
        }
    }
    trashPagesById($removedIds);
    // Save each page
    foreach ($pages as $page) {
        if (empty($page['id'])) continue;
        $id = sanitizePageId($page['id']);
        jsonWrite(pageFilePath($id), $page);
    }
    ok();

// ── DELETE PAGE ──
case 'delete_page':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : [$body['id'] ?? ''];
    $pageIds = sanitizePageIds($ids);
    if (!$pageIds) err('Missing id');
    trashPagesById($pageIds);
    ok(['deleted' => count($pageIds)]);

// ── UPLOAD IMAGE ──
case 'upload_image':
    requireAuth();
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) err('No file uploaded');
    $file = $_FILES['image'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) err(uploadErrorMessage($uploadError));

    $tmpName = $file['tmp_name'] ?? '';
    if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) err('Invalid upload');

    if (!is_dir(IMAGES_DIR) && !@mkdir(IMAGES_DIR, 0755, true) && !is_dir(IMAGES_DIR)) {
        err('Upload directory is not available', 500);
    }
    if (!is_writable(IMAGES_DIR)) err('Upload directory is not writable', 500);

    // Verify MIME type
    $mime = detectUploadedImageMime($tmpName);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!$mime || !in_array($mime, $allowed, true)) err('File type not allowed');

    // Max 10 MB
    if ($file['size'] > 10 * 1024 * 1024) err('File too large (max 10 MB)');

    $ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'
    ][$mime];
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = IMAGES_DIR . '/' . $name;

    if (!@move_uploaded_file($tmpName, $dest)) err('Failed to save file', 500);

    // Return web-relative URL
    $baseUrl = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    ok(['url' => $baseUrl . '/images/' . $name, 'filename' => $name]);

// ── DELETE IMAGE ──
case 'delete_image':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $name = basename($body['filename'] ?? '');
    // Only allowed characters in filename
    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $name)) err('Invalid filename');
    $path = IMAGES_DIR . '/' . $name;
    if (file_exists($path)) unlink($path);
    ok();

default:
    err('Unknown action');
}
