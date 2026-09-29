// ════════════════════════════════════════
//  MARKDOWN IMPORT — Markdown text → EditorJS block data
//  Editor-only helper (linked by editor.php, not by the viewer).
//  Emits only block types/data shapes the editor already uses and only inline
//  tags its sanitize rules keep (b, i, a, code.inline-code, mark.cdx-marker, br).
//  Raw HTML in the source is escaped, never passed through.
// ════════════════════════════════════════
(function (root) {
  'use strict';

  const FENCE_RE   = /^( *)(`{3,}|~{3,})[ \t]*([^`]*)$/;
  const ATX_RE     = /^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/;
  const HR_RE      = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
  const QUOTE_RE   = /^ {0,3}> ?(.*)$/;
  const LIST_RE    = /^( *)([-*+]|\d{1,9}[.)])(?:[ \t]+(.*)|[ \t]*)$/;
  const TASK_RE    = /^\[([ xX])\][ \t]+(.*)$/;
  const SETEXT_RE  = /^ {0,3}(=+|-+)[ \t]*$/;
  const TABLE_SEP  = /^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/;
  const REFDEF_RE  = /^ {0,3}\[([^\]]+)\]:[ \t]*<?([^\s>]+)>?(?:[ \t]+(?:"[^"]*"|'[^']*'|\([^)]*\)))?[ \t]*$/;
  const IMG_ONLY   = /^!\[([^\]]*)\]\(\s*<?([^\s)>]+)>?(?:\s+(?:"([^"]*)"|'([^']*)'))?\s*\)$/;
  const LINKED_IMG = /^\[(!\[[^\]]*\]\([^)]*\))\]\([^)]*\)$/;
  const HTML_IMG   = /^<img\b[^>]*>$/i;

  // GitHub alerts (> [!NOTE]) and Obsidian callouts (> [!tip] Title) → callout types.
  const ALERT_TYPES = {
    note: 'info', info: 'info', important: 'info', abstract: 'info', summary: 'info', todo: 'info', question: 'info',
    tip: 'tip', hint: 'tip', success: 'tip', check: 'tip', done: 'tip', example: 'tip',
    warning: 'warning', attention: 'warning',
    caution: 'danger', danger: 'danger', error: 'danger', bug: 'danger', failure: 'danger',
  };

  function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function isBlank(line) { return !line || !line.trim(); }
  function indentOf(line) { return line.match(/^ */)[0].length; }

  function safeUrl(url) {
    const u = String(url || '').trim();
    if (!u) return '';
    // Block script-capable schemes; allow everything else (http, mailto, relative, #anchor…).
    if (/^(javascript|vbscript|data):/i.test(u.replace(/[\s\u0000-\u001f]/g, ''))) return '';
    return u;
  }

  // Same attribute conventions as the editor's link tool: external links open
  // in a new tab, ?page= links carry data-page-id for in-app navigation.
  function anchor(href, innerHtml) {
    const internal = /^\?page=([A-Za-z0-9_-]+)/.exec(href);
    let attrs = `href="${escHtml(href)}"`;
    if (internal) attrs += ` data-page-id="${internal[1]}"`;
    else if (/^(https?:)?\/\//i.test(href)) attrs += ' target="_blank" rel="noopener"';
    return `<a ${attrs}>${innerHtml}</a>`;
  }

  // Drop a common leading indentation of up to `n` spaces from a line.
  function stripIndent(line, n) {
    const ind = indentOf(line);
    return line.slice(Math.min(ind, n));
  }

  // ── Inline Markdown → HTML ──────────────────────────────────────────
  function inline(src, refs) {
    if (!src) return '';
    const slots = [];
    const hold = (html) => { slots.push(html); return '\u0000' + (slots.length - 1) + '\u0000'; };
    let s = String(src);

    // Code spans first — their content is literal.
    s = s.replace(/(`+)([\s\S]*?[^`])\1(?!`)/g, (m, ticks, code) => {
      let c = code.replace(/\n/g, ' ');
      if (/^ .* $/.test(c) && c.trim()) c = c.slice(1, -1);
      return hold(`<code class="inline-code">${escHtml(c)}</code>`);
    });

    // Backslash escapes and hard line breaks.
    s = s.replace(/\\\n/g, () => hold('<br>'));
    s = s.replace(/ {2,}\n/g, () => hold('<br>'));
    s = s.replace(/\\([!"#$%&'()*+,\-./:;<=>?@[\\\]^_`{|}~])/g, (m, ch) => hold(escHtml(ch)));
    s = s.replace(/\n/g, ' ');

    // Autolinks <https://…> and <mail@example.com>.
    s = s.replace(/<((?:https?|ftp):\/\/[^\s<>]+)>/gi, (m, url) => hold(anchor(url, escHtml(url))));
    s = s.replace(/<([^\s<>@]+@[^\s<>@]+\.[^\s<>@]+)>/g, (m, mail) => hold(`<a href="mailto:${escHtml(mail)}">${escHtml(mail)}</a>`));

    // A few inline HTML tags map onto supported formatting; others are dropped (text kept).
    s = s.replace(/<br\s*\/?>/gi, () => hold('<br>'));
    s = s.replace(/<(\/?)(b|strong|i|em|mark|code)\b[^>]*>/gi, (m, close, tag) => {
      const map = { b: 'b', strong: 'b', i: 'i', em: 'i', mark: 'mark', code: 'code' };
      const t = map[tag.toLowerCase()];
      if (close) return hold(`</${t}>`);
      if (t === 'mark') return hold('<mark class="cdx-marker">');
      if (t === 'code') return hold('<code class="inline-code">');
      return hold(`<${t}>`);
    });
    s = s.replace(/<!--[\s\S]*?-->/g, '');
    s = s.replace(/<\/?[a-z][a-z0-9-]*(?:\s[^<>]*)?\/?>/gi, '');

    // Images inside running text can't be shown inline → keep their alt text.
    s = s.replace(/!\[([^\]]*)\]\([^)]*\)/g, (m, alt) => alt);
    s = s.replace(/!\[([^\]]*)\](?:\[[^\]]*\])?/g, (m, alt) => alt);

    const link = (text, url) => {
      const href = safeUrl(url);
      const inner = inline(text, refs);
      return hold(href ? anchor(href, inner) : inner);
    };

    // Inline links [text](url "title") and reference links [text][ref] / [text][] / [text].
    s = s.replace(/\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*<?([^\s)>]*)>?(?:\s+(?:"[^"]*"|'[^']*'|\([^)]*\)))?\s*\)/g,
      (m, text, url) => link(text, url));
    if (refs && Object.keys(refs).length) {
      s = s.replace(/\[((?:[^\[\]]|\[[^\[\]]*\])*)\](?:\[([^\]]*)\])?/g, (m, text, ref) => {
        const key = (ref || text).trim().toLowerCase().replace(/\s+/g, ' ');
        return refs[key] ? link(text, refs[key]) : m;
      });
    }

    // Bare URLs (GFM autolink literals).
    s = s.replace(/\bhttps?:\/\/[^\s<>\u0000]*[^\s<>\u0000.,:;"'!?)\]]/g, (url) => hold(anchor(url, escHtml(url))));

    // Everything still unprotected is literal text.
    s = escHtml(s);

    // Emphasis.
    s = s.replace(/(\*\*\*|___)(?=\S)([\s\S]*?\S)\1/g, '<b><i>$2</i></b>');
    s = s.replace(/(\*\*|__)(?=\S)([\s\S]*?\S)\1/g, '<b>$2</b>');
    s = s.replace(/(^|[^*\w])\*(?=[^\s*])([^*]*?[^\s*])\*(?![*\w])/g, '$1<i>$2</i>');
    s = s.replace(/(^|[^_\w])_(?=[^\s_])([^_]*?[^\s_])_(?![_\w])/g, '$1<i>$2</i>');
    s = s.replace(/==(?=\S)([^=]*?\S)==/g, '<mark class="cdx-marker">$1</mark>');
    s = s.replace(/~~(?=\S)([\s\S]*?\S)~~/g, '$1'); // no strikethrough tool — keep the text

    // Restore held fragments (they may be nested inside each other).
    for (let guard = 0; guard < 5 && s.indexOf('\u0000') !== -1; guard++) {
      s = s.replace(/\u0000(\d+)\u0000/g, (m, n) => slots[+n] ?? '');
    }
    return s.trim();
  }

  // Plain text (for headings, code-like fields): inline HTML minus all tags.
  function inlinePlain(src, refs) {
    return inline(src, refs).replace(/<[^>]+>/g, '');
  }

  // ── Block parsing ───────────────────────────────────────────────────
  function startsBlock(line, forParagraph) {
    if (ATX_RE.test(line) || FENCE_RE.test(line) && indentOf(line) < 4 || HR_RE.test(line) || QUOTE_RE.test(line)) return true;
    if (/^ {0,3}<(details|summary|\/details)\b/i.test(line)) return true;
    const m = LIST_RE.exec(line);
    if (m && indentOf(line) < 4 && m[3] !== undefined) {
      // Only "1." (or a bullet) may interrupt a running paragraph, per CommonMark.
      if (!forParagraph || !/\d/.test(m[2]) || /^1[.)]$/.test(m[2])) return true;
    }
    return false;
  }

  function isTableStart(lines, i) {
    return i + 1 < lines.length && lines[i].includes('|') && TABLE_SEP.test(lines[i + 1]) && lines[i + 1].includes('-')
      && (lines[i + 1].includes('|') || lines[i].trim().startsWith('|'));
  }

  function splitRow(line) {
    let s = line.trim();
    if (s.startsWith('|')) s = s.slice(1);
    if (s.endsWith('|') && !s.endsWith('\\|')) s = s.slice(0, -1);
    const cells = [];
    let cur = '', inCode = 0;
    for (let k = 0; k < s.length; k++) {
      const ch = s[k];
      if (ch === '\\' && s[k + 1] === '|') { cur += '|'; k++; continue; }
      if (ch === '`') inCode = inCode ? 0 : 1;
      if (ch === '|' && !inCode) { cells.push(cur.trim()); cur = ''; continue; }
      cur += ch;
    }
    cells.push(cur.trim());
    return cells;
  }

  function imageBlock(url, caption) {
    return { type: 'image', data: { url, caption: caption || '', stretched: false, withBorder: false, withBackground: false } };
  }

  // Paragraph consisting solely of an image → image block (or null).
  function paragraphImage(text) {
    let t = text.trim();
    const linked = LINKED_IMG.exec(t);
    if (linked) t = linked[1];
    const m = IMG_ONLY.exec(t);
    if (m) {
      const alt = m[1].trim(), title = (m[3] || m[4] || '').trim();
      const caption = title || (/\.\w{2,5}$/.test(alt) ? '' : alt);
      return imageBlock(m[2], caption);
    }
    if (HTML_IMG.test(t)) {
      const src = /\bsrc\s*=\s*["']([^"']+)["']/i.exec(t);
      const alt = /\balt\s*=\s*["']([^"']*)["']/i.exec(t);
      if (src) return imageBlock(src[1], alt ? alt[1] : '');
    }
    return null;
  }

  function htmlImages(html) {
    const out = [];
    const re = /<img\b[^>]*>/gi;
    let m;
    while ((m = re.exec(html))) { const b = paragraphImage(m[0]); if (b) out.push(b); }
    return out;
  }

  function parseFence(lines, i) {
    const open = FENCE_RE.exec(lines[i]);
    const indent = open[1].length, fence = open[2];
    const code = [];
    i++;
    while (i < lines.length) {
      const close = /^( *)(`{3,}|~{3,})[ \t]*$/.exec(lines[i]);
      if (close && close[2][0] === fence[0] && close[2].length >= fence.length) { i++; break; }
      code.push(stripIndent(lines[i], indent));
      i++;
    }
    return { block: { type: 'code', data: { code: code.join('\n') } }, next: i };
  }

  // Flatten nested blocks (inside a quote/callout) into inline HTML.
  function blocksToInlineHtml(blocks) {
    const out = [];
    const listLines = (items, depth, ordered) => items.forEach((it, n) => {
      const bullet = ordered ? `${n + 1}.` : '•';
      out.push(`${'&nbsp;&nbsp;&nbsp;&nbsp;'.repeat(depth)}${bullet} ${it.content}`);
      listLines(it.items || [], depth + 1, ordered);
    });
    blocks.forEach(b => {
      const d = b.data;
      switch (b.type) {
        case 'paragraph': case 'header': out.push(d.text); break;
        case 'list': listLines(d.items, 0, d.style === 'ordered'); break;
        case 'checklist': d.items.forEach(it => out.push(`${it.checked ? '☑' : '☐'} ${it.text}`)); break;
        case 'code': out.push(d.code.split('\n').map(l => `<code class="inline-code">${escHtml(l)}</code>`).join('<br>')); break;
        case 'quote': case 'warning': out.push(d.text || d.message || ''); break;
        case 'table': d.content.forEach(r => out.push(r.join(' | '))); break;
        case 'image': out.push(escHtml(d.caption || d.url)); break;
        default: break;
      }
    });
    return out.filter(Boolean).join('<br>');
  }

  function parseQuote(lines, i, refs) {
    const inner = [];
    while (i < lines.length) {
      const m = QUOTE_RE.exec(lines[i]);
      if (m) { inner.push(m[1]); i++; continue; }
      // Lazy continuation of a paragraph inside the quote.
      if (!isBlank(lines[i]) && inner.length && !isBlank(inner[inner.length - 1]) && !startsBlock(lines[i], true)) {
        inner.push(lines[i]); i++; continue;
      }
      break;
    }
    const alert = /^\[!(\w+)\][-+]?[ \t]*(.*)$/.exec(inner[0] ? inner[0].trim() : '');
    if (alert) {
      const type = ALERT_TYPES[alert[1].toLowerCase()] || 'info';
      const body = blocksToInlineHtml(parseBlocks(inner.slice(1), refs));
      return { block: { type: 'warning', data: { type, title: inline(alert[2], refs), message: body } }, next: i };
    }
    const html = blocksToInlineHtml(parseBlocks(inner, refs));
    return { block: { type: 'quote', data: { text: html, caption: '', alignment: 'left' } }, next: i };
  }

  function parseTable(lines, i, refs) {
    const header = splitRow(lines[i]);
    const cols = header.length;
    const rows = [header];
    i += 2;
    while (i < lines.length && !isBlank(lines[i]) && lines[i].includes('|') && !startsBlock(lines[i], true)) {
      rows.push(splitRow(lines[i]));
      i++;
    }
    const content = rows.map(r => {
      const cells = r.slice(0, cols);
      while (cells.length < cols) cells.push('');
      return cells.map(c => inline(c, refs));
    });
    return { block: { type: 'table', data: { withHeadings: true, content } }, next: i };
  }

  // Lists (bullet, ordered, task). A fenced code block inside an item ends the
  // current list, becomes its own code block, and the list continues afterwards.
  function parseList(lines, i, refs) {
    const blocks = [];
    let raw = [];
    let cur = null;
    let blank = false;

    const flush = () => {
      if (raw.length) blocks.push(buildList(raw, refs));
      raw = []; cur = null;
    };

    while (i < lines.length) {
      const line = lines[i];
      if (isBlank(line)) { blank = true; i++; continue; }

      const m = LIST_RE.exec(line);
      const ind = indentOf(line);
      const isItem = m && !HR_RE.test(line) && (ind < 4 || (cur && ind < cur.contentIndent + 4));

      if (isItem) {
        const markerWidth = m[2].length + 1;
        const ordered = /\d/.test(m[2]);
        const task = TASK_RE.test(m[3] || '');
        // Switching between bullet/numbered/task at the top level starts a new list.
        if (raw.length && ind < raw[0].indent + 2 && (ordered !== raw[0].ordered || task !== raw[0].task)) flush();
        cur = { indent: ind, ordered, task, text: [m[3] || ''], contentIndent: ind + markerWidth };
        raw.push(cur);
        blank = false; i++;
        continue;
      }

      if (cur && ind >= Math.min(cur.contentIndent, cur.indent + 2)) {
        const stripped = stripIndent(line, cur.contentIndent);
        if (/^ {0,3}(`{3,}|~{3,})/.test(stripped)) {
          flush();
          const fenceLines = lines.slice(i).map(l => stripIndent(l, ind));
          const f = parseFence(fenceLines, 0);
          blocks.push(f.block);
          i += f.next;
          blank = false;
          continue;
        }
        cur.text.push((blank ? '\u0001' : '') + stripped.trim());
        blank = false; i++;
        continue;
      }

      // Lazy continuation line (no blank line in between, not a new block).
      if (cur && !blank && !startsBlock(line, true)) {
        cur.text.push(line.trim());
        i++;
        continue;
      }
      break;
    }
    flush();
    return { blocks, next: i };
  }

  function itemHtml(textLines, refs) {
    // "\u0001" marks a continuation paragraph after a blank line.
    return textLines.join('\n').split('\n\u0001').map(p => inline(p, refs)).filter(Boolean).join('<br>');
  }

  function buildList(raw, refs) {
    const firstTask = TASK_RE.exec(raw[0].text[0] || '');
    if (firstTask) {
      const items = raw.map(it => {
        const lines = it.text.slice();
        const tm = TASK_RE.exec(lines[0] || '');
        let checked = false;
        if (tm) { checked = tm[1] !== ' '; lines[0] = tm[2]; }
        return { text: itemHtml(lines, refs), checked };
      });
      return { type: 'checklist', data: { items } };
    }

    const rootNode = { indent: -2, items: [] };
    const stack = [rootNode];
    raw.forEach(it => {
      while (stack.length > 1 && it.indent < stack[stack.length - 1].indent + 2) stack.pop();
      const node = { content: itemHtml(it.text, refs), items: [], indent: it.indent };
      stack[stack.length - 1].items.push(node);
      stack.push(node);
    });
    const clean = (items) => items.map(n => ({ content: n.content, items: clean(n.items) }));
    return { type: 'list', data: { style: raw[0].ordered ? 'ordered' : 'unordered', items: clean(rootNode.items) } };
  }

  function parseDetails(lines, i, refs) {
    const buf = [];
    let depth = 0;
    while (i < lines.length) {
      const line = lines[i];
      depth += (line.match(/<details\b/gi) || []).length;
      depth -= (line.match(/<\/details>/gi) || []).length;
      buf.push(line);
      i++;
      if (depth <= 0) break;
    }
    const html = buf.join('\n');
    const sum = /<summary[^>]*>([\s\S]*?)<\/summary>/i.exec(html);
    const title = sum ? inlinePlain(sum[1].trim(), refs) : '';
    let body = html.replace(/<summary[^>]*>[\s\S]*?<\/summary>/i, '').replace(/<\/?details[^>]*>/gi, '');
    body = body.replace(/<!--[\s\S]*?-->/g, '').replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
    body = body.split('\n').map(l => l.replace(/\s+$/, '')).join('\n').replace(/^\n+|\n+$/g, '').replace(/\n{3,}/g, '\n\n');
    // Strip the most common Markdown markers so the plain-text body reads cleanly.
    body = body.replace(/\*\*([^*]+)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1').replace(/^ *```.*$/gm, '').replace(/^\n+|\n+$/g, '');
    return { block: { type: 'collapse', data: { title, body, open: false } }, next: i };
  }

  function parseBlocks(lines, refs) {
    const blocks = [];
    let i = 0;
    while (i < lines.length) {
      const line = lines[i];
      if (isBlank(line)) { i++; continue; }

      if (FENCE_RE.test(line) && indentOf(line) < 4) {
        const f = parseFence(lines, i); blocks.push(f.block); i = f.next; continue;
      }

      const atx = ATX_RE.exec(line);
      if (atx) {
        const text = inlinePlain(atx[2] || '', refs);
        if (text) blocks.push({ type: 'header', data: { text, level: Math.min(atx[1].length, 3) } });
        i++; continue;
      }

      if (HR_RE.test(line)) { blocks.push({ type: 'delimiter', data: { style: 'line' } }); i++; continue; }

      if (QUOTE_RE.test(line)) { const q = parseQuote(lines, i, refs); blocks.push(q.block); i = q.next; continue; }

      if (/^ {0,3}<details\b/i.test(line)) { const d = parseDetails(lines, i, refs); blocks.push(d.block); i = d.next; continue; }

      if (/^ {0,3}<!--/.test(line)) {
        while (i < lines.length && !lines[i].includes('-->')) i++;
        i++; continue;
      }

      const lm = LIST_RE.exec(line);
      if (lm && indentOf(line) < 4 && lm[3] !== undefined) {
        const l = parseList(lines, i, refs); blocks.push(...l.blocks); i = l.next; continue;
      }

      if (isTableStart(lines, i)) { const tb = parseTable(lines, i, refs); blocks.push(tb.block); i = tb.next; continue; }

      // Indented code block (4+ spaces, not inside a paragraph).
      if (indentOf(line) >= 4) {
        const code = [];
        while (i < lines.length && (isBlank(lines[i]) || indentOf(lines[i]) >= 4)) { code.push(lines[i].slice(4)); i++; }
        while (code.length && isBlank(code[code.length - 1])) code.pop();
        blocks.push({ type: 'code', data: { code: code.join('\n') } });
        continue;
      }

      // Paragraph (possibly a setext heading).
      const para = [line];
      i++;
      let setext = 0;
      while (i < lines.length && !isBlank(lines[i])) {
        const se = SETEXT_RE.exec(lines[i]);
        if (se) { setext = se[1][0] === '=' ? 1 : 2; i++; break; }
        if (startsBlock(lines[i], true) || isTableStart(lines, i)) break;
        para.push(lines[i]);
        i++;
      }
      const joined = para.map(l => l.replace(/^ +/, '')).join('\n');
      if (setext) {
        blocks.push({ type: 'header', data: { text: inlinePlain(joined, refs), level: setext } });
        continue;
      }
      const img = paragraphImage(joined);
      if (img) { blocks.push(img); continue; }
      const html = inline(joined, refs);
      if (html) blocks.push({ type: 'paragraph', data: { text: html } });
      else blocks.push(...htmlImages(joined)); // e.g. <p align="center"><img src="…"></p>
    }
    return blocks;
  }

  function parseFrontMatter(text) {
    const m = /^---[ \t]*\n([\s\S]*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/.exec(text);
    if (!m) return { meta: {}, body: text };
    const meta = {};
    m[1].split('\n').forEach(l => {
      const kv = /^([A-Za-z_][\w-]*)[ \t]*:[ \t]*(.*)$/.exec(l);
      if (kv) meta[kv[1].toLowerCase()] = kv[2].trim().replace(/^(["'])(.*)\1$/, '$2');
    });
    return { meta, body: text.slice(m[0].length) };
  }

  /**
   * Convert Markdown source into EditorJS blocks.
   * Returns { blocks, title, subtitle }: `title`/`subtitle` come from front matter,
   * or `title` from a leading H1 (which is then removed from `blocks`) when
   * `extractTitle` is set.
   */
  function markdownToBlocks(source, options = {}) {
    let text = String(source || '').replace(/\u0000/g, '').replace(/^﻿/, '').replace(/\r\n?/g, '\n');
    text = text.replace(/^[ \t]+/gm, ws => ws.replace(/\t/g, '    '));

    const fm = parseFrontMatter(text);
    text = fm.body;

    // Collect reference-style link definitions (outside code fences) and drop them.
    const refs = {};
    const lines = [];
    let inFence = null;
    text.split('\n').forEach(line => {
      const f = /^ *(`{3,}|~{3,})/.exec(line);
      if (f) {
        if (!inFence) inFence = f[1];
        else if (f[1][0] === inFence[0] && f[1].length >= inFence.length && /^ *(`{3,}|~{3,})[ \t]*$/.test(line)) inFence = null;
      }
      const def = !inFence && REFDEF_RE.exec(line);
      if (def) { refs[def[1].trim().toLowerCase().replace(/\s+/g, ' ')] = def[2]; return; }
      lines.push(line);
    });

    const blocks = parseBlocks(lines, refs);

    let title = fm.meta.title || '';
    const subtitle = fm.meta.description || fm.meta.subtitle || '';
    if (options.extractTitle) {
      const first = blocks[0];
      if (first && first.type === 'header' && first.data.level === 1) {
        if (!title) title = first.data.text;
        if (first.data.text === title || !fm.meta.title) blocks.shift();
      }
    }
    return { blocks, title: decodeEntities(title), subtitle: decodeEntities(subtitle) };
  }

  function decodeEntities(s) {
    return String(s || '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
  }

  /**
   * Heuristic: does pasted plain text look like Markdown worth converting?
   * Requires multiple lines plus at least one Markdown block construct, or
   * several lines with inline Markdown (links, bold, code spans).
   */
  function looksLikeMarkdown(text) {
    const s = String(text || '').replace(/\r\n?/g, '\n').trim();
    if (!s.includes('\n')) return false;
    const lines = s.split('\n');
    let score = 0;
    let inlineHits = 0;
    for (let k = 0; k < lines.length; k++) {
      const l = lines[k];
      if (ATX_RE.test(l) && /^ {0,3}#{1,6} \S/.test(l)) score += 2;
      else if (/^ {0,3}(```|~~~)/.test(l)) score += 2;
      else if (/^ {0,3}>\s?\S/.test(l)) score += 1;
      else if (/^ *([-*+]|\d{1,9}[.)]) \S/.test(l)) score += 1;
      else if (k > 0 && TABLE_SEP.test(l) && l.includes('|') && l.includes('-')) score += 2;
      else if (HR_RE.test(l)) score += 1;
      if (/\[[^\]]+\]\([^)\s]+\)|\*\*[^*\n]+\*\*|`[^`\n]+`/.test(l)) inlineHits++;
    }
    return score >= 2 || inlineHits >= 2 || (score >= 1 && inlineHits >= 1);
  }

  function isMarkdownFileName(name) {
    return /\.(md|markdown|mdown|mkd|mkdn|mdwn|mdtxt|mdtext)$/i.test(String(name || ''));
  }

  const api = { markdownToBlocks, looksLikeMarkdown, isMarkdownFileName, markdownInline: inline };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else Object.assign(root, api);
})(typeof window !== 'undefined' ? window : globalThis);
