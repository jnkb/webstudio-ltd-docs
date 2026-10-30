## 🔄 Changes from the original version

This repository is a customized fork of the original Webstudio Docs release.
The main changes, most significant first:

### Major features

- **AI agent access via MCP** — `mcp.php` lets AI agents such as Claude Code read, search, write, and organize the docs with revocable bearer tokens created in Settings; everything they write is sanitized on the server. See [MCP.md](MCP.md).
- **Split public viewer and admin editor** — `index.php` is now the read-only public frontend, while `editor.php` contains the authenticated editing UI.
- **Markdown import** — paste Markdown into a paragraph to turn it into blocks, or drop `.md` files (with their images) into the editor to create pages.
- **Markdown download** — readers and editors can download the current page as a `.md` file from a button next to Share.
- **Local video uploads** — upload MP4, WebM, or Ogg files alongside YouTube/Vimeo embeds; uploads are chunked so very large files bypass PHP's upload limits, and they play in a standard HTML5 player in both the editor and the public viewer.

### Access, navigation & editing

- **Optional simple access gate** — hides the site behind a basic token link and browser cookie.
- **Editable page IDs and internal link picker** — page URLs can be customized and internal links can be selected from a searchable page list.
- **Search across all block types** — full-text search now also finds text in tables, callouts, timelines, cards, collapsibles, and other blocks.
- **German language support** — the interface now includes built-in German translations with centralized language configuration.
- **Viewer-only iframe embedding** — `index.php` can be embedded in an iframe, while `editor.php` stays protected against framing.
- **Clipboard image paste support** — pasted screenshots and copied images can be uploaded directly into the editor.

### Security & data safety

- **Safer page deletion** — removed pages and related rating files are archived into `data/trash/pages` instead of being deleted outright.
- **Drafts hide their subpages** — a draft page and its whole subtree are hidden from the public viewer, API, and link previews; logged-in editors still see them.
- **Image integrity checks** — corrupt or truncated images are refused on every upload path.
- **Built-in password change flow** — admins can change the password from settings without rerunning setup.

### Settings & comfort

- **Manual space reordering** — spaces can be moved left or right directly in the editor.
- **Broader translation coverage** — UI texts that were still hardcoded are now routed through the shared i18n layer.
- **Optional Google Translate button** — the translate button is now optional and can be disabled in settings.
- **Saved page feedback** — page helpfulness feedback is now persisted through the API.
- **HTML-capable footer content** — footer areas now support full HTML content instead of plain text only.
- **Configurable share section visibility** — the share block in the page sidebar can be shown or hidden from settings.
- **Optional single-space navigation mode** — the top space tabs can be hidden automatically when only one space exists.
- **Role-aware shortcuts** — admin-only keyboard shortcuts are hidden or blocked for non-admin users.
- **Platform-specific shortcut labels** — macOS shows ⌘/⇧, Windows and Linux show Ctrl/Shift.
- **Selectable divider styles** — delimiter blocks can render as stars, a solid line, a dashed line, or dots.
- **Preserved table line breaks** — multi-line table cells now keep their line breaks during save and render.

### Under the hood & fixes

- **Shared frontend assets** — styles, translations, and shared JavaScript helpers live in `assets/app.css`, `assets/i18n.js`, and `assets/shared.js` so both entry points stay in sync.
- **PHP 8.5 compatibility updates** — the fork includes fixes for current PHP deprecations and edge cases.
- **Header slash menu fix** — the header slash menu behavior was corrected in the customized frontend.

---

<p align="center">
  <img src="http://docs.web-studio.sk/screenshots/hero.jpg" alt="Webstudio Docs" width="100%">
</p>

<p align="center">
  <img src="http://docs.web-studio.sk/screenshots/webstudio-docs-logo.png" alt="Webstudio Docs" width="50%">
</p>

<p align="center">
  <strong>Open-source, self-hosted documentation platform.</strong><br>
  A free GitBook alternative — plain PHP, no database, no build process.<br>
  Upload to any PHP hosting and start writing.
</p>

<p align="center">
  <a href="#-changes-from-the-original-version">Changes in This Fork</a> •
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-features">Features</a> •
  <a href="#-screenshots">Screenshots</a> •
  <a href="#-roadmap">Roadmap</a> •
  <a href="#-ai-agents-mcp">AI Agents (MCP)</a> •
  <a href="#-contributing">Contributing</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License">
  <img src="https://img.shields.io/badge/PHP-7.4+-purple.svg" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/database-none-green.svg" alt="No database">
  <img src="https://img.shields.io/badge/build_process-none-green.svg" alt="No build">
</p>

---

## Why Webstudio Docs?

We're a web agency that got tired of paying $65/month per site for GitBook. So we built our own docs platform and open-sourced it.

**The result:**

- **Still lightweight** — plain PHP, JSON storage, and a simple file-based structure without framework overhead.
- **No database** — everything is stored as JSON files on your server.
- **No build process** — no npm, no webpack, no config files. Upload and done.
- **Self-hosted** — your server, your data, your domain. No vendor lock-in.
- **Free forever** — MIT license, no usage limits, no per-user pricing.

We use it in production for our own client projects. It works. It's fast. And it costs nothing.

### 🔗 Live Demo

Try it yourself: **[docs.web-studio.sk](https://docs.web-studio.sk)** — password: `DemoPassword@123`


---

## 🚀 Quick Start

**Requirements:** Any PHP 7.4+ hosting with write permissions. That's it.

This fork also includes compatibility fixes for current PHP 8.5 behavior.

```bash
# 1. Clone
git clone https://github.com/jnkb/webstudio-ltd-docs.git

# 2. Upload the project files to your PHP server
#    keep the folder structure intact (`assets/`, `data/`, `images/`, `videos/`)

# 3. Open editor.php in your browser → set your admin password → start writing
```

Readers use `index.php`. Admin editing, settings, and authentication live in `editor.php`.

No `npm install`. No environment variables. No database migrations. No Docker. Just upload the project and you have a documentation site.

---

## ✨ Features

### Editor — 14 block types
- **Slash commands** — type `/` anywhere to search and insert any block type
- **Block picker** — click ⊕ to browse all available blocks
- **Headings, paragraphs, lists, checklists** — the basics, done right
- **Code blocks** with syntax highlighting (Prism.js, 200+ languages) and one-click copy button
- **Tables** with header rows and preserved line breaks inside cells
- **Images** — upload, paste from clipboard, paste a URL, or drag & drop
- **Callouts** — info, tip, warning, danger with custom titles
- **Collapsible sections** — perfect for FAQs
- **Timeline** — ideal for changelogs and release notes
- **Cards** — icon grids with links to internal pages or external URLs
- **Video** — upload local files (MP4, WebM, Ogg) with chunked large-file support, or embed YouTube / Vimeo
- **Quotes, delimiters, inline code, markers** — including selectable divider styles
- **Internal page links** — searchable page picker for linking cards and rich text to other docs pages
- **Editable page IDs** — control clean URLs manually when you need stable or custom slugs
- **Markdown import** — paste Markdown into a paragraph, or drop `.md` files to create pages (headings, lists, tables, code, GitHub alerts, `<details>`, images)
- **Markdown download** — export any page as `.md`, in a format the importer reads back
- **Drag & drop** block reordering with visual drop indicator
- **Undo / Redo** with full history (Ctrl+Z / Ctrl+Shift+Z)

### Navigation & Search
- **Spaces** — separate documentation sections (like GitBook spaces), with manual left/right reordering in the editor
- **Tree structure** — pages, subpages, sections, and nested drag & drop reorder
- **Full-text search** — Ctrl+K / ⌘K searches titles, subtitles, and the text of every block type
- **Table of contents** — auto-generated from headings with scroll spy
- **Per-page feedback** — readers can mark a page as helpful, neutral, or not helpful, and ratings are saved
- **Optional single-space mode** — hide the top space switcher when your docs only use one space
- **Keyboard navigation** — ← → for prev/next page, ? for all shortcuts, with labels that match your platform
- **Breadcrumbs** and **previous/next** page links

### Looks Professional
- **Dark & Light mode** — one-click toggle
- **7 accent colors** + custom color picker
- **Custom logo & favicon** — upload in settings
- **Cover images** — per-page with drag-to-reposition and gradient covers
- **Reading mode** — distraction-free focused view
- **Mobile responsive** — works on phones and tablets

### SEO & Sharing
- **Dynamic OG images** — auto-generated with page title, description and your brand colors
- **Clean URLs** — `?page=installation` not `?page=_x7f2k9`
- **Twitter cards, meta descriptions, canonical URLs** — all automatic
- **Configurable share sidebar** — show or hide the share block in the page sidebar from settings
- **Per-page titles** — `Installation — My Docs` in browser tab

### Security
- **Setup wizard** — first-run password setup with strength validation
- **Bcrypt hashing** — passwords stored securely, never in plain text
- **Rate limiting** — 10 failed login attempts per IP per 5 minutes, stored server-side
- **Change password flow** — update the admin password later from Settings without rerunning setup
- **`.htaccess` protection** — blocks direct access to data files
- **Optional simple access gate** — hides the site behind a basic token link and browser cookie
- **Install-scoped cookies and sessions** — multiple docs instances on the same domain do not collide with each other
- **Viewer-only iframe support** — `index.php` may be embedded; `editor.php` remains frame-blocked
- **Verified uploads** — corrupt or truncated images are refused before they are stored
- **Revocable MCP tokens** — AI agents get their own bearer tokens (stored as hashes only), and everything they write is sanitized
- **No database** — no SQL injection possible. Ever.

### Developer-Friendly
- **Easy to extend** — it's just HTML, CSS, JS and PHP. No framework, no abstraction layers
- **AI agent access (MCP)** — let Claude Code or other MCP clients read and write your docs, see [MCP.md](MCP.md)
- **i18n ready** — English, German, and Slovak built-in, with broader translation coverage across the UI
- **Safer file-based cleanup** — deleted pages and related rating files are moved into `data/trash/pages`
- **PHP 7.4+ friendly** — updated to stay compatible with current PHP 8.5 behavior
- **Page templates** — Blank, Documentation, Changelog, API Reference, Tutorial, FAQ
- **Auto-save** — never lose work

---

## 📸 Screenshots

| Dark Mode | Light Mode |
|-----------|------------|
| ![Dark mode](http://docs.web-studio.sk/screenshots/dark.png) | ![Light mode](http://docs.web-studio.sk/screenshots/light.png) |

| Block Editor | Cards with Links |
|-------------|-----------------|
| ![Editor](http://docs.web-studio.sk/screenshots/editor.png) | ![Cards](http://docs.web-studio.sk/screenshots/cards.png) |

| Setup Wizard | Settings Panel |
|-------------|---------------|
| ![Setup](http://docs.web-studio.sk/screenshots/setup.png) | ![Settings](http://docs.web-studio.sk/screenshots/settings.png) |

---

## 🗺 Roadmap

Webstudio Docs is actively developed. Here's what we're working on:

### Coming to Open Source (Free)
- [ ] Multi-user support with roles (admin / editor / viewer)
- [x] Markdown import & export
- [ ] More languages (French, Spanish, Czech — PRs welcome!)
- [ ] Page versioning / history
- [ ] Keyboard shortcuts in sidebar navigation
- [ ] And more...

### 🔜 Premium Edition (Coming Soon)

We're building a **Premium version** with advanced features for teams and businesses — AI-powered writing tools, integrations, analytics, and more.

**Pricing model:** One-time purchase. No monthly fees. No per-user charges. Every purchase directly funds the continued development of the free open-source version.

👉 **Want early access?** [Star this repo](../../stargazers) and [watch for releases](../../releases) — we'll announce it here first.

---

## ⚙️ Configuration

### File Structure

```
your-docs-site/
├── access_guard.php ← Shared optional access gate helper
├── index.php      ← Public viewer (read-only frontend + OG tag generation)
├── editor.php     ← Admin editor UI
├── api.php        ← Backend API (pages, spaces, images, settings)
├── mcp.php        ← MCP server for AI agents (bearer tokens)
├── storage.php    ← Shared storage helpers for api.php + mcp.php (not web-accessible)
├── auth.php       ← Authentication (setup wizard, login, sessions)
├── .htaccess      ← Security (blocks /data/ from direct access)
├── MCP.md         ← Setup and reference for AI agent access
├── assets/
│   ├── app.css    ← Shared styles for viewer + editor
│   ├── i18n.js    ← Shared translations, icons, language config
│   ├── shared.js  ← Shared JS helpers for viewer + editor
│   ├── markdown-import.js ← Markdown → blocks (editor only)
│   └── markdown-export.js ← Page → Markdown download
├── data/
│   ├── index.php      ← Directory protection helper
│   ├── access_key.txt ← Optional simple access gate token
│   ├── auth.json      ← Hashed password (bcrypt)
│   ├── ratelimit.json ← Failed login attempts per IP
│   ├── mcp_tokens.json ← MCP access tokens (SHA-256 hashes only)
│   ├── mcp_upload_tickets.json ← Open single-use MCP upload URLs (hashes only)
│   ├── settings.json  ← Site configuration
│   ├── spaces.json    ← Space definitions
│   ├── pages/         ← One JSON file per page
│   ├── tmp_uploads/   ← Temporary parts of chunked video uploads
│   └── trash/
│       └── pages/     ← Archived deleted pages and rating files
├── images/            ← Uploaded images
│   └── og/            ← Auto-generated social sharing images
└── videos/            ← Uploaded videos
```

In this fork, `index.php` is the public reader frontend and iframe entry point. Open `editor.php` when you want to log in, edit content, manage spaces, or change settings. When the optional access gate is enabled, `access_guard.php` enforces the unlock cookie for both flows.

### Adding a Language

1. Open `assets/i18n.js`
2. Copy the entire `en: { ... }` block
3. Rename to your language code (e.g. `de`, `fr`, `es`)
4. Translate the values
5. Add the matching locale mapping in `LANG_LOCALES`
6. Select it in Settings → Interface language and test both `index.php` and `editor.php`
7. Submit a PR — we'd love to include it!

### Nginx Users

`.htaccess` is Apache-only. For Nginx, add this to your server block:

```nginx
location /data/ { deny all; return 403; }
location ~ \.json$ { deny all; return 403; }
location ~ /storage\.php$ { deny all; return 403; }
```

`storage.php` also refuses direct calls on its own. Nginx passes the `Authorization` header for `mcp.php` to PHP-FPM by default.

### Optional Simple Access Gate

If you want to hide the docs from casual visitors, search engines, or AI crawlers, you can enable a very simple token-based access gate.

This is intentionally **not** a high-security system. It is only a rudimentary access barrier for shared links and basic privacy. Do not rely on it to protect sensitive data.

How it works:

1. Put a token value into `data/access_key.txt`.
2. Open the site once with `?token=YOUR_TOKEN` appended to the URL.
3. The app sets a browser cookie for the docs domain.
4. In `index.php`, the token is removed from the visible URL after the first successful load, so internal viewer navigation and shared page links stay clean.
5. After that, the browser can access `index.php`, `editor.php`, `api.php`, and `auth.php` without the token as long as the cookie is still accepted and present.

Example:

```txt
data/access_key.txt
my-share-token-123
```

```txt
https://your-domain.example/?token=my-share-token-123
```

Notes:

- The first non-empty line of `data/access_key.txt` is used as the token value.
- Lines starting with `#` are ignored, so you can keep short comments in the file.
- If `data/access_key.txt` is empty or missing, the simple access gate is disabled.
- The token is only needed for the initial unlock request; the viewer no longer appends it to internal page URLs after that.
- The cookie is browser-based, so each browser or device must be unlocked once.
- Access cookies and auth sessions are namespaced per installation path, so multiple docs instances on the same domain stay isolated.
- If you embed the docs in an iframe, only `index.php` is intended to be embedded. `editor.php` stays frame-blocked on purpose.
- For iframe embedding, use HTTPS so the access-gate cookie can be sent reliably by modern browsers.
- This is meant to reduce accidental exposure, not to replace real authentication or server-side security.

### Embedding the Viewer in an iframe

`index.php` is the supported embeddable entry point. The admin editor in `editor.php` is intentionally not embeddable.

Example:

```html
<iframe
  src="https://your-domain.example/index.php?page=installation"
  width="100%"
  height="900"
  style="border:0"
  loading="lazy"
></iframe>
```

If the optional access gate is enabled, unlock the viewer once with `?token=...` on the docs domain first. After that, normal viewer navigation keeps clean URLs without repeating the token parameter.

---

## 🤖 AI Agents (MCP)

`mcp.php` is a [Model Context Protocol](https://modelcontextprotocol.io) server. AI agents such as Claude Code, Claude Desktop, or any other MCP client can use it to read, search, write, and organize your documentation — everything an admin can do with content in the editor.

It speaks MCP over **Streamable HTTP** (plain `POST` with JSON responses, no SSE, no sessions), so it runs on any PHP hosting without a background process. Agents authenticate with a bearer token.

**👉 Full guide: [MCP.md](MCP.md)** — client setup, all 23 tools, content format, uploads, security, troubleshooting.

### Quick start

1. Open `editor.php`, go to **Settings → AI agents (MCP)**, and click **Create token**. Copy the token (`wsd_…`) right away — it is shown only once.
2. Connect your agent, e.g. Claude Code:

   ```bash
   claude mcp add --transport http docs https://your-domain.example/mcp.php \
     --header "Authorization: Bearer wsd_your_token"
   ```

   The settings dialog also shows snippets for other MCP clients. For Claude Desktop, see [MCP.md](MCP.md#claude-desktop).
3. Ask the agent something like *"Give me an overview of the docs"* or *"Create a page Installation in the Getting Started section"*.

### What agents can do

| Group | Tools |
|---|---|
| **Read** | `get_site`, `list_pages`, `get_page`, `search_pages`, `get_content_guide`, `list_images`, `get_settings` |
| **Pages** | `create_page`, `update_page`, `edit_blocks`, `move_page`, `reorder_pages`, `set_page_id`, `delete_page`, `rename_section`, `set_section` |
| **Spaces & settings** | `create_space`, `update_space`, `delete_space`, `update_settings` |
| **Media** | `create_upload_url`, `upload_image`, `delete_image` |

### Good to know

- **A token has full admin rights for content.** Give each agent its own token and revoke unused ones in Settings. Only a SHA-256 hash is stored.
- **Everything an agent writes is sanitized on the server**, so a prompt-injected agent cannot plant scripts in your docs.
- **Nothing is hard-deleted** — deleted pages and spaces go to `data/trash/pages` (uploaded images are the exception).
- **Reload the editor after an agent has made changes.** An editor tab that stays open can overwrite them.
- **Hosting:** on PHP-FPM / CGI hosts, keep the `Authorization` passthrough rule in the bundled `.htaccess`. The PHP curl extension is only needed for image downloads via `source_url`.

---

## 🤝 Contributing

This project exists because of the community. Contributions welcome:

- 🐛 **Bug reports** — found something broken? Open an issue
- 🌍 **Translations** — add your language, it's just one object to translate
- 💡 **Feature ideas** — we read every suggestion
- 🔧 **Pull requests** — code improvements, new block types, accessibility fixes

---

## 💡 GitBook vs Webstudio Docs

| | GitBook Free | GitBook $65/mo | **Webstudio Docs** |
|---|---|---|---|
| Price | $0 (limited) | $65/month/site | **Free forever** |
| Self-hosted | ✗ | ✗ | **✓** |
| Custom domain | ✗ | ✓ | **✓ (your server)** |
| Per-user fees | 1 user only | $12/user/month | **No per-user pricing** |
| Setup time | Account signup | Account + payment | **Upload project files** |
| Database | Cloud only | Cloud only | **None needed** |
| Data ownership | Theirs | Theirs | **100% yours** |
| Block editor | ✓ | ✓ | **✓ (14 block types)** |
| Code highlighting | ✓ | ✓ | **✓ (200+ languages)** |
| Full-text search | ✓ | ✓ | **✓** |
| Dark mode | ✓ | ✓ | **✓** |
| OG images | ✓ | ✓ | **✓ (auto-generated)** |
| Custom branding | ✗ | ✓ | **✓ (logo, colors, favicon)** |
| Vendor lock-in | Yes | Yes | **None** |

---

## 📄 License

MIT — use it for anything. Personal projects, client work, startups, enterprises. Free forever.

---

<p align="center">
  <strong>Built with ♥ by <a href="https://webstudio.ltd">webstudio.ltd</a></strong><br><br>
  We built this because we needed it. We open-sourced it because everyone deserves<br>
  good documentation tools without paying a monthly subscription.<br><br>
  If Webstudio Docs saves you time or money, consider giving us a ⭐<br>
  It helps others discover the project and keeps us motivated to ship more.
</p>
