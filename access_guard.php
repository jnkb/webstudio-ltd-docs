<?php

if (defined('WEBSTUDIO_DOCS_ACCESS_GUARD_LOADED')) {
    return;
}

define('WEBSTUDIO_DOCS_ACCESS_GUARD_LOADED', true);
if (PHP_SAPI === 'cli') {
    return;
}

define('DOCS_ACCESS_COOKIE_NAME', 'docs_access_pass');
define('DOCS_ACCESS_COOKIE_TTL', 3600 * 24 * 30);
define('DOCS_ACCESS_KEY_FILE', __DIR__ . '/data/access_key.txt');
define('DOCS_ACCESS_QUERY_PARAM', 'token');

function docsIsHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        return $forwardedProto === 'https';
    }

    return false;
}

function docsCookieSameSite() {
    // Cross-site iframe cookies require SameSite=None and Secure.
    return docsIsHttpsRequest() ? 'None' : 'Lax';
}

function docsAccessReadConfig() {
    if (!is_file(DOCS_ACCESS_KEY_FILE)) {
        return ['enabled' => false];
    }

    if (!is_readable(DOCS_ACCESS_KEY_FILE)) {
        return ['error' => 'unreadable'];
    }

    $raw = file_get_contents(DOCS_ACCESS_KEY_FILE);
    if ($raw === false) {
        return ['error' => 'unreadable'];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $entry = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
            continue;
        }
        $entry = $line;
        break;
    }

    if ($entry === '') {
        return ['enabled' => false];
    }

    return ['enabled' => true, 'value' => $entry];
}

function docsAccessCookieOptions($expires) {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => docsIsHttpsRequest(),
        'httponly' => true,
        'samesite' => docsCookieSameSite(),
    ];
}

function docsAccessSetCookieValue($value, $expires) {
    setcookie(DOCS_ACCESS_COOKIE_NAME, $value, docsAccessCookieOptions($expires));
    if ($expires < time()) {
        unset($_COOKIE[DOCS_ACCESS_COOKIE_NAME]);
        return;
    }
    $_COOKIE[DOCS_ACCESS_COOKIE_NAME] = $value;
}

function docsAccessExpectedCookie($accessValue) {
    return hash('sha256', 'webstudio-docs|' . $accessValue);
}

function docsAccessCurrentUrlWithoutParam($param) {
    $path = '/';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (is_string($requestPath) && $requestPath !== '') {
            $path = $requestPath;
        }
    } elseif (!empty($_SERVER['PHP_SELF'])) {
        $path = $_SERVER['PHP_SELF'];
    }

    $query = $_GET;
    unset($query[$param]);

    if (!$query) {
        return $path;
    }

    return $path . '?' . http_build_query($query);
}

function docsAccessDeny($message) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access denied</title><style>body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#111827;color:#f9fafb;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}.card{max-width:560px;background:#1f2937;border:1px solid #374151;border-radius:16px;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,.35)}h1{margin:0 0 12px;font-size:28px}p{margin:0;color:#d1d5db;line-height:1.6}</style></head><body><main class="card"><h1>Access denied</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></main></body></html>';
    exit;
}

$docsAccessConfig = docsAccessReadConfig();

if (!empty($docsAccessConfig['error'])) {
    docsAccessSetCookieValue('', time() - 3600);
    docsAccessDeny('Access is not configured yet. Add your access key to data/access_key.txt first.');
}

if (empty($docsAccessConfig['enabled'])) {
    return;
}

header('X-Robots-Tag: noindex, nofollow, noarchive');

$docsAccessValue = $docsAccessConfig['value'];
$docsAccessExpectedCookie = docsAccessExpectedCookie($docsAccessValue);
$docsAccessRequestValue = $_GET[DOCS_ACCESS_QUERY_PARAM] ?? null;

if (is_string($docsAccessRequestValue) && hash_equals($docsAccessValue, $docsAccessRequestValue)) {
    docsAccessSetCookieValue($docsAccessExpectedCookie, time() + DOCS_ACCESS_COOKIE_TTL);
    return;
}

$docsAccessCookie = $_COOKIE[DOCS_ACCESS_COOKIE_NAME] ?? '';
if (!is_string($docsAccessCookie) || !hash_equals($docsAccessExpectedCookie, $docsAccessCookie)) {
    docsAccessSetCookieValue('', time() - 3600);
    docsAccessDeny('Open this page once with the configured access link to unlock the content in this browser.');
}