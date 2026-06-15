<?php
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
    'secure'   => isset($_SERVER['HTTPS']),
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
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    // Write to a temp file then rename: rename() is atomic on the same
    // filesystem, so a reader never sees a half-written file and two
    // concurrent writers can't interleave bytes into one file.
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

// Settings the public (unauthenticated) viewer is allowed to see. Everything
// else in settings.json stays admin-only instead of being dumped by ?action=load.
function publicSettings($s) {
    if (!is_array($s)) return [];
    $allow = ['siteName','tabTitle','accentColor','theme','lang',
              'logoDataUrl','faviconDataUrl','footerText'];
    $out = [];
    foreach ($allow as $k) {
        if (array_key_exists($k, $s)) $out[$k] = $s[$k];
    }
    return $out;
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
        foreach (glob(PAGES_DIR . '/*.json') as $file) {
            $page = jsonRead($file);
            if (!$page) continue;
            // Public visitors never see pages flagged as draft. (Backward
            // compatible: pages without the flag behave exactly as before.)
            if (!$authed && !empty($page['draft'])) continue;
            $pages[] = $page;
        }
    }
    // Public visitors get only render-safe settings, not the whole config blob.
    if (!$authed) $settings = publicSettings($settings);
    ok(['spaces' => $spaces, 'pages' => $pages, 'settings' => $settings]);

// ── LOAD PAGE — load content of a single page ──
case 'load_page':
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');
    if (!$id) err('Missing id');
    $page = jsonRead(PAGES_DIR . "/{$id}.json");
    if (!$page) err('Page not found', 404);
    // Same 404 as a missing page so drafts can't be probed by id.
    if (!$authed && !empty($page['draft'])) err('Page not found', 404);
    ok(['page' => $page]);

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
    jsonWrite(PAGES_DIR . "/{$id}.json", $page);
    ok();

// ── SAVE ALL PAGES — bulk save (on reorder, delete, etc.) ──
case 'save_pages':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $pages = $body['pages'] ?? null;
    if (!is_array($pages)) err('Missing pages');

    // Find existing files and delete pages that are no longer in the list
    $existingIds = [];
    foreach (glob(PAGES_DIR . '/*.json') as $f) {
        $existingIds[] = basename($f, '.json');
    }
    $newIds = array_column($pages, 'id');
    foreach ($existingIds as $eid) {
        if (!in_array($eid, $newIds)) {
            unlink(PAGES_DIR . "/{$eid}.json");
        }
    }
    // Save each page
    foreach ($pages as $page) {
        if (empty($page['id'])) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $page['id']);
        jsonWrite(PAGES_DIR . "/{$id}.json", $page);
    }
    ok();

// ── DELETE PAGE ──
case 'delete_page':
    requireAuth();
    $body = json_decode(file_get_contents('php://input'), true);
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['id'] ?? '');
    if (!$id) err('Missing id');
    $file = PAGES_DIR . "/{$id}.json";
    if (file_exists($file)) unlink($file);
    ok();

// ── UPLOAD IMAGE ──
case 'upload_image':
    requireAuth();
    if (empty($_FILES['image'])) err('No file uploaded');
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) err('Upload error');

    // Verify MIME type. NOTE: image/svg+xml is deliberately NOT allowed —
    // an SVG is XML and can carry <script>/onload=, and finfo happily reports
    // it as a valid image. Served from /images/ and embedded in a page, that's
    // stored XSS in every reader's browser. If you ever truly need SVG, run it
    // through a server-side sanitizer first; don't just whitelist the MIME.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed)) err('File type not allowed');

    // Max 10 MB
    if ($file['size'] > 10 * 1024 * 1024) err('File too large (max 10 MB)');

    $ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp'
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
