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
define('DATA_DIR',   __DIR__ . '/data');
define('PAGES_DIR',  __DIR__ . '/data/pages');
define('IMAGES_DIR', __DIR__ . '/images');

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
    foreach ([DATA_DIR, PAGES_DIR, IMAGES_DIR] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }
    // Protect data directory from direct web access
    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "# Deny all direct access to data files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    // Also add index.php to prevent directory listing as fallback
    $index = DATA_DIR . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');");
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

function pageFilePath($id) {
    return PAGES_DIR . "/{$id}.json";
}

function pageRatingsPath($id) {
    return PAGES_DIR . "/{$id}_ratings.csv";
}

function pageRatingSummaryPath($id) {
    return PAGES_DIR . "/{$id}_rating.json";
}

function deletePageArtifacts($id) {
    foreach ([pageFilePath($id), pageRatingsPath($id), pageRatingSummaryPath($id)] as $path) {
        if (file_exists($path)) unlink($path);
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
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $page['id']);
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
    $newIds = array_column($pages, 'id');
    foreach ($existingIds as $eid) {
        if (!in_array($eid, $newIds)) {
            deletePageArtifacts($eid);
        }
    }
    // Save each page
    foreach ($pages as $page) {
        if (empty($page['id'])) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $page['id']);
        jsonWrite(pageFilePath($id), $page);
    }
    ok();

// ── DELETE PAGE ──
case 'delete_page':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['id'] ?? '');
    if (!$id) err('Missing id');
    deletePageArtifacts($id);
    ok();

// ── UPLOAD IMAGE ──
case 'upload_image':
    requireAuth();
    if (empty($_FILES['image'])) err('No file uploaded');
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error');

    // Verify MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mime, $allowed)) err('File type not allowed');

    // Max 10 MB
    if ($file['size'] > 10 * 1024 * 1024) err('File too large (max 10 MB)');

    $ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'
    ][$mime];
    $name = uniqid('img_', true) . '.' . $ext;
    $dest = IMAGES_DIR . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) err('Failed to save file');

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
