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
<link rel="stylesheet" href="assets/app.css">

<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js" defer></script>
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
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutSearch">Search</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>K</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutReadingMode">Reading mode</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>R</kbd></span></div>
    <div class="shortcut-row" id="shortcut-share-row"><span class="shortcut-label" data-i18n="shortcutShare">Share page</span><span class="shortcut-keys"><kbd>⌘</kbd><kbd>⇧</kbd><kbd>C</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutPrevNext">Previous / Next page</span><span class="shortcut-keys"><kbd>←</kbd><kbd>→</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label" data-i18n="shortcutShortcuts">Shortcuts</span><span class="shortcut-keys"><kbd>?</kbd></span></div>
    <div class="shortcut-row"><span class="shortcut-label">Esc</span><span class="shortcut-keys"><kbd>Esc</kbd></span></div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <div class="toast-dot"></div>
  <span id="toast-text">Saved</span>
</div>

<!-- ════ SCRIPTS ════ -->
<script>
// ════════════════════════════════════════════════════════════
//  Webstudio Docs — read-only public viewer
//  Renders saved page content WITHOUT EditorJS.
//  TRANSLATIONS / DEFAULT_INTERFACE_LANG / LANG_LOCALES / ICON_LIST
//  are provided by assets/i18n.js.
// ════════════════════════════════════════════════════════════

// ── Viewer-only style supplements (replace CSS the EditorJS tools used to inject) ──
(function () {
  const css = `
    .ce-code .viewer-code{margin:0;padding:14px 44px 14px 16px;overflow:auto;background:transparent !important;font-family:var(--mono);font-size:13px;line-height:1.6;text-shadow:none !important;}
    .ce-code .viewer-code code{background:transparent !important;font-family:var(--mono);font-size:13px;line-height:1.6;text-shadow:none !important;white-space:pre;}
    [data-theme="dark"] .ce-code .viewer-code code:not([class*="language-"]){color:#7dd3fc;}
    [data-theme="light"] .ce-code .viewer-code code:not([class*="language-"]){color:#0f4c81;}
    .tc-table{border-collapse:collapse;width:100%;}
    .tc-cell{border:1px solid var(--border);padding:8px 12px;text-align:left;vertical-align:top;}
    .tc-table th.tc-cell{background:var(--bg3);font-weight:600;}
    .page-hero .page-icon{cursor:default;}
    .page-title-input{margin:0;}
  `;
  const st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);
})();

// ════════════════════════════════════════
//  STATE
// ════════════════════════════════════════
let S = {
  pages: [],
  spaces: [],
  currentSpaceId: null,
  currentPageId: null,
  authed: false,
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
// scrollSpyObserver is declared in assets/shared.js
let feedbackSaving = false;

// FEEDBACK_STORAGE_KEY is in assets/shared.js
const THEME_STORAGE_KEY = 'ws_docs_theme';
const ACCESS_TOKEN_QUERY_PARAM = 'token';
const ACCESS_TOKEN_STORAGE_KEY = 'ws_docs_access_token';
let accessTokenCache = '';

function getAccessTokenFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return params.get(ACCESS_TOKEN_QUERY_PARAM) || '';
}

function readStoredAccessToken() {
  try {
    return sessionStorage.getItem(ACCESS_TOKEN_STORAGE_KEY) || '';
  } catch (e) {
    return '';
  }
}

function writeStoredAccessToken(token) {
  accessTokenCache = token || '';
  try {
    if (accessTokenCache) sessionStorage.setItem(ACCESS_TOKEN_STORAGE_KEY, accessTokenCache);
    else sessionStorage.removeItem(ACCESS_TOKEN_STORAGE_KEY);
  } catch (e) {}
}

function getActiveAccessToken() {
  if (accessTokenCache) return accessTokenCache;

  const urlToken = getAccessTokenFromUrl();
  if (urlToken) {
    writeStoredAccessToken(urlToken);
    return urlToken;
  }

  const storedToken = readStoredAccessToken();
  if (storedToken) {
    accessTokenCache = storedToken;
    return storedToken;
  }

  return '';
}

function buildViewerUrl(pageId = '', options = {}) {
  const { includeAccessToken = false } = options;
  const url = new URL(window.location.pathname, window.location.origin);
  if (pageId) url.searchParams.set('page', pageId);

  if (includeAccessToken) {
    const accessToken = getActiveAccessToken();
    if (accessToken) url.searchParams.set(ACCESS_TOKEN_QUERY_PARAM, accessToken);
  }

  return url.pathname + url.search + url.hash;
}

function buildPageHref(pageId = '') {
  return buildViewerUrl(pageId);
}

// isPrimaryNavigationClick is in assets/shared.js

function internalPageIdFromLink(link) {
  let id = link.dataset.pageId || '';
  if (!id) {
    const href = link.getAttribute('href') || '';
    const m = href.match(/^\?page=([^&#]*)/);
    if (m) { try { id = decodeURIComponent(m[1]); } catch (e) { id = m[1]; } }
  }
  return id.replace(/[^A-Za-z0-9_-]/g, '');
}

function bindViewerPageLinks(root = document) {
  root.querySelectorAll('a[data-page-id], a[href^="?page="]').forEach(link => {
    if (link.dataset.navBound === '1') return;
    const pageId = internalPageIdFromLink(link);
    if (!pageId) return;
    link.dataset.navBound = '1';
    link.addEventListener('click', event => {
      if (!isPrimaryNavigationClick(event)) return;
      event.preventDefault();

      const targetSpaceId = link.dataset.spaceId || '';
      const shouldCloseSearch = link.dataset.closeSearch === '1';

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
// can call the viewer's link binder without knowing its specific name.
const bindPageLinks = bindViewerPageLinks;

function stripAccessTokenFromCurrentUrl() {
  if (!getAccessTokenFromUrl()) return;
  history.replaceState(history.state, '', buildViewerUrl(new URLSearchParams(window.location.search).get('page') || ''));
}

function restoreAccessTokenInUrlForReload() {
  const accessToken = getActiveAccessToken();
  if (!accessToken) return;

  const pageId = new URLSearchParams(window.location.search).get('page') || '';
  history.replaceState(history.state, '', buildViewerUrl(pageId, { includeAccessToken: true }));
}

function withAccessToken(url) {
  const accessToken = getActiveAccessToken();
  if (!accessToken) return url;

  const resolvedUrl = new URL(url, window.location.href);
  if (!resolvedUrl.searchParams.has(ACCESS_TOKEN_QUERY_PARAM)) {
    resolvedUrl.searchParams.set(ACCESS_TOKEN_QUERY_PARAM, accessToken);
  }

  return resolvedUrl.pathname + resolvedUrl.search + resolvedUrl.hash;
}

writeStoredAccessToken(getAccessTokenFromUrl() || readStoredAccessToken());
stripAccessTokenFromCurrentUrl();
window.addEventListener('beforeunload', restoreAccessTokenInUrlForReload);

// ════════════════════════════════════════
//  DATA — server JSON (api.php, read-only)
// ════════════════════════════════════════
async function load() {
  try {
    const r = await fetch(withAccessToken('api.php?action=load'), { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) throw new Error(d.error);
    S.spaces   = d.spaces   || [];
    S.settings = { ...S.settings, ...(d.settings || {}) };
    S.pages = (d.pages || []).map(p => ({ ...p, _contentLoaded: !!p.content?.blocks }));
    // Migrate old list format (string items → { content, items })
    S.pages.forEach(p => {
      if (p.content?.blocks) {
        p.content.blocks.forEach(b => {
          if (b.type === 'list' && Array.isArray(b.data?.items)) {
            b.data.items = b.data.items.map(i => typeof i === 'string' ? { content: i, items: [] } : i);
          }
        });
      }
    });
  } catch (e) {
    console.warn('Load error:', e);
  }
}

async function loadPageContent(pageId) {
  const page = S.pages.find(p => p.id === pageId);
  if (!page || page._contentLoaded) return page;
  try {
    const r = await fetch(withAccessToken(`api.php?action=load_page&id=${encodeURIComponent(pageId)}`), { credentials: 'same-origin' });
    const d = await r.json();
    if (d.ok && d.page) {
      Object.assign(page, d.page);
      page._contentLoaded = true;
    }
  } catch (e) {}
  return page;
}

// ════════════════════════════════════════
//  UTILITIES (shared helpers in assets/shared.js: esc, sanitizeFooterHtml,
//  footerHasText, applyFooterDisplay, showToast, formatDate, slugify,
//  highlight, getBlockSearchText, getPageTextSnippet, detectCodeLanguage)
// ════════════════════════════════════════

// ════════════════════════════════════════
//  SETTINGS / THEME
// ════════════════════════════════════════
function applySettings() {
  const s = S.settings;
  document.documentElement.dataset.theme = s.theme;
  document.documentElement.lang = s.lang || DEFAULT_INTERFACE_LANG;

  const themeBtn = document.getElementById('theme-btn');
  if (themeBtn) themeBtn.innerHTML = s.theme === 'dark'
    ? '<i class="fa-solid fa-moon"></i>' : '<i class="fa-solid fa-sun"></i>';

  const prismLink = document.getElementById('prism-theme');
  if (prismLink) prismLink.href = s.theme === 'dark'
    ? 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css'
    : 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css';

  const r = parseInt(s.accentColor.slice(1, 3), 16);
  const g = parseInt(s.accentColor.slice(3, 5), 16);
  const b = parseInt(s.accentColor.slice(5, 7), 16);
  document.documentElement.style.setProperty('--accent', s.accentColor);
  document.documentElement.style.setProperty('--accent-rgb', `${r},${g},${b}`);

  const logoEl = document.getElementById('logo-display');
  if (logoEl) logoEl.innerHTML = s.logoDataUrl
    ? `<img src="${s.logoDataUrl}" alt="logo">`
    : `<i class="fa-solid fa-book-open"></i>`;

  const faviconLink = document.getElementById('dynamic-favicon');
  if (faviconLink) {
    if (s.faviconDataUrl) { faviconLink.href = s.faviconDataUrl; faviconLink.type = ''; }
    else faviconLink.href = 'data:,';
  }

  const logoName = document.getElementById('logo-name-display');
  if (logoName) logoName.textContent = s.siteName || 'My Docs';
  applyFooterDisplay();
  document.title = s.tabTitle || 'Docs';

  applySpaceTabsVisibility();
  applyShareSectionAvailability();
  applyTranslateAvailability();
  applyTranslations();
}

function toggleTheme() {
  S.settings.theme = S.settings.theme === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(THEME_STORAGE_KEY, S.settings.theme); } catch (e) {}
  applySettings();
}

// ════════════════════════════════════════
//  SPACES (tab bar) + SIDEBAR NAV
// ════════════════════════════════════════
function renderSpaces() {
  const strip = document.getElementById('tab-strip');
  if (!strip) return;
  strip.innerHTML = '';
  S.spaces.forEach(sp => {
    const spacePageList = S.pages.filter(p => p.spaceId === sp.id);
    const firstPage = firstRootPage(spacePageList);
    const el = document.createElement('a');
    el.className = 'tab-item' + (sp.id === S.currentSpaceId ? ' active' : '');
    el.innerHTML = `<i class="fa-solid ${sp.icon || 'fa-book'}"></i><span>${esc(sp.name)}</span>`;
    el.href = buildPageHref(firstPage?.id || '');
    if (firstPage) {
      el.dataset.pageId = firstPage.id;
      el.dataset.spaceId = sp.id;
    } else {
      el.addEventListener('click', event => {
        if (!isPrimaryNavigationClick(event)) return;
        event.preventDefault();
        switchSpace(sp.id);
      });
    }
    strip.appendChild(el);
  });

  bindViewerPageLinks(strip);

  applySpaceTabsVisibility();
}

function switchSpace(id) {
  S.currentSpaceId = id;
  const pages = spacePages();
  const first = firstRootPage(pages);
  renderSpaces();
  renderNav();
  if (first) navigateTo(first.id);
  else { S.currentPageId = null; renderPage(); }
}

function renderNav() {
  const tree = document.getElementById('nav-tree');
  tree.innerHTML = '';
  const pages = spacePages();
  const rootPages = pages.filter(p => !p.parentId).sort((a, b) => a.order - b.order);

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
    rootPages.filter(p => (p.section || '') === sec).forEach(p => renderNavItem(p, tree, 0, pages));
  });
}

function renderNavItem(page, container, depth, allPages) {
  const children = allPages.filter(p => p.parentId === page.id).sort((a, b) => a.order - b.order);
  const isActive = S.currentPageId === page.id;
  const childActive = children.some(c => c.id === S.currentPageId || allPages.filter(x => x.parentId === c.id).some(g => g.id === S.currentPageId));
  const isOpen = isActive || childActive;

  const wrap = document.createElement('div');
  const item = document.createElement('div');
  item.className = 'nav-item' + (isActive ? ' active' : '');
  item.style.paddingLeft = `${12 + depth * 16}px`;
  item.dataset.pageId = page.id;

  if (children.length) {
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'nav-toggle' + (isOpen ? ' open' : '');
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    toggle.setAttribute('aria-controls', 'children-' + page.id);
    toggle.setAttribute('aria-label', `Toggle ${page.title}`);
    toggle.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
    toggle.addEventListener('click', () => toggleChildren(toggle, page.id));
    item.appendChild(toggle);
  } else {
    const spacer = document.createElement('div');
    spacer.className = 'nav-toggle-spacer';
    item.appendChild(spacer);
  }

  const link = document.createElement('a');
  link.className = 'nav-link';
  link.href = buildPageHref(page.id);
  link.dataset.pageId = page.id;
  link.innerHTML = `
    <span class="nav-ic"><i class="fa-solid ${page.icon || 'fa-file'}"></i></span>
    <span class="nav-label">${esc(page.title)}</span>
  `;
  item.appendChild(link);

  item.addEventListener('mouseenter', () => showHoverPreview(page.id, item));
  item.addEventListener('mouseleave', hideHoverPreview);
  wrap.appendChild(item);

  if (children.length) {
    const sub = document.createElement('div');
    sub.className = 'nav-children' + (isOpen ? ' open' : '');
    sub.id = 'children-' + page.id;
    children.forEach(c => renderNavItem(c, sub, depth + 1, allPages));
    wrap.appendChild(sub);
  }
  container.appendChild(wrap);
  bindViewerPageLinks(wrap);
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
  if (scrollSpyObserver) { scrollSpyObserver.disconnect(); scrollSpyObserver = null; }
  S.currentPageId = pageId;
  renderNav();
  await loadPageContent(pageId);
  renderPage();
  history.pushState({ pageId }, '', buildViewerUrl(pageId));
  document.querySelector('.content-wrap')?.scrollTo(0, 0);
  window.scrollTo(0, 0);
}

// ════════════════════════════════════════
//  CONTENT RENDERER (EditorJS-free, read-only)
//  Emits the same DOM/classes EditorJS produced so app.css styles it.
// ════════════════════════════════════════
function renderBlocks(content) {
  const blocks = (content && content.blocks) || [];
  if (!blocks.length) return '';
  return blocks.map(b => {
    const html = renderBlock(b);
    return html ? `<div class="ce-block"><div class="ce-block__content">${html}</div></div>` : '';
  }).join('');
}

function renderBlock(b) {
  const d = b.data || {};
  switch (b.type) {
    case 'paragraph':  return `<div class="ce-paragraph cdx-block">${d.text || ''}</div>`;
    case 'header': {
      const lvl = Math.min(6, Math.max(1, d.level || 2));
      return `<h${lvl} class="ce-header">${d.text || ''}</h${lvl}>`;
    }
    case 'list':       return renderList(d);
    case 'checklist':  return renderChecklist(d);
    case 'quote':      return renderQuote(d);
    case 'code':       return renderCode(d);
    case 'delimiter': {
      const st = ['stars','line','dashed','dots'].includes(d.style) ? d.style : 'stars';
      return `<div class="ce-delimiter cdx-delimiter cdx-block ce-delimiter--${st}"></div>`;
    }
    case 'table':      return renderTable(d);
    case 'image':      return renderImage(d);
    case 'warning':    return renderCallout(d);
    case 'timeline':   return renderTimeline(d);
    case 'collapse':   return renderCollapse(d);
    case 'video':      return renderVideo(d);
    case 'cards':      return renderCards(d);
    default:           return d.text ? `<div class="ce-paragraph cdx-block">${d.text}</div>` : '';
  }
}

function renderList(d) {
  const ordered = d.style === 'ordered';
  const tag = ordered ? 'ol' : 'ul';
  const cls = ordered ? 'cdx-list cdx-list--ordered' : 'cdx-list cdx-list--unordered';
  const build = (items) => {
    if (!items || !items.length) return '';
    return `<${tag} class="${cls}">` + items.map(it => {
      const itemContent = typeof it === 'string' ? it : (it.content || '');
      const sub = (it && it.items && it.items.length) ? build(it.items) : '';
      return `<li class="cdx-list__item">${itemContent}${sub}</li>`;
    }).join('') + `</${tag}>`;
  };
  return build(d.items || []);
}

function renderChecklist(d) {
  const items = d.items || [];
  return `<div class="cdx-checklist">` + items.map(it => {
    const checked = it.checked ? ' cdx-checklist__item--checked' : '';
    return `<div class="cdx-checklist__item${checked}"><span class="cdx-checklist__item-checkbox"></span><div class="cdx-checklist__item-text">${it.text || ''}</div></div>`;
  }).join('') + `</div>`;
}

function renderQuote(d) {
  return `<blockquote class="cdx-quote"><div class="cdx-quote__text">${d.text || ''}</div>${d.caption ? `<div class="cdx-quote__caption">${d.caption}</div>` : ''}</blockquote>`;
}

function renderCode(d) {
  const code = d.code || '';
  const lang = detectCodeLanguage(code);
  return `<div class="ce-code"><pre class="viewer-code language-${lang}"><code class="language-${lang}">${esc(code)}</code></pre></div>`;
}

function renderTable(d) {
  const rows = d.content || [];
  const withHeadings = !!d.withHeadings;
  let html = `<div class="tc-wrap"><table class="tc-table"><tbody>`;
  rows.forEach((row, ri) => {
    html += `<tr class="tc-row">`;
    (row || []).forEach(cell => {
      const tag = (withHeadings && ri === 0) ? 'th' : 'td';
      // Preserve line breaks: keep saved <br> and also normalize any raw
      // newlines (e.g. from pasted multi-line text) into <br>.
      const cellHtml = String(cell || '').replace(/\r\n|\r|\n/g, '<br>');
      html += `<${tag} class="tc-cell">${cellHtml}</${tag}>`;
    });
    html += `</tr>`;
  });
  html += `</tbody></table></div>`;
  return html;
}

function renderImage(d) {
  if (!d.url) return '';
  const cls = 'local-image-tool'
    + (d.stretched ? ' lit-stretched' : '')
    + (d.withBorder ? ' lit-border' : '')
    + (d.withBackground ? ' lit-bg' : '');
  return `<div class="${cls}"><img src="${esc(d.url)}" class="lit-img" alt="${esc(d.caption || '')}">${d.caption ? `<div class="lit-caption">${esc(d.caption)}</div>` : ''}</div>`;
}

function renderCallout(d) {
  const TYPES = {
    info:    { icon: 'fa-circle-info',          color: 'var(--accent)', bg: 'rgba(var(--accent-rgb),0.08)' },
    tip:     { icon: 'fa-lightbulb',            color: '#16a34a',       bg: 'rgba(22,163,74,0.08)' },
    warning: { icon: 'fa-triangle-exclamation', color: '#ca8a04',       bg: 'rgba(202,138,4,0.08)' },
    danger:  { icon: 'fa-circle-exclamation',   color: '#dc2626',       bg: 'rgba(220,38,38,0.08)' },
  };
  const cfg = TYPES[d.type] || TYPES.info;
  return `<div class="callout-block" style="border-left:3px solid ${cfg.color};background:${cfg.bg};border-radius:6px;padding:12px 16px;width:100%;box-sizing:border-box;">
    <div style="display:flex;align-items:flex-start;gap:10px;">
      <i class="fa-solid ${cfg.icon}" style="color:${cfg.color};margin-top:2px;flex-shrink:0;font-size:16px;font-style:normal;"></i>
      <div style="flex:1;min-width:0;overflow-wrap:break-word;word-break:break-word;">
        ${d.title ? `<div style="font-weight:600;font-size:14px;color:var(--text);margin-bottom:2px;overflow-wrap:break-word;word-break:break-word;">${d.title}</div>` : ''}
        ${d.message ? `<div style="font-size:14px;color:var(--text2);line-height:1.5;overflow-wrap:break-word;word-break:break-word;">${d.message}</div>` : ''}
      </div>
    </div>
  </div>`;
}

function renderTimeline(d) {
  const numbered = !!d.numbered;
  const items = d.items || [];
  let html = `<div class="tl-wrap">`;
  items.forEach((item, i) => {
    html += `<div class="tl-item">`;
    html += `<div class="tl-left" style="width:${numbered ? '48px' : '40px'}">`;
    html += `<div class="tl-line tl-line-top"></div>`;
    html += `<div class="${numbered ? 'tl-dot tl-dot-num' : 'tl-dot'}">${numbered ? (i + 1) : ''}</div>`;
    html += `<div class="tl-line"></div>`;
    html += `</div><div class="tl-content">`;
    if (item.date)  html += `<div class="tl-date">${esc(item.date)}</div>`;
    if (item.title) html += `<div class="tl-title">${esc(item.title)}</div>`;
    if (item.desc)  html += `<div class="tl-desc">${esc(item.desc).replace(/\n/g, '<br>')}</div>`;
    html += `</div></div>`;
  });
  html += `</div>`;
  return html;
}

function renderCollapse(d) {
  const open = d.open ? ' open' : '';
  const body = esc(d.body || '').replace(/\n/g, '<br>');
  return `<div class="collapsible-block${open}">
    <div class="collapsible-header" onclick="this.parentElement.classList.toggle('open')">
      <i class="fa-solid fa-chevron-right collapsible-chevron" style="font-style:normal"></i>
      <span class="collapsible-title-text">${esc(d.title || 'Section')}</span>
    </div>
    <div class="collapsible-body">${body}</div>
  </div>`;
}

function renderVideo(d) {
  if (!d.embedUrl) return '';
  return `<div class="video-block"><iframe src="${esc(d.embedUrl)}" allowfullscreen allow="autoplay; encrypted-media"></iframe></div>`;
}

function renderCards(d) {
  const cols = d.cols || 3;
  const cards = d.cards || [];
  let html = `<div class="cards-grid cols-${cols}">`;
  cards.forEach(card => {
    const linked = card.link ? ' card-linked' : '';
    const arrow = card.link ? '<i class="fa-solid fa-arrow-up-right-from-square card-link-arrow" style="font-style:normal"></i>' : '';
    let attrs = '';
    if (card.link) {
      const safe = esc(card.link).replace(/'/g, '%27');
      attrs = /^https?:\/\//.test(card.link)
        ? ` style="cursor:pointer" onclick="window.open('${safe}','_blank','noopener')"`
        : ` style="cursor:pointer" onclick="navigateTo('${safe}')"`;
    }
    html += `<div class="card-item${linked}"${attrs}>${arrow}<div class="card-icon"><i class="fa-solid ${card.icon || 'fa-star'}" style="font-style:normal"></i></div><div class="card-title">${esc(card.title || '')}</div><div class="card-desc">${esc(card.desc || '')}</div></div>`;
  });
  html += `</div>`;
  return html;
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
    syncFeedbackButtons();
    updateTOC();
    updatePageNavBottom(null);
    return;
  }

  // Breadcrumb — full ancestor trail
  const ancestors = [];
  let cur = page;
  while (cur.parentId) {
    const parent = S.pages.find(p => p.id === cur.parentId);
    if (!parent) break;
    ancestors.unshift(parent);
    cur = parent;
  }
  const breadParts = [];
  const sectionSource = ancestors.length ? ancestors[0] : page;
  if (sectionSource.section) {
    breadParts.push(`<span style="pointer-events:none;cursor:default;">${esc(sectionSource.section)}</span>`);
    breadParts.push(`<i class="fa-solid fa-chevron-right"></i>`);
  }
  ancestors.forEach(a => {
    breadParts.push(`<a href="${esc(buildPageHref(a.id))}" data-page-id="${esc(a.id)}">${esc(a.title)}</a>`);
    breadParts.push(`<i class="fa-solid fa-chevron-right"></i>`);
  });
  breadParts.push(`<span>${esc(page.title)}</span>`);

  view.innerHTML = `
    ${page.cover ? `<div class="page-cover" id="page-cover-el" style="${page.cover.type === 'color' ? 'background:' + page.cover.value : ''}">
      ${page.cover.type === 'image' ? `<img src="${esc(page.cover.value)}" alt="" style="object-fit:${page.cover.fit || 'cover'};object-position:${page.cover.position || '50% 50%'}">` : ''}
    </div>` : ''}
    <div class="breadcrumb">${breadParts.join('')}</div>
    <div class="page-hero">
      <div class="page-icon-wrap">
        <div class="page-icon"><i class="fa-solid ${page.icon || 'fa-file'}"></i></div>
      </div>
      <div style="flex:1;min-width:0;display:flex;align-items:center;gap:12px">
        <h1 class="page-title-input" id="pg-title">${esc(page.title)}</h1>
        <div style="display:flex;align-items:center;gap:8px;margin-left:auto;flex-shrink:0">
          <div class="reading-time" id="reading-time-el"><i class="fa-regular fa-clock"></i> <span>...</span></div>
        </div>
      </div>
    </div>
    <div class="page-desc" id="pg-desc" style="padding-bottom:16px;padding-left:52px">${page.subtitle ? esc(page.subtitle) : ''}</div>
    <div class="page-divider"></div>
    <div id="editor">${renderBlocks(page.content)}</div>
    <div id="page-last-updated"></div>
    <div id="page-nav-bottom"></div>
  `;

  bindViewerPageLinks(view);
  updatePageNavBottom(page);
  enhanceCodeBlocks();
  updateTOC();
  initScrollSpy();
  updateReadingTime();

  // Document title + OG tags
  const siteName = S.settings?.siteName || 'Docs';
  document.title = `${page.title} — ${siteName}`;
  const ogTitle = document.getElementById('og-title');
  const ogDesc = document.getElementById('og-desc');
  const ogSite = document.getElementById('og-site');
  const ogImage = document.getElementById('og-image');
  if (ogTitle) ogTitle.content = page.title;
  if (ogDesc) ogDesc.content = page.subtitle || `${siteName} documentation`;
  if (ogSite) ogSite.content = siteName;
  if (ogImage) {
    const coverUrl = page.cover?.type === 'image' ? page.cover.value : '';
    ogImage.content = coverUrl || generateOgImage(page.title, page.subtitle || '', siteName);
  }

  syncFeedbackButtons();
}

// ════════════════════════════════════════
//  TABLE OF CONTENTS + SCROLL SPY
// (updateTOC, initScrollSpy, slugify are in assets/shared.js)
// ════════════════════════════════════════
// scrollSpyObserver is declared in assets/shared.js

// ════════════════════════════════════════
//  SEARCH (title + subtitle + content)
// ════════════════════════════════════════
// highlight, getBlockSearchText, getPageTextSnippet are in assets/shared.js

function handleSearch(q) {
  const dd = document.getElementById('search-dd');
  if (!q.trim()) { dd.innerHTML = ''; dd.classList.remove('open'); return; }

  const ql = decodeHtmlEntities(q).toLowerCase();
  const results = S.pages.filter(p => {
    const titleText = decodeHtmlEntities(p.title || '').toLowerCase();
    const subtitleText = decodeHtmlEntities(p.subtitle || '').toLowerCase();
    return titleText.includes(ql) ||
      subtitleText.includes(ql) ||
      (p.content?.blocks || []).some(b => getBlockSearchText(b).some(txt => txt.toLowerCase().includes(ql)));
  }).slice(0, 8);

  if (!results.length) {
    dd.innerHTML = `<div class="search-empty"><i class="fa-solid fa-magnifying-glass" style="margin-right:6px"></i>${t('searchNoResults')}</div>`;
  } else {
    dd.innerHTML = results.map(p => {
      const snippet = getPageTextSnippet(p, q);
      const titleHl = highlight(p.title, q);
      const subtitleText = decodeHtmlEntities(p.subtitle || '');
      const subtitleHl = subtitleText ? highlight(subtitleText.slice(0, 60), q) : '';
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
  bindViewerPageLinks(dd);
  dd.classList.add('open');
}

// openSearchDD, closeSearchDD, selectSearch are in assets/shared.js

// ════════════════════════════════════════
//  CODE — copy button + Prism highlighting
// ════════════════════════════════════════
function enhanceCodeBlocks() {
  document.querySelectorAll('#editor .ce-code').forEach(block => {
    const codeEl = block.querySelector('code');
    if (!block.querySelector('.code-copy-btn')) {
      const btn = document.createElement('button');
      btn.className = 'code-copy-btn';
      btn.innerHTML = `<i class="fa-regular fa-copy" style="font-style:normal"></i> ${t('codeCopy')}`;
      btn.onclick = (e) => {
        e.stopPropagation();
        navigator.clipboard.writeText(codeEl ? codeEl.textContent : '').then(() => {
          btn.innerHTML = `<i class="fa-solid fa-check" style="font-style:normal;color:#22c55e"></i> ${t('codeCopied')}`;
          setTimeout(() => { btn.innerHTML = `<i class="fa-regular fa-copy" style="font-style:normal"></i> ${t('codeCopy')}`; }, 1500);
        });
      };
      block.appendChild(btn);
    }
    if (codeEl && typeof Prism !== 'undefined' && !codeEl.dataset.highlighted) {
      try { Prism.highlightElement(codeEl); } catch (e) {}
      codeEl.dataset.highlighted = '1';
    }
  });
}

// detectCodeLanguage is in assets/shared.js

window.addEventListener('load', () => { if (typeof Prism !== 'undefined') enhanceCodeBlocks(); });

// detectCodeLanguage is in assets/shared.js

// ════════════════════════════════════════
//  FEEDBACK (anonymous page rating)
// ════════════════════════════════════════
// normalizeClientRatingValue, readFeedbackStore, writeFeedbackStore,
// getStoredFeedback, rememberFeedback, setFeedbackButtonsDisabled,
// syncFeedbackButtons are in assets/shared.js.

async function react(r, icon = '') {
  const pageId = S.currentPageId;
  const ratingValue = normalizeClientRatingValue(r);
  const feedbackIcon = icon || FEEDBACK_ICON_BY_VALUE[ratingValue] || '';
  if (!pageId || !ratingValue || feedbackSaving) return;

  feedbackSaving = true;
  setFeedbackButtonsDisabled(true);
  try {
    const res = await fetch(withAccessToken('api.php?action=save_rating'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: pageId, rating: ratingValue })
    });
    let data = null;
    try { data = await res.json(); } catch (e) {}
    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Failed to save feedback');
    rememberFeedback(pageId, ratingValue);
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
//  OG IMAGE (client fallback) — in assets/shared.js
// ════════════════════════════════════════

// ════════════════════════════════════════
//  MOBILE SIDEBAR / SCROLL / READING MODE / SHARE
// ════════════════════════════════════════
// toggleMobileSidebar, closeMobileSidebar, popstate & scroll listeners,
// toggleReadingMode, sharePage are in assets/shared.js

// ════════════════════════════════════════
//  SHORTCUTS OVERLAY + HOVER PREVIEW
//  (toggleShortcuts, closeShortcuts, showHoverPreview, hideHoverPreview,
//   hoverPreviewTimer, hoverPreviewEl are in assets/shared.js)
// ════════════════════════════════════════

// ════════════════════════════════════════
//  KEYBOARD SHORTCUTS (reader)
//  Reader-level shortcuts (? overlay, Cmd+R, Cmd+Shift+C, Esc, ←→) are
//  registered in assets/shared.js. The viewer has no editor-only shortcuts.
// ════════════════════════════════════════

// ════════════════════════════════════════
//  INIT
// ════════════════════════════════════════
(async function init() {
  const overlay = document.createElement('div');
  overlay.id = 'init-overlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;gap:12px;';
  overlay.innerHTML = `
    <div style="width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin 0.7s linear infinite;"></div>
    <div id="init-msg" style="font-size:13px;color:var(--text3);">${t('loaderLoading')}</div>
    <button id="init-retry" style="display:none;margin-top:8px;padding:6px 18px;background:var(--accent);color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:var(--font);font-size:13px;">${t('loaderRetry')}</button>
  `;
  document.body.appendChild(overlay);

  const tryInit = async () => {
    document.getElementById('init-msg').textContent = t('loaderLoading');
    document.getElementById('init-retry').style.display = 'none';
    overlay.style.display = 'flex';
    try {
      await load();

      // Visitor theme preference overrides the saved site default
      try { const tp = localStorage.getItem(THEME_STORAGE_KEY); if (tp) S.settings.theme = tp; } catch (e) {}

      applySettings();
      updateTranslateOrigin();

      if (!S.currentSpaceId && S.spaces.length) S.currentSpaceId = S.spaces[0].id;

      const urlParams = new URLSearchParams(window.location.search);
      const targetPageId = urlParams.get('page') || window.location.hash.slice(1);
      if (targetPageId && S.pages.find(p => p.id === targetPageId)) {
        const targetPage = S.pages.find(p => p.id === targetPageId);
        S.currentSpaceId = targetPage.spaceId;
        S.currentPageId = targetPageId;
      } else {
        const sp = spacePages();
        S.currentPageId = firstRootPage(sp)?.id || null;
      }

      if (S.currentPageId) await loadPageContent(S.currentPageId);

      renderSpaces();
      renderNav();
      renderPage();

      overlay.style.opacity = '0';
      overlay.style.transition = 'opacity 0.2s';
      setTimeout(() => overlay.remove(), 200);
    } catch (e) {
      console.error('Init failed:', e);
      document.getElementById('init-msg').textContent = t('loaderFailed');
      document.getElementById('init-retry').style.display = 'inline-block';
    }
  };

  document.getElementById('init-retry')?.addEventListener('click', tryInit);
  await tryInit();
})();
</script>
</body>
</html>
