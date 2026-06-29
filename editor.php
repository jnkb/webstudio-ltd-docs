<?php
require __DIR__ . '/access_guard.php';

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
            foreach (glob($pagesDir . '/*.json') ?: [] as $f) {
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
<script src="https://cdn.jsdelivr.net/npm/@editorjs/inline-code@1.5.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/marker@1.4.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/checklist@1.6.0"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/table@2.3.0"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js" defer></script>
<link rel="stylesheet" href="assets/app.css">
<script src="assets/i18n.js"></script>
<script src="assets/shared.js"></script>
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
      <i class="fa-solid fa-bolt" id="footer-icon"></i>
      <span id="footer-text-display"></span>
      <span id="footer-tail-display" class="footer-tail-display"></span>
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
      <div id="toc-feedback-box">
        <div class="toc-sep"></div>
        <div class="toc-feedback-label" data-i18n="tocFeedback">Was this helpful?</div>
        <div class="toc-feedback-btns">
            <button class="fb-btn" type="button" data-rating="-1" data-icon="👎" onclick="react(this.dataset.rating, this.dataset.icon)" data-i18n-attr="title" data-i18n="ratingNegative" title="Not helpful"><i class="fa-regular fa-face-frown"></i></button>
            <button class="fb-btn" type="button" data-rating="0" data-icon="😐" onclick="react(this.dataset.rating, this.dataset.icon)" data-i18n-attr="title" data-i18n="ratingNeutral" title="Neutral"><i class="fa-regular fa-face-meh"></i></button>
            <button class="fb-btn" type="button" data-rating="1" data-icon="👍" onclick="react(this.dataset.rating, this.dataset.icon)" data-i18n-attr="title" data-i18n="ratingPositive" title="Helpful"><i class="fa-regular fa-face-smile"></i></button>
        </div>
      </div>
      <div id="toc-admin-rating-slot"></div>
        <div id="toc-share-section">
          <div class="toc-sep"></div>
          <div class="toc-share-box">
            <div class="toc-feedback-label" data-i18n="tocShare">Share</div>
            <button class="toc-share-btn" onclick="sharePage()">
              <i class="fa-solid fa-link"></i>
              <span id="toc-share-label" data-i18n="tocShare">Share</span>
            </button>
          </div>
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
    <div class="shortcut-row admin-only" style="display:none"><span class="shortcut-label" data-i18n="shortcutEdit">Edit / Preview</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>E</kbd></span></div>
    <div class="shortcut-row admin-only" style="display:none"><span class="shortcut-label" data-i18n="shortcutSave">Save</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>S</kbd></span></div>
    <div class="shortcut-row admin-only" style="display:none"><span class="shortcut-label" data-i18n="shortcutUndo">Undo</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>Z</kbd></span></div>
    <div class="shortcut-row admin-only" style="display:none"><span class="shortcut-label" data-i18n="shortcutRedo">Redo</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>⇧</kbd><kbd>Z</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutSearch">Search</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>K</kbd></span></div>
    <div class="shortcut-row admin-only" style="display:none"><span class="shortcut-label" data-i18n="shortcutSlashMenu">Slash menu</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>/</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutReadingMode">Reading mode</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>R</kbd></span></div>
    <div class="shortcut-row" id="shortcut-share-row"><span class="shortcut-label" data-i18n="shortcutShare">Share page</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>⇧</kbd><kbd>C</kbd></span></div>
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
      <input class="field-input" type="text" id="new-title" data-i18n-ph="modalAddPageName" placeholder="Page title" oninput="updateNewPageIdHint()" onkeydown="if(event.key==='Enter')confirmAddPage()">
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddIdLabel">Page ID (optional)</label>
      <input class="field-input" type="text" id="new-id" data-i18n-ph="modalAddIdPlaceholder" placeholder="Auto from title" oninput="updateNewPageIdHint()" onkeydown="if(event.key==='Enter')confirmAddPage()">
      <div id="new-id-hint" data-i18n="modalAddIdHint" style="font-size:11px;color:var(--text3);margin-top:4px;">Leave empty to use the title</div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddIcon">Icon (e.g. fa-file)</label>
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
      <label data-i18n="modalAddSectionLabel">Section (optional)</label>
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
      <label data-i18n="modalAddIcon">Icon (e.g. fa-file)</label>
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
      <label data-i18n="modalEditIdLabel">Page ID (URL)</label>
      <input class="field-input" type="text" id="page-edit-id" data-i18n-ph="modalAddIdPlaceholder" placeholder="Auto from title" oninput="updatePageEditIdHint()" onkeydown="if(event.key==='Enter')confirmPageEdit()">
      <div id="page-edit-id-hint" style="font-size:11px;color:var(--text3);margin-top:4px;"></div>
      <div data-i18n="modalEditIdWarn" style="font-size:11px;color:var(--text4);margin-top:2px;">Existing links to this page won't be updated automatically.</div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalEditSubtitle">Description (optional)</label>
      <input class="field-input" type="text" id="page-edit-subtitle" data-i18n-ph="modalEditSubtitlePlaceholder" placeholder="Short page description">
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddIcon">Icon (e.g. fa-file)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input class="field-input" type="text" id="page-edit-icon" placeholder="fa-file" oninput="previewPageEditIcon(this.value)" style="flex:1">
        <div id="page-edit-icon-preview" style="width:34px;height:34px;background:var(--bg3);border:1px solid var(--border2);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:14px;flex-shrink:0"><i class="fa-solid fa-file"></i></div>
      </div>
      <div style="margin-top:8px">
        <div class="icon-grid" id="page-edit-icon-grid"></div>
      </div>
    </div>
    <div class="modal-field">
      <label data-i18n="modalAddSectionLabel">Section (optional)</label>
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
          <input class="field-input" type="text" id="footer-input" data-i18n-ph="settingsFooterTextPh" placeholder="Powered by <a href='https://example.com'>Docs</a>" oninput="updateFooter(this.value)" style="margin-top:8px;width:100%">
        </div>
      </div>
      <div class="settings-row">
        <div style="flex:1">
          <div class="settings-row-label" data-i18n="settingsFooterTail">Footer secondary content</div>
          <div class="settings-row-sub" data-i18n="settingsFooterTailSub">Shown on the right side</div>
          <input class="field-input" type="text" id="footer-tail-input" data-i18n-ph="settingsFooterTailPh" placeholder="<a href='https://webstudio.ltd'>webstudio.ltd</a>" oninput="updateFooterTail(this.value)" style="margin-top:8px;width:100%">
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
      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsHideSingleSpaceTabs">Hide space tabs for one space</div>
          <div class="settings-row-sub" data-i18n="settingsHideSingleSpaceTabsSub">Hide the top space switcher when only one space exists</div>
        </div>
        <button class="toggle" id="hide-single-space-tabs-toggle" onclick="toggleHideSingleSpaceTabs()"></button>
      </div>
      <div class="settings-row">
        <div>
          <div class="settings-row-label" data-i18n="settingsShareSection">Share section</div>
          <div class="settings-row-sub" data-i18n="settingsShareSectionSub">Show the share block in the page sidebar</div>
        </div>
        <button class="toggle on" id="share-section-toggle" onclick="toggleShareSectionAvailability()"></button>
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
          <div class="settings-row-label" data-i18n="settingsChangePassword">Change password</div>
          <div class="settings-row-sub" data-i18n="settingsChangePasswordSub">Update your admin password</div>
        </div>
        <button class="btn btn-ghost" onclick="openChangePassword()" style="font-size:12px">
          <i class="fa-solid fa-key"></i> <span data-i18n="btnChangePassword">Change</span>
        </button>
      </div>
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
    <div class="auth-sub" id="auth-sub" data-i18n="authLoginSubtitle">Enter your password to access admin mode.</div>
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

<!-- CHANGE PASSWORD OVERLAY -->
<div class="auth-overlay" id="change-pw-overlay">
  <div class="setup-box">
    <div class="setup-icon"><i class="fa-solid fa-key"></i></div>
    <div class="setup-title" data-i18n="changePwTitle">Change password</div>
    <div class="setup-sub" data-i18n="changePwSubtitle">Enter your current password and choose a new one.</div>

    <div class="setup-field">
      <label data-i18n="changePwCurrent">Current password</label>
      <div class="setup-pw-wrap">
        <input type="password" id="change-pw-current" class="field-input" autocomplete="current-password"
          onkeydown="if(event.key==='Enter')document.getElementById('change-pw-new').focus()">
        <button class="pw-toggle" onclick="toggleSetupVis('change-pw-current', this)" type="button">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    </div>

    <div class="setup-field">
      <label data-i18n="changePwNew">New password</label>
      <div class="setup-pw-wrap">
        <input type="password" id="change-pw-new" class="field-input" autocomplete="new-password"
          oninput="validateChangePassword()" onkeydown="if(event.key==='Enter')document.getElementById('change-pw-confirm').focus()">
        <button class="pw-toggle" onclick="toggleSetupVis('change-pw-new', this)" type="button">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    </div>

    <div class="setup-field">
      <label data-i18n="changePwConfirm">Confirm new password</label>
      <div class="setup-pw-wrap">
        <input type="password" id="change-pw-confirm" class="field-input" autocomplete="new-password"
          oninput="validateChangePassword()" onkeydown="if(event.key==='Enter')submitChangePassword()">
        <button class="pw-toggle" onclick="toggleSetupVis('change-pw-confirm', this)" type="button">
          <i class="fa-solid fa-eye"></i>
        </button>
      </div>
    </div>

    <div class="setup-rules" id="change-pw-rules">
      <div class="setup-rule fail" id="cpw-rule-length"><i class="fa-solid fa-circle"></i> <span data-i18n="setupMinLength">At least 8 characters</span></div>
      <div class="setup-rule fail" id="cpw-rule-upper"><i class="fa-solid fa-circle"></i> <span data-i18n="setupUppercase">Uppercase letter</span></div>
      <div class="setup-rule fail" id="cpw-rule-lower"><i class="fa-solid fa-circle"></i> <span data-i18n="setupLowercase">Lowercase letter</span></div>
      <div class="setup-rule fail" id="cpw-rule-number"><i class="fa-solid fa-circle"></i> <span data-i18n="setupNumber">Number</span></div>
      <div class="setup-rule fail" id="cpw-rule-special"><i class="fa-solid fa-circle"></i> <span data-i18n="setupSpecial">Special character (!@#$...)</span></div>
      <div class="setup-rule fail" id="cpw-rule-match"><i class="fa-solid fa-circle"></i> <span data-i18n="setupMatch">Passwords match</span></div>
    </div>

    <div class="setup-error" id="change-pw-error"></div>

    <div class="auth-actions">
      <button class="btn btn-ghost" onclick="closeChangePassword()" data-i18n="btnCancel">Cancel</button>
      <button class="btn btn-primary" id="change-pw-btn" onclick="submitChangePassword()" disabled>
        <i class="fa-solid fa-key"></i> <span data-i18n="changePwBtn">Update password</span>
      </button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <div class="toast-dot"></div>
  <span id="toast-text">Saved</span>
</div>

// ════ SCRIPTS ════ -->
<script>
// t(), applyTranslations(), DEFAULT_ACCENT, DEFAULT_FOOTER_TEXT_HTML,
// DEFAULT_FOOTER_TAIL_HTML, FEEDBACK_STORAGE_KEY, FEEDBACK_VALUE_MAP,
// FEEDBACK_ICON_BY_VALUE, EMPTY_RATING_STATS, LANG_META are in assets/shared.js


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
    footerText: DEFAULT_FOOTER_TEXT_HTML,
    footerTailHtml: DEFAULT_FOOTER_TAIL_HTML,
    logoDataUrl: null,
    faviconDataUrl: null,
    tabTitle: 'Docs',
    lang: DEFAULT_INTERFACE_LANG,
    hideSingleSpaceTabs: false,
    shareSectionEnabled: true,
    translateEnabled: true,
  }
};

let editor = null;
let saveTimer = null;
let iconPreviewTimeout = null;
let feedbackSaving = false;

// FEEDBACK_STORAGE_KEY, FEEDBACK_VALUE_MAP, FEEDBACK_ICON_BY_VALUE,
// EMPTY_RATING_STATS are in assets/shared.js

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
}

async function loadPageContent(pageId) {
  const page = S.pages.find(p => p.id === pageId);
  const needsContent = page && !page._contentLoaded;
  const needsRatings = page && S.authed && !page._ratingsLoaded;
  if (!page || (!needsContent && !needsRatings)) return page;
  try {
    const r = await fetch(`api.php?action=load_page&id=${pageId}`, { credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok && d.page) {
      Object.assign(page, d.page);
      page._contentLoaded = true;
      if (S.authed) applyPageRatingData(page, d);
      else clearPageRatingData(page);
    }
  } catch(e) {}
  return page;
}

function applyPageRatingData(page, data) {
  if (!page) return;
  const stats = data?.ratingStats || EMPTY_RATING_STATS;
  page.ratingStats = {
    '-1': Number(stats['-1'] || 0),
    '0': Number(stats['0'] || 0),
    '1': Number(stats['1'] || 0),
  };
  page.ratingAverage = typeof data?.ratingAverage === 'number' ? data.ratingAverage : null;
  page.ratingCount = Number(data?.ratingCount || 0);
  page.ratingCsvAvailable = !!data?.ratingCsvAvailable || page.ratingCount > 0;
  page._ratingsLoaded = true;
}

function clearPageRatingData(page) {
  if (!page) return;
  delete page.ratingStats;
  delete page.ratingAverage;
  delete page.ratingCount;
  delete page.ratingCsvAvailable;
  page._ratingsLoaded = false;
}

async function refreshCurrentPageRatings(force = false) {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  if (!S.authed) {
    renderPage();
    return;
  }
  if (force) page._ratingsLoaded = false;
  await loadPageContent(page.id);
  renderPage();
}

async function parseApiJsonResponse(response) {
  const raw = await response.text();

  try {
    return raw ? JSON.parse(raw) : {};
  } catch (error) {
    const message = raw
      .replace(/<br\s*\/?>/gi, ' ')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 180);

    throw new Error(message || response.statusText || `HTTP ${response.status}`);
  }
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

// Rename a page's id on the server (renames file + ratings, re-parents children).
async function renamePageOnServer(oldId, newId, meta) {
  try {
    const r = await fetch('api.php?action=rename_page', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: oldId, newId, meta })
    });
    const d = await r.json();
    return !!(d && d.ok);
  } catch (e) { console.warn('Rename page error:', e); return false; }
}

// Persist order/parent/section changes to each affected page's own file.
// Reordering is not a content edit, so updatedAt is intentionally NOT bumped.
async function persistPages(pages) {
  await Promise.all((pages || []).map(p => {
    const {
      _contentLoaded, _ratingsLoaded,
      ratingStats, ratingAverage, ratingCount, ratingCsvAvailable,
      ...pageData
    } = p;
    return fetch('api.php?action=save_page', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page: pageData })
    }).catch(e => console.warn('Persist page error:', e));
  }));
}

async function deletePagesFromServer(ids) {
  const pageIds = (Array.isArray(ids) ? ids : [ids])
    .map(id => String(id || '').trim())
    .filter(Boolean);
  if (!pageIds.length) return;

  const body = pageIds.length === 1
    ? { id: pageIds[0] }
    : { ids: pageIds };

  try {
    await fetch('api.php?action=delete_page', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  } catch(e) {}
}

async function deletePageFromServer(id) {
  await deletePagesFromServer([id]);
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

// Normalize a user-typed page ID to the same charset the backend accepts ([A-Za-z0-9_-]).
function sanitizeManualId(raw) {
  return String(raw || '').trim().toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // remove diacritics
    .replace(/\s+/g, '-')
    .replace(/[^a-z0-9_-]/g, '')
    .replace(/-+/g, '-')
    .replace(/^[-_]+|[-_]+$/g, '')
    .slice(0, 60);
}

// Live hint under the optional ID field in the "New page" modal.
function updateNewPageIdHint() {
  const titleEl = document.getElementById('new-title');
  const idEl = document.getElementById('new-id');
  const hint = document.getElementById('new-id-hint');
  if (!titleEl || !idEl || !hint) return;
  const manual = idEl.value.trim();
  if (!manual && !titleEl.value.trim()) {
    hint.textContent = t('modalAddIdHint');
    return;
  }
  const effective = manual ? sanitizeManualId(manual) : pageSlug(titleEl.value.trim());
  hint.textContent = 'URL: ?page=' + (effective || '…');
}

function buildPageHref(pageId = '') {
  const url = new URL(window.location.pathname, window.location.origin);
  if (pageId) url.searchParams.set('page', pageId);
  return url.pathname + url.search + url.hash;
}

// isPrimaryNavigationClick is in assets/shared.js

function bindEditorPageLinks(root = document) {
  root.querySelectorAll('a[data-page-id]').forEach(link => {
    if (link.dataset.navBound === '1') return;
    link.dataset.navBound = '1';
    link.draggable = false;

    link.addEventListener('click', event => {
      if (!isPrimaryNavigationClick(event)) return;
      event.preventDefault();

      const pageId = link.dataset.pageId || '';
      const targetSpaceId = link.dataset.spaceId || '';
      const shouldCloseSearch = link.dataset.closeSearch === '1';
      if (!pageId) return;

      if (shouldCloseSearch) {
        const searchInput = document.getElementById('search-input');
        if (searchInput) searchInput.value = '';
        closeSearchDD();
      }

      if (targetSpaceId && targetSpaceId !== S.currentSpaceId) {
        S.currentSpaceId = targetSpaceId;
        renderSpaces();
      }

      navigateTo(pageId);
    });
  });
}

// Alias so the shared updatePageNavBottom() helper in assets/shared.js
// can call the editor's link binder without knowing its specific name.
const bindPageLinks = bindEditorPageLinks;

// formatDate is in assets/shared.js

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
      <button class="btn btn-ghost" style="font-size:11px;padding:2px 8px;color:#ef4444;border:1px solid rgba(239,68,68,.3)" onclick="event.stopPropagation();removeLogo();"><i class="fa-solid fa-trash"></i> ${t('settingsLogoRemove')}</button>`;
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
      <button class="btn btn-ghost" style="font-size:11px;padding:2px 8px;color:#ef4444;border:1px solid rgba(239,68,68,.3)" onclick="event.stopPropagation();removeFavicon();"><i class="fa-solid fa-trash"></i> ${t('settingsFaviconRemove')}</button>`;
  } else {
    if (faviconLink) faviconLink.href = 'data:,';
    if (faviconPreview) faviconPreview.innerHTML = `<i class="fa-solid fa-image" style="font-size:14px;color:var(--text4)"></i><span>${t('settingsFaviconUpload')}</span>`;
  }

  document.getElementById('logo-name-display').textContent = s.siteName || 'My Docs';
  applyFooterDisplay();
  document.title = s.tabTitle || 'Docs';

  // sync inputs
  document.getElementById('site-name-input').value = s.siteName || '';
  document.getElementById('footer-input').value = s.footerText ?? '';
  document.getElementById('footer-tail-input').value = s.footerTailHtml ?? '';
  document.getElementById('tab-title-input').value = s.tabTitle || '';

  // color swatches
  document.querySelectorAll('.color-swatch').forEach(el => {
    el.classList.toggle('active', el.dataset.color === s.accentColor);
  });

  // lang select sync
  const langSel = document.getElementById('lang-select');
  if (langSel && s.lang) langSel.value = s.lang;

  // share section toggle sync
  const shareSectionToggle = document.getElementById('share-section-toggle');
  if (shareSectionToggle) shareSectionToggle.className = 'toggle ' + (isShareSectionEnabled() ? 'on' : '');

  // single-space tabs toggle sync
  const hideSingleSpaceTabsToggle = document.getElementById('hide-single-space-tabs-toggle');
  if (hideSingleSpaceTabsToggle) hideSingleSpaceTabsToggle.className = 'toggle ' + (isHideSingleSpaceTabsEnabled() ? 'on' : '');

  // translate toggle sync
  const translateToggle = document.getElementById('translate-toggle');
  if (translateToggle) translateToggle.className = 'toggle ' + (isTranslateEnabled() ? 'on' : '');

  applySpaceTabsVisibility();
  applyShareSectionAvailability();
  applyTranslateAvailability();

  // apply translations to all data-i18n elements
  applyTranslations();
}

// isTranslateEnabled, isHideSingleSpaceTabsEnabled, isShareSectionEnabled,
// shouldHideSpaceTabs, applySpaceTabsVisibility, applyShareSectionAvailability,
// LANG_META, updateTranslateOrigin, resetTranslateState, applyTranslateAvailability,
// loadTranslateWidget, googleTranslateElementInit, translateTo, toggleTranslate
// are in assets/shared.js.

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

function toggleHideSingleSpaceTabs() {
  S.settings.hideSingleSpaceTabs = !isHideSingleSpaceTabsEnabled();
  saveSettingsDebounced();
  applySettings();
}

function toggleShareSectionAvailability() {
  S.settings.shareSectionEnabled = !isShareSectionEnabled();
  saveSettingsDebounced();
  applySettings();
}

let _settingsSaveTimer = null;
function saveSettingsDebounced() {
  clearTimeout(_settingsSaveTimer);
  _settingsSaveTimer = setTimeout(() => save(), 800);
}

async function saveSettingsNow() {
  clearTimeout(_settingsSaveTimer);
  try {
    await save();
  } catch (e) {
    console.warn('Save settings error:', e);
  }
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
  applyFooterDisplay();
  saveSettingsDebounced();
}

function updateFooterTail(v) {
  S.settings.footerTailHtml = v;
  applyFooterDisplay();
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
  reader.onload = async e => {
    S.settings.logoDataUrl = e.target.result;
    const area = document.getElementById('logo-preview-area');
    area.innerHTML = `<img src="${e.target.result}" class="logo-preview" style="max-height:30px;max-width:80px;border-radius:4px">`;
    applySettings();
    await saveSettingsNow();
  };
  reader.readAsDataURL(file);
}

function handleFaviconUpload(input) {
  const file = input.files[0];
  if (!file) return;
  // Max 256KB for favicon
  if (file.size > 256 * 1024) { showToast(t('toastFaviconTooLarge')); return; }
  const reader = new FileReader();
  reader.onload = async e => {
    S.settings.faviconDataUrl = e.target.result;
    applySettings();
    await saveSettingsNow();
  };
  reader.readAsDataURL(file);
}

function removeLogo() {
  S.settings.logoDataUrl = '';
  applySettings();
  saveSettingsNow();
}

function removeFavicon() {
  S.settings.faviconDataUrl = '';
  applySettings();
  saveSettingsNow();
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
  if (!strip) return;
  strip.innerHTML = '';

  const canReorder = S.authed && S.spaces.length > 1;

  S.spaces.forEach((sp, idx) => {
    const spacePageList = S.pages.filter(p => p.spaceId === sp.id);
    const firstPage = firstRootPage(spacePageList);
    const el = document.createElement('div');
    el.className = 'tab-item' + (sp.id === S.currentSpaceId ? ' active' : '');

    if (firstPage) {
      const link = document.createElement('a');
      link.className = 'tab-link';
      link.href = buildPageHref(firstPage.id);
      link.dataset.pageId = firstPage.id;
      link.dataset.spaceId = sp.id;
      link.innerHTML = `<i class="fa-solid ${sp.icon || 'fa-book'}"></i><span>${esc(sp.name)}</span>`;
      el.appendChild(link);
    } else {
      const label = document.createElement('div');
      label.className = 'tab-link';
      label.innerHTML = `<i class="fa-solid ${sp.icon || 'fa-book'}"></i><span>${esc(sp.name)}</span>`;
      label.addEventListener('click', event => {
        if (!isPrimaryNavigationClick(event)) return;
        event.preventDefault();
        switchSpace(sp.id);
      });
      el.appendChild(label);
    }

    if (canReorder) {
      const leftBtn = document.createElement('button');
      leftBtn.type = 'button';
      leftBtn.className = 'tab-move-btn';
      leftBtn.title = t('btnMoveSpaceLeft');
      leftBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
      leftBtn.disabled = idx === 0;
      leftBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveSpace(idx, idx - 1);
      });
      el.appendChild(leftBtn);

      const rightBtn = document.createElement('button');
      rightBtn.type = 'button';
      rightBtn.className = 'tab-move-btn';
      rightBtn.title = t('btnMoveSpaceRight');
      rightBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
      rightBtn.disabled = idx === S.spaces.length - 1;
      rightBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        moveSpace(idx, idx + 1);
      });
      el.appendChild(rightBtn);
    }

    if (S.authed) {
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'tab-edit-btn';
      editBtn.title = t('btnEditSpace');
      editBtn.innerHTML = '<i class="fa-solid fa-pen"></i>';
      el.appendChild(editBtn);
    }

    if (S.authed) {
      el.querySelector('.tab-edit-btn')?.addEventListener('click', (e) => {
        e.stopPropagation();
        openSpaceMenu(sp, el);
      });
    }

    strip.appendChild(el);
  });

  bindEditorPageLinks(strip);

  // Add space button — admin only
  const addBtn = document.createElement('button');
  addBtn.className = 'tab-add' + (S.authed ? ' admin-visible' : '');
  addBtn.innerHTML = `<i class="fa-solid fa-plus"></i> ${t('btnNewSpace')}`;
  addBtn.onclick = addSpace;
  strip.appendChild(addBtn);

  applySpaceTabsVisibility();
}

async function moveSpace(fromIdx, toIdx) {
  if (fromIdx < 0 || toIdx < 0 || fromIdx >= S.spaces.length || toIdx >= S.spaces.length) return;
  if (fromIdx === toIdx) return;
  const moved = S.spaces.splice(fromIdx, 1)[0];
  S.spaces.splice(toIdx, 0, moved);
  renderSpaces();
  await save();
  showToast(t('toastOrderSaved'));
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
      await deletePagesFromServer(pageIds);
      S.pages = S.pages.filter(p => p.spaceId !== id);
      S.spaces = S.spaces.filter(s => s.id !== id);
      if (S.currentSpaceId === id) {
        S.currentSpaceId = S.spaces[0].id;
        S.currentPageId = firstRootPage(S.pages.filter(p => p.spaceId === S.currentSpaceId))?.id || null;
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
  S.currentPageId = firstRootPage(pages)?.id || null;
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

// spacePages, firstRootPage are in assets/shared.js

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
  const seen = new Set();
  const sections = [];
  rootPages.forEach(p => {
    const sec = p.section || '';
    if (!seen.has(sec)) { seen.add(sec); sections.push(sec); }
  });

  sections.forEach(sec => {
    if (sec) {
      const label = document.createElement('div');
      label.className = 'section-label';
      label.textContent = sec;
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
    ? `<button type="button" class="nav-toggle${isOpen ? ' open' : ''}" onclick="event.stopPropagation();toggleChildren(this,'${page.id}')" aria-expanded="${isOpen ? 'true' : 'false'}" aria-controls="children-${page.id}" aria-label="Toggle ${esc(page.title)}"><i class="fa-solid fa-chevron-right"></i></button>`
    : `<div class="nav-toggle-spacer"></div>`;

  const actionsHtml = isAdmin ? `
    <div class="nav-actions">
      <button class="na-btn" onclick="event.stopPropagation();openPageEdit('${page.id}')" title="${t('btnEditPage')}"><i class="fa-solid fa-pen"></i></button>
      <button class="na-btn" onclick="event.stopPropagation();openAddPage('${page.id}')" title="${t('btnSubpage')}"><i class="fa-solid fa-plus"></i></button>
      <button class="na-btn na-btn-danger" onclick="event.stopPropagation();deletePage('${page.id}')" title="${t('btnDeletePage')}"><i class="fa-solid fa-trash"></i></button>
    </div>` : '';

  item.innerHTML = `
    ${toggleHtml}
    <a class="nav-link" href="${esc(buildPageHref(page.id))}" data-page-id="${esc(page.id)}">
      <span class="nav-ic"><i class="fa-solid ${page.icon || 'fa-file'}"></i></span>
      <span class="nav-label">${esc(page.title)}</span>
    </a>
    ${actionsHtml}
  `;
  wrap.appendChild(item);

  if (children.length) {
    const sub = document.createElement('div');
    sub.className = 'nav-children' + (isOpen ? ' open' : '');
    sub.id = 'children-' + page.id;
    children.forEach(c => renderNavItem(c, sub, depth + 1, allPages));
    wrap.appendChild(sub);
  }

  container.appendChild(wrap);
  bindEditorPageLinks(wrap);
}

function toggleChildren(btn, pageId) {
  btn.classList.toggle('open');
  btn.setAttribute('aria-expanded', btn.classList.contains('open') ? 'true' : 'false');
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
  const newUrl = buildPageHref(pageId);
  history.pushState({ pageId }, '', newUrl);
  // Scroll to top
  document.querySelector('.content-wrap')?.scrollTo(0, 0);
  window.scrollTo(0, 0);
}

// ════════════════════════════════════════
//  PAGE RENDER
// ════════════════════════════════════════
function disposeEditor(resetHolder = false) {
  const activeEditor = editor;
  editor = null;

  if (activeEditor?.destroy) {
    try {
      const destroyResult = activeEditor.destroy();
      if (destroyResult && typeof destroyResult.catch === 'function') destroyResult.catch(() => {});
    } catch(e) {}
  }

  if (resetHolder) {
    const holder = document.getElementById('editor');
    if (holder) {
      const freshHolder = document.createElement('div');
      freshHolder.id = 'editor';
      holder.replaceWith(freshHolder);
    }
  }
}

function renderPage() {
  disposeEditor();
  if (scrollSpyObserver) {
    scrollSpyObserver.disconnect();
    scrollSpyObserver = null;
  }

  const view = document.getElementById('page-view');
  const page = S.pages.find(p => p.id === S.currentPageId);

  if (!page) {
    view.innerHTML = `<div class="empty-state">
      <i class="fa-solid fa-book-open"></i>
      <p>${t('pageSelectPrompt')}</p>
    </div>`;
    syncFeedbackPanelVisibility();
    renderAdminRatingPanel(null);
    syncFeedbackButtons();
    updateTOC(); updatePageNavBottom(null);
    return;
  }

  const blocks = page.content?.blocks || [];

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
    breadParts.push(`<a href="${esc(buildPageHref(a.id))}" data-page-id="${esc(a.id)}">${esc(a.title)}</a>`);
    breadParts.push(`<i class="fa-solid fa-chevron-right"></i>`);
  });
  // Aktuálna stránka
  breadParts.push(`<span>${esc(page.title)}</span>`);

  view.innerHTML = `
    ${page.cover ? `<div class="page-cover" id="page-cover-el" style="${page.cover.type==='color' ? 'background:'+page.cover.value : ''}">
      ${page.cover.type==='image' ? `<img src="${page.cover.value}" alt="" style="object-fit:${page.cover.fit||'cover'};object-position:${page.cover.position||'50% 50%'}">` : ''}
      ${S.editMode ? `<div class="page-cover-actions">
        <button class="page-cover-btn" onclick="changeCover()"><i class="fa-solid fa-image"></i> ${t('coverChange')}</button>
        <button class="page-cover-btn" onclick="removeCover()"><i class="fa-solid fa-trash"></i> ${t('coverRemove')}</button>
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
          <button class="cover-pos-btn ${(page.cover?.fit||'cover')==='cover'?'active':''}" data-fit="cover" onclick="setCoverFit('cover')">${t('coverFitCover')}</button>
          <button class="cover-pos-btn ${(page.cover?.fit||'cover')==='contain'?'active':''}" data-fit="contain" onclick="setCoverFit('contain')">${t('coverFitContain')}</button>
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
          ${S.editMode && !page.cover ? `<button onclick="addCover()" style="font-size:12px;padding:4px 10px;border-radius:6px;border:1px dashed var(--border);background:transparent;color:var(--text3);cursor:pointer;font-family:var(--font);display:flex;align-items:center;gap:5px;transition:all .15s;" onmouseover="this.style.color='var(--text2)';this.style.borderColor='var(--text3)'" onmouseout="this.style.color='var(--text3)';this.style.borderColor='var(--border)'"><i class="fa-solid fa-image"></i> ${t('coverLabel')}</button>` : ''}
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

  bindEditorPageLinks(view);
  updatePageNavBottom(page);
  if (S.editMode || blocks.length) {
    initEditor(page);
  } else {
    const editorEl = document.getElementById('editor');
    if (editorEl) {
      editorEl.innerHTML = `
        <div class="page-empty-state">
          <i class="fa-regular fa-file-lines"></i>
          <p>${t('pageEmpty')}</p>
          ${S.authed ? `<button class="btn btn-primary" onclick="toggleEdit()"><i class="fa-solid fa-plus"></i> ${t('pageAddContent')}</button>` : ''}
        </div>`;
    }
    updateTOC();
  }

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

  syncFeedbackPanelVisibility();
  renderAdminRatingPanel(page);
  syncFeedbackButtons();

  // Reading time — update po načítaní editora
  setTimeout(() => updateReadingTime(), 800);
}

// updateReadingTime is in assets/shared.js

// ── Cover ──
async function addCover() {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page) return;
  const colors = ['linear-gradient(135deg,#f97316,#7c3aed)','linear-gradient(135deg,#3b82f6,#06b6d4)',
    'linear-gradient(135deg,#10b981,#3b82f6)','linear-gradient(135deg,#ec4899,#f97316)',
    'linear-gradient(135deg,#6366f1,#ec4899)','linear-gradient(135deg,#eab308,#ef4444)'];
  const pick = colors[Math.floor(Math.random() * colors.length)];
  page.cover = { type: 'color', value: pick };
  await savePageToServer(page);
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
      const d = await parseApiJsonResponse(r);
      if (!d.ok) throw new Error(d.error || t('toastUploadError'));
      if (d.url) {
        const page = S.pages.find(p => p.id === S.currentPageId);
        if (page) { page.cover = { type:'image', value:d.url }; await savePageToServer(page); renderPage(); }
      }
    } catch(e) { showToast(e?.message || t('toastUploadError')); }
  };
  input.click();
}

async function removeCover() {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (page) { page.cover = null; await savePageToServer(page); renderPage(); }
}

async function setCoverFit(fit) {
  const page = S.pages.find(p => p.id === S.currentPageId);
  if (!page?.cover) return;
  page.cover.fit = fit;
  await savePageToServer(page);
  const img = document.querySelector('#page-cover-el img');
  if (img) img.style.objectFit = fit;
  document.querySelectorAll('.cover-pos-btn[data-fit]').forEach(b => {
    b.classList.toggle('active', b.dataset.fit === fit);
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

// updatePageNavBottom is in assets/shared.js

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
  inp.placeholder = t('iconPickerSearch');
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
    this._wrapper.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text3)"><i class="fa-solid fa-spinner fa-spin"></i> ${t('imagePasteUploading')}</div>`;
    try {
      const fd = new FormData();
      fd.append('image', file);
      const r = await fetch('api.php?action=upload_image', {
        method: 'POST', credentials: 'same-origin', body: fd
      });
      const d = await parseApiJsonResponse(r);
      if (!d.ok) throw new Error(d.error);
      this.data.url = d.url;
      this.data.filename = d.filename;
      this._renderImage();
    } catch(e) {
      console.error('Image upload failed', e);
      this._wrapper.innerHTML = `<div style="padding:16px;text-align:center;color:#ef4444"><i class="fa-solid fa-circle-exclamation"></i> ${t('imagePasteFailed')}: ${e.message}</div>`;
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
    cap.dataset.placeholder = t('imageCaptionPlaceholder');
    cap.textContent = this.data.caption;
    cap.oninput = () => { this.data.caption = cap.textContent; img.alt = cap.textContent; };
    this._wrapper.appendChild(cap);

    if (!this.readOnly) {
      const del = document.createElement('button');
      del.className = 'lit-del-btn';
      del.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
      del.title = t('imageRemoveTitle');
      del.onclick = () => { this.data.url = ''; this.data.caption = ''; this._renderUploader(); };
      this._wrapper.appendChild(del);
    }
  }

  renderSettings() {
    return [
      { label: t('blockWithBorder'), icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>', isActive: this.data.withBorder, closeOnActivate: true, onActivate: () => { this.data.withBorder = !this.data.withBorder; if (this.data.url) this._renderImage(); } },
      { label: t('blockWithBackground'), icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>', isActive: this.data.withBackground, closeOnActivate: true, onActivate: () => { this.data.withBackground = !this.data.withBackground; if (this.data.url) this._renderImage(); } },
      { label: t('blockFullWidth'), icon: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>', isActive: this.data.stretched, closeOnActivate: true, onActivate: () => { this.data.stretched = !this.data.stretched; if (this.data.url) this._renderImage(); } },
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
// ── Inline tool: replaces the built-in Link tool, adding a "link to page" button ──
// Clicking the link button opens a URL input; the page button to its right opens
// the internal page picker. So the user can type a URL OR pick an internal page.
class LinkWithPageTool {
  static get isInline() { return true; }
  static get title() { return t('ctLink'); }
  static get sanitize() { return { a: { href: true, target: true, rel: true, 'data-page-id': true } }; }

  constructor({ api }) {
    this.api = api;
    this.button = null;
    this.wrapper = null;
    this.input = null;
    this.pageBtn = null;
    this.savedRange = null;
    this.inputOpened = false;
    this.state = false;
  }

  render() {
    this.button = document.createElement('button');
    this.button.type = 'button';
    this.button.classList.add(this.api.styles.inlineToolButton);
    this.button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
    return this.button;
  }

  renderActions() {
    this.wrapper = document.createElement('div');
    this.wrapper.className = 'ce-link-actions';
    this.wrapper.hidden = true;

    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.className = 'ce-link-actions__input';
    this.input.placeholder = t('ctLinkPlaceholder');

    this.pageBtn = document.createElement('button');
    this.pageBtn.type = 'button';
    this.pageBtn.className = 'ce-link-actions__page';
    this.pageBtn.title = t('linkInternalTitle');
    this.pageBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2.5-2.5L9 10"/></svg>';

    this.input.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); this._applyUrl(); }
      else if (e.key === 'Escape') { e.preventDefault(); this._close(); }
    });
    this.pageBtn.addEventListener('mousedown', e => { e.preventDefault(); this._openPicker(); });

    this.wrapper.appendChild(this.input);
    this.wrapper.appendChild(this.pageBtn);
    return this.wrapper;
  }

  surround(range) {
    if (range) this.savedRange = range.cloneRange();
    const existing = this._anchorFromNode(range && range.commonAncestorContainer);
    if (this.input) this.input.value = existing ? (existing.getAttribute('href') || '') : '';
    this._openActions();
  }

  checkState(selection) {
    const a = this._anchorFromNode(selection && selection.anchorNode);
    this.state = !!a;
    if (this.button) this.button.classList.toggle(this.api.styles.inlineToolButtonActive, this.state);
    if (a) {
      if (selection && selection.rangeCount) this.savedRange = selection.getRangeAt(0).cloneRange();
      if (this.input) this.input.value = a.getAttribute('href') || '';
      this._openActions();
    } else if (!this.inputOpened) {
      this._closeActions();
    }
    return this.state;
  }

  _openActions() {
    if (!this.wrapper) return;
    this.wrapper.hidden = false;
    this.inputOpened = true;
    setTimeout(() => { if (this.input) { this.input.focus(); this.input.select(); } }, 0);
  }

  _closeActions() {
    if (!this.wrapper) return;
    this.wrapper.hidden = true;
    this.input.value = '';
    this.inputOpened = false;
  }

  _openPicker() {
    if (typeof window.openPagePicker !== 'function') return;
    // Capture the range LOCALLY: closing the toolbar triggers clear() which nulls
    // this.savedRange before the user picks a page.
    const savedRange = this.savedRange ? this.savedRange.cloneRange() : null;
    const rect = this.input ? this.input.getBoundingClientRect() : null;
    this._close();
    window.openPagePicker(rect, { excludeId: S.currentPageId }).then(pageId => {
      if (!pageId) return;
      this._applyLink(savedRange, '?page=' + encodeURIComponent(pageId), pageId);
    });
  }

  _applyUrl() {
    const v = (this.input.value || '').trim();
    const savedRange = this.savedRange ? this.savedRange.cloneRange() : null;
    if (!v) { this._applyLink(savedRange, null, null); return; }
    const isInternal = /^\?page=/.test(v);
    const href = isInternal ? v : (v.match(/^https?:\/\//) ? v : 'https://' + v);
    const pageId = isInternal ? decodeURIComponent(v.replace(/^\?page=/, '')) : null;
    this._applyLink(savedRange, href, pageId);
  }

  _applyLink(savedRange, href, pageId) {
    if (!savedRange) { this._close(); return; }
    const sel = window.getSelection();
    let host = savedRange.commonAncestorContainer;
    if (host && host.nodeType === 3) host = host.parentElement;
    const editable = host && host.closest ? host.closest('[contenteditable="true"]') : null;
    if (editable) editable.focus();
    sel.removeAllRanges();
    sel.addRange(savedRange);
    if (!href) {
      document.execCommand('unlink', false, null);
    } else {
      document.execCommand('createLink', false, href);
      const a = this._anchorFromNode(sel.anchorNode);
      if (a) {
        a.setAttribute('href', href);
        if (pageId) { a.setAttribute('data-page-id', pageId); a.removeAttribute('target'); }
        else { a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener'); }
        // Collapse the caret after the link so the toolbar doesn't immediately reopen.
        const after = document.createRange();
        after.setStartAfter(a);
        after.collapse(true);
        sel.removeAllRanges();
        sel.addRange(after);
      }
    }
    if (editable) editable.dispatchEvent(new Event('input', { bubbles: true }));
    this._close();
  }

  _close() {
    this._closeActions();
    try { this.api.inlineToolbar.close(); } catch (e) {}
  }

  _anchorFromNode(node) {
    if (!node) return null;
    if (node.nodeType === 3) node = node.parentElement;
    return node && node.closest ? node.closest('a') : null;
  }

  clear() {
    this._closeActions();
    this.savedRange = null;
  }
}

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
      info:    { icon: 'fa-circle-info',          color: 'var(--accent)',  bg: 'rgba(var(--accent-rgb),0.08)', label: t('calloutTypeInfo') },
      tip:     { icon: 'fa-lightbulb',            color: '#16a34a',        bg: 'rgba(22,163,74,0.08)',         label: t('calloutTypeTip') },
      warning: { icon: 'fa-triangle-exclamation', color: '#ca8a04',        bg: 'rgba(202,138,4,0.08)',         label: t('calloutTypeWarning') },
      danger:  { icon: 'fa-circle-exclamation',   color: '#dc2626',        bg: 'rgba(220,38,38,0.08)',         label: t('calloutTypeDanger') },
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
//  DELIMITER TOOL — selectable styles: stars / line / dashed / dots
// ════════════════════════════════════════
class DelimiterTool {
  static get toolbox() {
    return {
      title: 'Divider',
      icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/></svg>'
    };
  }
  static get isReadOnlySupported() { return true; }

  static get STYLES() {
    return {
      stars:  { icon: 'fa-asterisk',   label: t('dividerStyleStars') },
      line:   { icon: 'fa-minus',      label: t('dividerStyleLine') },
      dashed: { icon: 'fa-grip-lines', label: t('dividerStyleDashed') },
      dots:   { icon: 'fa-ellipsis',   label: t('dividerStyleDots') },
    };
  }

  constructor({ data }) {
    this.data = { style: (data && DelimiterTool.STYLES[data.style]) ? data.style : 'stars' };
  }

  render() {
    this._el = document.createElement('div');
    this._el.className = `ce-delimiter cdx-delimiter cdx-block ce-delimiter--${this.data.style}`;
    return this._el;
  }

  save() {
    return { style: this.data.style || 'stars' };
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
      titleSpan.textContent = this.data.title || t('collapsibleDefaultTitle');
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
      ta.placeholder = t('collapsibleBodyPlaceholder');
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

// The @editorjs/table tool defines no `sanitize`, so EditorJS falls back to the
// inline-tools allow-list (which has no <br>) and strips line breaks from cells
// on save. Allow <br> + inline formatting inside table cells so Shift+Enter
// breaks survive saving and re-rendering.
if (typeof Table !== 'undefined' && !Object.prototype.hasOwnProperty.call(Table, 'sanitize')) {
  Object.defineProperty(Table, 'sanitize', {
    configurable: true,
    get() {
      return {
        withHeadings: false,
        content: {
          br: true,
          b: true,
          i: true,
          u: true,
          a: { href: true, target: true, rel: true },
          mark: { class: true },
          code: true,
        },
      };
    },
  });
}

async function initEditor(page) {
  if (!document.getElementById('editor')) return;

  let editorReady = false;

  const tools = {
    header: { class: Header, config: { levels: [1,2,3], defaultLevel: 1 } },
    list: { class: NestedList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
    checklist: { class: Checklist, inlineToolbar: true },
    code: { class: CodeTool },
    quote: { class: Quote, inlineToolbar: true },
    delimiter: { class: DelimiterTool },
    inlineCode: { class: InlineCode },
    marker: { class: Marker },
    link: { class: LinkWithPageTool },
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
      initImagePasteHandler();
    },
  });

  try { await editor.isReady; } catch(e) {}
  // Bind paste handler also after isReady resolves (fallback in case onReady didn't fire)
  initImagePasteHandler();
  hideSaveBar();
  undoStack.length = 0; redoStack.length = 0;
  setTimeout(() => { editor._wsReady = true; pushUndoSnapshot(); updateUndoRedoBtns(); }, 600);
}

// ════════════════════════════════════════
//  IMAGE PASTE HANDLER (Ctrl/Cmd+V in editor)
// ════════════════════════════════════════
let _imagePasteHandlerBound = false;

function initImagePasteHandler() {
  if (_imagePasteHandlerBound) return;
  _imagePasteHandlerBound = true;

  const holder = document.getElementById('editor');

  // Bind on the editor holder (capture phase)
  if (holder) {
    holder.addEventListener('paste', onEditorPaste, true);
  }

  // Also bind on document (capture) so we can detect paste events even if
  // EditorJS or some other layer stops propagation on the holder.
  document.addEventListener('paste', onEditorPaste, true);
}

async function onEditorPaste(e) {
  if (!editor || !S.editMode) return;

  const items = e.clipboardData?.items;
  if (!items || !items.length) return;

  // Find first image item in clipboard
  let imageFile = null;
  for (const it of items) {
    if (it.kind === 'file' && it.type && it.type.startsWith('image/')) {
      imageFile = it.getAsFile();
      if (imageFile) break;
    }
  }
  if (!imageFile) return;

  // Only handle paste when an editor block is focused (cursor inside editor)
  const sel = window.getSelection();
  if (!sel || !sel.anchorNode) return;
  const editorEl = document.getElementById('editor');
  if (!editorEl || !editorEl.contains(sel.anchorNode)) return;

  // Prevent default text/HTML paste behaviour for images
  e.preventDefault();
  e.stopPropagation();

  await insertPastedImageBlock(imageFile);
}

async function uploadImageFile(file) {
  const fd = new FormData();
  fd.append('image', file);
  const r = await fetch('api.php?action=upload_image', {
    method: 'POST', credentials: 'same-origin', body: fd
  });
  const d = await parseApiJsonResponse(r);
  if (!d.ok) throw new Error(d.error || 'upload failed');
  return { url: d.url, filename: d.filename };
}

async function insertPastedImageBlock(file) {
  showToast(t('imagePasteUploading'));
  try {
    const { url, filename } = await uploadImageFile(file);

    // Insert a new image block after the current one with the uploaded URL
    const currentIdx = Math.max(editor.blocks.getCurrentBlockIndex(), 0);
    const insertIdx = Math.min(currentIdx + 1, editor.blocks.getBlocksCount());
    editor.blocks.insert(
      'image',
      { url, caption: '', filename, stretched: false, withBorder: false, withBackground: false },
      undefined,
      insertIdx,
      true
    );

    requestAnimationFrame(() => editor?.caret?.setToBlock?.(insertIdx));
    markDirty();
    scheduleUndoSnapshot();
  } catch (err) {
    console.error('[imagePaste] insertPastedImageBlock failed', err);
    showToast(t('imagePasteFailed'));
  }
}

// ════════════════════════════════════════
//  CUSTOM BLOCK MENU (replaces native settings)
// ════════════════════════════════════════
let blockMenu = null;

// Resolve the editor block index from a viewport Y coordinate (typically the
// settings button's own vertical center). EditorJS does NOT reposition its
// hover toolbar after a programmatic insert/convert/move, so matching the
// (stale) toolbar top to the nearest block picks the wrong block. The button
// the user actually clicks is the reliable anchor: prefer the block whose
// vertical box contains the point, then fall back to the nearest block by gap.
function getEditorBlockEls() {
  return [...document.querySelectorAll('#editor .ce-block')];
}

function resolveEditorBlockIndexFromY(centerY) {
  const blocks = getEditorBlockEls();
  if (!blocks.length) return -1;
  for (let i = 0; i < blocks.length; i++) {
    const r = blocks[i].getBoundingClientRect();
    if (centerY >= r.top && centerY <= r.bottom) return i;
  }
  let best = -1, bestDist = Infinity;
  blocks.forEach((b, i) => {
    const r = b.getBoundingClientRect();
    const d = centerY < r.top ? r.top - centerY : centerY - r.bottom;
    if (d < bestDist) { bestDist = d; best = i; }
  });
  return best;
}

async function openBlockMenu(btn) {
  closeBlockMenu();
  const rect = btn.getBoundingClientRect();
  const total = editor?.blocks?.getBlocksCount?.() ?? 0;

  // Resolve the target block from the clicked button's own vertical center,
  // not the (possibly stale) EditorJS toolbar position.
  let idx = resolveEditorBlockIndexFromY(rect.top + rect.height / 2);
  if (idx < 0) idx = editor?.blocks?.getCurrentBlockIndex?.() ?? 0;

  // Read type + data for just this block (avoid a full-document save)
  let blockType = '';
  let blockData = {};
  try {
    const api = editor?.blocks?.getBlockByIndex?.(idx);
    if (api) {
      blockType = api.name || '';
      const saved = await api.save();
      blockData = saved?.data || {};
    }
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
    case 'delimiter':
      return Object.entries(DelimiterTool.STYLES).map(([st, c]) => ({
        icon: c.icon, label: c.label, active: (data.style || 'stars') === st,
        action: () => editor.blocks.update(editor.blocks.getBlockByIndex(idx).id, { ...data, style: st })
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

  // Find which block this settings button belongs to — anchor on the button's
  // own vertical center, not the (possibly stale) EditorJS toolbar position.
  const holder = document.getElementById('editor');
  if (!holder) return;
  const sRect = settingsBtn.getBoundingClientRect();
  let fromIdx = resolveEditorBlockIndexFromY(sRect.top + sRect.height / 2);
  if (fromIdx < 0) return;

  const startY = e.clientY;
  let isDragging = false;
  let placeholder = null;

  _blockDragState = { _didDrag: false };

  function onMove(ev) {
    // Require a clear vertical movement before starting a drag, so a normal
    // click (even with a little pointer jitter) still opens the block menu
    // instead of being swallowed as a no-op drag.
    if (!isDragging && Math.abs(ev.clientY - startY) < 10) return;

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
  if (!canUseAdminPageShortcuts()) return;
  // Ak ideme z edit → read, najprv ulož
  if (S.editMode) { await autoSave(true); hideSaveBar(); }

  S.editMode = !S.editMode;
  syncEditUI();

  renderPage();
  hideSaveBar();
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
        btn.innerHTML = `<i class="fa-solid fa-image"></i> ${t('coverLabel')}`;
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
            <button class="page-cover-btn" onclick="changeCover()"><i class="fa-solid fa-image"></i> ${t('coverChange')}</button>
            <button class="page-cover-btn" onclick="removeCover()"><i class="fa-solid fa-trash"></i> ${t('coverRemove')}</button>`;
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
                <button class="cover-pos-btn ${fit==='cover'?'active':''}" data-fit="cover" onclick="setCoverFit('cover')">${t('coverFitCover')}</button>
                <button class="cover-pos-btn ${fit==='contain'?'active':''}" data-fit="contain" onclick="setCoverFit('contain')">${t('coverFitContain')}</button>
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
  if (!S.authed || !editor || !S.editMode || undoStack.length < 2) return;
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
  if (!S.authed || !editor || !S.editMode || redoStack.length < 1) return;
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
  clearTimeout(saveTimer);
  clearTimeout(tocTimer);
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
  if (S.editMode) {
    S.editMode = false;
    syncEditUI();
  }
  renderNav();
  renderPage();
}

async function autoSave(silent = false) {
  if (!S.authed || !S.editMode || !editor) return;
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
  if (!S.authed || !S.editMode) return;
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
  document.getElementById('page-edit-id').value = page.id || '';
  updatePageEditIdHint();

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

// Live URL preview under the editable ID field in the "Edit page" modal.
function updatePageEditIdHint() {
  const idEl = document.getElementById('page-edit-id');
  const hint = document.getElementById('page-edit-id-hint');
  if (!idEl || !hint) return;
  const cur = S.pages.find(p => p.id === editingPageId);
  const eff = sanitizeManualId(idEl.value.trim()) || (cur ? cur.id : '');
  hint.textContent = 'URL: ?page=' + (eff || '…');
}

async function confirmPageEdit() {
  const page = S.pages.find(p => p.id === editingPageId);
  if (!page) return;
  const oldId = page.id;

  // Optional new page id (renames the page). Empty/unchanged keeps the current id.
  let newId = oldId;
  const newIdRaw = document.getElementById('page-edit-id').value.trim();
  if (newIdRaw) {
    const sanitized = sanitizeManualId(newIdRaw);
    if (sanitized && sanitized !== oldId) {
      if (S.pages.some(p => p.id === sanitized)) { showToast(t('toastIdTaken')); return; }
      newId = sanitized;
    }
  }

  page.title = document.getElementById('page-edit-title').value.trim() || t('pageUntitled');
  page.subtitle = document.getElementById('page-edit-subtitle').value.trim();
  page.icon = document.getElementById('page-edit-icon').value.trim() || 'fa-file';
  page.section = document.getElementById('page-edit-section').value.trim();

  if (newId !== oldId) {
    const ok = await renamePageOnServer(oldId, newId, {
      title: page.title, subtitle: page.subtitle, icon: page.icon, section: page.section,
    });
    if (!ok) { showToast(t('toastIdTaken')); return; }
    page.id = newId;
    S.pages.forEach(p => { if (p.parentId === oldId) p.parentId = newId; });
    if (S.currentPageId === oldId) S.currentPageId = newId;
    editingPageId = newId;
  }

  closeModal('page-edit-modal');
  await save();
  renderNav();
  if (S.currentPageId === editingPageId) {
    renderPage();
    if (newId !== oldId) history.replaceState(null, '', buildPageHref(newId));
  }
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
  document.getElementById('new-id').value = '';
  document.getElementById('new-icon').value = 'fa-file';
  document.getElementById('new-section').value = sectionHint;
  previewIcon('fa-file');
  updateNewPageIdHint();
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

  const manualIdRaw = document.getElementById('new-id').value.trim();
  let pageId;
  if (manualIdRaw) {
    pageId = sanitizeManualId(manualIdRaw);
    if (!pageId) {
      pageId = pageSlug(title);
    } else if (S.pages.some(p => p.id === pageId)) {
      showToast(t('toastIdTaken'));
      return;
    }
  } else {
    pageId = pageSlug(title);
  }

  let iconVal = document.getElementById('new-icon').value.trim() || 'fa-file';
  if (!iconVal.startsWith('fa-')) iconVal = 'fa-' + iconVal;
  const section = document.getElementById('new-section').value.trim() || null;

  const tmpl = getPageTemplates().find(tmpl => t.id === selectedTemplate) || getPageTemplates()[0];

  const siblings = S.pages.filter(p =>
    p.spaceId === S.currentSpaceId && p.parentId === S.addParentId
  );

  const page = {
    id: pageId,
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
      await deletePagesFromServer(Array.from(toRemove));
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
//  (scrollSpyObserver, slugify, updateTOC, initScrollSpy are in assets/shared.js)
// ════════════════════════════════════════

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
      <a class="search-result-item" href="${esc(buildPageHref(p.id))}" data-page-id="${esc(p.id)}" data-space-id="${esc(p.spaceId || '')}" data-close-search="1">
        <i class="fa-solid ${p.icon || 'fa-file'}"></i>
        <div>
          <div class="search-result-title">${esc(p.title)}</div>
          ${p.subtitle ? `<div class="search-result-path">${esc(p.subtitle.slice(0,60))}</div>` : ''}
        </div>
      </a>`).join('');
  }
  bindEditorPageLinks(dd);
  dd.classList.add('open');
}

// openSearchDD, closeSearchDD, selectSearch are in assets/shared.js

// ════════════════════════════════════════
//  TOAST / FEEDBACK
// ════════════════════════════════════════
// showToast, normalizeClientRatingValue, readFeedbackStore, writeFeedbackStore,
// getStoredFeedback, rememberFeedback, setFeedbackButtonsDisabled,
// syncFeedbackButtons are in assets/shared.js.

function formatRatingAverage(value) {
  if (typeof value !== 'number' || Number.isNaN(value)) return '–';
  const locale = LANG_LOCALES[S.settings?.lang || DEFAULT_INTERFACE_LANG] || LANG_LOCALES[DEFAULT_INTERFACE_LANG];
  const prefix = value > 0 ? '+' : '';
  return prefix + value.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function renderAdminRatingPanel(page) {
  const slot = document.getElementById('toc-admin-rating-slot');
  if (!slot) return;
  if (!S.authed || !page) {
    slot.innerHTML = '';
    return;
  }

  const stats = page.ratingStats || EMPTY_RATING_STATS;
  const total = Number(page.ratingCount || 0);
  const csvUrl = `api.php?action=download_ratings&id=${encodeURIComponent(page.id)}`;

  slot.innerHTML = `
    <div class="toc-sep"></div>
    <div class="toc-admin-rating">
      <div class="toc-feedback-label">${t('ratingStatsTitle')}</div>
      <div class="toc-rating-avg-row">
        <span class="toc-rating-avg-label">${t('ratingAverage')}</span>
        <strong class="toc-rating-avg-value">${total > 0 ? formatRatingAverage(page.ratingAverage) : '–'}</strong>
      </div>
      <div class="toc-rating-count">${total > 0 ? `${total} ${t('ratingVotes')}` : t('ratingNoVotes')}</div>
      <div class="toc-rating-pills">
      <div class="toc-rating-pill" title="${t('ratingNegative')}"><span>👎</span><strong>${Number(stats['-1'] || 0)}</strong></div>
      <div class="toc-rating-pill" title="${t('ratingNeutral')}"><span>😐</span><strong>${Number(stats['0'] || 0)}</strong></div>
      <div class="toc-rating-pill" title="${t('ratingPositive')}"><span>👍</span><strong>${Number(stats['1'] || 0)}</strong></div>
      </div>
      ${page.ratingCsvAvailable ? `<a class="toc-download-btn" href="${csvUrl}" download><i class="fa-solid fa-download"></i><span>${t('ratingDownloadCsv')}</span></a>` : ''}
    </div>
  `;
}

function syncFeedbackPanelVisibility() {
  const box = document.getElementById('toc-feedback-box');
  if (!box) return;
  box.style.display = S.authed ? 'none' : '';
}

// readFeedbackStore, writeFeedbackStore, getStoredFeedback, rememberFeedback,
// setFeedbackButtonsDisabled, syncFeedbackButtons are in assets/shared.js.

async function react(r, icon = '') {
  const pageId = S.currentPageId;
  const ratingValue = normalizeClientRatingValue(r);
  const feedbackIcon = icon || FEEDBACK_ICON_BY_VALUE[ratingValue] || '';
  if (!pageId || !ratingValue || feedbackSaving) return;

  feedbackSaving = true;
  setFeedbackButtonsDisabled(true);

  try {
    const res = await fetch('api.php?action=save_rating', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: pageId, rating: ratingValue })
    });

    let data = null;
    try { data = await res.json(); } catch (e) {}
    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Failed to save feedback');

    rememberFeedback(pageId, ratingValue);
    const page = S.pages.find(p => p.id === pageId);
    if (page && data) {
      applyPageRatingData(page, {
        ratingStats: data.ratingStats,
        ratingAverage: data.ratingAverage,
        ratingCount: data.ratingCount,
        ratingCsvAvailable: true,
      });
      if (pageId === S.currentPageId) renderAdminRatingPanel(page);
    }
    syncFeedbackButtons();
    showToast(t('toastFeedback') + feedbackIcon);
  } catch (e) {
    console.warn('Feedback save error:', e);
    showToast(t('toastFeedbackError'));
  } finally {
    feedbackSaving = false;
    setFeedbackButtonsDisabled(false);
  }
}

// ════════════════════════════════════════
//  UTILS
// ════════════════════════════════════════
// esc, sanitizeFooterHtml, footerHasText, applyFooterDisplay, generateOgImage
// are in assets/shared.js.

function canUseAdminPageShortcuts() {
  return !!S.authed && !!S.currentPageId;
}

function canUseEditorShortcuts() {
  return canUseAdminPageShortcuts() && S.editMode;
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
  const meta = e.metaKey || e.ctrlKey;
  const key = e.key.toLowerCase();

  if (meta && key === 's' && canUseEditorShortcuts()) {
    e.preventDefault();
    savePage();
  }
  if (meta && !e.shiftKey && key === 'z' && canUseEditorShortcuts()) {
    e.preventDefault();
    editorUndo();
  }
  if (meta && e.shiftKey && key === 'z' && canUseEditorShortcuts()) {
    e.preventDefault();
    editorRedo();
  }
  if (meta && key === 'y' && canUseEditorShortcuts()) {
    e.preventDefault();
    editorRedo();
  }
  if (meta && key === 'e' && canUseAdminPageShortcuts()) {
    e.preventDefault();
    toggleEdit();
  }
  if (meta && key === 'k') {
    e.preventDefault();
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
      searchInput.focus();
      searchInput.select();
    }
  }
  if (e.key === 'Escape') {
    closeModal('add-modal');
    closeSettings();
    closeSearchDD();
    closeIconPickerEl();
    closeChangePassword();
  }
});

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
      await refreshCurrentPageRatings(true);
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
  if (S.editMode) { S.editMode = false; syncEditUI(); }
  updateAdminUI();
  renderSpaces();
  renderPage();
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
  syncFeedbackPanelVisibility();
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
  document.getElementById('auth-sub').textContent = t('authLoginSubtitle');
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
      await load();
      S.currentSpaceId = S.spaces[0]?.id || null;
      const pages = spacePages();
      S.currentPageId = firstRootPage(pages)?.id || null;
      if (S.currentPageId) await loadPageContent(S.currentPageId);
      document.getElementById('setup-overlay').classList.remove('open');
      updateAdminUI();
      renderSpaces();
      renderNav();
      renderPage();
      await refreshCurrentPageRatings(true);
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
//  CHANGE PASSWORD (logged-in)
// ════════════════════════════════════════
function openChangePassword() {
  if (!S.authed) return;
  ['change-pw-current', 'change-pw-new', 'change-pw-confirm'].forEach(id => {
    const inp = document.getElementById(id);
    if (inp) inp.type = 'password';
  });
  document.getElementById('change-pw-current').value = '';
  document.getElementById('change-pw-new').value = '';
  document.getElementById('change-pw-confirm').value = '';
  document.getElementById('change-pw-error').innerHTML = '';
  document.querySelectorAll('#change-pw-overlay .pw-toggle i').forEach(i => i.className = 'fa-solid fa-eye');
  applyTranslations();
  validateChangePassword();
  closeSettings();
  document.getElementById('change-pw-overlay').classList.add('open');
  setTimeout(() => document.getElementById('change-pw-current').focus(), 80);
}

function closeChangePassword() {
  document.getElementById('change-pw-overlay').classList.remove('open');
}

function validateChangePassword() {
  const pw = document.getElementById('change-pw-new').value;
  const pw2 = document.getElementById('change-pw-confirm').value;

  const rules = {
    length:  pw.length >= 8,
    upper:   /[A-Z]/.test(pw),
    lower:   /[a-z]/.test(pw),
    number:  /[0-9]/.test(pw),
    special: /[^A-Za-z0-9]/.test(pw),
    match:   pw.length > 0 && pw === pw2,
  };

  Object.entries(rules).forEach(([key, pass]) => {
    const el = document.getElementById('cpw-rule-' + key);
    if (!el) return;
    el.className = 'setup-rule ' + (pass ? 'pass' : 'fail');
    el.querySelector('i').className = pass ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle';
  });

  const matchEl = document.getElementById('cpw-rule-match');
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
  document.getElementById('change-pw-btn').disabled = !allPass;
  return allPass;
}

async function submitChangePassword() {
  if (!validateChangePassword()) return;

  const current = document.getElementById('change-pw-current').value;
  const newPw = document.getElementById('change-pw-new').value;
  const errEl = document.getElementById('change-pw-error');
  const btn = document.getElementById('change-pw-btn');

  if (!current) {
    errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${t('changePwEnterCurrent')}`;
    document.getElementById('change-pw-current').focus();
    return;
  }

  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>${t('changePwSaving')}</span>`;
  errEl.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('action', 'change_password');
    fd.append('current_password', current);
    fd.append('new_password', newPw);
    const r = await fetch('auth.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const d = await r.json();

    if (d.ok) {
      closeChangePassword();
      showToast(t('changePwSuccess'));
    } else {
      errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${d.error || t('changePwError')}`;
      btn.disabled = false;
      btn.innerHTML = `<i class="fa-solid fa-key"></i> <span>${t('changePwBtn')}</span>`;
    }
  } catch(e) {
    errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${t('changePwError')}`;
    btn.disabled = false;
    btn.innerHTML = `<i class="fa-solid fa-key"></i> <span>${t('changePwBtn')}</span>`;
  }
}

// ════════════════════════════════════════
//  TRANSLATE
//  loadTranslateWidget, googleTranslateElementInit, translateTo, toggleTranslate
//  and the translate-wrap click handler are in assets/shared.js.
// ════════════════════════════════════════

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
        S.currentPageId = firstRootPage(sp)?.id || null;
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
let dragForbiddenIds = null;

// Drop zones within a row: top third = reorder before, bottom third = reorder
// after, middle third = nest as child.
function navDropZone(rect, clientY) {
  const ratio = (clientY - rect.top) / rect.height;
  if (ratio <= 0.30) return 'above';
  if (ratio >= 0.70) return 'below';
  return 'child';
}

function initDragDrop() {
  const tree = document.getElementById('nav-tree');
  if (!tree || !S.authed) return;

  const clearDropMarkers = () => {
    tree.querySelectorAll('.nav-item').forEach(i =>
      i.classList.remove('drag-over-above', 'drag-over-below', 'drag-over-child'));
  };

  tree.querySelectorAll('.nav-item').forEach(item => {
    const pageId = item.dataset.pageId;
    if (!pageId) return;
    item.draggable = true;

    item.addEventListener('dragstart', e => {
      dragSrcId = pageId;
      // Forbid dropping a page onto itself or any of its descendants (would create a cycle)
      dragForbiddenIds = collectDescendants(pageId);
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    item.addEventListener('dragend', () => {
      item.classList.remove('dragging');
      clearDropMarkers();
      dragSrcId = null;
      dragForbiddenIds = null;
    });
    item.addEventListener('dragover', e => {
      e.preventDefault();
      clearDropMarkers();
      if (!dragSrcId || dragForbiddenIds?.has(pageId)) { e.dataTransfer.dropEffect = 'none'; return; }
      item.classList.add('drag-over-' + navDropZone(item.getBoundingClientRect(), e.clientY));
    });
    item.addEventListener('dragleave', () => {
      item.classList.remove('drag-over-above', 'drag-over-below', 'drag-over-child');
    });
    item.addEventListener('drop', async e => {
      e.preventDefault();
      clearDropMarkers();

      const srcPage = S.pages.find(p => p.id === dragSrcId);
      const tgtPage = S.pages.find(p => p.id === pageId);
      if (!srcPage || !tgtPage || srcPage.spaceId !== tgtPage.spaceId) return;
      // Block cycles: a page cannot become a child of itself or its own descendants
      if (collectDescendants(srcPage.id).has(tgtPage.id)) return;

      const zone = navDropZone(item.getBoundingClientRect(), e.clientY);
      const asChild = zone === 'child';
      let affected;

      if (asChild) {
        // Nest the page as the last child of the target
        const children = S.pages
          .filter(p => p.spaceId === srcPage.spaceId && p.parentId === tgtPage.id && p.id !== srcPage.id)
          .sort((a, b) => a.order - b.order);
        children.push(srcPage);
        srcPage.parentId = tgtPage.id;
        srcPage.section = null; // sections only apply to root-level pages
        children.forEach((p, i) => p.order = i);
        affected = children;
      } else {
        // Reorder as a sibling, before or after the target
        const insertBefore = zone === 'above';
        const siblings = S.pages
          .filter(p => p.spaceId === srcPage.spaceId && p.parentId === tgtPage.parentId)
          .sort((a, b) => a.order - b.order)
          .filter(p => p.id !== srcPage.id);
        const tgtIdx = siblings.findIndex(p => p.id === pageId);
        siblings.splice(insertBefore ? tgtIdx : tgtIdx + 1, 0, srcPage);
        srcPage.parentId = tgtPage.parentId;
        // Adopt the target's section so section grouping stays consistent with the
        // visual order. Without this, a section-less root page dropped next to a
        // sectioned one makes the whole section-less group reflow to that spot.
        srcPage.section = tgtPage.parentId ? null : (tgtPage.section || null);
        siblings.forEach((p, i) => p.order = i);
        affected = siblings;
      }

      renderNav();

      if (asChild) {
        // Expand the target so the freshly nested page is visible
        document.getElementById('children-' + tgtPage.id)?.classList.add('open');
        const toggleBtn = tree.querySelector(`.nav-item[data-page-id="${tgtPage.id}"] .nav-toggle`);
        if (toggleBtn) { toggleBtn.classList.add('open'); toggleBtn.setAttribute('aria-expanded', 'true'); }
      }

      // Persist the moved page + reindexed siblings to their JSON files
      await persistPages(affected);
      showToast(t('toastOrderSaved'));
    });
  });
}

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
    delimiter: { type: 'delimiter', data: { style: 'stars' } },
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
//  CODE COPY BUTTONS
// ════════════════════════════════════════
function injectCodeCopyButtons() {
  document.querySelectorAll('.ce-code').forEach(block => {
    if (block.querySelector('.code-copy-btn')) return;
    const btn = document.createElement('button');
    btn.className = 'code-copy-btn';
    btn.innerHTML = `<i class="fa-regular fa-copy" style="font-style:normal"></i> ${t('codeCopy')}`;
    btn.onclick = (e) => {
      e.stopPropagation();
      const textarea = block.querySelector('.ce-code__textarea');
      if (!textarea) return;
      navigator.clipboard.writeText(textarea.value || textarea.textContent).then(() => {
        btn.innerHTML = `<i class="fa-solid fa-check" style="font-style:normal;color:#22c55e"></i> ${t('codeCopied')}`;
        setTimeout(() => { btn.innerHTML = `<i class="fa-regular fa-copy" style="font-style:normal"></i> ${t('codeCopy')}`; }, 1500);
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

// detectCodeLanguage is in assets/shared.js

// Re-inject after editor changes (new code blocks added)
const _origMarkDirty = markDirty;
markDirty = function() {
  _origMarkDirty();
  setTimeout(injectCodeCopyButtons, 300);
};

// ════════════════════════════════════════
//  MOBILE SIDEBAR
//  toggleMobileSidebar, closeMobileSidebar, popstate & scroll listeners,
//  toggleReadingMode, sharePage are in assets/shared.js.
// ════════════════════════════════════════

// ════════════════════════════════════════
//  READING MODE
//  (toggleReadingMode is in assets/shared.js)
// ════════════════════════════════════════

// ════════════════════════════════════════
//  SHARE PAGE
//  (sharePage is in assets/shared.js)
// ════════════════════════════════════════

// Load page from URL hash on init
function checkUrlHash() {
  const hash = window.location.hash.slice(1);
  if (hash && S.pages.find(p => p.id === hash)) {
    S.currentPageId = hash;
  }
}

// ════════════════════════════════════════
//  KEYBOARD SHORTCUTS OVERLAY
//  (toggleShortcuts, closeShortcuts are in assets/shared.js)
// ════════════════════════════════════════

// ════════════════════════════════════════
//  HOVER PREVIEW
//  (showHoverPreview, hideHoverPreview, hoverPreviewTimer, hoverPreviewEl
//   are in assets/shared.js)
// ════════════════════════════════════════

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
//  highlight, getBlockSearchText, getPageTextSnippet are in assets/shared.js.
// ════════════════════════════════════════

// Override handleSearch with enhanced version
handleSearch = function(q) {
  const dd = document.getElementById('search-dd');
  if (!q.trim()) { dd.innerHTML = ''; dd.classList.remove('open'); return; }

  const ql = q.toLowerCase();
  const results = S.pages.filter(p =>
    p.title.toLowerCase().includes(ql) ||
    (p.subtitle || '').toLowerCase().includes(ql) ||
    (p.content?.blocks || []).some(b => getBlockSearchText(b).some(txt => txt.toLowerCase().includes(ql)))
  ).slice(0, 8);

  if (!results.length) {
    dd.innerHTML = `<div class="search-empty"><i class="fa-solid fa-magnifying-glass" style="margin-right:6px"></i>${t('searchNoResults')}</div>`;
  } else {
    dd.innerHTML = results.map(p => {
      const snippet = getPageTextSnippet(p, q);
      const titleHl = highlight(p.title, q);
      const subtitleHl = p.subtitle ? highlight(p.subtitle.slice(0, 60), q) : '';
      return `
        <a class="search-result-item" href="${esc(buildPageHref(p.id))}" data-page-id="${esc(p.id)}" data-space-id="${esc(p.spaceId || '')}" data-close-search="1">
          <i class="fa-solid ${p.icon || 'fa-file'}"></i>
          <div style="min-width:0;flex:1">
            <div class="search-result-title">${titleHl}</div>
            ${subtitleHl ? `<div class="search-result-path">${subtitleHl}</div>` : ''}
            ${snippet ? `<div class="search-result-snippet">${snippet}</div>` : ''}
          </div>
        </a>`;
    }).join('');
  }
  bindEditorPageLinks(dd);
  dd.classList.add('open');
};

// ════════════════════════════════════════
//  EDITOR-ONLY KEYBOARD SHORTCUTS
//  Reader-level shortcuts (? overlay, Cmd+R, Cmd+Shift+C, Esc, ←→) are
//  registered in assets/shared.js. Only the editor-specific Cmd+/ slash
//  menu shortcut lives here.
// ════════════════════════════════════════
document.addEventListener('keydown', e => {
  const meta = e.metaKey || e.ctrlKey;

  // Cmd+/ = slash menu anywhere in editor
  if (meta && e.key === '/' && canUseEditorShortcuts()) {
    e.preventDefault();
    const toolbar = document.querySelector('.ce-toolbar__plus');
    if (toolbar) {
      const rect = toolbar.getBoundingClientRect();
      openSlashMenu(rect.left, rect.bottom, '');
    }
  }
});

// navigatePrevNext is in assets/shared.js

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
  <button class="ct-btn" id="ct-link-page" data-i18n-attr="title" data-i18n="linkInternalTitle" title="Link to page"><i class="fa-solid fa-file-lines"></i></button>
  <button class="ct-btn" id="ct-link-ok" data-i18n-attr="title" data-i18n="btnSaveChanges" title="Confirm"><i class="fa-solid fa-check" style="color:#16a34a;"></i></button>
  <button class="ct-btn" id="ct-link-remove" data-i18n-attr="title" data-i18n="ctLinkRemove" title="Remove link"><i class="fa-solid fa-link-slash" style="color:#ef4444;"></i></button>
</div>
<!-- Internal page picker popup (shared: inline link tool + callout link bar) -->
<div class="link-page-picker" id="link-page-picker">
  <input id="lpp-input" type="text" data-i18n-ph="linkPickerPlaceholder" placeholder="Search pages…">
  <div class="lpp-results" id="lpp-results"></div>
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
// ── Internal page picker (shared by inline link tool + callout link bar) ──
(function() {
  const pop = document.getElementById('link-page-picker');
  if (!pop) return;
  const input = document.getElementById('lpp-input');
  const results = document.getElementById('lpp-results');
  let resolveFn = null;
  let excludeId = null;

  function finish(result) {
    pop.style.display = 'none';
    document.removeEventListener('mousedown', onDocDown, true);
    const r = resolveFn; resolveFn = null;
    if (r) r(result);
  }

  function renderResults() {
    const q = input.value.trim().toLowerCase();
    let list = (S.pages || []).filter(p =>
      p.spaceId === S.currentSpaceId && p.id !== excludeId
    );
    if (q) list = list.filter(p =>
      p.title.toLowerCase().includes(q) || (p.subtitle || '').toLowerCase().includes(q)
    );
    list = list.slice(0, 8);
    if (!list.length) {
      results.innerHTML = `<div class="search-empty">${esc(t('linkPickerEmpty'))}</div>`;
      return;
    }
    results.innerHTML = list.map(p => `
      <button type="button" class="search-result-item lpp-item" data-id="${esc(p.id)}">
        <i class="fa-solid ${esc(p.icon || 'fa-file')}"></i>
        <div>
          <div class="search-result-title">${esc(p.title)}</div>
          ${p.subtitle ? `<div class="search-result-path">${esc(p.subtitle.slice(0,60))}</div>` : ''}
        </div>
      </button>`).join('');
    results.querySelectorAll('.lpp-item').forEach(btn => {
      btn.addEventListener('mousedown', e => { e.preventDefault(); finish(btn.dataset.id); });
    });
  }

  function onDocDown(e) {
    if (pop.contains(e.target)) return;
    finish(null);
  }

  input.addEventListener('input', renderResults);
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.preventDefault(); finish(null); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      const first = results.querySelector('.lpp-item');
      if (first) finish(first.dataset.id);
    }
  });

  window.openPagePicker = function(rect, opts = {}) {
    excludeId = opts.excludeId || null;
    return new Promise(resolve => {
      resolveFn = resolve;
      input.value = '';
      renderResults();
      pop.style.visibility = 'hidden';
      pop.style.display = 'block';
      const pw = pop.offsetWidth, ph = pop.offsetHeight;
      let left = rect ? (rect.left + rect.width / 2 - pw / 2) : (window.innerWidth / 2 - pw / 2);
      let top = rect ? (rect.bottom + 8) : 120;
      if (rect && (rect.bottom + ph + 8) > window.innerHeight) top = rect.top - ph - 8;
      left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
      top = Math.max(8, top);
      pop.style.left = left + 'px';
      pop.style.top = top + 'px';
      pop.style.visibility = 'visible';
      setTimeout(() => input.focus(), 0);
      setTimeout(() => document.addEventListener('mousedown', onDocDown, true), 0);
    });
  };
})();

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
      const sel0 = window.getSelection();
      const calloutEl = isInCallout(sel0 && sel0.anchorNode);
      if (calloutEl) { calloutEl.focus(); restoreRange(); }
      const isInternal = /^\?page=/.test(url);
      const fullUrl = isInternal ? url : (url.match(/^https?:\/\//) ? url : 'https://' + url);
      document.execCommand('createLink', false, fullUrl);
      const sel = window.getSelection();
      const a = sel?.anchorNode?.parentElement?.closest('a');
      if (a) {
        if (isInternal) {
          a.setAttribute('href', url);
          a.setAttribute('data-page-id', decodeURIComponent(url.replace(/^\?page=/, '')));
          a.removeAttribute('target');
        } else {
          a.target = '_blank';
        }
      }
      const activeEl = isInCallout(sel?.anchorNode);
      if (activeEl) activeEl.dispatchEvent(new Event('input'));
    }
    hideAll();
  }

  document.getElementById('ct-link-ok').addEventListener('mousedown', e => { e.preventDefault(); applyLink(); });
  document.getElementById('ct-link-page').addEventListener('mousedown', e => {
    e.preventDefault();
    if (typeof window.openPagePicker !== 'function') return;
    const rect = linkBar.getBoundingClientRect();
    window.openPagePicker(rect, { excludeId: S.currentPageId }).then(pageId => {
      if (!pageId) return;
      linkInput.value = '?page=' + encodeURIComponent(pageId);
      applyLink();
    });
  });
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
