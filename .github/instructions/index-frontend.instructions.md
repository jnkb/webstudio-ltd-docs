---
description: "Use when editing the front-end UI, vanilla JavaScript client state, translations, editor behavior, navigation, search, or OG/meta rendering in Webstudio Docs (index.php viewer and editor.php editor)."
name: "Front-end Guidelines"
applyTo: "index.php,editor.php,assets/*.js,assets/*.css"
---

# Front-end Guidelines

- The front end is split: [../../index.php](../../index.php) is the public read-only viewer (no EditorJS) and [../../editor.php](../../editor.php) is the full editor. Both link the shared [../../assets/app.css](../../assets/app.css), [../../assets/i18n.js](../../assets/i18n.js) (constants/translations), and [../../assets/shared.js](../../assets/shared.js) (shared vanilla-JS helpers: i18n, utilities, settings/theme helpers, translate widget, TOC/scroll-spy, feedback, search helpers, OG image, mobile/reading-mode/share, hover preview, reader-level keyboard shortcuts) instead of inlining them.
- Each file bootstraps OG/meta output first in PHP, then runs a vanilla-JS client. Keep changes local and incremental.
- The viewer must NOT depend on EditorJS. It renders saved page content via a custom `renderBlocks()` that mirrors EditorJS DOM/classes so `assets/app.css` styles it. Keep editor-only features (login, save, settings, slash menu, drag & drop) out of `index.php`.
- Prefer working in the nearest owning section instead of introducing new layers or splitting files further unless the task explicitly requires restructuring.
- Client state lives in the global `S` object. When changing UI behavior, check whether the source of truth belongs in `S`, derived rendering, or persisted page/settings data.
- Preserve the lazy-loading contract: initial load fetches spaces, settings, and page metadata; full page content loads separately via `loadPageContent(pageId)`.
- New user-facing labels, placeholders, button text, and inline messages should go through `TRANSLATIONS`, `t()`, and `applyTranslations()` rather than hardcoded strings.
- Keep default/source UI copy in English.
- When editing page navigation or routing, preserve `?page=...` behavior and compatibility with sanitized page IDs shared with the backend.
- When editing save flows, preserve the distinction between `save()` for spaces/settings and `savePageToServer()` for page content.
- When touching OG/meta generation at the top of the file, keep fallbacks working for: missing page, relative cover image URL, and environments without GD image support.
- Avoid hidden coupling bugs: if a UI change affects auth state, editor mode, current page selection, or translations, inspect the related update functions in the same slice before finishing.

## Quick validation

- Run `php -l index.php` and `php -l editor.php` after edits; run `node --check assets/i18n.js` and `node --check assets/shared.js` if you touched them.
- If behavior changed, manually verify the affected flow in a browser: page load, navigation, edit/save, search, translation-aware labels, or OG preview behavior.
- If you change shared rendering/styling/translations, check that both the viewer and editor still render correctly. Shared JS helpers live in [../../assets/shared.js](../../assets/shared.js); when editing a function there, verify both entry points still work since each defines its own global `S` and may override shared functions (e.g. `react()`, `handleSearch`, `bindPageLinks`).
- If you add or rename translation keys, confirm both the default English path and any existing fallback behavior still render sensible text.