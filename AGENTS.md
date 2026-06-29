# Webstudio Docs Agent Guide

Use this file as the default working context for changes in this repository. Keep it short, concrete, and repo-specific.

## Project shape

- Simple self-hosted documentation app built from plain PHP, HTML, CSS, and vanilla JavaScript.
- No framework, no database, no build step, no package manager.
- Runtime storage is JSON files under `data/` and uploaded assets under `images/`.
- The app is split into two entry points: [index.php](index.php) is the public read-only viewer (no EditorJS), and [editor.php](editor.php) is the full editor/admin UI.
- Shared front-end assets live in `assets/`: [assets/app.css](assets/app.css) (styles), [assets/i18n.js](assets/i18n.js) (icon list + `TRANSLATIONS` + `DEFAULT_INTERFACE_LANG` + `LANG_LOCALES`), and [assets/shared.js](assets/shared.js) (shared vanilla-JS helpers used by both entry points: i18n `t()`/`applyTranslations()`, utilities like `esc`/`showToast`/`formatDate`, settings/theme helpers, translate widget, TOC/scroll-spy, feedback, search helpers, OG image, mobile/reading-mode/share, hover preview, and reader-level keyboard shortcuts). Both [index.php](index.php) and [editor.php](editor.php) link these files in order: `i18n.js` → `shared.js` → inline script.
- For product overview, setup, screenshots, and roadmap, prefer [README.md](README.md) instead of duplicating it here.

## Primary files

- [index.php](index.php): public read-only viewer. Server-side OG/meta generation, then a slim vanilla-JS client that loads data and renders saved page content WITHOUT EditorJS (custom `renderBlocks()` mirrors EditorJS DOM/classes). No login, editing, settings, or EditorJS dependency.
- [editor.php](editor.php): the full single-file editor app (the original `index.php`). EditorJS-based editing, auth modal flows, settings, page/space CRUD. Open it directly to edit; visitors never need it.
- [api.php](api.php): JSON API for loading and saving spaces, settings, pages, and images. `load`, `load_page`, and `save_rating` are public; mutations require auth.
- [auth.php](auth.php): setup wizard, login/logout, session state, password hashing, rate limiting.
- [assets/app.css](assets/app.css), [assets/i18n.js](assets/i18n.js), [assets/shared.js](assets/shared.js): shared CSS, i18n/constants, and shared vanilla-JS helpers linked by both [index.php](index.php) and [editor.php](editor.php).
- [data/](data/): persisted site settings, spaces, auth data, and one JSON file per page.
- [.htaccess](.htaccess): Apache routing and protection expectations.

## Architecture notes

- [index.php](index.php) (viewer) and [editor.php](editor.php) (editor) share the same data contract, OG/meta head, layout, and `assets/app.css` / `assets/i18n.js` / `assets/shared.js`. Keep their shared behavior in sync when relevant.
- The viewer renders page content with a custom `renderBlocks()` that emits the same classes EditorJS produced (`.ce-block`, `.ce-header`, `.cdx-list`, `.callout-block`, `.tl-*`, etc.) so `assets/app.css` styles it identically. Do NOT add an EditorJS dependency to `index.php`.
- Client state lives in the global `S` object in each file. Prefer local, incremental changes over introducing abstractions.
- Page content is lazy-loaded: `api.php?action=load` returns metadata, `api.php?action=load_page&id=...` returns full content.
- Persisted pages are individual files in `data/pages/<id>.json`; page IDs are part of filenames, URLs, and client state.

## Change rules

- Keep the flat architecture. Do not add frameworks, build tooling, or large dependency layers.
- Preserve file-based JSON storage unless a task explicitly requires redesign.
- Any user-facing text added to the UI should go through `TRANSLATIONS`, `t()`, and `applyTranslations()` in [index.php](index.php) instead of scattered hardcoded strings.
- Keep default/source UI copy in English unless the task explicitly requires translation changes.
- Maintain page ID safety. Existing PHP code sanitizes IDs to `[A-Za-z0-9_-]`; do not introduce slash-based or arbitrary IDs without updating the full storage and routing model.
- Mutating API actions should continue to require auth and use same-origin requests.
- Preserve readable JSON output with UTF-8 and pretty printing.

## Pitfalls worth checking

- OG image generation in [index.php](index.php) must still behave when GD helpers such as `imagecreatetruecolor` are unavailable.
- Cover image URLs may be relative; when changing sharing/preview logic, keep relative-to-absolute URL handling intact.
- `data/` must stay protected from direct access. Changes that create or move persisted files must keep the protection story working.
- `data/` and `images/` must be writable on the host. If a bug smells environment-specific, check permissions before deeper refactors.
- Login state depends on the shared `docs_auth` session cookie behavior across [api.php](api.php) and [auth.php](auth.php).

## Validation

Run the smallest relevant checks after edits:

```bash
php -l index.php
php -l editor.php
php -l api.php
php -l auth.php
node --check assets/i18n.js
php -r 'foreach (glob("data/*.json") as $f) { json_decode(file_get_contents($f)); if (json_last_error()) { fwrite(STDERR, "$f\n"); exit(1); } }'
php -r 'foreach (glob("data/pages/*.json") as $f) { json_decode(file_get_contents($f)); if (json_last_error()) { fwrite(STDERR, "$f\n"); exit(1); } }'
```

If the change affects behavior, also verify it manually in a browser:

- first-run setup or login/logout flow
- create/edit/save/reload a page
- page navigation via `?page=...`
- image upload or cover image behavior when relevant

## Scope guidance for agents

- Prefer surgical edits in the owning file instead of cross-file rewrites.
- When fixing bugs, inspect the nearest deciding code path first: auth in [auth.php](auth.php), persistence in [api.php](api.php), viewer UI in [index.php](index.php), editor UI in [editor.php](editor.php).
- If you change shared rendering, styling, or translations, check whether both [index.php](index.php) and [editor.php](editor.php) (and `assets/`) need the change.
- Do not treat the README roadmap as an instruction to implement features unless the current task explicitly asks for them.