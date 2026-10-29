<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  Webstudio Docs — storage.php                            ║
 * ║  Shared file-storage helpers (api.php, mcp.php)          ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Contract: this file only defines constants and functions. It prints
 * nothing, starts no session and has no side effects on include. On errors
 * the helpers call err($msg, $code), which every entry point defines itself
 * (api.php: JSON response + exit; mcp.php: throws a tool error).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'storage.php') { http_response_code(403); exit; }

// ── Configuration ──────────────────────────
defined('DATA_DIR')        || define('DATA_DIR',        __DIR__ . '/data');
defined('PAGES_DIR')       || define('PAGES_DIR',       __DIR__ . '/data/pages');
defined('TRASH_DIR')       || define('TRASH_DIR',       __DIR__ . '/data/trash');
defined('PAGES_TRASH_DIR') || define('PAGES_TRASH_DIR', __DIR__ . '/data/trash/pages');
defined('IMAGES_DIR')      || define('IMAGES_DIR',      __DIR__ . '/images');
defined('VIDEOS_DIR')      || define('VIDEOS_DIR',      __DIR__ . '/videos');
defined('TMP_UPLOADS_DIR') || define('TMP_UPLOADS_DIR', DATA_DIR . '/tmp_uploads');

// Largest fully-assembled video upload we accept (2 GB). Chunks are uploaded
// individually as small raw-body requests (see upload_video_chunk in api.php),
// so this is the only effective ceiling for large local video uploads.
defined('VIDEO_MAX_BYTES')       || define('VIDEO_MAX_BYTES', 2 * 1024 * 1024 * 1024);
// Reject any single chunk larger than this (defensive; the client uses ~2 MB).
defined('VIDEO_CHUNK_MAX_BYTES') || define('VIDEO_CHUNK_MAX_BYTES', 8 * 1024 * 1024);

// ── Helper functions ────────────────────────
function ensureDirs() {
    foreach ([DATA_DIR, PAGES_DIR, TRASH_DIR, PAGES_TRASH_DIR, IMAGES_DIR, VIDEOS_DIR, TMP_UPLOADS_DIR] as $dir) {
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
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    // Write to a temp file then rename: rename() is atomic on the same
    // filesystem, so a reader never sees a half-written file and two
    // concurrent writers can't interleave bytes into one file.
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
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

function detectUploadedVideoMime($path) {
    if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }

    if (function_exists('finfo_open') && defined('FILEINFO_MIME_TYPE')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = @finfo_file($finfo, $path);
            @finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                // Some systems report Ogg video as application/ogg.
                if ($mime === 'application/ogg') return 'video/ogg';
                return $mime;
            }
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

function pageJsonFiles() {
    return array_values(array_filter(glob(PAGES_DIR . '/*.json') ?: [], function ($path) {
        return !preg_match('/_rating\.json$/', basename($path));
    }));
}

// Change a page's id (moves the page file + ratings, re-parents direct
// children) and/or apply edited metadata. Content is kept as stored.
// Calls err() on failure; returns the page as written.
function renameStoredPage($oldId, $newId, array $meta = []): array {
    $oldId = sanitizePageId($oldId);
    $newId = sanitizePageId($newId);
    if (!$oldId || !$newId) err('Missing id');
    $oldFile = pageFilePath($oldId);
    if (!file_exists($oldFile)) err('Page not found', 404);
    $page = jsonRead($oldFile);
    if (!$page) err('Page not found', 404);
    if ($newId !== $oldId && file_exists(pageFilePath($newId))) err('Target id already exists', 409);
    // Keep the existing content; only apply the edited metadata + the new id.
    foreach (['title', 'subtitle', 'icon', 'section'] as $k) {
        if (array_key_exists($k, $meta)) $page[$k] = $meta[$k];
    }
    $page['id'] = $newId;
    if (!jsonWrite(pageFilePath($newId), $page)) err('Failed to write page', 500);
    if ($newId !== $oldId) {
        @unlink($oldFile);
        if (file_exists(pageRatingsPath($oldId)))       @rename(pageRatingsPath($oldId), pageRatingsPath($newId));
        if (file_exists(pageRatingSummaryPath($oldId))) @rename(pageRatingSummaryPath($oldId), pageRatingSummaryPath($newId));
        // Re-parent direct children so the page tree stays intact.
        foreach (pageJsonFiles() as $file) {
            if ($file === pageFilePath($newId)) continue;
            $child = jsonRead($file);
            if ($child && ($child['parentId'] ?? null) === $oldId) {
                $child['parentId'] = $newId;
                jsonWrite($file, $child);
            }
        }
    }
    return $page;
}

// ── MCP access tokens ───────────────────────
// Bearer tokens for mcp.php. Only a SHA-256 hash of each token is stored
// (tokens carry 256 bits of randomness, so a slow password hash is not needed).
// All writes go through mcpTokensWith(), which holds an exclusive flock on a
// sidecar lock file, so lastUsedAt updates, creates and revokes never
// overwrite each other.
defined('MCP_TOKENS_FILE') || define('MCP_TOKENS_FILE', DATA_DIR . '/mcp_tokens.json');
defined('MCP_TOKENS_MAX')  || define('MCP_TOKENS_MAX', 20);
// Only persist lastUsedAt when the stored value is older than this (seconds).
defined('MCP_TOKEN_TOUCH_INTERVAL') || define('MCP_TOKEN_TOUCH_INTERVAL', 300);

function mcpTokensRead(): array {
    $data = jsonRead(MCP_TOKENS_FILE, null);
    $tokens = (is_array($data) && is_array($data['tokens'] ?? null)) ? $data['tokens'] : [];
    return ['tokens' => array_values(array_filter($tokens, function ($t) {
        return is_array($t) && !empty($t['id']) && !empty($t['hash']);
    }))];
}

function mcpTokensWrite(array $data): bool {
    $tokens = is_array($data['tokens'] ?? null) ? array_values($data['tokens']) : [];
    return jsonWrite(MCP_TOKENS_FILE, ['tokens' => $tokens]);
}

// Run $fn(array &$data) under an exclusive lock and persist $data afterwards
// if $fn returns ['changed' => true, ...]. Returns $fn's result (or null if the
// lock could not be taken). jsonWrite() replaces the file via rename(), so the
// lock lives on a separate file whose inode never changes.
function mcpTokensWith(callable $fn) {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    $fp = @fopen(MCP_TOKENS_FILE . '.lock', 'c');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return null; }
    try {
        $data = mcpTokensRead();
        $result = $fn($data);
        if (is_array($result) && !empty($result['changed'])) {
            $result['written'] = mcpTokensWrite($data);
        }
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function mcpTokenPublicEntry(array $entry): array {
    return [
        'id'         => (string)($entry['id'] ?? ''),
        'name'       => (string)($entry['name'] ?? ''),
        'hint'       => (string)($entry['hint'] ?? ''),
        'createdAt'  => $entry['createdAt'] ?? null,
        'lastUsedAt' => $entry['lastUsedAt'] ?? null,
    ];
}

function mcpTokenPublicList(): array {
    return array_map('mcpTokenPublicEntry', mcpTokensRead()['tokens']);
}

function mcpTokenCreate(string $name): array {
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 60, 'UTF-8') : substr($name, 0, 60);
    $name = trim($name);
    if ($name === '') $name = 'AI agent';

    $token = 'wsd_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $entry = [
        'id'         => 'tok_' . bin2hex(random_bytes(6)),
        'name'       => $name,
        'hash'       => hash('sha256', $token),
        'hint'       => substr($token, 0, 8) . '…' . substr($token, -4),
        'createdAt'  => date('c'),
        'lastUsedAt' => null,
    ];

    $res = mcpTokensWith(function (array &$data) use ($entry) {
        if (count($data['tokens']) >= MCP_TOKENS_MAX) return ['changed' => false, 'error' => 'limit'];
        $data['tokens'][] = $entry;
        return ['changed' => true];
    });
    if ($res === null) err('Failed to lock the token store', 500);
    if (($res['error'] ?? '') === 'limit') err('Token limit reached (max ' . MCP_TOKENS_MAX . '). Revoke an unused token first.', 409);
    if (empty($res['written'])) err('Failed to write the token store', 500);

    return ['token' => $token, 'entry' => mcpTokenPublicEntry($entry)];
}

function mcpTokenRevoke(string $id): bool {
    if ($id === '') return false;
    $res = mcpTokensWith(function (array &$data) use ($id) {
        $before = count($data['tokens']);
        $data['tokens'] = array_values(array_filter($data['tokens'], function ($t) use ($id) {
            return !hash_equals((string)$t['id'], $id);
        }));
        return ['changed' => count($data['tokens']) !== $before];
    });
    return is_array($res) && !empty($res['changed']) && !empty($res['written']);
}

// Returns the stored entry (with hash) for a valid plaintext token, else null.
// Bumps lastUsedAt at most every MCP_TOKEN_TOUCH_INTERVAL seconds, so a busy
// agent doesn't rewrite the file on every request.
function mcpTokenVerify(string $plain): ?array {
    $plain = trim($plain);
    if (strncmp($plain, 'wsd_', 4) !== 0 || strlen($plain) > 200) return null;
    $hash = hash('sha256', $plain);

    $match = null;
    foreach (mcpTokensRead()['tokens'] as $t) {
        if (hash_equals((string)$t['hash'], $hash)) { $match = $t; break; }
    }
    if ($match === null) return null;

    $last = !empty($match['lastUsedAt']) ? strtotime((string)$match['lastUsedAt']) : false;
    if ($last === false || time() - $last >= MCP_TOKEN_TOUCH_INTERVAL) {
        $now = date('c');
        $touched = mcpTokensWith(function (array &$data) use ($hash, $now) {
            foreach ($data['tokens'] as &$t) {
                if (hash_equals((string)$t['hash'], $hash)) {
                    $t['lastUsedAt'] = $now;
                    return ['changed' => true, 'entry' => $t];
                }
            }
            unset($t);
            return ['changed' => false, 'revoked' => true];
        });
        // Revoked between the unlocked read and the lock: reject.
        if (is_array($touched) && !empty($touched['revoked'])) return null;
        if (is_array($touched) && isset($touched['entry'])) $match = $touched['entry'];
    }
    return $match;
}
