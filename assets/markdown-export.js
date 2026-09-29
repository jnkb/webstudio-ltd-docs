// ════════════════════════════════════════
//  MARKDOWN EXPORT — EditorJS page data → Markdown text
//  Shared by the viewer and the editor (linked after shared.js by both).
//  Counterpart of assets/markdown-import.js: emits the syntax the importer
//  understands (GitHub alerts, ==mark==, <details>, backslash line breaks),
//  so an exported page can be dropped back into the editor.
// ════════════════════════════════════════
(function (root) {
  'use strict';

  const CALLOUT_ALERTS = { info: 'NOTE', tip: 'TIP', warning: 'WARNING', danger: 'CAUTION' };
  const INLINE_MARKS = { b: '**', strong: '**', i: '*', em: '*', mark: '==', s: '~~', del: '~~', strike: '~~' };

  function decodeEntities(s) {
    return String(s || '')
      .replace(/&nbsp;/g, ' ')
      .replace(/&#(\d+);/g, (m, n) => String.fromCodePoint(+n))
      .replace(/&#x([0-9a-f]+);/gi, (m, n) => String.fromCodePoint(parseInt(n, 16)))
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/&amp;/g, '&');
  }

  // Escape characters that would otherwise turn plain text into Markdown syntax.
  function escText(s) {
    return String(s)
      .replace(/([\\`*[\]])/g, '\\$1')
      .replace(/<(?=[A-Za-z\/!?])/g, '\\<')
      .replace(/(^|\W)_|_(?=\W|$)/g, (m, pre) => (pre !== undefined ? pre : '') + '\\_');
  }

  // Keep text at the start of a line from being read as a heading, quote or list.
  function escLineStart(line) {
    return line
      .replace(/^(\s*)([#>+-])(?=\s|$)/, '$1\\$2')
      .replace(/^(\s*)(\d+)([.)])(?=\s|$)/, '$1$2\\$3');
  }

  function codeSpan(text) {
    const runs = text.match(/`+/g) || [];
    const fence = '`'.repeat(Math.max(0, ...runs.map(r => r.length)) + 1);
    const pad = /^`|`$/.test(text) ? ' ' : '';
    return fence + pad + text + pad + fence;
  }

  function attr(tag, name) {
    const m = new RegExp(`\\b${name}\\s*=\\s*("([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i').exec(tag);
    return m ? decodeEntities(m[2] ?? m[3] ?? m[4] ?? '') : '';
  }

  function absUrl(url, opts) {
    const u = String(url || '').trim();
    if (!u || !opts.baseUrl || /^([a-z][a-z0-9+.-]*:|#|\/\/)/i.test(u)) return u;
    try { return new URL(u, opts.baseUrl).href; } catch (e) { return u; }
  }

  function mdUrl(url) {
    return /[\s()<>]/.test(url) ? `<${url.replace(/[<>]/g, encodeURIComponent)}>` : url;
  }

  // Wrap `inner` in a pair of markers, keeping surrounding whitespace outside
  // (`**foo **` is not bold in Markdown).
  function wrap(inner, open, close) {
    const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(inner);
    if (!m[2]) return inner;
    return m[1] + open + m[2] + close + m[3];
  }

  /**
   * Convert the inline HTML EditorJS stores (b, i, a, code, mark, br, …) to
   * Markdown. `br` is the replacement for line breaks.
   */
  function inline(html, opts = {}, br = '\\\n') {
    const src = String(html || '').replace(/\r\n?/g, '\n').replace(/\n/g, '<br>');
    const stack = [{ tag: '', buf: '' }];
    const top = () => stack[stack.length - 1];
    const closeFrame = () => {
      const f = stack.pop();
      let out = f.buf;
      if (f.tag === 'a') {
        const core = /^(\s*)([\s\S]*?)(\s*)$/.exec(out);
        out = f.href ? `${core[1]}[${core[2] || f.href}](${mdUrl(f.href)})${core[3]}` : out;
      } else if (INLINE_MARKS[f.tag]) {
        out = wrap(out, INLINE_MARKS[f.tag], INLINE_MARKS[f.tag]);
      }
      top().buf += out;
    };

    const re = /<code\b[^>]*>([\s\S]*?)<\/code>|<(\/?)([a-z][a-z0-9]*)\b[^>]*>|([^<]+|<)/gi;
    let m;
    while ((m = re.exec(src))) {
      if (m[1] !== undefined) {
        const text = decodeEntities(m[1].replace(/<br\s*\/?>/gi, ' ').replace(/<[^>]+>/g, ''));
        if (text) top().buf += codeSpan(text);
        continue;
      }
      if (m[4] !== undefined) { top().buf += escText(decodeEntities(m[4])); continue; }
      const closing = m[2] === '/';
      const tag = m[3].toLowerCase();
      if (tag === 'br') { top().buf += br; continue; }
      if (tag !== 'a' && !INLINE_MARKS[tag]) continue; // span, u, font, … → keep text only
      if (!closing) {
        const frame = { tag, buf: '' };
        if (tag === 'a') {
          const href = attr(m[0], 'href');
          const pageId = attr(m[0], 'data-page-id');
          frame.href = absUrl(pageId && !href ? `?page=${pageId}` : href, opts);
        }
        stack.push(frame);
      } else if (stack.some((f, idx) => idx > 0 && f.tag === tag)) {
        while (top().tag !== tag) closeFrame();
        closeFrame();
      }
    }
    while (stack.length > 1) closeFrame();
    return stack[0].buf.replace(/(\\\n)+$/, '').trim();
  }

  // Paragraph-like text: inline conversion plus line-start escaping per line.
  function textBlock(html, opts) {
    return inline(html, opts).split('\n').map(escLineStart).join('\n');
  }

  function prefixLines(text, first, rest) {
    return text.split('\n').map((l, i) => (i === 0 ? first : (l ? rest : rest.trimEnd())) + l).join('\n');
  }

  function plain(text) {
    return escText(String(text || '')).split('\n').map(escLineStart).join('\\\n');
  }

  function listItems(items, style, opts, depth = 0) {
    const out = [];
    (items || []).forEach((it, idx) => {
      const content = typeof it === 'string' ? it : (it && it.content) || '';
      let marker = style === 'ordered' ? `${idx + 1}. ` : '- ';
      if (style === 'checklist') marker = `- [${it && it.meta && it.meta.checked ? 'x' : ' '}] `;
      const indent = ' '.repeat(marker.length);
      out.push(prefixLines(inline(content, opts) || ' ', marker, indent));
      const sub = it && typeof it === 'object' && it.items;
      if (sub && sub.length) out.push(prefixLines(listItems(sub, style, opts, depth + 1), indent, indent));
    });
    return out.join('\n');
  }

  function tableCell(html, opts) {
    return inline(html, opts, '<br>').replace(/\|/g, '\\|') || ' ';
  }

  function table(d, opts) {
    const rows = (d.content || []).filter(r => Array.isArray(r));
    if (!rows.length) return '';
    const cols = Math.max(...rows.map(r => r.length));
    const line = cells => '| ' + Array.from({ length: cols }, (_, i) => tableCell(cells[i], opts)).join(' | ') + ' |';
    // GFM tables always have a header row; without headings it stays empty.
    const head = d.withHeadings ? rows[0] : [];
    const body = d.withHeadings ? rows.slice(1) : rows;
    return [line(head), '|' + ' --- |'.repeat(cols), ...body.map(line)].join('\n');
  }

  function callout(d, opts) {
    const lines = [`[!${CALLOUT_ALERTS[d.type] || 'NOTE'}]`];
    const title = inline(d.title, opts);
    const message = textBlock(d.message, opts);
    if (!title && !message) return '';
    if (title) lines.push(`**${title}**` + (message ? '\\' : ''));
    if (message) lines.push(message);
    return prefixLines(lines.join('\n'), '> ', '> ');
  }

  function timeline(d) {
    return (d.items || []).filter(item => item && (item.date || item.title || item.desc)).map((item, i) => {
      const marker = d.numbered ? `${i + 1}. ` : '- ';
      const head = [item.date ? `**${escText(item.date)}**` : '', item.title ? escText(item.title) : ''].filter(Boolean).join(' — ');
      const body = [head, item.desc ? plain(item.desc) : ''].filter(Boolean).join('\\\n') || ' ';
      return prefixLines(body, marker, ' '.repeat(marker.length));
    }).join('\n');
  }

  function cards(d, opts) {
    return (d.cards || []).map(card => {
      let title = card.title ? `**${escText(card.title)}**` : '';
      if (card.link) {
        const href = absUrl(/^[A-Za-z0-9_-]+$/.test(card.link) ? `?page=${card.link}` : card.link, opts);
        title = `[${title || escText(card.link)}](${mdUrl(href)})`;
      }
      const desc = card.desc ? escText(card.desc).replace(/\n/g, ' ') : '';
      return '- ' + ([title, desc].filter(Boolean).join(' — ') || ' ');
    }).join('\n');
  }

  function codeFence(d) {
    const code = String(d.code || '').replace(/\r\n?/g, '\n').replace(/\n+$/, '');
    const runs = code.match(/^ *`{3,}/gm) || [];
    const fence = '`'.repeat(Math.max(2, ...runs.map(r => r.trim().length)) + 1);
    const lang = String(d.language || d.lang || '').replace(/[^\w+#.-]/g, '');
    return `${fence}${lang}\n${code}\n${fence}`;
  }

  function block(b, opts) {
    const d = (b && b.data) || {};
    switch (b && b.type) {
      case 'paragraph': return textBlock(d.text, opts);
      case 'header': {
        const text = inline(d.text, opts, ' ');
        return text ? '#'.repeat(Math.min(6, Math.max(1, d.level || 2))) + ' ' + text : '';
      }
      case 'list':      return listItems(d.items, d.style, opts);
      case 'checklist':
        return (d.items || []).map(it => prefixLines(inline(it.text, opts) || ' ', `- [${it.checked ? 'x' : ' '}] `, '      ')).join('\n');
      case 'quote': {
        const text = textBlock(d.text, opts);
        const caption = inline(d.caption, opts, ' ');
        const body = caption ? `${text}\n\n— ${caption}` : text;
        return body ? prefixLines(body, '> ', '> ') : '';
      }
      case 'code':      return codeFence(d);
      case 'delimiter': return '---';
      case 'table':     return table(d, opts);
      case 'image': {
        if (!d.url) return '';
        const caption = decodeEntities(String(d.caption || '').replace(/<[^>]+>/g, '')).trim();
        return `![${caption.replace(/([\\[\]])/g, '\\$1')}](${mdUrl(absUrl(d.url, opts))})`;
      }
      case 'warning':   return callout(d, opts);
      case 'timeline':  return timeline(d);
      case 'collapse': {
        const body = String(d.body || '').replace(/\r\n?/g, '\n').trim();
        const summary = String(d.title || '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
        return `<details>\n<summary>${summary}</summary>\n\n${body}\n\n</details>`;
      }
      case 'video': {
        const src = d.src || d.embedUrl;
        return src ? `[${escText(d.caption || 'Video')}](${mdUrl(absUrl(src, opts))})` : '';
      }
      case 'cards':     return cards(d, opts);
      default:          return d.text ? textBlock(d.text, opts) : '';
    }
  }

  // Quote with whichever quote character the value does not contain — the
  // importer strips surrounding quotes but does not unescape.
  function yamlString(s) {
    const v = String(s).replace(/\s+/g, ' ');
    return v.includes('"') && !v.includes("'") ? `'${v}'` : `"${v.replace(/"/g, "'")}"`;
  }

  /**
   * Convert a page ({ title, subtitle, content: { blocks } }) to a Markdown
   * document. Front matter carries title/description (the importer reads it),
   * followed by the title as H1 and the blocks separated by blank lines.
   * `options.baseUrl` makes relative image/link URLs absolute.
   */
  function pageToMarkdown(page, options = {}) {
    const title = String((page && page.title) || '').trim();
    const subtitle = String((page && page.subtitle) || '').trim();
    const parts = [];
    const fm = [];
    if (title) fm.push(`title: ${yamlString(title)}`);
    if (subtitle) fm.push(`description: ${yamlString(subtitle)}`);
    if (fm.length) parts.push(`---\n${fm.join('\n')}\n---`);
    if (title) parts.push(`# ${escText(title)}`);
    const blocks = (page && page.content && page.content.blocks) || [];
    blocks.forEach(b => {
      const md = block(b, options);
      if (md && md.trim()) parts.push(md);
    });
    return parts.join('\n\n') + '\n';
  }

  // File name from the page title; keeps Unicode, drops characters file systems reject.
  function markdownFileName(page) {
    const base = String((page && page.title) || '').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim();
    return (base || (page && page.id) || 'page').slice(0, 120) + '.md';
  }

  const api = { pageToMarkdown, markdownFileName, htmlToMarkdownInline: inline };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else Object.assign(root, api);
})(typeof window !== 'undefined' ? window : globalThis);
