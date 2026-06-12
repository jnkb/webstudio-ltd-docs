---
description: "Use when editing index.php, frontend UI, vanilla JavaScript client state, translations, editor behavior, navigation, search, or OG/meta rendering in Webstudio Docs."
name: "Index Frontend Guidelines"
applyTo: "index.php"
---

# Index Frontend Guidelines

- [../../index.php](../../index.php) is a single-file application: PHP prepares OG/meta output first, then a large vanilla-JS client takes over. Keep changes local and incremental.
- Prefer working in the nearest owning section instead of introducing new layers or splitting the file unless the task explicitly requires restructuring.
- Client state lives in the global `S` object. When changing UI behavior, check whether the source of truth belongs in `S`, derived rendering, or persisted page/settings data.
- Preserve the lazy-loading contract: initial load fetches spaces, settings, and page metadata; full page content loads separately via `loadPageContent(pageId)`.
- New user-facing labels, placeholders, button text, and inline messages should go through `TRANSLATIONS`, `t()`, and `applyTranslations()` rather than hardcoded strings.
- Keep default/source UI copy in English.
- When editing page navigation or routing, preserve `?page=...` behavior and compatibility with sanitized page IDs shared with the backend.
- When editing save flows, preserve the distinction between `save()` for spaces/settings and `savePageToServer()` for page content.
- When touching OG/meta generation at the top of the file, keep fallbacks working for: missing page, relative cover image URL, and environments without GD image support.
- Avoid hidden coupling bugs: if a UI change affects auth state, editor mode, current page selection, or translations, inspect the related update functions in the same slice before finishing.

## Quick validation

- Run `php -l index.php` after edits.
- If behavior changed, manually verify the affected flow in a browser: page load, navigation, edit/save, search, translation-aware labels, or OG preview behavior.
- If you add or rename translation keys, confirm both the default English path and any existing fallback behavior still render sensible text.