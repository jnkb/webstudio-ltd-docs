<p align="center">
  <img src="http://docs.web-studio.sk/screenshots/hero.jpg" alt="Webstudio Docs" width="100%">
</p>

<p align="center">
  <img src="http://docs.web-studio.sk/screenshots/webstudio-docs-logo.png" alt="Webstudio Docs" width="50%">
</p>

<p align="center">
  <strong>Open-source, self-hosted documentation platform.</strong><br>
  A free GitBook alternative — 4 files, no database, no build process.<br>
  Upload to any PHP hosting and start writing.
</p>

<p align="center">
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-features">Features</a> •
  <a href="#-screenshots">Screenshots</a> •
  <a href="#-roadmap">Roadmap</a> •
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

- **4 files** — `index.php`, `api.php`, `auth.php`, `.htaccess`. That's the entire platform.
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

```bash
# 1. Clone
git clone https://github.com/webstudio-ltd/docs.git

# 2. Upload to your PHP server
#    index.php + api.php + auth.php + .htaccess

# 3. Open in browser → set your admin password → start writing
```

No `npm install`. No environment variables. No database migrations. No Docker. Just upload four files and you have a documentation site.

---

## ✨ Features

### Editor — 14 block types
- **Slash commands** — type `/` anywhere to search and insert any block type
- **Block picker** — click ⊕ to browse all available blocks
- **Headings, paragraphs, lists, checklists** — the basics, done right
- **Code blocks** with syntax highlighting (Prism.js, 200+ languages) and one-click copy button
- **Tables** with header rows
- **Images** — upload, paste URL, or drag & drop
- **Callouts** — info, tip, warning, danger with custom titles
- **Collapsible sections** — perfect for FAQs
- **Timeline** — ideal for changelogs and release notes
- **Cards** — icon grids with links to internal pages or external URLs
- **Video embeds** — YouTube, Vimeo
- **Quotes, delimiters, inline code, markers**
- **Drag & drop** block reordering with visual drop indicator
- **Undo / Redo** with full history (Ctrl+Z / Ctrl+Shift+Z)

### Navigation & Search
- **Spaces** — separate documentation sections (like GitBook spaces)
- **Tree structure** — pages, subpages, sections, drag & drop reorder
- **Full-text search** — Ctrl+K searches titles, subtitles, and page content
- **Table of contents** — auto-generated from headings with scroll spy
- **Keyboard navigation** — ← → for prev/next page, ? for all shortcuts
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
- **Per-page titles** — `Installation — My Docs` in browser tab

### Security
- **Setup wizard** — first-run password setup with strength validation
- **Bcrypt hashing** — passwords stored securely, never in plain text
- **Rate limiting** — 10 attempts per 5 minutes, brute force protection
- **`.htaccess` protection** — blocks direct access to data files
- **No database** — no SQL injection possible. Ever.

### Developer-Friendly
- **Easy to extend** — it's just HTML, CSS, JS and PHP. No framework, no abstraction layers
- **i18n ready** — English, German, and Slovak built-in, add your language by copying one object
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
- [ ] Markdown import & export
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
├── index.php      ← Main application (frontend + OG tag generation)
├── api.php        ← Backend API (pages, spaces, images, settings)
├── auth.php       ← Authentication (setup wizard, login, sessions)
├── .htaccess      ← Security (blocks /data/ from direct access)
├── data/          ← Auto-created on first run
│   ├── auth.json      ← Hashed password (bcrypt)
│   ├── settings.json  ← Site configuration
│   ├── spaces.json    ← Space definitions
│   └── pages/         ← One JSON file per page
└── images/        ← Auto-created
    └── og/            ← Auto-generated social sharing images
```

### Adding a Language

1. Open `index.php`, find the `TRANSLATIONS` object
2. Copy the entire `en: { ... }` block
3. Rename to your language code (e.g. `de`, `fr`, `es`)
4. Translate the values
5. Add the option to the language `<select>` in the settings HTML
6. Submit a PR — we'd love to include it!

### Nginx Users

`.htaccess` is Apache-only. For Nginx, add this to your server block:

```nginx
location /data/ { deny all; return 403; }
location ~ \.json$ { deny all; return 403; }
```

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
| Setup time | Account signup | Account + payment | **Upload 4 files** |
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
