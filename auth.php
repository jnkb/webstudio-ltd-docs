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

define('SESSION_NAME', 'docs_auth');
define('SESSION_LIFETIME', 3600 * 8); // 8 hours
define('DATA_DIR', __DIR__ . '/data');
define('AUTH_FILE', DATA_DIR . '/auth.json');

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

        // Ensure data directory exists
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

        // Protect data directory from direct web access
        $htaccess = DATA_DIR . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "# Deny all direct access to data files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = DATA_DIR . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');");
        }

        // Hash with bcrypt (cost 12)
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

        $authData = [
            'passwordHash' => $hash,
            'createdAt'    => date('c'),
            'algorithm'    => 'bcrypt',
        ];

        if (file_put_contents(AUTH_FILE, json_encode($authData, JSON_PRETTY_PRINT)) === false) {
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
