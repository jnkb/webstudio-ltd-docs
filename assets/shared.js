// ════════════════════════════════════════════════════════════
//  Webstudio Docs — Shared client helpers
//  Used by both index.php (viewer) and editor.php (editor).
//  Depends on assets/i18n.js (TRANSLATIONS, DEFAULT_INTERFACE_LANG,
//  LANG_LOCALES, ICON_LIST) and a global `S` state object.
// ════════════════════════════════════════════════════════════

// ════════════════════════════════════════
//  CONSTANTS
// ════════════════════════════════════════
const DEFAULT_ACCENT = '#f97316';
const DEFAULT_FOOTER_TEXT_HTML = 'Powered by Docs';
const DEFAULT_FOOTER_TAIL_HTML = '<a href="https://webstudio.ltd" target="_blank" rel="noopener" title="Built by webstudio.ltd">webstudio.ltd</a>';
const FEEDBACK_STORAGE_KEY = 'ws_docs_feedback';
const FEEDBACK_VALUE_MAP = { '1': '1', '0': '0', '-1': '-1', '👍': '1', '😐': '0', '👎': '-1' };
const FEEDBACK_ICON_BY_VALUE = { '1': '👍', '0': '😐', '-1': '👎' };
const EMPTY_RATING_STATS = { '-1': 0, '0': 0, '1': 0 };

// Maps interface lang code → {flag, name} for the GT "original" dropdown item
const LANG_META = {
    de: { flag: '🇩🇪', name: 'Deutsch' },
    en: { flag: '🇬🇧', name: 'English' },
    sk: { flag: '🇸🇰', name: 'Slovenčina' },
};

// ════════════════════════════════════════
//  i18n
// ════════════════════════════════════════
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

// ════════════════════════════════════════
//  UTILITIES
// ════════════════════════════════════════
function esc(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function sanitizeFooterHtml(input) {
    const tpl = document.createElement('template');
    tpl.innerHTML = String(input ?? '');
    const allowedTags = new Set(['A', 'B', 'STRONG', 'I', 'EM', 'U', 'SPAN', 'SMALL', 'CODE', 'BR']);
    const allowedGlobalAttrs = new Set(['title']);

    const sanitizeTree = (root) => {
        Array.from(root.children).forEach((el) => {
            sanitizeTree(el);
            if (!allowedTags.has(el.tagName)) {
                el.replaceWith(...Array.from(el.childNodes));
                return;
            }

            Array.from(el.attributes).forEach((attr) => {
                const name = attr.name.toLowerCase();
                const value = attr.value || '';
                if (el.tagName === 'A') {
                    const allowedLinkAttr = name === 'href' || name === 'target' || name === 'rel' || allowedGlobalAttrs.has(name);
                    if (!allowedLinkAttr) {
                        el.removeAttribute(attr.name);
                        return;
                    }
                    if (name === 'href' && /^\s*(javascript|data):/i.test(value)) {
                        el.removeAttribute(attr.name);
                    }
                    return;
                }

                if (!allowedGlobalAttrs.has(name)) {
                    el.removeAttribute(attr.name);
                }
            });

            if (el.tagName === 'A') {
                el.setAttribute('rel', 'noopener');
                if (!el.getAttribute('target')) el.setAttribute('target', '_blank');
            }
        });
    };

    sanitizeTree(tpl.content);
    return tpl.innerHTML;
}

function footerHasText(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    return (tmp.textContent || '').trim().length > 0;
}

function applyFooterDisplay() {
    const s = S.settings || {};
    const textHtml = sanitizeFooterHtml(s.footerText ?? DEFAULT_FOOTER_TEXT_HTML);
    const tailHtml = sanitizeFooterHtml(s.footerTailHtml ?? DEFAULT_FOOTER_TAIL_HTML);

    const iconEl = document.getElementById('footer-icon');
    const textEl = document.getElementById('footer-text-display');
    const tailEl = document.getElementById('footer-tail-display');

    if (textEl) textEl.innerHTML = textHtml;
    if (tailEl) {
        tailEl.innerHTML = tailHtml;
        tailEl.style.display = footerHasText(tailHtml) ? '' : 'none';
    }
    if (iconEl) iconEl.style.display = footerHasText(textHtml) ? '' : 'none';
}

function showToast(msg) {
    const el = document.getElementById('toast');
    if (!el) return;
    document.getElementById('toast-text').textContent = msg;
    el.classList.add('show');
    clearTimeout(el._timer);
    el._timer = setTimeout(() => el.classList.remove('show'), 2600);
}

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const lang = S.settings?.lang || DEFAULT_INTERFACE_LANG;
    const locale = LANG_LOCALES[lang] || LANG_LOCALES[DEFAULT_INTERFACE_LANG];
    const separator = lang === 'sk' ? ' o ' : ', ';
    return d.toLocaleDateString(locale, { day: 'numeric', month: 'long', year: 'numeric' })
        + separator + d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
}

function slugify(text) {
    return text.toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .trim() || 'heading';
}

function highlight(text, q) {
    if (!q) return esc(text);
    const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return esc(text).replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>');
}

// Collect all searchable plain text from a block, across every tool's data
// shape (paragraph, header, list, checklist, quote, code, table, image,
// callout, timeline, collapse, video, cards). Returns an array of cleaned
// text fragments so callers can match + build snippets uniformly.
function getBlockSearchText(block) {
    const d = block?.data || {};
    const parts = [];
    const push = (v) => {
        if (typeof v !== 'string') return;
        const clean = v.replace(/<[^>]+>/g, '').trim();
        if (clean) parts.push(clean);
    };

    // Simple string fields shared by several tools
    ['text', 'caption', 'title', 'message', 'body', 'code'].forEach(f => push(d[f]));

    // List / nested-list items: { content, items[] }
    // Checklist items: { text, checked }
    // Timeline items: { date, title, desc }
    if (Array.isArray(d.items)) {
        const walkItems = (items) => {
            items.forEach(it => {
                if (typeof it === 'string') { push(it); return; }
                if (!it || typeof it !== 'object') return;
                push(it.content);
                push(it.text);
                push(it.date);
                push(it.title);
                push(it.desc);
                if (Array.isArray(it.items)) walkItems(it.items);
            });
        };
        walkItems(d.items);
    }

    // Table cells: array of arrays of strings
    if (Array.isArray(d.content)) {
        d.content.forEach(row => {
            if (Array.isArray(row)) row.forEach(cell => push(cell));
        });
    }

    // Cards: array of { icon, title, desc, link }
    if (Array.isArray(d.cards)) {
        d.cards.forEach(c => {
            if (!c) return;
            push(c.title);
            push(c.desc);
        });
    }

    return parts;
}

function getPageTextSnippet(page, q) {
    const blocks = page.content?.blocks || [];
    const ql = q.toLowerCase();
    for (const b of blocks) {
        for (const txt of getBlockSearchText(b)) {
            if (txt.toLowerCase().includes(ql)) {
                const idx = txt.toLowerCase().indexOf(ql);
                const start = Math.max(0, idx - 30);
                const snippet = (start > 0 ? '…' : '') + txt.slice(start, idx + 80) + (txt.length > idx + 80 ? '…' : '');
                return highlight(snippet, q);
            }
        }
    }
    return '';
}

function detectCodeLanguage(code) {
    const first = code.trim().split('\n')[0];
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
    return 'markup';
}

// ════════════════════════════════════════
//  SETTINGS / THEME HELPERS
// ════════════════════════════════════════
function isTranslateEnabled() { return S.settings.translateEnabled !== false; }
function isHideSingleSpaceTabsEnabled() { return S.settings.hideSingleSpaceTabs === true; }
function isShareSectionEnabled() { return S.settings.shareSectionEnabled !== false; }
function shouldHideSpaceTabs() { return isHideSingleSpaceTabsEnabled() && S.spaces.length <= 1; }

function applySpaceTabsVisibility() {
    const tabbar = document.getElementById('tabbar');
    const root = document.documentElement;
    const styles = getComputedStyle(root);
    const navHeight = parseInt(styles.getPropertyValue('--nav-h'), 10) || 50;
    const tabHeight = parseInt(styles.getPropertyValue('--tab-h'), 10) || 40;
    const hideTabs = shouldHideSpaceTabs();

    if (tabbar) tabbar.style.display = hideTabs ? 'none' : '';
    root.style.setProperty('--total-h', `${navHeight + (hideTabs ? 0 : tabHeight)}px`);
}

function applyShareSectionAvailability() {
    const shareSection = document.getElementById('toc-share-section');
    if (shareSection) shareSection.style.display = isShareSectionEnabled() ? '' : 'none';
    const shortcutShareRow = document.getElementById('shortcut-share-row');
    if (shortcutShareRow) shortcutShareRow.style.display = isShareSectionEnabled() ? '' : 'none';
}

// ════════════════════════════════════════
//  TRANSLATE (Google Translate widget for readers)
// ════════════════════════════════════════
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
    if (typeof doGTranslate === 'function') doGTranslate(`${srcLang}|${srcLang}`);
    const combo = document.querySelector('.goog-te-combo');
    if (combo) { combo.value = srcLang; combo.dispatchEvent(new Event('change')); }
    document.querySelectorAll('iframe.skiptranslate, .goog-te-banner-frame, #goog-gt-tt').forEach(el => el.remove());
    document.cookie = 'googtrans=; max-age=0; path=/';
    document.cookie = `googtrans=; max-age=0; path=/; domain=${location.hostname}`;
    document.cookie = `googtrans=; max-age=0; path=/; domain=.${location.hostname}`;
    document.getElementById('gt-script')?.remove();
    document.body.style.top = '';
}

// Translate is shown to readers only. In the editor S.authed hides it for admins.
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

function loadTranslateWidget() {
    if (document.getElementById('gt-script')) return;
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
        if (typeof doGTranslate === 'function') doGTranslate(`${srcLang}|${srcLang}`);
        const combo = document.querySelector('.goog-te-combo');
        if (combo) { combo.value = srcLang; combo.dispatchEvent(new Event('change')); }
        document.querySelectorAll('.translate-lang').forEach(el => el.classList.toggle('active', el.dataset.lang === lang));
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
    document.querySelectorAll('.translate-lang').forEach(el => el.classList.toggle('active', el.dataset.lang === lang));
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

// ════════════════════════════════════════
//  NAVIGATION HELPERS
// ════════════════════════════════════════
function spacePages() {
    return S.pages.filter(p => p.spaceId === S.currentSpaceId);
}

// First page as it appears at the top of the sidebar nav:
// lowest-order root page of the given list (mirrors renderNav() sorting).
function firstRootPage(pages) {
    return pages.filter(p => !p.parentId).sort((a, b) => (a.order || 0) - (b.order || 0))[0] || pages[0] || null;
}

function isPrimaryNavigationClick(event) {
    return event.button === 0
        && !event.defaultPrevented
        && !event.metaKey
        && !event.ctrlKey
        && !event.shiftKey
        && !event.altKey;
}

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

// ════════════════════════════════════════
//  SEARCH HELPERS
// ════════════════════════════════════════
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
//  TABLE OF CONTENTS + SCROLL SPY
// ════════════════════════════════════════
let scrollSpyObserver = null;

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
//  PAGE RENDER HELPERS
// ════════════════════════════════════════
function updateReadingTime() {
    const el = document.getElementById('reading-time-el');
    if (!el) return;
    const text = document.getElementById('editor')?.innerText || '';
    const words = text.trim().split(/\s+/).filter(Boolean).length;
    const mins = Math.max(1, Math.round(words / 200));
    el.innerHTML = `<i class="fa-regular fa-clock"></i> <span>${t('readingTimeLabel', mins, words)}</span>`;
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
    ${prev ? `<a class="page-nav-card" href="${esc(buildPageHref(prev.id))}" data-page-id="${esc(prev.id)}">
      <div class="page-nav-dir"><i class="fa-solid fa-arrow-left"></i> ${t('navPrev')}</div>
      <div class="page-nav-title"><i class="fa-solid ${prev.icon || 'fa-file'}"></i>${esc(prev.title)}</div>
    </a>` : '<div></div>'}
    ${next ? `<a class="page-nav-card right" href="${esc(buildPageHref(next.id))}" data-page-id="${esc(next.id)}">
      <div class="page-nav-dir">${t('navNext')} <i class="fa-solid fa-arrow-right"></i></div>
      <div class="page-nav-title"><i class="fa-solid ${next.icon || 'fa-file'}"></i>${esc(next.title)}</div>
    </a>` : '<div></div>'}
  `;
    bindPageLinks(el);
}

// ════════════════════════════════════════
//  FEEDBACK (anonymous page rating)
// ════════════════════════════════════════
function normalizeClientRatingValue(value) {
    return FEEDBACK_VALUE_MAP[String(value ?? '').trim()] || '';
}

function readFeedbackStore() {
    try { return JSON.parse(localStorage.getItem(FEEDBACK_STORAGE_KEY) || '{}') || {}; }
    catch (e) { return {}; }
}

function writeFeedbackStore(store) {
    try { localStorage.setItem(FEEDBACK_STORAGE_KEY, JSON.stringify(store)); } catch (e) { }
}

function getStoredFeedback(pageId) {
    if (!pageId) return '';
    return normalizeClientRatingValue(readFeedbackStore()[pageId] || '');
}

function rememberFeedback(pageId, rating) {
    if (!pageId) return;
    const store = readFeedbackStore();
    store[pageId] = normalizeClientRatingValue(rating);
    writeFeedbackStore(store);
}

function setFeedbackButtonsDisabled(disabled) {
    document.querySelectorAll('.toc-feedback-btns .fb-btn').forEach(btn => { btn.disabled = disabled; });
}

function syncFeedbackButtons() {
    const currentRating = getStoredFeedback(S.currentPageId);
    document.querySelectorAll('.toc-feedback-btns .fb-btn').forEach(btn => {
        btn.classList.toggle('active', !!currentRating && btn.dataset.rating === currentRating);
    });
}

// ════════════════════════════════════════
//  OG IMAGE (client fallback)
// ════════════════════════════════════════
function generateOgImage(title, subtitle, siteName) {
    try {
        const c = document.createElement('canvas');
        c.width = 1200; c.height = 630;
        const ctx = c.getContext('2d');

        // Gradient background using accent color
        const accent = S.settings?.accentColor || '#f97316';
        const r = parseInt(accent.slice(1, 3), 16), g = parseInt(accent.slice(3, 5), 16), b = parseInt(accent.slice(5, 7), 16);
        const grad = ctx.createLinearGradient(0, 0, 1200, 630);
        grad.addColorStop(0, `rgba(${r},${g},${b},1)`);
        grad.addColorStop(1, `rgba(${Math.max(0, r - 40)},${Math.max(0, g - 40)},${Math.max(0, b - 20)},1)`);
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
    } catch (e) { return ''; }
}

// ════════════════════════════════════════
//  MOBILE SIDEBAR / SCROLL / READING MODE / SHARE
// ════════════════════════════════════════
function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobile-sidebar-overlay');
    const isOpen = sidebar.classList.contains('mobile-open');
    sidebar.classList.toggle('mobile-open', !isOpen);
    overlay.classList.toggle('open', !isOpen);
}

function closeMobileSidebar() {
    document.getElementById('sidebar')?.classList.remove('mobile-open');
    document.getElementById('mobile-sidebar-overlay')?.classList.remove('open');
}

window.addEventListener('popstate', () => {
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

window.addEventListener('scroll', () => {
    const btn = document.getElementById('scroll-top-btn');
    if (btn) btn.classList.toggle('show', window.scrollY > 200);
}, { passive: true });

function toggleReadingMode() {
    document.body.classList.toggle('reading-mode');
    const btn = document.getElementById('reading-mode-btn');
    if (btn) btn.title = t('btnReadingMode');
}

function sharePage() {
    if (!isShareSectionEnabled()) return;
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

// ════════════════════════════════════════
//  SHORTCUTS OVERLAY + HOVER PREVIEW
// ════════════════════════════════════════
function toggleShortcuts() { document.getElementById('shortcuts-overlay').classList.toggle('open'); }
function closeShortcuts() { document.getElementById('shortcuts-overlay').classList.remove('open'); }

let hoverPreviewTimer = null;
// Resolved lazily: shared.js loads in <head>, so #nav-hover-preview is not
// in the DOM yet at module-evaluation time.
let _hoverPreviewEl = null;
function getHoverPreviewEl() {
    if (!_hoverPreviewEl) _hoverPreviewEl = document.getElementById('nav-hover-preview');
    return _hoverPreviewEl;
}

function showHoverPreview(pageId, anchorEl) {
    clearTimeout(hoverPreviewTimer);
    hoverPreviewTimer = setTimeout(() => {
        const hoverPreviewEl = getHoverPreviewEl();
        if (!hoverPreviewEl) return;
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
    const hoverPreviewEl = getHoverPreviewEl();
    if (hoverPreviewEl) hoverPreviewEl.classList.remove('show');
}

// ════════════════════════════════════════
//  SHARED KEYBOARD SHORTCUTS (reader-level)
//  Editor-only shortcuts (save/undo/edit-mode) stay local in editor.php.
// ════════════════════════════════════════
document.addEventListener('keydown', e => {
    const meta = e.metaKey || e.ctrlKey;

    // ? = shortcuts overlay (only when not typing)
    if (e.key === '?' && !e.target.closest('input, textarea, [contenteditable]')) {
        e.preventDefault();
        toggleShortcuts();
        return;
    }

    // Cmd+R = reading mode
    if (meta && e.key === 'r') {
        e.preventDefault();
        toggleReadingMode();
        return;
    }

    // Cmd+Shift+C = share
    if (meta && e.shiftKey && e.key === 'c' && isShareSectionEnabled()) {
        e.preventDefault();
        sharePage();
        return;
    }

    if (e.key === 'Escape') {
        closeSearchDD();
        closeShortcuts();
    }

    // ←→ arrow keys for prev/next page (only when not typing and not editing)
    if (!e.target.closest('input, textarea, [contenteditable], select') && !S.editMode) {
        if (e.key === 'ArrowLeft') navigatePrevNext(-1);
        if (e.key === 'ArrowRight') navigatePrevNext(1);
    }
});

// ════════════════════════════════════════
//  PAGE-LINK BINDING (shared contract)
//  Each file defines its own bindPageLinks() / bindEditorPageLinks() /
//  bindViewerPageLinks() locally because the viewer needs access-token
//  handling. updatePageNavBottom() above calls bindPageLinks(el), which
//  each file aliases to its own binder so the shared helper works in both.
// ════════════════════════════════════════
