<?php
require __DIR__ . '/access_guard.php';

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  Webstudio Docs — auth.php                               ║
 * ║  Open-source self-hosted documentation platform          ║
 * ║  Built with ♥ by webstudio.ltd                           ║
 * ║  https://github.com/webstudio-ltd/docs                   ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Authentication handler for Docs.
 * Password is hashed with bcrypt and stored in data/auth.json
 * On first run, the setup wizard in index.html will prompt for a password.
 */

define('SESSION_NAME', 'docs_auth_' . DOCS_INSTANCE_HASH);
define('SESSION_LIFETIME', 3600 * 8); // 8 hours
define('DATA_DIR', __DIR__ . '/data');
define('AUTH_FILE', DATA_DIR . '/auth.json');
define('SPACES_FILE', DATA_DIR . '/spaces.json');
define('PAGES_DIR', DATA_DIR . '/pages');

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => docsIsHttpsRequest(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Powered-By: Webstudio Docs — webstudio.ltd');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── Helpers ──
function getAuthData() {
    if (!file_exists(AUTH_FILE)) return null;
    $raw = file_get_contents(AUTH_FILE);
    return $raw ? json_decode($raw, true) : null;
}

function isSetupComplete() {
    $auth = getAuthData();
    return $auth && !empty($auth['passwordHash']);
}

function getPasswordHash() {
    $auth = getAuthData();
    return $auth['passwordHash'] ?? null;
}

function jsonReadFile($path, $default = null) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    return $raw ? json_decode($raw, true) : $default;
}

function jsonWriteFile($path, $data) {
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
}

function ensureSetupStorage() {
    foreach ([DATA_DIR, PAGES_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }

    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess) && @file_put_contents($htaccess, "# Deny all direct access to data files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n") === false) {
        return false;
    }

    $index = DATA_DIR . '/index.php';
    if (!file_exists($index) && @file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');") === false) {
        return false;
    }

    return true;
}

function sanitizeBootstrapSpaceId($value) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
}

function generateBootstrapSpaceId() {
    try {
        return '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable $e) {
        return '_' . substr(md5(uniqid('', true)), 0, 8);
    }
}

function loadStoredPages() {
    $pages = [];
    foreach (glob(PAGES_DIR . '/*.json') ?: [] as $file) {
        if (substr($file, -12) === '_rating.json') continue;
        $page = jsonReadFile($file);
        if (is_array($page) && !empty($page['id'])) {
            $pages[] = $page;
        }
    }
    return $pages;
}

function buildDefaultPages($spaceId) {
    $nowMs = (int) round(microtime(true) * 1000);
    $updatedAt = date('c');

    return [
        [
            'id' => 'welcome',
            'spaceId' => $spaceId,
            'parentId' => null,
            'title' => 'Welcome',
            'icon' => 'fa-house',
            'subtitle' => 'Introduction to your documentation workspace',
            'section' => 'Getting Started',
            'order' => 0,
            'updatedAt' => $updatedAt,
            'content' => [
                'time' => $nowMs,
                'blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'Getting started', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Welcome to your self-hosted documentation platform. Click <b>Edit mode</b> in the top right to start writing content.']],
                    ['type' => 'warning', 'data' => ['type' => 'tip', 'title' => 'Tip', 'message' => 'Use the plus button in the editor to insert blocks such as headings, code, tables, lists, and more.']],
                    ['type' => 'header', 'data' => ['text' => 'What you can do', 'level' => 2]],
                    ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                        ['content' => 'Create pages and subpages in a tree structure', 'items' => []],
                        ['content' => 'Organize content into sections and spaces', 'items' => []],
                        ['content' => 'Upload your own logo and customize the accent color', 'items' => []],
                        ['content' => 'Everything is saved as JSON files on your server', 'items' => []],
                    ]]],
                    ['type' => 'delimiter', 'data' => new stdClass()],
                    ['type' => 'paragraph', 'data' => ['text' => 'All changes are automatically saved to the server as JSON files. Images are stored in the images directory.']],
                ],
            ],
        ],
        [
            'id' => 'installation',
            'spaceId' => $spaceId,
            'parentId' => null,
            'title' => 'Installation',
            'icon' => 'fa-terminal',
            'subtitle' => 'Deploy Webstudio Docs on your own hosting',
            'section' => 'Getting Started',
            'order' => 1,
            'updatedAt' => $updatedAt,
            'content' => [
                'time' => $nowMs + 1,
                'blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'Deploy to your own domain', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Upload index.php, editor.php, api.php, and auth.php to any PHP hosting. No database is required because everything is stored as JSON files.']],
                    ['type' => 'header', 'data' => ['text' => 'Requirements', 'level' => 3]],
                    ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                        ['content' => '<b>PHP 7.4+</b> - any standard hosting works', 'items' => []],
                        ['content' => '<b>Write permissions</b> - for the data and images directories', 'items' => []],
                        ['content' => '<b>No database</b> - all data is stored in JSON files', 'items' => []],
                    ]]],
                    ['type' => 'header', 'data' => ['text' => 'Quick start', 'level' => 3]],
                    ['type' => 'code', 'data' => ['code' => "# 1. Upload all files to your server\n# 2. Open the URL in your browser\n# 3. Set your admin password on first run\n# 4. Start writing!"]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Point your custom domain or subdomain, for example docs.yourdomain.com, to the directory where you uploaded the files.']],
                ],
            ],
        ],
        [
            'id' => 'writing-content',
            'spaceId' => $spaceId,
            'parentId' => null,
            'title' => 'Writing Content',
            'icon' => 'fa-pen',
            'subtitle' => 'Overview of the available content blocks',
            'section' => 'Editor',
            'order' => 2,
            'updatedAt' => $updatedAt,
            'content' => [
                'time' => $nowMs + 2,
                'blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'Block types', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'The editor supports various content types. Click the plus button or type / to see all available blocks.']],
                    ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [
                        ['Block', 'Description', 'Shortcut'],
                        ['Heading', 'H1, H2, H3', '# ## ###'],
                        ['List', 'Bulleted or numbered', '- or 1.'],
                        ['Code', 'Code block with syntax', '```'],
                        ['Quote', 'Highlighted quotation', ''],
                        ['Callout', 'Info, warning, or tip box', ''],
                        ['Checklist', 'Checkable items', ''],
                        ['Delimiter', 'Section divider', '---'],
                        ['Table', 'Table with headers', ''],
                        ['Cards', 'Card grid with icons', ''],
                        ['Timeline', 'Changelog timeline', ''],
                    ]]],
                    ['type' => 'quote', 'data' => ['text' => 'Good documentation is the foundation of every project.', 'caption' => '- Developer wisdom']],
                    ['type' => 'checklist', 'data' => ['items' => [
                        ['text' => 'Create your first page', 'checked' => true],
                        ['text' => 'Upload a logo and set the accent color', 'checked' => false],
                        ['text' => 'Add content using blocks', 'checked' => false],
                    ]]],
                ],
            ],
        ],
        [
            'id' => 'blocks-in-editor',
            'spaceId' => $spaceId,
            'parentId' => 'writing-content',
            'title' => 'Blocks in Editor',
            'icon' => 'fa-cube',
            'subtitle' => '',
            'section' => null,
            'order' => 0,
            'updatedAt' => $updatedAt,
            'content' => ['blocks' => []],
        ],
    ];
}

function ensureInitialDocumentationContent() {
    $spaces = jsonReadFile(SPACES_FILE, []);
    if (!is_array($spaces)) {
        $spaces = [];
    }

    $pages = loadStoredPages();
    $spaceIds = [];
    foreach ($spaces as $space) {
        $spaceId = sanitizeBootstrapSpaceId($space['id'] ?? '');
        if ($spaceId !== '') {
            $spaceIds[$spaceId] = true;
        }
    }

    $firstSpaceId = sanitizeBootstrapSpaceId($spaces[0]['id'] ?? '');
    if ($firstSpaceId === '') {
        foreach ($pages as $page) {
            $candidate = sanitizeBootstrapSpaceId($page['spaceId'] ?? '');
            if ($candidate !== '') {
                $firstSpaceId = $candidate;
                break;
            }
        }
    }
    if ($firstSpaceId === '') {
        $firstSpaceId = generateBootstrapSpaceId();
    }

    if (!$spaceIds) {
        $spaces = [[
            'id' => $firstSpaceId,
            'name' => 'Documentation',
            'icon' => 'fa-book',
        ]];
        if (!jsonWriteFile(SPACES_FILE, $spaces)) {
            return false;
        }
        $spaceIds = [$firstSpaceId => true];
    }

    if (!$pages) {
        foreach (buildDefaultPages($firstSpaceId) as $page) {
            if (!jsonWriteFile(PAGES_DIR . '/' . $page['id'] . '.json', $page)) {
                return false;
            }
        }
        return true;
    }

    $defaultPageIds = [
        'welcome' => true,
        'installation' => true,
        'writing-content' => true,
        'blocks-in-editor' => true,
    ];

    foreach ($pages as $page) {
        $pageId = (string) ($page['id'] ?? '');
        if (!isset($defaultPageIds[$pageId])) {
            continue;
        }

        $pageSpaceId = sanitizeBootstrapSpaceId($page['spaceId'] ?? '');
        if ($pageSpaceId !== '' && isset($spaceIds[$pageSpaceId])) {
            continue;
        }

        $page['spaceId'] = $firstSpaceId;
        if ($pageId === 'blocks-in-editor' && empty($page['parentId'])) {
            $page['parentId'] = 'writing-content';
        }

        if (!jsonWriteFile(PAGES_DIR . '/' . $pageId . '.json', $page)) {
            return false;
        }
    }

    return true;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── Check session + setup status ──
    case 'check':
        echo json_encode([
            'ok'         => true,
            'authed'     => !empty($_SESSION['authed']),
            'needsSetup' => !isSetupComplete(),
        ]);
        break;

    // ── First-time setup — set password ──
    case 'setup':
        if (isSetupComplete()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Setup already completed']);
            break;
        }

        $pw = $_POST['password'] ?? '';
        if (strlen($pw) < 8) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters']);
            break;
        }

        if (!ensureSetupStorage()) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to initialize storage']);
            break;
        }

        if (!ensureInitialDocumentationContent()) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to create the initial documentation content']);
            break;
        }

        // Hash with bcrypt (cost 12)
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

        $authData = [
            'passwordHash' => $hash,
            'createdAt'    => date('c'),
            'algorithm'    => 'bcrypt',
        ];

        if (!jsonWriteFile(AUTH_FILE, $authData)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save auth configuration']);
            break;
        }

        // Auto-login after setup
        $_SESSION['authed'] = true;
        $_SESSION['authed_at'] = time();
        session_regenerate_id(true);

        echo json_encode(['ok' => true, 'authed' => true]);
        break;

    // ── Login ──
    case 'login':
        if (!isSetupComplete()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Setup not completed']);
            break;
        }

        $pw = $_POST['password'] ?? '';
        if (empty($pw)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Enter password']);
            break;
        }

        // Rate limiting — max 10 attempts per 5 minutes
        $attempts = &$_SESSION['login_attempts'];
        $lastAttempt = &$_SESSION['last_attempt_time'];
        $now = time();
        if ($lastAttempt && ($now - $lastAttempt) > 300) {
            $attempts = 0; // reset after 5 min
        }
        if ($attempts >= 10) {
            $wait = 300 - ($now - $lastAttempt);
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => "Too many attempts. Try again in {$wait}s."]);
            break;
        }
        $attempts = ($attempts ?? 0) + 1;
        $lastAttempt = $now;

        $hash = getPasswordHash();
        if ($hash && password_verify($pw, $hash)) {
            $_SESSION['authed'] = true;
            $_SESSION['authed_at'] = $now;
            $attempts = 0;
            session_regenerate_id(true);

            // Re-hash if needed (algorithm upgrade)
            if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
                $authData = getAuthData();
                $authData['passwordHash'] = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
                $authData['updatedAt'] = date('c');
                file_put_contents(AUTH_FILE, json_encode($authData, JSON_PRETTY_PRINT));
            }

            echo json_encode(['ok' => true, 'authed' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Wrong password']);
        }
        break;

    // ── Change password (requires active session) ──
    case 'change_password':
        if (empty($_SESSION['authed'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            break;
        }

        if (!isSetupComplete()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Setup not completed']);
            break;
        }

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';

        $hash = getPasswordHash();
        if (!$hash || !password_verify($current, $hash)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Current password is incorrect']);
            break;
        }

        if (strlen($new) < 8) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters']);
            break;
        }

        if (password_verify($new, $hash)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'New password must differ from the current one']);
            break;
        }

        $authData = getAuthData();
        $authData['passwordHash'] = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $authData['updatedAt'] = date('c');

        if (file_put_contents(AUTH_FILE, json_encode($authData, JSON_PRETTY_PRINT)) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save new password']);
            break;
        }

        // Keep the user logged in and rotate the session id
        $_SESSION['authed'] = true;
        $_SESSION['authed_at'] = time();
        session_regenerate_id(true);

        echo json_encode(['ok' => true]);
        break;

    // ── Logout ──
    case 'logout':
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true, 'authed' => false]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
