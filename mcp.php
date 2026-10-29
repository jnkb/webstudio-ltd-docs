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

// ── Tree / text helpers (read tools, reusable by write tools) ──

// Space by id or McpToolError listing the known spaces.
function mcpRequireSpace(string $id): array {
    $known = [];
    foreach (mcpReadSpaces() as $s) {
        if ((string)($s['id'] ?? '') === $id) return $s;
        $known[] = ($s['id'] ?? '?') . ' (' . ($s['name'] ?? '') . ')';
    }
    throw new McpToolError("Unknown space_id: $id. Known spaces: " . ($known ? implode(', ', $known) : 'none'));
}

// Section group of a root page, like `p.section || ''` in renderNav(): null = no section.
function mcpPageSection(array $p): ?string {
    $s = $p['section'] ?? null;
    return is_string($s) && $s !== '' ? $s : null;
}

// Sort a list of pages like the sidebar: by numeric order (pages without one
// last, as in mcpReindexSiblings), ties keep their input order (stable on 7.4).
function mcpSortPages(array $pages): array {
    $pages = array_values($pages);
    $keyed = [];
    foreach ($pages as $i => $p) {
        $keyed[] = [is_numeric($p['order'] ?? null) ? (float)$p['order'] : INF, $i, $p];
    }
    usort($keyed, function ($a, $b) { return [$a[0], $a[1]] <=> [$b[0], $b[1]]; });
    return array_map(function ($k) { return $k[2]; }, $keyed);
}

// Direct children of a page (same space, parentId = $id), sidebar order.
function mcpChildPages(array $pages, string $id): array {
    $parent = $pages[$id] ?? null;
    $spaceId = $parent['spaceId'] ?? null;
    return mcpSortPages(array_filter($pages, function ($p) use ($id, $spaceId) {
        return ($p['parentId'] ?? null) === $id && ($p['spaceId'] ?? null) === $spaceId;
    }));
}

// Ancestors of a page, root first, as [{id, title}] (cycle-safe).
function mcpBreadcrumb(array $pages, string $id): array {
    $out = [];
    $seen = [$id => true];
    $cur = $pages[$id] ?? null;
    while ($cur && is_string($cur['parentId'] ?? null) && $cur['parentId'] !== '') {
        $pid = $cur['parentId'];
        if (isset($seen[$pid]) || !isset($pages[$pid])) break;
        $seen[$pid] = true;
        $cur = $pages[$pid];
        array_unshift($out, ['id' => $pid, 'title' => (string)($cur['title'] ?? '')]);
    }
    return $out;
}

// Inline HTML → plain text (tags removed, entities decoded, whitespace collapsed).
function mcpPlainText(string $html): string {
    $text = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? '';
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

// Searchable plain text of EditorJS blocks: every string inside block data
// except technical fields (URLs, icons, styles, mime types).
function mcpBlocksPlainText(array $blocks): string {
    $skip = ['url', 'embedUrl', 'src', 'mime', 'link', 'icon', 'style', 'type', 'alignment', 'filename', 'id'];
    $parts = [];
    $walk = function ($v) use (&$walk, &$parts, $skip) {
        if (is_string($v)) { $t = mcpPlainText($v); if ($t !== '') $parts[] = $t; return; }
        if (!is_array($v)) return;
        foreach ($v as $k => $x) {
            if (is_string($k) && in_array($k, $skip, true)) continue;
            $walk($x);
        }
    };
    foreach ($blocks as $b) {
        if (is_array($b) && is_array($b['data'] ?? null)) $walk($b['data']);
    }
    return implode(' ', $parts);
}

function mcpLower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

// Raw JSON text of every page file, keyed by page id (for usage searches).
function mcpPageJsonRaw(): array {
    $raw = [];
    foreach (pageJsonFiles() as $file) {
        $txt = @file_get_contents($file);
        if (is_string($txt)) $raw[basename($file, '.json')] = $txt;
    }
    return $raw;
}

// Ids of pages whose JSON (content or cover) mentions an image file name.
// Pass $raw from mcpPageJsonRaw() when checking several files.
function mcpImageUsedBy(string $filename, ?array $raw = null): array {
    if ($filename === '') return [];
    $raw = $raw ?? mcpPageJsonRaw();
    $ids = [];
    foreach ($raw as $id => $txt) {
        if (strpos($txt, $filename) !== false) $ids[] = (string)$id;
    }
    return $ids;
}

// ── Page write helpers (AP5, reusable by AP6) ──

// Remove diacritics like JS normalize('NFD').replace(/[̀-ͯ]/g, '').
// Without intl a map covers the common Latin letters (incl. de/sk).
function mcpStripDiacritics(string $s): string {
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) return preg_replace('/[\x{0300}-\x{036f}]/u', '', $n) ?? $s;
    }
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (['a' => 'àáâãäåāăą', 'c' => 'çćč', 'd' => 'ď', 'e' => 'èéêëēėęě', 'i' => 'ìíîïīį', 'l' => 'ĺľ',
                  'n' => 'ñńň', 'o' => 'òóôõöōő', 'r' => 'ŕř', 's' => 'śšşș', 't' => 'ťţț', 'u' => 'ùúûüūůűų',
                  'y' => 'ýÿ', 'z' => 'źżž'] as $base => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $c) $map[$c] = $base;
        }
    }
    return strtr($s, $map);
}

// Page id from a title, like pageSlug() in editor.php (without the
// uniqueness loop, see mcpUniquePageId): lowercase, no diacritics, spaces → "-".
function mcpSlugify(string $title): string {
    $s = mcpStripDiacritics(mcpLower($title));
    $s = preg_replace('/[^a-z0-9\s-]/u', '', $s) ?? '';
    $s = preg_replace('/\s+/u', '-', $s) ?? '';
    $s = preg_replace('/-+/', '-', $s) ?? '';
    $s = rtrim(substr(trim($s, '-'), 0, 60), '-');
    return $s !== '' ? $s : 'page';
}

// User-typed page id, like sanitizeManualId() in editor.php.
function mcpSanitizeManualId(string $raw): string {
    $s = mcpStripDiacritics(mcpLower(trim($raw)));
    $s = preg_replace('/\s+/u', '-', $s) ?? '';
    $s = preg_replace('/[^a-z0-9_-]/', '', $s) ?? '';
    $s = preg_replace('/-+/', '-', $s) ?? '';
    $s = preg_replace('/^[-_]+|[-_]+$/', '', $s) ?? '';
    return substr($s, 0, 60);
}

// True if a page id is used or reserved ("<id>_rating" is the rating file of <id>
// and is hidden by pageJsonFiles()).
function mcpPageIdTaken(string $id, array $pages): bool {
    return isset($pages[$id]) || file_exists(pageFilePath($id)) || preg_match('/_rating$/', $id);
}

// $base, or "$base-1", "$base-2", … (first free one, max. 60 characters).
function mcpUniquePageId(string $base, array $pages): string {
    $candidate = $base;
    for ($n = 1; mcpPageIdTaken($candidate, $pages); $n++) {
        $suffix = '-' . $n;
        $candidate = rtrim(substr($base, 0, 60 - strlen($suffix)), '-') . $suffix;
    }
    return $candidate;
}

// Explicit new page id (create_page id, set_page_id new_id). Never renamed
// silently: a malformed id is an error with a suggestion, a used id too.
function mcpRequireNewPageId(string $raw, array $pages, string $param = 'id'): string {
    $raw = trim($raw);
    $shown = mcpSanitizeText($raw, 80);
    $clean = mcpSanitizeManualId($raw);
    if ($clean === '') {
        throw new McpToolError("Invalid $param '$shown': page ids use lowercase a-z, 0-9, \"-\" and \"_\" (max. 60 characters)");
    }
    if ($clean !== $raw) {
        throw new McpToolError("Invalid $param '$shown': page ids use lowercase a-z, 0-9, \"-\" and \"_\" (max. 60 characters, not starting or ending with \"-\"/\"_\"). Did you mean '$clean'?");
    }
    if (preg_match('/_rating$/', $clean)) throw new McpToolError("Invalid $param '$clean': ids ending in \"_rating\" are reserved");
    if (mcpPageIdTaken($clean, $pages)) {
        throw new McpToolError("Page id already exists: $clean. Choose another id" . ($param === 'id' ? ' or omit id to derive one from the title' : '') . '.');
    }
    return $clean;
}

// parentId of a page, null for root pages ('' counts as root, like the editor).
function mcpParentOf(array $p): ?string {
    $pid = $p['parentId'] ?? null;
    return is_string($pid) && $pid !== '' ? $pid : null;
}

// Section argument → stored value: plain text (max. 100), '' / null → null.
function mcpSectionValue($v): ?string {
    if ($v === null) return null;
    if (!is_scalar($v)) throw new McpToolError('section must be a string or null');
    $s = mcpSanitizeText((string)$v, 100);
    return $s === '' ? null : $s;
}

// Icon argument: '' → $default, invalid → McpToolError.
function mcpIconValue($v, string $default = 'fa-file'): string {
    if (!is_string($v)) throw new McpToolError('icon must be a string like "fa-book"');
    if (trim($v) === '') return $default;
    $icon = mcpSanitizeIcon($v, '');
    if ($icon === '') throw new McpToolError('Invalid icon: use a Font Awesome name like "fa-book" or "rocket"');
    return $icon;
}

// All descendants of a page (children, grandchildren, …), any space, cycle-safe.
function mcpDescendantIds(array $pages, string $id): array {
    $byParent = [];
    foreach ($pages as $pid => $p) {
        $parent = mcpParentOf($p);
        if ($parent !== null) $byParent[$parent][] = (string)$pid;
    }
    $out = [];
    $seen = [$id => true];
    $queue = [$id];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($byParent[$cur] ?? [] as $child) {
            if (isset($seen[$child])) continue;
            $seen[$child] = true;
            $out[] = $child;
            $queue[] = $child;
        }
    }
    return $out;
}

// Ids of the siblings (same spaceId, same parentId; null = root) in sidebar
// order, without $exclude.
function mcpSiblingIds(array $pages, $spaceId, ?string $parentId, array $exclude = []): array {
    $ex = array_flip($exclude);
    $sib = array_filter($pages, function ($p) use ($spaceId, $parentId, $ex) {
        return ($p['spaceId'] ?? null) === $spaceId && mcpParentOf($p) === $parentId && !isset($ex[$p['id']]);
    });
    return array_map(function ($p) { return (string)$p['id']; }, mcpSortPages($sib));
}

// Default index for a root page with $section among $siblingIds (ordered,
// moved pages already removed): behind the last page with the same section
// (null = no section), or the end if there is none (plan §2).
function mcpSectionInsertIndex(array $pages, array $siblingIds, ?string $section): int {
    $idx = count($siblingIds);
    foreach (array_values($siblingIds) as $i => $sid) {
        if (isset($pages[$sid]) && mcpPageSection($pages[$sid]) === $section) $idx = $i + 1;
    }
    return $idx;
}

// Put the pages $ids (in that order) at $index among their siblings
// ($spaceId, $parentId) and renumber the group 0…n-1. The pages must already
// carry that spaceId/parentId in $pages. Returns the pages whose order changed
// (keyed by id, see mcpReindexSiblings); save them plus the moved pages.
function mcpPlacePages(array $pages, $spaceId, ?string $parentId, array $ids, int $index): array {
    $others = mcpSiblingIds($pages, $spaceId, $parentId, $ids);
    $index = max(0, min($index, count($others)));
    array_splice($others, $index, 0, array_values($ids));
    return mcpReindexSiblings($pages, $spaceId, $parentId, $others);
}

// Save the given pages from $pages (each via mcpSavePage), skipping unknown ids.
function mcpSavePagesById(array $pages, array $ids): array {
    $saved = [];
    foreach (array_unique($ids) as $id) {
        if (!isset($pages[$id])) continue;
        mcpSavePage($pages[$id]);
        $saved[] = (string)$id;
    }
    return $saved;
}

// Timestamp for updatedAt: ISO 8601 with milliseconds, so two edits within
// one second still differ for if_updated_at.
function mcpNowIso(): string {
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.vP');
}

// Mark a content/metadata edit: new updatedAt and content.time.
function mcpTouchPage(array $page): array {
    $now = mcpNowIso();
    // Every edit must change updatedAt (two edits within one millisecond).
    if ($now === ($page['updatedAt'] ?? null)) {
        $now = (new DateTimeImmutable($now))->modify('+1 millisecond')->format('Y-m-d\TH:i:s.vP');
    }
    $page['updatedAt'] = $now;
    if (!is_array($page['content'] ?? null)) $page['content'] = ['blocks' => []];
    $page['content']['time'] = (int)round(microtime(true) * 1000);
    return $page;
}

// Optimistic locking: if_updated_at must equal the stored updatedAt (same
// instant; "Z" vs "+00:00" spelling is fine). Missing/empty → no check.
function mcpCheckUpdatedAt(array $page, array $args): void {
    $want = trim(mcpArg($args, 'if_updated_at', 'string', ''));
    if ($want === '') return;
    $have = (string)($page['updatedAt'] ?? '');
    if ($want === $have) return;
    try {
        if ($have !== '' && (new DateTimeImmutable($want))->format('U.u') === (new DateTimeImmutable($have))->format('U.u')) return;
    } catch (Exception $e) { /* unparsable → mismatch */ }
    throw new McpToolError('Page changed since you read it – reload with get_page (updatedAt is now "'
        . ($have === '' ? 'unset' : $have) . '", you sent "' . mcpSanitizeText($want, 60) . '") and apply your change again.');
}

// Page metadata for write-tool results: no blocks, plus blockCount and urls.
function mcpPageSummary(array $page): array {
    $out = [];
    foreach (MCP_PAGE_KEYS as $k) {
        if ($k !== 'content') $out[$k] = $page[$k] ?? null;
    }
    $out['draft'] = !empty($page['draft']);
    $out['blockCount'] = is_array($page['content']['blocks'] ?? null) ? count($page['content']['blocks']) : 0;
    $out['urls'] = mcpPageUrls((string)$page['id']);
    return $out;
}

// Warning text if root pages of one section are not next to each other by
// order (the sidebar still groups them, so 'order' and display differ).
function mcpSectionWarning(array $pages, $spaceId): ?string {
    $seen = []; $split = []; $prev = null; $first = true;
    foreach (mcpSiblingIds($pages, $spaceId, null) as $id) {
        $k = mcpPageSection($pages[$id]) ?? '';
        if (!$first && $k === $prev) continue;
        if (isset($seen[$k])) $split[$k] = true;
        $seen[$k] = true; $prev = $k; $first = false;
    }
    if (!$split) return null;
    $names = array_map(function ($k) { return $k === '' ? '(no section)' : '"' . $k . '"'; }, array_keys($split));
    return 'Root pages of section ' . implode(', ', $names) . ' are not next to each other by order. The sidebar still shows each section as one group at the position of its first page. Use reorder_pages to keep each section together.';
}

// Rewrite (or, with $new = null, only count) links to page $old inside block
// data: <a> tags with data-page-id="old" or a relative/own-site href
// "…?page=old", and cards links equal to "old". $count = number of links.
// Code blocks (data.code) are sample text, not links, and stay as they are.
function mcpRewritePageLinks($value, string $old, ?string $new, int &$count) {
    if (is_string($value)) {
        if (strpos($value, $old) === false || stripos($value, '<a') === false) return $value;
        $q = preg_quote($old, '/');
        $base = preg_quote(mcpBaseUrl() . '/', '/');
        $reId = '/(\bdata-page-id\s*=\s*["\'])' . $q . '(?=["\'])/';
        $reHref = '/(\bhref\s*=\s*["\'](?:' . $base . ')?[^"\':?#]*\?(?:[^"\'#]*?&(?:amp;)?)?page=)' . $q . '(?=["\'&#])/';
        return preg_replace_callback('/<a\b[^>]*>/i', function ($m) use ($reId, $reHref, $old, $new, &$count) {
            $to = $new ?? ($old . "\0");
            $tag = preg_replace([$reId, $reHref], '${1}' . $to, $m[0]) ?? $m[0];
            if ($tag === $m[0]) return $m[0];
            $count++;
            return $new === null ? $m[0] : $tag;
        }, $value) ?? $value;
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if ($k === 'code') continue;
            if ($k === 'link' && $v === $old) {
                $count++;
                if ($new !== null) $value[$k] = $new;
            } else {
                $value[$k] = mcpRewritePageLinks($v, $old, $new, $count);
            }
        }
    }
    return $value;
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

$MCP_RO = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

mcpTool('list_pages', [
    'title'       => 'List page tree',
    'description' => 'Page tree per space, in sidebar order: root pages grouped into sections (section null = no section, groups in order of first appearance), subpages nested in children. Metadata only, no content (use get_page). '
                   . 'include_drafts=false shows what the public sees (drafts and their subpages are left out). Pages the sidebar cannot show (unknown space, missing parent, cycle) are listed under "unreachable".',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'space_id'       => ['type' => 'string', 'description' => 'Only this space (id from get_site). Default: all spaces.'],
        'include_drafts' => ['type' => 'boolean', 'description' => 'Include draft pages (default true).', 'default' => true],
    ], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): array {
    $spaceFilter = mcpArg($args, 'space_id', 'string', '');
    $includeDrafts = mcpArg($args, 'include_drafts', 'bool', true);
    $spaces = $spaceFilter !== '' ? [mcpRequireSpace($spaceFilter)] : mcpReadSpaces();

    $all = mcpLoadAllPages();
    $pages = $includeDrafts ? $all : array_filter($all, function ($p) { return empty($p['draft']); });

    // Group by "spaceId \0 parentId" (root = parentId null/'').
    $groups = [];
    foreach ($pages as $p) {
        $pid = $p['parentId'] ?? null;
        $key = (string)($p['spaceId'] ?? '') . "\0" . (is_string($pid) ? $pid : '');
        $groups[$key][] = $p;
    }
    $visited = [];
    $node = function (array $p) use (&$node, &$visited, $groups) {
        $visited[$p['id']] = true;
        $children = [];
        foreach (mcpSortPages($groups[(string)($p['spaceId'] ?? '') . "\0" . $p['id']] ?? []) as $c) {
            if (!isset($visited[$c['id']])) $children[] = $node($c);
        }
        return [
            'id'        => $p['id'],
            'title'     => (string)($p['title'] ?? ''),
            'icon'      => (string)($p['icon'] ?? ''),
            'subtitle'  => (string)($p['subtitle'] ?? ''),
            'draft'     => !empty($p['draft']),
            'order'     => $p['order'] ?? null,
            'updatedAt' => $p['updatedAt'] ?? null,
            'url'       => mcpPageUrls($p['id'])['view'],
            'children'  => $children,
        ];
    };

    $out = [];
    foreach ($spaces as $s) {
        $sid = (string)($s['id'] ?? '');
        $sections = [];
        foreach (mcpSortPages($groups[$sid . "\0"] ?? []) as $root) {
            $sec = mcpPageSection($root);
            $k = $sec === null ? "\0" : $sec;
            if (!isset($sections[$k])) $sections[$k] = ['section' => $sec, 'pages' => []];
            $sections[$k]['pages'][] = $node($root);
        }
        $count = count(array_filter($pages, function ($p) use ($sid) { return (string)($p['spaceId'] ?? '') === $sid; }));
        $out[] = [
            'space'     => ['id' => $sid, 'name' => (string)($s['name'] ?? ''), 'icon' => (string)($s['icon'] ?? '')],
            'pageCount' => $count,
            'sections'  => array_values($sections),
        ];
    }

    // Pages in scope that the sidebar never reaches.
    $spaceIds = array_map(function ($s) { return (string)($s['id'] ?? ''); }, mcpReadSpaces());
    $unreachable = [];
    foreach ($pages as $id => $p) {
        $sid = (string)($p['spaceId'] ?? '');
        if (isset($visited[$id]) || ($spaceFilter !== '' && $sid !== $spaceFilter)) continue;
        $pid = $p['parentId'] ?? null;
        $hasParent = is_string($pid) && $pid !== '';
        if (!$includeDrafts && $hasParent) {
            // Hidden because an ancestor is a draft → intentionally left out.
            $hidden = false;
            $seen = [$id => true];
            for ($cur = $all[$pid] ?? null; $cur && !isset($seen[$cur['id']]); $cur = $all[$cur['parentId'] ?? ''] ?? null) {
                $seen[$cur['id']] = true;
                if (!empty($cur['draft'])) { $hidden = true; break; }
            }
            if ($hidden) continue;
        }
        if (!in_array($sid, $spaceIds, true))              $reason = 'unknown space';
        elseif ($hasParent && !isset($all[$pid]))          $reason = 'parent not found';
        elseif ($hasParent && (string)($all[$pid]['spaceId'] ?? '') !== $sid) $reason = 'parent is in another space';
        else                                               $reason = 'parent cycle';
        $unreachable[] = ['id' => $id, 'title' => (string)($p['title'] ?? ''), 'spaceId' => $p['spaceId'] ?? null,
                          'parentId' => $hasParent ? $pid : null, 'reason' => $reason];
    }

    $result = ['spaces' => $out];
    if ($unreachable) $result['unreachable'] = $unreachable;
    return $result;
});

mcpTool('get_page', [
    'title'       => 'Get page',
    'description' => 'One page with all metadata (title, subtitle, icon, section, parentId, order, cover, draft, updatedAt) and its EditorJS content. '
                   . 'Each block carries an extra "index" (its position, for edit_blocks; ignored when writing). Also returns children (ids, in order), breadcrumb (ancestors, root first) and urls. '
                   . 'Pass updatedAt as if_updated_at when you write the page back.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id' => ['type' => 'string', 'description' => 'Page id (from list_pages or search_pages)'],
    ], 'required' => ['id'], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $all = mcpLoadAllPages();
    $page = $all[$id] ?? null;
    if ($page === null) throw new McpToolError("Page not found: $id. Use list_pages or search_pages to find page ids.");

    $out = [];
    foreach (MCP_PAGE_KEYS as $k) {
        if ($k !== 'content') $out[$k] = $page[$k] ?? null;
    }
    foreach ($page as $k => $v) {
        if (!array_key_exists($k, $out) && $k !== 'content' && $k !== '' && $k[0] !== '_' && !in_array($k, MCP_PAGE_TRANSIENT_KEYS, true)) $out[$k] = $v;
    }
    $out['draft'] = !empty($page['draft']);

    $content = is_array($page['content'] ?? null) ? $page['content'] : [];
    $blocks = [];
    foreach (array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : []) as $i => $b) {
        $blocks[] = is_array($b) ? array_merge(['index' => $i], array_diff_key($b, ['index' => 1])) : ['index' => $i, 'type' => null];
    }
    $out['content'] = ['time' => $content['time'] ?? null, 'blocks' => $blocks];
    $out['children'] = array_map(function ($c) { return $c['id']; }, mcpChildPages($all, $page['id']));
    $out['breadcrumb'] = mcpBreadcrumb($all, $page['id']);
    $out['urls'] = mcpPageUrls($page['id']);
    return $out;
});

mcpTool('search_pages', [
    'title'       => 'Search pages',
    'description' => 'Full-text search over page titles, subtitles, sections and block text (case-insensitive; several words must all match). '
                   . 'Returns {id, title, space, score, snippet}, pages with a title match first. Drafts are included and flagged.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'query'    => ['type' => 'string', 'description' => 'Search words'],
        'space_id' => ['type' => 'string', 'description' => 'Only search this space'],
        'limit'    => ['type' => 'integer', 'description' => 'Max. results (1–100, default 20)', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
    ], 'required' => ['query'], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): array {
    $query = mcpSubstr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', mcpArg($args, 'query', 'string')) ?? ''), 0, 200);
    $spaceFilter = mcpArg($args, 'space_id', 'string', '');
    $limit = max(1, min(100, mcpArg($args, 'limit', 'int', 20)));
    $terms = array_values(array_unique(array_filter(preg_split('/\s+/u', mcpLower($query)) ?: [], 'strlen')));
    if (!$terms) throw new McpToolError('query must contain at least one word');
    $terms = array_slice($terms, 0, 10);
    if ($spaceFilter !== '') mcpRequireSpace($spaceFilter);

    $spaceNames = [];
    foreach (mcpReadSpaces() as $s) $spaceNames[(string)($s['id'] ?? '')] = (string)($s['name'] ?? '');
    $count = function (string $hay, string $needle): int {
        return function_exists('mb_substr_count') ? mb_substr_count($hay, $needle, 'UTF-8') : substr_count($hay, $needle);
    };
    $pos = function (string $hay, string $needle) {
        return function_exists('mb_stripos') ? mb_stripos($hay, $needle, 0, 'UTF-8') : stripos($hay, $needle);
    };

    $hits = [];
    foreach (mcpLoadAllPages() as $id => $p) {
        $sid = (string)($p['spaceId'] ?? '');
        if ($spaceFilter !== '' && $sid !== $spaceFilter) continue;
        $title    = mcpPlainText((string)($p['title'] ?? ''));
        $subtitle = mcpPlainText((string)($p['subtitle'] ?? ''));
        $section  = mcpPlainText((string)(mcpPageSection($p) ?? ''));
        $body     = mcpBlocksPlainText(is_array($p['content']['blocks'] ?? null) ? $p['content']['blocks'] : []);
        $lt = mcpLower($title); $ls = mcpLower($subtitle); $lsec = mcpLower($section); $lb = mcpLower($body);

        $score = 0; $titleHit = false; $all = true;
        foreach ($terms as $t) {
            $s = 0;
            if (strpos($lt, $t) !== false)   { $s += 10; $titleHit = true; if (strpos($lt, $t) === 0) $s += 2; }
            if (strpos($ls, $t) !== false)   $s += 4;
            if (strpos($lsec, $t) !== false) $s += 3;
            $s += min(5, $count($lb, $t));
            if ($s === 0) { $all = false; break; }
            $score += $s;
        }
        if (!$all) continue;
        if ($lt === mcpLower($query)) $score += 20;

        // Snippet: around the first body hit, else subtitle, else start of body.
        $snippet = '';
        $first = null;
        foreach ($terms as $t) {
            $p0 = $pos($body, $t);
            if ($p0 !== false && ($first === null || $p0 < $first)) $first = $p0;
        }
        if ($first !== null) {
            $start = max(0, $first - 60);
            $snippet = ($start > 0 ? '…' : '') . mcpSubstr($body, $start, 180);
            if ($start + 180 < mcpStrlen($body)) $snippet .= '…';
        } else {
            $snippet = $subtitle !== '' ? $subtitle : mcpSubstr($body, 0, 180) . (mcpStrlen($body) > 180 ? '…' : '');
        }

        $hit = ['id' => $id, 'title' => $title, 'space' => ['id' => $sid, 'name' => $spaceNames[$sid] ?? ''],
                'score' => $score, 'snippet' => $snippet];
        if (!empty($p['draft'])) $hit['draft'] = true;
        $hits[] = [$titleHit ? 1 : 0, $hit];
    }
    usort($hits, function ($a, $b) {
        return [$b[0], $b[1]['score'], $a[1]['title']] <=> [$a[0], $a[1]['score'], $b[1]['title']];
    });
    return [
        'query'   => $query,
        'total'   => count($hits),
        'results' => array_map(function ($h) { return $h[1]; }, array_slice($hits, 0, $limit)),
    ];
});

mcpTool('get_content_guide', [
    'title'       => 'Get content guide',
    'description' => 'Reference for writing page content: every EditorJS block type with its data fields, the allowed inline HTML, links between pages, images, covers, sections and nesting, with examples. Read this before create_page, update_page or edit_blocks.',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): string {
    return mcpContentGuide();
});

mcpTool('list_images', [
    'title'       => 'List images',
    'description' => 'Uploaded images (newest first) with their root-relative url (use it in image blocks and covers), size, modification time and the ids of pages that use them (content or cover).',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): array {
    $raw = mcpPageJsonRaw();
    $base = mcpBasePath();
    $images = [];
    foreach (mcpImageFiles() as $f) {
        $name = basename($f);
        $mtime = (int)@filemtime($f);
        $images[] = [
            'filename'   => $name,
            'url'        => $base . '/images/' . rawurlencode($name),
            'bytes'      => (int)@filesize($f),
            'modifiedAt' => $mtime ? date('c', $mtime) : null,
            'usedBy'     => mcpImageUsedBy($name, $raw),
        ];
    }
    usort($images, function ($a, $b) { return [$b['modifiedAt'], $a['filename']] <=> [$a['modifiedAt'], $b['filename']]; });
    return ['count' => count($images), 'images' => $images];
});

mcpTool('get_settings', [
    'title'       => 'Get site settings',
    'description' => 'Site settings: siteName, tabTitle, accentColor, theme, lang, footerText, footerTailHtml, hideSingleSpaceTabs, shareSectionEnabled, translateEnabled, plus hasLogo / hasFavicon (the images themselves are not returned). Missing values are null.',
    'inputSchema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
    'annotations' => $MCP_RO,
], function (array $args): array {
    $s = mcpReadSettings();
    $out = [];
    foreach (['siteName', 'tabTitle', 'accentColor', 'theme', 'lang', 'footerText', 'footerTailHtml'] as $k) {
        $out[$k] = array_key_exists($k, $s) ? $s[$k] : null;
    }
    // Effective values, same defaults as shared.js (isHideSingleSpaceTabsEnabled() etc.).
    $out['hideSingleSpaceTabs'] = ($s['hideSingleSpaceTabs'] ?? false) === true;
    $out['shareSectionEnabled'] = ($s['shareSectionEnabled'] ?? true) !== false;
    $out['translateEnabled']    = ($s['translateEnabled'] ?? true) !== false;
    $out['hasLogo']    = is_string($s['logoDataUrl'] ?? null) && $s['logoDataUrl'] !== '';
    $out['hasFavicon'] = is_string($s['faviconDataUrl'] ?? null) && $s['faviconDataUrl'] !== '';
    return $out;
});

// Text of get_content_guide. Must match mcpNormalizeBlockData() above.
function mcpContentGuide(): string {
    return <<<'GUIDE'
# Writing content for Webstudio Docs

Page content is a JSON array of EditorJS blocks: [{"type": "...", "data": {...}}, ...].
Not Markdown: "**bold**" or "# Title" show up literally. The page title, subtitle, icon
and cover are page metadata (shown above the content) - do not repeat the title as a
heading block. Start headings at level 2.

Every write is validated and sanitized. Unknown block types are rejected; unknown data
fields are dropped; missing fields get defaults. Blocks may keep an "id" (from
get_page, [A-Za-z0-9_-]{1,40}); omit it for new blocks. The "index" field that
get_page adds is ignored on write.

## Block types

| type | data | text format |
|---|---|---|
| paragraph | {text} | inline HTML |
| header | {text, level: 1-3 (default 2)} | inline HTML |
| list | {style: "unordered"/"ordered", items: [{content, items: [...nested, max 10 levels]}]} - an item may also be a plain string | content: inline HTML |
| checklist | {items: [{text, checked: bool}]} | text: inline HTML |
| quote | {text, caption, alignment?: "left"/"center"} | inline HTML |
| code | {code} - language is detected automatically | raw text, stored as is (no escaping needed) |
| delimiter | {style: "stars"/"line"/"dashed"/"dots" (default stars)} | - |
| table | {withHeadings: bool (first row = header), content: [[cell, ...], ...]} - rows are padded to equal length | cells: inline HTML, "\n" becomes <br> |
| image | {url, caption, stretched, withBorder, withBackground} (booleans default false) | caption: plain text, max 500 |
| warning | callout: {type: "info"/"tip"/"warning"/"danger" (default info), title, message} | inline HTML |
| timeline | {numbered: bool, items: [{date, title, desc}]} | plain text; "\n" in desc = line break |
| collapse | {title, body, open: bool} - expandable section | plain text; "\n" in body = line break |
| video | {embedUrl} YouTube/Vimeo (watch links like youtube.com/watch?v=… are converted), or {src, filename, mime} for an uploaded video (/videos/vid_….mp4, mime video/mp4, video/webm or video/ogg) | - |
| cards | {cols: 2/3/4 (default 3), cards: [{icon, title, desc, link}]} - icon: Font Awesome name like "fa-rocket"; link: a page id or an https:// URL (optional) | plain text |

"Plain text" fields: tags are removed, write text only.

## Inline HTML (fields marked "inline HTML")

Allowed: <b> <strong> <i> <em> <u> <s> <br>, <mark class="cdx-marker"> (highlight),
<code class="inline-code"> (inline code), <a href target rel data-page-id>.
Any other tag is escaped and shows up as text (no <p>, <div>, <span>, <img>, <h1>);
other attributes (style, on*, …) are dropped. Write literal & < > as &amp; &lt; &gt;.
Link URLs must be http(s), mailto, relative or #anchor; anything else is removed.

- Link to another page: <a href="?page=PAGE_ID" data-page-id="PAGE_ID">Label</a>
- External link: <a href="https://example.com" target="_blank" rel="noopener">Label</a>

## Images and covers

1. Upload first (upload_image, or the raw upload endpoint from get_site with curl).
2. Use the returned root-relative url, e.g. "/images/img_6720….png", in an image block
   or cover. External http(s) image URLs work too. list_images shows existing images.

Page cover (create_page / update_page "cover"; null removes it):
- {"type": "color", "value": "#7c3aed"} or {"type": "color", "value": "linear-gradient(135deg,#f97316,#7c3aed)"}
- {"type": "image", "value": "/images/img_….png", "fit": "cover"/"contain", "position": "50% 50%"}

## Structure: spaces, sections, nesting, order

- Spaces are the top-level tabs. Every page belongs to one space (space_id).
- Pages form a tree: parent_id = null for root pages, otherwise the parent page id.
- Sections are named groups of ROOT pages in the sidebar (shown as a label above the
  group). Subpages never have a section. Groups appear in the order of their first page;
  a new root page with an existing section is placed behind that section's last page.
- Siblings are ordered by "order" (0..n-1). Use position on create/move, or reorder_pages.
- draft: true hides a page (and its subpages) from the public viewer.
- Page ids: lowercase a-z, 0-9, "-", "_" (max 60), derived from the title if not given.
  They are part of the URL: index.php?page=ID.

## Writing tools

- create_page: new page with metadata and blocks (id from the title unless given).
- update_page: metadata and/or "blocks" (replaces the whole content). Pass the
  updatedAt from get_page as if_updated_at; a mismatch means someone else changed
  the page: reload it and apply your change again.
- edit_blocks: change single blocks by index (the "index" from get_page), in order:
  {"op": "insert", "index": 3, "blocks": [...]}   (index = block count appends)
  {"op": "replace", "index": 0, "block": {...}}
  {"op": "delete", "index": 2, "count": 1}
  {"op": "move", "from": 4, "to": 0}
  Each index refers to the blocks after the previous operations. One invalid
  operation aborts the whole call, nothing is saved.
- move_page: new parent (null = root), space, section or position; subpages move along.
- reorder_pages: all siblings in their new order (exactly the current set).
- set_section / rename_section: group root pages ("" removes the section).
- set_page_id: change a page id; internal links in all pages are rewritten.
- delete_page: moves to the trash; subpages only with include_children=true.
- position (create_page, move_page) is the 0-based index among the siblings; for
  root pages among all root pages of the space, across sections.
- Structure changes (move, reorder, sections) do not change updatedAt; content and
  metadata edits do.

## Example 1: heading, paragraph with links, nested list, code

[
  {"type": "header", "data": {"text": "Installation", "level": 2}},
  {"type": "paragraph", "data": {"text": "Read <a href=\"?page=welcome\" data-page-id=\"welcome\">Welcome</a> first. You need <b>PHP 7.4+</b>, see <a href=\"https://www.php.net\" target=\"_blank\" rel=\"noopener\">php.net</a>."}},
  {"type": "list", "data": {"style": "ordered", "items": [
    {"content": "Upload the files", "items": []},
    {"content": "Make <code class=\"inline-code\">data/</code> writable", "items": [
      {"content": "Apache: <mark class=\"cdx-marker\">chown www-data</mark>", "items": []}
    ]}
  ]}},
  {"type": "code", "data": {"code": "chmod -R 775 data images"}}
]

## Example 2: callout, table, collapse

[
  {"type": "warning", "data": {"type": "tip", "title": "Good to know", "message": "Drafts are only visible in the editor."}},
  {"type": "table", "data": {"withHeadings": true, "content": [
    ["Setting", "Default"],
    ["theme", "<code class=\"inline-code\">dark</code>"]
  ]}},
  {"type": "collapse", "data": {"title": "Troubleshooting", "body": "Check file permissions.\nThen reload the page.", "open": false}}
]

## Example 3: image after upload, cards linking to pages

[
  {"type": "image", "data": {"url": "/images/img_6720a1b2c3d4e5.12345678.png", "caption": "The settings dialog", "withBorder": true}},
  {"type": "cards", "data": {"cols": 2, "cards": [
    {"icon": "fa-rocket", "title": "Quick start", "desc": "Set up in 5 minutes", "link": "installation"},
    {"icon": "fa-book", "title": "PHP manual", "desc": "External reference", "link": "https://www.php.net/manual/"}
  ]}}
]
GUIDE;
}

// ── Page write tools (AP5) ─────────────────
// Pages are always written with mcpSavePage(), blocks always pass
// mcpNormalizeBlocks(). Content/metadata edits set updatedAt + content.time
// (mcpTouchPage); pure structure changes (order, parent, space, section via
// move/reorder/set_section/rename_section) leave them alone, like persistPages().

$MCP_POSITION_DOC = '0-based index among the new siblings (for root pages: among all root pages of the space, across sections). Larger values = at the end.';

// position argument: null when not given, otherwise an int >= 0.
function mcpPositionArg(array $args): ?int {
    $pos = mcpArg($args, 'position', 'int', null);
    if ($pos !== null && $pos < 0) throw new McpToolError('position must be 0 or greater');
    return $pos;
}

mcpTool('create_page', [
    'title'       => 'Create page',
    'description' => 'Create a page with metadata and EditorJS blocks (read get_content_guide first). '
                   . 'The id is derived from the title (lowercase slug, "-1", "-2" … if taken) unless you pass id; an explicit id that is malformed or taken is an error. '
                   . 'Default space: the parent\'s space, else the first space. Subpages (parent_id) never have a section. '
                   . 'Default position: subpages at the end; root pages behind the last root page of the same section (or at the end if the section is new). '
                   . 'Returns the page metadata (without blocks) and its urls.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'title'     => ['type' => 'string', 'description' => 'Page title (plain text)'],
        'space_id'  => ['type' => 'string', 'description' => 'Space id (from get_site). Default: space of parent_id, else the first space.'],
        'parent_id' => ['type' => ['string', 'null'], 'description' => 'Parent page id for a subpage; omit or null for a root page'],
        'section'   => ['type' => ['string', 'null'], 'description' => 'Sidebar section (group label) for a root page; ignored for subpages'],
        'id'        => ['type' => 'string', 'description' => 'Explicit page id: lowercase a-z, 0-9, "-", "_" (max. 60). Default: slug of the title.'],
        'icon'      => ['type' => 'string', 'description' => 'Font Awesome icon, e.g. "fa-rocket" (default fa-file)'],
        'subtitle'  => ['type' => 'string', 'description' => 'Short description under the title (plain text)'],
        'blocks'    => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'EditorJS blocks [{type, data}], see get_content_guide'],
        'cover'     => ['type' => ['object', 'null'], 'description' => 'Cover: {type:"color", value} or {type:"image", value, fit?, position?}'],
        'draft'     => ['type' => 'boolean', 'description' => 'Hide from the public viewer (default false)'],
        'position'  => ['type' => 'integer', 'minimum' => 0, 'description' => $MCP_POSITION_DOC],
    ], 'required' => ['title'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
], function (array $args): array {
    $title = mcpSanitizeText(mcpArg($args, 'title', 'string'), 200);
    if ($title === '') throw new McpToolError('title must not be empty');
    $pages = mcpLoadAllPages();
    $notes = [];

    $spaceArg = trim(mcpArg($args, 'space_id', 'string', ''));
    $parentId = trim((string)mcpArg($args, 'parent_id', '?string', ''));
    if ($parentId !== '') {
        $parent = $pages[$parentId] ?? null;
        if ($parent === null) throw new McpToolError("parent_id not found: $parentId. Use list_pages to find page ids.");
        $spaceId = (string)($parent['spaceId'] ?? '');
        if ($spaceArg !== '' && $spaceArg !== $spaceId) {
            throw new McpToolError("Parent page $parentId is in space $spaceId, not $spaceArg. Omit space_id for subpages.");
        }
        mcpRequireSpace($spaceId);
    } else {
        $parentId = null;
        if ($spaceArg !== '') {
            $spaceId = (string)mcpRequireSpace($spaceArg)['id'];
        } else {
            $spaces = mcpReadSpaces();
            if (!$spaces) throw new McpToolError('There is no space yet. Create one first (create_space).');
            $spaceId = (string)($spaces[0]['id'] ?? '');
        }
    }

    $idArg = mcpArg($args, 'id', 'string', '');
    $id = trim($idArg) !== '' ? mcpRequireNewPageId($idArg, $pages, 'id') : mcpUniquePageId(mcpSlugify($title), $pages);

    $section = mcpSectionValue(mcpArg($args, 'section', '?string', null));
    if ($parentId !== null && $section !== null) {
        $notes[] = 'section ignored: subpages never have a section (only root pages are grouped)';
        $section = null;
    }

    $page = [
        'id'        => $id,
        'spaceId'   => $spaceId,
        'parentId'  => $parentId,
        'title'     => $title,
        'icon'      => mcpIconValue(mcpArg($args, 'icon', 'string', '')),
        'subtitle'  => mcpSanitizeText(mcpArg($args, 'subtitle', 'string', ''), 300),
        'section'   => $section,
        'order'     => 0,
        'updatedAt' => '',
        'cover'     => mcpSanitizeCover(mcpArg($args, 'cover', '?any', null)),
        'draft'     => mcpArg($args, 'draft', 'bool', false),
        'content'   => ['time' => 0, 'blocks' => mcpNormalizeBlocks(mcpArg($args, 'blocks', 'array', []))],
    ];
    $page = mcpTouchPage($page);
    $pages[$id] = $page;

    $siblings = mcpSiblingIds($pages, $spaceId, $parentId, [$id]);
    $pos = mcpPositionArg($args);
    if ($pos === null) $pos = $parentId === null ? mcpSectionInsertIndex($pages, $siblings, $section) : count($siblings);
    $pos = min($pos, count($siblings));
    $changed = mcpPlacePages($pages, $spaceId, $parentId, [$id], $pos);
    foreach ($changed as $cid => $cp) $pages[$cid] = $cp;

    $saved = mcpSavePage($pages[$id]);
    $others = mcpSavePagesById($pages, array_diff(array_keys($changed), [$id]));

    $out = mcpPageSummary($saved) + ['siblingsReordered' => $others];
    if ($parentId === null && ($w = mcpSectionWarning($pages, $spaceId))) $out['warning'] = $w;
    if ($notes) $out['notes'] = $notes;
    return $out;
});

mcpTool('update_page', [
    'title'       => 'Update page',
    'description' => 'Change page metadata and/or replace the whole content. Only the fields you pass change. '
                   . 'blocks replaces ALL blocks (use edit_blocks for single blocks). cover: null removes it. '
                   . 'section only applies to root pages ("" or null removes it); a page that changes section moves behind the last page of that section. '
                   . 'Pass the updatedAt from get_page as if_updated_at to avoid overwriting newer changes. Use move_page / set_page_id for parent, space or id.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id'            => ['type' => 'string', 'description' => 'Page id'],
        'title'         => ['type' => 'string'],
        'subtitle'      => ['type' => ['string', 'null']],
        'icon'          => ['type' => 'string', 'description' => 'Font Awesome icon, e.g. "fa-book"'],
        'section'       => ['type' => ['string', 'null'], 'description' => 'Root pages only; "" or null = no section'],
        'cover'         => ['type' => ['object', 'null'], 'description' => 'New cover, or null to remove it'],
        'draft'         => ['type' => 'boolean'],
        'blocks'        => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Replaces the whole content'],
        'if_updated_at' => ['type' => 'string', 'description' => 'updatedAt you read; the update fails if the page changed since'],
    ], 'required' => ['id'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $pages = mcpLoadAllPages();
    $page = $pages[$id] ?? null;
    if ($page === null) throw new McpToolError("Page not found: $id. Use list_pages or search_pages to find page ids.");
    mcpCheckUpdatedAt($page, $args);

    $fields = [];
    $resection = false;
    if (($title = mcpArg($args, 'title', 'string', null)) !== null) {
        $title = mcpSanitizeText($title, 200);
        if ($title === '') throw new McpToolError('title must not be empty');
        $page['title'] = $title;
        $fields[] = 'title';
    }
    if (array_key_exists('subtitle', $args)) {
        $page['subtitle'] = mcpSanitizeText((string)mcpArg($args, 'subtitle', '?string', ''), 300);
        $fields[] = 'subtitle';
    }
    if (($icon = mcpArg($args, 'icon', 'string', null)) !== null) {
        $page['icon'] = mcpIconValue($icon, (string)($page['icon'] ?? 'fa-file'));
        $fields[] = 'icon';
    }
    if (array_key_exists('section', $args)) {
        $section = mcpSectionValue(mcpArg($args, 'section', '?string', null));
        if (mcpParentOf($page) !== null) {
            if ($section !== null) {
                throw new McpToolError("section only applies to root pages; $id is a subpage of " . mcpParentOf($page) . '. Use move_page with parent_id null to make it a root page.');
            }
        } else {
            $resection = $section !== mcpPageSection($page);
            $page['section'] = $section;
            $fields[] = 'section';
        }
    }
    if (array_key_exists('cover', $args)) {
        $page['cover'] = mcpSanitizeCover($args['cover']);
        $fields[] = 'cover';
    }
    if (($draft = mcpArg($args, 'draft', 'bool', null)) !== null) {
        $page['draft'] = $draft;
        $fields[] = 'draft';
    }
    if (($blocks = mcpArg($args, 'blocks', 'array', null)) !== null) {
        if (!is_array($page['content'] ?? null)) $page['content'] = [];
        $page['content']['blocks'] = mcpNormalizeBlocks($blocks);
        $fields[] = 'blocks';
    }
    if (!$fields) throw new McpToolError('Nothing to update: pass at least one of title, subtitle, icon, section, cover, draft, blocks');

    $page = mcpTouchPage($page);
    $pages[$id] = $page;
    $changed = [];
    if ($resection) {
        $spaceId = $page['spaceId'] ?? null;
        $sib = mcpSiblingIds($pages, $spaceId, null, [$id]);
        $changed = mcpPlacePages($pages, $spaceId, null, [$id], mcpSectionInsertIndex($pages, $sib, $page['section']));
        foreach ($changed as $cid => $cp) $pages[$cid] = $cp;
    }
    $saved = mcpSavePage($pages[$id]);
    $others = mcpSavePagesById($pages, array_diff(array_keys($changed), [$id]));

    $out = mcpPageSummary($saved) + ['updatedFields' => $fields];
    if ($others) $out['siblingsReordered'] = $others;
    if ($resection && ($w = mcpSectionWarning($pages, $page['spaceId'] ?? null))) $out['warning'] = $w;
    return $out;
});

mcpTool('edit_blocks', [
    'title'       => 'Edit blocks',
    'description' => 'Change single blocks of a page without resending the rest. Operations run in order on a copy; index = position at the time of that operation (see "index" in get_page): '
                   . '{op:"insert", index, blocks:[…]} (index = block count appends), {op:"replace", index, block}, {op:"delete", index, count=1}, {op:"move", from, to} (to = final position). '
                   . 'Any invalid operation or index aborts everything, nothing is saved. Returns the new block count and updatedAt.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id'            => ['type' => 'string', 'description' => 'Page id'],
        'operations'    => ['type' => 'array', 'description' => 'Operations, applied in order', 'items' => ['type' => 'object', 'properties' => [
            'op'     => ['type' => 'string', 'enum' => ['insert', 'replace', 'delete', 'move']],
            'index'  => ['type' => 'integer', 'minimum' => 0],
            'blocks' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'insert: new blocks'],
            'block'  => ['type' => 'object', 'description' => 'replace: the new block'],
            'count'  => ['type' => 'integer', 'minimum' => 1, 'description' => 'delete: number of blocks (default 1)'],
            'from'   => ['type' => 'integer', 'minimum' => 0],
            'to'     => ['type' => 'integer', 'minimum' => 0],
        ], 'required' => ['op']]],
        'if_updated_at' => ['type' => 'string', 'description' => 'updatedAt you read; fails if the page changed since'],
    ], 'required' => ['id', 'operations'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $page = mcpLoadPage($id);
    mcpCheckUpdatedAt($page, $args);
    $ops = mcpArg($args, 'operations', 'array');
    if (!$ops) throw new McpToolError('operations must not be empty');
    if (count($ops) > 500) throw new McpToolError('At most 500 operations per call');

    $blocks = array_values(is_array($page['content']['blocks'] ?? null) ? $page['content']['blocks'] : []);
    foreach ($ops as $i => $op) {
        if (!is_array($op)) throw new McpToolError("operations[$i] must be an object {op, …}");
        $name = is_string($op['op'] ?? null) ? $op['op'] : '';
        $where = "operations[$i] ($name)";
        $len = count($blocks);
        $int = function (string $key, int $max, ?int $default = null) use ($op, $where, $len) {
            if (!array_key_exists($key, $op) || $op[$key] === null) {
                if ($default !== null) return $default;
                throw new McpToolError("$where: missing $key");
            }
            $v = $op[$key];
            if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) $v = (int)$v;
            if (is_float($v) && floor($v) === $v) $v = (int)$v;
            if (!is_int($v)) throw new McpToolError("$where: $key must be an integer");
            if ($v < 0 || $v > $max) {
                throw new McpToolError("$where: $key $v is out of range " . ($max < 0 ? "(the page has no blocks at this point)" : "0..$max (the page has $len blocks at this point)") . '. Nothing was saved.');
            }
            return $v;
        };
        $normalize = function (array $list) use ($where) {
            try {
                return mcpNormalizeBlocks($list);
            } catch (McpToolError $e) {
                throw new McpToolError("$where: " . $e->getMessage() . ' Nothing was saved.');
            }
        };
        switch ($name) {
            case 'insert':
                $at = $int('index', $len);
                $new = $op['blocks'] ?? (isset($op['block']) ? [$op['block']] : null);
                if (!is_array($new) || !mcpIsList($new) || !$new) throw new McpToolError("$where: blocks must be a non-empty array of {type, data}");
                array_splice($blocks, $at, 0, $normalize($new));
                break;
            case 'replace':
                $at = $int('index', $len - 1);
                if (!is_array($op['block'] ?? null)) throw new McpToolError("$where: block must be an object {type, data}");
                $b = $normalize([$op['block']])[0];
                // Keep the EditorJS id of the replaced block unless a new one is given.
                if (!isset($b['id']) && is_string($blocks[$at]['id'] ?? null)) $b = ['id' => $blocks[$at]['id']] + $b;
                $blocks[$at] = $b;
                break;
            case 'delete':
                $at = $int('index', $len - 1);
                $count = $int('count', PHP_INT_MAX, 1);
                if ($count < 1) throw new McpToolError("$where: count must be 1 or greater");
                if ($at + $count > $len) throw new McpToolError("$where: cannot delete $count block(s) from index $at, the page has $len blocks at this point. Nothing was saved.");
                array_splice($blocks, $at, $count);
                break;
            case 'move':
                $from = $int('from', $len - 1);
                $to = $int('to', $len - 1);
                $moved = array_splice($blocks, $from, 1);
                array_splice($blocks, $to, 0, $moved);
                break;
            default:
                throw new McpToolError("operations[$i]: unknown op '" . mcpSanitizeText($name, 20) . "' (use insert, replace, delete or move). Nothing was saved.");
        }
    }

    if (!is_array($page['content'] ?? null)) $page['content'] = [];
    $page['content']['blocks'] = array_values($blocks);
    $saved = mcpSavePage(mcpTouchPage($page));
    return ['id' => $id, 'blockCount' => count($saved['content']['blocks']), 'updatedAt' => $saved['updatedAt'], 'operations' => count($ops)];
});

mcpTool('move_page', [
    'title'       => 'Move page',
    'description' => 'Move a page (with all its subpages) in the tree: under another parent, to the root (parent_id null), into another space, and/or to another position or section. '
                   . 'Omitted parent_id keeps the parent (root of the new space when space_id changes). A page cannot be moved under itself or its own subpages. '
                   . 'Root pages keep their section unless you pass section; subpages never have one. Default position: subpages at the end, root pages behind the last page of their section; '
                   . 'without position, parent, space and section changes the page stays where it is. Old and new siblings are renumbered; updatedAt does not change.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id'        => ['type' => 'string', 'description' => 'Page id'],
        'parent_id' => ['type' => ['string', 'null'], 'description' => 'New parent page id, or null for the root level'],
        'space_id'  => ['type' => 'string', 'description' => 'Target space (moves the whole subtree)'],
        'section'   => ['type' => ['string', 'null'], 'description' => 'Section for a root page ("" or null = none)'],
        'position'  => ['type' => 'integer', 'minimum' => 0, 'description' => $MCP_POSITION_DOC],
    ], 'required' => ['id'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $pages = mcpLoadAllPages();
    $page = $pages[$id] ?? null;
    if ($page === null) throw new McpToolError("Page not found: $id. Use list_pages to find page ids.");
    $oldSpace = $page['spaceId'] ?? null;
    $oldParent = mcpParentOf($page);
    $oldSection = mcpPageSection($page);
    $descendants = mcpDescendantIds($pages, $id);
    $notes = [];

    $spaceArg = trim(mcpArg($args, 'space_id', 'string', ''));
    if (array_key_exists('parent_id', $args)) {
        $newParent = trim((string)mcpArg($args, 'parent_id', '?string', ''));
        $newParent = $newParent === '' ? null : $newParent;
    } else {
        $newParent = ($spaceArg !== '' && $spaceArg !== $oldSpace) ? null : $oldParent;
    }
    if ($newParent !== null) {
        if ($newParent === $id) throw new McpToolError('A page cannot be its own parent');
        if (in_array($newParent, $descendants, true)) {
            throw new McpToolError("Cannot move $id under $newParent: $newParent is a subpage of $id (that would create a cycle). Move $newParent first.");
        }
        $parent = $pages[$newParent] ?? null;
        if ($parent === null) throw new McpToolError("parent_id not found: $newParent");
        $newSpace = $parent['spaceId'] ?? null;
        if ($spaceArg !== '' && $spaceArg !== $newSpace) {
            throw new McpToolError("Parent page $newParent is in space $newSpace, not $spaceArg. Omit space_id when moving under a parent.");
        }
    } else {
        $newSpace = $spaceArg !== '' ? $spaceArg : $oldSpace;
    }
    mcpRequireSpace((string)$newSpace);

    if ($newParent === null) {
        $newSection = array_key_exists('section', $args) ? mcpSectionValue(mcpArg($args, 'section', '?string', null)) : $oldSection;
    } else {
        $newSection = null;
        if (array_key_exists('section', $args) && mcpSectionValue(mcpArg($args, 'section', '?string', null)) !== null) {
            $notes[] = 'section ignored: subpages never have a section';
        }
    }
    $pos = mcpPositionArg($args);
    $sameGroup = $newSpace === $oldSpace && $newParent === $oldParent;
    $spaceChanged = $newSpace !== $oldSpace;

    if ($sameGroup && $pos === null && $newSection === $oldSection) {
        return mcpPageSummary($page) + ['moved' => false, 'notes' => array_merge($notes, ['Nothing to change: same parent, space and section, no position given'])];
    }

    $page['spaceId'] = $newSpace;
    $page['parentId'] = $newParent;
    $page['section'] = $newSection;
    $pages[$id] = $page;
    if ($spaceChanged) {
        foreach ($descendants as $d) $pages[$d]['spaceId'] = $newSpace;
    }

    $siblings = mcpSiblingIds($pages, $newSpace, $newParent, [$id]);
    if ($pos === null) {
        $pos = $newParent !== null ? count($siblings) : mcpSectionInsertIndex($pages, $siblings, $newSection);
    }
    $pos = min($pos, count($siblings));
    $changed = mcpPlacePages($pages, $newSpace, $newParent, [$id], $pos);
    foreach ($changed as $cid => $cp) $pages[$cid] = $cp;
    if (!$sameGroup) {
        foreach (mcpReindexSiblings($pages, $oldSpace, $oldParent) as $cid => $cp) { $pages[$cid] = $cp; $changed[$cid] = $cp; }
    }

    $toSave = array_merge([$id], $spaceChanged ? $descendants : [], array_keys($changed));
    mcpSavePagesById($pages, $toSave);

    $out = mcpPageSummary($pages[$id]) + [
        'moved'             => true,
        'position'          => $pos,
        'siblingsReordered' => array_values(array_diff(array_keys($changed), [$id])),
    ];
    if ($spaceChanged) $out['subpagesMovedToSpace'] = $descendants;
    $warnings = [];
    if ($newParent === null && ($w = mcpSectionWarning($pages, $newSpace))) $warnings[] = $w;
    if (!$sameGroup && $oldParent === null && $oldSpace !== $newSpace && ($w = mcpSectionWarning($pages, $oldSpace))) $warnings[] = $w;
    if ($warnings) $out['warning'] = implode(' ', $warnings);
    if ($notes) $out['notes'] = $notes;
    return $out;
});

mcpTool('reorder_pages', [
    'title'       => 'Reorder sibling pages',
    'description' => 'Set the order of all siblings at once: the root pages of a space (parent_id omitted/null) or the subpages of one parent. '
                   . 'page_ids must contain exactly the current siblings (each once), in the new order; otherwise the error lists the expected ids. '
                   . 'For root pages keep each section\'s pages next to each other (the sidebar groups sections at the position of their first page). updatedAt does not change.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'space_id'  => ['type' => 'string', 'description' => 'Space id'],
        'parent_id' => ['type' => ['string', 'null'], 'description' => 'Parent page id; omit or null for root pages'],
        'page_ids'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'All sibling ids in the new order'],
    ], 'required' => ['space_id', 'page_ids'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
], function (array $args): array {
    $spaceId = (string)mcpRequireSpace(trim(mcpArg($args, 'space_id', 'string')))['id'];
    $pages = mcpLoadAllPages();
    $parentId = trim((string)mcpArg($args, 'parent_id', '?string', ''));
    $parentId = $parentId === '' ? null : $parentId;
    if ($parentId !== null) {
        if (!isset($pages[$parentId])) throw new McpToolError("parent_id not found: $parentId");
        if (($pages[$parentId]['spaceId'] ?? null) !== $spaceId) {
            throw new McpToolError("Page $parentId is in space " . ($pages[$parentId]['spaceId'] ?? '?') . ", not $spaceId");
        }
    }
    $ids = [];
    foreach (mcpArg($args, 'page_ids', 'array') as $v) {
        if (!is_string($v) && !is_int($v)) throw new McpToolError('page_ids must be an array of page id strings');
        $ids[] = trim((string)$v);
    }
    $expected = mcpSiblingIds($pages, $spaceId, $parentId);
    $where = $parentId === null ? "root pages of space $spaceId" : "subpages of $parentId";
    $dups = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));
    $missing = array_values(array_diff($expected, $ids));
    $extra = array_values(array_diff($ids, $expected));
    if ($dups || $missing || $extra) {
        $msg = 'page_ids must list exactly the ' . count($expected) . " $where, each once. Expected (current order): " . json_encode($expected) . '.';
        if ($missing) $msg .= ' Missing: ' . json_encode($missing) . '.';
        if ($extra)   $msg .= ' Not siblings here: ' . json_encode(array_map(function ($s) { return mcpSanitizeText($s, 60); }, $extra)) . '.';
        if ($dups)    $msg .= ' Duplicates: ' . json_encode($dups) . '.';
        throw new McpToolError($msg);
    }
    $changed = mcpReindexSiblings($pages, $spaceId, $parentId, $ids);
    foreach ($changed as $cid => $cp) $pages[$cid] = $cp;
    mcpSavePagesById($pages, array_keys($changed));

    $out = ['spaceId' => $spaceId, 'parentId' => $parentId, 'order' => $ids, 'changed' => array_keys($changed)];
    if ($parentId === null && ($w = mcpSectionWarning($pages, $spaceId))) $out['warning'] = $w;
    return $out;
});

mcpTool('set_page_id', [
    'title'       => 'Change page id',
    'description' => 'Rename a page id (= its URL index.php?page=ID). Subpages are re-attached, ratings move along, and internal links to the old id in ALL pages '
                   . '(<a data-page-id> / href="?page=…" and cards links) are rewritten; the result reports how many. '
                   . 'Old URLs ?page=<old id> (bookmarks, search engines, external sites) stop working.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id'     => ['type' => 'string', 'description' => 'Current page id'],
        'new_id' => ['type' => 'string', 'description' => 'New id: lowercase a-z, 0-9, "-", "_" (max. 60), not used yet'],
    ], 'required' => ['id', 'new_id'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $pages = mcpLoadAllPages();
    if (!isset($pages[$id])) throw new McpToolError("Page not found: $id");
    $newRaw = mcpArg($args, 'new_id', 'string');
    if (trim($newRaw) === $id) throw new McpToolError('new_id is the same as the current id');
    $newId = mcpRequireNewPageId($newRaw, $pages, 'new_id');
    $children = array_values(array_filter(array_keys($pages), function ($pid) use ($pages, $id) {
        return mcpParentOf($pages[$pid]) === $id;
    }));

    renameStoredPage($id, $newId);

    $links = 0;
    $updated = [];
    foreach (mcpLoadAllPages() as $pid => $p) {
        if (!is_array($p['content']['blocks'] ?? null)) continue;
        $n = 0;
        $blocks = mcpRewritePageLinks($p['content']['blocks'], $id, $newId, $n);
        if ($n === 0) continue;
        $p['content']['blocks'] = $blocks;
        mcpSavePage(mcpTouchPage($p));
        $links += $n;
        $updated[] = (string)$pid;
    }
    return [
        'id'                 => $newId,
        'oldId'              => $id,
        'urls'               => mcpPageUrls($newId),
        'subpagesReattached' => $children,
        'linksRewritten'     => $links,
        'pagesUpdated'       => $updated,
        'note'               => "Old links ?page=$id (bookmarks, search engines, external sites) no longer work.",
    ];
});

mcpTool('delete_page', [
    'title'       => 'Delete page',
    'description' => 'Move a page to the trash (data/trash/pages on the server; not a hard delete). A page with subpages is only deleted with include_children=true, '
                   . 'which trashes the whole subtree; otherwise the error lists the subpages. Remaining siblings are renumbered. '
                   . 'The result lists pages that still link to the deleted pages (fix them with edit_blocks).',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'id'               => ['type' => 'string', 'description' => 'Page id'],
        'include_children' => ['type' => 'boolean', 'description' => 'Also delete all subpages (default false)', 'default' => false],
    ], 'required' => ['id'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
], function (array $args): array {
    $id = trim(mcpArg($args, 'id', 'string'));
    $include = mcpArg($args, 'include_children', 'bool', false);
    $pages = mcpLoadAllPages();
    $page = $pages[$id] ?? null;
    if ($page === null) throw new McpToolError("Page not found: $id");
    $children = mcpSortPages(array_filter($pages, function ($p) use ($id) { return mcpParentOf($p) === $id; }));
    $subtree = array_merge([$id], mcpDescendantIds($pages, $id));
    if ($children && !$include) {
        $list = implode(', ', array_map(function ($c) { return $c['id'] . ' ("' . ($c['title'] ?? '') . '")'; }, $children));
        throw new McpToolError("Page $id has " . count($children) . " subpage(s): $list. Pass include_children=true to delete the whole subtree ("
            . count($subtree) . ' pages), or move the subpages first with move_page.');
    }

    trashPagesById($subtree);
    foreach ($subtree as $d) {
        if (file_exists(pageFilePath($d))) throw new McpToolError("Failed to move page $d to the trash");
        unset($pages[$d]);
    }
    $changed = mcpReindexSiblings($pages, $page['spaceId'] ?? null, mcpParentOf($page));
    foreach ($changed as $cid => $cp) $pages[$cid] = $cp;
    mcpSavePagesById($pages, array_keys($changed));

    $linkedFrom = [];
    foreach ($pages as $pid => $p) {
        if (!is_array($p['content']['blocks'] ?? null)) continue;
        $n = 0;
        foreach ($subtree as $d) mcpRewritePageLinks($p['content']['blocks'], $d, null, $n);
        if ($n > 0) $linkedFrom[] = ['id' => (string)$pid, 'links' => $n];
    }
    $out = ['deleted' => $subtree, 'trash' => 'data/trash/pages', 'siblingsReordered' => array_keys($changed)];
    if ($linkedFrom) $out['linkedFrom'] = $linkedFrom;
    return $out;
});

mcpTool('rename_section', [
    'title'       => 'Rename section',
    'description' => 'Rename a sidebar section of a space (all its root pages get the new name), or remove the grouping with to = "". '
                   . 'If the target section already exists, the pages are merged into it (placed behind its last page). updatedAt does not change.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'space_id' => ['type' => 'string', 'description' => 'Space id'],
        'from'     => ['type' => 'string', 'description' => 'Current section name (exact)'],
        'to'       => ['type' => ['string', 'null'], 'description' => 'New name; "" or null removes the section'],
    ], 'required' => ['space_id', 'from', 'to'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
], function (array $args): array {
    $spaceId = (string)mcpRequireSpace(trim(mcpArg($args, 'space_id', 'string')))['id'];
    $from = trim(mcpArg($args, 'from', 'string'));
    if ($from === '') throw new McpToolError('from must name an existing section (use set_section to group pages without a section)');
    if (!array_key_exists('to', $args)) throw new McpToolError('Missing required parameter: to ("" removes the section)');
    $to = mcpSectionValue(mcpArg($args, 'to', '?string', null));
    $pages = mcpLoadAllPages();
    $roots = mcpSiblingIds($pages, $spaceId, null);
    $hits = array_values(array_filter($roots, function ($rid) use ($pages, $from) { return mcpPageSection($pages[$rid]) === $from; }));
    if (!$hits) {
        $names = array_values(array_unique(array_filter(array_map(function ($rid) use ($pages) { return mcpPageSection($pages[$rid]); }, $roots), 'is_string')));
        throw new McpToolError("No root page in space $spaceId has section \"" . mcpSanitizeText($from, 100) . '". Sections here: ' . ($names ? json_encode($names, JSON_UNESCAPED_UNICODE) : 'none'));
    }
    if ($to === $from) throw new McpToolError('from and to are the same');

    $merged = false;
    foreach ($roots as $rid) {
        if (!in_array($rid, $hits, true) && mcpPageSection($pages[$rid]) === $to) { $merged = true; break; }
    }
    foreach ($hits as $h) $pages[$h]['section'] = $to;
    $changed = [];
    if ($merged) {
        $others = mcpSiblingIds($pages, $spaceId, null, $hits);
        $changed = mcpPlacePages($pages, $spaceId, null, $hits, mcpSectionInsertIndex($pages, $others, $to));
        foreach ($changed as $cid => $cp) $pages[$cid] = $cp;
    }
    mcpSavePagesById($pages, array_merge($hits, array_keys($changed)));
    $out = ['spaceId' => $spaceId, 'from' => $from, 'to' => $to, 'pages' => $hits, 'merged' => $merged];
    if ($w = mcpSectionWarning($pages, $spaceId)) $out['warning'] = $w;
    return $out;
});

mcpTool('set_section', [
    'title'       => 'Set section of pages',
    'description' => 'Put root pages into a sidebar section (group), or remove them from their section with section = "". '
                   . 'Pages that change section are placed, in the given order, behind the last page of the target section (or at the end if the section is new); pages already in it stay. '
                   . 'Only root pages can have a section. updatedAt does not change.',
    'inputSchema' => ['type' => 'object', 'properties' => [
        'page_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Root page ids'],
        'section'  => ['type' => ['string', 'null'], 'description' => 'Section name; "" or null = no section'],
    ], 'required' => ['page_ids', 'section'], 'additionalProperties' => false],
    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
], function (array $args): array {
    $ids = [];
    foreach (mcpArg($args, 'page_ids', 'array') as $v) {
        if (!is_string($v) && !is_int($v)) throw new McpToolError('page_ids must be an array of page id strings');
        $v = trim((string)$v);
        if (!in_array($v, $ids, true)) $ids[] = $v;
    }
    if (!$ids) throw new McpToolError('page_ids must not be empty');
    if (!array_key_exists('section', $args)) throw new McpToolError('Missing required parameter: section ("" removes the section)');
    $section = mcpSectionValue(mcpArg($args, 'section', '?string', null));
    $pages = mcpLoadAllPages();
    $missing = array_values(array_filter($ids, function ($i) use ($pages) { return !isset($pages[$i]); }));
    if ($missing) throw new McpToolError('Pages not found: ' . json_encode(array_map(function ($s) { return mcpSanitizeText($s, 60); }, $missing)));
    $subs = array_values(array_filter($ids, function ($i) use ($pages) { return mcpParentOf($pages[$i]) !== null; }));
    if ($subs) throw new McpToolError('Only root pages can have a section; these are subpages: ' . json_encode($subs) . '. Use move_page with parent_id null first.');

    $moved = []; $unchanged = [];
    foreach ($ids as $i) {
        if (mcpPageSection($pages[$i]) === $section) $unchanged[] = $i; else $moved[] = $i;
    }
    $bySpace = [];
    foreach ($moved as $i) $bySpace[(string)($pages[$i]['spaceId'] ?? '')][] = $i;
    $changed = [];
    foreach ($bySpace as $sid => $group) {
        $spaceId = $pages[$group[0]]['spaceId'] ?? null;
        foreach ($group as $i) $pages[$i]['section'] = $section;
        $others = mcpSiblingIds($pages, $spaceId, null, $group);
        foreach (mcpPlacePages($pages, $spaceId, null, $group, mcpSectionInsertIndex($pages, $others, $section)) as $cid => $cp) {
            $pages[$cid] = $cp;
            $changed[$cid] = $cp;
        }
    }
    mcpSavePagesById($pages, array_merge($moved, array_keys($changed)));
    $out = ['section' => $section, 'changed' => $moved, 'unchanged' => $unchanged,
            'siblingsReordered' => array_values(array_diff(array_keys($changed), $moved))];
    $warnings = [];
    foreach (array_keys($bySpace) as $sid) {
        if ($w = mcpSectionWarning($pages, $pages[$bySpace[$sid][0]]['spaceId'] ?? null)) $warnings[] = $w;
    }
    if ($warnings) $out['warning'] = implode(' ', $warnings);
    return $out;
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
