<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  Webstudio Docs — mcp.php                                ║
 * ║  MCP server (Streamable HTTP, JSON responses only)       ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Lets AI agents (Claude Code, Claude Desktop, other MCP clients) read and
 * edit the documentation with the same rights as a logged-in admin.
 *
 *  - Transport: POST only, one JSON-RPC message or a batch per request, the
 *    answer is plain application/json (no SSE, no sessions).
 *  - Auth: "Authorization: Bearer wsd_…" (tokens from the editor settings,
 *    verified by mcpTokenVerify() in storage.php). access_guard.php is NOT
 *    loaded: the bearer token replaces the access cookie.
 *  - Everything an agent writes goes through the sanitizers below, because
 *    the viewer renders HTML fields, icons and cover values unfiltered.
 *
 * File layout (append new tools in the TOOLS section only):
 *   1. Bootstrap + error contract (McpToolError, err(), ok())
 *   2. Shared helpers: arguments, sanitizers, block normalizer, URLs, pages
 *   3. Tool registry (mcpTool)
 *   4. TOOLS
 *   5. Raw upload hook (?upload=image|video)
 *   6. JSON-RPC dispatcher + HTTP entry point
 */

ini_set('display_errors', '0');

require __DIR__ . '/storage.php';

// ════════════════════════════════════════════
//  1. ERROR CONTRACT
// ════════════════════════════════════════════

// Thrown by tool handlers (and by err() from storage.php). The dispatcher
// turns it into a tool result with isError: true, so the model sees the text.
class McpToolError extends RuntimeException {}

// storage.php calls err() on failure; in MCP context that becomes a tool error.
function err($msg, $code = 400) {
    throw new McpToolError((string)$msg, (int)$code);
}

// Part of the entry-point contract, but tools return arrays instead.
function ok($data = []) {
    throw new LogicException('ok() is not available in mcp.php; return an array from the tool handler');
}

const MCP_SERVER_VERSION   = '1.0.0';
const MCP_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']; // newest first
const MCP_MAX_BODY_BYTES   = 16 * 1024 * 1024; // base64 image (10 MB) + JSON overhead
const MCP_REQUIRED         = "\0mcp-required\0"; // sentinel default for mcpArg()

// Block types the viewer (index.php renderBlock) and the editor tools support.
const MCP_BLOCK_TYPES = ['paragraph', 'header', 'list', 'checklist', 'quote', 'code', 'delimiter',
                         'table', 'image', 'warning', 'timeline', 'collapse', 'video', 'cards'];

// ════════════════════════════════════════════
//  2. SHARED HELPERS (used by all tools)
// ════════════════════════════════════════════

// array_is_list() for PHP 7.4 (README promises 7.4+). [] counts as a list.
function mcpIsList(array $a): bool {
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) return false; }
    return true;
}

// mb_* with a byte-based fallback for hosts without mbstring.
function mcpStrlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function mcpSubstr(string $s, int $start, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
}

// ── Arguments ──────────────────────────────
// Read and type-check one tool argument. $type: 'string' | 'int' | 'number' |
// 'bool' | 'array' (JSON list) | 'object' (JSON object) | 'any'. Prefix with
// '?' to accept an explicit null (returned as null). Otherwise null counts as
// missing: the default is returned, or McpToolError when the argument is
// required (no $default given). Lenient: numeric strings for int/number,
// "true"/"false"/0/1 for bool, numbers for string.
// For "null means remove" semantics, test array_key_exists() first.
function mcpArg(array $args, string $name, string $type, $default = MCP_REQUIRED) {
    $nullable = $type !== '' && $type[0] === '?';
    if ($nullable) $type = substr($type, 1);

    if (!array_key_exists($name, $args) || $args[$name] === null) {
        if ($nullable && array_key_exists($name, $args)) return null;
        if ($default === MCP_REQUIRED) throw new McpToolError("Missing required parameter: $name");
        return $default;
    }
    $v = $args[$name];
    $bad = function () use ($name, $type) {
        return new McpToolError("Parameter '$name' must be of type $type");
    };

    switch ($type) {
        case 'string':
            if (is_string($v)) return $v;
            if (is_int($v) || is_float($v)) return (string)$v;
            throw $bad();
        case 'int':
            if (is_int($v)) return $v;
            if (is_float($v) && floor($v) === $v) return (int)$v;
            if (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))) return (int)trim($v);
            throw $bad();
        case 'number':
            if (is_int($v) || is_float($v)) return $v;
            if (is_string($v) && is_numeric(trim($v))) return (float)trim($v);
            throw $bad();
        case 'bool':
            if (is_bool($v)) return $v;
            if ($v === 0 || $v === 1) return (bool)$v;
            if (is_string($v) && in_array(strtolower($v), ['true', 'false', '1', '0'], true)) {
                return in_array(strtolower($v), ['true', '1'], true);
            }
            throw $bad();
        case 'array':
            if (is_array($v) && mcpIsList($v)) return $v;
            throw $bad();
        case 'object':
            if (is_array($v) && ($v === [] || !mcpIsList($v))) return $v;
            throw $bad();
        case 'any':
            return $v;
    }
    throw new LogicException("mcpArg: unknown type '$type'");
}

// ── Sanitizers ─────────────────────────────

// URL check for href/src values. Allows http, https, mailto and (if
// $allowRelative) scheme-less references: /path, path, ./x, ../x, ?page=…,
// #anchor, //host. Anything else (javascript:, data:, vbscript:, …) → ''.
// Entities and control/whitespace characters are resolved before the scheme
// check, so "java&#09;script:" or "javascript&colon;" are caught too.
function mcpSanitizeUrl(string $url, bool $allowRelative = true): string {
    $url = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '');
    if ($url === '' || strlen($url) > 2048) return '';
    $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $probe) ?? '';
    if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $probe, $m)) {
        return in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true) ? $url : '';
    }
    // No scheme in the decoded form, but a colon before any / ? # could still
    // be read as a scheme by a browser in some edge case → reject.
    if (preg_match('/^[^\/?#]*:/', $probe)) return '';
    return $allowRelative ? $url : '';
}

// Allowed inline tags and their attributes (see plan §2).
const MCP_INLINE_TAGS = [
    'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
    'mark' => ['class'], 'code' => ['class'], 'br' => [],
    'a' => ['href', 'target', 'rel', 'data-page-id'],
];

// Whitelist sanitizer for EditorJS inline HTML (paragraph text, list items,
// table cells, callout title/message, …). Every "<…" sequence is either
// rebuilt as an allowed tag (allowed attributes only, values re-escaped,
// href via mcpSanitizeUrl, class only cdx-marker / inline-code) or escaped
// completely, so no raw "<" survives. Existing HTML entities are kept.
function mcpSanitizeInlineHtml(string $html): string {
    if ($html === '') return '';
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html) ?? '';
    return preg_replace_callback('/<[^>]*>?/', function ($m) {
        $raw = $m[0];
        $escaped = str_replace(['<', '>'], ['&lt;', '&gt;'], $raw);
        if (!preg_match('/^<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)(.*?)>$/s', $raw, $p)) return $escaped;
        $tag = strtolower($p[2]);
        if (!array_key_exists($tag, MCP_INLINE_TAGS)) return $escaped;
        $rest = $p[3];
        // The tag name must end here (no "<bfoo>" read as <b>).
        if ($rest !== '' && !preg_match('/^[\s\/]/', $rest)) return $escaped;
        if ($p[1] === '/') return $tag === 'br' ? '' : "</$tag>";
        if ($tag === 'br') return '<br>';

        $attrs = [];
        preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/s',
            $rest, $am, PREG_SET_ORDER);
        foreach ($am as $a) {
            $name = strtolower($a[1]);
            if (!in_array($name, MCP_INLINE_TAGS[$tag], true) || isset($attrs[$name])) continue;
            $val = ($a[2] ?? '') . ($a[3] ?? '') . ($a[4] ?? ''); // only one group matches
            $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'href') {
                $val = mcpSanitizeUrl($val, true);
            } elseif ($name === 'class') {
                $want = $tag === 'mark' ? 'cdx-marker' : 'inline-code';
                $val = in_array($want, preg_split('/\s+/', trim($val)) ?: [], true) ? $want : '';
            } elseif ($name === 'target') {
                $val = in_array(strtolower(trim($val)), ['_blank', '_self'], true) ? strtolower(trim($val)) : '';
            } elseif ($name === 'rel') {
                $ok = array_intersect(preg_split('/\s+/', strtolower(trim($val))) ?: [],
                                      ['noopener', 'noreferrer', 'nofollow']);
                $val = implode(' ', array_unique($ok));
            } elseif ($name === 'data-page-id') {
                $val = substr(sanitizePageId($val), 0, 60);
            }
            if ($val !== '') $attrs[$name] = $val;
        }
        // The editor/importer always mark these spans with their class.
        if ($tag === 'mark') $attrs['class'] = 'cdx-marker';
        if ($tag === 'code') $attrs['class'] = 'inline-code';
        if ($tag === 'a' && ($attrs['target'] ?? '') === '_blank' && empty($attrs['rel'])) $attrs['rel'] = 'noopener';

        $out = '<' . $tag;
        foreach ($attrs as $k => $v) {
            $out .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }
        return $out . '>';
    }, $html) ?? '';
}

// Plain-text field (timeline, collapse, cards, image caption, titles …):
// removes anything that looks like a tag and control chars (keeps \n), trims,
// optionally cuts to $max characters. The viewer escapes these fields, but
// the editor may put some of them into innerHTML, so tags never get stored.
function mcpSanitizeText($text, int $max = 0): string {
    if (!is_string($text)) $text = is_scalar($text) ? (string)$text : '';
    $text = preg_replace('/<\/?[a-zA-Z!?][^>]*>?/', '', $text) ?? '';
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', str_replace("\r\n", "\n", $text)) ?? '';
    $text = trim($text);
    if ($max > 0 && mcpStrlen($text) > $max) $text = rtrim(mcpSubstr($text, 0, $max));
    return $text;
}

// Font Awesome icon class (rendered unescaped into class="fa-solid …").
// "house" → "fa-house"; anything not matching ^fa-[a-z0-9-]{1,40}$ → $fallback.
function mcpSanitizeIcon(?string $icon, string $fallback = 'fa-file'): string {
    $icon = strtolower(trim((string)$icon));
    $icon = preg_replace('/^fa-(solid|regular|brands)\s+/', '', $icon) ?? '';
    if ($icon === '') return $fallback;
    if (strncmp($icon, 'fa-', 3) !== 0) $icon = 'fa-' . $icon;
    return preg_match('/^fa-[a-z0-9-]{1,40}$/', $icon) ? $icon : $fallback;
}

// Page cover. null/false/'' → null (no cover). Valid shapes:
//   { type: "color", value: "<css color or gradient>" }
//   { type: "image", value: "<url>", fit?: "cover"|"contain", position?: "50% 50%" }
// Invalid input → McpToolError (so the agent can fix it).
function mcpSanitizeCover($cover): ?array {
    if ($cover === null || $cover === false || $cover === '' || $cover === []) return null;
    if (!is_array($cover)) throw new McpToolError('cover must be null or an object {type, value}');
    $type = $cover['type'] ?? '';
    $value = is_string($cover['value'] ?? null) ? trim($cover['value']) : '';
    if ($type === 'color') {
        if (!preg_match('/^[#a-zA-Z0-9(),.%\s-]{1,200}$/', $value)) {
            throw new McpToolError('cover.value for type "color" must be a CSS color or gradient, e.g. "#7c3aed" or "linear-gradient(135deg,#f97316,#7c3aed)"');
        }
        return ['type' => 'color', 'value' => $value];
    }
    if ($type === 'image') {
        $url = mcpSanitizeUrl($value, true);
        if ($url === '' || stripos($url, 'mailto:') === 0) {
            throw new McpToolError('cover.value for type "image" must be an http(s) URL or a path like /images/img_….png (use upload_image first)');
        }
        $fit = in_array($cover['fit'] ?? '', ['cover', 'contain'], true) ? $cover['fit'] : 'cover';
        $pos = is_string($cover['position'] ?? null) && preg_match('/^\d{1,3}% \d{1,3}%$/', $cover['position'])
            ? $cover['position'] : '50% 50%';
        return ['type' => 'image', 'value' => $url, 'fit' => $fit, 'position' => $pos];
    }
    throw new McpToolError('cover.type must be "color" or "image"');
}

// ── Block normalizer ───────────────────────

// Turn YouTube/Vimeo watch links into embed URLs (like VideoTool._toEmbed).
function mcpVideoEmbedUrl(string $url): string {
    $url = mcpSanitizeUrl($url, false);
    if ($url === '' || !preg_match('#^https?://#i', $url)) return '';
    if (preg_match('#(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/)([\w-]+)#', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('#vimeo\.com/(\d+)#', $url, $m) && stripos($url, 'player.vimeo.com') === false) return 'https://player.vimeo.com/video/' . $m[1];
    return $url;
}

function mcpNormalizeListItems($items, string $path, int $depth = 0): array {
    if (!is_array($items)) throw new McpToolError("$path must be an array");
    if ($depth > 10) throw new McpToolError("$path: lists may be nested at most 10 levels deep");
    $out = [];
    foreach (array_values($items) as $i => $it) {
        if (is_string($it) || is_int($it) || is_float($it)) {
            $out[] = ['content' => mcpSanitizeInlineHtml((string)$it), 'items' => []];
            continue;
        }
        if (!is_array($it)) throw new McpToolError("{$path}[$i] must be a string or {content, items}");
        $content = $it['content'] ?? ($it['text'] ?? '');
        $out[] = [
            'content' => mcpSanitizeInlineHtml(is_scalar($content) ? (string)$content : ''),
            'items'   => isset($it['items']) ? mcpNormalizeListItems($it['items'], "{$path}[$i].items", $depth + 1) : [],
        ];
    }
    return $out;
}

// Validate + normalize one block's data for its type. $path is used in errors.
function mcpNormalizeBlockData(string $type, array $d, string $path): array {
    $str  = function ($v) { return is_scalar($v) ? (string)$v : ''; };
    $html = function ($v) use ($str) { return mcpSanitizeInlineHtml($str($v)); };
    $list = function ($v, $what) use ($path) {
        if ($v === null) return [];
        if (!is_array($v) || !mcpIsList($v)) throw new McpToolError("$path.data.$what must be an array");
        return $v;
    };

    switch ($type) {
        case 'paragraph':
            return ['text' => $html($d['text'] ?? '')];

        case 'header':
            $level = (int)($d['level'] ?? 2);
            return ['text' => $html($d['text'] ?? ''), 'level' => max(1, min(3, $level ?: 2))];

        case 'list':
            return [
                'style' => ($d['style'] ?? '') === 'ordered' ? 'ordered' : 'unordered',
                'items' => mcpNormalizeListItems($d['items'] ?? [], "$path.data.items"),
            ];

        case 'checklist':
            $items = [];
            foreach ($list($d['items'] ?? [], 'items') as $it) {
                if (is_string($it)) { $items[] = ['text' => $html($it), 'checked' => false]; continue; }
                if (!is_array($it)) throw new McpToolError("$path.data.items entries must be {text, checked}");
                $items[] = ['text' => $html($it['text'] ?? ''), 'checked' => !empty($it['checked'])];
            }
            return ['items' => $items];

        case 'quote':
            $out = ['text' => $html($d['text'] ?? ''), 'caption' => $html($d['caption'] ?? '')];
            if (in_array($d['alignment'] ?? '', ['left', 'center'], true)) $out['alignment'] = $d['alignment'];
            return $out;

        case 'code':
            // Stored raw; the viewer escapes it and detects the language itself.
            return ['code' => str_replace("\r\n", "\n", $str($d['code'] ?? ''))];

        case 'delimiter':
            return ['style' => in_array($d['style'] ?? '', ['stars', 'line', 'dashed', 'dots'], true) ? $d['style'] : 'stars'];

        case 'table':
            $rows = [];
            $width = 0;
            foreach ($list($d['content'] ?? [], 'content') as $r => $row) {
                if (!is_array($row) || !mcpIsList($row)) throw new McpToolError("$path.data.content[$r] must be an array of cells");
                $cells = [];
                foreach ($row as $cell) {
                    $cells[] = $html(str_replace(["\r\n", "\n"], '<br>', $str($cell)));
                }
                $rows[] = $cells;
                $width = max($width, count($cells));
            }
            // EditorJS' table tool expects a rectangular grid.
            foreach ($rows as &$cells) $cells = array_pad($cells, $width, '');
            unset($cells);
            return ['withHeadings' => !empty($d['withHeadings']), 'content' => $rows];

        case 'image':
            $url = mcpSanitizeUrl($str($d['url'] ?? ''), true);
            if ($url === '' || stripos($url, 'mailto:') === 0) {
                throw new McpToolError("$path.data.url must be an http(s) URL or a path like /images/img_….png (upload with upload_image first)");
            }
            $out = [
                'url'            => $url,
                'caption'        => mcpSanitizeText($d['caption'] ?? '', 500),
                'stretched'      => !empty($d['stretched']),
                'withBorder'     => !empty($d['withBorder']),
                'withBackground' => !empty($d['withBackground']),
            ];
            if (isset($d['filename']) && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $str($d['filename']))) $out['filename'] = $str($d['filename']);
            return $out;

        case 'warning':
            return [
                'type'    => in_array($d['type'] ?? '', ['info', 'tip', 'warning', 'danger'], true) ? $d['type'] : 'info',
                'title'   => $html($d['title'] ?? ''),
                'message' => $html($d['message'] ?? ''),
            ];

        case 'timeline':
            $items = [];
            foreach ($list($d['items'] ?? [], 'items') as $it) {
                if (!is_array($it)) throw new McpToolError("$path.data.items entries must be {date, title, desc}");
                $items[] = [
                    'date'  => mcpSanitizeText($it['date'] ?? '', 100),
                    'title' => mcpSanitizeText($it['title'] ?? '', 300),
                    'desc'  => mcpSanitizeText($it['desc'] ?? '', 5000),
                ];
            }
            return ['numbered' => !empty($d['numbered']), 'items' => $items];

        case 'collapse':
            return [
                'title' => mcpSanitizeText($d['title'] ?? '', 300),
                'body'  => mcpSanitizeText($d['body'] ?? '', 20000),
                'open'  => !empty($d['open']),
            ];

        case 'video':
            $embed = $str($d['embedUrl'] ?? '');
            $src   = $str($d['src'] ?? '');
            $orig  = $str($d['url'] ?? '');
            if ($embed === '' && $src === '' && $orig !== '') $embed = $orig; // a plain YouTube/Vimeo link
            $out = ['url' => '', 'embedUrl' => '', 'src' => '', 'filename' => '', 'mime' => ''];
            if ($src !== '') {
                $out['src'] = mcpSanitizeUrl($src, true);
                if ($out['src'] === '' || stripos($out['src'], 'mailto:') === 0) {
                    throw new McpToolError("$path.data.src must be a path like /videos/vid_….mp4 or an http(s) URL");
                }
                $out['filename'] = mcpSanitizeText($d['filename'] ?? basename(parse_url($out['src'], PHP_URL_PATH) ?: ''), 200);
                $mime = $str($d['mime'] ?? '');
                $out['mime'] = in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'], true) ? $mime : '';
                return $out;
            }
            $out['embedUrl'] = mcpVideoEmbedUrl($embed);
            if ($out['embedUrl'] === '') throw new McpToolError("$path.data needs embedUrl (YouTube/Vimeo, https) or src (uploaded video)");
            $out['url'] = $orig !== '' ? mcpSanitizeUrl($orig, false) : $out['embedUrl'];
            return $out;

        case 'cards':
            $cols = (int)($d['cols'] ?? 3);
            $cards = [];
            foreach ($list($d['cards'] ?? [], 'cards') as $c => $card) {
                if (!is_array($card)) throw new McpToolError("$path.data.cards[$c] must be {icon, title, desc, link}");
                $item = [
                    'icon'  => mcpSanitizeIcon($str($card['icon'] ?? ''), 'fa-star'),
                    'title' => mcpSanitizeText($card['title'] ?? '', 200),
                    'desc'  => mcpSanitizeText($card['desc'] ?? '', 1000),
                ];
                $link = trim($str($card['link'] ?? ''));
                if (preg_match('/^\??page=(.+)$/', $link, $m)) $link = rawurldecode($m[1]);
                if ($link !== '') {
                    if (preg_match('#^https?://#i', $link)) {
                        $link = mcpSanitizeUrl($link, false);
                    } elseif (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $link)) {
                        $link = '';
                    }
                    if ($link === '') throw new McpToolError("$path.data.cards[$c].link must be an https:// URL or a page id");
                    $item['link'] = $link;
                }
                $cards[] = $item;
            }
            return ['cols' => in_array($cols, [2, 3, 4], true) ? $cols : 3, 'cards' => $cards];
    }
    throw new LogicException("mcpNormalizeBlockData: unhandled type $type");
}

// Validate and normalize a list of EditorJS blocks as sent by an agent.
// Unknown types → McpToolError listing the allowed types. Block ids that look
// like EditorJS ids are kept; other keys (tunes, index, …) are dropped.
function mcpNormalizeBlocks(array $blocks): array {
    if (!mcpIsList($blocks)) throw new McpToolError('blocks must be an array of {type, data}');
    $out = [];
    foreach ($blocks as $i => $b) {
        $path = "blocks[$i]";
        if (!is_array($b)) throw new McpToolError("$path must be an object {type, data}");
        $type = is_string($b['type'] ?? null) ? $b['type'] : '';
        if (!in_array($type, MCP_BLOCK_TYPES, true)) {
            throw new McpToolError("$path: unknown block type '" . mcpSanitizeText($type, 40) . "'. Allowed: " . implode(', ', MCP_BLOCK_TYPES) . '. See get_content_guide.');
        }
        $data = $b['data'] ?? [];
        if (!is_array($data)) throw new McpToolError("$path.data must be an object");
        $block = [];
        if (is_string($b['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $b['id'])) $block['id'] = $b['id'];
        $block['type'] = $type;
        $block['data'] = mcpNormalizeBlockData($type, $data, $path);
        $out[] = $block;
    }
    return $out;
}

// ── URLs ───────────────────────────────────

// Same logic as docsIsHttpsRequest() in access_guard.php (not loaded here).
function mcpIsHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
    }
    return false;
}

// Absolute URL of the installation directory, without trailing slash,
// e.g. "https://docs.example.com" or "http://127.0.0.1:8092/docs".
function mcpBaseUrl(): string {
    $scheme = mcpIsHttpsRequest() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $host   = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)$host) ?: 'localhost';
    $dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/mcp.php'));
    $dir    = ($dir === '.' || $dir === '/') ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

// Root-relative base path for stored asset URLs ("/images/…"), same formula
// as upload_image in api.php: dirname(PHP_SELF).
function mcpBasePath(): string {
    return rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/mcp.php')), '/');
}

function mcpPageUrls(string $id): array {
    $base = mcpBaseUrl();
    $q = rawurlencode($id);
    return ['view' => "$base/index.php?page=$q", 'edit' => "$base/editor.php?page=$q"];
}

// ── Pages ──────────────────────────────────

// Keys of a stored page, in file order. mcpSavePage() writes these plus any
// other key the stored file already has (forward compatibility).
const MCP_PAGE_KEYS = ['id', 'spaceId', 'parentId', 'title', 'icon', 'subtitle', 'section',
                       'order', 'updatedAt', 'cover', 'draft', 'content'];
// Client-side / response-only fields that must never be persisted.
const MCP_PAGE_TRANSIENT_KEYS = ['ratingStats', 'ratingAverage', 'ratingCount', 'ratingCsvAvailable',
                                 'urls', 'children', 'breadcrumb', 'url'];

// All pages including content, keyed by id (= file name), in file order.
function mcpLoadAllPages(): array {
    $pages = [];
    foreach (pageJsonFiles() as $file) {
        $page = jsonRead($file);
        if (!is_array($page)) continue;
        $id = basename($file, '.json');
        $page['id'] = $id;
        $pages[$id] = $page;
    }
    return $pages;
}

// Load one page (with content) or throw "Page not found".
function mcpLoadPage(string $id): array {
    $clean = sanitizePageId($id);
    $page = $clean !== '' && $clean === $id ? jsonRead(pageFilePath($clean)) : null;
    if (!is_array($page)) throw new McpToolError("Page not found: $id");
    $page['id'] = $clean;
    return $page;
}

// Persist one page to data/pages/<id>.json and return what was written.
// Writes the known keys (MCP_PAGE_KEYS) from $page, falling back to the
// stored file / defaults, plus unknown keys the stored file already has.
// Never writes "_…" keys or MCP_PAGE_TRANSIENT_KEYS. Does NOT touch
// updatedAt or content.time: callers set them for content/metadata edits and
// leave them alone for pure reordering (like persistPages() in the editor).
function mcpSavePage(array $page): array {
    $id = (string)($page['id'] ?? '');
    if ($id === '' || sanitizePageId($id) !== $id || strlen($id) > 60) {
        throw new McpToolError('Invalid page id (allowed: a-z, 0-9, "-", "_", max. 60 characters)');
    }
    $stored = jsonRead(pageFilePath($id));
    if (!is_array($stored)) $stored = [];
    $defaults = [
        'id' => $id, 'spaceId' => null, 'parentId' => null, 'title' => '', 'icon' => 'fa-file',
        'subtitle' => '', 'section' => null, 'order' => 0, 'updatedAt' => date('c'), 'cover' => null,
        'content' => ['time' => (int)round(microtime(true) * 1000), 'blocks' => []],
    ];

    $out = [];
    foreach (MCP_PAGE_KEYS as $k) {
        if (array_key_exists($k, $page))       $out[$k] = $page[$k];
        elseif (array_key_exists($k, $stored)) $out[$k] = $stored[$k];
        elseif (array_key_exists($k, $defaults)) $out[$k] = $defaults[$k];
    }
    foreach ($stored as $k => $v) {
        if (array_key_exists($k, $out) || $k === '' || $k[0] === '_' || in_array($k, MCP_PAGE_TRANSIENT_KEYS, true)) continue;
        $out[$k] = array_key_exists($k, $page) ? $page[$k] : $v;
    }
    $out['id'] = $id;
    $out['parentId'] = ($out['parentId'] ?? '') === '' ? null : $out['parentId'];
    if (!is_array($out['content'] ?? null)) $out['content'] = $defaults['content'];
    if (!is_array($out['content']['blocks'] ?? null)) $out['content']['blocks'] = [];
    // Response-only per-block "index" (get_page) must not end up on disk.
    foreach ($out['content']['blocks'] as &$b) { if (is_array($b)) unset($b['index']); }
    unset($b);

    if (!jsonWrite(pageFilePath($id), $out)) throw new McpToolError("Failed to write page $id");
    return $out;
}

// Siblings = pages with the same spaceId and parentId (null = root level).
// Sorted by current order (unknown order last, stable). Renumbers them to
// 0…n-1; with $orderedIds those ids come first in that order, the rest keep
// their relative order behind them. Returns the pages whose order changed,
// keyed by id, with the new order applied. The caller merges them into its
// $pages and saves each with mcpSavePage() — plus any page it moved itself
// (changed parentId/spaceId/section), even if its order stayed the same.
function mcpReindexSiblings(array $pages, $spaceId, $parentId, ?array $orderedIds = null): array {
    $parentId = ($parentId === '' ? null : $parentId);
    $siblings = array_values(array_filter($pages, function ($p) use ($spaceId, $parentId) {
        $pp = $p['parentId'] ?? null;
        return ($p['spaceId'] ?? null) === $spaceId && ($pp === '' ? null : $pp) === $parentId;
    }));
    usort($siblings, function ($a, $b) {
        $oa = is_numeric($a['order'] ?? null) ? (float)$a['order'] : INF;
        $ob = is_numeric($b['order'] ?? null) ? (float)$b['order'] : INF;
        return $oa <=> $ob;
    });
    if ($orderedIds !== null) {
        $rank = array_flip(array_values(array_map('strval', $orderedIds)));
        $first = []; $rest = [];
        foreach ($siblings as $p) {
            if (isset($rank[$p['id']])) $first[$rank[$p['id']]] = $p; else $rest[] = $p;
        }
        ksort($first);
        $siblings = array_merge(array_values($first), $rest);
    }
    $changed = [];
    foreach ($siblings as $i => $p) {
        if (($p['order'] ?? null) !== $i) {
            $p['order'] = $i;
            $changed[$p['id']] = $p;
        }
    }
    return $changed;
}

// ── Misc ───────────────────────────────────

function mcpReadSpaces(): array {
    $spaces = jsonRead(DATA_DIR . '/spaces.json', []);
    return is_array($spaces) ? array_values(array_filter($spaces, 'is_array')) : [];
}

function mcpReadSettings(): array {
    $settings = jsonRead(DATA_DIR . '/settings.json', []);
    return is_array($settings) ? $settings : [];
}

function mcpSiteName(): string {
    $name = trim((string)(mcpReadSettings()['siteName'] ?? ''));
    return $name !== '' ? $name : 'Webstudio Docs';
}

// Uploaded image files in images/ (top level only; og/ is generated).
function mcpImageFiles(): array {
    $files = [];
    foreach (glob(IMAGES_DIR . '/*') ?: [] as $f) {
        $name = basename($f);
        if (is_file($f) && $name[0] !== '.' && $name !== 'index.php') $files[] = $f;
    }
    return $files;
}

// ════════════════════════════════════════════
//  3. TOOL REGISTRY
// ════════════════════════════════════════════

$MCP_TOOLS = [];

// Register a tool. $def: title, description, inputSchema (JSON Schema object;
// defaults to no parameters), annotations (readOnlyHint, destructiveHint,
// idempotentHint, openWorldHint). $handler: function (array $args): array|string.
// The returned array is sent as compact JSON text; a string is sent as is.
// Throw McpToolError (or call err()) for errors the model should see.
function mcpTool(string $name, array $def, callable $handler): void {
    global $MCP_TOOLS;
    $schema = $def['inputSchema'] ?? [];
    if (!isset($schema['type'])) $schema = ['type' => 'object'] + $schema;
    $MCP_TOOLS[$name] = [
        'def' => [
            'name'        => $name,
            'title'       => $def['title'] ?? $name,
            'description' => $def['description'] ?? '',
            'inputSchema' => mcpSchemaObjects($schema),
            'annotations' => (object)($def['annotations'] ?? []),
        ],
        'handler' => $handler,
    ];
}

// json_encode turns empty PHP arrays into [], but JSON Schema needs
// "properties": {} — fix that recursively.
function mcpSchemaObjects(array $schema): array {
    foreach ($schema as $k => $v) {
        if (($k === 'properties' || $k === 'patternProperties' || $k === '$defs') && $v === []) {
            $schema[$k] = new stdClass();
        } elseif (is_array($v)) {
            $schema[$k] = mcpSchemaObjects($v);
        }
    }
    if (($schema['type'] ?? null) === 'object' && !isset($schema['properties'])) $schema['properties'] = new stdClass();
    return $schema;
}

// ════════════════════════════════════════════
//  4. TOOLS
//  Append new tools below, one mcpTool() call each, grouped by AP:
//  read tools (AP4), page writes (AP5), spaces/settings/media (AP6).
// ════════════════════════════════════════════

// ── Read tools ─────────────────────────────

mcpTool('get_site', [
    'title'       => 'Get site overview',
    'description' => 'Overview of this documentation site: name, URLs, spaces (top-level tabs) with page counts, total pages and images, and the raw upload endpoint. Call this first.',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
], function (array $args): array {
    $base = mcpBaseUrl();
    $pages = mcpLoadAllPages();
    $perSpace = [];
    foreach ($pages as $p) {
        $sid = (string)($p['spaceId'] ?? '');
        $perSpace[$sid] = ($perSpace[$sid] ?? 0) + 1;
    }
    $spaces = array_map(function ($s) use ($perSpace) {
        $id = (string)($s['id'] ?? '');
        return ['id' => $id, 'name' => (string)($s['name'] ?? ''), 'icon' => (string)($s['icon'] ?? ''), 'pageCount' => $perSpace[$id] ?? 0];
    }, mcpReadSpaces());
    return [
        'siteName'       => mcpSiteName(),
        'baseUrl'        => $base,
        'viewerUrl'      => "$base/index.php",
        'editorUrl'      => "$base/editor.php",
        'spaces'         => $spaces,
        'pageCount'      => count($pages),
        'imagesCount'    => count(mcpImageFiles()),
        'uploadEndpoint' => "$base/mcp.php?upload=image&filename=<name>",
    ];
});

// ════════════════════════════════════════════
//  5. RAW UPLOAD HOOK
//  mcp.php?upload=image|video&filename=… (POST/PUT, body = file, bearer auth).
//  Called after authentication; must send a plain JSON response (no JSON-RPC).
//  AP6 replaces the body of this function.
// ════════════════════════════════════════════

function mcpHandleRawUpload(string $kind): void {
    mcpSendJson(['ok' => false, 'error' => 'Raw upload is not available yet'], 501);
}

// ════════════════════════════════════════════
//  6. JSON-RPC DISPATCHER + HTTP ENTRY POINT
// ════════════════════════════════════════════

function mcpSendJson($data, int $status = 200, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function mcpRpcError($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcpInstructions(): string {
    $base = mcpBaseUrl();
    return implode("\n", [
        'This server manages the "' . mcpSiteName() . '" documentation site (Webstudio Docs) with admin rights.',
        'Structure: spaces (top-level tabs) contain pages; pages form a tree (parent_id) and root pages can be grouped into named sections.',
        'Start with get_site and list_pages to learn the structure, use search_pages / get_page to read content.',
        'Page content is a list of EditorJS blocks (paragraph, header, list, table, image, warning, …), not Markdown.',
        'Read get_content_guide before writing content: it lists every block type, its data fields and the allowed inline HTML.',
        'Everything you write is sanitized: disallowed HTML is escaped, unsafe URLs are removed.',
        'To avoid overwriting edits made in the meantime, pass the updatedAt you read as if_updated_at when updating a page.',
        'Deleting pages moves them to the trash on the server (data/trash), it is not a hard delete.',
        'Images: upload first, then reference the returned url in an image block or cover.',
        'With shell access, upload local files directly instead of base64:',
        '  curl -H "Authorization: Bearer <TOKEN>" --data-binary @image.png "' . $base . '/mcp.php?upload=image&filename=image.png"',
        'Videos: same endpoint with upload=video (mp4/webm/ogg), or a video block with a YouTube/Vimeo embedUrl.',
        'Pages are public at ' . $base . '/index.php?page=<id> unless draft is true; humans edit at ' . $base . '/editor.php.',
        'If a human has the editor open, ask them to reload it after your changes.',
    ]);
}

function mcpCallTool(array $params): array {
    global $MCP_TOOLS;
    $name = $params['name'] ?? null;
    if (!is_string($name) || !isset($MCP_TOOLS[$name])) {
        throw new InvalidArgumentException('Unknown tool: ' . (is_string($name) ? $name : '(missing name)'));
    }
    $args = $params['arguments'] ?? [];
    $text = function ($t, bool $isError = false) {
        $r = ['content' => [['type' => 'text', 'text' => $t]]];
        if ($isError) $r['isError'] = true;
        return $r;
    };
    if (!is_array($args) || ($args !== [] && mcpIsList($args))) {
        return $text('Invalid arguments: expected a JSON object', true);
    }
    try {
        $result = ($MCP_TOOLS[$name]['handler'])($args);
    } catch (McpToolError $e) {
        return $text($e->getMessage(), true);
    } catch (Throwable $e) {
        error_log('[mcp.php] ' . $name . ': ' . $e);
        return $text('Internal error in ' . $name . ': ' . $e->getMessage(), true);
    }
    if (is_string($result)) return $text($result);
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $text($json === false ? 'null' : $json);
}

// Handle one decoded JSON-RPC message. Returns a response array, or null for
// notifications and client responses (which get no answer).
function mcpHandleMessage($msg): ?array {
    global $MCP_TOOLS;
    if (!is_array($msg) || ($msg !== [] && mcpIsList($msg))) return mcpRpcError(null, -32600, 'Invalid Request');
    $hasId = array_key_exists('id', $msg);
    $id = $msg['id'] ?? null;
    if (!isset($msg['method'])) {
        // A response from the client (to a request we never send) → ignore.
        if (array_key_exists('result', $msg) || array_key_exists('error', $msg)) return null;
        return mcpRpcError($hasId ? $id : null, -32600, 'Invalid Request');
    }
    if (!$hasId) return null; // notification (e.g. notifications/initialized)
    if (!is_string($id) && !is_int($id) && !is_float($id) && $id !== null) return mcpRpcError(null, -32600, 'Invalid Request: bad id');
    if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'])) return mcpRpcError($id, -32600, 'Invalid Request');
    $params = $msg['params'] ?? [];
    if (!is_array($params)) return mcpRpcError($id, -32602, 'Invalid params');

    $result = null;
    try {
        switch ($msg['method']) {
            case 'initialize':
                $requested = $params['protocolVersion'] ?? null;
                $version = in_array($requested, MCP_PROTOCOL_VERSIONS, true) ? $requested : MCP_PROTOCOL_VERSIONS[0];
                $result = [
                    'protocolVersion' => $version,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'webstudio-docs', 'title' => mcpSiteName(), 'version' => MCP_SERVER_VERSION],
                    'instructions'    => mcpInstructions(),
                ];
                break;
            case 'ping':
                $result = new stdClass();
                break;
            case 'tools/list':
                $result = ['tools' => array_values(array_map(function ($t) { return $t['def']; }, $MCP_TOOLS))];
                break;
            case 'tools/call':
                $result = mcpCallTool($params);
                break;
            case 'resources/list':
                $result = ['resources' => []];
                break;
            case 'resources/templates/list':
                $result = ['resourceTemplates' => []];
                break;
            case 'prompts/list':
                $result = ['prompts' => []];
                break;
            default:
                return mcpRpcError($id, -32601, 'Method not found: ' . mcpSanitizeText($msg['method'], 100));
        }
    } catch (InvalidArgumentException $e) {
        return mcpRpcError($id, -32602, $e->getMessage());
    } catch (Throwable $e) {
        error_log('[mcp.php] ' . $msg['method'] . ': ' . $e);
        return mcpRpcError($id, -32603, 'Internal error');
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

// Bearer token from the Authorization header. Apache/FPM may only expose it
// as REDIRECT_HTTP_AUTHORIZATION (see .htaccess) or via getallheaders().
function mcpBearerToken(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $header = (string)$v; break; }
        }
    }
    return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string)$header, $m) ? $m[1] : '';
}

function mcpMain(): void {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $upload = isset($_GET['upload']) ? (string)$_GET['upload'] : null;
    $allowed = $upload !== null ? ['POST', 'PUT'] : ['POST'];

    // 1. Method
    if ($method === 'OPTIONS') {
        header('Allow: ' . implode(', ', $allowed) . ', OPTIONS');
        http_response_code(204);
        return;
    }
    if (!in_array($method, $allowed, true)) {
        mcpSendJson(mcpRpcError(null, -32000, 'Method not allowed: use POST'), 405, ['Allow: ' . implode(', ', $allowed)]);
        return;
    }

    // 2. Auth
    try {
        ensureDirs();
        ensureProtectedDirectoryIndex(TRASH_DIR);
        ensureProtectedDirectoryIndex(PAGES_TRASH_DIR);
        ensureProtectedDirectoryIndex(TMP_UPLOADS_DIR);
    } catch (McpToolError $e) {
        mcpSendJson(mcpRpcError(null, -32603, $e->getMessage()), 500);
        return;
    }
    $token = mcpBearerToken();
    if ($token === '' || mcpTokenVerify($token) === null) {
        $hdr = ['WWW-Authenticate: Bearer realm="webstudio-docs"'];
        if ($upload !== null) mcpSendJson(['ok' => false, 'error' => 'Unauthorized'], 401, $hdr);
        else mcpSendJson(mcpRpcError(null, -32001, 'Unauthorized'), 401, $hdr);
        return;
    }

    // 3. Raw upload branch
    if ($upload !== null) {
        try {
            mcpHandleRawUpload($upload);
        } catch (McpToolError $e) {
            $code = $e->getCode();
            mcpSendJson(['ok' => false, 'error' => $e->getMessage()], $code >= 400 && $code < 600 ? $code : 400);
        }
        return;
    }

    // 4. JSON-RPC body
    $raw = stream_get_contents(fopen('php://input', 'rb'), MCP_MAX_BODY_BYTES + 1);
    if ($raw === false) $raw = '';
    if (strlen($raw) > MCP_MAX_BODY_BYTES) {
        mcpSendJson(mcpRpcError(null, -32600, 'Request too large'), 413);
        return;
    }
    $body = json_decode($raw, true);
    if ($raw === '' || json_last_error() !== JSON_ERROR_NONE) {
        mcpSendJson(mcpRpcError(null, -32700, 'Parse error'), 400);
        return;
    }

    // 5. Single message or batch
    if (is_array($body) && mcpIsList($body) && $body !== []) {
        $responses = [];
        foreach ($body as $msg) {
            $r = mcpHandleMessage($msg);
            if ($r !== null) $responses[] = $r;
        }
        if (!$responses) { http_response_code(202); return; }
        mcpSendJson($responses);
        return;
    }
    $response = mcpHandleMessage($body);
    if ($response === null) { http_response_code(202); return; }
    mcpSendJson($response);
}

// CLI scripts can define MCP_NO_MAIN and require this file to test helpers.
if (!defined('MCP_NO_MAIN')) mcpMain();
