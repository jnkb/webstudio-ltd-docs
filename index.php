<?php
/**
 * Server-side OG meta tags for social media crawlers.
 * Calculates og:title, og:description, og:image for the requested page.
 */
$_ogData = (function() {
    $dataDir  = __DIR__ . '/data';
    $pagesDir = __DIR__ . '/data/pages';
    $ogImgDir = __DIR__ . '/images/og';
    $fontDir  = __DIR__ . '/data/.fonts';

    $pageId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['page'] ?? '');

    $readJson = function($path) {
        if (!file_exists($path)) return null;
        $raw = file_get_contents($path);
        return $raw ? json_decode($raw, true) : null;
    };

    $settings = $readJson($dataDir . '/settings.json') ?? [];
    $siteName = $settings['siteName'] ?? 'Docs';
    $accent   = $settings['accentColor'] ?? '#f97316';
    $page     = null;

    if ($pageId) {
        $page = $readJson($pagesDir . "/{$pageId}.json");
    }

    // Fallback: first page of first space
    if (!$page) {
        $spaces = $readJson($dataDir . '/spaces.json') ?? [];
        if (!empty($spaces)) {
            $sid = $spaces[0]['id'] ?? '';
            foreach (glob($pagesDir . '/*.json') as $f) {
                $p = $readJson($f);
                if ($p && ($p['spaceId'] ?? '') === $sid && empty($p['parentId'])) {
                    if (!$page || ($p['order'] ?? 0) < ($page['order'] ?? 0)) $page = $p;
                }
            }
        }
    }

    $title = $page ? ($page['title'] ?? 'Docs') : $siteName;
    $desc  = $page && !empty($page['subtitle']) ? $page['subtitle'] : "{$siteName} documentation";
    $imgUrl = '';

    // Cover image? (type=image only, not color)
    if ($page && !empty($page['cover']) && ($page['cover']['type'] ?? '') === 'image') {
        $imgUrl = $page['cover']['value'] ?? '';
        if ($imgUrl && !preg_match('#^https?://#', $imgUrl)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base  = rtrim(dirname($_SERVER['PHP_SELF']), '/');
            $imgUrl = "{$proto}://{$host}{$base}/{$imgUrl}";
        }
    }

    // Generate OG image if no cover image
    if (!$imgUrl && $page && function_exists('imagecreatetruecolor')) {
        if (!is_dir($ogImgDir)) @mkdir($ogImgDir, 0755, true);
        
        // Include cover color in hash so it regenerates if cover changes
        $coverVal = $page['cover']['value'] ?? '';
        $hash = md5($pageId . $title . ($page['subtitle'] ?? '') . $accent . $coverVal . $siteName);
        $cache = $ogImgDir . "/{$hash}.png";

        if (!file_exists($cache)) {
            $w = 1200; $h = 630;
            $img = imagecreatetruecolor($w, $h);

            $hasCoverColor = !empty($page['cover']) && ($page['cover']['type'] ?? '') === 'color';
            if ($hasCoverColor) {
                $val = $page['cover']['value'] ?? '';
                $colors = [];
                if (preg_match_all('/#([0-9a-fA-F]{6})/', $val, $m)) $colors = $m[0];
                if (count($colors) >= 2) { $c1 = $colors[0]; $c2 = $colors[1]; }
                else { $c1 = $accent; $c2 = $accent; }
                $r1 = hexdec(substr($c1,1,2)); $g1 = hexdec(substr($c1,3,2)); $b1 = hexdec(substr($c1,5,2));
                $r2 = hexdec(substr($c2,1,2)); $g2 = hexdec(substr($c2,3,2)); $b2 = hexdec(substr($c2,5,2));
            } else {
                $r1 = hexdec(substr($accent,1,2)); $g1 = hexdec(substr($accent,3,2)); $b1 = hexdec(substr($accent,5,2));
                $r2 = max(0,$r1-50); $g2 = max(0,$g1-50); $b2 = max(0,$b1-30);
            }

            for ($x = 0; $x < $w; $x++) {
                $ratio = $x / $w;
                $cr = (int)($r1 + ($r2-$r1) * $ratio);
                $cg = (int)($g1 + ($g2-$g1) * $ratio);
                $cb = (int)($b1 + ($b2-$b1) * $ratio);
                $color = imagecolorallocate($img, max(0,min(255,$cr)), max(0,min(255,$cg)), max(0,min(255,$cb)));
                imageline($img, $x, 0, $x, $h, $color);
            }

            $white = imagecolorallocate($img, 255, 255, 255);
            $whiteA70 = imagecolorallocatealpha($img, 255, 255, 255, 38);

            if (!is_dir($fontDir)) @mkdir($fontDir, 0755, true);
            $fontBold = $fontDir . '/Inter-Bold.ttf';
            $fontRegular = $fontDir . '/Inter-Regular.ttf';
            if (!file_exists($fontBold))
                @file_put_contents($fontBold, @file_get_contents('https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuFuYMZhrib2Bg-4.ttf'));
            if (!file_exists($fontRegular))
                @file_put_contents($fontRegular, @file_get_contents('https://fonts.gstatic.com/s/inter/v18/UcCO3FwrK3iLTeHuS_nVMrMxCp50SjIw2boKoduKmMEVuLyfMZhrib2Bg-4.ttf'));
            $useTTF = file_exists($fontBold) && filesize($fontBold) > 1000;

            if ($useTTF) {
                $snBbox = imagettfbbox(17, 0, $fontRegular, $siteName);
                $snWidth = $snBbox ? ($snBbox[2] - $snBbox[0]) : 100;
                imagettftext($img, 17, 0, $w - $snWidth - 60, 48, $whiteA70, $fontRegular, $siteName);

                $fontSize = 52;
                $titleText = $page['title'] ?? 'Docs';
                $words = explode(' ', $titleText);
                $lines = []; $line = '';
                foreach ($words as $word) {
                    $test = $line ? "{$line} {$word}" : $word;
                    $bbox = imagettfbbox($fontSize, 0, $fontBold, $test);
                    if ($bbox && ($bbox[2] - $bbox[0]) > 1040) { if ($line) $lines[] = $line; $line = $word; }
                    else $line = $test;
                }
                if ($line) $lines[] = $line;
                $lines = array_slice($lines, 0, 3);
                $hasSubtitle = !empty($page['subtitle']);
                $titleY = $hasSubtitle ? 300 : 350;
                $lineHeight = (int)($fontSize * 1.35);
                foreach ($lines as $i => $l)
                    imagettftext($img, $fontSize, 0, 80, $titleY + $i * $lineHeight, $white, $fontBold, $l);
                if ($hasSubtitle)
                    imagettftext($img, 23, 0, 80, $titleY + count($lines) * $lineHeight + 20, $whiteA70, $fontRegular, mb_substr($page['subtitle'], 0, 90));
            } else {
                imagestring($img, 5, 80, 300, $page['title'] ?? 'Docs', $white);
                if (!empty($page['subtitle'])) imagestring($img, 4, 80, 340, substr($page['subtitle'], 0, 80), $whiteA70);
                imagestring($img, 3, $w - 200, 30, $siteName, $whiteA70);
            }

            imagepng($img, $cache, 0);
            imagedestroy($img);
        }
        if (file_exists($cache)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base  = rtrim(dirname($_SERVER['PHP_SELF']), '/');
            $imgUrl = "{$proto}://{$host}{$base}/images/og/" . basename($cache);
        }
    }

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $url = "{$proto}://{$host}{$basePath}/" . ($pageId ? "?page={$pageId}" : '');

    return [
        'title'    => htmlspecialchars($title, ENT_QUOTES),
        'desc'     => htmlspecialchars($desc, ENT_QUOTES),
        'siteName' => htmlspecialchars($siteName, ENT_QUOTES),
        'image'    => htmlspecialchars($imgUrl, ENT_QUOTES),
        'url'      => htmlspecialchars($url, ENT_QUOTES),
        'fullTitle'=> htmlspecialchars($page ? "{$title} — {$siteName}" : $siteName, ENT_QUOTES),
    ];
})();
?>
<!DOCTYPE html>
<!--
 ╔══════════════════════════════════════════════════════════╗
 ║  Webstudio Docs                                          ║
 ║  Open-source self-hosted documentation platform          ║
 ║  Built with ♥ by webstudio.ltd                           ║
 ║  https://github.com/webstudio-ltd/docs                   ║
 ║                                                          ║
 ║  Free to use. If this saves you money on GitBook,        ║
 ║  consider giving us a ⭐ on GitHub.                      ║
 ╚══════════════════════════════════════════════════════════╝
-->
<html lang="de" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="generator" content="Webstudio Docs — webstudio.ltd">
<meta name="description" content="<?= $_ogData['desc'] ?>">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $_ogData['url'] ?>">
<meta property="og:title" content="<?= $_ogData['title'] ?>" id="og-title">
<meta property="og:description" content="<?= $_ogData['desc'] ?>" id="og-desc">
<meta property="og:site_name" content="<?= $_ogData['siteName'] ?>" id="og-site">
<meta property="og:image" content="<?= $_ogData['image'] ?>" id="og-image">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $_ogData['title'] ?>">
<meta name="twitter:description" content="<?= $_ogData['desc'] ?>">
<meta name="twitter:image" content="<?= $_ogData['image'] ?>">
<link rel="canonical" href="<?= $_ogData['url'] ?>">
<link rel="icon" id="dynamic-favicon" href="data:,">
<title id="doc-title"><?= $_ogData['fullTitle'] ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" id="prism-theme">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2.26.5"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/header@2.8.1"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/nested-list@1.4.2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/code@2.9.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/quote@2.6.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/delimiter@1.4.2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/inline-code@1.5.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/marker@1.4.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/checklist@1.6.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/table@2.3.0"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js" defer></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── TOKENS ── */
:root {
  --accent: #f97316;
  --accent-rgb: 249,115,22;
  --radius: 6px;
  --sidebar-w: 272px;
  --toc-w: 190px;
  --nav-h: 50px;
  --tab-h: 40px;
  --total-h: 90px; /* nav + tab */
  --font: 'Geist', system-ui, sans-serif;
  --mono: 'Geist Mono', monospace;
  --transition: 0.15s ease;
}

[data-theme="dark"] {
  --bg:       #111113;
  --bg2:      #18181b;
  --bg3:      #1f1f23;
  --bg4:      #28282d;
  --bg5:      #323237;
  --border:   #2a2a2f;
  --border2:  #35353c;
  --text:     #e4e4e7;
  --text2:    #a1a1aa;
  --text3:    #52525b;
  --text4:    #3f3f46;
  --shadow:   rgba(0,0,0,0.5);
  --invert:   0;
}

[data-theme="light"] {
  --bg:       #ffffff;
  --bg2:      #fafafa;
  --bg3:      #f4f4f5;
  --bg4:      #e4e4e7;
  --bg5:      #d4d4d8;
  --border:   #e4e4e7;
  --border2:  #d4d4d8;
  --text:     #18181b;
  --text2:    #52525b;
  --text3:    #a1a1aa;
  --text4:    #d4d4d8;
  --shadow:   rgba(0,0,0,0.1);
  --invert:   1;
}

html { font-size: 14px; scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font);
  line-height: 1.6;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  -webkit-font-smoothing: antialiased;
}

/* ════════════════════════════
   TOP NAV
════════════════════════════ */
.topnav {
  height: var(--nav-h);
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  display: grid;
  grid-template-columns: var(--sidebar-w) 1fr auto;
  align-items: center;
  position: fixed; top: 0; left: 0; right: 0;
  z-index: 200;
}
.logo-area {
  display: flex; align-items: center; gap: 9px;
  flex-shrink: 0; cursor: pointer;
  padding: 0 16px; height: 100%;
  transition: background var(--transition);
  overflow: hidden;
}
.logo-area:hover { background: var(--bg3); }
.logo-img {
  width: 26px; height: 26px; border-radius: 5px;
  flex-shrink: 0; background: var(--bg4);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--text3); overflow: hidden;
}
.logo-img img { width: 100%; height: 100%; object-fit: cover; }
.logo-img i { font-size: 12px; }
.logo-name {
  font-size: 14px; font-weight: 600; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Search — center column, capped width */
.search-wrap {
  display: flex; align-items: center; justify-content: center;
  padding: 0 16px;
}
.search-box {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 0 11px; height: 32px;
  cursor: text; transition: border-color var(--transition);
  width: 100%; max-width: 380px;
}
.search-box:focus-within { border-color: var(--accent); }
.search-box i { color: var(--text3); font-size: 12px; flex-shrink: 0; }
.search-box input {
  background: none; border: none; outline: none;
  font: 13px var(--font); color: var(--text); width: 100%;
}
.search-box input::placeholder { color: var(--text3); }
.search-kbd {
  font-size: 10px; color: var(--text3);
  background: var(--bg4); border: 1px solid var(--border2);
  border-radius: 4px; padding: 1px 5px;
  font-family: var(--mono); white-space: nowrap; flex-shrink: 0;
}

/* Right actions */
.nav-right {
  display: flex; align-items: center; gap: 4px;
  padding: 0 16px; flex-shrink: 0;
}
.nav-divider {
  width: 1px; height: 20px;
  background: var(--border); margin: 0 6px; flex-shrink: 0;
}
.icon-btn {
  width: 34px; height: 34px; border-radius: var(--radius);
  border: none; background: none; color: var(--text2); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; transition: all var(--transition);
}
.icon-btn:hover { background: var(--bg3); color: var(--text); }
.icon-btn.disabled { opacity: 0.3; pointer-events: none; }
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 16px; border-radius: var(--radius);
  font: 13.5px/1 var(--font); font-weight: 500;
  cursor: pointer; border: none; transition: all var(--transition);
  white-space: nowrap;
}
.btn-ghost { background: transparent; color: var(--text2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg3); color: var(--text); border-color: var(--border2); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { filter: brightness(1.1); }
.btn-success { background: #16a34a; color: #fff; display: none; }
.btn-success:hover { background: #15803d; }
.btn-success.show { display: inline-flex; }
.auth-nav-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 7px 14px; border-radius: var(--radius);
  font: 13.5px/1 var(--font); font-weight: 500;
  cursor: pointer; border: 1px solid var(--border);
  background: transparent; color: var(--text2);
  transition: all var(--transition); white-space: nowrap;
}
.auth-nav-btn:hover { background: var(--bg3); color: var(--text); }
.auth-nav-btn.authed { color: #16a34a; border-color: rgba(22,163,74,0.3); background: rgba(22,163,74,0.06); }
.auth-nav-btn.authed:hover { background: rgba(22,163,74,0.12); }

/* ════════════════════════════
   TAB BAR — second row, full width (GitBook style)
════════════════════════════ */
.tabbar {
  height: var(--tab-h);
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: stretch;
  position: fixed; top: var(--nav-h); left: 0; right: 0;
  z-index: 190; overflow-x: auto; scrollbar-width: none;
}
.tabbar::-webkit-scrollbar { display: none; }
.tab-strip {
  display: flex; align-items: stretch;
  overflow-x: auto; scrollbar-width: none;
}
.tab-strip::-webkit-scrollbar { display: none; }
.tab-item {
  display: flex; align-items: center; gap: 7px;
  padding: 0 20px; font-size: 13px; color: var(--text2);
  cursor: pointer; border-bottom: 2px solid transparent;
  white-space: nowrap; flex-shrink: 0;
  transition: all var(--transition); height: 100%;
}
.tab-item:hover { color: var(--text); background: rgba(var(--accent-rgb),0.04); }
.tab-item.active { color: var(--text); border-bottom-color: var(--accent); font-weight: 500; }
.tab-item i { font-size: 12px; color: var(--text3); }
.tab-item.active i { color: var(--accent); }
.tab-edit-btn {
  display: none; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 5px; border: none;
  background: transparent; color: var(--text3); cursor: pointer;
  font-size: 11px; padding: 0; margin-left: 2px; flex-shrink: 0;
  transition: all .15s;
}
.tab-edit-btn:hover { background: var(--bg3); color: var(--text); }
.tab-item:hover .tab-edit-btn,
.tab-item.active .tab-edit-btn { display: flex; }
.tab-add {
  display: none; align-items: center; gap: 5px;
  padding: 0 14px; color: var(--text3); cursor: pointer;
  font-size: 12.5px; flex-shrink: 0; height: 100%;
  border: none; background: none; font-family: var(--font);
  transition: color var(--transition);
}
.tab-add:hover { color: var(--text2); }
.tab-add.admin-visible { display: flex; }

/* ════════════════════════════
   LAYOUT
════════════════════════════ */
.layout {
  display: flex;
  margin-top: var(--total-h);
  min-height: calc(100vh - var(--total-h));
}

/* ════════════════════════════
   SIDEBAR
════════════════════════════ */
.sidebar {
  width: var(--sidebar-w);
  background: var(--bg2);
  border-right: 1px solid var(--border);
  position: fixed;
  top: var(--total-h);
  left: 0; bottom: 0;
  overflow-y: auto; overflow-x: hidden;
  display: flex; flex-direction: column;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border); }

.sidebar-body { flex: 1; padding: 10px 0 16px; }

.section-label {
  font-size: 10.5px; font-weight: 600;
  letter-spacing: 0.07em; text-transform: uppercase;
  color: var(--text3); padding: 14px 16px 5px;
  display: flex; align-items: center; justify-content: space-between;
}
.section-label .section-add {
  opacity: 0; cursor: pointer; color: var(--text3); font-size: 11px;
  background: none; border: none; transition: opacity var(--transition);
  padding: 2px 4px; border-radius: 3px;
}
.section-label:hover .section-add { opacity: 1; }
.section-label .section-add:hover { color: var(--text2); background: var(--bg4); }

.nav-divider { height: 1px; background: var(--border); margin: 8px 0; }

.nav-item {
  display: flex; align-items: center;
  padding: 5px 16px 5px 12px;
  font-size: 13.5px; color: var(--text2);
  cursor: pointer; border-left: 2px solid transparent;
  transition: all 0.12s; user-select: none;
  gap: 0; position: relative; min-height: 30px;
}
.nav-item:hover { color: var(--text); background: var(--bg3); }
.nav-item.active {
  color: var(--accent); border-left-color: var(--accent);
  background: rgba(var(--accent-rgb), 0.08); font-weight: 500;
}
.nav-item.active .nav-ic { color: var(--accent); }

.nav-indent { display: block; flex-shrink: 0; }

.nav-toggle {
  width: 18px; height: 18px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: var(--text3); font-size: 9px; border-radius: 3px;
  transition: all 0.15s; margin-right: 2px;
}
.nav-toggle:hover { background: var(--bg5); color: var(--text2); }
.nav-toggle.open { transform: rotate(90deg); }
.nav-toggle-spacer { width: 18px; flex-shrink: 0; margin-right: 2px; }

.nav-ic {
  width: 16px; flex-shrink: 0; text-align: center;
  font-size: 12px; color: var(--text3); margin-right: 7px;
  transition: color 0.12s;
}
.nav-item:hover .nav-ic { color: var(--text2); }

.nav-label {
  flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.nav-actions {
  display: flex; gap: 2px;
  opacity: 0; transition: opacity 0.1s; flex-shrink: 0;
}
.nav-item:hover .nav-actions { opacity: 1; }

.na-btn {
  width: 24px; height: 24px; border-radius: 5px;
  border: none; background: none; cursor: pointer;
  color: var(--text3); font-size: 11px;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.1s;
}
.na-btn:hover { background: var(--bg5); color: var(--text2); }
.na-btn-danger:hover { background: rgba(239,68,68,.12) !important; color: #ef4444 !important; }

.nav-children { display: none; }
.nav-children.open { display: block; }

.sidebar-footer {
  padding: 10px 14px 12px;
  border-top: 1px solid var(--border);
  font-size: 11.5px; color: var(--text3);
  display: flex; align-items: center; gap: 6px;
}
.sidebar-footer i { font-size: 11px; }
.sidebar-footer a {
  color: var(--text3); text-decoration: none;
  transition: color var(--transition);
}
.sidebar-footer a:hover { color: var(--text2); }

.add-page-row {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 14px; font-size: 12.5px; color: var(--text3);
  cursor: pointer; border: none; background: none;
  width: 100%; font-family: var(--font);
  transition: color var(--transition);
}
.add-page-row:hover { color: var(--text2); }
.add-page-row i { font-size: 11px; }

/* ════════════════════════════
   MAIN CONTENT
════════════════════════════ */
.main {
  margin-left: var(--sidebar-w);
  flex: 1; display: flex; justify-content: center;
  min-width: 0;
}

.content-wrap {
  flex: 1; max-width: 860px;
  padding: 48px 60px 100px;
  min-width: 0;
}

.breadcrumb {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; color: var(--text3); margin-bottom: 20px;
  text-transform: uppercase; letter-spacing: 0.04em;
}
.breadcrumb span { cursor: pointer; transition: color var(--transition); display: flex; align-items: center; gap: 4px; }
.breadcrumb span:hover { color: var(--text); }
.breadcrumb span:last-child { color: var(--text2); cursor: default; font-weight: 600; pointer-events: none; }
.breadcrumb i.fa-chevron-right { font-size: 8px; color: var(--text4); }

.page-hero { display: flex; align-items: flex-start; gap: 14px; }

/* ── Page Cover ── */
.page-cover {
  width: 100%; height: 160px; border-radius: 10px;
  margin-bottom: 24px; position: relative; overflow: hidden;
  background: linear-gradient(135deg, var(--accent) 0%, #7c3aed 100%);
  cursor: pointer;
}
.page-cover img { width: 100%; height: 100%; object-fit: cover; object-position: center; }
.page-cover-actions {
  position: absolute; bottom: 10px; right: 10px;
  display: flex; gap: 6px; opacity: 0; transition: opacity 0.15s;
}
.page-cover:hover .page-cover-actions { opacity: 1; }
.page-cover-btn {
  font-size: 11px; padding: 4px 10px; border-radius: 5px;
  background: rgba(0,0,0,0.5); color: #fff; border: none;
  cursor: pointer; font-family: var(--font); backdrop-filter: blur(4px);
  display: flex; align-items: center; gap: 5px;
}
.page-cover-btn:hover { background: rgba(0,0,0,0.7); }

/* Cover position panel */
.cover-pos-panel {
  position: absolute; bottom: 10px; left: 10px;
  background: rgba(0,0,0,0.55); backdrop-filter: blur(6px);
  border-radius: 8px; padding: 6px 10px;
  display: flex; align-items: center; gap: 8px;
  opacity: 0; transition: opacity .15s;
}
.page-cover:hover .cover-pos-panel { opacity: 1; }
[data-edit="1"] .cover-pos-panel { opacity: 1; }
.cover-pos-label { font-size: 11px; color: rgba(255,255,255,.7); white-space: nowrap; }
.cover-pos-btns { display: flex; gap: 4px; }
.cover-pos-btn {
  font-size: 10px; padding: 3px 8px; border-radius: 5px;
  background: rgba(255,255,255,.15); color: #fff; border: none;
  cursor: pointer; font-family: var(--font); transition: background .15s;
  white-space: nowrap;
}
.cover-pos-btn:hover, .cover-pos-btn.active { background: rgba(255,255,255,.35); }
.cover-add-btn {
  position: absolute; top: 10px; left: 0; right: 0;
  display: flex; justify-content: center; opacity: 0; transition: opacity 0.15s;
}
/* show add cover button on hero hover when no cover */
.page-hero-wrap:hover .cover-add-btn { opacity: 1; }

/* ── Reading time ── */
.reading-time {
  font-size: 12px; color: var(--text3);
  display: flex; align-items: center; gap: 5px;
  white-space: nowrap; flex-shrink: 0;
}

/* ── Collapsible ── */
.collapsible-block {
  border: 1px solid var(--border); border-radius: 8px;
  overflow: hidden; margin: 4px 0;
}
.collapsible-header {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; cursor: pointer;
  background: var(--bg2); user-select: none;
  transition: background var(--transition);
}
.collapsible-header:hover { background: var(--bg3); }
.collapsible-chevron {
  font-size: 11px; color: var(--text3); transition: transform 0.2s;
  font-style: normal; flex-shrink: 0;
}
.collapsible-block.open .collapsible-chevron { transform: rotate(90deg); }
.collapsible-title-text {
  font-weight: 600; font-size: 14px; color: var(--text);
  flex: 1; background: none; border: none; outline: none;
  font-family: var(--font);
}
.collapsible-body {
  padding: 12px 16px; display: none;
  font-size: 14px; color: var(--text2); line-height: 1.6;
  border-top: 1px solid var(--border);
}
.collapsible-body textarea {
  width: 100%; background: none; border: none; outline: none;
  font-size: 14px; color: var(--text2); line-height: 1.6;
  font-family: var(--font); resize: none; min-height: 60px;
}
.collapsible-block.open .collapsible-body { display: block; }

.page-icon-wrap { position: relative; flex-shrink: 0; }

.page-icon {
  width: 38px; height: 38px;
  border-radius: 7px; display: flex; align-items: center;
  justify-content: center; font-size: 22px;
  color: var(--text2); background: transparent;
  cursor: pointer; transition: all var(--transition);
  border: none;
}
.page-icon:hover { color: var(--text); }
.page-icon i { pointer-events: none; }

.page-title-input {
  background: none; border: none; outline: none;
  font: 700 30px/1.2 var(--font); color: var(--text);
  width: 100%; padding: 4px 0;
}
.page-title-input::placeholder { color: var(--text4); }
.page-title-input[readonly] { cursor: default; }

.page-desc {
  font-size: 15px; color: var(--text2);
  line-height: 1.6; min-height: 22px;
  word-break: break-word; overflow-wrap: break-word;
  white-space: pre-wrap;
}
.page-desc[contenteditable="true"]:empty::before {
  content: 'Short page description...'; color: var(--text4);
}
.page-desc:focus { outline: none; }

.page-divider {
  height: 1px; background: var(--border); margin-bottom: 60px;
}

/* ════════════════════════════
   EDITOR STYLES
════════════════════════════ */
#editor { min-height: 300px; }

.codex-editor { color: var(--text) !important; }
/* Override EditorJS internal CSS variables */
.ce-popover, .ce-settings, .ce-toolbox, .ce-inline-toolbar, .ce-conversion-toolbar {
  --color-bg: var(--bg2);
  --color-text-primary: var(--text);
  --color-text-secondary: var(--text2);
  --color-border: var(--border2);
  --bg-light-gray: var(--bg3);
  --color-active-icon: var(--accent);
  color-scheme: dark;
}
.codex-editor__redactor { padding-bottom: 24px !important; }
/* Toolbar in natural left gutter via negative positioning — content stays full width */
.ce-toolbar__actions { position: absolute !important; right: 100% !important; left: auto !important; top: 0 !important; padding-right: 6px !important; display: flex !important; gap: 2px !important; }

/* Block spacing — comfortable gaps between blocks */
.ce-block { margin: 4px 0 !important; overflow: visible !important; }
.ce-block + .ce-block { margin-top: 8px !important; }
.codex-editor, .codex-editor__redactor, .ce-block__content { overflow: visible !important; }

/* Extra breathing room before headings */
.ce-block:has(h1.ce-header),
.ce-block:has(h2.ce-header),
.ce-block:has(h3.ce-header) { margin-top: 28px !important; }

/* More space after headings (before next block) */
.ce-block:has(h1.ce-header) + .ce-block,
.ce-block:has(h2.ce-header) + .ce-block,
.ce-block:has(h3.ce-header) + .ce-block { margin-top: 14px !important; }

/* More space for block-type elements */
.ce-block:has(.cdx-quote),
.ce-block:has(.callout-block),
.ce-block:has(.ce-code),
.ce-block:has(.cdx-checklist),
.ce-block:has(.tc-wrap),
.ce-block:has(.local-image-tool),
.ce-block:has(.cdx-delimiter) { margin: 14px 0 !important; }

.ce-paragraph {
  font-size: 15px; line-height: 1.75; color: var(--text);
  font-family: var(--font) !important;
}
.ce-paragraph[data-placeholder]:empty::before { color: var(--text3) !important; }
.codex-editor--read-only .ce-paragraph[data-placeholder]:empty::before { display: none !important; }
body:not([data-edit]) .ce-paragraph[data-placeholder]:empty::before { display: none !important; }

.ce-header { color: var(--text) !important; font-family: var(--font) !important; }
.ce-header[data-placeholder]:empty::before { color: var(--text3) !important; }
h1.ce-header { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
h2.ce-header { font-size: 21px; font-weight: 650; margin: 0 0 0; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
h3.ce-header { font-size: 17px; font-weight: 600; margin: 0 0 2px; }

.cdx-list { color: var(--text); padding-left: 20px; }
.cdx-list__item { font-size: 15px; line-height: 1.75; padding: 2px 0; }

.cdx-quote {
  background: var(--bg3);
  border-left: 3px solid var(--accent);
  border-radius: 0 7px 7px 0;
  padding: 14px 18px; margin: 16px 0;
}
.cdx-quote__text {
  color: var(--text2) !important; font-style: italic;
  font-size: 15px; background: transparent !important;
  border: none !important; outline: none !important;
}
.cdx-quote__caption {
  color: var(--text3) !important; font-size: 12px;
  background: transparent !important; border: none !important;
  outline: none !important; margin-top: 6px;
}

.ce-code {
  background: var(--bg3) !important;
  border: 1px solid var(--border) !important;
  border-radius: 8px !important;
  padding: 0 !important;
  overflow: hidden !important;
  position: relative !important;
}
.ce-code .code-copy-btn {
  position: absolute; top: 8px; right: 8px;
  background: var(--bg4); border: 1px solid var(--border2);
  border-radius: 5px; padding: 4px 8px; cursor: pointer;
  font-size: 11px; color: var(--text3); font-family: var(--font);
  display: flex; align-items: center; gap: 4px;
  opacity: 0; transition: opacity .15s;
  z-index: 2;
}
.ce-code:hover .code-copy-btn { opacity: 1; }
.ce-code .code-copy-btn:hover { background: var(--bg5); color: var(--text); }
/* Syntax highlighted code (read mode overlay) */
.ce-code .code-highlighted {
  position: absolute; inset: 0; pointer-events: none;
  padding: 14px 16px; overflow: auto;
  font-family: var(--mono) !important; font-size: 13px !important; line-height: 1.6 !important;
  background: transparent; z-index: 1;
}
.ce-code .code-highlighted pre { margin: 0; background: transparent !important; padding: 0 !important; border: none !important; }
.ce-code .code-highlighted code { font-family: var(--mono) !important; font-size: 13px !important; line-height: 1.6 !important; background: transparent !important; }
/* Light theme Prism overrides */
[data-theme="light"] .ce-code .code-highlighted pre[class*="language-"],
[data-theme="light"] .ce-code .code-highlighted code[class*="language-"] { background: transparent !important; text-shadow: none !important; }
.ce-code__textarea {
  background: transparent !important;
  color: var(--text) !important;
  font-family: var(--mono) !important;
  font-size: 13px !important;
  min-height: 80px !important;
  padding: 14px 16px !important;
  line-height: 1.6 !important;
  border: none !important;
  outline: none !important;
  box-shadow: none !important;
  resize: vertical !important;
  border-radius: 0 !important;
  width: 100% !important;
  display: block !important;
}
[data-theme="dark"] .ce-code__textarea { color: #7dd3fc !important; }
[data-theme="light"] .ce-code__textarea { color: #0f4c81 !important; }
/* Hide the ugly default resize handle — replaced by overflow:hidden on parent */
.ce-code__textarea::-webkit-resizer { display: none; }

/* ── Callout types ── */
.callout-block {
  display: flex; align-items: flex-start; gap: 10px;
  border-radius: 8px; padding: 12px 14px;
  border-left: 3px solid;
}
/* contenteditable placeholder */
.callout-block [contenteditable][data-placeholder]:empty::before {
  content: attr(data-placeholder);
  color: var(--text4); pointer-events: none; font-weight: normal;
}

/* Callout inline toolbar */
.callout-toolbar {
  position: fixed; z-index: 1000;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 8px; padding: 4px;
  box-shadow: 0 4px 20px var(--shadow);
  display: none; align-items: center; gap: 1px;
  pointer-events: all;
}
.ct-btn {
  width: 28px; height: 28px; border: none; background: none;
  border-radius: 5px; cursor: pointer; color: var(--text2);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; transition: all .15s;
}
.ct-btn:hover { background: var(--bg3); color: var(--text); }
.ct-btn.active { background: var(--bg4); color: var(--text); }
.ct-sep { width: 1px; height: 16px; background: var(--border); margin: 0 2px; }
/* info (default) */
.callout-block.callout-info   { background: rgba(var(--accent-rgb),.07); border-color: var(--accent); }
/* tip — zelená */
.callout-block.callout-tip    { background: rgba(22,163,74,.07);  border-color: #16a34a; }
/* warning — žltá */
.callout-block.callout-warning { background: rgba(234,179,8,.07); border-color: #ca8a04; }
/* danger — červená */
.callout-block.callout-danger  { background: rgba(220,38,38,.07); border-color: #dc2626; }

.callout-icon {
  flex-shrink: 0; width: 20px; height: 20px; margin-top: 2px;
  display: flex; align-items: center; justify-content: center;
}
.callout-icon svg { width: 18px; height: 18px; }
.callout-info    .callout-icon { color: var(--accent); }
.callout-tip     .callout-icon { color: #16a34a; }
.callout-warning .callout-icon { color: #ca8a04; }
.callout-danger  .callout-icon { color: #dc2626; }

.callout-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.callout-title {
  font-weight: 600; font-size: 14px; outline: none;
  min-height: 1.4em; word-break: break-word; white-space: normal;
}
.callout-info    .callout-title { color: var(--accent); }
.callout-tip     .callout-title { color: #16a34a; }
.callout-warning .callout-title { color: #ca8a04; }
.callout-danger  .callout-title { color: #dc2626; }
.callout-title:empty::before { content: attr(data-placeholder); opacity: 0.4; font-weight: 400; }
.callout-message {
  font-size: 14px; line-height: 1.6; color: var(--text2);
  outline: none; min-height: 1.6em; word-break: break-word; white-space: normal;
}
.callout-message:empty::before { content: attr(data-placeholder); color: var(--text4); }

/* Callout type picker (edit mode) */
.callout-type-picker {
  display: flex; gap: 4px; margin-bottom: 6px;
}
.callout-type-btn {
  padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;
  border: 1px solid transparent; cursor: pointer; background: none;
  transition: all var(--transition);
}
.callout-type-btn.info    { color: var(--accent); border-color: rgba(var(--accent-rgb),.3); }
.callout-type-btn.tip     { color: #16a34a; border-color: rgba(22,163,74,.3); }
.callout-type-btn.warning { color: #ca8a04; border-color: rgba(202,138,4,.3); }
.callout-type-btn.danger  { color: #dc2626; border-color: rgba(220,38,38,.3); }
.callout-type-btn.active  { filter: brightness(1); opacity: 1; }
.callout-type-btn:not(.active) { opacity: 0.45; }

/* ── Timeline ── */
.tl-wrap { padding: 4px 0; position: relative; z-index: 0; }
.tl-toolbar {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 20px; padding: 8px 12px;
  background: var(--bg3); border-radius: 8px;
  border: 1px solid var(--border);
}
.tl-toolbar label {
  display: flex; align-items: center; gap: 7px;
  font-size: 13px; color: var(--text2); cursor: pointer; user-select: none;
}
.tl-toolbar input[type=checkbox] { accent-color: var(--accent); width: 14px; height: 14px; cursor: pointer; }
.tl-item { display: flex; gap: 0; position: relative; align-items: flex-start; }
.tl-left {
  display: flex; flex-direction: column; align-items: center;
  width: 40px; flex-shrink: 0; align-self: stretch;
}
.tl-line {
  width: 2px; background: var(--border);
  flex: 1; min-height: 16px;
}
.tl-line.tl-line-top { background: transparent; max-height: 6px; min-height: 6px; flex: none; }
.tl-dot {
  width: 14px; height: 14px; border-radius: 50%;
  background: var(--bg); border: 2.5px solid var(--accent);
  flex-shrink: 0; z-index: 1;
}
.tl-dot-num {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--accent); border: none;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 15px; font-weight: 700;
  font-family: var(--font); flex-shrink: 0; z-index: 1;
}
.tl-content {
  flex: 1; padding: 4px 0 40px 20px; min-width: 0;
}
.tl-item:last-child .tl-content { padding-bottom: 8px; }
.tl-date {
  font-size: 11px; color: var(--text3); font-weight: 500;
  text-transform: uppercase; letter-spacing: 0.05em;
  margin-bottom: 5px;
}
.tl-title { font-weight: 700; font-size: 17px; color: var(--text); margin-bottom: 6px; line-height: 1.3; }
.tl-desc  { font-size: 14px; color: var(--text2); line-height: 1.6; }
.tl-add-btn {
  display: flex; align-items: center; gap: 5px;
  margin: 10px 0 10px 60px;
  font-size: 12px; color: var(--text4); background: none;
  border: 1px dashed var(--border); border-radius: 5px;
  padding: 4px 12px; cursor: pointer; font-family: var(--font);
  transition: all var(--transition); font-style: normal;
}
.tl-add-btn:hover { color: var(--accent); border-color: rgba(var(--accent-rgb),.5); background: rgba(var(--accent-rgb),.05); }

/* ── Page last updated ── */
.page-last-updated {
  font-size: 12px; color: var(--text2);
  padding: 16px 0; margin: 0;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 6px;
}

/* Table */
.tc-wrap { --color-border: var(--border) !important; }
.tc-table { color: var(--text) !important; }
.tc-cell { background: var(--bg) !important; color: var(--text) !important; border-color: var(--border) !important; }
.tc-add-column, .tc-add-row { color: var(--text2) !important; background: var(--bg3) !important; }

/* Checklist — proper accent checkbox, no orange bg */
.cdx-checklist__item { display: flex; align-items: center; gap: 10px; background: transparent !important; }
.cdx-checklist__item--checked { background: transparent !important; }
.cdx-checklist__item-text {
  color: var(--text) !important; font-size: 15px !important;
  background: transparent !important; border: none !important;
  outline: none !important;
}
.cdx-checklist__item-checkbox {
  width: 18px !important; height: 18px !important;
  border: 1.5px solid var(--border2) !important;
  background: transparent !important;
  border-radius: 4px !important;
  flex-shrink: 0 !important;
  display: flex !important; align-items: center !important; justify-content: center !important;
  cursor: pointer !important; transition: all 0.15s !important;
  box-shadow: none !important;
}
.cdx-checklist__item-checkbox:hover {
  border-color: var(--accent) !important;
  background: rgba(var(--accent-rgb), 0.06) !important;
}
.cdx-checklist__item--checked .cdx-checklist__item-checkbox {
  background: var(--accent) !important;
  border-color: var(--accent) !important;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 8'%3E%3Cpath d='M1 4l3 3 5-6' stroke='white' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
  background-repeat: no-repeat !important;
  background-position: center !important;
  background-size: 11px !important;
}
.cdx-checklist__item--checked .cdx-checklist__item-text {
  color: var(--text3) !important;
  text-decoration: line-through !important;
}

/* LocalImageTool */
.local-image-tool { position: relative; text-align: center; margin: 4px 0; }
.local-image-tool.lit-stretched { margin-left: calc(-1 * var(--content-pad, 0px)); margin-right: calc(-1 * var(--content-pad, 0px)); }
.local-image-tool.lit-border .lit-img { border: 1px solid var(--border) !important; }
.local-image-tool.lit-bg { background: var(--bg3); border-radius: 8px; padding: 16px; }

.lit-img {
  max-width: 100%; height: auto; display: block;
  margin: 0 auto; border-radius: 6px;
}
.lit-stretched .lit-img { max-width: 100%; width: 100%; border-radius: 0; }

.lit-caption {
  margin-top: 8px; font-size: 13px; color: var(--text3);
  text-align: center; outline: none; min-height: 1em;
}
.lit-caption:empty::before { content: attr(data-placeholder); color: var(--text4); }

.lit-del-btn {
  position: absolute; top: 8px; right: 8px;
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); cursor: pointer; opacity: 0;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s;
}
.local-image-tool:hover .lit-del-btn { opacity: 1; }
.lit-del-btn:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

.lit-drop-zone {
  border: 2px dashed var(--border2); border-radius: 8px;
  padding: 28px 20px; display: flex; flex-direction: column;
  align-items: center; gap: 8px; cursor: pointer;
  color: var(--text3); transition: all 0.15s;
}
.lit-drop-zone:hover, .lit-drop-zone.drag-over {
  border-color: var(--accent); background: rgba(var(--accent-rgb), 0.04);
  color: var(--text2);
}
.lit-drop-zone svg { opacity: 0.5; }
.lit-drop-label { font-size: 13.5px; }
.lit-pick-btn { color: var(--accent); cursor: pointer; text-decoration: underline; }
.lit-drop-sub { font-size: 12px; color: var(--text4); }
.lit-url-row { display: flex; gap: 6px; width: 100%; max-width: 400px; margin-top: 4px; }
.lit-url-input {
  flex: 1; padding: 5px 10px; border-radius: var(--radius);
  border: 1px solid var(--border); background: var(--bg3);
  color: var(--text); font: 13px var(--font); outline: none;
}
.lit-url-input:focus { border-color: var(--accent); }
.lit-url-btn {
  padding: 5px 12px; border-radius: var(--radius);
  background: var(--accent); color: #fff; border: none;
  font: 13px var(--font); cursor: pointer; white-space: nowrap;
  font-weight: 500;
}
.lit-url-btn:hover { filter: brightness(1.1); }

/* Editor UI chrome */
/* Hide native toolbox — we replace it with our slash menu */
.ce-toolbox { display: none !important; }
/* Native settings panel — styled to match our UI */
.ce-popover__overlay,
.ce-popover__overlay--hidden {
  display: none !important;
  visibility: hidden !important;
  pointer-events: none !important;
  width: 0 !important; height: 0 !important;
}
.ce-toolbar { background: transparent !important; border: none !important; }
.ce-toolbar__plus {
  color: var(--text3) !important;
  background: transparent !important;
  border: 1px solid transparent !important;
  border-radius: 6px !important;
  width: 26px !important; height: 26px !important;
  transition: all .15s !important;
}
.ce-toolbar__plus:hover {
  color: var(--text) !important;
  background: var(--bg3) !important;
  border-color: var(--border) !important;
}
/* Replace settings btn with our custom one */
.ce-toolbar__settings-btn {
  color: var(--text3) !important;
  background: transparent !important;
  border: 1px solid transparent !important;
  border-radius: 6px !important;
  width: 26px !important; height: 26px !important;
  transition: all .15s !important;
  cursor: grab !important;
}
.ce-toolbar__settings-btn:active { cursor: grabbing !important; }

.block-drop-indicator {
  height: 48px; border-radius: 8px;
  margin: 4px 0; pointer-events: none;
  background: rgba(var(--accent-rgb), 0.06);
  border: 2px dashed rgba(var(--accent-rgb), 0.4);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; color: var(--accent); font-family: var(--font);
  opacity: 0.8;
}
.ce-toolbar__settings-btn:hover {
  color: var(--text) !important;
  background: var(--bg3) !important;
  border-color: var(--border) !important;
}

/* Our custom block menu */
.block-menu {
  position: fixed; z-index: 9999;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 12px; padding: 6px;
  box-shadow: 0 12px 40px rgba(0,0,0,.6);
  min-width: 160px;
}
.block-menu-item {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 10px; border-radius: 7px; cursor: pointer;
  font-size: 13px; color: var(--text); transition: background .1s;
}
.block-menu-item:hover { background: var(--bg3); }
.block-menu-item.danger { color: #ef4444; }
.block-menu-icon {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--bg3); display: flex; align-items: center;
  justify-content: center; flex-shrink: 0; font-size: 13px;
  color: var(--text2); transition: all .12s; font-style: normal;
}
.block-menu-item:hover .block-menu-icon { background: rgba(var(--accent-rgb),.15); color: var(--accent); }
.block-menu-item.active { color: var(--accent) !important; }
.block-menu-item.active .block-menu-icon { background: rgba(var(--accent-rgb),.15) !important; color: var(--accent) !important; }

/* ── Popover (toolbox dropdown) ── */
.ce-popover,
.ce-toolbox {
  background: var(--bg2) !important;
  border: 1px solid var(--border2) !important;
  border-radius: 12px !important;
  box-shadow: 0 12px 40px rgba(0,0,0,.45), 0 2px 8px rgba(0,0,0,.2) !important;
  padding: 6px !important;
  min-width: 240px !important;
}

/* Hide the overlay ghost element */
.ce-popover__overlay,
.ce-popover__overlay--hidden {
  display: none !important;
  visibility: hidden !important;
  opacity: 0 !important;
  pointer-events: none !important;
  width: 0 !important; height: 0 !important;
}

/* Search field */
.cdx-search-field {
  background: var(--bg3) !important;
  border: 1px solid var(--border) !important;
  border-radius: 7px !important;
  margin-bottom: 4px !important;
  padding: 2px 4px !important;
}
.cdx-search-field:focus-within { border-color: var(--accent) !important; }
.cdx-search-field__icon { color: var(--text4) !important; }
.cdx-search-field__icon svg { color: var(--text4) !important; }
.cdx-search-field__input {
  background: transparent !important;
  color: var(--text) !important;
  font-size: 13px !important;
  font-family: var(--font) !important;
  border: none !important;
  outline: none !important;
  padding: 4px 6px !important;
}
.cdx-search-field__input::placeholder { color: var(--text4) !important; }

/* Each row */
.ce-popover__item {
  border-radius: 7px !important;
  padding: 7px 10px !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  cursor: pointer !important;
  transition: background .1s !important;
}
.ce-popover__item:hover,
.ce-popover__item--focused {
  background: var(--bg3) !important;
}
.ce-popover__item--active {
  background: rgba(var(--accent-rgb),.1) !important;
}

/* Icon box */
.ce-popover__item-icon {
  background: var(--bg3) !important;
  border: none !important;
  border-radius: 7px !important;
  width: 30px !important; height: 30px !important;
  min-width: 30px !important;
  display: flex !important; align-items: center !important; justify-content: center !important;
  color: var(--text2) !important;
  transition: all .12s !important;
  flex-shrink: 0 !important;
}
.ce-popover__item:hover .ce-popover__item-icon,
.ce-popover__item--focused .ce-popover__item-icon {
  background: rgba(var(--accent-rgb),.15) !important;
  color: var(--accent) !important;
}
.ce-popover__item-icon svg { color: inherit !important; }

/* Label */
.ce-popover__item-label {
  font-size: 13px !important;
  font-weight: 500 !important;
  color: var(--text) !important;
  font-family: var(--font) !important;
}
.ce-popover__item-secondary-label {
  font-size: 11px !important;
  color: var(--text4) !important;
}

/* Separator */
.ce-popover__item-separator { border-color: var(--border) !important; margin: 4px 0 !important; }
.ce-popover__item-separator--hidden { display: none !important; }

/* Nothing found */
.ce-popover__no-found {
  color: var(--text4) !important;
  font-size: 13px !important;
  padding: 8px 10px !important;
  font-family: var(--font) !important;
}

/* ── Inline toolbar (text selection) ── */
.ce-inline-toolbar {
  background: var(--bg2) !important;
  border: 1px solid var(--border2) !important;
  border-radius: 10px !important;
  box-shadow: 0 8px 28px rgba(0,0,0,.4), 0 2px 6px rgba(0,0,0,.2) !important;
  padding: 4px 6px !important;
  gap: 2px !important;
}
.ce-inline-toolbar__dropdown {
  border-right: 1px solid var(--border) !important;
  margin-right: 4px !important;
  padding-right: 4px !important;
  color: var(--text2) !important;
}
.ce-inline-toolbar__dropdown:hover { background: var(--bg3) !important; border-radius: 6px !important; color: var(--text) !important; }

.ce-inline-toolbar__buttons { gap: 2px !important; }
.ce-inline-toolbar__buttons button {
  color: var(--text3) !important;
  border-radius: 6px !important;
  width: 28px !important; height: 28px !important;
  display: flex !important; align-items: center !important; justify-content: center !important;
  transition: all .12s !important;
  background: transparent !important;
  border: none !important;
}
.ce-inline-toolbar__buttons button:hover {
  color: var(--text) !important;
  background: var(--bg3) !important;
}
.ce-inline-toolbar__buttons button.ce-inline-tool--active {
  color: var(--accent) !important;
  background: rgba(var(--accent-rgb),.12) !important;
}

/* Inline toolbar input (links) */
.ce-inline-tool-input {
  background: var(--bg3) !important;
  border: 1px solid var(--border) !important;
  border-radius: 6px !important;
  color: var(--text) !important;
  font-family: var(--font) !important;
  font-size: 13px !important;
  padding: 4px 8px !important;
  outline: none !important;
}
.ce-inline-tool-input:focus { border-color: var(--accent) !important; }

/* ── Conversion toolbar (block type switcher) ── */
.ce-conversion-toolbar {
  background: var(--bg2) !important;
  border: 1px solid var(--border2) !important;
  border-radius: 10px !important;
  box-shadow: 0 8px 28px rgba(0,0,0,.4) !important;
  padding: 4px !important;
}
.ce-conversion-toolbar__label { color: var(--text4) !important; font-size: 11px !important; padding: 4px 8px 2px !important; }
.ce-conversion-tool {
  color: var(--text2) !important;
  border-radius: 6px !important;
  font-size: 13px !important;
  font-family: var(--font) !important;
  padding: 5px 8px !important;
  transition: background .1s !important;
}
.ce-conversion-tool:hover { background: var(--bg3) !important; color: var(--text) !important; }
.ce-conversion-tool--focused { background: var(--bg3) !important; color: var(--text) !important; }
.ce-conversion-tool__icon {
  background: var(--bg3) !important;
  border: 1px solid var(--border) !important;
  border-radius: 6px !important;
  width: 26px !important; height: 26px !important;
}

/* ── Settings panel (block options) ── */
/* Native settings panel completely hidden — replaced by our custom block menu */
.ce-settings, .ce-settings * { display: none !important; }


.cdx-block { color: var(--text) !important; }
.ce-block--selected .ce-block__content { background: rgba(var(--accent-rgb), 0.05) !important; border-radius: 6px; }
.cdx-delimiter::before { color: var(--text4) !important; }

/* inline code */
.inline-code { background: var(--bg4) !important; color: #7dd3fc !important; border-radius: 4px; font-family: var(--mono) !important; font-size: 12.5px; padding: 1px 5px; }
.cdx-marker { background: rgba(var(--accent-rgb), 0.25) !important; color: var(--text) !important; border-radius: 2px; }

/* ════════════════════════════
   TOC RIGHT PANEL
════════════════════════════ */
.toc-panel {
  width: var(--toc-w); flex-shrink: 0;
  position: sticky; top: var(--total-h);
  height: calc(100vh - var(--total-h));
  padding: 40px 20px 40px 12px;
  overflow-y: auto; scrollbar-width: none;
}
.toc-panel::-webkit-scrollbar { display: none; }

.toc-head {
  font-size: 10.5px; font-weight: 600;
  letter-spacing: 0.07em; text-transform: uppercase;
  color: var(--text3); margin-bottom: 8px;
}

.toc-item {
  font-size: 12.5px; color: var(--text3);
  padding: 3px 0 3px 10px;
  cursor: pointer; border-left: 1px solid var(--border);
  line-height: 1.4; transition: color var(--transition);
  display: block;
}
.toc-item:hover { color: var(--text2); }
.toc-item.h3 { padding-left: 20px; font-size: 12px; }
.toc-item.active { color: var(--accent); border-left-color: var(--accent); }

.toc-sep { height: 1px; background: var(--border); margin: 24px 0; }

.toc-feedback-label { font-size: 12px; color: var(--text3); margin-bottom: 8px; }
.toc-feedback-btns { display: flex; gap: 5px; }
.toc-share-btn {
  display: flex; align-items: center; gap: 8px;
  width: 100%; padding: 8px 10px; border-radius: 8px;
  background: var(--bg3); border: 1px solid var(--border);
  color: var(--text2); font: 13px var(--font); cursor: pointer;
  transition: all .15s;
}
.toc-share-btn:hover { background: var(--bg4); color: var(--text); border-color: var(--border2); }
.toc-share-btn i { color: var(--accent); font-size: 12px; flex-shrink: 0; }
.toc-share-btn.copied { color: #22c55e; border-color: rgba(34,197,94,.3); background: rgba(34,197,94,.06); }
.toc-share-btn.copied i { color: #22c55e !important; }
.fb-btn {
  width: 28px; height: 28px; border-radius: 5px;
  border: 1px solid var(--border); background: none; cursor: pointer;
  font-size: 12px; color: var(--text3);
  display: flex; align-items: center; justify-content: center;
  transition: all var(--transition);
}
.fb-btn:hover { background: var(--bg3); border-color: var(--border2); color: var(--text2); }

/* ════════════════════════════
   PAGE BOTTOM NAV
════════════════════════════ */
.page-nav {
  display: flex; justify-content: space-between;
  padding-top: 24px;
  border-top: 1px solid var(--border);
  gap: 12px;
}
.page-nav-card {
  flex: 1; max-width: 240px; cursor: pointer;
  padding: 12px 16px; border-radius: 8px;
  border: 1px solid var(--border);
  transition: all var(--transition);
  display: flex; flex-direction: column; gap: 3px;
}
.page-nav-card:hover { border-color: var(--border2); background: var(--bg3); }
.page-nav-card.right { text-align: right; align-items: flex-end; }
.page-nav-dir { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 5px; }
.page-nav-title { font-size: 13.5px; color: var(--text); font-weight: 500; display: flex; align-items: center; gap: 7px; }
.page-nav-title i { font-size: 12px; color: var(--text3); }

/* ════════════════════════════
   MODALS
════════════════════════════ */
.overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0);
  z-index: 400; display: none;
  align-items: center; justify-content: center;
  backdrop-filter: blur(0px);
  transition: background .2s ease, backdrop-filter .2s ease;
}
.overlay.open { display: flex; }
.overlay.animate { background: rgba(0,0,0,0.55); backdrop-filter: blur(3px); }

.modal {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 12px; padding: 24px;
  box-shadow: 0 24px 64px var(--shadow);
  min-width: 340px; max-width: 440px; width: 100%;
  transform: scale(0.94) translateY(8px); opacity: 0;
  transition: transform .22s cubic-bezier(0.34,1.4,0.64,1), opacity .18s ease;
}
.overlay.animate .modal { transform: scale(1) translateY(0); opacity: 1; }

/* Confirm dialog */
.confirm-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0); z-index: 9000;
  display: none; align-items: center; justify-content: center;
  backdrop-filter: blur(0px);
  transition: background .15s ease, backdrop-filter .15s ease;
}
.confirm-overlay.open { display: flex; }
.confirm-overlay.animate { background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
.confirm-box {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 14px; padding: 24px 24px 20px;
  min-width: 300px; max-width: 380px; width: 100%;
  box-shadow: 0 24px 64px var(--shadow);
  transform: scale(0.92) translateY(10px); opacity: 0;
  transition: transform .2s cubic-bezier(0.34,1.4,0.64,1), opacity .15s ease;
}
.confirm-box {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 14px; padding: 32px 28px 24px;
  min-width: 320px; max-width: 400px; width: 100%;
  box-shadow: 0 24px 64px var(--shadow);
  transform: scale(0.92) translateY(10px); opacity: 0;
  transition: transform .2s cubic-bezier(0.34,1.4,0.64,1), opacity .15s ease;
  text-align: center;
}
.confirm-overlay.animate .confirm-box { transform: scale(1) translateY(0); opacity: 1; }
.confirm-icon { font-size: 18px; margin: 0 auto 16px; width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.confirm-icon.danger { background: rgba(239,68,68,.12); color: #ef4444; }
.confirm-icon.warning { background: rgba(249,115,22,.12); color: #f97316; }
.confirm-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.confirm-msg { font-size: 13.5px; color: var(--text2); line-height: 1.6; margin-bottom: 24px; }
.confirm-actions { display: flex; justify-content: center; gap: 10px; }
.confirm-actions .btn { padding: 9px 22px; font-size: 14px; min-width: 100px; justify-content: center; }
.modal-title { font-size: 15px; font-weight: 600; margin-bottom: 18px; }
.modal-field { margin-bottom: 12px; }
.modal-field label { display: block; font-size: 12px; color: var(--text2); margin-bottom: 5px; font-weight: 500; }

.field-input {
  width: 100%; background: var(--bg3);
  border: 1px solid var(--border2); border-radius: 6px;
  padding: 8px 12px; font: 13.5px var(--font); color: var(--text);
  outline: none; transition: border-color var(--transition);
}
.field-input:focus { border-color: var(--accent); }
.field-input::placeholder { color: var(--text3); }

.color-row { display: flex; gap: 6px; flex-wrap: wrap; }
.color-swatch {
  width: 26px; height: 26px; border-radius: 50%;
  cursor: pointer; border: 2px solid transparent;
  transition: all var(--transition); flex-shrink: 0;
}
.color-swatch.active { border-color: var(--text); transform: scale(1.15); }
.color-swatch-custom {
  width: 26px; height: 26px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; cursor: pointer;
  background: var(--bg4); color: var(--text2);
  border: 1px solid var(--border2); position: relative; overflow: hidden;
}
.color-swatch-custom input[type=color] {
  position: absolute; inset: 0; opacity: 0;
  cursor: pointer; width: 100%; height: 100%; border: none;
}

.logo-upload-area {
  border: 1.5px dashed var(--border2); border-radius: 8px;
  padding: 20px; text-align: center; cursor: pointer;
  transition: all var(--transition); position: relative; overflow: hidden;
}
.logo-upload-area:hover { border-color: var(--accent); background: rgba(var(--accent-rgb),0.04); }
.logo-upload-area input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.logo-preview { max-height: 48px; max-width: 120px; object-fit: contain; margin: 0 auto 8px; display: block; border-radius: 4px; }
.logo-upload-text { font-size: 12px; color: var(--text3); }
.logo-upload-text i { display: block; font-size: 20px; margin-bottom: 6px; color: var(--text4); }

.modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.btn-danger { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
.btn-danger:hover { background: #dc2626 !important; border-color: #dc2626 !important; }

/* ════════════════════════════
   ICON PICKER MODAL
════════════════════════════ */
.icon-search-modal { margin-bottom: 12px; }
.icon-grid {
  display: grid; grid-template-columns: repeat(8, 1fr);
  gap: 4px; max-height: 200px; overflow-y: auto;
  padding-right: 4px;
}
.icon-grid::-webkit-scrollbar { width: 4px; }
.icon-grid::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }
.ig-item {
  width: 100%; aspect-ratio: 1; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--text2); cursor: pointer;
  border: 1px solid transparent; transition: all 0.1s;
}
.ig-item:hover { background: var(--bg3); color: var(--text); border-color: var(--border); }
.ig-item.selected { background: rgba(var(--accent-rgb),0.15); color: var(--accent); border-color: rgba(var(--accent-rgb),0.3); }

/* ════════════════════════════
   SEARCH RESULTS DROPDOWN
════════════════════════════ */
.search-dropdown {
  position: fixed; top: calc(var(--nav-h) + 4px); left: 50%;
  transform: translateX(-50%); width: 480px; max-width: 90vw;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 10px; box-shadow: 0 12px 40px var(--shadow);
  z-index: 300; display: none; overflow: hidden;
}
.search-dropdown.open { display: block; }
.search-result-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; cursor: pointer; transition: background var(--transition);
}
.search-result-item:hover { background: var(--bg3); }
.search-result-item i { color: var(--text3); font-size: 12px; flex-shrink: 0; }
.search-result-title { font-size: 13.5px; color: var(--text); }
.search-result-path { font-size: 11px; color: var(--text3); margin-top: 1px; }
.search-empty { padding: 20px; text-align: center; color: var(--text3); font-size: 13px; }

/* ════════════════════════════
   TOAST
════════════════════════════ */
.toast {
  position: fixed; bottom: 24px; left: 50%;
  transform: translateX(-50%) translateY(calc(100% + 30px));
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 8px; padding: 9px 16px;
  font-size: 13px; color: var(--text2);
  box-shadow: 0 8px 24px var(--shadow);
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1);
  z-index: 500; display: flex; align-items: center; gap: 8px;
  pointer-events: none;
}
.toast.show { transform: translateX(-50%) translateY(0); }
.toast-dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; flex-shrink: 0; }

/* ── Floating save bar ── */
/* ════════════════════════════
   SCROLL TO TOP
════════════════════════════ */
.scroll-top-btn {
  position: fixed; bottom: 24px; right: 24px;
  width: 40px; height: 40px; border-radius: 50%;
  background: var(--bg2); border: 1px solid var(--border2);
  color: var(--text2); font-size: 14px;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px var(--shadow);
  opacity: 0; pointer-events: none;
  transition: opacity 0.2s, transform 0.2s, background 0.15s;
  z-index: 500;
}
.scroll-top-btn.show { opacity: 1; pointer-events: all; }
.scroll-top-btn:hover { background: var(--bg3); color: var(--accent); transform: translateY(-2px); }

#save-bar {
  position: fixed; bottom: 24px; left: 50%;
  transform: translateX(-50%) translateY(calc(100% + 40px));
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 12px; padding: 8px 8px 8px 16px;
  display: flex; align-items: center; gap: 10px;
  box-shadow: 0 8px 32px var(--shadow);
  transition: transform 0.35s cubic-bezier(0.34,1.56,0.64,1);
  z-index: 600; pointer-events: all;
  font-size: 13px; color: var(--text2);
  white-space: nowrap;
}
#save-bar.show { transform: translateX(-50%) translateY(0); }
#save-bar .save-bar-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #f97316; flex-shrink: 0;
  box-shadow: 0 0 6px rgba(249,115,22,.5);
}
#save-bar .save-bar-text { flex: 1; }
#save-bar .save-bar-btn {
  padding: 6px 14px; border-radius: 8px;
  background: #16a34a; color: #fff;
  border: none; font: 600 13px var(--font);
  cursor: pointer; display: flex; align-items: center; gap: 6px;
  transition: background .15s;
}
#save-bar .save-bar-btn:hover { background: #15803d; }
#save-bar .save-bar-discard {
  padding: 6px 10px; border-radius: 8px;
  background: transparent; color: var(--text3);
  border: none; font: 13px var(--font);
  cursor: pointer; transition: color .15s;
}
#save-bar .save-bar-discard:hover { color: var(--text); }

/* ════════════════════════════
   SETTINGS PANEL
════════════════════════════ */
.settings-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.5);
  z-index: 350; display: none;
}
.settings-overlay.open { display: block; }

.settings-panel {
  position: fixed; right: 0; top: 0; bottom: 0; width: 360px;
  background: var(--bg2); border-left: 1px solid var(--border);
  z-index: 360; transform: translateX(100%);
  transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
  display: flex; flex-direction: column;
  box-shadow: -8px 0 32px var(--shadow);
}
.settings-panel.open { transform: translateX(0); }

.settings-header {
  padding: 20px 20px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.settings-header h2 { font-size: 15px; font-weight: 600; }
.settings-body { flex: 1; overflow-y: auto; padding: 20px; }
.settings-section { padding-bottom: 20px; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
.settings-section:last-child { border-bottom: none; margin-bottom: 0; }
.settings-section-label {
  font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.07em;
  color: var(--text3); margin-bottom: 12px;
}
.settings-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 0; border-bottom: 1px solid var(--border);
  gap: 12px;
}
.settings-row:last-child { border-bottom: none; padding-bottom: 0; }
.settings-row-label { font-size: 13.5px; color: var(--text); }
.settings-row-sub { font-size: 12px; color: var(--text3); margin-top: 1px; }

/* Toggle */
.toggle {
  width: 36px; height: 20px; background: var(--bg4);
  border-radius: 10px; cursor: pointer; position: relative;
  border: none; flex-shrink: 0; transition: background var(--transition);
}
.toggle.on { background: var(--accent); }
.toggle::after {
  content: ''; position: absolute;
  top: 2px; left: 2px; width: 16px; height: 16px;
  background: #fff; border-radius: 50%;
  transition: transform var(--transition);
}
.toggle.on::after { transform: translateX(16px); }

/* ════════════════════════════
   EMPTY STATE
════════════════════════════ */
.empty-state {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  min-height: 320px; gap: 14px; color: var(--text3);
  text-align: center;
}
.empty-state i { font-size: 40px; opacity: 0.3; }
.empty-state p { font-size: 14px; }

/* ════════════════════════════
   TRANSLATE DROPDOWN
════════════════════════════ */
.translate-wrap { position: relative; }

/* Widget musí byť v DOM-e (nie display:none) aby Google vedel inicializovať */
#google_translate_element {
  position: fixed; top: -9999px; left: -9999px;
  width: 1px; height: 1px; overflow: hidden;
}

.translate-dropdown {
  display: none;
  position: absolute; top: calc(100% + 8px); right: 0;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 10px; padding: 6px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.2);
  z-index: 300; min-width: 160px;
}
.translate-dropdown.open { display: block; }
.translate-lang {
  display: flex; align-items: center; gap: 9px;
  padding: 7px 10px; border-radius: 6px;
  font-size: 13px; color: var(--text2);
  cursor: pointer; transition: all var(--transition);
  white-space: nowrap;
}
.translate-lang:hover { background: var(--bg3); color: var(--text); }
.translate-lang .flag { font-size: 16px; line-height: 1; }
.translate-lang.active { color: var(--accent); font-weight: 500; }
.translate-sep { height: 1px; background: var(--border); margin: 4px 2px; }

/* Skry Google toolbar a banner */
.goog-te-banner-frame, .goog-te-balloon-frame,
#goog-gt-tt, .goog-tooltip { display: none !important; }
body { top: 0 !important; }
.skiptranslate { display: none !important; }

/* ════════════════════════════
   SCROLLBAR
════════════════════════════ */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border2); }

/* ════════════════════════════
   AUTH / PASSWORD
════════════════════════════ */
/* ════════════════════════════
   SETUP WIZARD
════════════════════════════ */
.setup-overlay {
  position: fixed; inset: 0;
  background: var(--bg);
  z-index: 10000; display: none;
  align-items: center; justify-content: center;
}
.setup-overlay.open { display: flex; }
.setup-box {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 16px; padding: 36px 32px;
  width: 400px; max-width: 92vw;
  box-shadow: 0 32px 80px rgba(0,0,0,0.5);
}
.setup-icon {
  width: 56px; height: 56px; border-radius: 14px;
  background: rgba(var(--accent-rgb),0.12);
  border: 1px solid rgba(var(--accent-rgb),0.25);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 20px; font-size: 24px; color: var(--accent);
}
.setup-title { font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 4px; }
.setup-sub { font-size: 13px; color: var(--text3); text-align: center; margin-bottom: 24px; }
.setup-field { margin-bottom: 14px; }
.setup-field label { display: block; font-size: 12px; color: var(--text2); margin-bottom: 5px; font-weight: 500; }
.setup-pw-wrap { position: relative; }
.setup-pw-wrap input { width: 100%; padding-right: 38px; }
.setup-pw-wrap .pw-toggle {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text3); font-size: 13px;
}
.setup-rules { margin: 16px 0 20px; display: flex; flex-direction: column; gap: 5px; }
.setup-rule {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; color: var(--text3); transition: color 0.2s;
}
.setup-rule i { font-size: 11px; width: 14px; text-align: center; transition: color 0.2s; }
.setup-rule.pass { color: #22c55e; }
.setup-rule.pass i { color: #22c55e; }
.setup-rule.fail { color: var(--text3); }
.setup-rule.fail i { color: var(--text4); }
.setup-error {
  font-size: 12px; color: #ef4444; text-align: center;
  margin-bottom: 14px; min-height: 16px;
  display: flex; align-items: center; justify-content: center; gap: 5px;
}
.setup-btn {
  width: 100%; padding: 10px; border-radius: 8px;
  background: var(--accent); color: #fff; border: none;
  font: 600 14px var(--font); cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: opacity 0.15s, filter 0.15s;
}
.setup-btn:hover { filter: brightness(1.1); }
.setup-btn:disabled { opacity: 0.5; cursor: not-allowed; filter: none; }

/* ════════════════════════════
   AUTH OVERLAY
════════════════════════════ */
.auth-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.7);
  z-index: 600; display: none;
  align-items: center; justify-content: center;
  backdrop-filter: blur(6px);
}
.auth-overlay.open { display: flex; }

.auth-box {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 14px; padding: 32px 28px;
  width: 320px; box-shadow: 0 32px 80px rgba(0,0,0,0.6);
  text-align: center;
}
.auth-icon {
  width: 52px; height: 52px; border-radius: 12px;
  background: rgba(var(--accent-rgb),0.12);
  border: 1px solid rgba(var(--accent-rgb),0.25);
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 18px; font-size: 22px; color: var(--accent);
}
.auth-title { font-size: 16px; font-weight: 600; margin-bottom: 5px; }
.auth-sub { font-size: 13px; color: var(--text3); margin-bottom: 22px; }

.pin-row {
  display: flex; gap: 8px; justify-content: center; margin-bottom: 18px;
}
.pin-digit {
  width: 44px; height: 52px;
  background: var(--bg3); border: 1.5px solid var(--border2);
  border-radius: 8px; font-size: 22px; font-weight: 700;
  color: var(--text); text-align: center; outline: none;
  font-family: var(--mono); caret-color: var(--accent);
  transition: border-color var(--transition);
}
.pin-digit:focus { border-color: var(--accent); }
.pin-digit.filled { border-color: var(--border2); color: var(--accent); }

.auth-error {
  font-size: 12.5px; color: #ef4444;
  margin-bottom: 14px; min-height: 18px;
  display: flex; align-items: center; justify-content: center; gap: 5px;
}
.auth-error i { font-size: 11px; }

.auth-hint {
  font-size: 11px; color: var(--text4); margin-top: 14px;
}

.auth-actions { display: flex; gap: 8px; }
.auth-actions .btn { flex: 1; justify-content: center; }

/* PIN setup prompt */
.pin-setup-steps { text-align: left; margin-bottom: 18px; }
.pin-step {
  display: flex; align-items: center; gap: 10px;
  font-size: 13px; color: var(--text2); padding: 6px 0;
}
.pin-step-num {
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(var(--accent-rgb),0.15);
  color: var(--accent); font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.pin-step.done .pin-step-num { background: #16a34a; color: #fff; }
.pin-step.done .pin-step-num i { font-size: 9px; }

/* Lock indicator in nav */
.lock-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; color: var(--text3); padding: 3px 8px;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 20px;
}
.lock-badge.unlocked { color: #16a34a; border-color: rgba(22,163,74,0.3); background: rgba(22,163,74,0.08); }

/* ════════════════════════════
   RESPONSIVE
════════════════════════════ */
@media (max-width: 1100px) {
  .toc-panel { display: none; }
}
@media (max-width: 768px) {
  :root { --sidebar-w: 0px; }
  .sidebar { position: fixed; left: 0; top: var(--total-h); bottom: 0; transform: translateX(-272px); transition: transform 0.25s; z-index: 150; width: 272px; background: var(--bg2); }
  .sidebar.mobile-open { transform: translateX(0); }
  .mobile-sidebar-overlay { position: fixed; inset: 0; top: var(--total-h); background: rgba(0,0,0,0.4); z-index: 140; display: none; }
  .mobile-sidebar-overlay.open { display: block; }
  .main { margin-left: 0; }
  .content-wrap { padding: 32px 20px 80px; }
  .tabbar { padding-left: 0; }
  .mobile-menu-btn { display: flex !important; }
  .logo-area { display: none !important; }
}
.mobile-menu-btn { display: none; margin-left: 8px; }

/* ── Drag & Drop ── */
.nav-item.drag-over-above { border-top: 2px solid var(--accent) !important; }
.nav-item.drag-over-below { border-bottom: 2px solid var(--accent) !important; }
.nav-item.dragging { opacity: 0.4; }
.nav-item[draggable="true"] { cursor: grab; }
.nav-item[draggable="true"]:active { cursor: grabbing; }

/* ── Video Block ── */
.video-block { border-radius: 8px; overflow: hidden; background: var(--bg2); border: 1px solid var(--border); }
.video-block iframe { display: block; width: 100%; aspect-ratio: 16/9; border: none; }
.video-upload-zone {
  padding: 32px; text-align: center; cursor: pointer;
  color: var(--text3); display: flex; flex-direction: column; align-items: center; gap: 10px;
}
.video-upload-zone i { font-size: 28px; color: var(--text4); }
.video-url-row { display: flex; gap: 8px; margin-top: 4px; width: 100%; max-width: 400px; }
.video-url-input { flex: 1; background: var(--bg3); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; color: var(--text); font-family: var(--font); font-size: 13px; outline: none; }
.video-url-input:focus { border-color: var(--accent); }
.video-url-btn { padding: 6px 14px; background: var(--accent); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-family: var(--font); font-size: 13px; }

/* ── Cards Block ── */
.cards-grid { display: grid; gap: 12px; }
.cards-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.cards-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.card-item {
  border: 1px solid var(--border); border-radius: 10px;
  padding: 18px; background: var(--bg2); position: relative;
  transition: border-color var(--transition), box-shadow var(--transition), transform .2s;
}
.card-item:hover { border-color: var(--accent); box-shadow: 0 2px 12px rgba(var(--accent-rgb),.1); }
.card-linked { cursor: pointer; }
.card-linked:hover { transform: translateY(-2px); }
.card-link-arrow {
  position: absolute; top: 14px; right: 14px;
  font-size: 12px; color: var(--text4); transition: color .15s;
}
.card-linked:hover .card-link-arrow { color: var(--accent); }
.card-icon { font-size: 22px; color: var(--accent); margin-bottom: 10px; font-style: normal; }
.card-title { font-weight: 700; font-size: 14px; color: var(--text); margin-bottom: 6px; }
.card-desc { font-size: 13px; color: var(--text2); line-height: 1.55; }
.cards-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.cards-toolbar label { font-size: 12px; color: var(--text3); }
.cards-col-btn { padding: 3px 10px; border-radius: 4px; border: 1px solid var(--border); background: none; color: var(--text2); font-size: 12px; cursor: pointer; font-family: var(--font); transition: all .12s; }
.cards-col-btn.active { border-color: var(--accent); color: var(--accent); background: rgba(var(--accent-rgb),.07); }
.card-edit { border: 1px dashed var(--border); border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 6px; background: var(--bg2); }
.card-edit input, .card-edit textarea { background: none; border: none; border-bottom: 1px solid var(--border); outline: none; color: var(--text); font-family: var(--font); width: 100%; padding: 2px 0 4px; }
.card-edit input { font-size: 13px; font-weight: 600; }
.card-edit textarea { font-size: 13px; color: var(--text2); resize: none; min-height: 40px; }
.cards-add-btn { margin-top: 4px; padding: 6px 14px; background: none; border: 1px dashed var(--border); border-radius: 7px; color: var(--text3); cursor: pointer; font-family: var(--font); font-size: 12px; transition: all .12s; }
.cards-add-btn:hover { border-color: var(--accent); color: var(--accent); }

/* ── Slash Command Menu ── */
.slash-menu {
  position: absolute; z-index: 9999;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 12px; padding: 6px;
  box-shadow: 0 12px 40px rgba(0,0,0,.45), 0 2px 8px rgba(0,0,0,.2);
  min-width: 240px; max-height: 340px; overflow-y: auto;
}
.slash-menu::-webkit-scrollbar { width: 4px; }
.slash-menu::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }
.slash-item {
  display: flex; align-items: center; gap: 10px;
  padding: 7px 10px; border-radius: 6px; cursor: pointer;
  transition: background var(--transition);
}
.slash-item:hover, .slash-item.active { background: var(--bg3); }
.slash-item-icon {
  width: 28px; height: 28px; border-radius: 6px;
  background: var(--bg3); display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: var(--text2); flex-shrink: 0;
}
.slash-item.active .slash-item-icon { background: rgba(var(--accent-rgb),.15); color: var(--accent); }
.slash-item-label { font-size: 13px; font-weight: 500; color: var(--text); }
.slash-item-desc { font-size: 11px; color: var(--text3); }

/* ── Keyboard shortcut hint ── */
.kbd { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; color: var(--text3); }
.kbd kbd { background: var(--bg3); border: 1px solid var(--border2); border-radius: 3px; padding: 1px 5px; font-family: var(--mono); font-size: 11px; }

/* ── Page transition ── */
#page-view { transition: opacity .18s ease; }
#page-view.fading { opacity: 0; }

/* ── Empty state ── */
.page-empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 60px 20px; gap: 14px; color: var(--text3);
}
.page-empty-state > i { font-size: 36px; opacity: .3; }
.page-empty-state p { font-size: 14px; }
.page-empty-state .btn { margin-top: 4px; align-self: center; }

/* ── Reading mode ── */
body.reading-mode .sidebar { transform: translateX(-100%); width: 0; min-width: 0; overflow: hidden; }
body.reading-mode .toc-panel { display: none; }
body.reading-mode .main { margin-left: 0 !important; justify-content: center; }
body.reading-mode .content-wrap { max-width: 980px; width: 100%; padding-left: 80px; padding-right: 80px; }
body.reading-mode #reading-mode-btn { color: var(--accent) !important; }
.sidebar { transition: transform .25s ease, width .25s ease, min-width .25s ease; }

/* ── Hover preview ── */
.nav-hover-preview {
  position: fixed; z-index: 9000;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 10px; padding: 12px 14px; width: 240px;
  box-shadow: 0 8px 30px rgba(0,0,0,.5);
  pointer-events: none; opacity: 0; transition: opacity .15s;
  word-break: break-word; overflow-wrap: break-word;
}
.nav-hover-preview.show { opacity: 1; }
.nav-hover-preview-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.nav-hover-preview-desc { font-size: 12px; color: var(--text3); line-height: 1.5; white-space: normal; }

/* ── Search highlight ── */
.search-result-snippet { font-size: 11px; color: var(--text3); margin-top: 2px; line-height: 1.4; }
.search-result-snippet mark { background: rgba(var(--accent-rgb),.25); color: var(--accent); border-radius: 2px; padding: 0 1px; }
.search-result-title mark { background: rgba(var(--accent-rgb),.2); color: var(--accent); border-radius: 2px; padding: 0 1px; }

/* ── Shortcuts overlay ── */
.shortcuts-overlay {
  position: fixed; inset: 0; z-index: 10000;
  background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.shortcuts-overlay.open { opacity: 1; pointer-events: all; }
.shortcuts-panel {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 16px; padding: 24px 28px; min-width: 340px;
  box-shadow: 0 24px 60px rgba(0,0,0,.8);
}
.shortcuts-panel h3 { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 16px; }
.shortcut-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); }
.shortcut-row:last-child { border: none; }
.shortcut-label { font-size: 13px; color: var(--text2); }
.shortcut-keys { display: flex; gap: 4px; }
.shortcut-keys kbd {
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 4px; padding: 2px 7px; font-size: 11px;
  font-family: var(--mono); color: var(--text3);
}

/* ── Share button toast ── */
.share-toast {
  position: fixed; bottom: 20px; right: 20px; z-index: 9999;
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: 8px; padding: 10px 16px; font-size: 13px;
  color: var(--text); box-shadow: 0 4px 20px rgba(0,0,0,.4);
  display: flex; align-items: center; gap: 8px;
  animation: slideInRight .2s ease;
}
@keyframes slideInRight { from { transform: translateX(20px); opacity:0; } to { transform: translateX(0); opacity:1; } }
</style>
</head>
<body>
<!-- Google Translate init element — musí byť v DOM, schovaný offscreen -->
<div id="google_translate_element"></div>
<div class="mobile-sidebar-overlay" id="mobile-sidebar-overlay" onclick="toggleMobileSidebar()"></div>

<!-- TOP NAV — header row -->
<nav class="topnav">
  <button class="icon-btn mobile-menu-btn" id="mobile-menu-btn" onclick="toggleMobileSidebar()">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="logo-area" id="logo-area-btn">
    <div class="logo-img" id="logo-display">
      <i class="fa-solid fa-book-open"></i>
    </div>
    <span class="logo-name" id="logo-name-display">My Docs</span>
  </div>

  <div class="search-wrap">
    <div class="search-box">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" data-i18n-ph="searchPlaceholder" placeholder="Search..." id="search-input"
        oninput="handleSearch(this.value)"
        onfocus="openSearchDD()"
        onblur="setTimeout(closeSearchDD, 200)">
      <span class="search-kbd">⌘K</span>
    </div>
    <div class="search-dropdown" id="search-dd"></div>
  </div>

  <div class="nav-right">
    <button class="btn btn-success" id="save-btn" onclick="savePage()" style="display:none!important">
      <i class="fa-solid fa-check"></i> <span data-i18n="btnSave">Save</span>
    </button>
    <button class="btn btn-ghost admin-only" onclick="toggleEdit()" id="edit-btn" style="display:none">
      <i class="fa-solid fa-pen"></i> <span data-i18n="btnEdit">Edit mode</span>
    </button>
    <button class="icon-btn" id="undo-btn" onclick="editorUndo()" title="Undo (⌘Z)" style="display:none">
      <i class="fa-solid fa-rotate-left"></i>
    </button>
    <button class="icon-btn" id="redo-btn" onclick="editorRedo()" title="Redo (⌘⇧Z)" style="display:none">
      <i class="fa-solid fa-rotate-right"></i>
    </button>
    <div class="nav-divider admin-only" style="display:none"></div>
    <button class="icon-btn" onclick="toggleReadingMode()" id="reading-mode-btn" data-i18n-attr="title" data-i18n="btnReadingMode" title="Reading mode (focus)">
      <i class="fa-solid fa-book-open-reader"></i>
    </button>
    <button class="icon-btn" onclick="toggleTheme()" id="theme-btn" data-i18n-attr="title" data-i18n="btnToggleTheme" title="Toggle theme">
      <i class="fa-solid fa-moon"></i>
    </button>
    <!-- Translate -->
    <div class="translate-wrap" id="translate-wrap">
      <button class="icon-btn" onclick="toggleTranslate()" id="translate-btn" data-i18n-attr="title" data-i18n="btnTranslate" title="Translate page">
        <i class="fa-solid fa-language"></i>
      </button>
      <div class="translate-dropdown" id="translate-dd">
        <div class="translate-lang active" id="translate-origin-item" data-lang="origin" onclick="translateTo(S.settings.lang||'de')"><span class="flag" id="translate-origin-flag">🇩🇪</span> <span id="translate-origin-label">Deutsch (original)</span></div>
        <div class="translate-sep"></div>
        <div class="translate-lang" data-lang="en" onclick="translateTo('en')"><span class="flag">🇬🇧</span> English</div>
        <div class="translate-lang" data-lang="cs" onclick="translateTo('cs')"><span class="flag">🇨🇿</span> Čeština</div>
        <div class="translate-lang" data-lang="de" onclick="translateTo('de')"><span class="flag">🇩🇪</span> Deutsch</div>
        <div class="translate-lang" data-lang="fr" onclick="translateTo('fr')"><span class="flag">🇫🇷</span> Français</div>
        <div class="translate-lang" data-lang="es" onclick="translateTo('es')"><span class="flag">🇪🇸</span> Español</div>
        <div class="translate-lang" data-lang="pl" onclick="translateTo('pl')"><span class="flag">🇵🇱</span> Polski</div>
        <div class="translate-lang" data-lang="uk" onclick="translateTo('uk')"><span class="flag">🇺🇦</span> Українська</div>
        <div class="translate-lang" data-lang="ru" onclick="translateTo('ru')"><span class="flag">🇷🇺</span> Русский</div>
      </div>
    </div>
    <button class="icon-btn admin-only" onclick="openSettings()" data-i18n-attr="title" data-i18n="btnSettings" title="Settings" id="settings-btn" style="display:none">
      <i class="fa-solid fa-gear"></i>
    </button>
    <div class="nav-divider"></div>
    <button class="auth-nav-btn" id="auth-nav-btn" onclick="handleAuthBtn()">
      <i class="fa-solid fa-lock" id="auth-btn-icon"></i>
      <span id="auth-btn-label">Log in</span>
    </button>
  </div>
</nav>

<!-- TAB BAR — second row, full width (GitBook style) -->
<div class="tabbar" id="tabbar">
  <div class="tab-strip" id="tab-strip"></div>
</div>

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <!-- Nav tree -->
    <div class="sidebar-body" id="nav-tree"></div>

    <!-- Add page — admin only -->
    <button class="add-page-row admin-only" id="add-page-btn" onclick="openAddPage(null)" style="display:none">
      <i class="fa-solid fa-plus"></i> <span data-i18n="modalAddTitle">New page</span>
    </button>
    <div class="sidebar-footer" id="sidebar-footer">
      <i class="fa-solid fa-bolt"></i>
      <span id="footer-text-display">Powered by Docs</span>
      <a href="https://webstudio.ltd" target="_blank" rel="noopener"
         style="margin-left:auto;opacity:.4;font-size:10px;color:inherit;text-decoration:none;white-space:nowrap;flex-shrink:0;"
         title="Built by webstudio.ltd">webstudio.ltd</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="content-wrap" id="content-wrap">
      <div id="page-view"></div>
    </div>
    <div class="toc-panel" id="toc-panel">
      <div class="toc-head" data-i18n="tocTitle">On this page</div>
      <div id="toc-items"></div>
      <div class="toc-sep"></div>
      <div class="toc-feedback-label" data-i18n="tocFeedback">Was this helpful?</div>
      <div class="toc-feedback-btns">
        <button class="fb-btn" onclick="react('👍')" title="Yes"><i class="fa-regular fa-face-smile"></i></button>
        <button class="fb-btn" onclick="react('😐')" title="Neutral"><i class="fa-regular fa-face-meh"></i></button>
        <button class="fb-btn" onclick="react('👎')" title="No"><i class="fa-regular fa-face-frown"></i></button>
      </div>
      <div class="toc-sep"></div>
      <div class="toc-share-box">
        <div class="toc-feedback-label" data-i18n="tocShare">Share</div>
        <button class="toc-share-btn" onclick="sharePage()">
          <i class="fa-solid fa-link"></i>
          <span id="toc-share-label" data-i18n="tocShare">Share</span>
        </button>
      </div>
    </div>
  </main>
</div>

<!-- Scroll to top button -->
<button class="scroll-top-btn" id="scroll-top-btn" onclick="window.scrollTo({top:0,behavior:'smooth'})">
  <i class="fa-solid fa-arrow-up"></i>
</button>

<!-- Floating save bar -->
<div id="save-bar">
  <div class="save-bar-dot"></div>
  <span class="save-bar-text" data-i18n="unsavedChanges">You have unsaved changes</span>
  <button class="save-bar-discard" onclick="discardChanges()" data-i18n="btnDiscard">Discard</button>
  <button class="save-bar-btn" onclick="savePage()">
    <i class="fa-solid fa-check"></i> <span data-i18n="btnSave">Save</span>
  </button>
</div>

<!-- Hover preview -->
<div class="nav-hover-preview" id="nav-hover-preview">
  <div id="nhp-cover" style="height:80px;border-radius:7px;margin-bottom:10px;background-size:cover;background-position:center;display:none;overflow:hidden;"></div>
  <div class="nav-hover-preview-title" id="nhp-title"></div>
  <div class="nav-hover-preview-desc" id="nhp-desc"></div>
</div>

<!-- Shortcuts overlay -->
<div class="shortcuts-overlay" id="shortcuts-overlay" onclick="closeShortcuts()">
  <div class="shortcuts-panel" onclick="event.stopPropagation()">
    <h3><i class="fa-solid fa-keyboard" style="margin-right:8px;color:var(--accent)"></i><span data-i18n="shortcutShortcuts">Shortcuts</span></h3>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutEdit">Edit / Preview</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>E</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutSave">Save</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>S</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutUndo">Undo</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>Z</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutRedo">Redo</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>⇧</kbd><kbd>Z</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutSearch">Search</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>K</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label">Slash menu</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>/</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutReadingMode">Reading mode</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>R</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutShare">Share page</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>⇧</kbd><kbd>C</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutPrevNext">Previous / Next page</span><span class="shortcut-keys"><kbd>←</kbd><kbd>→</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutShortcuts">Shortcuts</span><span class="shortcut-keys"><kbd>?</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label">Esc</span><span class="shortcut-keys"><kbd>Esc</kbd></span></div>
  </div>
</div>

<!-- ════ MODALS ════ -->

<!-- Add Page Modal -->
<div class="overlay" id="add-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-title" data-i18n="modalAddTitle">New page</div>
    <div class="modal-field">
      <label data-i18n="modalAddTemplate">Template</label>
      <div id="template-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px;"></div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddPageName">Page title</label>
      <input class="field-input" type="text" id="new-title" data-i18n-ph="modalAddPageName" placeholder="Page title" onkeydown="if(event.key==='Enter')confirmAddPage()">
    </div>
    <div class="modal-field">
      <label>Icon (Font Awesome)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input class="field-input" type="text" id="new-icon" placeholder="fa-file" style="flex:1" oninput="previewIcon(this.value)">
        <div id="icon-preview-box" style="width:34px;height:34px;background:var(--bg3);border:1px solid var(--border2);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:14px;flex-shrink:0">
          <i class="fa-solid fa-file"></i>
        </div>
      </div>
      <div style="margin-top:8px">
        <div class="icon-grid" id="quick-icon-grid"></div>
      </div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddSection">Section (optional)</label>
      <input class="field-input" type="text" id="new-section" data-i18n-ph="modalAddSection" placeholder="Section name">
    </div>
    <div class="modal-actions" style="justify-content:space-between;">
      <button class="btn btn-ghost" onclick="closeModal('add-modal')" data-i18n="btnCancel">Cancel</button>
      <button class="btn btn-primary" onclick="confirmAddPage()"><i class="fa-solid fa-plus"></i> <span data-i18n="btnCreate">Create</span></button>
    </div>
  </div>
</div>


<!-- Space Edit Modal -->
<div class="overlay" id="space-modal">  <div class="modal">
    <div class="modal-title" id="space-modal-title" data-i18n="modalEditSpaceTitle">Edit space</div>
    <div class="modal-field">
      <label data-i18n="modalSpaceName">Space name</label>
      <input class="field-input" type="text" id="space-name-input" data-i18n-ph="modalSpaceName" placeholder="Space name" onkeydown="if(event.key==='Enter')confirmSpaceEdit()">
    </div>
    <div class="modal-field">
      <label>Icon (Font Awesome)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input class="field-input" type="text" id="space-icon-input" placeholder="fa-book" style="flex:1" oninput="previewSpaceIcon(this.value)">
        <div id="space-icon-preview" style="width:34px;height:34px;background:var(--bg3);border:1px solid var(--border2);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:14px;flex-shrink:0">
          <i class="fa-solid fa-book"></i>
        </div>
      </div>
      <div style="margin-top:8px">
        <div class="icon-grid" id="space-icon-grid"></div>
      </div>
    </div>
    <div class="modal-actions" style="justify-content:space-between;">
      <div>
        <button class="btn" id="space-delete-btn" style="color:#ef4444;border:1px solid rgba(239,68,68,.3);background:transparent;" onclick="deleteCurrentSpace()"><i class="fa-solid fa-trash"></i> <span data-i18n="btnRemoveSpace">Remove space</span></button>
        <button class="btn btn-ghost" id="space-cancel-btn" onclick="closeModal('space-modal')" data-i18n="btnCancel" style="display:none">Cancel</button>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-ghost" id="space-cancel-btn-right" onclick="closeModal('space-modal')" data-i18n="btnCancel">Cancel</button>
        <button class="btn btn-primary" id="space-confirm-btn" onclick="confirmSpaceEdit()"><i class="fa-solid fa-check"></i> <span data-i18n="btnSaveChanges">Save</span></button>
      </div>
    </div>
  </div>
</div>

<!-- Page Edit Modal (full) -->
<div class="overlay" id="page-edit-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-title" data-i18n="modalEditPageTitle">Edit page</div>
    <div class="modal-field">
      <label data-i18n="modalAddPageName">Page title</label>
      <input class="field-input" type="text" id="page-edit-title" data-i18n-ph="modalAddPageName" placeholder="Page title" onkeydown="if(event.key==='Enter')confirmPageEdit()">
    </div>
    <div class="modal-field">
      <label data-i18n="modalEditSubtitle">Description (optional)</label>
      <input class="field-input" type="text" id="page-edit-subtitle" data-i18n-ph="modalEditSubtitlePlaceholder" placeholder="Short page description">
    </div>
    <div class="modal-field">
      <label>Icon (Font Awesome)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input class="field-input" type="text" id="page-edit-icon" placeholder="fa-file" oninput="previewPageEditIcon(this.value)" style="flex:1">
        <div id="page-edit-icon-preview" style="width:34px;height:34px;background:var(--bg3);border:1px solid var(--border2);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:14px;flex-shrink:0"><i class="fa-solid fa-file"></i></div>
      </div>
      <div style="margin-top:8px">
        <div class="icon-grid" id="page-edit-icon-grid"></div>
      </div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddSection">Section (optional)</label>
      <input class="field-input" type="text" id="page-edit-section" data-i18n-ph="modalAddSection" placeholder="Section name">
    </div>
    <div class="modal-actions" style="justify-content:space-between;">
      <button class="btn" style="color:#ef4444;border:1px solid rgba(239,68,68,.3);background:transparent;" onclick="deletePageFromEdit()"><i class="fa-solid fa-trash"></i> <span data-i18n="btnRemovePage">Remove</span></button>
      <div style="display:flex;gap:8px;margin-left:auto;">
        <button class="btn btn-ghost" onclick="closeModal('page-edit-modal')" data-i18n="btnCancel">Cancel</button>
        <button class="btn btn-primary" onclick="confirmPageEdit()"><i class="fa-solid fa-check"></i> <span data-i18n="btnSaveChanges">Save</span></button>
      </div>
    </div>
  </div>
</div>

<!-- Settings Panel -->
<div class="settings-overlay" id="settings-overlay" onclick="closeSettings()"></div>
<div class="settings-panel" id="settings-panel">
  <div class="settings-header">
    <h2><i class="fa-solid fa-gear" style="margin-right:8px;font-size:13px"></i><span data-i18n="settingsTitle">Settings</span></h2>
    <button class="icon-btn" onclick="closeSettings()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="settings-body">

    <div class="settings-section">
      <div class="settings-section-label" data-i18n="settingsAppearance">Appearance</div>

      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsLogo">Logo</div>
        </div>
        <div>
          <div class="logo-upload-area" onclick="document.getElementById('logo-file').click()" style="padding:10px 16px;display:flex;align-items:center;gap:10px">
            <input type="file" id="logo-file" accept="image/*" style="display:none" onchange="handleLogoUpload(this)">
            <div id="logo-preview-area" style="color:var(--text3);font-size:12px;display:flex;align-items:center;gap:8px">
              <i class="fa-solid fa-cloud-arrow-up" style="font-size:18px;color:var(--text4)"></i>
              <span data-i18n="settingsLogoUpload">Click to upload logo</span>
            </div>
          </div>
        </div>
      </div>

      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsFavicon">Favicon</div>
          <div class="settings-row-sub" data-i18n="settingsFaviconSub">Browser tab icon</div>
        </div>
        <div>
          <div class="logo-upload-area" onclick="document.getElementById('favicon-file').click()" style="padding:8px 14px;display:flex;align-items:center;gap:8px">
            <input type="file" id="favicon-file" accept="image/png,image/x-icon,image/svg+xml,image/gif" style="display:none" onchange="handleFaviconUpload(this)">
            <div id="favicon-preview-area" style="color:var(--text3);font-size:12px;display:flex;align-items:center;gap:8px">
              <i class="fa-solid fa-image" style="font-size:14px;color:var(--text4)"></i>
              <span data-i18n="settingsFaviconUpload">Upload favicon</span>
            </div>
          </div>
        </div>
      </div>

      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsSiteName">Site name</div>
        </div>
        <input class="field-input" type="text" id="site-name-input" style="width:160px;text-align:right" placeholder="My Docs" oninput="updateSiteName(this.value)">
      </div>

      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsAccentColor">Accent color</div>
        </div>
        <div class="color-row" id="color-row">
          <div class="color-swatch active" style="background:#f97316" data-color="#f97316" onclick="setAccent('#f97316',this)" data-i18n-attr="title" data-i18n="colorOrange" title="Orange"></div>
          <div class="color-swatch" style="background:#3b82f6" data-color="#3b82f6" onclick="setAccent('#3b82f6',this)" data-i18n-attr="title" data-i18n="colorBlue" title="Blue"></div>
          <div class="color-swatch" style="background:#8b5cf6" data-color="#8b5cf6" onclick="setAccent('#8b5cf6',this)" data-i18n-attr="title" data-i18n="colorPurple" title="Purple"></div>
          <div class="color-swatch" style="background:#10b981" data-color="#10b981" onclick="setAccent('#10b981',this)" data-i18n-attr="title" data-i18n="colorGreen" title="Green"></div>
          <div class="color-swatch" style="background:#ec4899" data-color="#ec4899" onclick="setAccent('#ec4899',this)" data-i18n-attr="title" data-i18n="colorPink" title="Pink"></div>
          <div class="color-swatch" style="background:#ef4444" data-color="#ef4444" onclick="setAccent('#ef4444',this)" data-i18n-attr="title" data-i18n="colorRed" title="Red"></div>
          <div class="color-swatch" style="background:#eab308" data-color="#eab308" onclick="setAccent('#eab308',this)" data-i18n-attr="title" data-i18n="colorYellow" title="Yellow"></div>
          <div class="color-swatch-custom" data-i18n-attr="title" data-i18n="settingsCustomColor" title="Custom color">
            <i class="fa-solid fa-plus" style="font-size:9px;pointer-events:none"></i>
            <input type="color" id="custom-color" oninput="setAccent(this.value,null,true)">
          </div>
        </div>
      </div>

      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsTheme">Dark / Light mode</div>
        </div>
        <button class="toggle on" id="theme-toggle" onclick="toggleTheme()"></button>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-label" data-i18n="settingsFooter">Footer</div>
      <div class="settings-row">
        <div style="flex:1">
          <div class="settings-row-label" data-i18n="settingsFooterText">Footer text</div>
          <div class="settings-row-sub" data-i18n="settingsFooterSub">Shown in sidebar</div>
          <input class="field-input" type="text" id="footer-input" placeholder="Powered by Docs" oninput="updateFooter(this.value)" style="margin-top:8px;width:100%">
        </div>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-label" data-i18n="settingsPage">Page</div>
      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsTabTitle">Browser tab title</div>
        </div>
        <input class="field-input" type="text" id="tab-title-input" style="width:160px;text-align:right" placeholder="Docs" oninput="updateTabTitle(this.value)">
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-label" data-i18n="settingsLanguage">Interface language</div>
      <div class="settings-row">
        <select id="lang-select" class="field-input" style="width:100%" onchange="setLang(this.value)">
          <option value="de">🇩🇪 Deutsch</option>
          <option value="en">🇬🇧 English</option>
          <option value="sk">🇸🇰 Slovenčina</option>
        </select>
      </div>
      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsTranslateToggle">Translate button</div>
          <div class="settings-row-sub" data-i18n="settingsTranslateToggleSub">Show Google Translate button in header for readers</div>
        </div>
        <button class="toggle on" id="translate-toggle" onclick="toggleTranslateAvailability()"></button>
      </div>
    </div>

    <div class="settings-section" id="settings-pin-section">
      <div class="settings-section-label" data-i18n="settingsPassword">Password</div>
      <div class="settings-row">
        <div>
          <div class="settings-row-sub"><span data-i18n="settingsPasswordNote">Password is securely stored (bcrypt)</span></div>
        </div>
        <button class="btn btn-ghost" onclick="handleLogout()" style="font-size:12px;color:#ef4444;border-color:rgba(239,68,68,0.3)">
          <i class="fa-solid fa-right-from-bracket"></i> <span data-i18n="btnLogout">Log out</span>
        </button>
      </div>
    </div>

  </div>
</div>

<!-- SETUP WIZARD (first run) -->
<div class="setup-overlay" id="setup-overlay">
  <div class="setup-box">
    <div class="setup-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <div class="setup-title" id="setup-title">Welcome to Webstudio Docs</div>
    <div class="setup-sub" id="setup-sub">Set up your admin password to get started.</div>

    <div class="setup-field">
      <label id="setup-pw-label">Password</label>
      <div class="setup-pw-wrap">
        <input type="password" id="setup-pw" class="field-input" autocomplete="new-password"
          oninput="validateSetupPassword()" onkeydown="if(event.key==='Enter')document.getElementById('setup-pw2').focus()">
        <button class="pw-toggle" onclick="toggleSetupVis('setup-pw', this)" type="button">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    </div>

    <div class="setup-field">
      <label id="setup-confirm-label">Confirm password</label>
      <div class="setup-pw-wrap">
        <input type="password" id="setup-pw2" class="field-input" autocomplete="new-password"
          oninput="validateSetupPassword()" onkeydown="if(event.key==='Enter')submitSetup()">
        <button class="pw-toggle" onclick="toggleSetupVis('setup-pw2', this)" type="button">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    </div>

    <div class="setup-rules" id="setup-rules">
      <div class="setup-rule fail" id="rule-length"><i class="fa-solid fa-circle"></i> <span>At least 8 characters</span></div>
      <div class="setup-rule fail" id="rule-upper"><i class="fa-solid fa-circle"></i> <span>Uppercase letter</span></div>
      <div class="setup-rule fail" id="rule-lower"><i class="fa-solid fa-circle"></i> <span>Lowercase letter</span></div>
      <div class="setup-rule fail" id="rule-number"><i class="fa-solid fa-circle"></i> <span>Number</span></div>
      <div class="setup-rule fail" id="rule-special"><i class="fa-solid fa-circle"></i> <span>Special character (!@#$...)</span></div>
      <div class="setup-rule fail" id="rule-match"><i class="fa-solid fa-circle"></i> <span>Passwords match</span></div>
    </div>

    <div class="setup-error" id="setup-error"></div>

    <button class="setup-btn" id="setup-btn" onclick="submitSetup()" disabled>
      <i class="fa-solid fa-lock"></i> <span>Create password</span>
    </button>
  </div>
</div>

<!-- AUTH OVERLAY -->
<div class="auth-overlay" id="auth-overlay">
  <div class="auth-box" id="auth-box">
    <div class="auth-icon"><i class="fa-solid fa-lock" id="auth-icon-i"></i></div>
    <div class="auth-title" id="auth-title" data-i18n="authLogin">Log in</div>
    <div class="auth-sub" id="auth-sub">Enter your password to access admin mode.</div>
    <div class="pin-row" id="pin-row">
      <input class="pin-digit" type="password" maxlength="1" inputmode="numeric" pattern="[0-9]" id="pin0" oninput="pinInput(0,this)">
      <input class="pin-digit" type="password" maxlength="1" inputmode="numeric" pattern="[0-9]" id="pin1" oninput="pinInput(1,this)">
      <input class="pin-digit" type="password" maxlength="1" inputmode="numeric" pattern="[0-9]" id="pin2" oninput="pinInput(2,this)">
      <input class="pin-digit" type="password" maxlength="1" inputmode="numeric" pattern="[0-9]" id="pin3" oninput="pinInput(3,this)">
    </div>
    <div class="auth-error" id="auth-error"></div>
    <div class="auth-actions">
      <button class="btn btn-ghost" onclick="closeAuth()" data-i18n="btnCancel">Cancel</button>
      <button class="btn btn-primary" onclick="submitPin()" id="auth-submit-btn" data-i18n="authLogin">Log in</button>
    </div>
    <div class="auth-hint" id="auth-hint"></div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <div class="toast-dot"></div>
  <span id="toast-text">Saved</span>
</div>

<!-- ════ SCRIPTS ════ -->
<script>
// ════════════════════════════════════════
//  CONSTANTS
// ════════════════════════════════════════
const ICON_LIST = [
  // Dokumenty & súbory
  'fa-file','fa-file-code','fa-file-lines','fa-file-pdf','fa-file-image','fa-file-video',
  'fa-file-audio','fa-file-zipper','fa-file-csv','fa-file-excel','fa-file-word','fa-file-powerpoint',
  'fa-book','fa-book-open','fa-book-atlas','fa-book-bookmark','fa-bookmark','fa-scroll','fa-newspaper','fa-feather',
  // Priečinky & navigácia
  'fa-folder','fa-folder-open','fa-folder-tree','fa-house','fa-sitemap','fa-diagram-project',
  'fa-network-wired','fa-link','fa-anchor','fa-compass','fa-map','fa-map-location-dot',
  // Tech & kód
  'fa-code','fa-terminal','fa-laptop-code','fa-bug','fa-robot','fa-microchip','fa-cpu',
  'fa-memory','fa-server','fa-database','fa-cloud','fa-cloud-arrow-up','fa-cloud-arrow-down',
  'fa-plug','fa-power-off','fa-hard-drive','fa-floppy-disk','fa-keyboard',
  // Nastavenia & nástroje
  'fa-gear','fa-gears','fa-wrench','fa-screwdriver-wrench','fa-hammer','fa-toolbox',
  'fa-sliders','fa-toggle-on','fa-toggle-off','fa-filter','fa-wand-magic-sparkles',
  // Bezpečnosť
  'fa-shield','fa-shield-halved','fa-lock','fa-lock-open','fa-key','fa-fingerprint','fa-eye','fa-eye-slash',
  // Ľudia
  'fa-user','fa-users','fa-user-tie','fa-user-gear','fa-user-shield','fa-address-card','fa-id-badge',
  // Komunikácia
  'fa-envelope','fa-envelope-open','fa-bell','fa-bell-slash','fa-comment','fa-comments',
  'fa-message','fa-paper-plane','fa-inbox','fa-at',
  // Médiá & dizajn
  'fa-palette','fa-pen','fa-pen-to-square','fa-pencil','fa-paintbrush','fa-crop',
  'fa-image','fa-images','fa-camera','fa-video','fa-music','fa-headphones',
  // Grafy & dáta
  'fa-chart-bar','fa-chart-line','fa-chart-pie','fa-chart-area','fa-table',
  'fa-list','fa-list-check','fa-layer-group','fa-cube','fa-cubes',
  // Statusy & ikony
  'fa-star','fa-heart','fa-bolt','fa-fire','fa-rocket','fa-flag','fa-tag','fa-tags',
  'fa-circle-check','fa-circle-info','fa-circle-question','fa-circle-exclamation',
  'fa-triangle-exclamation','fa-ban','fa-xmark','fa-check','fa-plus','fa-minus',
  // Šípky & pohyb
  'fa-arrow-right','fa-arrow-left','fa-arrow-up','fa-arrow-down',
  'fa-arrows-left-right','fa-arrows-up-down','fa-rotate','fa-rotate-right',
  'fa-up-right-from-square','fa-download','fa-upload','fa-share','fa-reply',
  // Čas & kalendár
  'fa-clock','fa-calendar','fa-calendar-days','fa-calendar-check','fa-hourglass','fa-stopwatch',
  // Miesto
  'fa-location-dot','fa-globe','fa-earth-europe','fa-building','fa-city','fa-store',
  // Ostatné
  'fa-lightbulb','fa-graduation-cap','fa-briefcase','fa-trophy','fa-medal',
  'fa-puzzle-piece','fa-dice','fa-infinity','fa-hashtag','fa-percent',
  'fa-money-bill','fa-coins','fa-credit-card','fa-cart-shopping','fa-basket-shopping',
  'fa-play','fa-pause','fa-stop','fa-forward','fa-backward',
];

// ════════════════════════════════════════
//  i18n — Translations
//  To add a new language: copy the 'en' object, rename the key, translate values.
//  Set default in Settings → Interface language.
// ════════════════════════════════════════
const TRANSLATIONS = {
  en: {
    // ── Nav ──
    btnEdit: 'Edit mode', btnPreview: 'Leave edit mode', btnSave: 'Save', btnDiscard: 'Discard',
    unsavedChanges: 'You have unsaved changes',
    loaderLoading: 'Loading...', loaderFailed: 'Failed to load. Check your connection.', loaderRetry: 'Try again',
    btnLogin: 'Log in', btnLoggedIn: 'Logged in', btnLogout: 'Log out',
    btnSettings: 'Settings', btnReadingMode: 'Reading mode (focus)',
    btnToggleTheme: 'Toggle theme', btnTranslate: 'Translate page',
    btnEditSpace: 'Edit space', btnNewSpace: 'New space',
    btnEditPage: 'Edit page', btnSubpage: 'Subpage', btnDeletePage: 'Delete',
    btnAddPageSection: 'Add to section',
    // ── Reading time & nav ──
    readingTimeLabel: (mins, words) => `${mins} min read · ${words} words`,
    lastUpdated: 'Last updated',
    navPrev: 'Previous', navNext: 'Next',
    // ── Search ──
    searchPlaceholder: 'Search...', searchNoResults: 'No results',
    // ── Save bar ──
    savingLabel: 'Saving...', savingBtn: 'Saving...',
    // ── TOC ──
    tocTitle: 'On this page', tocNoHeadings: 'No headings', tocFeedback: 'Was this helpful?',
    tocShare: 'Share', tocShareCopied: 'Copied!',
    // ── Modals — Add page ──
    modalAddTitle: 'New page', modalAddPageName: 'Page title',
    modalAddIcon: 'Icon (e.g. fa-file)', modalAddSection: 'Section (e.g. GET STARTED)',
    modalAddTemplate: 'Template', btnCreate: 'Create', btnCancel: 'Cancel',
    // ── Modals — Edit page ──
    modalEditPageTitle: 'Edit page', modalEditSubtitle: 'Description (optional)',
    modalEditSubtitlePlaceholder: 'Short page description',
    btnRemovePage: 'Remove', btnSaveChanges: 'Save',
    // ── Modals — Space ──
    modalEditSpaceTitle: 'Edit space', modalNewSpaceTitle: 'New space', modalSpaceName: 'Space name',
    modalSpaceIcon: 'Icon', btnRemoveSpace: 'Remove space',
    // ── Settings ──
    settingsTitle: 'Settings', settingsAppearance: 'Appearance',
    settingsSiteName: 'Site name', settingsAccentColor: 'Accent color',
    settingsCustomColor: 'Custom color', settingsLogo: 'Logo',
    settingsLogoUpload: 'Click to upload logo', settingsLogoFormats: 'PNG, SVG, WebP',
    settingsLogoRemove: 'Remove logo',
    settingsFavicon: 'Favicon', settingsFaviconSub: 'Browser tab icon',
    settingsFaviconUpload: 'Upload favicon', settingsFaviconRemove: 'Remove',
    settingsTabTitle: 'Browser tab title',
    settingsTheme: 'Dark / Light mode', settingsFooter: 'Footer',
    settingsFooterText: 'Footer text', settingsFooterSub: 'Shown in sidebar',
    settingsPage: 'Page',
    settingsPassword: 'Password', settingsPasswordNote: 'Password is securely stored (bcrypt)',
    settingsLanguage: 'Interface language',
    settingsTranslateToggle: 'Translate button',
    settingsTranslateToggleSub: 'Show Google Translate button in header for readers',
    colorOrange: 'Orange', colorBlue: 'Blue', colorPurple: 'Purple',
    colorGreen: 'Green', colorPink: 'Pink', colorRed: 'Red', colorYellow: 'Yellow',
    // ── Auth ──
    authPassword: 'Password', authLogin: 'Log in', authWrong: 'Wrong password',
    authEnterPw: 'Enter your password', authVerifying: 'Verifying...', authConnError: 'Server connection error',
    authLogoutConfirmTitle: 'Log out?', authLogoutConfirmMsg: 'You will switch to reader mode.',
    authLogoutOk: 'Log out',
    // ── Setup wizard ──
    setupTitle: 'Welcome to Webstudio Docs',
    setupSubtitle: 'Set up your admin password to get started.',
    setupPassword: 'Password',
    setupConfirm: 'Confirm password',
    setupBtn: 'Create password',
    setupCreating: 'Setting up...',
    setupMinLength: 'At least 8 characters',
    setupUppercase: 'Uppercase letter',
    setupLowercase: 'Lowercase letter',
    setupNumber: 'Number',
    setupSpecial: 'Special character (!@#$...)',
    setupMatch: 'Passwords match',
    setupMismatch: 'Passwords do not match',
    setupError: 'Setup failed. Check server permissions.',
    // ── Cover ──
    coverChange: 'Change', coverRemove: 'Remove', coverTop: 'Top',
    coverCenter: 'Center', coverBottom: 'Bottom',
    coverFitCover: 'Fill', coverFitContain: 'Fit',
    // ── Page ──
    pageUntitled: 'Untitled', pageDescPlaceholder: 'Short page description...',
    pageSelectPrompt: 'Select a page from the navigation or create a new one.',
    pageAddContent: 'Add content', pageEmpty: 'This page has no content yet.',
    // ── Shortcuts ──
    shortcutEdit: 'Edit / Preview', shortcutSave: 'Save', shortcutUndo: 'Undo', shortcutRedo: 'Redo',
    shortcutSearch: 'Search', shortcutReadingMode: 'Reading mode',
    shortcutShare: 'Share page', shortcutPrevNext: 'Previous / Next page', shortcutShortcuts: 'Shortcuts',
    // ── Block menu ──
    blockMoveUp: 'Move up', blockMoveDown: 'Move down', blockDelete: 'Delete block', blockDropHere: 'Drop here',
    blockHeading: 'Heading H', blockUnorderedList: 'Bullet list',
    blockOrderedList: 'Numbered list', blockAlignLeft: 'Align left',
    blockAlignCenter: 'Align center', blockFullWidth: 'Full width',
    blockWithBorder: 'With border', blockWithBackground: 'With background',
    blockShowHeadings: 'Show headings', blockHideHeadings: 'Hide headings',
    blockNumbered: 'Numbered', blockUnNumbered: 'Without numbering',
    blockCols: 'columns',
    // ── Block picker ──
    blockPickerHeading: 'Heading', blockPickerHeadingDesc: 'H1, H2, H3',
    blockPickerText: 'Text', blockPickerTextDesc: 'Plain paragraph',
    blockPickerList: 'List', blockPickerListDesc: 'Bullets or numbers',
    blockPickerChecklist: 'Checklist', blockPickerChecklistDesc: 'Task list',
    blockPickerImage: 'Image', blockPickerImageDesc: 'Upload or URL',
    blockPickerVideo: 'Video', blockPickerVideoDesc: 'YouTube / Vimeo',
    blockPickerCode: 'Code', blockPickerCodeDesc: 'Code block',
    blockPickerQuote: 'Quote', blockPickerQuoteDesc: 'Blockquote',
    blockPickerTable: 'Table', blockPickerTableDesc: 'Rows and columns',
    blockPickerCallout: 'Callout', blockPickerCalloutDesc: 'Info, tip, warning',
    blockPickerCollapse: 'Collapsible', blockPickerCollapseDesc: 'Foldable section',
    blockPickerTimeline: 'Timeline', blockPickerTimelineDesc: 'Vertical timeline',
    blockPickerCards: 'Cards', blockPickerCardsDesc: 'Card grid',
    blockPickerDelimiter: 'Divider', blockPickerDelimiterDesc: 'Horizontal line',
    // ── Callout tool ──
    calloutTitlePlaceholder: 'Title (optional)', calloutMsgPlaceholder: 'Description...',
    // ── Collapsible tool ──
    collapsiblePlaceholder: 'Section heading...',
    // ── Video tool ──
    videoInsertLabel: 'Insert video', videoInsertDesc: 'YouTube or Vimeo URL',
    videoInsertBtn: 'Insert',
    // ── Image tool ──
    imageDropLabel: 'Drop an image or <span class="lit-pick-btn">browse files</span>',
    imageDropSub: 'or enter a URL',
    imageUploadLabel: 'Click or drag to upload image',
    imageUrlPlaceholder: 'https://...', imageUrlBtn: 'Load',
    // ── Cards tool ──
    cardsColumns: 'Columns:',
    cardsLinkNone: 'No link', cardsLinkLabel: 'Link to page', cardsLinkExternal: 'External URL...',
    cardsTitlePlaceholder: 'Title', cardsDescPlaceholder: 'Description...',
    cardsDefaultTitle1: 'Getting started', cardsDefaultDesc1: 'First card description.',
    cardsDefaultTitle2: 'Documentation', cardsDefaultDesc2: 'Second card description.',
    cardsDefaultTitle3: 'API', cardsDefaultDesc3: 'Third card description.',
    // ── Timeline tool ──
    timelineNumbered: 'Numbered points', timelineAddBtn: 'Add point',
    timelineDatePlaceholder: 'Date / version', timelineTitlePlaceholder: 'Title',
    timelineDescPlaceholder: 'Description (supports line breaks, Shift+Enter for new line)...',
    // ── Editor ──
    editorPlaceholder: 'Click and start typing... or press / for blocks',
    // ── Toasts ──
    toastSaved: 'Saved', toastPageEdited: 'Page updated',
    toastPageDeleted: 'Page deleted', toastSpaceSaved: 'Space saved', toastSpaceCreated: 'Space created',
    toastSpaceDeleted: 'Space deleted', toastUploadError: 'Upload error',
    toastOrderSaved: 'Order saved', toastFeedback: 'Thanks for the feedback ',
    toastLastSpace: "Can't delete the last space",
    toastLinkCopied: 'Link copied',
    // ── Confirm dialogs ──
    confirmDeletePageTitle: 'Delete page?',
    confirmDeletePageMsg: (name, childCount) => `"${name}"${childCount ? ` and ${childCount} subpage(s)` : ''} will be permanently deleted.`,
    confirmDeleteSpaceTitle: 'Delete space?',
    confirmDeleteSpaceMsg: (name) => `Space "${name}" and all its pages will be permanently deleted.`,
    confirmDeleteOk: 'Delete', confirmDeletePageOk: 'Delete',
    confirmLogoutTitle: 'Log out?', confirmLogoutMsg: 'You will switch to reader mode.',
    confirmLogoutOk: 'Log out',
    // ── Templates ──
    tplBlankLabel: 'Blank', tplBlankDesc: 'Start from scratch',
    tplDocLabel: 'Documentation', tplDocDesc: 'Structured article',
    tplChangelogLabel: 'Changelog', tplChangelogDesc: 'Change history',
    tplApiLabel: 'API Reference', tplApiDesc: 'API documentation',
    tplTutorialLabel: 'Tutorial', tplTutorialDesc: 'Step by step',
    tplFaqLabel: 'FAQ', tplFaqDesc: 'Frequently asked questions',
    // ── Default content ──
    defaultSpaceName: 'My Docs',
    defaultPage1Title: 'Welcome', defaultPage1Section: 'GET STARTED',
    defaultPage1Subtitle: 'Welcome to your documentation.',
    defaultPage2Title: 'Installation', defaultPage2Section: 'GET STARTED',
    defaultPage2Subtitle: 'How to deploy docs on your own subdomain.',
    defaultPage3Title: 'Writing content', defaultPage3Section: 'CREATE',
    defaultPage3Subtitle: 'How to use the editor and all blocks.',
    defaultPage4Title: 'Blocks in editor',
    // ── Callout inline toolbar ──
    ctBold: 'Bold', ctItalic: 'Italic', ctUnderline: 'Underline',
    ctLink: 'Link', ctRemoveFormat: 'Remove formatting',
    ctLinkPlaceholder: 'https://', ctLinkRemove: 'Remove link',
    // ── Icon picker ──
    iconPickerSearch: 'Search icons...',
    // ── Drag & drop ──
    dragReorder: 'Drag to reorder',
  },
  de: {
    btnEdit: 'Bearbeitungsmodus', btnPreview: 'Bearbeitungsmodus verlassen', btnSave: 'Speichern', btnDiscard: 'Verwerfen',
    unsavedChanges: 'Sie haben ungespeicherte Änderungen',
    loaderLoading: 'Wird geladen...', loaderFailed: 'Laden fehlgeschlagen. Prüfen Sie Ihre Verbindung.', loaderRetry: 'Erneut versuchen',
    btnLogin: 'Anmelden', btnLoggedIn: 'Angemeldet', btnLogout: 'Abmelden',
    btnSettings: 'Einstellungen', btnReadingMode: 'Lesemodus (Fokus)',
    btnToggleTheme: 'Design umschalten', btnTranslate: 'Seite übersetzen',
    btnEditSpace: 'Bereich bearbeiten', btnNewSpace: 'Neuer Bereich',
    btnEditPage: 'Seite bearbeiten', btnSubpage: 'Unterseite', btnDeletePage: 'Löschen',
    btnAddPageSection: 'Zu Abschnitt hinzufügen',
    readingTimeLabel: (mins, words) => `${mins} Min. Lesezeit · ${words} Wörter`,
    lastUpdated: 'Zuletzt aktualisiert',
    navPrev: 'Zurück', navNext: 'Weiter',
    searchPlaceholder: 'Suchen...', searchNoResults: 'Keine Ergebnisse',
    savingLabel: 'Wird gespeichert...', savingBtn: 'Wird gespeichert...',
    tocTitle: 'Auf dieser Seite', tocNoHeadings: 'Keine Überschriften', tocFeedback: 'War das hilfreich?',
    tocShare: 'Teilen', tocShareCopied: 'Kopiert!',
    modalAddTitle: 'Neue Seite', modalAddPageName: 'Seitentitel',
    modalAddIcon: 'Icon (z. B. fa-file)', modalAddSection: 'Abschnitt (z. B. ERSTE SCHRITTE)',
    modalAddTemplate: 'Vorlage', btnCreate: 'Erstellen', btnCancel: 'Abbrechen',
    modalEditPageTitle: 'Seite bearbeiten', modalEditSubtitle: 'Beschreibung (optional)',
    modalEditSubtitlePlaceholder: 'Kurze Seitenbeschreibung',
    btnRemovePage: 'Entfernen', btnSaveChanges: 'Speichern',
    modalEditSpaceTitle: 'Bereich bearbeiten', modalNewSpaceTitle: 'Neuer Bereich', modalSpaceName: 'Bereichsname',
    modalSpaceIcon: 'Icon', btnRemoveSpace: 'Bereich entfernen',
    settingsTitle: 'Einstellungen', settingsAppearance: 'Darstellung',
    settingsSiteName: 'Website-Name', settingsAccentColor: 'Akzentfarbe',
    settingsCustomColor: 'Benutzerdefinierte Farbe', settingsLogo: 'Logo',
    settingsLogoUpload: 'Zum Hochladen des Logos klicken', settingsLogoFormats: 'PNG, SVG, WebP',
    settingsLogoRemove: 'Logo entfernen',
    settingsFavicon: 'Favicon', settingsFaviconSub: 'Browser-Tab-Symbol',
    settingsFaviconUpload: 'Favicon hochladen', settingsFaviconRemove: 'Entfernen',
    settingsTabTitle: 'Browser-Tab-Titel',
    settingsTheme: 'Dunkel- / Hellmodus', settingsFooter: 'Fußzeile',
    settingsFooterText: 'Text der Fußzeile', settingsFooterSub: 'Wird in der Seitenleiste angezeigt',
    settingsPage: 'Seite',
    settingsPassword: 'Passwort', settingsPasswordNote: 'Das Passwort wird sicher gespeichert (bcrypt)',
    settingsLanguage: 'Oberflächensprache',
    settingsTranslateToggle: 'Übersetzungsbutton',
    settingsTranslateToggleSub: 'Google-Translate-Button im Header für Leser anzeigen',
    colorOrange: 'Orange', colorBlue: 'Blau', colorPurple: 'Lila',
    colorGreen: 'Grün', colorPink: 'Pink', colorRed: 'Rot', colorYellow: 'Gelb',
    authPassword: 'Passwort', authLogin: 'Anmelden', authWrong: 'Falsches Passwort',
    authEnterPw: 'Passwort eingeben', authVerifying: 'Wird überprüft...', authConnError: 'Serververbindungsfehler',
    authLogoutConfirmTitle: 'Abmelden?', authLogoutConfirmMsg: 'Sie wechseln in den Lesemodus.',
    authLogoutOk: 'Abmelden',
    setupTitle: 'Willkommen bei Webstudio Docs',
    setupSubtitle: 'Richten Sie Ihr Admin-Passwort ein, um zu starten.',
    setupPassword: 'Passwort',
    setupConfirm: 'Passwort bestätigen',
    setupBtn: 'Passwort erstellen',
    setupCreating: 'Wird eingerichtet...',
    setupMinLength: 'Mindestens 8 Zeichen',
    setupUppercase: 'Großbuchstabe',
    setupLowercase: 'Kleinbuchstabe',
    setupNumber: 'Zahl',
    setupSpecial: 'Sonderzeichen (!@#$...)',
    setupMatch: 'Passwörter stimmen überein',
    setupMismatch: 'Passwörter stimmen nicht überein',
    setupError: 'Einrichtung fehlgeschlagen. Prüfen Sie die Serverberechtigungen.',
    coverChange: 'Ändern', coverRemove: 'Entfernen', coverTop: 'Oben',
    coverCenter: 'Mitte', coverBottom: 'Unten',
    coverFitCover: 'Füllen', coverFitContain: 'Einpassen',
    pageUntitled: 'Ohne Titel', pageDescPlaceholder: 'Kurze Seitenbeschreibung...',
    pageSelectPrompt: 'Wählen Sie eine Seite aus der Navigation oder erstellen Sie eine neue.',
    pageAddContent: 'Inhalt hinzufügen', pageEmpty: 'Diese Seite hat noch keinen Inhalt.',
    shortcutEdit: 'Bearbeiten / Vorschau', shortcutSave: 'Speichern', shortcutUndo: 'Rückgängig', shortcutRedo: 'Wiederholen',
    shortcutSearch: 'Suchen', shortcutReadingMode: 'Lesemodus',
    shortcutShare: 'Seite teilen', shortcutPrevNext: 'Vorherige / Nächste Seite', shortcutShortcuts: 'Tastenkürzel',
    blockMoveUp: 'Nach oben', blockMoveDown: 'Nach unten', blockDelete: 'Block löschen', blockDropHere: 'Hier ablegen',
    blockHeading: 'Überschrift H', blockUnorderedList: 'Aufzählung',
    blockOrderedList: 'Nummerierte Liste', blockAlignLeft: 'Linksbündig',
    blockAlignCenter: 'Zentriert', blockFullWidth: 'Volle Breite',
    blockWithBorder: 'Mit Rahmen', blockWithBackground: 'Mit Hintergrund',
    blockShowHeadings: 'Überschrift anzeigen', blockHideHeadings: 'Überschrift ausblenden',
    blockNumbered: 'Nummeriert', blockUnNumbered: 'Ohne Nummerierung',
    blockCols: 'Spalten',
    blockPickerHeading: 'Überschrift', blockPickerHeadingDesc: 'H1, H2, H3',
    blockPickerText: 'Text', blockPickerTextDesc: 'Einfacher Absatz',
    blockPickerList: 'Liste', blockPickerListDesc: 'Aufzählung oder Nummerierung',
    blockPickerChecklist: 'Checkliste', blockPickerChecklistDesc: 'Aufgabenliste',
    blockPickerImage: 'Bild', blockPickerImageDesc: 'Hochladen oder URL',
    blockPickerVideo: 'Video', blockPickerVideoDesc: 'YouTube / Vimeo',
    blockPickerCode: 'Code', blockPickerCodeDesc: 'Codeblock',
    blockPickerQuote: 'Zitat', blockPickerQuoteDesc: 'Blockzitat',
    blockPickerTable: 'Tabelle', blockPickerTableDesc: 'Zeilen und Spalten',
    blockPickerCallout: 'Callout', blockPickerCalloutDesc: 'Info, Tipp, Warnung',
    blockPickerCollapse: 'Einklappbar', blockPickerCollapseDesc: 'Aufklappbarer Abschnitt',
    blockPickerTimeline: 'Zeitleiste', blockPickerTimelineDesc: 'Vertikale Zeitleiste',
    blockPickerCards: 'Karten', blockPickerCardsDesc: 'Kartenraster',
    blockPickerDelimiter: 'Trenner', blockPickerDelimiterDesc: 'Horizontale Linie',
    calloutTitlePlaceholder: 'Titel (optional)', calloutMsgPlaceholder: 'Beschreibung...',
    collapsiblePlaceholder: 'Abschnittsüberschrift...',
    videoInsertLabel: 'Video einfügen', videoInsertDesc: 'YouTube- oder Vimeo-URL',
    videoInsertBtn: 'Einfügen',
    imageDropLabel: 'Bild hier ablegen oder <span class="lit-pick-btn">Dateien auswählen</span>',
    imageDropSub: 'oder eine URL eingeben',
    imageUploadLabel: 'Zum Hochladen klicken oder Bild hierher ziehen',
    imageUrlPlaceholder: 'https://...', imageUrlBtn: 'Laden',
    cardsColumns: 'Spalten:',
    cardsLinkNone: 'Kein Link', cardsLinkLabel: 'Link zur Seite', cardsLinkExternal: 'Externe URL...',
    cardsTitlePlaceholder: 'Titel', cardsDescPlaceholder: 'Beschreibung...',
    cardsDefaultTitle1: 'Erste Schritte', cardsDefaultDesc1: 'Beschreibung der ersten Karte.',
    cardsDefaultTitle2: 'Dokumentation', cardsDefaultDesc2: 'Beschreibung der zweiten Karte.',
    cardsDefaultTitle3: 'API', cardsDefaultDesc3: 'Beschreibung der dritten Karte.',
    timelineNumbered: 'Nummerierte Punkte', timelineAddBtn: 'Punkt hinzufügen',
    timelineDatePlaceholder: 'Datum / Version', timelineTitlePlaceholder: 'Titel',
    timelineDescPlaceholder: 'Beschreibung (unterstützt Zeilenumbrüche, Shift+Enter für eine neue Zeile)...',
    editorPlaceholder: 'Klicken und losschreiben... oder / für Blöcke drücken',
    toastSaved: 'Gespeichert', toastPageEdited: 'Seite aktualisiert',
    toastPageDeleted: 'Seite gelöscht', toastSpaceSaved: 'Bereich gespeichert', toastSpaceCreated: 'Bereich erstellt',
    toastSpaceDeleted: 'Bereich gelöscht', toastUploadError: 'Upload-Fehler',
    toastOrderSaved: 'Reihenfolge gespeichert', toastFeedback: 'Danke für das Feedback ',
    toastLastSpace: 'Der letzte Bereich kann nicht gelöscht werden',
    toastLinkCopied: 'Link kopiert',
    confirmDeletePageTitle: 'Seite löschen?',
    confirmDeletePageMsg: (name, childCount) => childCount ? `"${name}" und ${childCount} Unterseite(n) werden dauerhaft gelöscht.` : `"${name}" wird dauerhaft gelöscht.`,
    confirmDeleteSpaceTitle: 'Bereich löschen?',
    confirmDeleteSpaceMsg: (name) => `Der Bereich "${name}" und alle zugehörigen Seiten werden dauerhaft gelöscht.`,
    confirmDeleteOk: 'Löschen', confirmDeletePageOk: 'Löschen',
    confirmLogoutTitle: 'Abmelden?', confirmLogoutMsg: 'Sie wechseln in den Lesemodus.',
    confirmLogoutOk: 'Abmelden',
    tplBlankLabel: 'Leer', tplBlankDesc: 'Von Grund auf beginnen',
    tplDocLabel: 'Dokumentation', tplDocDesc: 'Strukturierter Artikel',
    tplChangelogLabel: 'Changelog', tplChangelogDesc: 'Änderungsverlauf',
    tplApiLabel: 'API Reference', tplApiDesc: 'API-Dokumentation',
    tplTutorialLabel: 'Anleitung', tplTutorialDesc: 'Schritt für Schritt',
    tplFaqLabel: 'FAQ', tplFaqDesc: 'Häufig gestellte Fragen',
    defaultSpaceName: 'Meine Docs',
    defaultPage1Title: 'Willkommen', defaultPage1Section: 'ERSTE SCHRITTE',
    defaultPage1Subtitle: 'Willkommen in Ihrer Dokumentation.',
    defaultPage2Title: 'Installation', defaultPage2Section: 'ERSTE SCHRITTE',
    defaultPage2Subtitle: 'So stellen Sie die Docs auf Ihrer eigenen Subdomain bereit.',
    defaultPage3Title: 'Inhalte erstellen', defaultPage3Section: 'ERSTELLEN',
    defaultPage3Subtitle: 'So verwenden Sie den Editor und alle Blöcke.',
    defaultPage4Title: 'Blöcke im Editor',
    ctBold: 'Fett', ctItalic: 'Kursiv', ctUnderline: 'Unterstrichen',
    ctLink: 'Link', ctRemoveFormat: 'Formatierung entfernen',
    ctLinkPlaceholder: 'https://', ctLinkRemove: 'Link entfernen',
    iconPickerSearch: 'Icons suchen...',
    dragReorder: 'Zum Neuordnen ziehen',
  },
  sk: {
    // ── Nav ──
    btnEdit: 'Editačný mód', btnPreview: 'Opustiť edit. mód', btnSave: 'Uložiť', btnDiscard: 'Zahodiť',
    unsavedChanges: 'Máte neuložené zmeny',
    loaderLoading: 'Načítavam...', loaderFailed: 'Nepodarilo sa načítať. Skontroluj pripojenie.', loaderRetry: 'Skúsiť znova',
    btnLogin: 'Prihlásiť sa', btnLoggedIn: 'Prihlásený', btnLogout: 'Odhlásiť',
    btnSettings: 'Nastavenia', btnReadingMode: 'Čítací mód (fokus)',
    btnToggleTheme: 'Prepnúť tému', btnTranslate: 'Preložiť stránku',
    btnEditSpace: 'Upraviť priestor', btnNewSpace: 'Nový priestor',
    btnEditPage: 'Upraviť stránku', btnSubpage: 'Podstránka', btnDeletePage: 'Vymazať',
    btnAddPageSection: 'Pridať do sekcie',
    // ── Reading time & nav ──
    readingTimeLabel: (mins, words) => `${mins} min čítania · ${words} slov`,
    lastUpdated: 'Naposledy upravené',
    navPrev: 'Predošlé', navNext: 'Ďalšie',
    // ── Search ──
    searchPlaceholder: 'Hľadaj...', searchNoResults: 'Žiadne výsledky',
    // ── Save bar ──
    savingLabel: 'Ukladá sa...', savingBtn: 'Ukladá sa...',
    // ── TOC ──
    tocTitle: 'Na tejto stránke', tocNoHeadings: 'Žiadne nadpisy', tocFeedback: 'Bolo to užitočné?',
    tocShare: 'Zdieľať', tocShareCopied: 'Skopírované!',
    // ── Modals — Add page ──
    modalAddTitle: 'Nová stránka', modalAddPageName: 'Názov stránky',
    modalAddIcon: 'Ikona (napr. fa-file)', modalAddSection: 'Sekcia (napr. GET STARTED)',
    modalAddTemplate: 'Šablóna', btnCreate: 'Vytvoriť', btnCancel: 'Zrušiť',
    // ── Modals — Edit page ──
    modalEditPageTitle: 'Upraviť stránku', modalEditSubtitle: 'Popis (voliteľný)',
    modalEditSubtitlePlaceholder: 'Krátky popis stránky',
    btnRemovePage: 'Odstrániť', btnSaveChanges: 'Uložiť',
    // ── Modals — Space ──
    modalEditSpaceTitle: 'Upraviť priestor', modalNewSpaceTitle: 'Nový priestor', modalSpaceName: 'Názov priestoru',
    modalSpaceIcon: 'Ikona', btnRemoveSpace: 'Odstrániť priestor',
    // ── Settings ──
    settingsTitle: 'Nastavenia', settingsAppearance: 'Vzhľad',
    settingsSiteName: 'Názov webu', settingsAccentColor: 'Farba zvýraznenia',
    settingsCustomColor: 'Vlastná farba', settingsLogo: 'Logo',
    settingsLogoUpload: 'Klikni pre nahratie loga', settingsLogoFormats: 'PNG, SVG, WebP',
    settingsLogoRemove: 'Odstrániť logo',
    settingsFavicon: 'Favicon', settingsFaviconSub: 'Ikona v tabe prehliadača',
    settingsFaviconUpload: 'Nahrať favicon', settingsFaviconRemove: 'Odstrániť',
    settingsTabTitle: 'Nadpis tabu prehliadača',
    settingsTheme: 'Tmavý / Svetlý režim', settingsFooter: 'Päta',
    settingsFooterText: 'Text päty', settingsFooterSub: 'Zobrazené v bočnom paneli',
    settingsPage: 'Stránka',
    settingsPassword: 'Heslo', settingsPasswordNote: 'Heslo je bezpečne uložené (bcrypt)',
    settingsLanguage: 'Jazyk rozhrania',
    settingsTranslateToggle: 'Tlačidlo prekladu',
    settingsTranslateToggleSub: 'Zobraziť Google Translate tlačidlo v hlavičke pre čitateľov',
    colorOrange: 'Oranžová', colorBlue: 'Modrá', colorPurple: 'Fialová',
    colorGreen: 'Zelená', colorPink: 'Ružová', colorRed: 'Červená', colorYellow: 'Žltá',
    // ── Auth ──
    authPassword: 'Heslo', authLogin: 'Prihlásiť sa', authWrong: 'Nesprávne heslo',
    authEnterPw: 'Zadaj heslo', authVerifying: 'Overujem...', authConnError: 'Chyba pripojenia k serveru',
    authLogoutConfirmTitle: 'Odhlásiť sa?', authLogoutConfirmMsg: 'Prepneš sa do režimu čitateľa.',
    authLogoutOk: 'Odhlásiť',
    // ── Setup wizard ──
    setupTitle: 'Vitajte vo Webstudio Docs',
    setupSubtitle: 'Nastavte si admin heslo pre začiatok.',
    setupPassword: 'Heslo',
    setupConfirm: 'Potvrdenie hesla',
    setupBtn: 'Vytvoriť heslo',
    setupCreating: 'Nastavujem...',
    setupMinLength: 'Minimálne 8 znakov',
    setupUppercase: 'Veľké písmeno',
    setupLowercase: 'Malé písmeno',
    setupNumber: 'Číslo',
    setupSpecial: 'Špeciálny znak (!@#$...)',
    setupMatch: 'Heslá sa zhodujú',
    setupMismatch: 'Heslá sa nezhodujú',
    setupError: 'Nastavenie zlyhalo. Skontrolujte oprávnenia servera.',
    // ── Cover ──
    coverChange: 'Zmeniť', coverRemove: 'Odstrániť', coverTop: 'Hore',
    coverCenter: 'Stred', coverBottom: 'Dole',
    coverFitCover: 'Vyplniť', coverFitContain: 'Zmestiť',
    // ── Page ──
    pageUntitled: 'Bez názvu', pageDescPlaceholder: 'Krátky popis stránky...',
    pageSelectPrompt: 'Vyber stránku z navigácie alebo vytvor novú.',
    pageAddContent: 'Pridať obsah', pageEmpty: 'Táto stránka ešte nemá obsah.',
    // ── Shortcuts ──
    shortcutEdit: 'Upraviť / Náhľad', shortcutSave: 'Uložiť', shortcutUndo: 'Späť', shortcutRedo: 'Znova',
    shortcutSearch: 'Hľadať', shortcutReadingMode: 'Čítací mód',
    shortcutShare: 'Zdieľať stránku', shortcutPrevNext: 'Predošlá / Ďalšia stránka', shortcutShortcuts: 'Skratky',
    // ── Block menu ──
    blockMoveUp: 'Presunúť hore', blockMoveDown: 'Presunúť dole', blockDelete: 'Vymazať blok', blockDropHere: 'Pustiť sem',
    blockHeading: 'Nadpis H', blockUnorderedList: 'Odrážkový zoznam',
    blockOrderedList: 'Číslovaný zoznam', blockAlignLeft: 'Zarovnať vľavo',
    blockAlignCenter: 'Zarovnať na stred', blockFullWidth: 'Na celú šírku',
    blockWithBorder: 'Orámovanie', blockWithBackground: 'Pozadie',
    blockShowHeadings: 'Zobraziť hlavičku', blockHideHeadings: 'Skryť hlavičku',
    blockNumbered: 'S číslovaním', blockUnNumbered: 'Bez číslovania',
    blockCols: 'stĺpce',
    // ── Block picker ──
    blockPickerHeading: 'Nadpis', blockPickerHeadingDesc: 'H1, H2, H3',
    blockPickerText: 'Text', blockPickerTextDesc: 'Obyčajný odstavec',
    blockPickerList: 'Zoznam', blockPickerListDesc: 'Odrážky alebo čísel.',
    blockPickerChecklist: 'Checklist', blockPickerChecklistDesc: 'Zoznam úloh',
    blockPickerImage: 'Obrázok', blockPickerImageDesc: 'Nahraj alebo URL',
    blockPickerVideo: 'Video', blockPickerVideoDesc: 'YouTube / Vimeo',
    blockPickerCode: 'Kód', blockPickerCodeDesc: 'Blok kódu',
    blockPickerQuote: 'Citát', blockPickerQuoteDesc: 'Blockquote',
    blockPickerTable: 'Tabuľka', blockPickerTableDesc: 'Riadky a stĺpce',
    blockPickerCallout: 'Callout', blockPickerCalloutDesc: 'Info, tip, warning',
    blockPickerCollapse: 'Collapsible', blockPickerCollapseDesc: 'Skladacia sekcia',
    blockPickerTimeline: 'Timeline', blockPickerTimelineDesc: 'Vertikálna os',
    blockPickerCards: 'Karty', blockPickerCardsDesc: 'Grid kariet',
    blockPickerDelimiter: 'Oddeľovač', blockPickerDelimiterDesc: 'Horizontálna čiara',
    // ── Callout tool ──
    calloutTitlePlaceholder: 'Titulok (voliteľný)', calloutMsgPlaceholder: 'Popis...',
    // ── Collapsible tool ──
    collapsiblePlaceholder: 'Nadpis sekcie...',
    // ── Video tool ──
    videoInsertLabel: 'Vložiť video', videoInsertDesc: 'YouTube alebo Vimeo URL',
    videoInsertBtn: 'Vložiť',
    // ── Image tool ──
    imageDropLabel: 'Pretiahnite obrázok alebo <span class="lit-pick-btn">vyberte súbor</span>',
    imageDropSub: 'alebo zadajte URL',
    imageUploadLabel: 'Klikni alebo presuň obrázok',
    imageUrlPlaceholder: 'https://...', imageUrlBtn: 'Načítať',
    // ── Cards tool ──
    cardsColumns: 'Stĺpce:',
    cardsLinkNone: 'Bez odkazu', cardsLinkLabel: 'Odkaz na stránku', cardsLinkExternal: 'Externá URL...',
    cardsTitlePlaceholder: 'Titulok', cardsDescPlaceholder: 'Popis...',
    cardsDefaultTitle1: 'Začíname', cardsDefaultDesc1: 'Popis prvej karty.',
    cardsDefaultTitle2: 'Dokumentácia', cardsDefaultDesc2: 'Popis druhej karty.',
    cardsDefaultTitle3: 'API', cardsDefaultDesc3: 'Popis tretej karty.',
    // ── Timeline tool ──
    timelineNumbered: 'Číslované body', timelineAddBtn: 'Pridať bod',
    timelineDatePlaceholder: 'Dátum / verzia', timelineTitlePlaceholder: 'Titulok',
    timelineDescPlaceholder: 'Popis (podporuje zalomenie textu, Shift+Enter pre nový riadok)...',
    // ── Editor ──
    editorPlaceholder: 'Klikni a začni písať... alebo stlač / pre blok',
    // ── Toasts ──
    toastSaved: 'Uložené', toastPageEdited: 'Stránka upravená',
    toastPageDeleted: 'Stránka vymazaná', toastSpaceSaved: 'Priestor uložený', toastSpaceCreated: 'Priestor vytvorený',
    toastSpaceDeleted: 'Priestor odstránený', toastUploadError: 'Chyba pri nahrávaní obrázka',
    toastOrderSaved: 'Poradie uložené', toastFeedback: 'Ďakujeme za feedback ',
    toastLastSpace: 'Nemôžeš odstrániť posledný priestor',
    toastLinkCopied: 'Skopírované!',
    // ── Confirm dialogs ──
    confirmDeletePageTitle: 'Vymazať stránku?',
    confirmDeletePageMsg: (name, childCount) => `"${name}"${childCount ? ` a ${childCount} podstránok` : ''} bude natrvalo odstránená.`,
    confirmDeleteSpaceTitle: 'Odstrániť priestor?',
    confirmDeleteSpaceMsg: (name) => `Priestor "${name}" a všetky jeho stránky budú natrvalo odstránené.`,
    confirmDeleteOk: 'Odstrániť', confirmDeletePageOk: 'Vymazať',
    confirmLogoutTitle: 'Odhlásiť sa?', confirmLogoutMsg: 'Prepneš sa do režimu čitateľa.',
    confirmLogoutOk: 'Odhlásiť',
    // ── Templates ──
    tplBlankLabel: 'Prázdna', tplBlankDesc: 'Začni od nuly',
    tplDocLabel: 'Dokumentácia', tplDocDesc: 'Štruktúrovaný článok',
    tplChangelogLabel: 'Changelog', tplChangelogDesc: 'História zmien',
    tplApiLabel: 'API Reference', tplApiDesc: 'Dokumentácia API',
    tplTutorialLabel: 'Návod', tplTutorialDesc: 'Krok za krokom',
    tplFaqLabel: 'FAQ', tplFaqDesc: 'Časté otázky',
    // ── Default content ──
    defaultSpaceName: 'My Docs',
    defaultPage1Title: 'Vitaj', defaultPage1Section: 'GET STARTED',
    defaultPage1Subtitle: 'Úvodná stránka tvojej dokumentácie.',
    defaultPage2Title: 'Inštalácia', defaultPage2Section: 'GET STARTED',
    defaultPage2Subtitle: 'Ako nahodiť docs na vlastnú subdoménu.',
    defaultPage3Title: 'Písanie obsahu', defaultPage3Section: 'CREATE',
    defaultPage3Subtitle: 'Ako používať editor a všetky bloky.',
    defaultPage4Title: 'Bloky v editore',
    // ── Callout inline toolbar ──
    ctBold: 'Tučné', ctItalic: 'Kurzíva', ctUnderline: 'Podčiarknutie',
    ctLink: 'Odkaz', ctRemoveFormat: 'Odstrániť formátovanie',
    ctLinkPlaceholder: 'https://', ctLinkRemove: 'Odstrániť link',
    // ── Icon picker ──
    iconPickerSearch: 'Hľadaj ikony...',
    // ── Drag & drop ──
    dragReorder: 'Presuň pre zmenu poradia',
  },
};

const DEFAULT_INTERFACE_LANG = 'de';
const LANG_LOCALES = {
  de: 'de-DE',
  en: 'en-US',
  sk: 'sk-SK',
};

// t() — get translation for current language, fallback to default language
function t(key, ...args) {
  const lang = (typeof S !== 'undefined' ? S?.settings?.lang : null) || DEFAULT_INTERFACE_LANG;
  const dict = TRANSLATIONS[lang] || TRANSLATIONS[DEFAULT_INTERFACE_LANG];
  const val = dict[key] ?? TRANSLATIONS[DEFAULT_INTERFACE_LANG][key] ?? TRANSLATIONS.en[key] ?? key;
  return typeof val === 'function' ? val(...args) : val;
}

// Apply all translatable static HTML elements
function applyTranslations() {
  const els = document.querySelectorAll('[data-i18n]');
  els.forEach(el => {
    const key = el.dataset.i18n;
    const attr = el.dataset.i18nAttr;
    const val = t(key);
    if (attr) el.setAttribute(attr, val);
    else el.textContent = val;
  });
  const pls = document.querySelectorAll('[data-i18n-ph]');
  pls.forEach(el => el.placeholder = t(el.dataset.i18nPh));
}


const DEFAULT_ACCENT = '#f97316';
const ACCENT_COLORS = ['#f97316','#3b82f6','#8b5cf6','#10b981','#ec4899','#ef4444','#eab308'];

function getPageTemplates() { return [
  {
    id: 'blank', label: t('tplBlankLabel'), icon: 'fa-file', desc: t('tplBlankDesc'),
    content: { blocks: [] }, subtitle: '', cover: null,
  },
  {
    id: 'doc', label: t('tplDocLabel'), icon: 'fa-book-open', desc: t('tplDocDesc'),
    subtitle: '',
    cover: null,
    content: { blocks: [
      { type:'header', data:{ text:'Overview', level:2 } },
      { type:'paragraph', data:{ text:'Write an introduction to the topic here.' } },
      { type:'header', data:{ text:'Requirements', level:2 } },
      { type:'list', data:{ style:'unordered', items:[{content:'First requirement',items:[]},{content:'Second requirement',items:[]}] } },
      { type:'header', data:{ text:'Steps', level:2 } },
      { type:'paragraph', data:{ text:'Describe the process step by step.' } },
    ]}
  },
  {
    id: 'changelog', label: t('tplChangelogLabel'), icon: 'fa-clock-rotate-left', desc: t('tplChangelogDesc'),
    subtitle: '',
    cover: null,
    content: { blocks: [
      { type:'timeline', data:{ numbered:false, items:[
        { date:'v1.1.0 — '+new Date().toLocaleDateString(), title:'New feature', desc:'Description of what was added or changed.' },
        { date:'v1.0.0', title:'First release', desc:'Initial version of the project.' },
      ]}}
    ]}
  },
  {
    id: 'api', label: t('tplApiLabel'), icon: 'fa-code', desc: t('tplApiDesc'),
    subtitle: '',
    cover: null,
    content: { blocks: [
      { type:'header', data:{ text:'Endpoint', level:2 } },
      { type:'code', data:{ code:'GET /api/v1/resource' } },
      { type:'header', data:{ text:'Parameters', level:3 } },
      { type:'table', data:{ withHeadings:true, content:[['Parameter','Type','Description'],['id','string','Record ID'],['limit','number','Max results']] } },
      { type:'header', data:{ text:'Response', level:3 } },
      { type:'code', data:{ code:'{\n  "ok": true,\n  "data": []\n}' } },
      { type:'warning', data:{ type:'info', title:'Authentication', message:'All requests require a Bearer token in the Authorization header.' } },
    ]}
  },
  {
    id: 'tutorial', label: t('tplTutorialLabel'), icon: 'fa-graduation-cap', desc: t('tplTutorialDesc'),
    subtitle: '',
    cover: null,
    content: { blocks: [
      { type:'warning', data:{ type:'tip', title:'What you will learn', message:'Description of the outcome after completing this tutorial.' } },
      { type:'header', data:{ text:'Step 1 — Getting started', level:2 } },
      { type:'paragraph', data:{ text:'Description of the first step.' } },
      { type:'header', data:{ text:'Step 2 — Next steps', level:2 } },
      { type:'paragraph', data:{ text:'Description of the second step.' } },
      { type:'header', data:{ text:'Conclusion', level:2 } },
      { type:'paragraph', data:{ text:'Summary and next steps.' } },
    ]}
  },
  {
    id: 'faq', label: t('tplFaqLabel'), icon: 'fa-circle-question', desc: t('tplFaqDesc'),
    subtitle: '',
    cover: null,
    content: { blocks: [
      { type:'header', data:{ text:'Frequently Asked Questions', level:2 } },
      { type:'collapse', data:{ title:'How do I get started?', body:'Describe the answer to this question here.' } },
      { type:'collapse', data:{ title:'Where can I find the documentation?', body:'Link to documentation or description.' } },
      { type:'collapse', data:{ title:'How do I contact support?', body:'Contact information and process.' } },
    ]}
  },
]; }

let selectedTemplate = 'blank';

// ════════════════════════════════════════
//  STATE
// ════════════════════════════════════════
let S = {
  pages: [],
  spaces: [],
  currentSpaceId: null,
  currentPageId: null,
  editMode: false,
  addParentId: null,
  settings: {
    siteName: 'My Docs',
    accentColor: DEFAULT_ACCENT,
    theme: 'dark',
    footerText: 'Powered by Docs',
    logoDataUrl: null,
    faviconDataUrl: null,
    tabTitle: 'Docs',
    lang: DEFAULT_INTERFACE_LANG,
    translateEnabled: true,
  }
};

let editor = null;
let saveTimer = null;
let iconPreviewTimeout = null;

// ════════════════════════════════════════
//  STORAGE
// ════════════════════════════════════════
// ════════════════════════════════════════
//  DATA — server JSON (api.php)
// ════════════════════════════════════════

async function load() {
  try {
    const r = await fetch('api.php?action=load', { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) throw new Error(d.error);

    S.spaces   = d.spaces   || [];
    S.settings = { ...S.settings, ...(d.settings || {}) };

    // Pages — content sa načíta lazy pri navigácii
    S.pages = (d.pages || []).map(p => ({ ...p, _contentLoaded: !!p.content?.blocks }));

    // Migrate old list format
    S.pages.forEach(p => {
      if (p.content?.blocks) {
        p.content.blocks.forEach(b => {
          if (b.type === 'list' && Array.isArray(b.data?.items)) {
            b.data.items = b.data.items.map(i => typeof i === 'string' ? { content: i, items: [] } : i);
          }
        });
      }
    });

  } catch(e) {
    console.warn('Load error:', e);
  }
  if (!S.spaces.length) await initDefaults();
}

async function loadPageContent(pageId) {
  const page = S.pages.find(p => p.id === pageId);
  if (!page || page._contentLoaded) return page;
  try {
    const r = await fetch(`api.php?action=load_page&id=${pageId}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok && d.page) {
      Object.assign(page, d.page);
      page._contentLoaded = true;
    }
  } catch(e) {}
  return page;
}

async function save() {
  // Uloží spaces + settings (nie pages — tie sa ukladajú zvlášť cez savePage)
  try {
    await fetch('api.php?action=save_spaces', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ spaces: S.spaces })
    });
    await fetch('api.php?action=save_settings', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ settings: S.settings })
    });
  } catch(e) { console.warn('Save error:', e); }
}

async function savePageToServer(page) {
  const { _contentLoaded, ...pageData } = page;
  pageData.updatedAt = new Date().toISOString();
  page.updatedAt = pageData.updatedAt;
  try {
    await fetch('api.php?action=save_page', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: pageData })
    });
  } catch(e) { console.warn('Save page error:', e); }
}

async function deletePageFromServer(id) {
  try {
    await fetch('api.php?action=delete_page', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
  } catch(e) {}
}

async function initDefaults() {
  const spaceId = uid();
  S.spaces = [{ id: spaceId, name: t('defaultSpaceName'), icon: 'fa-book' }];
  S.pages = [
    { id: 'welcome', spaceId, parentId: null, title: t('defaultPage1Title'), icon: 'fa-house', subtitle: t('defaultPage1Subtitle'), section: t('defaultPage1Section'), order: 0, content: makeDefaultContent1(), _contentLoaded: true },
    { id: 'installation', spaceId, parentId: null, title: t('defaultPage2Title'), icon: 'fa-terminal', subtitle: t('defaultPage2Subtitle'), section: t('defaultPage2Section'), order: 1, content: makeDefaultContent2(), _contentLoaded: true },
    { id: 'writing-content', spaceId, parentId: null, title: t('defaultPage3Title'), icon: 'fa-pen', subtitle: t('defaultPage3Subtitle'), section: t('defaultPage3Section'), order: 2, content: makeDefaultContent3(), _contentLoaded: true },
    { id: 'blocks-in-editor', spaceId, parentId: 'writing-content', title: t('defaultPage4Title'), icon: 'fa-cube', subtitle: '', section: null, order: 0, content: { blocks: [] }, _contentLoaded: true },
  ];
  S.currentSpaceId = spaceId;
  S.currentPageId = 'welcome';
  // Ulož defaults na server
  await save();
  for (const p of S.pages) await savePageToServer(p);
}

function uid() { return '_' + Math.random().toString(36).slice(2,10); }

function pageSlug(title) {
  let slug = title.toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove diacritics
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60) || 'page';
  // Ensure unique
  let candidate = slug;
  let counter = 1;
  while (S.pages.some(p => p.id === candidate)) {
    candidate = `${slug}-${counter++}`;
  }
  return candidate;
}

function formatDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const lang = S.settings?.lang || DEFAULT_INTERFACE_LANG;
  const locale = LANG_LOCALES[lang] || LANG_LOCALES[DEFAULT_INTERFACE_LANG];
  const separator = lang === 'sk' ? ' o ' : ', ';
  return d.toLocaleDateString(locale, { day:'numeric', month:'long', year:'numeric' })
    + separator + d.toLocaleTimeString(locale, { hour:'2-digit', minute:'2-digit' });
}

// ════════════════════════════════════════
//  SETTINGS / THEME
// ════════════════════════════════════════
function applySettings() {
  const s = S.settings;
  document.documentElement.dataset.theme = s.theme;
  document.documentElement.lang = s.lang || DEFAULT_INTERFACE_LANG;
  document.getElementById('theme-btn').innerHTML = s.theme === 'dark'
    ? '<i class="fa-solid fa-moon"></i>' : '<i class="fa-solid fa-sun"></i>';
  document.getElementById('theme-toggle').className = 'toggle ' + (s.theme === 'dark' ? 'on' : '');

  // Prism theme
  const prismLink = document.getElementById('prism-theme');
  if (prismLink) prismLink.href = s.theme === 'dark'
    ? 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css'
    : 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css';

  // accent
  const r = parseInt(s.accentColor.slice(1,3),16);
  const g = parseInt(s.accentColor.slice(3,5),16);
  const b = parseInt(s.accentColor.slice(5,7),16);
  document.documentElement.style.setProperty('--accent', s.accentColor);
  document.documentElement.style.setProperty('--accent-rgb', `${r},${g},${b}`);

  // logo
  const logoEl = document.getElementById('logo-display');
  const logoPreviewArea = document.getElementById('logo-preview-area');
  if (s.logoDataUrl) {
    logoEl.innerHTML = `<img src="${s.logoDataUrl}" alt="logo">`;
    if (logoPreviewArea) logoPreviewArea.innerHTML = `
      <img src="${s.logoDataUrl}" style="max-height:30px;max-width:80px;border-radius:4px">
      <button class="btn btn-ghost" style="font-size:11px;padding:2px 8px;color:#ef4444;border:1px solid rgba(239,68,68,.3)" onclick="event.stopPropagation();S.settings.logoDataUrl='';saveSettingsDebounced();applySettings();"><i class="fa-solid fa-trash"></i> ${t('settingsLogoRemove')}</button>`;
  } else {
    logoEl.innerHTML = `<i class="fa-solid fa-book-open"></i>`;
    if (logoPreviewArea) logoPreviewArea.innerHTML = `<i class="fa-solid fa-cloud-arrow-up" style="font-size:18px;color:var(--text4)"></i><span>${t('settingsLogoUpload')}</span>`;
  }

  // favicon
  const faviconLink = document.getElementById('dynamic-favicon');
  const faviconPreview = document.getElementById('favicon-preview-area');
  if (s.faviconDataUrl) {
    if (faviconLink) { faviconLink.href = s.faviconDataUrl; faviconLink.type = ''; }
    if (faviconPreview) faviconPreview.innerHTML = `
      <img src="${s.faviconDataUrl}" style="width:20px;height:20px;border-radius:3px;object-fit:contain">
      <button class="btn btn-ghost" style="font-size:11px;padding:2px 8px;color:#ef4444;border:1px solid rgba(239,68,68,.3)" onclick="event.stopPropagation();S.settings.faviconDataUrl='';saveSettingsDebounced();applySettings();"><i class="fa-solid fa-trash"></i> ${t('settingsFaviconRemove')}</button>`;
  } else {
    if (faviconLink) faviconLink.href = 'data:,';
    if (faviconPreview) faviconPreview.innerHTML = `<i class="fa-solid fa-image" style="font-size:14px;color:var(--text4)"></i><span>${t('settingsFaviconUpload')}</span>`;
  }

  document.getElementById('logo-name-display').textContent = s.siteName || 'My Docs';
  document.getElementById('footer-text-display').textContent = s.footerText || 'Powered by Docs';
  document.title = s.tabTitle || 'Docs';

  // sync inputs
  document.getElementById('site-name-input').value = s.siteName || '';
  document.getElementById('footer-input').value = s.footerText || '';
  document.getElementById('tab-title-input').value = s.tabTitle || '';

  // color swatches
  document.querySelectorAll('.color-swatch').forEach(el => {
    el.classList.toggle('active', el.dataset.color === s.accentColor);
  });

  // lang select sync
  const langSel = document.getElementById('lang-select');
  if (langSel && s.lang) langSel.value = s.lang;

  // translate toggle sync
  const translateToggle = document.getElementById('translate-toggle');
  if (translateToggle) translateToggle.className = 'toggle ' + (isTranslateEnabled() ? 'on' : '');

  applyTranslateAvailability();

  // apply translations to all data-i18n elements
  applyTranslations();
}

function isTranslateEnabled() {
  return S.settings.translateEnabled !== false;
}

function setLang(lang) {
  if (!TRANSLATIONS[lang]) return;
  S.settings.lang = lang;
  saveSettingsDebounced();
  applySettings();
  updateTranslateOrigin();
  renderSpaces();
  renderNav();
  renderPage();
  syncEditUI();
  updateAdminUI();
}

function toggleTranslateAvailability() {
  S.settings.translateEnabled = !isTranslateEnabled();
  saveSettingsDebounced();
  applySettings();
}

// Maps interface lang code → {flag, name} for the GT "original" dropdown item
const LANG_META = {
  de: { flag: '🇩🇪', name: 'Deutsch' },
  en: { flag: '🇬🇧', name: 'English' },
  sk: { flag: '🇸🇰', name: 'Slovenčina' },
};

function updateTranslateOrigin() {
  const lang = S.settings.lang || DEFAULT_INTERFACE_LANG;
  const meta = LANG_META[lang] || { flag: '🌐', name: lang.toUpperCase() };
  const flagEl = document.getElementById('translate-origin-flag');
  const labelEl = document.getElementById('translate-origin-label');
  if (flagEl) flagEl.textContent = meta.flag;
  if (labelEl) labelEl.textContent = meta.name + ' (original)';
  document.querySelectorAll('.translate-lang[data-lang]').forEach(item => {
    if (item.id === 'translate-origin-item') return;
    item.style.display = item.dataset.lang === lang ? 'none' : '';
  });
}

function resetTranslateState() {
  const srcLang = S.settings.lang || DEFAULT_INTERFACE_LANG;
  if (typeof doGTranslate === 'function') {
    doGTranslate(`${srcLang}|${srcLang}`);
  }
  const combo = document.querySelector('.goog-te-combo');
  if (combo) {
    combo.value = srcLang;
    combo.dispatchEvent(new Event('change'));
  }
  document.querySelectorAll('iframe.skiptranslate, .goog-te-banner-frame, #goog-gt-tt').forEach(el => el.remove());
  document.cookie = 'googtrans=; max-age=0; path=/';
  document.cookie = `googtrans=; max-age=0; path=/; domain=${location.hostname}`;
  document.cookie = `googtrans=; max-age=0; path=/; domain=.${location.hostname}`;
  document.getElementById('gt-script')?.remove();
  document.body.style.top = '';
}

function applyTranslateAvailability() {
  const wrap = document.getElementById('translate-wrap');
  const showTranslate = !S.authed && isTranslateEnabled();
  if (wrap) wrap.style.display = showTranslate ? '' : 'none';

  if (!showTranslate) {
    document.getElementById('translate-dd')?.classList.remove('open');
    resetTranslateState();
    return;
  }

  loadTranslateWidget();
}

let _settingsSaveTimer = null;
function saveSettingsDebounced() {
  clearTimeout(_settingsSaveTimer);
  _settingsSaveTimer = setTimeout(() => save(), 800);
}

function toggleTheme() {
  S.settings.theme = S.settings.theme === 'dark' ? 'light' : 'dark';
  saveSettingsDebounced(); applySettings();
}

function setAccent(color, el, fromCustom = false) {
  S.settings.accentColor = color;
  if (!fromCustom) {
    document.querySelectorAll('.color-swatch').forEach(e => e.classList.remove('active'));
    if (el) el.classList.add('active');
  }
  saveSettingsDebounced(); applySettings();
}

function updateSiteName(v) {
  S.settings.siteName = v;
  document.getElementById('logo-name-display').textContent = v || 'My Docs';
  saveSettingsDebounced();
}

function updateFooter(v) {
  S.settings.footerText = v;
  document.getElementById('footer-text-display').textContent = v || '';
  saveSettingsDebounced();
}

function updateTabTitle(v) {
  S.settings.tabTitle = v;
  document.title = v || 'Docs';
  saveSettingsDebounced();
}

function handleLogoUpload(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    S.settings.logoDataUrl = e.target.result;
    const area = document.getElementById('logo-preview-area');
    area.innerHTML = `<img src="${e.target.result}" class="logo-preview" style="max-height:30px;max-width:80px;border-radius:4px">`;
    saveSettingsDebounced(); applySettings();
  };
  reader.readAsDataURL(file);
}

function handleFaviconUpload(input) {
  const file = input.files[0];
  if (!file) return;
  // Max 256KB for favicon
  if (file.size > 256 * 1024) { showToast('Favicon too large (max 256 KB)'); return; }
  const reader = new FileReader();
  reader.onload = e => {
    S.settings.faviconDataUrl = e.target.result;
    saveSettingsDebounced(); applySettings();
  };
  reader.readAsDataURL(file);
}

function openSettings() {
  document.getElementById('settings-panel').classList.add('open');
  document.getElementById('settings-overlay').classList.add('open');
}
function closeSettings() {
  document.getElementById('settings-panel').classList.remove('open');
  document.getElementById('settings-overlay').classList.remove('open');
}

// ════════════════════════════════════════
//  TABS IN TOPNAV (inline, GitBook style)
// ════════════════════════════════════════
function renderSpaces() {
  const strip = document.getElementById('tab-strip');
  strip.innerHTML = '';

  S.spaces.forEach(sp => {
    const el = document.createElement('div');
    el.className = 'tab-item' + (sp.id === S.currentSpaceId ? ' active' : '');
    el.innerHTML = `<i class="fa-solid ${sp.icon || 'fa-book'}"></i><span>${esc(sp.name)}</span>${S.authed ? `<button class="tab-edit-btn" title="${t('btnEditSpace')}"><i class="fa-solid fa-pen"></i></button>` : ''}`;
    el.onclick = () => switchSpace(sp.id);

    if (S.authed) {
      el.querySelector('.tab-edit-btn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        openSpaceMenu(sp, el);
      });
    }

    strip.appendChild(el);
  });

  // Add space button — admin only
  const addBtn = document.createElement('button');
  addBtn.className = 'tab-add' + (S.authed ? ' admin-visible' : '');
  addBtn.innerHTML = `<i class="fa-solid fa-plus"></i> ${t('btnNewSpace')}`;
  addBtn.onclick = addSpace;
  strip.appendChild(addBtn);
}

let editingSpaceId = null;

function openSpaceMenu(sp) {
  editingSpaceId = sp.id;
  document.getElementById('space-modal-title').textContent = t('modalEditSpaceTitle');
  document.getElementById('space-name-input').value = sp.name;
  const icon = sp.icon || 'fa-book';
  document.getElementById('space-icon-input').value = icon;
  document.getElementById('space-icon-preview').innerHTML = `<i class="fa-solid ${icon}"></i>`;
  document.getElementById('space-delete-btn').style.display = '';
  document.getElementById('space-cancel-btn').style.display = 'none';
  document.getElementById('space-cancel-btn-right').style.display = '';
  document.getElementById('space-confirm-btn').innerHTML = `<i class="fa-solid fa-check"></i> ${t('btnSaveChanges')}`;
  buildSpaceIconGrid();
  openModal('space-modal');
  setTimeout(() => document.getElementById('space-name-input').focus(), 100);
}

function previewSpaceIcon(val) {
  const icon = val.startsWith('fa-') ? val : 'fa-' + val;
  document.getElementById('space-icon-preview').innerHTML = `<i class="fa-solid ${icon}"></i>`;
}

function buildSpaceIconGrid() {
  const grid = document.getElementById('space-icon-grid');
  if (!grid) return;
  grid.innerHTML = '';
  ICON_LIST.slice(0, 32).forEach(ic => {
    const el = document.createElement('div');
    el.className = 'ig-item';
    el.innerHTML = `<i class="fa-solid ${ic}" title="${ic}"></i>`;
    el.onclick = () => {
      document.getElementById('space-icon-input').value = ic;
      previewSpaceIcon(ic);
      grid.querySelectorAll('.ig-item').forEach(e => e.classList.remove('selected'));
      el.classList.add('selected');
    };
    grid.appendChild(el);
  });
}

async function confirmSpaceEdit() {
  const name = document.getElementById('space-name-input').value.trim();
  if (!name) return;
  let icon = document.getElementById('space-icon-input').value.trim() || 'fa-book';
  if (!icon.startsWith('fa-')) icon = 'fa-' + icon;

  if (editingSpaceId) {
    // Edit existing space
    const sp = S.spaces.find(s => s.id === editingSpaceId);
    if (!sp) return;
    sp.name = name;
    sp.icon = icon;
    await save();
    closeModal('space-modal');
    renderSpaces();
    showToast(t('toastSpaceSaved'));
  } else {
    // Create new space
    const sp = { id: uid(), name, icon };
    S.spaces.push(sp);
    S.currentSpaceId = sp.id;
    S.currentPageId = null;
    await save();
    closeModal('space-modal');
    renderSpaces(); renderNav(); renderPage();
    showToast(t('toastSpaceCreated'));
  }
}

function deleteCurrentSpace() {
  closeModal('space-modal');
  deleteSpace(editingSpaceId);
}

async function deleteSpace(id) {
  if (S.spaces.length <= 1) { showToast(t('toastLastSpace')); return; }
  closeModal('space-modal');
  const sp = S.spaces.find(s => s.id === id);
  showConfirm({
    title: t('confirmDeleteSpaceTitle'),
    msg: t('confirmDeleteSpaceMsg', sp?.name || ''),
    icon: 'fa-trash', iconType: 'danger',
    okLabel: t('confirmDeleteOk'), okClass: 'btn-danger',
    onOk: async () => {
      const pageIds = S.pages.filter(p => p.spaceId === id).map(p => p.id);
      for (const pid of pageIds) await deletePageFromServer(pid);
      S.pages = S.pages.filter(p => p.spaceId !== id);
      S.spaces = S.spaces.filter(s => s.id !== id);
      if (S.currentSpaceId === id) {
        S.currentSpaceId = S.spaces[0].id;
        S.currentPageId = S.pages.find(p => p.spaceId === S.currentSpaceId)?.id || null;
      }
      await save();
      renderSpaces(); renderNav(); renderPage();
      showToast(t('toastSpaceDeleted'));
    }
  });
}

function switchSpace(id) {
  if (S.editMode) autoSave(true);
  S.currentSpaceId = id;
  S.editMode = false;
  const pages = spacePages();
  S.currentPageId = pages.find(p => !p.parentId)?.id || pages[0]?.id || null;
  renderSpaces(); renderNav(); renderPage();
  syncEditUI();
}

async function addSpace() {
  editingSpaceId = null; // null = create mode
  document.getElementById('space-modal-title').textContent = t('modalNewSpaceTitle');
  document.getElementById('space-name-input').value = '';
  document.getElementById('space-icon-input').value = 'fa-book';
  document.getElementById('space-icon-preview').innerHTML = '<i class="fa-solid fa-book"></i>';
  document.getElementById('space-delete-btn').style.display = 'none';
  document.getElementById('space-cancel-btn').style.display = '';
  document.getElementById('space-cancel-btn-right').style.display = 'none';
  document.getElementById('space-confirm-btn').innerHTML = `<i class="fa-solid fa-plus"></i> ${t('btnCreate')}`;
  buildSpaceIconGrid();
  openModal('space-modal');
  setTimeout(() => document.getElementById('space-name-input').focus(), 100);
}

function spacePages() {
  return S.pages.filter(p => p.spaceId === S.currentSpaceId);
}

// ════════════════════════════════════════
//  SIDEBAR / NAV
// ════════════════════════════════════════
function renderNav() {
  const tree = document.getElementById('nav-tree');
  tree.innerHTML = '';
  const isAdmin = S.authed;
  const pages = spacePages();
  const rootPages = pages.filter(p => !p.parentId).sort((a,b) => a.order - b.order);

  // Group by section
  const sections = [];
  const seen = new Set();
  rootPages.forEach(p => {
    const sec = p.section || '';
    if (!seen.has(sec)) { seen.add(sec); sections.push(sec); }
  });

  sections.forEach(sec => {
    if (sec) {
      const label = document.createElement('div');
      label.className = 'section-label';
      const addBtn = isAdmin ? `<button class="section-add" onclick="event.stopPropagation();openAddPage(null,'${esc(sec)}')" title="${t('btnAddPageSection')}"><i class="fa-solid fa-plus"></i></button>` : '';
      label.innerHTML = `${esc(sec)}${addBtn}`;
      tree.appendChild(label);
    }
    rootPages.filter(p => (p.section || '') === sec).forEach(p => {
      renderNavItem(p, tree, 0, pages);
    });
  });

  // Show/hide add page button
  const addBtn = document.getElementById('add-page-btn');
  if (addBtn) addBtn.style.display = isAdmin ? '' : 'none';

  // Init drag & drop after DOM is built
  requestAnimationFrame(() => initDragDrop());
}

function renderNavItem(page, container, depth, allPages) {
  const children = allPages.filter(p => p.parentId === page.id).sort((a,b) => a.order - b.order);
  const isActive = S.currentPageId === page.id;
  const childActive = children.some(c => c.id === S.currentPageId || allPages.filter(x => x.parentId === c.id).some(g => g.id === S.currentPageId));
  const isOpen = isActive || childActive;
  const isAdmin = S.authed;

  const wrap = document.createElement('div');

  const item = document.createElement('div');
  item.className = 'nav-item' + (isActive ? ' active' : '');
  item.style.paddingLeft = `${12 + depth * 16}px`;
  item.dataset.pageId = page.id;

  const toggleHtml = children.length
    ? `<div class="nav-toggle${isOpen ? ' open' : ''}" onclick="event.stopPropagation();toggleChildren(this,'${page.id}')"><i class="fa-solid fa-chevron-right"></i></div>`
    : `<div class="nav-toggle-spacer"></div>`;

  const actionsHtml = isAdmin ? `
    <div class="nav-actions">
      <button class="na-btn" onclick="event.stopPropagation();openPageEdit('${page.id}')" title="${t('btnEditPage')}"><i class="fa-solid fa-pen"></i></button>
      <button class="na-btn" onclick="event.stopPropagation();openAddPage('${page.id}')" title="${t('btnSubpage')}"><i class="fa-solid fa-plus"></i></button>
      <button class="na-btn na-btn-danger" onclick="event.stopPropagation();deletePage('${page.id}')" title="${t('btnDeletePage')}"><i class="fa-solid fa-trash"></i></button>
    </div>` : '';

  item.innerHTML = `
    ${toggleHtml}
    <span class="nav-ic"><i class="fa-solid ${page.icon || 'fa-file'}"></i></span>
    <span class="nav-label">${esc(page.title)}</span>
    ${actionsHtml}
  `;
  item.onclick = () => navigateTo(page.id);
  wrap.appendChild(item);

  if (children.length) {
    const sub = document.createElement('div');
    sub.className = 'nav-children' + (isOpen ? ' open' : '');
    sub.id = 'children-' + page.id;
    children.forEach(c => renderNavItem(c, sub, depth + 1, allPages));
    wrap.appendChild(sub);
  }

  container.appendChild(wrap);
}

function toggleChildren(btn, pageId) {
  btn.classList.toggle('open');
  const sub = document.getElementById('children-' + pageId);
  if (sub) sub.classList.toggle('open');
}

// ════════════════════════════════════════
//  NAVIGATION
// ════════════════════════════════════════
async function navigateTo(pageId) {
  closeMobileSidebar();
  clearTimeout(saveTimer);
  clearTimeout(tocTimer);

  // Zruš scroll spy
  if (scrollSpyObserver) { scrollSpyObserver.disconnect(); scrollSpyObserver = null; }

  // Ulož pred zničením editora
  if (S.editMode && S.currentPageId && editor) {
    try { 
      const data = await editor.save();
      const page = S.pages.find(p => p.id === S.currentPageId);
      if (page) { page.content = data; await savePageToServer(page); }
    } catch(e) {}
  }

  // Zruš editor
  if (editor) {
    try { await editor.destroy(); } catch(e) {}
    editor = null;
    const h = document.getElementById('editor');
    if (h) { const c = document.createElement('div'); c.id = 'editor'; h.replaceWith(c); }
  }

  S.currentPageId = pageId;
  S.editMode = false;
  syncEditUI();
  renderNav();
  await loadPageContent(pageId);
  renderPage();
  // Update URL
  const newUrl = `${window.location.pathname}?page=${encodeURIComponent(pageId)}`;
  history.pushState({ pageId }, '', newUrl);
  // Scroll to top
  document.querySelector('.content-wrap')?.scrollTo(0, 0);
  window.scrollTo(0, 0);
}

// ════════════════════════════════════════
//  PAGE RENDER
// ════════════════════════════════════════
function renderPage() {
  const view = document.getElementById('page-view');
  const page = S.pages.find(p => p.id === S.currentPageId);

  if (!page) {
    view.innerHTML = `<div class="empty-state">
      <i class="fa-solid fa-book-open"></i>
      <p>${t('pageSelectPrompt')}</p>
    </div>`;
    updateTOC(); updatePageNavBottom(null);
    return;
  }

  // Breadcrumb — rekurzívne celý strom predkov
  const ancestors = [];
  let cur = page;
  while (cur.parentId) {
    const parent = S.pages.find(p => p.id === cur.parentId);
    if (!parent) break;
    ancestors.unshift(parent);
    cur = parent;
  }

  let breadParts = [];
  // Sekcia z page alebo z najvyššieho predka
  const sectionSource = ancestors.length ? ancestors[0] : page;
  if (sectionSource.section) {
    breadParts.push(`<span style="pointer-events:none;cursor:default;">${esc(sectionSource.section)}</span>`);
    breadParts.push(`<i class="fa-solid fa-chevron-right"></i>`);
  }
  // Všetci predkovia ako klikateľné linky
  ancestors.forEach(a => {
    breadParts.push(`<span onclick="navigateTo('${a.id}')">${esc(a.title)}</span>`);
    breadParts.push(`<i class="fa-solid fa-chevron-right"></i>`);
  });
  // Aktuálna stránka
  breadParts.push(`<span>${esc(page.title)}</span>`);

  view.innerHTML = `
    ${page.cover ? `<div class="page-cover" id="page-cover-el" style="${page.cover.type==='color' ? 'background:'+page.cover.value : ''}">
      ${page.cover.type==='image' ? `<img src="${page.cover.value}" alt="" style="object-fit:${page.cover.fit||'cover'};object-position:${page.cover.position||'50% 50%'}">` : ''}
      ${S.editMode ? `<div class="page-cover-actions">
        <button class="page-cover-btn" onclick="changeCover()"><i class="fa-solid fa-image"></i> <span data-i18n="coverChange">Change</span></button>
        <button class="page-cover-btn" onclick="removeCover()"><i class="fa-solid fa-trash"></i> <span data-i18n="coverRemove">Remove</span></button>
      </div>
      ${page.cover?.type === 'image' ? `<div class="cover-pos-panel">
        <span class="cover-pos-label">${t('coverCenter')}</span>
        <div class="cover-pos-btns">
          <button class="cover-pos-btn ${(page.cover?.position||'center')==='top'?'active':''}" onclick="setCoverPosition('top')">${t('coverTop')}</button>
          <button class="cover-pos-btn ${(page.cover?.position||'center')==='center'?'active':''}" onclick="setCoverPosition('center')">${t('coverCenter')}</button>
          <button class="cover-pos-btn ${(page.cover?.position||'center')==='bottom'?'active':''}" onclick="setCoverPosition('bottom')">${t('coverBottom')}</button>
        </div>
        <span class="cover-pos-label" style="margin-left:4px">${t('coverFitCover')}</span>
        <div class="cover-pos-btns">
          <button class="cover-pos-btn ${(page.cover?.fit||'cover')==='cover'?'active':''}" onclick="setCoverFit('cover')">${t('coverFitCover')}</button>
          <button class="cover-pos-btn ${(page.cover?.fit||'cover')==='contain'?'active':''}" onclick="setCoverFit('contain')">${t('coverFitContain')}</button>
        </div>
      </div>` : ''}` : ''}
    </div>` : ''}
    <div class="breadcrumb">${breadParts.join('')}</div>
    <div class="page-hero">
      <div class="page-icon-wrap">
        <div class="page-icon" id="pg-icon" onclick="openIconPicker()" title="">
          <i class="fa-solid ${page.icon || 'fa-file'}"></i>
        </div>
      </div>
      <div style="flex:1;min-width:0;display:flex;align-items:center;gap:12px">
        <input type="text" class="page-title-input" id="pg-title"
          value="${esc(page.title)}" placeholder="${t('pageUntitled')}"
          ${S.editMode ? '' : 'readonly'}
          oninput="markDirty()">
        <div style="display:flex;align-items:center;gap:8px;margin-left:auto;flex-shrink:0">
          ${S.editMode && !page.cover ? `<button onclick="addCover()" style="font-size:12px;padding:4px 10px;border-radius:6px;border:1px dashed var(--border);background:transparent;color:var(--text3);cursor:pointer;font-family:var(--font);display:flex;align-items:center;gap:5px;transition:all .15s;" onmouseover="this.style.color='var(--text2)';this.style.borderColor='var(--text3)'" onmouseout="this.style.color='var(--text3)';this.style.borderColor='var(--border)'"><i class="fa-solid fa-image"></i> Cover</button>` : ''}
          <div class="reading-time" id="reading-time-el"><i class="fa-regular fa-clock"></i> <span>...</span></div>
        </div>
      </div>
    </div>
    <div class="page-desc" id="pg-desc" contenteditable="${S.editMode}" style="padding-bottom:16px;padding-left:52px">${page.subtitle ? esc(page.subtitle) : ''}</div>
    <div class="page-divider"></div>
    <div id="editor"></div>
    <div id="page-last-updated"></div>
    <div id="page-nav-bottom"></div>
  `;

  updatePageNavBottom(page);
  initEditor(page);

  // Update document title and OG tags
  const siteName = S.settings?.siteName || 'Docs';
  document.title = `${page.title} — ${siteName}`;
  const ogTitle = document.getElementById('og-title');
  const ogDesc = document.getElementById('og-desc');
  const ogSite = document.getElementById('og-site');
  const ogImage = document.getElementById('og-image');
  if (ogTitle) ogTitle.content = page.title;
  if (ogDesc) ogDesc.content = page.subtitle || `${siteName} documentation`;
  if (ogSite) ogSite.content = siteName;
  // OG image: page cover > generated card
  if (ogImage) {
    const coverUrl = page.cover?.type === 'image' ? page.cover.value : '';
    ogImage.content = coverUrl || generateOgImage(page.title, page.subtitle || '', siteName);
  }

  // Reading time — update po načítaní editora
  setTimeout(() => updateReadingTime(), 800);
}

function updateReadingTime() {
  const el = document.getElementById('reading-time-el');
  if (!el) return;
  const text = document.getElementById('editor')?.innerText || '';
  const words = text.trim().split(/\s+/).filter(Boolean).length;
  const mins = Math.max(1, Math.round(words / 200));
  el.innerHTML = `<i class="fa-regular fa-clock"></i> <span>${t('readingTimeLabel', mins, words)}</span>`;
}

// ── Cover ──
function addCover() {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  const colors = ['linear-gradient(135deg,#f97316,#7c3aed)','linear-gradient(135deg,#3b82f6,#06b6d4)',
    'linear-gradient(135deg,#10b981,#3b82f6)','linear-gradient(135deg,#ec4899,#f97316)',
    'linear-gradient(135deg,#6366f1,#ec4899)','linear-gradient(135deg,#eab308,#ef4444)'];
  const pick = colors[Math.floor(Math.random() * colors.length)];
  page.cover = { type: 'color', value: pick };
  savePageToServer(page);
  renderPage();
  syncEditUI();
}

function changeCover() {
  const input = document.createElement('input');
  input.type = 'file'; input.accept = 'image/*';
  input.onchange = async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('image', file);
    try {
      const r = await fetch('api.php?action=upload_image', { method:'POST', body:fd, credentials:'same-origin' });
      const d = await r.json();
      if (d.url) {
        const page = S.pages.find(p => p.id === S.currentPageId);
        if (page) { page.cover = { type:'image', value:d.url }; savePageToServer(page); renderPage(); }
      }
    } catch(e) { showToast(t('toastUploadError')); }
  };
  input.click();
}

function removeCover() {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (page) { page.cover = null; savePageToServer(page); renderPage(); }
}

function setCoverFit(fit) {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page?.cover) return;
  page.cover.fit = fit;
  savePageToServer(page);
  const img = document.querySelector('#page-cover-el img');
  if (img) img.style.objectFit = fit;
  document.querySelectorAll('.cover-pos-btn').forEach(b => {
    const label = b.textContent.trim();
    if (label === 'Vyplniť' || label === 'Celý') {
      b.classList.toggle('active', (label === 'Vyplniť' ? 'cover' : 'contain') === fit);
    }
  });
}

function initCoverDrag(coverEl, page) {
  const img = coverEl.querySelector('img');
  if (!img) return;

  let dragging = false;
  let startY, startX;
  // Parse current position (e.g. "50% 30%")
  let posX = 50, posY = 50;
  const cur = (page.cover.position || '50% 50%');
  const parts = cur.split(' ');
  if (parts.length === 2) {
    posX = parseFloat(parts[0]) || 50;
    posY = parseFloat(parts[1]) || 50;
  } else if (cur === 'top') { posY = 0; posX = 50; }
  else if (cur === 'bottom') { posY = 100; posX = 50; }
  else if (cur === 'center') { posX = 50; posY = 50; }

  img.style.objectPosition = `${posX}% ${posY}%`;
  img.style.cursor = 'grab';

  img.addEventListener('mousedown', (e) => {
    if (!S.editMode) return;
    dragging = true;
    startX = e.clientX;
    startY = e.clientY;
    img.style.cursor = 'grabbing';
    e.preventDefault();
  });

  document.addEventListener('mousemove', (e) => {
    if (!dragging) return;
    const rect = coverEl.getBoundingClientRect();
    const imgNaturalRatio = img.naturalWidth / img.naturalHeight;
    const coverRatio = rect.width / rect.height;

    // Sensitivity based on overflow
    const dx = (e.clientX - startX) / rect.width * 100;
    const dy = (e.clientY - startY) / rect.height * 100;

    posX = Math.min(100, Math.max(0, posX - dx * 0.5));
    posY = Math.min(100, Math.max(0, posY - dy * 0.5));

    startX = e.clientX;
    startY = e.clientY;

    img.style.objectPosition = `${posX}% ${posY}%`;
  });

  document.addEventListener('mouseup', () => {
    if (!dragging) return;
    dragging = false;
    img.style.cursor = 'grab';
    // Ulož
    page.cover.position = `${Math.round(posX)}% ${Math.round(posY)}%`;
    savePageToServer(page);
  });
}

// ── Templates ──
function buildTemplateGrid() {
  const grid = document.getElementById('template-grid');
  if (!grid) return;
  grid.innerHTML = '';
  getPageTemplates().forEach(t => {
    const card = document.createElement('div');
    const active = selectedTemplate === t.id;
    card.style.cssText = `padding:10px 12px;border-radius:8px;border:2px solid ${active ? 'var(--accent)' : 'var(--border)'};
      background:${active ? 'rgba(var(--accent-rgb),.07)' : 'var(--bg2)'};cursor:pointer;transition:all .15s;`;
    card.innerHTML = `<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
      <i class="fa-solid ${t.icon}" style="color:${active ? 'var(--accent)' : 'var(--text3)'}"></i>
      <span style="font-weight:600;font-size:13px;color:var(--text)">${t.label}</span>
    </div>
    <div style="font-size:11px;color:var(--text3)">${t.desc}</div>`;
    card.onclick = () => {
      selectedTemplate = t.id;
      // auto-fill title if still default
      const titleInput = document.getElementById('new-title');
      if (!titleInput.value || getPageTemplates().find(x => x.label === titleInput.value)) {
        titleInput.value = t.id !== 'blank' ? t.label : '';
      }
      // auto icon
      document.getElementById('new-icon').value = t.icon;
      previewIcon(t.icon);
      buildTemplateGrid();
    };
    grid.appendChild(card);
  });
}

function updatePageNavBottom(page) {
  const lu = document.getElementById('page-last-updated');
  if (lu) {
    lu.innerHTML = page?.updatedAt
      ? `<div class="page-last-updated"><i class="fa-solid fa-clock"></i> ${t('lastUpdated')}: ${formatDate(page.updatedAt)}</div>`
      : '';
  }

  const el = document.getElementById('page-nav-bottom');
  if (!el || !page) return;

  // Build flat ordered list via DFS — same order as sidebar
  function flatDFS(parentId) {
    return S.pages
      .filter(p => p.spaceId === S.currentSpaceId && p.parentId === (parentId || null))
      .sort((a, b) => a.order - b.order)
      .flatMap(p => [p, ...flatDFS(p.id)]);
  }
  const ordered = flatDFS(null);
  const idx = ordered.findIndex(p => p.id === page.id);
  const prev = idx > 0 ? ordered[idx - 1] : null;
  const next = idx < ordered.length - 1 ? ordered[idx + 1] : null;

  el.className = 'page-nav';
  el.innerHTML = `
    ${prev ? `<div class="page-nav-card" onclick="navigateTo('${prev.id}')">
      <div class="page-nav-dir"><i class="fa-solid fa-arrow-left"></i> ${t('navPrev')}</div>
      <div class="page-nav-title"><i class="fa-solid ${prev.icon || 'fa-file'}"></i>${esc(prev.title)}</div>
    </div>` : '<div></div>'}
    ${next ? `<div class="page-nav-card right" onclick="navigateTo('${next.id}')">
      <div class="page-nav-dir">${t('navNext')} <i class="fa-solid fa-arrow-right"></i></div>
      <div class="page-nav-title"><i class="fa-solid ${next.icon || 'fa-file'}"></i>${esc(next.title)}</div>
    </div>` : '<div></div>'}
  `;
}

// ════════════════════════════════════════
//  ICON PICKER (for page icon)
// ════════════════════════════════════════
let iconPickerOpen = false;
let pickerDiv = null;

function openIconPicker() {
  if (!S.editMode) return;
  if (iconPickerOpen) { closeIconPickerEl(); return; }
  iconPickerOpen = true;

  pickerDiv = document.createElement('div');
  pickerDiv.style.cssText = `
    position:fixed;z-index:300;background:var(--bg2);
    border:1px solid var(--border2);border-radius:10px;
    padding:12px;box-shadow:0 10px 32px var(--shadow);
    width:280px;
  `;

  const inp = document.createElement('input');
  inp.className = 'field-input icon-search-modal';
  inp.placeholder = 'Hľadaj ikonu... (napr. file)';
  inp.style.cssText = 'width:100%;margin-bottom:10px';

  const grid = document.createElement('div');
  grid.className = 'icon-grid';

  function fillGrid(filter) {
    grid.innerHTML = '';
    ICON_LIST.filter(ic => !filter || ic.includes(filter)).forEach(ic => {
      const el = document.createElement('div');
      el.className = 'ig-item';
      const page = S.pages.find(p => p.id === S.currentPageId);
      if (page && page.icon === ic) el.classList.add('selected');
      el.innerHTML = `<i class="fa-solid ${ic}" title="${ic}"></i>`;
      el.onclick = () => setPageIcon(ic);
      grid.appendChild(el);
    });
  }

  inp.oninput = () => fillGrid(inp.value.replace('fa-',''));
  fillGrid('');

  pickerDiv.appendChild(inp);
  pickerDiv.appendChild(grid);
  document.body.appendChild(pickerDiv);

  const iconEl = document.getElementById('pg-icon');
  if (iconEl) {
    const rect = iconEl.getBoundingClientRect();
    pickerDiv.style.top = (rect.bottom + 6) + 'px';
    pickerDiv.style.left = rect.left + 'px';
  }

  setTimeout(() => {
    document.addEventListener('click', outsidePickerClick);
  }, 10);
}

function outsidePickerClick(e) {
  if (pickerDiv && !pickerDiv.contains(e.target)) {
    closeIconPickerEl();
  }
}

function closeIconPickerEl() {
  if (pickerDiv) { pickerDiv.remove(); pickerDiv = null; }
  iconPickerOpen = false;
  document.removeEventListener('click', outsidePickerClick);
}

function setPageIcon(icon) {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (page) { page.icon = icon; savePageToServer(page); }
  const iconEl = document.getElementById('pg-icon');
  if (iconEl) iconEl.innerHTML = `<i class="fa-solid ${icon}"></i>`;
  renderNav();
  closeIconPickerEl();
  markDirty();
}

// ════════════════════════════════════════
//  CUSTOM IMAGE TOOL — uploads to images/ via api.php
// ════════════════════════════════════════
class LocalImageTool {
  static get toolbox() {
    return { title: t('blockPickerImage'), icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' };
  }
  static get isReadOnlySupported() { return true; }

  constructor({ data, config, api, readOnly }) {
    this.api = api;
    this.readOnly = readOnly;
    this.data = {
      url: data.url || '',
      caption: data.caption || '',
      stretched: data.stretched || false,
      withBorder: data.withBorder || false,
      withBackground: data.withBackground || false,
    };
    this._wrapper = null;
  }

  render() {
    this._wrapper = document.createElement('div');
    this._wrapper.classList.add('local-image-tool');
    if (this.data.url) {
      this._renderImage();
    } else if (!this.readOnly) {
      this._renderUploader();
    }
    return this._wrapper;
  }

  _renderUploader() {
    this._wrapper.innerHTML = '';
    const zone = document.createElement('div');
    zone.className = 'lit-drop-zone';
    zone.innerHTML = `
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <div class="lit-drop-label">${t('imageDropLabel')}</div>
      <div class="lit-drop-sub">${t('imageDropSub')}</div>
      <div class="lit-url-row">
        <input class="lit-url-input" type="url" placeholder="${t('imageUrlPlaceholder')}">
        <button class="lit-url-btn">${t('imageUrlBtn')}</button>
      </div>
    `;
    this._wrapper.appendChild(zone);

    const input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.style.display = 'none';
    this._wrapper.appendChild(input);

    zone.querySelector('.lit-pick-btn').onclick = () => input.click();
    input.onchange = () => { if (input.files[0]) this._loadFile(input.files[0]); };

    zone.ondragover = (e) => { e.preventDefault(); zone.classList.add('drag-over'); };
    zone.ondragleave = () => zone.classList.remove('drag-over');
    zone.ondrop = (e) => {
      e.preventDefault(); zone.classList.remove('drag-over');
      const file = e.dataTransfer.files[0];
      if (file && file.type.startsWith('image/')) this._loadFile(file);
    };

    const urlInput = zone.querySelector('.lit-url-input');
    const urlBtn = zone.querySelector('.lit-url-btn');
    urlBtn.onclick = () => { if (urlInput.value.trim()) { this.data.url = urlInput.value.trim(); this._renderImage(); } };
    urlInput.onkeydown = (e) => { if (e.key === 'Enter') urlBtn.click(); };
  }

  async _loadFile(file) {
    // Show loading state
    this._wrapper.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text3)"><i class="fa-solid fa-spinner fa-spin"></i> Nahrávam...</div>';
    try {
      const fd = new FormData();
      fd.append('image', file);
      const r = await fetch('api.php?action=upload_image', {
        method: 'POST', credentials: 'same-origin', body: fd
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error);
      this.data.url = d.url;
      this.data.filename = d.filename;
      this._renderImage();
    } catch(e) {
      this._wrapper.innerHTML = `<div style="padding:16px;text-align:center;color:#ef4444"><i class="fa-solid fa-circle-exclamation"></i> Upload zlyhal: ${e.message}</div>`;
      setTimeout(() => this._renderUploader(), 2000);
    }
  }

  _renderImage() {
    this._wrapper.innerHTML = '';
    this._wrapper.className = 'local-image-tool' +
      (this.data.stretched ? ' lit-stretched' : '') +
      (this.data.withBorder ? ' lit-border' : '') +
      (this.data.withBackground ? ' lit-bg' : '');

    const img = document.createElement('img');
    img.src = this.data.url;
    img.className = 'lit-img';
    img.alt = this.data.caption;
    this._wrapper.appendChild(img);

    const cap = document.createElement('div');
    cap.className = 'lit-caption';
    cap.contentEditable = this.readOnly ? 'false' : 'true';
    cap.dataset.placeholder = 'Popis obrázka...';
    cap.textContent = this.data.caption;
    cap.oninput = () => { this.data.caption = cap.textContent; img.alt = cap.textContent; };
    this._wrapper.appendChild(cap);

    if (!this.readOnly) {
      const del = document.createElement('button');
      del.className = 'lit-del-btn';
      del.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
      del.title = 'Odstrániť obrázok';
      del.onclick = () => { this.data.url = ''; this.data.caption = ''; this._renderUploader(); };
      this._wrapper.appendChild(del);
    }
  }

  renderSettings() {
    return [
      { label: 'Orámovanie', icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>', isActive: this.data.withBorder, closeOnActivate: true, onActivate: () => { this.data.withBorder = !this.data.withBorder; if (this.data.url) this._renderImage(); } },
      { label: 'Pozadie', icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>', isActive: this.data.withBackground, closeOnActivate: true, onActivate: () => { this.data.withBackground = !this.data.withBackground; if (this.data.url) this._renderImage(); } },
      { label: 'Na celú šírku', icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>', isActive: this.data.stretched, closeOnActivate: true, onActivate: () => { this.data.stretched = !this.data.stretched; if (this.data.url) this._renderImage(); } },
    ];
  }

  save() { return { ...this.data }; }

  static get sanitize() {
    return { url: false, caption: { b: true, i: true } };
  }
}

// ════════════════════════════════════════
//  CALLOUT TOOL — 4 typy: info / tip / warning / danger
// ════════════════════════════════════════
class CalloutTool {
  static get toolbox() {
    return {
      title: 'Callout',
      icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
    };
  }
  static get isReadOnlySupported() { return true; }
  static get enableLineBreaks() { return true; }

  static get TYPES() {
    return {
      info:    { icon: 'fa-circle-info',          color: 'var(--accent)',  bg: 'rgba(var(--accent-rgb),0.08)', label: 'Info' },
      tip:     { icon: 'fa-lightbulb',            color: '#16a34a',        bg: 'rgba(22,163,74,0.08)',         label: 'Tip' },
      warning: { icon: 'fa-triangle-exclamation', color: '#ca8a04',        bg: 'rgba(202,138,4,0.08)',         label: 'Warning' },
      danger:  { icon: 'fa-circle-exclamation',   color: '#dc2626',        bg: 'rgba(220,38,38,0.08)',         label: 'Danger' },
    };
  }

  constructor({ data, readOnly }) {
    this.readOnly = readOnly;
    this.data = {
      type:    data.type    || 'info',
      title:   data.title   || '',
      message: data.message || '',
    };
  }

  static get sanitize() {
    return {
      title:   { br: true, b: true, i: true, u: true, a: { href: true, target: true }, code: true, mark: true },
      message: { br: true, b: true, i: true, u: true, a: { href: true, target: true }, code: true, mark: true },
    };
  }

  render() {
    const cfg = CalloutTool.TYPES[this.data.type] || CalloutTool.TYPES.info;
    this._wrap = document.createElement('div');
    this._wrap.style.cssText = 'width:100%;box-sizing:border-box;';

    this._el = document.createElement('div');
    this._el.className = 'callout-block';
    this._el.style.cssText = `border-left:3px solid ${cfg.color};background:${cfg.bg};border-radius:6px;padding:12px 16px;width:100%;box-sizing:border-box;`;

    if (!this.readOnly) {
      const body = document.createElement('div');
      body.style.cssText = 'display:flex;gap:10px;align-items:flex-start;width:100%;min-width:0;overflow:hidden;';

      const icon = document.createElement('i');
      icon.className = `fa-solid ${cfg.icon}`;
      icon.style.cssText = `color:${cfg.color};margin-top:3px;flex-shrink:0;font-size:16px;font-style:normal;`;
      this._iconEl = icon;

      const fields = document.createElement('div');
      fields.style.cssText = 'flex:1;min-width:0;';

      // Title — contenteditable
      this._titleEl = document.createElement('div');
      this._titleEl.contentEditable = 'true';
      this._titleEl.innerHTML = this.data.title || '';
      this._titleEl.dataset.placeholder = t('calloutTitlePlaceholder');
      this._titleEl.style.cssText = 'font-weight:600;font-size:14px;background:none;border:none;outline:none;width:100%;color:var(--text);margin-bottom:4px;font-family:var(--font);line-height:1.4;min-height:1.4em;word-break:break-word;';
      this._titleEl.addEventListener('input', () => { this.data.title = this._titleEl.innerHTML; });
      this._titleEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); this._msgEl.focus(); } });

      // Message — contenteditable
      this._msgEl = document.createElement('div');
      this._msgEl.contentEditable = 'true';
      this._msgEl.innerHTML = this.data.message || '';
      this._msgEl.dataset.placeholder = t('calloutMsgPlaceholder');
      this._msgEl.style.cssText = 'font-size:14px;background:none;border:none;outline:none;width:100%;color:var(--text2);font-family:var(--font);line-height:1.5;min-height:1.5em;word-break:break-word;';
      this._msgEl.addEventListener('input', () => { this.data.message = this._msgEl.innerHTML; });

      fields.appendChild(this._titleEl);
      fields.appendChild(this._msgEl);
      body.appendChild(icon);
      body.appendChild(fields);
      this._el.appendChild(body);
    } else {
      this._el.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:10px;">
          <i class="fa-solid ${cfg.icon}" style="color:${cfg.color};margin-top:2px;flex-shrink:0;font-size:16px;font-style:normal;"></i>
          <div style="flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word;">
            ${this.data.title ? `<div style="font-weight:600;font-size:14px;color:var(--text);margin-bottom:2px;overflow-wrap:break-word;word-break:break-word;">${this.data.title}</div>` : ''}
            ${this.data.message ? `<div style="font-size:14px;color:var(--text2);line-height:1.5;overflow-wrap:break-word;word-break:break-word;">${this.data.message}</div>` : ''}
          </div>
        </div>
      `;
    }

    this._wrap.appendChild(this._el);
    return this._wrap;
  }

  save() {
    return {
      type:    this.data.type,
      title:   this._titleEl ? this._titleEl.innerHTML : this.data.title,
      message: this._msgEl   ? this._msgEl.innerHTML   : this.data.message,
    };
  }
}

// ════════════════════════════════════════
//  TIMELINE TOOL
// ════════════════════════════════════════
class TimelineTool {
  static get toolbox() {
    return {
      title: 'Timeline',
      icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="22"/><circle cx="12" cy="6" r="2" fill="currentColor"/><circle cx="12" cy="12" r="2" fill="currentColor"/><circle cx="12" cy="18" r="2" fill="currentColor"/><line x1="12" y1="6" x2="18" y2="6"/><line x1="12" y1="12" x2="18" y2="12"/><line x1="12" y1="18" x2="18" y2="18"/></svg>'
    };
  }
  static get isReadOnlySupported() { return true; }

  constructor({ data, readOnly }) {
    this.readOnly = readOnly;
    this.data = {
      numbered: data.numbered !== undefined ? data.numbered : false,
      items: data.items && data.items.length ? data.items : [
        { date: '', title: '', desc: '' }
      ]
    };
  }

  render() {
    this._el = document.createElement('div');
    this._el.className = 'tl-wrap';
    this._renderAll();
    return this._el;
  }

  _addItem(atIndex) {
    this.data.items.splice(atIndex, 0, { date: '', title: '', desc: '' });
    this._renderAll();
  }

  _makeAddBtn(atIndex) {
    const btn = document.createElement('button');
    btn.className = 'tl-add-btn';
    btn.innerHTML = `<i class="fa-solid fa-plus" style="font-style:normal"></i> ${t('timelineAddBtn')}`;
    btn.onmousedown = (e) => { e.preventDefault(); this._addItem(atIndex); };
    return btn;
  }

  _renderAll() {
    this._el.innerHTML = '';

    if (!this.readOnly) {
      // numbered toggle
      const toolbar = document.createElement('div');
      toolbar.className = 'tl-toolbar';
      const toggle = document.createElement('label');
      const chk = document.createElement('input');
      chk.type = 'checkbox';
      chk.checked = this.data.numbered;
      chk.addEventListener('change', e => { this.data.numbered = e.target.checked; this._renderAll(); });
      toggle.appendChild(chk);
      toggle.appendChild(document.createTextNode(' ' + t('timelineNumbered')));
      toolbar.appendChild(toggle);
      this._el.appendChild(toolbar);

      // add button at top
      this._el.appendChild(this._makeAddBtn(0));
    }

    this.data.items.forEach((item, i) => {
      this._el.appendChild(this._makeItem(item, i));
      if (!this.readOnly) {
        this._el.appendChild(this._makeAddBtn(i + 1));
      }
    });
  }

  _makeItem(item, i) {
    const row = document.createElement('div');
    row.className = 'tl-item';

    // Left column: line + dot + line
    const left = document.createElement('div');
    left.className = 'tl-left';
    left.style.width = this.data.numbered ? '48px' : '40px';

    const lineTop = document.createElement('div');
    lineTop.className = 'tl-line tl-line-top';

    const dot = document.createElement('div');
    dot.className = this.data.numbered ? 'tl-dot tl-dot-num' : 'tl-dot';
    if (this.data.numbered) {
      dot.textContent = i + 1;
    }

    const lineBot = document.createElement('div');
    lineBot.className = 'tl-line';

    left.appendChild(lineTop);
    left.appendChild(dot);
    left.appendChild(lineBot);

    // Right column
    const right = document.createElement('div');
    right.className = 'tl-content';

    if (!this.readOnly) {
      const topRow = document.createElement('div');
      topRow.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;';

      const dateIn = document.createElement('input');
      dateIn.value = item.date || '';
      dateIn.placeholder = t('timelineDatePlaceholder');
      dateIn.style.cssText = 'flex:1;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:0.04em;background:none;border:none;border-bottom:1px solid var(--border);outline:none;color:var(--text3);font-family:var(--font);padding:0 0 2px;';
      dateIn.addEventListener('input', e => item.date = e.target.value);

      const delBtn = document.createElement('button');
      delBtn.innerHTML = '<i class="fa-solid fa-trash" style="font-style:normal"></i>';
      delBtn.style.cssText = 'background:none;border:none;cursor:pointer;color:var(--text4);font-size:11px;padding:0 0 0 8px;flex-shrink:0;';
      delBtn.onmousedown = (e) => {
        e.preventDefault();
        if (this.data.items.length > 1) { this.data.items.splice(i, 1); this._renderAll(); }
      };

      topRow.appendChild(dateIn);
      topRow.appendChild(delBtn);

      const titleIn = document.createElement('input');
      titleIn.value = item.title || '';
      titleIn.placeholder = t('timelineTitlePlaceholder');
      titleIn.style.cssText = 'font-weight:700;font-size:17px;background:none;border:none;outline:none;width:100%;color:var(--text);font-family:var(--font);display:block;margin-bottom:6px;line-height:1.3;';
      titleIn.addEventListener('input', e => item.title = e.target.value);

      const descIn = document.createElement('textarea');
      descIn.value = item.desc || '';
      descIn.placeholder = t('timelineDescPlaceholder');
      descIn.rows = Math.max(2, (item.desc || '').split('\n').length);
      descIn.style.cssText = 'font-size:14px;background:none;border:none;outline:none;width:100%;color:var(--text2);resize:none;font-family:var(--font);line-height:1.6;overflow:hidden;';
      descIn.addEventListener('input', e => {
        item.desc = e.target.value;
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
      });
      // auto-height on render
      setTimeout(() => {
        descIn.style.height = 'auto';
        descIn.style.height = descIn.scrollHeight + 'px';
      }, 0);

      right.appendChild(topRow);
      right.appendChild(titleIn);
      right.appendChild(descIn);
    } else {
      if (item.date) {
        const dateEl = document.createElement('div');
        dateEl.className = 'tl-date';
        dateEl.textContent = item.date;
        right.appendChild(dateEl);
      }
      if (item.title) {
        const titleEl = document.createElement('div');
        titleEl.className = 'tl-title';
        titleEl.textContent = item.title;
        right.appendChild(titleEl);
      }
      if (item.desc) {
        const descEl = document.createElement('div');
        descEl.className = 'tl-desc';
        // preserve line breaks
        descEl.innerHTML = item.desc.replace(/\n/g, '<br>');
        right.appendChild(descEl);
      }
    }

    row.appendChild(left);
    row.appendChild(right);
    return row;
  }

  save() {
    return { numbered: this.data.numbered, items: this.data.items };
  }
}

// ════════════════════════════════════════
//  COLLAPSIBLE TOOL
// ════════════════════════════════════════
class CollapsibleTool {
  static get toolbox() {
    return {
      title: 'Collapsible',
      icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/><line x1="3" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="21" y2="12"/></svg>'
    };
  }
  static get isReadOnlySupported() { return true; }

  constructor({ data, readOnly }) {
    this.readOnly = readOnly;
    this.data = { title: data.title || '', body: data.body || '', open: data.open || false };
  }

  render() {
    this._el = document.createElement('div');
    this._el.className = 'collapsible-block' + (this.data.open ? ' open' : '');

    const header = document.createElement('div');
    header.className = 'collapsible-header';

    const chevron = document.createElement('i');
    chevron.className = 'fa-solid fa-chevron-right collapsible-chevron';
    chevron.style.fontStyle = 'normal';

    if (!this.readOnly) {
      const titleInput = document.createElement('input');
      titleInput.className = 'collapsible-title-text';
      titleInput.value = this.data.title;
      titleInput.placeholder = t('collapsiblePlaceholder');
      titleInput.addEventListener('input', e => this.data.title = e.target.value);
      titleInput.addEventListener('click', e => e.stopPropagation());

      header.appendChild(chevron);
      header.appendChild(titleInput);
    } else {
      const titleSpan = document.createElement('span');
      titleSpan.className = 'collapsible-title-text';
      titleSpan.textContent = this.data.title || 'Sekcia';
      header.appendChild(chevron);
      header.appendChild(titleSpan);
    }

    header.addEventListener('click', (e) => {
      if (e.target.tagName === 'INPUT') return;
      this._el.classList.toggle('open');
      this.data.open = this._el.classList.contains('open');
    });

    const body = document.createElement('div');
    body.className = 'collapsible-body';

    if (!this.readOnly) {
      const ta = document.createElement('textarea');
      ta.value = this.data.body;
      ta.placeholder = 'Obsah sekcie...';
      ta.rows = 3;
      ta.addEventListener('input', e => {
        this.data.body = e.target.value;
        e.target.style.height = 'auto';
        e.target.style.height = e.target.scrollHeight + 'px';
      });
      setTimeout(() => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }, 0);
      body.appendChild(ta);
    } else {
      body.innerHTML = this.data.body.replace(/\n/g, '<br>');
    }

    this._el.appendChild(header);
    this._el.appendChild(body);
    return this._el;
  }

  save() { return { title: this.data.title, body: this.data.body, open: this.data.open }; }
}

// ════════════════════════════════════════
//  VIDEO TOOL
// ════════════════════════════════════════
class VideoTool {
  static get toolbox() {
    return { title: 'Video', icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>' };
  }
  static get isReadOnlySupported() { return true; }
  constructor({ data, readOnly }) {
    this.readOnly = readOnly;
    this.data = { url: data.url || '', embedUrl: data.embedUrl || '' };
  }
  _toEmbed(url) {
    // YouTube
    let m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/);
    if (m) return `https://www.youtube.com/embed/${m[1]}`;
    // Vimeo
    m = url.match(/vimeo\.com\/(\d+)/);
    if (m) return `https://player.vimeo.com/video/${m[1]}`;
    // Already embed or iframe src
    if (url.includes('embed') || url.includes('player')) return url;
    return null;
  }
  render() {
    this._el = document.createElement('div');
    this._el.className = 'video-block';
    if (this.data.embedUrl) {
      this._renderVideo();
    } else if (!this.readOnly) {
      this._renderInput();
    }
    return this._el;
  }
  _renderVideo() {
    this._el.innerHTML = `<iframe src="${this.data.embedUrl}" allowfullscreen allow="autoplay; encrypted-media"></iframe>`;
    if (!this.readOnly) {
      const bar = document.createElement('div');
      bar.style.cssText = 'display:flex;justify-content:flex-end;padding:6px 8px;';
      bar.innerHTML = `<button style="font-size:11px;padding:3px 10px;background:none;border:1px solid var(--border);border-radius:4px;color:var(--text3);cursor:pointer;font-family:var(--font)">${t('videoInsertBtn')}</button>`;
      bar.querySelector('button').onclick = () => { this.data.embedUrl=''; this.data.url=''; this._el.innerHTML=''; this._renderInput(); };
      this._el.appendChild(bar);
    }
  }
  _renderInput() {
    const zone = document.createElement('div');
    zone.className = 'video-upload-zone';
    zone.innerHTML = `<i class="fa-solid fa-video" style="font-style:normal"></i><div style="font-weight:500;font-size:14px;color:var(--text2)">${t('videoInsertLabel')}</div><div style="font-size:12px">${t('videoInsertDesc')}</div>`;
    const row = document.createElement('div');
    row.className = 'video-url-row';
    const input = document.createElement('input');
    input.className = 'video-url-input'; input.placeholder = 'https://youtube.com/watch?v=...';
    input.type = 'url';
    const btn = document.createElement('button');
    btn.className = 'video-url-btn'; btn.textContent = t('videoInsertBtn');
    btn.onmousedown = (e) => {
      e.preventDefault();
      const embed = this._toEmbed(input.value.trim());
      if (embed) { this.data.url = input.value.trim(); this.data.embedUrl = embed; this._el.innerHTML = ''; this._renderVideo(); }
      else { input.style.borderColor = '#ef4444'; }
    };
    input.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); btn.onmousedown(e); } };
    row.appendChild(input); row.appendChild(btn);
    zone.appendChild(row);
    this._el.appendChild(zone);
  }
  save() { return { url: this.data.url, embedUrl: this.data.embedUrl }; }
}

// ════════════════════════════════════════
//  CARDS TOOL
// ════════════════════════════════════════
class CardsTool {
  static get toolbox() {
    return { title: t('blockPickerCards'), icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="9" height="9" rx="2"/><rect x="13" y="3" width="9" height="9" rx="2"/><rect x="2" y="14" width="9" height="9" rx="2"/><rect x="13" y="14" width="9" height="9" rx="2"/></svg>' };
  }
  static get isReadOnlySupported() { return true; }
  constructor({ data, readOnly }) {
    this.readOnly = readOnly;
    this.data = {
      cols: data.cols || 3,
      cards: data.cards || [
        { icon: 'fa-rocket', title: t('cardsDefaultTitle1'), desc: t('cardsDefaultDesc1') },
        { icon: 'fa-book', title: t('cardsDefaultTitle2'), desc: t('cardsDefaultDesc2') },
        { icon: 'fa-code', title: t('cardsDefaultTitle3'), desc: t('cardsDefaultDesc3') },
      ]
    };
  }
  render() {
    this._el = document.createElement('div');
    this._renderAll();
    return this._el;
  }
  _renderAll() {
    this._el.innerHTML = '';
    if (!this.readOnly) {
      const toolbar = document.createElement('div');
      toolbar.className = 'cards-toolbar';
      toolbar.innerHTML = `<label>${t('cardsColumns')}</label>`;
      [2,3].forEach(n => {
        const btn = document.createElement('button');
        btn.className = 'cards-col-btn' + (this.data.cols === n ? ' active' : '');
        btn.textContent = n;
        btn.onmousedown = (e) => { e.preventDefault(); this.data.cols = n; this._renderAll(); };
        toolbar.appendChild(btn);
      });
      this._el.appendChild(toolbar);
    }
    const grid = document.createElement('div');
    grid.className = `cards-grid cols-${this.data.cols}`;
    this.data.cards.forEach((card, i) => {
      if (!this.readOnly) {
        const cel = document.createElement('div');
        cel.className = 'card-edit';
        const iconRow = document.createElement('div');
        iconRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:4px;';

        // Icon picker button
        const iconBtn = document.createElement('button');
        iconBtn.style.cssText = 'width:36px;height:36px;border-radius:8px;background:var(--bg3);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;color:var(--accent);flex-shrink:0;transition:all .15s;';
        iconBtn.innerHTML = `<i class="fa-solid ${card.icon || 'fa-star'}" style="font-style:normal"></i>`;
        iconBtn.title = card.icon || 'fa-star';
        iconBtn.onmousedown = (e) => {
          e.preventDefault();
          // Toggle icon grid
          const existing = cel.querySelector('.card-icon-grid');
          if (existing) { existing.remove(); return; }
          // Close other open grids
          document.querySelectorAll('.card-icon-grid').forEach(g => g.remove());
          const gridEl = document.createElement('div');
          gridEl.className = 'card-icon-grid icon-grid';
          gridEl.style.cssText = 'margin:8px 0 4px;';
          ICON_LIST.slice(0, 32).forEach(ic => {
            const item = document.createElement('div');
            item.className = 'ig-item' + (ic === card.icon ? ' selected' : '');
            item.innerHTML = `<i class="fa-solid ${ic}" style="font-style:normal"></i>`;
            item.title = ic;
            item.onmousedown = (ev) => {
              ev.preventDefault();
              card.icon = ic;
              iconBtn.innerHTML = `<i class="fa-solid ${ic}" style="font-style:normal"></i>`;
              iconBtn.title = ic;
              gridEl.querySelectorAll('.ig-item').forEach(x => x.classList.remove('selected'));
              item.classList.add('selected');
              gridEl.remove();
            };
            gridEl.appendChild(item);
          });
          cel.insertBefore(gridEl, titleIn);
        };

        const delBtn = document.createElement('button');
        delBtn.innerHTML = '<i class="fa-solid fa-trash" style="font-style:normal"></i>';
        delBtn.style.cssText = 'margin-left:auto;background:none;border:none;color:var(--text4);cursor:pointer;font-size:11px;';
        delBtn.onmousedown = (e) => { e.preventDefault(); this.data.cards.splice(i,1); this._renderAll(); };
        iconRow.appendChild(iconBtn); iconRow.appendChild(delBtn);

        const titleIn = document.createElement('input');
        titleIn.value = card.title; titleIn.placeholder = t('cardsTitlePlaceholder');
        titleIn.addEventListener('input', e => card.title = e.target.value);
        const descIn = document.createElement('textarea');
        descIn.value = card.desc; descIn.placeholder = t('cardsDescPlaceholder');
        descIn.rows = 2;
        descIn.addEventListener('input', e => card.desc = e.target.value);

        // Link select (internal pages + external URL)
        const linkRow = document.createElement('div');
        linkRow.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap;';
        const linkIcon = document.createElement('i');
        linkIcon.className = 'fa-solid fa-link';
        linkIcon.style.cssText = 'font-size:11px;color:var(--text4);font-style:normal;flex-shrink:0;';

        const isExternal = card.link && card.link.startsWith('http');
        const linkSelect = document.createElement('select');
        linkSelect.style.cssText = 'flex:1;min-width:0;font-size:11px;font-family:var(--font);background:var(--bg3);color:var(--text2);border:1px solid var(--border);border-radius:4px;padding:3px 6px;outline:none;cursor:pointer;';
        const noneOpt = document.createElement('option');
        noneOpt.value = ''; noneOpt.textContent = t('cardsLinkNone');
        linkSelect.appendChild(noneOpt);
        S.pages.filter(p => p.spaceId === S.currentSpaceId).forEach(p => {
          const opt = document.createElement('option');
          opt.value = p.id; opt.textContent = p.title || p.id;
          if (!isExternal && card.link === p.id) opt.selected = true;
          linkSelect.appendChild(opt);
        });
        const extOpt = document.createElement('option');
        extOpt.value = '__external__'; extOpt.textContent = t('cardsLinkExternal');
        if (isExternal) extOpt.selected = true;
        linkSelect.appendChild(extOpt);

        const urlInput = document.createElement('input');
        urlInput.type = 'url';
        urlInput.placeholder = 'https://...';
        urlInput.value = isExternal ? card.link : '';
        urlInput.style.cssText = 'width:100%;font-size:11px;font-family:var(--font);background:var(--bg3);color:var(--text2);border:1px solid var(--border);border-radius:4px;padding:3px 6px;outline:none;margin-top:4px;display:' + (isExternal ? 'block' : 'none') + ';';
        urlInput.addEventListener('input', e => card.link = e.target.value);

        linkSelect.addEventListener('change', e => {
          if (e.target.value === '__external__') {
            urlInput.style.display = 'block';
            card.link = urlInput.value || '';
            setTimeout(() => urlInput.focus(), 50);
          } else {
            urlInput.style.display = 'none';
            card.link = e.target.value || '';
          }
        });

        linkRow.appendChild(linkIcon); linkRow.appendChild(linkSelect);

        cel.appendChild(iconRow); cel.appendChild(titleIn); cel.appendChild(descIn); cel.appendChild(linkRow); cel.appendChild(urlInput);
        grid.appendChild(cel);
      } else {
        const cel = document.createElement('div');
        cel.className = 'card-item' + (card.link ? ' card-linked' : '');
        cel.innerHTML = `
          ${card.link ? '<i class="fa-solid fa-arrow-up-right-from-square card-link-arrow" style="font-style:normal"></i>' : ''}
          <div class="card-icon"><i class="fa-solid ${card.icon || 'fa-star'}" style="font-style:normal"></i></div>
          <div class="card-title">${card.title || ''}</div>
          <div class="card-desc">${card.desc || ''}</div>
        `;
        if (card.link) {
          cel.style.cursor = 'pointer';
          if (card.link.startsWith('http')) {
            cel.onclick = () => window.open(card.link, '_blank', 'noopener');
          } else {
            cel.onclick = () => navigateTo(card.link);
          }
        }
        grid.appendChild(cel);
      }
    });
    this._el.appendChild(grid);
    if (!this.readOnly) {
      const addBtn = document.createElement('button');
      addBtn.className = 'cards-add-btn';
      addBtn.innerHTML = `<i class="fa-solid fa-plus" style="font-style:normal"></i> ${t('blockPickerCards')}`;
      addBtn.onmousedown = (e) => { e.preventDefault(); this.data.cards.push({icon:'fa-star',title:'',desc:''}); this._renderAll(); };
      this._el.appendChild(addBtn);
    }
  }
  save() { return { cols: this.data.cols, cards: this.data.cards }; }
}

// ════════════════════════════════════════
//  EDITOR
// ════════════════════════════════════════
async function initEditor(page) {
  if (!document.getElementById('editor')) return;

  let editorReady = false;

  const tools = {
    header: { class: Header, config: { levels: [1,2,3], defaultLevel: 1 } },
    list: { class: NestedList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
    checklist: { class: Checklist, inlineToolbar: true },
    code: { class: CodeTool },
    quote: { class: Quote, inlineToolbar: true },
    delimiter: Delimiter,
    inlineCode: { class: InlineCode },
    marker: { class: Marker },
    table: { class: Table, inlineToolbar: true },
    image: { class: LocalImageTool, inlineToolbar: false },
    warning: { class: CalloutTool, inlineToolbar: false },
    timeline: { class: TimelineTool, inlineToolbar: false },
    collapse: { class: CollapsibleTool, inlineToolbar: false },
    video: { class: VideoTool, inlineToolbar: false },
    cards: { class: CardsTool, inlineToolbar: false },
  };

  editor = new EditorJS({
    holder: 'editor',
    readOnly: !S.editMode,
    data: page.content?.blocks ? page.content : { blocks: [] },
    placeholder: t('editorPlaceholder'),
    tools,
    onChange: () => { if (editor._wsReady) { markDirty(); scheduleUndoSnapshot(); } },
    onReady: () => {
      updateTOC();
      initScrollSpy();
      injectCodeCopyButtons();
    },
  });

  try { await editor.isReady; } catch(e) {}
  hideSaveBar();
  undoStack.length = 0; redoStack.length = 0;
  setTimeout(() => { editor._wsReady = true; pushUndoSnapshot(); updateUndoRedoBtns(); }, 600);
}

// ════════════════════════════════════════
//  CUSTOM BLOCK MENU (replaces native settings)
// ════════════════════════════════════════
let blockMenu = null;

async function openBlockMenu(btn) {
  closeBlockMenu();
  const rect = btn.getBoundingClientRect();
  const total = editor?.blocks?.getBlocksCount?.() ?? 0;

  // Get index from DOM — find the ce-block that contains this toolbar
  let idx = -1;
  const toolbar = btn.closest('.ce-toolbar');
  if (toolbar) {
    const allBlocks = [...document.querySelectorAll('.ce-block')];
    // toolbar is sibling/relative to blocks — find by vertical position
    const toolbarTop = toolbar.getBoundingClientRect().top;
    let closest = 0, minDist = Infinity;
    allBlocks.forEach((b, i) => {
      const dist = Math.abs(b.getBoundingClientRect().top - toolbarTop);
      if (dist < minDist) { minDist = dist; closest = i; }
    });
    idx = closest;
  }
  if (idx < 0) idx = editor?.blocks?.getCurrentBlockIndex?.() ?? 0;

  // Get current block type and data
  let blockType = '';
  let blockData = {};
  try {
    const saved = await editor.save();
    const block = saved.blocks[idx];
    if (block) { blockType = block.type; blockData = block.data; }
  } catch(e) {}

  blockMenu = document.createElement('div');
  blockMenu.className = 'block-menu';
  blockMenu.style.cssText = `left:${rect.left}px;top:0;visibility:hidden;`;

  // ── Block-specific tunes ──
  const tunes = getBlockTunes(blockType, blockData, idx);

  if (tunes.length) {
    const tuneSection = document.createElement('div');
    tuneSection.style.cssText = 'padding-bottom:4px;margin-bottom:4px;border-bottom:1px solid var(--border);';
    tunes.forEach(tune => {
      const row = makeMenuRow(tune);
      tuneSection.appendChild(row);
    });
    blockMenu.appendChild(tuneSection);
  }

  // ── Move & Delete ──
  const resync = async () => { try { const d = await editor.save(); await editor.render(d); } catch(e) {} };
  const actions = [
    { icon: 'fa-chevron-up',   label: t('blockMoveUp'),   action: async () => { editor.blocks.move(idx, idx - 1); await resync(); }, disabled: idx === 0 },
    { icon: 'fa-chevron-down', label: t('blockMoveDown'), action: async () => { editor.blocks.move(idx, idx + 1); await resync(); }, disabled: idx >= total - 1 },
    { icon: 'fa-trash',        label: t('blockDelete'),   action: async () => { editor.blocks.delete(idx); await resync(); }, danger: true },
  ];
  actions.forEach(item => {
    if (item.disabled) return;
    blockMenu.appendChild(makeMenuRow(item));
  });

  document.body.appendChild(blockMenu);

  // Position based on actual menu height
  const menuRect = blockMenu.getBoundingClientRect();
  const menuH = menuRect.height;
  let top;
  if (rect.bottom + 4 + menuH > window.innerHeight - 8) {
    // Flip above the button
    top = rect.top - menuH - 4;
  } else {
    top = rect.bottom + 4;
  }
  // Clamp within viewport
  top = Math.max(8, Math.min(top, window.innerHeight - menuH - 8));
  blockMenu.style.cssText = `left:${rect.left}px;top:${top}px;`;

  setTimeout(() => document.addEventListener('click', closeBlockMenu, { once: true }), 10);

  // Close on scroll
  const onScroll = () => closeBlockMenu();
  window.addEventListener('scroll', onScroll, { once: true, passive: true });
  document.querySelector('.content-wrap')?.addEventListener('scroll', onScroll, { once: true, passive: true });
}

function makeMenuRow(item) {
  const row = document.createElement('div');
  row.className = 'block-menu-item' + (item.danger ? ' danger' : '') + (item.active ? ' active' : '');
  if (item.active) {
    row.style.cssText = 'background:rgba(var(--accent-rgb),.1);color:var(--accent);';
  }
  row.innerHTML = `<span class="block-menu-icon"><i class="fa-solid ${item.icon}" style="font-style:normal"></i></span><span>${item.label}</span>`;
  row.onmousedown = (e) => { e.preventDefault(); if (!item.noClose) closeBlockMenu(); item.action(); };
  return row;
}

function getBlockTunes(type, data, idx) {
  switch(type) {
    case 'header':
      return [1,2,3].map(level => ({
        icon: level === 1 ? 'fa-heading' : level === 2 ? 'fa-h' : 'fa-text-height',
        label: `${t('blockHeading')}${level}`,
        active: data.level === level,
        action: () => { editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, level }); }
      }));
    case 'list':
      return [
        { icon: 'fa-list-ul', label: t('blockUnorderedList'), active: data.style === 'unordered',
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, style: 'unordered' }) },
        { icon: 'fa-list-ol', label: t('blockOrderedList'), active: data.style === 'ordered',
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, style: 'ordered' }) },
      ];
    case 'quote':
      return [
        { icon: 'fa-align-left',   label: t('blockAlignLeft'),   active: data.alignment === 'left',
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, alignment: 'left' }) },
        { icon: 'fa-align-center', label: t('blockAlignCenter'), active: data.alignment === 'center',
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, alignment: 'center' }) },
      ];
    case 'image':
      return [
        { icon: 'fa-expand',     label: t('blockFullWidth'),      active: data.stretched,
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, stretched: !data.stretched }) },
        { icon: 'fa-border-all', label: t('blockWithBorder'),     active: data.withBorder,
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, withBorder: !data.withBorder }) },
        { icon: 'fa-square',     label: t('blockWithBackground'), active: data.withBackground,
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, withBackground: !data.withBackground }) },
      ];
    case 'table':
      return [
        { icon: 'fa-table-columns', label: data.withHeadings ? t('blockHideHeadings') : t('blockShowHeadings'),
          active: data.withHeadings,
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, withHeadings: !data.withHeadings }) },
      ];
    case 'warning':
      return Object.entries(CalloutTool.TYPES).map(([tp, c]) => ({
        icon: c.icon, label: c.label, active: data.type === tp,
        action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, type: tp })
      }));
    case 'timeline':
      return [
        { icon: 'fa-list-ol', label: data.numbered ? t('blockUnNumbered') : t('blockNumbered'), active: data.numbered,
          action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, numbered: !data.numbered }) },
      ];
    case 'cards':
      return [2,3].map(cols => ({
        icon: cols === 2 ? 'fa-table-columns' : 'fa-table-cells',
        label: `${cols} ${t('blockCols')}`,
        active: data.cols === cols,
        action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, cols })
      }));
    default: return [];
  }
}

function closeBlockMenu() {
  blockMenu?.remove();
  blockMenu = null;
}

// Intercept settings button click → our menu OR drag
let _blockDragState = null;

document.addEventListener('click', (e) => {
  if (!S.editMode || !editor) return;
  const settingsBtn = e.target.closest('.ce-toolbar__settings-btn');
  if (!settingsBtn) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  e.stopPropagation();
  // Only open menu if we didn't just finish a drag
  if (!_blockDragState?._didDrag) openBlockMenu(settingsBtn);
  if (_blockDragState) _blockDragState._didDrag = false;
}, true);

// Mousedown on settings btn — start potential drag
document.addEventListener('mousedown', (e) => {
  if (!S.editMode || !editor) return;
  const settingsBtn = e.target.closest('.ce-toolbar__settings-btn');
  if (!settingsBtn) return;
  e.preventDefault();
  e.stopImmediatePropagation();

  // Find which block this toolbar belongs to
  const holder = document.getElementById('editor');
  if (!holder) return;
  const toolbar = settingsBtn.closest('.ce-toolbar');
  if (!toolbar) return;
  const tRect = toolbar.getBoundingClientRect();
  const blocks = [...holder.querySelectorAll('.ce-block')];
  let fromIdx = -1, minDist = Infinity;
  blocks.forEach((b, i) => {
    const d = Math.abs(b.getBoundingClientRect().top - tRect.top);
    if (d < minDist) { minDist = d; fromIdx = i; }
  });
  if (fromIdx < 0) return;

  const startY = e.clientY;
  let isDragging = false;
  let placeholder = null;

  _blockDragState = { _didDrag: false };

  function onMove(ev) {
    // Need at least 6px movement to start drag
    if (!isDragging && Math.abs(ev.clientY - startY) < 6) return;

    if (!isDragging) {
      isDragging = true;
      _blockDragState._didDrag = true;
      closeBlockMenu();
      // Dim source block
      const currentBlocks = holder.querySelectorAll('.ce-block');
      if (currentBlocks[fromIdx]) currentBlocks[fromIdx].style.opacity = '0.35';
      // Create indicator
      placeholder = document.createElement('div');
      placeholder.className = 'block-drop-indicator';
      placeholder.textContent = t('blockDropHere');
      document.body.style.cursor = 'grabbing';
      document.body.style.userSelect = 'none';
    }

    ev.preventDefault();
    const mouseY = ev.clientY;
    const currentBlocks = [...holder.querySelectorAll('.ce-block')];

    placeholder.remove();

    // Find drop position
    let dropBefore = null;
    for (let i = 0; i < currentBlocks.length; i++) {
      const rect = currentBlocks[i].getBoundingClientRect();
      if (mouseY < rect.top + rect.height / 2) {
        dropBefore = currentBlocks[i];
        break;
      }
    }

    const redactor = holder.querySelector('.codex-editor__redactor');
    if (!redactor) return;
    if (dropBefore) {
      redactor.insertBefore(placeholder, dropBefore);
    } else {
      redactor.appendChild(placeholder);
    }
  }

  async function onUp() {
    document.removeEventListener('mousemove', onMove);
    document.removeEventListener('mouseup', onUp);
    document.body.style.cursor = '';
    document.body.style.userSelect = '';

    // Reset block opacity
    holder.querySelectorAll('.ce-block').forEach(b => b.style.opacity = '');

    if (!isDragging || !placeholder) return;

    // Calculate target index
    const currentBlocks = [...holder.querySelectorAll('.ce-block')];
    const next = placeholder.nextElementSibling;
    placeholder.remove();

    let toIdx;
    if (next && next.classList.contains('ce-block')) {
      toIdx = currentBlocks.indexOf(next);
      if (toIdx > fromIdx) toIdx--;
    } else {
      toIdx = currentBlocks.length - 1;
    }

    if (fromIdx >= 0 && toIdx >= 0 && fromIdx !== toIdx) {
      try {
        editor.blocks.move(toIdx, fromIdx);
        // Re-sync editor internal state
        const saved = await editor.save();
        await editor.render(saved);
        markDirty();
        scheduleUndoSnapshot();
      } catch(err) {}
    }
  }

  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
}, true);

// ════════════════════════════════════════
//  EDIT MODE
// ════════════════════════════════════════
async function toggleEdit() {
  // Ak ideme z edit → read, najprv ulož
  if (S.editMode) { await autoSave(true); hideSaveBar(); }

  S.editMode = !S.editMode;
  syncEditUI();

  const pg = document.getElementById('pg-title');
  const desc = document.getElementById('pg-desc');
  if (pg) pg.readOnly = !S.editMode;
  if (desc) desc.contentEditable = S.editMode;

  // Zruš starý editor a vytvor nový so správnym readOnly
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (page) {
    if (editor) {
      try { await editor.destroy(); } catch(e) {}
      editor = null;
      const h = document.getElementById('editor');
      if (h) { const c = document.createElement('div'); c.id = 'editor'; h.replaceWith(c); }
    }
    await initEditor(page);
    hideSaveBar();
  }
}

function syncEditUI() {
  document.getElementById('edit-btn').innerHTML = S.editMode
    ? `<i class="fa-solid fa-eye"></i> ${t('btnPreview')}`
    : `<i class="fa-solid fa-pen"></i> ${t('btnEdit')}`;
  document.getElementById('save-btn').classList.toggle('show', S.editMode);
  document.getElementById('undo-btn').style.display = S.editMode ? '' : 'none';
  document.getElementById('redo-btn').style.display = S.editMode ? '' : 'none';
  if (S.editMode) {
    document.body.setAttribute('data-edit', '1');
  } else {
    document.body.removeAttribute('data-edit');
  }

  // Cover button — inject/remove dynamically
  const existingCoverBtn = document.getElementById('cover-add-btn-inline');
  if (existingCoverBtn) existingCoverBtn.remove();
  // Also remove existing dynamic cover actions
  document.getElementById('cover-actions-inline')?.remove();

  if (S.editMode) {
    const page = S.pages.find(p => p.id === S.currentPageId);
    const readingTimeEl = document.getElementById('reading-time-el');
    if (readingTimeEl && page) {
      if (!page.cover) {
        // No cover — show "Add cover" button
        const btn = document.createElement('button');
        btn.id = 'cover-add-btn-inline';
        btn.innerHTML = '<i class="fa-solid fa-image"></i> Cover';
        btn.style.cssText = 'font-size:12px;padding:4px 10px;border-radius:6px;border:1px dashed var(--border);background:transparent;color:var(--text3);cursor:pointer;font-family:var(--font);display:flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap;';
        btn.onmouseover = () => { btn.style.color = 'var(--text2)'; btn.style.borderColor = 'var(--text3)'; };
        btn.onmouseout = () => { btn.style.color = 'var(--text3)'; btn.style.borderColor = 'var(--border)'; };
        btn.onclick = addCover;
        readingTimeEl.parentNode.insertBefore(btn, readingTimeEl);
      } else {
        // Has cover — show change/remove + position panel
        const coverEl = document.getElementById('page-cover-el');
        if (coverEl) {
          // Remove old if exists
          coverEl.querySelector('.page-cover-actions')?.remove();
          coverEl.querySelector('.cover-pos-panel')?.remove();

          const actions = document.createElement('div');
          actions.className = 'page-cover-actions';
          actions.id = 'cover-actions-inline';
          actions.innerHTML = `
            <button class="page-cover-btn" onclick="changeCover()"><i class="fa-solid fa-image"></i> <span data-i18n="coverChange">Change</span></button>
            <button class="page-cover-btn" onclick="removeCover()"><i class="fa-solid fa-trash"></i> <span data-i18n="coverRemove">Remove</span></button>`;
          coverEl.appendChild(actions);

          // Position panel len pre obrázky
          if (page.cover.type === 'image') {
            const fit = page.cover.fit || 'cover';
            const panel = document.createElement('div');
            panel.className = 'cover-pos-panel';
            panel.innerHTML = `
              <i class="fa-solid fa-up-down-left-right" style="color:rgba(255,255,255,.7);font-size:11px"></i>
              <span class="cover-pos-label">${t('dragReorder')}</span>
              <div class="cover-pos-btns">
                <button class="cover-pos-btn ${fit==='cover'?'active':''}" onclick="setCoverFit('cover')">${t('coverFitCover')}</button>
                <button class="cover-pos-btn ${fit==='contain'?'active':''}" onclick="setCoverFit('contain')">${t('coverFitContain')}</button>
              </div>`;
            coverEl.appendChild(panel);
            initCoverDrag(coverEl, page);
          }
        }
      }
    }
  } else {
    // Remove cover actions when leaving edit mode
    document.querySelector('.page-cover-actions')?.remove();
  }
}

let tocTimer = null;
// ════════════════════════════════════════
//  UNDO / REDO
// ════════════════════════════════════════
const undoStack = [];
const redoStack = [];
let undoTimer = null;
let _undoRedoInProgress = false;
const MAX_UNDO = 50;

function updateUndoRedoBtns() {
  const undoBtn = document.getElementById('undo-btn');
  const redoBtn = document.getElementById('redo-btn');
  if (undoBtn) undoBtn.classList.toggle('disabled', undoStack.length < 2);
  if (redoBtn) redoBtn.classList.toggle('disabled', redoStack.length < 1);
}

async function pushUndoSnapshot() {
  if (!editor || !S.editMode || _undoRedoInProgress) return;
  try {
    const saved = await editor.save();
    const json = JSON.stringify(saved);
    if (undoStack.length && undoStack[undoStack.length - 1] === json) return;
    undoStack.push(json);
    if (undoStack.length > MAX_UNDO) undoStack.shift();
    redoStack.length = 0;
    updateUndoRedoBtns();
  } catch(e) {}
}

function scheduleUndoSnapshot() {
  clearTimeout(undoTimer);
  undoTimer = setTimeout(pushUndoSnapshot, 600);
}

async function editorUndo() {
  if (!editor || !S.editMode || undoStack.length < 2) return;
  _undoRedoInProgress = true;
  try {
    // Current state is top of undo stack, move it to redo
    const current = undoStack.pop();
    redoStack.push(current);
    const snapshot = undoStack[undoStack.length - 1];
    if (!snapshot) { _undoRedoInProgress = false; return; }
    const data = JSON.parse(snapshot);
    await editor.render(data);
    updateUndoRedoBtns();
    showSaveBar();
  } catch(e) {}
  setTimeout(() => { _undoRedoInProgress = false; }, 400);
}

async function editorRedo() {
  if (!editor || !S.editMode || redoStack.length < 1) return;
  _undoRedoInProgress = true;
  try {
    const snapshot = redoStack.pop();
    undoStack.push(snapshot);
    const data = JSON.parse(snapshot);
    await editor.render(data);
    updateUndoRedoBtns();
    showSaveBar();
  } catch(e) {}
  setTimeout(() => { _undoRedoInProgress = false; }, 400);
}

function markDirty() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => autoSave(true), 3000);
  showSaveBar();
  // Aktualizuj TOC s oneskorením (čakáme kým EditorJS re-renderuje headings)
  clearTimeout(tocTimer);
  tocTimer = setTimeout(() => { updateTOC(); initScrollSpy(); }, 600);
}

function showSaveBar() {
  document.getElementById('save-bar').classList.add('show');
}

function hideSaveBar() {
  document.getElementById('save-bar').classList.remove('show');
}

async function discardChanges() {
  hideSaveBar();
  // Reloadni stránku z servera
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  try {
    const r = await fetch(`api.php?action=load_page&id=${page.id}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok && d.page) {
      const idx = S.pages.findIndex(p => p.id === page.id);
      if (idx !== -1) S.pages[idx] = d.page;
    }
  } catch(e) {}
  renderPage();
  if (S.editMode) {
    S.editMode = false;
    await toggleEdit();
  }
}

async function autoSave(silent = false) {
  if (!S.editMode || !editor) return;
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  try {
    const data = await editor.save();
    page.content = data;
    page._contentLoaded = true;
    const titleEl = document.getElementById('pg-title');
    const descEl = document.getElementById('pg-desc');
    if (titleEl) page.title = titleEl.value;
    if (descEl) page.subtitle = descEl.textContent;
    await savePageToServer(page);
    await save();
    renderNav();
    // Bar neschováme tu — zmizne len po manuálnom kliknutí Uložiť alebo Zahodiť
    if (!silent) showToast(t('toastSaved'));
  } catch(e) { console.warn('autoSave error:', e); }
}

async function savePage() {
  const bar = document.getElementById('save-bar');
  const dot = bar?.querySelector('.save-bar-dot');
  const text = bar?.querySelector('.save-bar-text');
  const btn = bar?.querySelector('.save-bar-btn');

  // Ukáž stav "Saving..."
  if (text) text.textContent = t('savingLabel');
  if (dot) { dot.style.background = '#facc15'; dot.style.boxShadow = '0 0 6px rgba(250,204,21,.6)'; }
  if (btn) { btn.disabled = true; btn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> ${t('savingLabel')}`; }

  const [result] = await Promise.allSettled([
    autoSave(true),
    new Promise(r => setTimeout(r, 800))
  ]);

  // Reset a schovaj
  hideSaveBar();
  if (text) text.textContent = t('unsavedChanges');
  if (dot) { dot.style.background = ''; dot.style.boxShadow = ''; }
  if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fa-solid fa-check"></i> ${t('btnSave')}`; }
  showToast(t('toastSaved'));
}

// ════════════════════════════════════════
//  ADD / DELETE PAGE
// ════════════════════════════════════════
function buildIconGrid() {
  const grid = document.getElementById('quick-icon-grid');
  if (!grid) return;
  grid.innerHTML = '';
  ICON_LIST.slice(0, 32).forEach(ic => {
    const el = document.createElement('div');
    el.className = 'ig-item';
    el.innerHTML = `<i class="fa-solid ${ic}" title="${ic}"></i>`;
    el.onclick = () => {
      document.getElementById('new-icon').value = ic;
      previewIcon(ic);
      grid.querySelectorAll('.ig-item').forEach(e => e.classList.remove('selected'));
      el.classList.add('selected');
    };
    grid.appendChild(el);
  });
}

function previewIcon(val) {
  const box = document.getElementById('icon-preview-box');
  if (!box) return;
  const icon = val.startsWith('fa-') ? val : 'fa-' + val;
  box.innerHTML = `<i class="fa-solid ${icon}"></i>`;
}

// ════════════════════════════════════════
//  PAGE EDIT MODAL
// ════════════════════════════════════════
let editingPageId = null;

function openPageEdit(pageId) {
  const page = S.pages.find(p => p.id === pageId);
  if (!page) return;
  editingPageId = pageId;

  document.getElementById('page-edit-title').value = page.title || '';
  document.getElementById('page-edit-subtitle').value = page.subtitle || '';
  document.getElementById('page-edit-icon').value = page.icon || 'fa-file';
  document.getElementById('page-edit-section').value = page.section || '';

  const preview = document.getElementById('page-edit-icon-preview');
  preview.innerHTML = `<i class="fa-solid ${page.icon || 'fa-file'}"></i>`;

  // Build icon grid
  const grid = document.getElementById('page-edit-icon-grid');
  grid.innerHTML = '';
  ICON_LIST.slice(0, 32).forEach(ic => {
    const btn = document.createElement('div');
    btn.className = 'ig-item';
    btn.innerHTML = `<i class="fa-solid ${ic}"></i>`;
    btn.title = ic;
    btn.onclick = () => {
      document.getElementById('page-edit-icon').value = ic;
      previewPageEditIcon(ic);
    };
    grid.appendChild(btn);
  });

  openModal('page-edit-modal');
  setTimeout(() => document.getElementById('page-edit-title').focus(), 100);
}

function previewPageEditIcon(val) {
  const v = val.trim() || 'fa-file';
  document.getElementById('page-edit-icon-preview').innerHTML = `<i class="fa-solid ${v}"></i>`;
}

async function confirmPageEdit() {
  const page = S.pages.find(p => p.id === editingPageId);
  if (!page) return;
  page.title = document.getElementById('page-edit-title').value.trim() || t('pageUntitled');
  page.subtitle = document.getElementById('page-edit-subtitle').value.trim();
  page.icon = document.getElementById('page-edit-icon').value.trim() || 'fa-file';
  page.section = document.getElementById('page-edit-section').value.trim();
  closeModal('page-edit-modal');
  await save();
  renderNav();
  if (S.currentPageId === editingPageId) renderPage();
  showToast(t('toastPageEdited'));
}

function deletePageFromEdit() {
  closeModal('page-edit-modal');
  deletePage(editingPageId);
}

function openAddPage(parentId, sectionHint = '') {
  S.addParentId = parentId;
  selectedTemplate = 'blank';
  document.getElementById('new-title').value = '';
  document.getElementById('new-icon').value = 'fa-file';
  document.getElementById('new-section').value = sectionHint;
  previewIcon('fa-file');
  buildIconGrid();
  buildTemplateGrid();
  openModal('add-modal');
  setTimeout(() => document.getElementById('new-title').focus(), 100);
}

function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('animate')));
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('animate');
  setTimeout(() => el.classList.remove('open'), 200);
}

// Custom confirm dialog
function showConfirm({ title, msg, icon = 'fa-trash', iconType = 'danger', okLabel = t('confirmDeleteOk'), okClass = 'btn-danger', onOk }) {
  const overlay = document.getElementById('confirm-overlay');
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-msg').textContent = msg;
  const iconEl = document.getElementById('confirm-icon');
  iconEl.className = `confirm-icon ${iconType}`;
  iconEl.innerHTML = `<i class="fa-solid ${icon}"></i>`;
  const okBtn = document.getElementById('confirm-ok-btn');
  okBtn.textContent = okLabel;
  okBtn.className = `btn ${okClass}`;
  document.getElementById('confirm-cancel-btn').textContent = t('btnCancel');

  overlay.classList.add('open');
  requestAnimationFrame(() => requestAnimationFrame(() => overlay.classList.add('animate')));

  const close = () => {
    overlay.classList.remove('animate');
    setTimeout(() => overlay.classList.remove('open'), 200);
  };

  okBtn.onclick = () => { close(); onOk(); };
  document.getElementById('confirm-cancel-btn').onclick = close;
  overlay.onclick = (e) => { if (e.target === overlay) close(); };
}

async function confirmAddPage() {
  const title = document.getElementById('new-title').value.trim();
  if (!title) return;
  let iconVal = document.getElementById('new-icon').value.trim() || 'fa-file';
  if (!iconVal.startsWith('fa-')) iconVal = 'fa-' + iconVal;
  const section = document.getElementById('new-section').value.trim() || null;

  const tmpl = getPageTemplates().find(tmpl => t.id === selectedTemplate) || getPageTemplates()[0];

  const siblings = S.pages.filter(p =>
    p.spaceId === S.currentSpaceId && p.parentId === S.addParentId
  );

  const page = {
    id: pageSlug(title),
    spaceId: S.currentSpaceId,
    parentId: S.addParentId,
    title, icon: iconVal,
    subtitle: tmpl.subtitle || '',
    section: S.addParentId ? null : (section || null),
    order: siblings.length,
    content: JSON.parse(JSON.stringify(tmpl.content)),
    cover: tmpl.cover || null,
    _contentLoaded: true,
  };

  S.pages.push(page);
  await savePageToServer(page);
  await save();
  closeModal('add-modal');
  await navigateTo(page.id);
  if (!S.editMode) toggleEdit();
  showToast(`${t("toastSaved")} — "${title}"`);
}

async function deletePage(id) {
  const pg = S.pages.find(p => p.id === id);
  const children = S.pages.filter(p => p.parentId === id);
  closeModal('page-edit-modal');
  showConfirm({
    title: t('confirmDeletePageTitle'),
    msg: t('confirmDeletePageMsg', pg?.title || '', children.length),
    icon: 'fa-trash', iconType: 'danger',
    okLabel: t('confirmDeletePageOk'), okClass: 'btn-danger',
    onOk: async () => {
      const toRemove = collectDescendants(id);
      S.pages = S.pages.filter(p => !toRemove.has(p.id));
      for (const rid of toRemove) await deletePageFromServer(rid);
      await save();
      if (toRemove.has(S.currentPageId)) {
        S.currentPageId = spacePages()[0]?.id || null;
      }
      renderNav(); renderPage();
      showToast(t('toastPageDeleted'));
    }
  });
}

function collectDescendants(id) {
  const set = new Set([id]);
  S.pages.filter(p => p.parentId === id).forEach(c => {
    collectDescendants(c.id).forEach(x => set.add(x));
  });
  return set;
}

// ════════════════════════════════════════
//  TOC
// ════════════════════════════════════════
// ════════════════════════════════════════
//  TOC — builds after editor ready, anchors + scroll spy
// ════════════════════════════════════════
let scrollSpyObserver = null;

function slugify(text) {
  return text.toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .trim() || 'heading';
}

function updateTOC() {
  const toc = document.getElementById('toc-items');
  if (!toc) return;
  toc.innerHTML = '';

  // Collect both editor headers and timeline titles, in DOM order
  const editorHeaders = Array.from(document.querySelectorAll('#editor .ce-header'));
  const timelineTitles = Array.from(document.querySelectorAll('#editor .tl-title'));

  // Merge and sort by DOM position
  const allItems = [...editorHeaders, ...timelineTitles].sort((a, b) => {
    return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
  });

  if (!allItems.length) {
    toc.innerHTML = `<div style="font-size:12px;color:var(--text4);padding:4px 0 4px 10px;border-left:1px solid var(--border)">${t('tocNoHeadings')}</div>`;
    return;
  }

  const usedIds = {};
  allItems.forEach(h => {
    const isTLTitle = h.classList.contains('tl-title');
    const level = isTLTitle ? 3 : (parseInt(h.tagName[1]) || 2);
    if (!isTLTitle && level > 3) return;

    const baseSlug = slugify(h.textContent);
    const count = usedIds[baseSlug] = (usedIds[baseSlug] || 0) + 1;
    const id = count > 1 ? `${baseSlug}-${count}` : baseSlug;
    h.id = id;

    const item = document.createElement('div');
    item.className = 'toc-item' + (level === 3 ? ' h3' : '');
    item.dataset.target = id;
    item.textContent = h.textContent;
    item.onclick = () => {
      const navOffset = getComputedStyle(document.documentElement)
        .getPropertyValue('--total-h').trim();
      const offsetPx = parseInt(navOffset) || 90;
      const rect = h.getBoundingClientRect();
      const scrollTop = window.scrollY + rect.top - offsetPx - 16;
      window.scrollTo({ top: scrollTop, behavior: 'smooth' });
    };
    toc.appendChild(item);
  });
}

function initScrollSpy() {
  if (scrollSpyObserver) scrollSpyObserver.disconnect();

  const navOffset = parseInt(
    getComputedStyle(document.documentElement).getPropertyValue('--total-h')
  ) || 90;

  const options = {
    root: null,
    rootMargin: `-${navOffset + 8}px 0px -60% 0px`,
    threshold: 0,
  };

  scrollSpyObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        document.querySelectorAll('.toc-item').forEach(item => {
          item.classList.toggle('active', item.dataset.target === id);
        });
      }
    });
  }, options);

  document.querySelectorAll('#editor .ce-header[id], #editor .tl-title[id]').forEach(h => {
    scrollSpyObserver.observe(h);
  });
}

// ════════════════════════════════════════
//  SEARCH
// ════════════════════════════════════════
function handleSearch(q) {
  const dd = document.getElementById('search-dd');
  if (!q.trim()) { dd.innerHTML = ''; dd.classList.remove('open'); return; }
  const results = S.pages.filter(p =>
    p.title.toLowerCase().includes(q.toLowerCase()) ||
    (p.subtitle || '').toLowerCase().includes(q.toLowerCase())
  ).slice(0, 8);

  if (!results.length) {
    dd.innerHTML = `<div class="search-empty"><i class="fa-solid fa-magnifying-glass" style="margin-right:6px"></i>${t('searchNoResults')}</div>`;
  } else {
    dd.innerHTML = results.map(p => `
      <div class="search-result-item" onclick="selectSearch('${p.id}')">
        <i class="fa-solid ${p.icon || 'fa-file'}"></i>
        <div>
          <div class="search-result-title">${esc(p.title)}</div>
          ${p.subtitle ? `<div class="search-result-path">${esc(p.subtitle.slice(0,60))}</div>` : ''}
        </div>
      </div>`).join('');
  }
  dd.classList.add('open');
}

function openSearchDD() {
  const q = document.getElementById('search-input').value;
  if (q.trim()) handleSearch(q);
}

function closeSearchDD() {
  document.getElementById('search-dd').classList.remove('open');
}

function selectSearch(id) {
  document.getElementById('search-input').value = '';
  closeSearchDD();
  const sp = S.pages.find(p => p.id === id);
  if (sp && sp.spaceId !== S.currentSpaceId) {
    S.currentSpaceId = sp.spaceId;
    renderSpaces();
  }
  navigateTo(id);
}

// ════════════════════════════════════════
//  TOAST / FEEDBACK
// ════════════════════════════════════════
function showToast(msg) {
  const t = document.getElementById('toast');
  document.getElementById('toast-text').textContent = msg;
  t.classList.add('show');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 2600);
}

function react(r) {
  showToast(t('toastFeedback') + r);
}

// ════════════════════════════════════════
//  UTILS
// ════════════════════════════════════════
// ════════════════════════════════════════
//  OG IMAGE GENERATOR
// ════════════════════════════════════════
function generateOgImage(title, subtitle, siteName) {
  try {
    const c = document.createElement('canvas');
    c.width = 1200; c.height = 630;
    const ctx = c.getContext('2d');

    // Gradient background using accent color
    const accent = S.settings?.accentColor || '#f97316';
    const r = parseInt(accent.slice(1,3),16), g = parseInt(accent.slice(3,5),16), b = parseInt(accent.slice(5,7),16);
    const grad = ctx.createLinearGradient(0, 0, 1200, 630);
    grad.addColorStop(0, `rgba(${r},${g},${b},1)`);
    grad.addColorStop(1, `rgba(${Math.max(0,r-40)},${Math.max(0,g-40)},${Math.max(0,b-20)},1)`);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 1200, 630);

    // Subtle pattern overlay
    ctx.fillStyle = 'rgba(255,255,255,0.03)';
    for (let i = 0; i < 12; i++) {
      ctx.beginPath();
      ctx.arc(100 + i * 100, 100 + (i % 3) * 180, 60 + i * 8, 0, Math.PI * 2);
      ctx.fill();
    }

    // Title
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 54px system-ui, -apple-system, sans-serif';
    const words = title.split(' ');
    let lines = []; let line = '';
    words.forEach(w => {
      const test = line ? line + ' ' + w : w;
      if (ctx.measureText(test).width > 1040) { lines.push(line); line = w; }
      else line = test;
    });
    if (line) lines.push(line);
    lines = lines.slice(0, 3); // max 3 lines

    const titleY = subtitle ? 230 : 270;
    lines.forEach((l, i) => {
      ctx.fillText(l, 80, titleY + i * 66);
    });

    // Subtitle
    if (subtitle) {
      ctx.fillStyle = 'rgba(255,255,255,0.7)';
      ctx.font = '28px system-ui, -apple-system, sans-serif';
      ctx.fillText(subtitle.slice(0, 80), 80, titleY + lines.length * 66 + 20);
    }

    // Site name at bottom
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = '500 22px system-ui, -apple-system, sans-serif';
    ctx.fillText(siteName, 80, 570);

    // Small accent dot before site name
    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.arc(60, 564, 5, 0, Math.PI * 2);
    ctx.fill();

    return c.toDataURL('image/png');
  } catch(e) { return ''; }
}

function esc(str) {
  return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && e.key === 's') {
    e.preventDefault();
    if (S.editMode) savePage();
  }
  if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
    if (S.editMode) { e.preventDefault(); editorUndo(); }
  }
  if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === 'z') {
    if (S.editMode) { e.preventDefault(); editorRedo(); }
  }
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'y') {
    if (S.editMode) { e.preventDefault(); editorRedo(); }
  }
  if ((e.metaKey || e.ctrlKey) && e.key === 'e') { e.preventDefault(); toggleEdit(); }
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); document.getElementById('search-input').focus(); }
  if (e.key === 'Escape') {
    closeModal('add-modal');
    closeSettings();
    closeSearchDD();
    closeIconPickerEl();
  }
});

// ════════════════════════════════════════
//  DEFAULT CONTENT
// ════════════════════════════════════════
function makeDefaultContent1() {
  return {"time":Date.now(),"blocks":[
    {"type":"header","data":{"text":"Getting started","level":2}},
    {"type":"paragraph","data":{"text":"Welcome to your self-hosted documentation platform. Click <b>Edit mode</b> in the top right to start writing content."}},
    {"type":"warning","data":{"title":"Tip","message":"Use the ⊕ button in the editor to insert blocks — headings, code, tables, lists, and more."}},
    {"type":"header","data":{"text":"What you can do","level":2}},
    {"type":"list","data":{"style":"unordered","items":[
      {"content":"Create pages and subpages in a tree structure","items":[]},
      {"content":"Organize content into sections and spaces","items":[]},
      {"content":"Upload your own logo and customize the accent color","items":[]},
      {"content":"Everything is saved as JSON files on your server","items":[]}
    ]}},
    {"type":"delimiter","data":{}},
    {"type":"paragraph","data":{"text":"All changes are automatically saved to the server as JSON files. Images are stored in the images/ directory."}}
  ]};
}
function makeDefaultContent2() {
  return {"time":Date.now(),"blocks":[
    {"type":"header","data":{"text":"Deploy to your own domain","level":2}},
    {"type":"paragraph","data":{"text":"Upload index.html, index.php, api.php, and auth.php to any PHP hosting. No database required — everything is stored as JSON files."}},
    {"type":"header","data":{"text":"Requirements","level":3}},
    {"type":"list","data":{"style":"unordered","items":[
      {"content":"<b>PHP 7.4+</b> — any standard hosting works","items":[]},
      {"content":"<b>Write permissions</b> — for the data/ and images/ directories","items":[]},
      {"content":"<b>No database</b> — all data is stored in JSON files","items":[]}
    ]}},
    {"type":"header","data":{"text":"Quick start","level":3}},
    {"type":"code","data":{"code":"# 1. Upload all files to your server\n# 2. Open the URL in your browser\n# 3. Set your admin password on first run\n# 4. Start writing!"}},
    {"type":"paragraph","data":{"text":"Point your custom domain or subdomain (e.g. docs.yourdomain.com) to the directory where you uploaded the files."}}
  ]};
}
function makeDefaultContent3() {
  return {"time":Date.now(),"blocks":[
    {"type":"header","data":{"text":"Block types","level":2}},
    {"type":"paragraph","data":{"text":"The editor supports various content types. Click ⊕ or type / to see all available blocks."}},
    {"type":"table","data":{"withHeadings":true,"content":[["Block","Description","Shortcut"],["Heading","H1, H2, H3","# ## ###"],["List","Bulleted or numbered","- or 1."],["Code","Code block with syntax","```"],["Quote","Highlighted quotation",""],["Callout","Info/warning/tip box",""],["Checklist","Checkable items",""],["Delimiter","Section divider","---"],["Table","Table with headers",""],["Cards","Card grid with icons",""],["Timeline","Changelog timeline",""]]}},
    {"type":"quote","data":{"text":"Good documentation is the foundation of every project.","caption":"— Developer wisdom"}},
    {"type":"checklist","data":{"items":[{"text":"Create your first page","checked":true},{"text":"Upload logo and set accent color","checked":false},{"text":"Add content using blocks","checked":false}]}}
  ]};
}

// ════════════════════════════════════════
//  AUTH — PHP backend (auth.php)
// ════════════════════════════════════════
/*
  S.authed = true/false based on server session via auth.php
  All admin UI only shows when S.authed === true
*/

S.authed = false;
S.needsSetup = false;

async function checkAuth() {
  try {
    const r = await fetch('auth.php?action=check', { credentials: 'same-origin' });
    const d = await r.json();
    S.authed = !!d.authed;
    S.needsSetup = !!d.needsSetup;
  } catch(e) {
    S.authed = false;
  }
  updateAdminUI();
}

async function phpLogin(password) {
  const fd = new FormData();
  fd.append('action', 'login');
  fd.append('password', password);
  try {
    const r = await fetch('auth.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const d = await r.json();
    if (d.authed) {
      S.authed = true;
      updateAdminUI();
      closeAuth();
      renderSpaces();
      showToast(t('btnLoggedIn') + ' ✓');
      return true;
    } else {
      return d.error || t('authWrong');
    }
  } catch(e) {
    return t('authConnError');
  }
}

async function phpLogout() {
  const fd = new FormData();
  fd.append('action', 'logout');
  try {
    await fetch('auth.php', { method: 'POST', credentials: 'same-origin', body: fd });
  } catch(e) {}
  S.authed = false;
  if (S.editMode) { S.editMode = false; syncEditUI(); renderPage(); }
  updateAdminUI();
  renderSpaces();
  showToast(t('btnLogout'));
}

function handleAuthBtn() {
  if (S.authed) {
    showConfirm({ title: t('confirmLogoutTitle'), msg: t('confirmLogoutMsg'), icon: 'fa-arrow-right-from-bracket', iconType: 'warning', okLabel: t('confirmLogoutOk'), okClass: 'btn-ghost', onOk: phpLogout });
  } else {
    openLoginModal();
  }
}

function updateAdminUI() {
  // Auth button
  const btn = document.getElementById('auth-nav-btn');
  const icon = document.getElementById('auth-btn-icon');
  const label = document.getElementById('auth-btn-label');
  if (btn) {
    btn.className = 'auth-nav-btn' + (S.authed ? ' authed' : '');
    icon.className = S.authed ? 'fa-solid fa-lock-open' : 'fa-solid fa-lock';
    label.textContent = S.authed ? t('btnLoggedIn') : t('btnLogin');
  }
  // Admin-only elements
  document.querySelectorAll('.admin-only').forEach(el => {
    el.style.display = S.authed ? (el.classList.contains('nav-divider') ? 'block' : '') : 'none';
  });
  // Translate availability depends on auth state + settings toggle
  applyTranslateAvailability();
  // Logo area: click goes to settings only if admin
  const logoArea = document.getElementById('logo-area-btn');
  if (logoArea) {
    logoArea.onclick = S.authed ? openSettings : null;
    logoArea.style.cursor = S.authed ? 'pointer' : 'default';
  }
  // Pin setup section in settings
  const pinSection = document.getElementById('settings-pin-section');
  if (pinSection) pinSection.style.display = S.authed ? '' : 'none';
  // Update tab strip add button
  renderSpaces();
}

// ── Login modal (reuses auth-overlay but password-style) ──
let _loginMode = 'password'; // we use password field now, not PIN

function openLoginModal() {
  // Build modal content for password login
  document.getElementById('auth-icon-i').className = 'fa-solid fa-key';
  document.getElementById('auth-title').textContent = t('authLogin');
  document.getElementById('auth-sub').textContent = t('authLogin');
  document.getElementById('auth-submit-btn').textContent = t('authLogin');
  document.getElementById('auth-hint').textContent = '';
  document.getElementById('auth-error').innerHTML = '';

  // Switch to password input mode
  const pinRow = document.getElementById('pin-row');
  pinRow.style.display = 'none';
  let pwWrap = document.getElementById('auth-pw-wrap');
  if (!pwWrap) {
    pwWrap = document.createElement('div');
    pwWrap.id = 'auth-pw-wrap';
    pwWrap.style.cssText = 'margin-bottom:16px';
    pwWrap.innerHTML = `
      <div style="position:relative">
        <input type="password" id="auth-pw-input" class="field-input"
          placeholder="${t('authPassword')}"
          style="width:100%;padding-right:38px"
          onkeydown="if(event.key==='Enter')submitLogin()">
        <button onclick="togglePwVis()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text3);font-size:13px" id="pw-vis-btn">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    `;
    pinRow.parentNode.insertBefore(pwWrap, pinRow);
  }
  pwWrap.style.display = 'block';
  document.getElementById('auth-submit-btn').onclick = submitLogin;

  document.getElementById('auth-overlay').classList.add('open');
  setTimeout(() => document.getElementById('auth-pw-input')?.focus(), 80);
}

function togglePwVis() {
  const inp = document.getElementById('auth-pw-input');
  const btn = document.getElementById('pw-vis-btn');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.innerHTML = inp.type === 'password'
    ? '<i class="fa-solid fa-eye"></i>'
    : '<i class="fa-solid fa-eye-slash"></i>';
}

async function submitLogin() {
  const inp = document.getElementById('auth-pw-input');
  const pw = inp?.value || '';
  if (!pw) { showLoginError(t('authEnterPw')); return; }

  const btn = document.getElementById('auth-submit-btn');
  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${t('authVerifying')}`;

  const result = await phpLogin(pw);
  btn.disabled = false;
  btn.textContent = t('authLogin');

  if (result !== true) {
    showLoginError(result);
    if (inp) inp.value = '';
  }
}

function showLoginError(msg) {
  document.getElementById('auth-error').innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${msg}`;
  const pwWrap = document.getElementById('auth-pw-wrap');
  if (pwWrap) {
    pwWrap.style.animation = 'none';
    pwWrap.offsetHeight;
    pwWrap.style.animation = 'shake 0.4s ease';
  }
}

// closeAuth reused from PIN system
function closeAuth() {
  document.getElementById('auth-overlay').classList.remove('open');
  const pwWrap = document.getElementById('auth-pw-wrap');
  if (pwWrap) pwWrap.style.display = 'none';
  document.getElementById('pin-row').style.display = '';
}

// ════════════════════════════════════════
//  SETUP WIZARD (first run)
// ════════════════════════════════════════
function openSetupWizard() {
  // Apply i18n to setup wizard
  document.getElementById('setup-title').textContent = t('setupTitle');
  document.getElementById('setup-sub').textContent = t('setupSubtitle');
  document.getElementById('setup-pw-label').textContent = t('setupPassword');
  document.getElementById('setup-confirm-label').textContent = t('setupConfirm');
  document.getElementById('setup-btn').querySelector('span').textContent = t('setupBtn');
  document.querySelector('#rule-length span').textContent = t('setupMinLength');
  document.querySelector('#rule-upper span').textContent = t('setupUppercase');
  document.querySelector('#rule-lower span').textContent = t('setupLowercase');
  document.querySelector('#rule-number span').textContent = t('setupNumber');
  document.querySelector('#rule-special span').textContent = t('setupSpecial');
  document.querySelector('#rule-match span').textContent = t('setupMatch');
  document.getElementById('setup-overlay').classList.add('open');
  setTimeout(() => document.getElementById('setup-pw').focus(), 100);
}

function toggleSetupVis(inputId, btn) {
  const inp = document.getElementById(inputId);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.innerHTML = inp.type === 'password'
    ? '<i class="fa-solid fa-eye"></i>'
    : '<i class="fa-solid fa-eye-slash"></i>';
}

function validateSetupPassword() {
  const pw = document.getElementById('setup-pw').value;
  const pw2 = document.getElementById('setup-pw2').value;

  const rules = {
    length:  pw.length >= 8,
    upper:   /[A-Z]/.test(pw),
    lower:   /[a-z]/.test(pw),
    number:  /[0-9]/.test(pw),
    special: /[^A-Za-z0-9]/.test(pw),
    match:   pw.length > 0 && pw === pw2,
  };

  Object.entries(rules).forEach(([key, pass]) => {
    const el = document.getElementById('rule-' + key);
    if (!el) return;
    el.className = 'setup-rule ' + (pass ? 'pass' : 'fail');
    el.querySelector('i').className = pass ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle';
  });

  // Show mismatch message
  const matchEl = document.getElementById('rule-match');
  if (pw2.length > 0 && !rules.match) {
    matchEl.querySelector('span').textContent = t('setupMismatch');
    matchEl.className = 'setup-rule fail';
    matchEl.querySelector('i').className = 'fa-solid fa-circle-xmark';
    matchEl.style.color = '#ef4444';
    matchEl.querySelector('i').style.color = '#ef4444';
  } else {
    matchEl.querySelector('span').textContent = t('setupMatch');
    matchEl.style.color = '';
    matchEl.querySelector('i').style.color = '';
  }

  const allPass = Object.values(rules).every(Boolean);
  document.getElementById('setup-btn').disabled = !allPass;
  return allPass;
}

async function submitSetup() {
  if (!validateSetupPassword()) return;

  const pw = document.getElementById('setup-pw').value;
  const btn = document.getElementById('setup-btn');
  const errEl = document.getElementById('setup-error');

  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>${t('setupCreating')}</span>`;
  errEl.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action', 'setup');
    fd.append('password', pw);
    const r = await fetch('auth.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const d = await r.json();

    if (d.ok && d.authed) {
      S.authed = true;
      S.needsSetup = false;
      document.getElementById('setup-overlay').classList.remove('open');
      updateAdminUI();
      renderSpaces();
      showToast(t('btnLoggedIn') + ' ✓');
    } else {
      errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${d.error || t('setupError')}`;
      btn.disabled = false;
      btn.innerHTML = `<i class="fa-solid fa-lock"></i> <span>${t('setupBtn')}</span>`;
    }
  } catch(e) {
    errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${t('setupError')}`;
    btn.disabled = false;
    btn.innerHTML = `<i class="fa-solid fa-lock"></i> <span>${t('setupBtn')}</span>`;
  }
}

// ════════════════════════════════════════
//  TRANSLATE
// ════════════════════════════════════════
function loadTranslateWidget() {
  if (document.getElementById('gt-script')) return; // už načítaný
  const s = document.createElement('script');
  s.id = 'gt-script';
  s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
  document.body.appendChild(s);
}

function googleTranslateElementInit() {
  new google.translate.TranslateElement({
    pageLanguage: S.settings.lang || DEFAULT_INTERFACE_LANG,
    includedLanguages: 'en,sk,cs,de,fr,es,pl,uk,ru',
    autoDisplay: false,
  }, 'google_translate_element');
}

function translateTo(lang) {
  document.getElementById('translate-dd').classList.remove('open');
  const srcLang = S.settings.lang || DEFAULT_INTERFACE_LANG;

  if (lang === srcLang) {
    // Restore original
    if (typeof doGTranslate === 'function') {
      doGTranslate(`${srcLang}|${srcLang}`);
    }
    const combo = document.querySelector('.goog-te-combo');
    if (combo) { combo.value = srcLang; combo.dispatchEvent(new Event('change')); }
    // Update active state
    document.querySelectorAll('.translate-lang').forEach(el => {
      el.classList.toggle('active', el.dataset.lang === lang);
    });
    return;
  }

  if (typeof doGTranslate === 'function') {
    doGTranslate(`${srcLang}|${lang}`);
  } else {
    const val = `/${srcLang}/${lang}`;
    document.cookie = `googtrans=${val}; path=/`;
    document.cookie = `googtrans=${val}; path=/; domain=${location.hostname}`;
    const sel = document.querySelector('.goog-te-combo');
    if (sel) { sel.value = lang; sel.dispatchEvent(new Event('change')); }
    else location.reload();
  }
  // Update active state
  document.querySelectorAll('.translate-lang').forEach(el => {
    el.classList.toggle('active', el.dataset.lang === lang);
  });
}

function toggleTranslate() {
  if (S.authed || !isTranslateEnabled()) return;
  document.getElementById('translate-dd').classList.toggle('open');
}

document.addEventListener('click', e => {
  const wrap = document.getElementById('translate-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('translate-dd')?.classList.remove('open');
  }
});

(async function init() {
  // Loading overlay
  const overlay = document.createElement('div');
  overlay.id = 'init-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;gap:12px;';
  overlay.innerHTML = `
    <div style="width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin 0.7s linear infinite;"></div>
    <div id="init-msg" style="font-size:13px;color:var(--text3);">${t('loaderLoading')}</div>
    <button id="init-retry" style="display:none;margin-top:8px;padding:6px 18px;background:var(--accent);color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:var(--font);font-size:13px;">${t('loaderRetry')}</button>
  `;
  if (!document.getElementById('init-overlay')) document.body.appendChild(overlay);

  const tryInit = async () => {
    document.getElementById('init-msg').textContent = t('loaderLoading');
    document.getElementById('init-retry').style.display = 'none';
    overlay.style.display = 'flex';

    try {
      await Promise.all([
        checkAuth().catch(() => updateAdminUI()),
        load()
      ]);

      applySettings();
      updateTranslateOrigin();

      // Set current space if not set
      if (!S.currentSpaceId && S.spaces.length) S.currentSpaceId = S.spaces[0].id;

      // Apply URL ?page= param, hash, or fall back to first page
      const urlParams = new URLSearchParams(window.location.search);
      const pageParam = urlParams.get('page');
      const hash = window.location.hash.slice(1);
      const targetPageId = pageParam || hash;
      if (targetPageId && S.pages.find(p => p.id === targetPageId)) {
        const targetPage = S.pages.find(p => p.id === targetPageId);
        S.currentSpaceId = targetPage.spaceId;
        S.currentPageId = targetPageId;
      } else {
        // Always pick first root page of current space on load
        const sp = spacePages();
        S.currentPageId = sp.find(p => !p.parentId)?.id || sp[0]?.id || null;
      }

      if (S.currentPageId) await loadPageContent(S.currentPageId);

      renderSpaces();
      renderNav();
      renderPage();

      // Hotovo — skry overlay
      overlay.style.opacity = '0';
      overlay.style.transition = 'opacity 0.2s';
      setTimeout(() => overlay.remove(), 200);

      // Show setup wizard on first run
      if (S.needsSetup) {
        setTimeout(() => openSetupWizard(), 300);
      }

    } catch(e) {
      console.error('Init failed:', e);
      document.getElementById('init-msg').textContent = t('loaderFailed');
      document.getElementById('init-retry').style.display = 'inline-block';
    }
  };

  document.getElementById('init-retry')?.addEventListener('click', tryInit);
  await tryInit();
})();

// ════════════════════════════════════════
//  DRAG & DROP — sidebar pages
// ════════════════════════════════════════
let dragSrcId = null;

function initDragDrop() {
  const tree = document.getElementById('nav-tree');
  if (!tree || !S.authed) return;

  tree.querySelectorAll('.nav-item').forEach(item => {
    const pageId = item.dataset.pageId;
    if (!pageId) return;
    item.draggable = true;

    item.addEventListener('dragstart', e => {
      dragSrcId = pageId;
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    item.addEventListener('dragend', () => {
      item.classList.remove('dragging');
      tree.querySelectorAll('.nav-item').forEach(i => {
        i.classList.remove('drag-over-above', 'drag-over-below');
      });
    });
    item.addEventListener('dragover', e => {
      e.preventDefault();
      if (dragSrcId === pageId) return;
      const rect = item.getBoundingClientRect();
      const mid = rect.top + rect.height / 2;
      tree.querySelectorAll('.nav-item').forEach(i => i.classList.remove('drag-over-above','drag-over-below'));
      item.classList.add(e.clientY < mid ? 'drag-over-above' : 'drag-over-below');
    });
    item.addEventListener('dragleave', () => {
      item.classList.remove('drag-over-above','drag-over-below');
    });
    item.addEventListener('drop', async e => {
      e.preventDefault();
      if (dragSrcId === pageId) return;
      item.classList.remove('drag-over-above','drag-over-below');

      const srcPage = S.pages.find(p => p.id === dragSrcId);
      const tgtPage = S.pages.find(p => p.id === pageId);
      if (!srcPage || !tgtPage || srcPage.spaceId !== tgtPage.spaceId) return;

      const rect = item.getBoundingClientRect();
      const insertBefore = e.clientY < rect.top + rect.height / 2;

      const siblings = S.pages
        .filter(p => p.spaceId === srcPage.spaceId && p.parentId === tgtPage.parentId)
        .sort((a,b) => a.order - b.order)
        .filter(p => p.id !== srcPage.id);

      const tgtIdx = siblings.findIndex(p => p.id === pageId);
      siblings.splice(insertBefore ? tgtIdx : tgtIdx + 1, 0, srcPage);
      srcPage.parentId = tgtPage.parentId;
      siblings.forEach((p, i) => p.order = i);

      renderNav();
      await save();
      showToast(t('toastOrderSaved'));
    });
  });
}

// ════════════════════════════════════════
//  KEYBOARD SHORTCUTS
// ════════════════════════════════════════
document.addEventListener('keydown', async (e) => {
  const meta = e.metaKey || e.ctrlKey;
  if (!meta) return;

  // Cmd+E — toggle edit/preview
  if (e.key === 'e' && S.authed && S.currentPageId) {
    e.preventDefault();
    toggleEdit();
    return;
  }
  // Cmd+S — save
  if (e.key === 's' && S.editMode) {
    e.preventDefault();
    await autoSave(false);
    showToast(t('toastSaved'));
    return;
  }
  // Cmd+K — focus search
  if (e.key === 'k') {
    e.preventDefault();
    const si = document.getElementById('search-input');
    if (si) { si.focus(); si.select(); }
    return;
  }
});

// ════════════════════════════════════════
//  SLASH COMMAND MENU
// ════════════════════════════════════════
function getSlashCommands() { return [
  { id: 'header',    label: t('blockPickerHeading'),   desc: t('blockPickerHeadingDesc'),   icon: 'fa-heading' },
  { id: 'paragraph', label: t('blockPickerText'),      desc: t('blockPickerTextDesc'),      icon: 'fa-paragraph' },
  { id: 'list',      label: t('blockPickerList'),      desc: t('blockPickerListDesc'),      icon: 'fa-list-ul' },
  { id: 'checklist', label: t('blockPickerChecklist'), desc: t('blockPickerChecklistDesc'), icon: 'fa-check-square' },
  { id: 'image',     label: t('blockPickerImage'),     desc: t('blockPickerImageDesc'),     icon: 'fa-image' },
  { id: 'video',     label: t('blockPickerVideo'),     desc: t('blockPickerVideoDesc'),     icon: 'fa-video' },
  { id: 'code',      label: t('blockPickerCode'),      desc: t('blockPickerCodeDesc'),      icon: 'fa-code' },
  { id: 'quote',     label: t('blockPickerQuote'),     desc: t('blockPickerQuoteDesc'),     icon: 'fa-quote-left' },
  { id: 'table',     label: t('blockPickerTable'),     desc: t('blockPickerTableDesc'),     icon: 'fa-table' },
  { id: 'warning',   label: t('blockPickerCallout'),   desc: t('blockPickerCalloutDesc'),   icon: 'fa-circle-info' },
  { id: 'collapse',  label: t('blockPickerCollapse'),  desc: t('blockPickerCollapseDesc'),  icon: 'fa-chevron-right' },
  { id: 'timeline',  label: t('blockPickerTimeline'),  desc: t('blockPickerTimelineDesc'),  icon: 'fa-clock-rotate-left' },
  { id: 'cards',     label: t('blockPickerCards'),     desc: t('blockPickerCardsDesc'),     icon: 'fa-table-cells' },
  { id: 'delimiter', label: t('blockPickerDelimiter'), desc: t('blockPickerDelimiterDesc'), icon: 'fa-minus' },
]; }

let slashMenu = null;
let slashQuery = '';
let slashActiveIdx = 0;
let slashAnchorBlock = null;

function openSlashMenu(x, y, query, context = null) {
  closeSlashMenu();
  slashQuery = query;
  slashActiveIdx = 0;
  slashAnchorBlock = context;

  const filtered = getSlashCommands().filter(c =>
    !query || c.label.toLowerCase().includes(query.toLowerCase()) || c.id.includes(query.toLowerCase())
  );
  if (!filtered.length) return;

  // Use the scrollable content container as anchor so menu scrolls with page
  const contentWrap = document.querySelector('.content-wrap');
  if (!contentWrap) return;
  const wrapRect = contentWrap.getBoundingClientRect();
  const scrollTop = contentWrap.scrollTop;

  slashMenu = document.createElement('div');
  slashMenu.className = 'slash-menu';
  // Convert viewport coords to content-wrap relative + add scroll offset
  slashMenu.style.left = (x - wrapRect.left) + 'px';
  slashMenu.style.top = (y - wrapRect.top + scrollTop + 4) + 'px';

  filtered.forEach((cmd, i) => {
    const item = document.createElement('div');
    item.className = 'slash-item' + (i === 0 ? ' active' : '');
    item.dataset.id = cmd.id;
    item.innerHTML = `
      <div class="slash-item-icon"><i class="fa-solid ${cmd.icon}" style="font-style:normal"></i></div>
      <div><div class="slash-item-label">${cmd.label}</div><div class="slash-item-desc">${cmd.desc}</div></div>
    `;
    item.onmousedown = (e) => { e.preventDefault(); insertSlashBlock(cmd.id); };
    slashMenu.appendChild(item);
  });

  contentWrap.style.position = 'relative';
  contentWrap.appendChild(slashMenu);
}

function closeSlashMenu(resetContext = true) {
  slashMenu?.remove();
  slashMenu = null;
  if (resetContext) slashAnchorBlock = null;
}

function updateSlashActive(delta) {
  if (!slashMenu) return;
  const items = slashMenu.querySelectorAll('.slash-item');
  items[slashActiveIdx]?.classList.remove('active');
  slashActiveIdx = (slashActiveIdx + delta + items.length) % items.length;
  items[slashActiveIdx]?.classList.add('active');
  items[slashActiveIdx]?.scrollIntoView({ block: 'nearest' });
}

async function insertSlashBlock(type) {
  const context = slashAnchorBlock;
  closeSlashMenu(false);
  if (!editor) return;

  const blockMap = {
    header:    { type: 'header',    data: { text: '', level: 1 } },
    paragraph: { type: 'paragraph', data: { text: '' } },
    list:      { type: 'list',      data: { style: 'unordered', items: [{ content: '', items: [] }] } },
    checklist: { type: 'checklist', data: { items: [{ text: '', checked: false }] } },
    image:     { type: 'image',     data: {} },
    video:     { type: 'video',     data: {} },
    code:      { type: 'code',      data: { code: '' } },
    quote:     { type: 'quote',     data: { text: '', caption: '' } },
    table:     { type: 'table',     data: { withHeadings: false, content: [['',''],['',' ']] } },
    warning:   { type: 'warning',   data: { type: 'info', title: '', message: '' } },
    collapse:  { type: 'collapse',  data: { title: '', body: '' } },
    timeline:  { type: 'timeline',  data: { numbered: false, items: [{ date: '', title: '', desc: '' }] } },
    cards:     { type: 'cards',     data: {} },
    delimiter: { type: 'delimiter', data: {} },
  };

  const block = blockMap[type];
  if (!block) return;

  try {
    const currentIdx = editor.blocks.getCurrentBlockIndex();
    let focusIdx = Math.min(Math.max(currentIdx, 0), editor.blocks.getBlocksCount());

    if (context?.mode === 'replace') {
      focusIdx = Math.min(Math.max(context.index ?? currentIdx, 0), Math.max(editor.blocks.getBlocksCount() - 1, 0));
      editor.blocks.insert(block.type, block.data, undefined, focusIdx, true, true);
    } else {
      focusIdx = Math.min(Math.max(context?.index ?? currentIdx + 1, 0), editor.blocks.getBlocksCount());
      editor.blocks.insert(block.type, block.data, undefined, focusIdx, true);
    }

    requestAnimationFrame(() => editor?.caret?.setToBlock?.(focusIdx));
    markDirty();
    scheduleUndoSnapshot();
  } catch(e) {}

  slashAnchorBlock = null;
}

// Listen for slash on editor
document.addEventListener('keydown', (e) => {
  if (!S.editMode || !editor) return;

  if (slashMenu) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      updateSlashActive(1);
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      updateSlashActive(-1);
      return;
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      const active = slashMenu?.querySelector('.slash-item.active');
      if (active) insertSlashBlock(active.dataset.id);
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      closeSlashMenu();
      return;
    }
  }
}, true);

document.addEventListener('input', (e) => {
  if (!S.editMode || !editor) return;
  const target = e.target;
  if (!target.closest('#editor')) return;
  const text = target.textContent || target.innerText || '';
  const slashIdx = text.lastIndexOf('/');

  if (slashIdx !== -1) {
    const query = text.slice(slashIdx + 1);
    if (!/\s/.test(query)) {
      const range = window.getSelection()?.getRangeAt(0);
      if (range) {
        const rect = range.getBoundingClientRect();
        openSlashMenu(rect.left, rect.bottom, query, {
          mode: 'replace',
          index: editor.blocks.getCurrentBlockIndex(),
        });
        return;
      }
    }
  }
  closeSlashMenu();
}, true);

document.addEventListener('click', (e) => {
  if (!e.target.closest('.slash-menu')) closeSlashMenu();
});

// Intercept EditorJS + button — open our slash menu instead of native toolbox
document.addEventListener('click', (e) => {
  if (!S.editMode || !editor) return;
  const plusBtn = e.target.closest('.ce-toolbar__plus');
  if (!plusBtn) return;
  e.preventDefault();
  e.stopImmediatePropagation();

  const rect = plusBtn.getBoundingClientRect();
  const contentWrap = document.querySelector('.content-wrap');
  if (!contentWrap) return;
  const wrapRect = contentWrap.getBoundingClientRect();
  const scrollTop = contentWrap.scrollTop;

  // Open our slash menu aligned to the + button
  openSlashMenu(rect.left, rect.bottom, '', {
    mode: 'insert',
    index: editor.blocks.getCurrentBlockIndex() + 1,
  });
}, true);

// ════════════════════════════════════════
//  PAGE TRANSITION FADE
// ════════════════════════════════════════
const _origNavigateTo = navigateTo;
navigateTo = async function(pageId) {
  const view = document.getElementById('page-view');
  if (view && pageId !== S.currentPageId) {
    view.classList.add('fading');
    await new Promise(r => setTimeout(r, 120));
  }
  await _origNavigateTo(pageId);
  if (view) {
    view.classList.remove('fading');
  }
};

// ════════════════════════════════════════
//  EMPTY STATE
// ════════════════════════════════════════
const _origRenderPage = renderPage;
renderPage = function() {
  _origRenderPage();
  // Inject empty state if no blocks
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  const blocks = page.content?.blocks || [];
  if (!blocks.length && !S.editMode) {
    const editorEl = document.getElementById('editor');
    if (editorEl) {
      editorEl.innerHTML = `
        <div class="page-empty-state">
          <i class="fa-regular fa-file-lines"></i>
          <p>${t('pageEmpty')}</p>
          ${S.authed ? `<button class="btn btn-primary" onclick="toggleEdit()"><i class="fa-solid fa-plus"></i> ${t('pageAddContent')}</button>` : ''}
        </div>`;
    }
  }
};

// ════════════════════════════════════════
//  CODE COPY BUTTONS
// ════════════════════════════════════════
function injectCodeCopyButtons() {
  document.querySelectorAll('.ce-code').forEach(block => {
    if (block.querySelector('.code-copy-btn')) return;
    const btn = document.createElement('button');
    btn.className = 'code-copy-btn';
    btn.innerHTML = '<i class="fa-regular fa-copy" style="font-style:normal"></i> Copy';
    btn.onclick = (e) => {
      e.stopPropagation();
      const textarea = block.querySelector('.ce-code__textarea');
      if (!textarea) return;
      navigator.clipboard.writeText(textarea.value || textarea.textContent).then(() => {
        btn.innerHTML = '<i class="fa-solid fa-check" style="font-style:normal;color:#22c55e"></i> Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fa-regular fa-copy" style="font-style:normal"></i> Copy'; }, 1500);
      });
    };
    block.appendChild(btn);
  });
  // Syntax highlighting in read mode
  if (!S.editMode && typeof Prism !== 'undefined') highlightCodeBlocks();
}

function highlightCodeBlocks() {
  document.querySelectorAll('.ce-code').forEach(block => {
    if (block.querySelector('.code-highlighted')) return;
    const textarea = block.querySelector('.ce-code__textarea');
    if (!textarea) return;
    const code = textarea.value || textarea.textContent || '';
    if (!code.trim()) return;

    // Auto-detect language from first line
    const lang = detectCodeLanguage(code);

    const overlay = document.createElement('div');
    overlay.className = 'code-highlighted';
    const pre = document.createElement('pre');
    pre.className = `language-${lang}`;
    const codeEl = document.createElement('code');
    codeEl.className = `language-${lang}`;
    codeEl.textContent = code;
    pre.appendChild(codeEl);
    overlay.appendChild(pre);
    block.appendChild(overlay);

    // Hide textarea text (keep it for copy), show highlighted overlay
    textarea.style.color = 'transparent';
    textarea.style.caretColor = 'var(--text)';

    try { Prism.highlightElement(codeEl); } catch(e) {}
  });
}

function detectCodeLanguage(code) {
  const first = code.trim().split('\n')[0];
  // Common patterns
  if (/^(import |from .+ import|def |class .*:|if __name__)/.test(first)) return 'python';
  if (/^(const |let |var |function |import .* from|export |=>|async )/.test(first)) return 'javascript';
  if (/^(interface |type .*=|const .*:.*=)/.test(first)) return 'typescript';
  if (/^(<\?php|namespace |use |function .*\(.*\$)/.test(first)) return 'php';
  if (/^(<!DOCTYPE|<html|<div|<script|<link|<meta)/.test(first)) return 'html';
  if (/^\{|\[/.test(first) && /[}\]]$/.test(code.trim())) return 'json';
  if (/^(SELECT |INSERT |UPDATE |DELETE |CREATE |ALTER |DROP )/i.test(first)) return 'sql';
  if (/^(#!\/bin\/(bash|sh)|^\$ |^(curl|wget|npm|pip|git|docker|cd |ls |mkdir|chmod) )/.test(first)) return 'bash';
  if (/^(# |## |### |\*\*|!\[|```|\[.*\]\()/.test(first)) return 'markdown';
  if (/^(apiVersion:|kind:|metadata:|spec:|- name:)/.test(first)) return 'yaml';
  if (/^(FROM |RUN |COPY |CMD |ENTRYPOINT |WORKDIR |EXPOSE )/.test(first)) return 'docker';
  if (/^(package |import "| func | fmt\.)/.test(first)) return 'go';
  if (/^(use |fn |let mut |pub |struct |impl |mod )/.test(first)) return 'rust';
  if (/^\.([\w-]+)\s*\{|^#[\w-]+\s*\{|^@media|^:root/.test(first)) return 'css';
  if (/^(GET |POST |PUT |DELETE |PATCH )/.test(first)) return 'http';
  return 'markup'; // fallback
}

// Re-inject after editor changes (new code blocks added)
const _origMarkDirty = markDirty;
markDirty = function() {
  _origMarkDirty();
  setTimeout(injectCodeCopyButtons, 300);
};

// ════════════════════════════════════════
//  MOBILE SIDEBAR
// ════════════════════════════════════════
function toggleMobileSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('mobile-sidebar-overlay');
  const isOpen = sidebar.classList.contains('mobile-open');
  sidebar.classList.toggle('mobile-open', !isOpen);
  overlay.classList.toggle('open', !isOpen);
}

// Close mobile sidebar on navigation
function closeMobileSidebar() {
  document.getElementById('sidebar')?.classList.remove('mobile-open');
  document.getElementById('mobile-sidebar-overlay')?.classList.remove('open');
}

// Handle browser back/forward
window.addEventListener('popstate', (e) => {
  const params = new URLSearchParams(window.location.search);
  const pageId = params.get('page') || window.location.hash.slice(1);
  if (pageId && S.pages.find(p => p.id === pageId)) {
    S.currentPageId = pageId;
    const pg = S.pages.find(p => p.id === pageId);
    if (pg && pg.spaceId !== S.currentSpaceId) {
      S.currentSpaceId = pg.spaceId;
      renderSpaces();
    }
    loadPageContent(pageId).then(() => { renderNav(); renderPage(); });
  }
});

// Scroll to top button visibility
window.addEventListener('scroll', () => {
  const btn = document.getElementById('scroll-top-btn');
  if (btn) btn.classList.toggle('show', window.scrollY > 200);
}, { passive: true });

// ════════════════════════════════════════
//  READING MODE
// ════════════════════════════════════════
function toggleReadingMode() {
  document.body.classList.toggle('reading-mode');
  const btn = document.getElementById('reading-mode-btn');
  const active = document.body.classList.contains('reading-mode');
  btn.title = t('btnReadingMode');
}

// ════════════════════════════════════════
//  SHARE PAGE
// ════════════════════════════════════════
function sharePage() {
  const base = window.location.origin + window.location.pathname.replace(/index\.php$/, '');
  const pageId = S.currentPageId || '';
  const url = pageId ? `${base}?page=${encodeURIComponent(pageId)}` : base;
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.querySelector('.toc-share-btn');
    const label = document.getElementById('toc-share-label');
    const icon = btn?.querySelector('i');
    if (btn && label) {
      btn.classList.add('copied');
      if (icon) icon.className = 'fa-solid fa-check';
      label.textContent = t('tocShareCopied');
      setTimeout(() => {
        btn.classList.remove('copied');
        if (icon) icon.className = 'fa-solid fa-link';
        label.textContent = t('tocShare');
      }, 2000);
    }
  });
}

// Load page from URL hash on init
function checkUrlHash() {
  const hash = window.location.hash.slice(1);
  if (hash && S.pages.find(p => p.id === hash)) {
    S.currentPageId = hash;
  }
}

// ════════════════════════════════════════
//  KEYBOARD SHORTCUTS OVERLAY
// ════════════════════════════════════════
function toggleShortcuts() {
  document.getElementById('shortcuts-overlay').classList.toggle('open');
}
function closeShortcuts() {
  document.getElementById('shortcuts-overlay').classList.remove('open');
}

// ════════════════════════════════════════
//  HOVER PREVIEW
// ════════════════════════════════════════
let hoverPreviewTimer = null;
const hoverPreviewEl = document.getElementById('nav-hover-preview');

function showHoverPreview(pageId, anchorEl) {
  clearTimeout(hoverPreviewTimer);
  hoverPreviewTimer = setTimeout(() => {
    const page = S.pages.find(p => p.id === pageId);
    if (!page || pageId === S.currentPageId) return;

    document.getElementById('nhp-title').textContent = page.title || t('pageUntitled');
    document.getElementById('nhp-desc').textContent = page.subtitle || '';

    // Cover
    const coverEl = document.getElementById('nhp-cover');
    if (page.cover) {
      coverEl.style.display = 'block';
      if (page.cover.type === 'color') {
        coverEl.style.background = page.cover.value;
      } else {
        coverEl.style.background = `url(${page.cover.value}) center/cover no-repeat`;
      }
    } else {
      coverEl.style.display = 'none';
    }

    const rect = anchorEl.getBoundingClientRect();
    hoverPreviewEl.style.top = (rect.top + rect.height / 2 - 40) + 'px';
    hoverPreviewEl.style.left = (rect.right + 10) + 'px';
    hoverPreviewEl.classList.add('show');
  }, 400);
}

function hideHoverPreview() {
  clearTimeout(hoverPreviewTimer);
  hoverPreviewEl.classList.remove('show');
}

// Hook into nav item rendering
const _origRenderNavItem = renderNavItem;
renderNavItem = function(page, container, depth, allPages) {
  _origRenderNavItem(page, container, depth, allPages);
  // Find the item we just added and attach hover listeners
  const items = container.querySelectorAll(`.nav-item[data-page-id="${page.id}"]`);
  const item = items[items.length - 1];
  if (item) {
    item.addEventListener('mouseenter', () => showHoverPreview(page.id, item));
    item.addEventListener('mouseleave', hideHoverPreview);
  }
};

// ════════════════════════════════════════
//  ENHANCED SEARCH WITH CONTENT + HIGHLIGHT
// ════════════════════════════════════════
function highlight(text, q) {
  if (!q) return esc(text);
  const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return esc(text).replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>');
}

function getPageTextSnippet(page, q) {
  const blocks = page.content?.blocks || [];
  for (const b of blocks) {
    const txt = (b.data?.text || b.data?.caption || b.data?.title || '').replace(/<[^>]+>/g, '').trim();
    if (txt.toLowerCase().includes(q.toLowerCase())) {
      const idx = txt.toLowerCase().indexOf(q.toLowerCase());
      const start = Math.max(0, idx - 30);
      const snippet = (start > 0 ? '…' : '') + txt.slice(start, idx + 80) + (txt.length > idx + 80 ? '…' : '');
      return highlight(snippet, q);
    }
    // Also check list items
    if (b.data?.items) {
      for (const item of b.data.items) {
        const itemText = (typeof item === 'string' ? item : item.content || '').replace(/<[^>]+>/g, '').trim();
        if (itemText.toLowerCase().includes(q.toLowerCase())) {
          return highlight(itemText.slice(0, 100), q);
        }
      }
    }
  }
  return '';
}

// Override handleSearch with enhanced version
handleSearch = function(q) {
  const dd = document.getElementById('search-dd');
  if (!q.trim()) { dd.innerHTML = ''; dd.classList.remove('open'); return; }

  const ql = q.toLowerCase();
  const results = S.pages.filter(p =>
    p.title.toLowerCase().includes(ql) ||
    (p.subtitle || '').toLowerCase().includes(ql) ||
    (p.content?.blocks || []).some(b => {
      const txt = (b.data?.text || b.data?.caption || b.data?.title || '').replace(/<[^>]+>/g, '');
      if (txt.toLowerCase().includes(ql)) return true;
      if (b.data?.items) return b.data.items.some(i => (typeof i === 'string' ? i : i.content || '').toLowerCase().includes(ql));
      return false;
    })
  ).slice(0, 8);

  if (!results.length) {
    dd.innerHTML = `<div class="search-empty"><i class="fa-solid fa-magnifying-glass" style="margin-right:6px"></i>${t('searchNoResults')}</div>`;
  } else {
    dd.innerHTML = results.map(p => {
      const snippet = getPageTextSnippet(p, q);
      const titleHl = highlight(p.title, q);
      const subtitleHl = p.subtitle ? highlight(p.subtitle.slice(0, 60), q) : '';
      return `
        <div class="search-result-item" onclick="selectSearch('${p.id}')">
          <i class="fa-solid ${p.icon || 'fa-file'}"></i>
          <div style="min-width:0;flex:1">
            <div class="search-result-title">${titleHl}</div>
            ${subtitleHl ? `<div class="search-result-path">${subtitleHl}</div>` : ''}
            ${snippet ? `<div class="search-result-snippet">${snippet}</div>` : ''}
          </div>
        </div>`;
    }).join('');
  }
  dd.classList.add('open');
};

// ════════════════════════════════════════
//  EXTENDED KEYBOARD SHORTCUTS
// ════════════════════════════════════════
document.addEventListener('keydown', e => {
  const meta = e.metaKey || e.ctrlKey;

  // ? = shortcuts overlay (only when not typing)
  if (e.key === '?' && !e.target.closest('input, textarea, [contenteditable]')) {
    e.preventDefault();
    toggleShortcuts();
  }

  // Cmd+R = reading mode
  if (meta && e.key === 'r') {
    e.preventDefault();
    toggleReadingMode();
  }

  // Cmd+Shift+C = share
  if (meta && e.shiftKey && e.key === 'c') {
    e.preventDefault();
    sharePage();
  }

  // Cmd+/ = slash menu anywhere in editor
  if (meta && e.key === '/') {
    e.preventDefault();
    if (S.editMode && editor) {
      const toolbar = document.querySelector('.ce-toolbar__plus');
      if (toolbar) {
        const rect = toolbar.getBoundingClientRect();
        openSlashMenu(rect.left, rect.bottom, '');
      }
    }
  }

  // Escape closes shortcuts overlay too
  if (e.key === 'Escape') closeShortcuts();

  // ←→ arrow keys for prev/next page (only when not typing)
  if (!e.target.closest('input, textarea, [contenteditable], select') && !S.editMode) {
    if (e.key === 'ArrowLeft') { navigatePrevNext(-1); }
    if (e.key === 'ArrowRight') { navigatePrevNext(1); }
  }
});

// Navigate to prev (-1) or next (1) page
function navigatePrevNext(dir) {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  function flatDFS(parentId) {
    return S.pages
      .filter(p => p.spaceId === S.currentSpaceId && p.parentId === (parentId || null))
      .sort((a, b) => a.order - b.order)
      .flatMap(p => [p, ...flatDFS(p.id)]);
  }
  const ordered = flatDFS(null);
  const idx = ordered.findIndex(p => p.id === page.id);
  const target = ordered[idx + dir];
  if (target) navigateTo(target.id);
}

// Handle URL on page load
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const pageId = params.get('page') || window.location.hash.slice(1);
  if (pageId) {
    window._initialPageId = pageId;
  }
});
</script>
<!-- Callout inline toolbar (singleton) -->
<div class="callout-toolbar" id="callout-toolbar">
  <button class="ct-btn" data-cmd="bold" data-i18n-attr="title" data-i18n="ctBold" title="Bold"><b>B</b></button>
  <button class="ct-btn" data-cmd="italic" data-i18n-attr="title" data-i18n="ctItalic" title="Italic"><i>I</i></button>
  <button class="ct-btn" data-cmd="underline" data-i18n-attr="title" data-i18n="ctUnderline" title="Underline"><u>U</u></button>
  <div class="ct-sep"></div>
  <button class="ct-btn" id="ct-link-btn" data-i18n-attr="title" data-i18n="ctLink" title="Link"><i class="fa-solid fa-link"></i></button>
  <button class="ct-btn" data-cmd="removeFormat" data-i18n-attr="title" data-i18n="ctRemoveFormat" title="Remove formatting"><i class="fa-solid fa-text-slash"></i></button>
</div>
<!-- Link input popup for callout -->
<div class="callout-toolbar" id="callout-link-bar" style="gap:4px;padding:6px 8px;">
  <i class="fa-solid fa-link" style="color:var(--text3);font-size:12px;margin-right:2px;"></i>
  <input id="ct-link-input" type="text" placeholder="https://..." style="border:none;outline:none;background:none;font:13px var(--font);color:var(--text);width:220px;">
  <button class="ct-btn" id="ct-link-ok" data-i18n-attr="title" data-i18n="btnSaveChanges" title="Confirm"><i class="fa-solid fa-check" style="color:#16a34a;"></i></button>
  <button class="ct-btn" id="ct-link-remove" data-i18n-attr="title" data-i18n="ctLinkRemove" title="Remove link"><i class="fa-solid fa-link-slash" style="color:#ef4444;"></i></button>
</div>

<div class="confirm-overlay" id="confirm-overlay">
  <div class="confirm-box" id="confirm-box">
    <div class="confirm-icon danger" id="confirm-icon"><i class="fa-solid fa-trash"></i></div>
    <div class="confirm-title" id="confirm-title"></div>
    <div class="confirm-msg" id="confirm-msg"></div>
    <div class="confirm-actions">
      <button class="btn btn-ghost" id="confirm-cancel-btn">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok-btn">Delete</button>
    </div>
  </div>
</div>

<script>
// ── Callout inline toolbar ──────────────────────────────────
(function() {
  const toolbar   = document.getElementById('callout-toolbar');
  const linkBar   = document.getElementById('callout-link-bar');
  const linkInput = document.getElementById('ct-link-input');
  let savedRange  = null;

  function isInCallout(node) {
    if (!node) return null;
    const el = node instanceof Text ? node.parentElement : node;
    return el?.closest?.('.callout-block [contenteditable]') || null;
  }

  function positionBar(bar) {
    bar.style.visibility = 'hidden';
    bar.style.display = 'flex';
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    if (!rect.width && !rect.height) return;
    const bw = bar.offsetWidth;
    const bh = bar.offsetHeight;
    let left = rect.left + rect.width / 2 - bw / 2;
    let top  = rect.top + window.scrollY - bh - 8;
    left = Math.max(8, Math.min(left, window.innerWidth - bw - 8));
    bar.style.left = left + 'px';
    bar.style.top  = top + 'px';
    bar.style.visibility = 'visible';
  }

  function showToolbar() {
    linkBar.style.display = 'none';
    positionBar(toolbar);
  }

  function hideAll() {
    toolbar.style.display = 'none';
    linkBar.style.display = 'none';
  }

  function saveRange() {
    const sel = window.getSelection();
    if (sel.rangeCount) savedRange = sel.getRangeAt(0).cloneRange();
  }

  function restoreRange() {
    if (!savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(savedRange);
  }

  // Show on mouseup inside callout
  document.addEventListener('mouseup', () => {
    setTimeout(() => {
      const sel = window.getSelection();
      if (!sel || sel.isCollapsed || !sel.rangeCount) return;
      if (isInCallout(sel.anchorNode)) showToolbar();
    }, 10);
  });

  // Also show on keyboard selection inside callout
  document.addEventListener('keyup', (e) => {
    if (!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(e.key) && !e.shiftKey) return;
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed || !sel.rangeCount) return;
    if (isInCallout(sel.anchorNode)) showToolbar();
  });

  // Hide when clicking outside both bars
  document.addEventListener('mousedown', e => {
    if (toolbar.contains(e.target) || linkBar.contains(e.target)) return;
    hideAll();
  });

  // Format buttons
  toolbar.querySelectorAll('[data-cmd]').forEach(btn => {
    btn.addEventListener('mousedown', e => {
      e.preventDefault();
      document.execCommand(btn.dataset.cmd, false, null);
      const sel = window.getSelection();
      const activeEl = isInCallout(sel?.anchorNode);
      if (activeEl) activeEl.dispatchEvent(new Event('input'));
    });
  });

  // Link button — switch to link bar
  document.getElementById('ct-link-btn').addEventListener('mousedown', e => {
    e.preventDefault();
    saveRange();
    toolbar.style.display = 'none';
    const sel = window.getSelection();
    const anchor = sel.anchorNode?.parentElement?.closest('a');
    linkInput.value = anchor ? anchor.href : '';
    positionBar(linkBar);
    setTimeout(() => { linkInput.focus(); linkInput.select(); }, 0);
  });

  function applyLink() {
    restoreRange();
    const url = linkInput.value.trim();
    if (url) {
      const fullUrl = url.match(/^https?:\/\//) ? url : 'https://' + url;
      document.execCommand('createLink', false, fullUrl);
      const sel = window.getSelection();
      const a = sel?.anchorNode?.parentElement?.closest('a');
      if (a) a.target = '_blank';
      const activeEl = isInCallout(sel?.anchorNode);
      if (activeEl) activeEl.dispatchEvent(new Event('input'));
    }
    hideAll();
  }

  document.getElementById('ct-link-ok').addEventListener('mousedown', e => { e.preventDefault(); applyLink(); });
  linkInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); applyLink(); }
    if (e.key === 'Escape') { hideAll(); }
  });

  document.getElementById('ct-link-remove').addEventListener('mousedown', e => {
    e.preventDefault();
    restoreRange();
    document.execCommand('unlink', false, null);
    const sel = window.getSelection();
    const activeEl = isInCallout(sel?.anchorNode);
    if (activeEl) activeEl.dispatchEvent(new Event('input'));
    hideAll();
  });

  // Init hidden
  hideAll();
})();
// ────────────────────────────────────────────────────────────

// Easter egg — console branding
(function() {
  const s1 = 'font-size:18px;font-weight:700;color:#f97316;font-family:monospace;';
  const s2 = 'font-size:12px;color:#888;font-family:monospace;';
  const s3 = 'font-size:12px;color:#f97316;font-family:monospace;';
  console.log('%cWebstudio Docs', s1);
  console.log('%cOpen-source self-hosted documentation platform', s2);
  console.log('%c⭐ https://github.com/webstudio-ltd/docs', s3);
  console.log('%c🌐 https://webstudio.ltd', s3);
  console.log('%cBuilt with ♥ — free forever, no monthly fees.', s2);
})();
</script>
</body>
</html>
