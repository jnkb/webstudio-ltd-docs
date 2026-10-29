# AI Agent Access (MCP)

`mcp.php` is a [Model Context Protocol](https://modelcontextprotocol.io) server built into Webstudio Docs. It lets AI agents such as Claude Code, Claude Desktop, or any other MCP client read, search, write, and organize your documentation. They can do everything an admin can do with content in the editor.

This guide covers setup, the available tools, how content and uploads work, security, and troubleshooting. For a short overview, see the [README](README.md#-ai-agents-mcp).

**Contents**

- [How it works](#how-it-works)
- [1. Create an access token](#1-create-an-access-token)
- [2. Connect an agent](#2-connect-an-agent)
- [3. First steps](#3-first-steps)
- [Tool reference](#tool-reference)
- [Content format](#content-format)
- [Editing safely](#editing-safely)
- [Uploading images and videos](#uploading-images-and-videos)
- [Security](#security)
- [Server requirements](#server-requirements)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Protocol details](#protocol-details)

---

## How it works

- **One endpoint:** `https://your-domain.example/mcp.php` (next to `index.php` and `editor.php` in your installation).
- **Transport:** MCP **Streamable HTTP**, plain `POST` with JSON responses. There is no SSE stream and no session, so it runs on any PHP hosting without a background process or extra service.
- **Auth:** every request carries `Authorization: Bearer wsd_…`. Tokens are created in the editor and can be revoked at any time.
- **Same data as the editor:** agents work directly on the JSON files under `data/` and on `images/` and `videos/`. Changes show up in the public viewer right away.
- **Server-side sanitizing:** everything an agent writes is cleaned on the server before it is stored (see [Security](#security)).

## 1. Create an access token

1. Open `editor.php` and log in.
2. Go to **Settings → AI agents (MCP)**. The section shows the endpoint URL with a copy button.
3. Click **Create token** and give it a name you will recognise later, e.g. "Claude Code on my laptop".
4. Copy the token (`wsd_…`) right away. **It is shown only once.** The dialog also shows ready-to-paste setup snippets for Claude Code and for other MCP clients.

The same section lists all tokens with a short hint, the creation date, and the last use, and lets you **revoke** each one. A revoked token stops working immediately. Up to 20 tokens can exist at the same time.

Give each agent or machine its own token, so you can revoke one without affecting the others.

## 2. Connect an agent

### Claude Code

```bash
claude mcp add --transport http docs https://your-domain.example/mcp.php \
  --header "Authorization: Bearer wsd_your_token"
```

Add `--scope user` to make the server available in all your projects, or `--scope project` to share the config through `.mcp.json`. Do not commit a token: in a shared `.mcp.json`, use an environment variable such as `"Authorization": "Bearer ${DOCS_MCP_TOKEN}"`.

Check the connection with `claude mcp list` or `/mcp` inside Claude Code.

### Other MCP clients

Clients that support HTTP servers with custom headers usually take a JSON config like this:

```json
{
  "mcpServers": {
    "docs": {
      "type": "http",
      "url": "https://your-domain.example/mcp.php",
      "headers": { "Authorization": "Bearer wsd_your_token" }
    }
  }
}
```

### Claude Desktop

Claude Desktop's custom connectors cannot send a static `Authorization` header. Bridge the server through [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) in `claude_desktop_config.json` instead (needs Node.js):

```json
{
  "mcpServers": {
    "docs": {
      "command": "npx",
      "args": ["mcp-remote", "https://your-domain.example/mcp.php", "--header", "Authorization:${DOCS_AUTH}"],
      "env": { "DOCS_AUTH": "Bearer wsd_your_token" }
    }
  }
}
```

### Test with curl

You can check the endpoint without any MCP client:

```bash
curl -sS https://your-domain.example/mcp.php \
  -H "Authorization: Bearer wsd_your_token" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

A JSON list of tools means everything works. `401 Unauthorized` means the token is wrong or revoked, or the `Authorization` header does not reach PHP (see [Troubleshooting](#troubleshooting)).

## 3. First steps

When an agent connects, the server sends it instructions. In short: read the structure with `get_site` and `list_pages`, read content with `search_pages` / `get_page`, and read `get_content_guide` before writing anything.

Example requests you can give your agent:

- "Give me an overview of the docs: spaces, sections, and the page tree."
- "Find all pages that mention the old API endpoint and update them."
- "Create a page *Installation* in the *Getting Started* section with steps for Apache and Nginx."
- "Turn `CHANGELOG.md` from this repo into a timeline page and keep it as a draft."
- "Upload `./screenshots/settings.png` and add it under the *Settings* heading on the *configuration* page."
- "Move all API pages into a new space *API Reference*."

**If you have the editor open while an agent works, reload the editor afterwards** (see [Known limitations](#known-limitations)).

## Tool reference

All 23 tools come with descriptions and JSON schemas that the agent reads itself. This table is the overview for humans.

### Read

| Tool | What it does |
|---|---|
| `get_site` | Site overview: name, URLs, spaces with page counts, total pages and images, upload hints. The agent calls this first. |
| `list_pages` | Page tree per space in sidebar order, root pages grouped into sections, subpages nested. Metadata only. `include_drafts: false` shows what the public sees. |
| `get_page` | One page with all metadata and its EditorJS blocks (each with an `index` for `edit_blocks`), plus children, breadcrumb, and URLs. |
| `search_pages` | Full-text search over titles, subtitles, sections, and block text (all words must match), with snippets. Drafts are included and flagged. |
| `get_content_guide` | Reference for writing content: every block type, its fields, the allowed inline HTML, links, images, covers, sections, nesting, with examples. |
| `list_images` | Uploaded images, newest first, with URL, size, date, and the pages that use them. |
| `get_settings` | Site settings (name, colors, theme, language, footer, toggles), plus whether a logo or favicon is set. |

### Pages

| Tool | What it does |
|---|---|
| `create_page` | Create a page with title, blocks, and optional `space_id`, `parent_id`, `section`, `icon`, `subtitle`, `cover`, `draft`, `position`, or an explicit `id`. Without an `id`, it is derived from the title. |
| `update_page` | Change metadata and/or replace **all** blocks. Only the fields you pass change; `cover: null` removes the cover. Supports `if_updated_at`. |
| `edit_blocks` | Change single blocks without resending the page: `insert`, `replace`, `delete`, `move`. Operations run in order; one invalid operation cancels them all. Supports `if_updated_at`. |
| `move_page` | Move a page with its subpages under another parent, to the root, into another space, or to another position or section. |
| `reorder_pages` | Set the order of all siblings at once (root pages of a space or subpages of one parent). |
| `set_page_id` | Change a page ID (its URL). Subpages, ratings, and all internal links and card links in every page are updated. |
| `delete_page` | Move a page to the trash. A page with subpages needs `include_children: true`. Reports pages that still link to it. |
| `rename_section` | Rename a sidebar section of a space, merge it into an existing one, or remove the grouping (`to: ""`). |
| `set_section` | Put root pages into a section or take them out of it. |

### Spaces & settings

| Tool | What it does |
|---|---|
| `create_space` | Create a space (top-level tab), added after the existing ones. |
| `update_space` | Rename a space, change its icon, or move its tab (`position`). |
| `delete_space` | Delete a space and move all its pages to the trash. Needs `confirm: true`; the last space cannot be deleted. |
| `update_settings` | Change site name, tab title, accent color, default theme, interface language, footer, and the toggles for single-space tabs, share section, and translate widget. Logo and favicon can only be changed in the editor. |

### Media

| Tool | What it does |
|---|---|
| `create_upload_url` | Single-use, pre-signed upload URL for a local image or video (valid for 15 minutes). The recommended upload path for agents with a shell. |
| `upload_image` | Store an image from a public `source_url` or from `data_base64`. |
| `delete_image` | Delete an uploaded image permanently. Refuses images that pages still use unless `force: true`. |

## Content format

Agents write and receive content as **EditorJS blocks**, the format the editor stores in `data/pages/<id>.json`. Agents do not send Markdown. `get_content_guide` is the full reference. The short version:

| Block | `data` |
|---|---|
| `paragraph` | `{ text }` |
| `header` | `{ text, level: 1–3 }` |
| `list` | `{ style: "unordered" \| "ordered", items: [ { content, items: [...] } ] }` (nested) |
| `checklist` | `{ items: [ { text, checked } ] }` |
| `quote` | `{ text, caption }` |
| `code` | `{ code }` (language is detected automatically) |
| `delimiter` | `{ style: "stars" \| "line" \| "dashed" \| "dots" }` |
| `table` | `{ withHeadings, content: [ [cell, ...], ... ] }` |
| `image` | `{ url, caption, stretched, withBorder, withBackground }` |
| `warning` (callout) | `{ type: "info" \| "tip" \| "warning" \| "danger", title, message }` |
| `timeline` | `{ numbered, items: [ { date, title, desc } ] }` |
| `collapse` | `{ title, body, open }` |
| `video` | `{ embedUrl }` (YouTube, Vimeo, or another host's embed URL) or `{ src, filename, mime }` (uploaded file) |
| `cards` | `{ cols: 2–4, cards: [ { icon, title, desc, link } ] }` (`link`: URL or page ID) |

- **Inline HTML** in text fields is limited to `b`, `strong`, `i`, `em`, `u`, `s`, `mark`, `code`, `br`, and `a`. Anything else is escaped and shows up as visible text.
- **Internal links:** `<a href="?page=<id>" data-page-id="<id>">`. `set_page_id` keeps them up to date when a page ID changes.
- **Structure:** spaces contain pages, pages form a tree (`parent_id`), and root pages can be grouped into named **sections** in the sidebar. Subpages never have a section.
- **Drafts:** `draft: true` hides a page **and all its subpages** from the public: sidebar, direct URL, API, link previews, and OG meta. Logged-in editors still see them. The editor has no draft switch yet, so drafts are set through MCP.
- **Page IDs** are lowercase `a-z`, `0-9`, `-`, `_` (max. 60 characters) and form the URL `index.php?page=<id>`.

## Editing safely

- **Optimistic locking:** `update_page` and `edit_blocks` accept `if_updated_at`, the `updatedAt` value the agent read with `get_page`. If the page changed in the meantime, the write is rejected and nothing is overwritten.
- **Prefer `edit_blocks` for small changes.** Blocks the agent does not touch stay exactly as stored, including blocks created in the editor.
- **Nothing is hard-deleted:** `delete_page` and `delete_space` move pages to `data/trash/pages`. To restore a page, move its JSON file back to `data/pages/` on the server (drop a `__dup2`-style suffix if the trash already held a file with that name).
- **Images are the exception:** `delete_image` deletes the file permanently (images have no trash). It refuses images that are still in use unless `force: true`.
- **Changing a page ID** breaks external links and bookmarks to `?page=<old id>`. Internal links are rewritten.

## Uploading images and videos

Images: PNG, JPEG, GIF, WebP, max. 10 MB, **no SVG** through MCP. Videos: MP4, WebM, Ogg, max. 2 GB. The server tells agents which path to use:

1. **Local file and a shell (recommended):** the agent calls `create_upload_url` (`kind: "image"` or `"video"`) and gets a pre-signed, single-use URL that is valid for 15 minutes and needs no `Authorization` header. The agent never has to read the bearer token from its MCP client config:

   ```bash
   curl -sS -T ./screenshot.png "https://your-domain.example/mcp.php?upload=image&ticket=wsu_…&filename=screenshot.png"
   ```

2. **Image on the public internet:** `upload_image` with `source_url`. The server downloads it (http/https, public internet addresses only, max. 3 redirects, 20 s).
3. **`upload_image` with `data_base64`:** only when the bytes come from a program or tool output. Models that type or transcribe base64 by hand produce corrupt files.

Each upload answers with the root-relative `url` to store, an `absoluteUrl`, `width`/`height` for images, and a ready-to-use `block` for an image or video block.

**Integrity check:** every image upload (all MCP paths and the editor upload) is checked to be a complete, decodable image. `getimagesize()` must agree with the detected type. A structural check catches truncated files (missing PNG `IEND`, JPEG end-of-image marker, cut-off GIF blocks, short WebP RIFF). GD decodes the whole image when it supports the type and the bitmap fits into memory (up to 50 megapixels). Corrupt data is refused with *"The image data is corrupt or truncated"* and nothing is stored. SVG files uploaded in the editor are not checked this way.

### Direct upload for scripts

Scripts that hold the bearer token can send files directly:

```bash
# Images
curl -H "Authorization: Bearer wsd_your_token" --data-binary @screenshot.png \
  "https://your-domain.example/mcp.php?upload=image&filename=screenshot.png"

# Videos: use PUT (-T) for large files
curl -T demo.mp4 -H "Authorization: Bearer wsd_your_token" \
  "https://your-domain.example/mcp.php?upload=video&filename=demo.mp4"
```

Both endpoints take the file itself as the request body (`POST` or `PUT`, not a multipart form) and answer with plain JSON. `curl -T` sends a `PUT`, which avoids PHP `post_max_size` warnings for big videos. Hosted videos can also be embedded with a video block: YouTube and Vimeo links are converted to embed URLs, and other hosts work with their embed or player URL.

## Security

- **A token has full admin rights for content.** Anyone who holds it can create, change, and delete pages, spaces, settings, and images. Treat it like your admin password.
- **Revoke** unused or leaked tokens in Settings → AI agents (MCP). Revoked or unknown tokens get `401 Unauthorized`.
- **Only a SHA-256 hash is stored** in `data/mcp_tokens.json` (protected like the rest of `data/`). The plain token cannot be recovered; create a new one if it gets lost.
- **Everything an agent writes is sanitized on the server.** Inline HTML is reduced to a whitelist of tags and attributes. Links are limited to safe schemes. URLs are percent-encoded so they cannot break out of HTML attributes. Video embeds only take http(s) URLs (shown in an iframe, as in the editor), and icons and cover values are validated. A prompt-injected agent cannot plant scripts in your public docs or in the editor.
- **Footer HTML** written through MCP is limited to the same whitelist the footer uses in the browser: `a`, `b`, `strong`, `i`, `em`, `u`, `span`, `small`, `code`, `br`, with `title` on all of them and `href`/`target`/`rel` on links.
- **`source_url` downloads only reach the public internet.** The server resolves the host itself and refuses loopback, private LAN, link-local (including cloud metadata such as `169.254.169.254`), CGNAT, multicast, and other reserved addresses, in any IP notation and on every redirect hop. The connection is pinned to the checked address, so DNS rebinding cannot get past the check. Network errors are reported generically, so the tool cannot be used to probe internal hosts or ports. To upload a file from your own machine or LAN, use `create_upload_url` or the direct upload endpoint.
- **Upload URLs from `create_upload_url` are single-use.** The URL itself is the credential for exactly one upload. It expires after 15 minutes. The first upload attempt that gets past the header checks uses it up, even if that upload fails, so it can never be replayed or used by two requests at once. It allows nothing but that one image or video upload, and it stops working when the token that created it is revoked. Only a SHA-256 hash is stored in `data/mcp_upload_tickets.json`, and at most 50 can be open at once. Because the ticket is a query parameter, it shows up in web server access logs. That is acceptable: a logged URL is normally already used or expired, and even an unused one grants no admin rights and no read access, only one upload into `images/` or `videos/`.
- **The optional access gate does not apply to `mcp.php`.** The bearer token replaces the access cookie, and ticket uploads do not use it either.
- **Drafts stay private:** a draft and all its subpages are hidden from the public. Logged-in editors still see them.

## Server requirements

- **PHP 7.4+**, the same as the rest of Webstudio Docs. `data/`, `images/`, and `videos/` must be writable.
- **Authorization header:** on PHP-FPM / CGI hosts, Apache drops the `Authorization` header unless it is passed through. The bundled `.htaccess` already does this (`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`); keep it when you customise the file. Nginx passes the header to PHP-FPM by default.
- **PHP curl extension:** only needed for `upload_image` with `source_url`. Everything else, including both upload endpoints, works without it.
- **PHP GD** (optional): used for the full decode check of uploaded images. Without GD, the type and structure checks still run.
- **Upload limits:** direct uploads stream the request body, so PHP's `upload_max_filesize` does not apply. For large videos, prefer `PUT` (`curl -T`), and make sure the web server allows large request bodies (e.g. `client_max_body_size` on Nginx, `LimitRequestBody` on Apache).

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `401 Unauthorized` | The token is wrong, revoked, or missing. Create a new token and check the client config. On FPM/CGI hosts, make sure the `.htaccess` header passthrough is in place. |
| The client starts an OAuth or login flow, or reports an auth error | Some clients try OAuth discovery after a `401`. The server has no OAuth, so the real cause is the token; see above. |
| `405 Method not allowed` | The endpoint only takes `POST` (and `PUT` for uploads). Opening `mcp.php` in a browser sends `GET` and gets this error. |
| *"The image data is corrupt or truncated"* | The file is broken, usually because base64 was typed by hand. Upload the file with `create_upload_url` instead. |
| Upload URL refused | Ticket URLs work once and for 15 minutes. Request a new one for every file and every retry. |
| `source_url` download refused | The URL points to localhost, a LAN address, or another non-public address, or the PHP curl extension is missing. Use `create_upload_url` for local files. |
| Agent changes disappear | An editor tab that was open during the agent's work saved its older copy. Reload the editor after agent changes. |
| A page is missing in the public viewer | It is a draft, or it sits below a draft. Check with `list_pages include_drafts: false`. |

## Known limitations

- **Concurrent editing:** the editor writes `spaces.json` and `settings.json` as a whole on every save, and a page that is open in the editor is saved from its in-browser copy. If an editor tab is open while an agent works, that tab can overwrite the agent's changes. **Reload the editor after an agent has made changes.** Agents protect their own writes with `if_updated_at`; there is no real locking.
- **No Markdown through MCP:** agents read and write EditorJS blocks. The Markdown import and download in the editor run in the browser only.
- **No token scopes and no OAuth:** every token has full rights for content.
- **No logo or favicon changes:** `update_settings` cannot change them; use the editor.
- **No SVG uploads through MCP.** The editor still accepts SVG.

## Protocol details

- Streamable HTTP with JSON responses, `POST` only. No SSE, no `Mcp-Session-Id`. `GET` and `DELETE` get `405` with an `Allow` header.
- Supported protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`. If the client asks for one of these, the server uses it; otherwise it answers with the newest.
- JSON-RPC batches (arrays) are processed. Notifications get `202 Accepted` without a body.
- Tool errors come back as tool results with `isError: true`, so the model sees the message and can react to it.
- The server instructions (`mcpInstructions()`) and the content guide (`mcpContentGuide()`) in `mcp.php` are what agents read. Keep them in sync with tool behavior when you change the code.
