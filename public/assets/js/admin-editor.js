/*
 * The editor of the main content: a visual editor over Markdown. What is saved is always Markdown, in the textarea named "body"; the
 * visual side only shows it the way the site does and writes Markdown back.
 *
 *   - The server draws the Markdown block by block (/admin/markdown-visual) with the lines each block came from. A block the person
 *     does not touch is written back exactly as it was; only the blocks they edit are written again. Raw HTML is shown as a block
 *     that cannot be edited here and is kept character for character.
 *   - Nothing is written to the textarea until the person types something, so opening a page and leaving it never changes the file.
 *   - Markdown mode is the plain textarea with a few buttons. The mode is remembered. If the visual editor cannot start, the textarea
 *     is what is shown, and nothing is lost.
 *
 * Needs: the markup of admin/templates/edit.twig (data-editor ...). FarosMediaPicker (admin-media-picker.js) is used when it is there.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-editor]');
  if (!box) { return; }
  var area = box.querySelector('[data-md-body]');
  var surface = box.querySelector('[data-ed-surface]');
  var tools = box.querySelector('[data-ed-tools]');
  var mdTools = box.querySelector('[data-md-toolbar]');
  var note = box.querySelector('[data-ed-note]');
  var words = box.querySelector('[data-ed-words]');
  var form = box.closest('form');
  var endpoint = box.getAttribute('data-endpoint') || '';
  var canRaw = box.getAttribute('data-raw-html') === '1';
  var csrfField = form ? form.querySelector('input[name="_csrf"]') : null;
  var supported = !!(surface && tools && window.fetch && window.DOMParser && endpoint && csrfField && 'contentEditable' in document.documentElement);

  var state = { mode: 'markdown', loaded: false, dirty: false, sources: [], snaps: [], tail: '', verbatim: true, saved: null };

  /* ---------------------------------------------------------------- small helpers */

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === false || value === null || value === undefined) { return; }
      if (key === 'class') { node.className = value; }
      else if (key === 'text') { node.textContent = value; }
      else if (key === 'html') { node.innerHTML = value; }
      else if (key.slice(0, 2) === 'on') { node.addEventListener(key.slice(2), value); }
      else { node.setAttribute(key, value === true ? '' : value); }
    });
    (children || []).forEach(function (child) { if (child) { node.appendChild(child); } });
    return node;
  }

  function say(text) { if (note) { note.textContent = text || ''; } }

  /* ---------------------------------------------------------------- DOM to Markdown */

  var BLOCK = { P: 1, DIV: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, PRE: 1, HR: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, SECTION: 1, ARTICLE: 1, FIGURE: 1 };

  function isBlock(node) {
    return node.nodeType === 1 && (BLOCK[node.tagName] === 1 || node.classList.contains('md-raw'));
  }

  /** Text as Markdown text: what would be read as formatting is escaped, and nothing else (a shortcode like [form slug="x"] stays as typed). */
  function escapeText(text) {
    var links = /\]\(|\]\[/.test(text);
    return text.replace(/[\\`*_~<&\[\]{]/g, function (c, i) {
      var p = text.charAt(i - 1), n = text.charAt(i + 1);
      if (c === '_') { return /[\p{L}\p{N}]/u.test(p) && /[\p{L}\p{N}]/u.test(n) ? c : '\\_'; }
      if (c === '~') { return p === '~' || n === '~' ? '\\~' : c; }
      if (c === '<') { return /[A-Za-z\/!?]/.test(n) ? '\\<' : c; }
      if (c === '&') { return /^[#A-Za-z0-9]+;/.test(text.slice(i + 1)) ? '\\&' : c; }
      if (c === '[' || c === ']') { return links || (c === '[' && p === '!') ? '\\' + c : c; }
      // {key=value}, {.class} and {#id} are attributes of what is next to them, and would be read, not shown.
      if (c === '{') { return /^\{[^{}]*[=#.:][^{}]*\}/.test(text.slice(i)) ? '\\{' : c; }
      return '\\' + c;
    });
  }

  /** A line of a paragraph that would start a block when read back gets its first character escaped. */
  function guardLine(line) {
    return line
      .replace(/^( {0,3})(#{1,6})(?=\s|$)/, '$1\\$2')
      .replace(/^( {0,3})([>+-])(?=\s|$|[>+-]{2})/, '$1\\$2')
      .replace(/^( {0,3})(\d{1,9})([.)])(?=\s|$)/, '$1$2\\$3')
      .replace(/^( {0,3})(={2,}|-{2,})\s*$/, '$1\\$2')
      .replace(/^( {0,3})(`{3,}|~{3,})/, '$1\\$2')
      // A line that is only {…} would be read as the attributes of the block after it ({align=center}).
      .replace(/^( {0,3})\{(?=[^{}]*\}\s*$)/, '$1\\{');
  }

  function wrap(marker, inner, closing) {
    var m = /^(\s*)([\s\S]*?)(\s*)$/.exec(inner);
    if (m[2] === '') { return inner; }
    return m[1] + marker + m[2] + (closing || marker) + m[3];
  }

  function codeSpan(text) {
    text = text.replace(/\n/g, ' ');
    var longest = 0;
    (text.match(/`+/g) || []).forEach(function (run) { longest = Math.max(longest, run.length); });
    var fence = new Array(longest + 2).join('`');
    var pad = /^`|`$|^ .* $/.test(text) ? ' ' : '';
    return fence + pad + text + pad + fence;
  }

  function kids(node) {
    var out = '';
    Array.prototype.forEach.call(node.childNodes, function (child) { out += inlineNode(child); });
    return out;
  }

  function link(node) {
    var href = (node.getAttribute('href') || '').trim();
    var inner = kids(node);
    if (href === '') { return inner; }
    if (/[\s()<>]/.test(href)) { href = '<' + href.replace(/[<>]/g, encodeURIComponent) + '>'; }
    var title = node.getAttribute('title');
    // A link that opens in a new tab is written with an attribute after it: [text](address){target=_blank}.
    var blank = node.getAttribute('target') === '_blank' ? '{target=_blank}' : '';
    return '[' + inner + '](' + href + (title ? ' "' + title.replace(/"/g, '\\"') + '"' : '') + ')' + blank;
  }

  function image(node) {
    var src = (node.getAttribute('src') || '').trim();
    if (src === '') { return ''; }
    if (/[\s()<>]/.test(src)) { src = '<' + src.replace(/[<>]/g, encodeURIComponent) + '>'; }
    var alt = (node.getAttribute('alt') || '').replace(/([\[\]\\])/g, '\\$1');
    var title = node.getAttribute('title');
    var side = floatOf(node);
    return '![' + alt + '](' + src + (title ? ' "' + title.replace(/"/g, '\\"') + '"' : '') + ')' + (side !== '' ? '{align=' + side + '}' : '');
  }

  /** Which side a picture sits on beside the text, left or right; nothing for one in the line of text. Read from align, a WordPress class, or a float. */
  function floatOf(node) {
    var value = (node.getAttribute('align') || '').toLowerCase();
    if (value !== 'left' && value !== 'right') {
      var cls = ' ' + (node.getAttribute('class') || '').toLowerCase() + ' ';
      var match = /(?:^|;)\s*float:\s*(left|right)/i.exec(node.getAttribute('style') || '');
      value = /\salignleft\s/.test(cls) ? 'left' : (/\salignright\s/.test(cls) ? 'right' : (match ? match[1].toLowerCase() : ''));
    }
    return value === 'left' || value === 'right' ? value : '';
  }

  function inlineNode(node) {
    if (node.nodeType === 3) { return escapeText(node.nodeValue.replace(/​/g, '').replace(/[ \t\r\n ]+/g, ' ')); }
    if (node.nodeType !== 1) { return ''; }
    switch (node.tagName) {
      case 'BR': return '\n';
      case 'STRONG': case 'B': return wrap('**', kids(node));
      case 'EM': case 'I': return wrap('*', kids(node));
      case 'DEL': case 'S': case 'STRIKE': return wrap('~~', kids(node));
      case 'U': return wrap('<u>', kids(node), '</u>');
      case 'CODE': return codeSpan(node.textContent);
      case 'A': return link(node);
      case 'IMG': return image(node);
      case 'SPAN':
        if (node.classList.contains('md-raw-inline')) { return node.getAttribute('data-raw') || ''; }
        return kids(node);
      default: return kids(node);
    }
  }

  /** A run of inline nodes as one paragraph of Markdown, or nothing when it holds no text. */
  function paragraph(nodes) {
    var text = '';
    nodes.forEach(function (node) { text += inlineNode(node); });
    text = text.replace(/ *\n */g, '\n').replace(/^[ \n]+|[ \n]+$/g, '');
    if (text === '') { return ''; }
    return text.split('\n').map(guardLine).join('\n');
  }

  function indentLines(text, first, rest) {
    return text.split('\n').map(function (line, i) { return line === '' ? '' : (i === 0 ? first : rest) + line; }).join('\n');
  }

  /** Two lists of the same kind one after the other would read back as one, so the second uses the other marker. */
  function listString(list) {
    var ordered = list.tagName === 'OL';
    var before = list.previousElementSibling;
    var alt = !!before && before.tagName === list.tagName;
    var start = parseInt(list.getAttribute('start') || '1', 10) || 1;
    var items = Array.prototype.filter.call(list.children, function (child) { return child.tagName === 'LI'; });
    var loose = items.some(function (li) { return Array.prototype.some.call(li.children, function (c) { return c.tagName === 'P'; }); });
    var out = items.map(function (li, i) {
      var marker = ordered ? (start + i) + (alt ? ') ' : '. ') : (alt ? '* ' : '- ');
      var parts = blocksOf(li);
      var text = parts.join(loose ? '\n\n' : '\n');
      if (text === '') { return marker.trim(); }
      return indentLines(text, marker, new Array(marker.length + 1).join(' '));
    });
    return out.join(loose ? '\n\n' : '\n');
  }

  function codeBlock(pre) {
    var code = pre.querySelector('code');
    var text = (code || pre).textContent.replace(/\n$/, '');
    var lang = '';
    if (code) { var m = /(?:^|\s)language-([\w+#.-]+)/.exec(code.className); lang = m ? m[1] : ''; }
    var longest = 2;
    (text.match(/`{3,}/g) || []).forEach(function (run) { longest = Math.max(longest, run.length); });
    var fence = new Array(longest + 2).join('`');
    return fence + lang + '\n' + text + '\n' + fence;
  }

  function cellText(cell) {
    return kids(cell).replace(/\s*\n\s*/g, ' ').replace(/\|/g, '\\|').trim();
  }

  function tableString(table) {
    var rows = Array.prototype.slice.call(table.querySelectorAll('tr'));
    if (rows.length === 0) { return ''; }
    var width = 0;
    rows.forEach(function (row) { width = Math.max(width, row.children.length); });
    var aligns = [];
    Array.prototype.forEach.call(rows[0].children, function (cell, i) {
      var a = (cell.getAttribute('align') || (/text-align:\s*(left|right|center)/.exec(cell.getAttribute('style') || '') || [])[1] || '').toLowerCase();
      aligns[i] = a;
    });
    var line = function (row) {
      var cells = [];
      for (var i = 0; i < width; i++) { cells.push(row.children[i] ? cellText(row.children[i]) : ''); }
      return '| ' + cells.join(' | ') + ' |';
    };
    var rule = [];
    for (var i = 0; i < width; i++) {
      rule.push(aligns[i] === 'center' ? ':---:' : (aligns[i] === 'right' ? '---:' : (aligns[i] === 'left' ? ':---' : '---')));
    }
    return [line(rows[0]), '| ' + rule.join(' | ') + ' |'].concat(rows.slice(1).map(line)).join('\n');
  }

  /** How a paragraph or heading is aligned: center, right or justify; nothing for the usual (left). */
  function alignOf(node) {
    var value = (node.getAttribute('align') || (/(?:^|;)\s*text-align:\s*(center|right|justify)/i.exec(node.getAttribute('style') || '') || [])[1] || '').toLowerCase();
    return value === 'center' || value === 'right' || value === 'justify' ? value : '';
  }

  /** A paragraph or heading, with the line of attributes before it when it is aligned: {align=center}. */
  function blockString(node) {
    var text = blockText(node);
    var align = /^(P|H[1-6])$/.test(node.tagName) && !node.classList.contains('md-raw') ? alignOf(node) : '';
    return align !== '' && text !== '' ? '{align=' + align + '}\n' + text : text;
  }

  function blockText(node) {
    if (node.classList.contains('md-raw')) { return node.getAttribute('data-raw') || ''; }
    var tag = node.tagName;
    if (/^H[1-6]$/.test(tag)) {
      var text = paragraph(Array.prototype.slice.call(node.childNodes)).replace(/\n/g, ' ');
      return text === '' ? '' : new Array(parseInt(tag.charAt(1), 10) + 1).join('#') + ' ' + text;
    }
    switch (tag) {
      case 'UL': case 'OL': return listString(node);
      case 'PRE': return codeBlock(node);
      case 'HR': return '---';
      case 'TABLE': return tableString(node);
      case 'BLOCKQUOTE':
        var inner = blocksOf(node).join('\n\n');
        return inner === '' ? '' : inner.split('\n').map(function (l) { return l === '' ? '>' : '> ' + l; }).join('\n');
      default:
        if (node.querySelector && node.querySelector('table') && node.classList.contains('table-wrap')) { return tableString(node.querySelector('table')); }
        return blocksOf(node).join('\n\n');
    }
  }

  /** The blocks inside a container: a run of text and inline elements is a paragraph, a block element is a block. */
  function blocksOf(container) {
    var out = [], run = [];
    var flush = function () {
      if (run.length) { var text = paragraph(run); if (text !== '') { out.push(text); } run = []; }
    };
    Array.prototype.forEach.call(container.childNodes, function (node) {
      if (node.nodeType === 3) { run.push(node); return; }
      if (node.nodeType !== 1) { return; }
      if (!isBlock(node) && !(node.classList && node.classList.contains('table-wrap'))) { run.push(node); return; }
      flush();
      var text = blockString(node);
      if (text !== '') { out.push(text); }
    });
    flush();
    return out;
  }

  /** What a block looks like for the comparison with how it was drawn: without the mark of what is picked (a picture, an HTML box). */
  function snapshotOf(node) {
    if (!node.querySelector('.is-picked') && !node.classList.contains('is-picked')) { return node.outerHTML; }
    var copy = node.cloneNode(true);
    [copy].concat(Array.prototype.slice.call(copy.querySelectorAll('.is-picked'))).forEach(function (n) {
      n.classList.remove('is-picked');
      if (n.getAttribute('class') === '') { n.removeAttribute('class'); }
    });
    return copy.outerHTML;
  }

  /**
   * The whole document as Markdown. A top-level block that is exactly as it was drawn is written as the lines it came from.
   * @param {boolean} full write every block again (used to test that what is written reads back the same)
   */
  function serialize(full) {
    var out = [], used = {};
    var flush = null;
    var run = [];
    var endRun = function () {
      if (run.length) { var text = paragraph(run); if (text !== '') { out.push(text); } run = []; }
    };
    Array.prototype.forEach.call(surface.childNodes, function (node) {
      if (node.nodeType === 3) { run.push(node); return; }
      if (node.nodeType !== 1) { return; }
      if (!isBlock(node) && !node.classList.contains('table-wrap')) { run.push(node); return; }
      endRun();
      var index = node.getAttribute('data-b');
      if (!full && state.verbatim && index !== null && !used[index] && state.snaps[index] === snapshotOf(node) && state.sources[index] !== '') {
        used[index] = true;
        out.push(state.sources[index]);
        return;
      }
      var text = blockString(node);
      if (text !== '') { out.push(text); }
    });
    endRun();
    if (state.tail) { out.push(state.tail); }
    return out.join('\n\n');
  }

  /* ---------------------------------------------------------------- the server draws the Markdown */

  function draw(markdown) {
    var data = new FormData();
    data.append('_csrf', csrfField.value);
    data.append('body', markdown);
    return fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }, body: data })
      .then(function (response) { if (!response.ok) { throw new Error('status ' + response.status); } return response.json(); })
      .then(function (json) { if (!json || !json.ok) { throw new Error('refused'); } return json; });
  }

  function load(markdown) {
    return draw(markdown).then(function (json) {
      surface.innerHTML = '';
      state.sources = []; state.snaps = []; state.tail = json.tail || ''; state.verbatim = !!json.verbatim;
      json.blocks.forEach(function (block, i) {
        var holder = document.createElement('div');
        holder.innerHTML = block.html;
        if (holder.children.length !== 1) { state.verbatim = false; }
        Array.prototype.slice.call(holder.children).forEach(function (child) {
          child.setAttribute('data-b', String(i));
          surface.appendChild(child);
        });
        state.sources.push(block.source);
      });
      if (surface.children.length === 0) { surface.appendChild(el('p', { html: '<br>' })); }
      // The snapshots are taken from the page, so they are what the browser makes of the HTML.
      Array.prototype.forEach.call(surface.children, function (child) {
        var index = child.getAttribute('data-b');
        if (index !== null) { state.snaps[index] = child.outerHTML; }
      });
      state.loaded = true;
      state.dirty = false;
      count();
    });
  }

  /* ---------------------------------------------------------------- keeping the textarea in step */

  var timer = null;
  function sync() {
    clearTimeout(timer);
    if (state.mode !== 'visual' || !state.dirty) { return; }
    var markdown = serialize(false);
    if (markdown !== area.value) {
      area.value = markdown;
      area.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }
  function changed() {
    state.dirty = true;
    clearTimeout(timer);
    timer = setTimeout(sync, 300);
    count();
    updateState();
  }
  function count() {
    if (!words) { return; }
    var text = (state.mode === 'visual' ? surface.textContent : area.value).trim();
    var n = text === '' ? 0 : text.split(/\s+/).length;
    words.textContent = n + (n === 1 ? ' word' : ' words');
  }

  /* ---------------------------------------------------------------- selection helpers */

  var saved = null;
  function rememberRange() {
    var sel = window.getSelection();
    if (sel.rangeCount && surface.contains(sel.anchorNode)) { saved = sel.getRangeAt(0).cloneRange(); }
  }
  function restoreRange() {
    surface.focus();
    if (!saved) { return; }
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(saved);
  }
  function currentRange() {
    var sel = window.getSelection();
    return sel.rangeCount && surface.contains(sel.anchorNode) ? sel.getRangeAt(0) : null;
  }
  function closest(node, selector) {
    while (node && node !== surface) {
      if (node.nodeType === 1 && node.matches(selector)) { return node; }
      node = node.parentNode;
    }
    return null;
  }
  function topBlock(node) {
    while (node && node.parentNode !== surface) { node = node.parentNode; }
    return node && node.nodeType === 1 ? node : null;
  }
  function placeCaret(node, atEnd) {
    var range = document.createRange();
    range.selectNodeContents(node);
    range.collapse(!atEnd);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }
  /** Puts a block after the one the caret is in (or at the end) and leaves the caret in a paragraph below it. */
  function insertBlock(block) {
    var range = currentRange();
    var anchor = range ? topBlock(range.startContainer) : null;
    if (anchor && anchor.tagName === 'P' && anchor.textContent === '' && !anchor.querySelector('img')) { surface.replaceChild(block, anchor); }
    else if (anchor) { anchor.parentNode.insertBefore(block, anchor.nextSibling); }
    else { surface.appendChild(block); }
    if (!block.nextElementSibling) { surface.appendChild(el('p', { html: '<br>' })); }
    placeCaret(block.nextElementSibling, false);
    changed();
  }

  function command(name, value) {
    restoreRange();
    document.execCommand(name, false, value === undefined ? null : value);
    changed();
  }

  function wrapInline(tag) {
    restoreRange();
    var range = currentRange();
    if (!range) { return; }
    var existing = closest(range.commonAncestorContainer, tag);
    if (existing) {
      var parent = existing.parentNode;
      while (existing.firstChild) { parent.insertBefore(existing.firstChild, existing); }
      parent.removeChild(existing);
      changed();
      return;
    }
    if (range.collapsed || closest(range.commonAncestorContainer, 'pre') || topBlock(range.startContainer) !== topBlock(range.endContainer)) { return; }
    var node = document.createElement(tag);
    node.appendChild(range.extractContents());
    range.insertNode(node);
    placeCaret(node, true);
    changed();
  }

  function setBlock(tag) {
    restoreRange();
    var range = currentRange();
    if (!range) { return; }
    var quote = closest(range.startContainer, 'blockquote');
    if (tag === 'blockquote') {
      if (quote) { unwrap(quote); } else { document.execCommand('formatBlock', false, 'blockquote'); }
    } else {
      if (quote) { unwrap(quote); restoreRange(); }
      document.execCommand('formatBlock', false, tag === 'p' ? 'p' : tag);
    }
    changed();
  }
  /** Aligns the paragraphs and headings the selection touches: 'left' (the usual) takes the choice away. */
  function setAlign(value) {
    restoreRange();
    var range = currentRange();
    if (!range) { return; }
    var pick = function () {
      var found = [];
      Array.prototype.forEach.call(surface.querySelectorAll('p,h1,h2,h3,h4,h5,h6'), function (b) {
        if (range.intersectsNode(b) && !closest(b, '.md-raw') && !closest(b, 'td,th,li,pre')) { found.push(b); }
      });
      return found;
    };
    var blocks = pick();
    if (blocks.length === 0 && !closest(range.startContainer, 'li,td,th,pre') && !closest(range.startContainer, '.md-raw')) {
      // Text typed straight into the page, with no paragraph around it yet.
      document.execCommand('formatBlock', false, 'p');
      range = currentRange() || range;
      blocks = pick();
    }
    if (blocks.length === 0) { say('Alignment is for paragraphs and headings, not for list items, tables or code.'); return; }
    say('');
    blocks.forEach(function (b) {
      if (value === 'left') { b.removeAttribute('align'); } else { b.setAttribute('align', value); }
      if (b.getAttribute('style') !== null && /text-align/.test(b.getAttribute('style'))) {
        b.style.textAlign = '';
        if (b.getAttribute('style') === '') { b.removeAttribute('style'); }
      }
    });
    changed();
  }
  function unwrap(node) {
    var parent = node.parentNode;
    var first = node.firstChild;
    while (node.firstChild) { parent.insertBefore(node.firstChild, node); }
    parent.removeChild(node);
    if (first) { placeCaret(first.nodeType === 1 ? first : parent, false); }
  }

  /* ---------------------------------------------------------------- small forms that open under a button */

  var pop = null;
  function closePop() { if (pop) { pop.remove(); pop = null; } }
  /**
   * @param {Element} anchor the button
   * @param {string} title
   * @param {Array<{name: string, label: string, value?: string, placeholder?: string}>} fields
   * @param {function(Object)} done called with the values
   * @param {Object=} extra {ok: label, remove: function, library: function(Element[])}
   */
  function ask(anchor, title, fields, done, extra) {
    extra = extra || {};
    rememberRange();
    closePop();
    var inputs = {};
    var rows = fields.map(function (f) {
      if (f.type === 'checkbox') {
        inputs[f.name] = el('input', { type: 'checkbox', checked: !!f.value });
        return el('label', { class: 'ed-pop-check' }, [inputs[f.name], el('span', { text: f.label })]);
      }
      inputs[f.name] = el('input', { type: 'text', value: f.value || '', placeholder: f.placeholder || '', class: 'lc-input', autocomplete: 'off', spellcheck: 'false' });
      return el('label', { class: 'ed-pop-row' }, [el('span', { text: f.label }), inputs[f.name]]);
    });
    var actions = [el('button', { type: 'submit', class: 'ed-pop-ok', text: extra.ok || 'Apply' })];
    if (extra.library && window.FarosMediaPicker) {
      actions.push(el('button', { type: 'button', class: 'ed-pop-alt', text: 'Library…', onclick: function () { extra.library(inputs); } }));
    }
    if (extra.remove) {
      actions.push(el('button', { type: 'button', class: 'ed-pop-alt is-danger', text: extra.removeLabel || 'Remove', onclick: function () { closePop(); restoreRange(); extra.remove(); } }));
    }
    actions.push(el('button', { type: 'button', class: 'ed-pop-alt', text: 'Cancel', onclick: function () { closePop(); restoreRange(); } }));
    pop = el('form', { class: 'ed-pop', role: 'dialog', 'aria-label': title }, [el('strong', { text: title })].concat(rows, [el('div', { class: 'ed-pop-actions' }, actions)]));
    pop.addEventListener('submit', function (event) {
      event.preventDefault();
      var values = {};
      Object.keys(inputs).forEach(function (k) { values[k] = inputs[k].type === 'checkbox' ? inputs[k].checked : inputs[k].value.trim(); });
      closePop();
      restoreRange();
      done(values);
    });
    pop.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.stopPropagation(); closePop(); restoreRange(); } });
    box.appendChild(pop);
    var at = anchor.getBoundingClientRect(), host = box.getBoundingClientRect();
    pop.style.top = (at.bottom - host.top + 6) + 'px';
    pop.style.left = Math.max(0, Math.min(at.left - host.left, host.width - pop.offsetWidth - 8)) + 'px';
    var first = pop.querySelector('input[type="text"]');
    if (first) { first.focus(); first.select(); }
  }
  // A click outside the small form closes it, except in the toolbar and in the dialogs it opens (the picture library).
  document.addEventListener('mousedown', function (event) { if (pop && !pop.contains(event.target) && !event.target.closest('[data-ed-tools], dialog')) { closePop(); } });

  function safeUrl(value) {
    value = value.trim();
    if (value === '' || /^\s*(javascript|data|vbscript):/i.test(value)) { return ''; }
    if (/^([a-z][a-z0-9+.-]*:|\/|#|\?)/i.test(value)) { return value; }
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? 'mailto:' + value : (/^[^\s/]+\.[a-z]{2,}/i.test(value) ? 'https://' + value : value);
  }

  /** Makes a link open in a new tab (or not): the attribute the Markdown writes as {target=_blank}. */
  function setBlank(link, blank) {
    if (blank) { link.setAttribute('target', '_blank'); link.setAttribute('rel', 'noopener noreferrer'); }
    else { link.removeAttribute('target'); link.removeAttribute('rel'); }
  }

  function linkDialog(anchor) {
    rememberRange();
    var range = currentRange();
    var existing = range ? closest(range.commonAncestorContainer, 'a') : null;
    ask(anchor, existing ? 'Edit link' : 'Add a link', [
      { name: 'url', label: 'Address', value: existing ? existing.getAttribute('href') : '', placeholder: 'https://… or /page' },
      { name: 'blank', type: 'checkbox', label: 'Open in a new tab', value: existing ? existing.getAttribute('target') === '_blank' : false }
    ], function (v) {
      var url = safeUrl(v.url);
      if (url === '') { return; }
      var r = currentRange();
      var current = r ? closest(r.commonAncestorContainer, 'a') : null;
      if (current) { current.setAttribute('href', url); setBlank(current, v.blank); changed(); return; }
      if (r && r.collapsed) {
        document.execCommand('insertHTML', false, '<a href="' + url.replace(/"/g, '&quot;') + '"' + (v.blank ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + escapeHtml(v.url) + '</a>');
      } else {
        document.execCommand('createLink', false, url);
        var after = currentRange();
        if (after && v.blank) {
          Array.prototype.forEach.call(surface.querySelectorAll('a'), function (a) { if (after.intersectsNode(a) && a.getAttribute('href') === url) { setBlank(a, true); } });
        }
      }
      changed();
    }, existing ? { remove: function () { var u = closest(currentRange().commonAncestorContainer, 'a'); if (u) { unwrap(u); changed(); } }, removeLabel: 'Remove link' } : {});
  }

  function imageDialog(anchor) {
    ask(anchor, 'Add an image', [
      { name: 'url', label: 'Address', placeholder: '/uploads/picture.jpg' },
      { name: 'alt', label: 'Description', placeholder: 'What the picture shows' }
    ], function (v) {
      var url = safeUrl(v.url);
      if (url === '') { return; }
      document.execCommand('insertHTML', false, '<img src="' + url.replace(/"/g, '&quot;') + '" alt="' + v.alt.replace(/"/g, '&quot;') + '">');
      changed();
    }, {
      ok: 'Insert',
      library: function (inputs) {
        window.FarosMediaPicker.open(function (url, item) {
          inputs.url.value = url;
          if (item && item.alt && !inputs.alt.value) { inputs.alt.value = item.alt; }
          inputs.alt.focus();
        });
      }
    });
  }

  /* A picture in the text: a click picks it, and a small bar offers to change or remove it (Delete and Backspace remove it too). */

  var pictureBar = null;
  function unpick() {
    Array.prototype.forEach.call(surface.querySelectorAll('.is-picked'), function (n) { n.classList.remove('is-picked'); });
    if (pictureBar) { pictureBar.remove(); pictureBar = null; }
  }
  function pickPicture(img) {
    unpick();
    img.classList.add('is-picked');
    var keep = function (e) { e.preventDefault(); };
    var edit = el('button', { type: 'button', class: 'ed-text', text: 'Edit', title: 'Change the address or the description', onmousedown: keep, onclick: function () { rememberRange(); editPicture(img, edit); } });
    var remove = el('button', { type: 'button', class: 'ed-text is-danger', text: 'Remove picture', onmousedown: keep, onclick: function () { removePicture(img); } });
    // Beside the text: the picture floats to one side and the text goes around it (Markdown: ![…](…){align=left}).
    var sides = [['', 'In the text', 'The picture stays in the line of text'], ['left', 'Left', 'The picture sits on the left, the text goes around it'], ['right', 'Right', 'The picture sits on the right, the text goes around it']].map(function (choice) {
      var button = el('button', { type: 'button', class: 'ed-text', text: choice[1], title: choice[2], 'aria-pressed': floatOf(img) === choice[0] ? 'true' : 'false', 'data-side': choice[0], onmousedown: keep, onclick: function () {
        if (choice[0] === '') { img.removeAttribute('align'); } else { img.setAttribute('align', choice[0]); }
        img.removeAttribute('style');
        img.classList.remove('alignleft', 'alignright');
        Array.prototype.forEach.call(pictureBar.querySelectorAll('[data-side]'), function (other) { other.setAttribute('aria-pressed', other === button ? 'true' : 'false'); });
        changed();
      } });
      return button;
    });
    pictureBar = el('div', { class: 'ed-picturebar', role: 'toolbar', 'aria-label': 'Picture' }, sides.concat([edit, remove]));
    box.appendChild(pictureBar);
    var at = img.getBoundingClientRect(), host = box.getBoundingClientRect();
    pictureBar.style.top = Math.max(0, at.top - host.top + 6) + 'px';
    pictureBar.style.left = Math.max(0, Math.min(at.left - host.left + 6, host.width - pictureBar.offsetWidth - 8)) + 'px';
  }
  function removePicture(img) {
    var parent = img.parentNode;
    var link = parent && parent.tagName === 'A' && parent.childNodes.length === 1 ? parent : null;
    var holder = (link || img).parentNode;
    (link || img).remove();
    unpick();
    var next = holder;
    // A paragraph that held only the picture goes with it.
    if (holder !== surface && holder.tagName === 'P' && holder.textContent.trim() === '' && !holder.querySelector('img')) {
      next = holder.nextElementSibling || holder.previousElementSibling;
      holder.remove();
    }
    if (!surface.firstElementChild) { surface.appendChild(el('p', { html: '<br>' })); next = surface.firstElementChild; }
    if (next) { placeCaret(next, false); }
    surface.focus();
    changed();
  }
  function editPicture(img, anchor) {
    ask(anchor, 'Edit picture', [
      { name: 'url', label: 'Address', value: img.getAttribute('src') || '' },
      { name: 'alt', label: 'Description', value: img.getAttribute('alt') || '', placeholder: 'What the picture shows' }
    ], function (v) {
      var url = safeUrl(v.url);
      if (url === '') { return; }
      img.setAttribute('src', url);
      img.setAttribute('alt', v.alt);
      changed();
    }, {
      ok: 'Apply',
      removeLabel: 'Remove picture',
      remove: function () { removePicture(img); },
      library: function (inputs) {
        window.FarosMediaPicker.open(function (url, item) {
          inputs.url.value = url;
          if (item && item.alt && !inputs.alt.value) { inputs.alt.value = item.alt; }
          inputs.alt.focus();
        });
      }
    });
  }

  function rawDialog(anchor, kind) {
    if (kind === 'iframe') {
      ask(anchor, 'Embed a page or video', [{ name: 'url', label: 'Address', placeholder: 'https://…' }], function (v) {
        var url = safeUrl(v.url);
        if (url === '') { return; }
        insertBlock(rawBlock('<iframe src="' + url.replace(/"/g, '&quot;') + '" width="100%" height="800" frameborder="0"></iframe>'));
      }, { ok: 'Embed' });
    } else {
      ask(anchor, 'Add a button', [{ name: 'text', label: 'Text', value: 'Button' }, { name: 'url', label: 'Address', placeholder: 'https://… or /page' }], function (v) {
        insertBlock(rawBlock('<a class="btn" href="' + (safeUrl(v.url) || '#').replace(/"/g, '&quot;') + '">' + escapeHtml(v.text || 'Button') + '</a>'));
      }, { ok: 'Add' });
    }
  }
  function rawBlock(literal) {
    var shown = literal.length > 160 ? literal.slice(0, 160) + '…' : literal;
    return el('div', { class: 'md-raw', contenteditable: 'false', 'data-raw': literal }, [el('span', { class: 'md-raw-tag', text: 'HTML' }), el('code', { text: shown })]);
  }
  function escapeHtml(text) { return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /* ---------------------------------------------------------------- tables */

  function insertTable() {
    var table = document.createElement('table');
    var head = '<thead><tr><th><br></th><th><br></th><th><br></th></tr></thead>';
    var body = '<tbody>' + new Array(3).join('<tr><td><br></td><td><br></td><td><br></td></tr>') + '</tbody>';
    table.innerHTML = head + body;
    insertBlock(table);
    placeCaret(table.querySelector('th'), false);
  }
  function tableAction(action) {
    restoreRange();
    var range = currentRange();
    var cell = range ? closest(range.startContainer, 'td,th') : null;
    if (!cell) { return; }
    var table = closest(cell, 'table'), row = cell.parentNode, index = cell.cellIndex;
    var blank = function (tag) { var c = document.createElement(tag); c.innerHTML = '<br>'; return c; };
    if (action === 'row') {
      var fresh = document.createElement('tr');
      Array.prototype.forEach.call(row.children, function () { fresh.appendChild(blank('td')); });
      var section = row.parentNode.tagName === 'THEAD' ? (table.tBodies[0] || table.appendChild(document.createElement('tbody'))) : row.parentNode;
      if (row.parentNode.tagName === 'THEAD') { section.insertBefore(fresh, section.firstChild); } else { section.insertBefore(fresh, row.nextSibling); }
    } else if (action === 'col') {
      Array.prototype.forEach.call(table.rows, function (r) { r.insertBefore(blank(r.parentNode.tagName === 'THEAD' ? 'th' : 'td'), r.children[index + 1] || null); });
    } else if (action === 'delrow') {
      if (table.rows.length > 1 && row.parentNode.tagName !== 'THEAD') { row.parentNode.removeChild(row); }
    } else if (action === 'delcol') {
      if (row.children.length > 1) { Array.prototype.forEach.call(table.rows, function (r) { if (r.children[index]) { r.removeChild(r.children[index]); } }); }
    } else if (action === 'deltable') {
      var holder = closest(table, '.table-wrap') || table;
      var next = holder.nextElementSibling || holder.previousElementSibling;
      holder.parentNode.removeChild(holder);
      if (!surface.firstElementChild) { surface.appendChild(el('p', { html: '<br>' })); next = surface.firstElementChild; }
      if (next) { placeCaret(next, false); }
    }
    changed();
  }

  /* ---------------------------------------------------------------- paste */

  var ALLOWED_INLINE = { STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', DEL: 's', S: 's', STRIKE: 's', CODE: 'code' };
  /** HTML from the clipboard reduced to what the Markdown can say. */
  function cleanHtml(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('script,style,meta,link,head,iframe,object,embed,form,input,button,select,textarea,svg').forEach(function (n) { n.remove(); });
    var out = [];
    var walk = function (node, into) {
      if (node.nodeType === 3) { into.push(escapeHtml(node.nodeValue.replace(/[\r\n]+/g, ' '))); return; }
      if (node.nodeType !== 1) { return; }
      var tag = node.tagName, inner = [];
      var children = function () { Array.prototype.forEach.call(node.childNodes, function (c) { walk(c, inner); }); return inner.join(''); };
      var style = node.getAttribute('style') || '';
      if (ALLOWED_INLINE[tag] && !(tag === 'B' && /font-weight:\s*(normal|400)/i.test(style))) { var t = ALLOWED_INLINE[tag]; into.push('<' + t + '>' + children() + '</' + t + '>'); return; }
      switch (tag) {
        case 'H1': case 'H2': into.push('<h2>' + children() + '</h2>'); return;
        case 'H3': into.push('<h3>' + children() + '</h3>'); return;
        case 'H4': case 'H5': case 'H6': into.push('<h4>' + children() + '</h4>'); return;
        case 'P': into.push('<p>' + children() + '</p>'); return;
        case 'BR': into.push('<br>'); return;
        case 'HR': into.push('<hr>'); return;
        case 'U': into.push(canRaw ? '<u>' + children() + '</u>' : children()); return;
        case 'UL': case 'OL': into.push('<' + tag.toLowerCase() + '>' + children() + '</' + tag.toLowerCase() + '>'); return;
        case 'LI': into.push('<li>' + children() + '</li>'); return;
        case 'BLOCKQUOTE': into.push('<blockquote>' + children() + '</blockquote>'); return;
        case 'PRE': into.push('<pre><code>' + escapeHtml(node.textContent.replace(/\n$/, '')) + '</code></pre>'); return;
        case 'A':
          var href = safeUrl(node.getAttribute('href') || '');
          into.push(href && !/^#/.test(href) ? '<a href="' + escapeHtml(href) + '">' + children() + '</a>' : children());
          return;
        case 'IMG':
          var src = safeUrl(node.getAttribute('src') || '');
          if (src && !/^data:/i.test(src)) { var side = floatOf(node); into.push('<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(node.getAttribute('alt') || '') + '"' + (side !== '' ? ' align="' + side + '"' : '') + '>'); }
          return;
        case 'TABLE': case 'THEAD': case 'TBODY': case 'TR': case 'TH': case 'TD':
          into.push('<' + tag.toLowerCase() + '>' + children() + '</' + tag.toLowerCase() + '>');
          return;
        case 'SPAN': case 'FONT': case 'DIV': case 'SECTION': case 'ARTICLE': case 'LABEL': case 'MARK': case 'SUB': case 'SUP': default:
          var text = children();
          if (/font-weight:\s*(bold|[6-9]00)/i.test(style)) { text = '<strong>' + text + '</strong>'; }
          if (/font-style:\s*italic/i.test(style)) { text = '<em>' + text + '</em>'; }
          if (/line-through/i.test(style)) { text = '<s>' + text + '</s>'; }
          if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE') {
            // A box of text is a paragraph, unless it already holds blocks.
            into.push(/<(p|h[1-6]|ul|ol|blockquote|pre|table|hr)\b/.test(text) ? text : (text.trim() === '' ? '' : '<p>' + text + '</p>'));
          } else { into.push(text); }
      }
    };
    walk(doc.body, out);
    return out.join('').replace(/(<p>\s*<\/p>)/g, '');
  }

  function plainToHtml(text) {
    return text.replace(/\r\n?/g, '\n').split(/\n{2,}/).map(function (para) { return '<p>' + escapeHtml(para).replace(/\n/g, '<br>') + '</p>'; }).join('');
  }
  function looksLikeMarkdown(text) {
    return /(^|\n)(#{1,6} |[-*+] |\d+[.)] |> |```)/.test(text) || /\*\*[^*\n]+\*\*|\[[^\]\n]+\]\([^)\n]+\)/.test(text);
  }

  surface && surface.addEventListener('paste', function (event) {
    var data = event.clipboardData;
    if (!data) { return; }
    var html = data.getData('text/html'), text = data.getData('text/plain');
    if (html !== '') {
      event.preventDefault();
      var cleaned = cleanHtml(html);
      if (cleaned.trim() === '') { cleaned = plainToHtml(text); }
      document.execCommand('insertHTML', false, cleaned);
      changed();
      return;
    }
    if (text === '') { return; }
    event.preventDefault();
    if (looksLikeMarkdown(text) && text.length < 200000) {
      draw(text).then(function (json) {
        document.execCommand('insertHTML', false, json.blocks.map(function (b) { return b.html; }).join(''));
        changed();
      }).catch(function () { document.execCommand('insertHTML', false, plainToHtml(text)); changed(); });
    } else {
      document.execCommand('insertHTML', false, plainToHtml(text));
      changed();
    }
  });

  /* ---------------------------------------------------------------- typing shortcuts and keys */

  /** "## " at the start of a line makes a heading, "- " a list, "1. " a numbered one, "> " a quote. */
  function typedRule(event) {
    var range = currentRange();
    if (!range || !range.collapsed) { return false; }
    var block = closest(range.startContainer, 'p,div');
    if (!block || block === surface || block.parentNode !== surface && !closest(block, 'blockquote')) { return false; }
    var before = document.createRange();
    before.setStart(block, 0);
    before.setEnd(range.startContainer, range.startOffset);
    var typed = before.toString();
    var made = null;
    if (/^#{1,4}$/.test(typed)) { made = ['h' + (typed.length === 1 ? 1 : typed.length)]; }
    else if (/^[-*+]$/.test(typed)) { made = ['ul']; }
    else if (/^\d{1,3}[.)]$/.test(typed)) { made = ['ol']; }
    else if (typed === '>') { made = ['blockquote']; }
    if (!made) { return false; }
    event.preventDefault();
    before.deleteContents();
    // An empty block has nothing for the browser to reformat, so it gets a line break to hold the caret.
    if (block.textContent === '' && !block.querySelector('br')) { block.innerHTML = '<br>'; }
    placeCaret(block, false);
    if (made[0] === 'ul') { document.execCommand('insertUnorderedList'); }
    else if (made[0] === 'ol') { document.execCommand('insertOrderedList'); }
    else { document.execCommand('formatBlock', false, made[0]); }
    changed();
    return true;
  }
  function enterRule(event) {
    var range = currentRange();
    if (!range || !range.collapsed) { return false; }
    var block = closest(range.startContainer, 'p,div');
    if (!block || block.parentNode !== surface) { return false; }
    var text = block.textContent.trim();
    if (text === '---' || text === '***') {
      event.preventDefault();
      var rule = document.createElement('hr');
      surface.replaceChild(rule, block);
      if (!rule.nextElementSibling) { surface.appendChild(el('p', { html: '<br>' })); }
      placeCaret(rule.nextElementSibling, false);
      changed();
      return true;
    }
    if (/^```[\w+#.-]*$/.test(text)) {
      event.preventDefault();
      var pre = document.createElement('pre'), code = document.createElement('code');
      var lang = text.slice(3);
      if (lang) { code.className = 'language-' + lang; }
      code.innerHTML = '<br>';
      pre.appendChild(code);
      surface.replaceChild(pre, block);
      placeCaret(code, false);
      changed();
      return true;
    }
    return false;
  }

  surface && surface.addEventListener('keydown', function (event) {
    var mod = event.metaKey || event.ctrlKey;
    if (event.key === ' ' && !mod && !event.altKey && typedRule(event)) { return; }
    if (event.key === 'Escape' && surface.querySelector('.is-picked')) { unpick(); return; }
    if (event.key === 'Enter' && !event.shiftKey && !mod && enterRule(event)) { return; }
    if (event.key === 'Tab' && !mod) {
      var range = currentRange();
      var cell = range ? closest(range.startContainer, 'td,th') : null;
      var item = range ? closest(range.startContainer, 'li') : null;
      if (cell) {
        event.preventDefault();
        var cells = Array.prototype.slice.call(closest(cell, 'table').querySelectorAll('th,td'));
        var at = cells.indexOf(cell) + (event.shiftKey ? -1 : 1);
        if (at >= cells.length) { tableAction('row'); cells = Array.prototype.slice.call(closest(cell, 'table').querySelectorAll('th,td')); }
        if (cells[at]) { placeCaret(cells[at], false); }
      } else if (item) {
        event.preventDefault();
        document.execCommand(event.shiftKey ? 'outdent' : 'indent');
        changed();
      }
      return;
    }
    if (mod && event.key.toLowerCase() === 'k') { event.preventDefault(); linkDialog(tools.querySelector('[data-cmd="link"]') || tools); return; }
    if (mod && event.shiftKey && event.key.toLowerCase() === 's') { event.preventDefault(); command('strikeThrough'); return; }
    if ((event.key === 'Backspace' || event.key === 'Delete')) {
      var pickedPicture = surface.querySelector('img.is-picked');
      if (pickedPicture) { event.preventDefault(); removePicture(pickedPicture); return; }
      var picked = surface.querySelector('.md-raw.is-picked');
      if (picked) { event.preventDefault(); var next = picked.nextElementSibling || picked.previousElementSibling; picked.remove(); if (!surface.firstElementChild) { surface.appendChild(el('p', { html: '<br>' })); next = surface.firstElementChild; } if (next) { placeCaret(next, false); } changed(); }
    }
  });
  surface && surface.addEventListener('click', function (event) {
    unpick();
    var chip = event.target.closest && event.target.closest('.md-raw');
    if (chip) { chip.classList.add('is-picked'); return; }
    if (event.target.tagName === 'IMG') { event.preventDefault(); pickPicture(event.target); }
  });
  surface && surface.addEventListener('input', changed);
  surface && surface.addEventListener('blur', sync);

  /* ---------------------------------------------------------------- the toolbar of the visual editor */

  var ICONS = {
    bold: '<path d="M7 5h6a3.5 3.5 0 010 7H7zM7 12h7a3.5 3.5 0 010 7H7z"/>',
    italic: '<path d="M10 5h8M6 19h8M15 5L9 19"/>',
    strike: '<path d="M5 12h14M16 7.5C15.4 6 13.9 5 12 5c-2.2 0-4 1.2-4 3 0 4 8 2.5 8 7 0 1.9-1.8 3-4 3-2.1 0-3.7-1.1-4.3-2.8"/>',
    underline: '<path d="M7 4v7a5 5 0 0010 0V4M5 20h14"/>',
    code: '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4"/>',
    link: '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
    ul: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
    ol: '<path d="M10 6h10M10 12h10M10 18h10M4 5l1-1v4M4 14c0-1 2-1 2 0s-2 2-2 3h2"/>',
    quote: '<path d="M7 17c-2 0-3-1.3-3-3 0-3 2-5 4-6M16 17c-2 0-3-1.3-3-3 0-3 2-5 4-6"/>',
    codeblock: '<path d="M4 5h16v14H4zM8 10l-2 2 2 2M16 10l2 2-2 2M13 9l-2 6"/>',
    hr: '<path d="M4 12h16"/>',
    image: '<path d="M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M9 9h.01"/>',
    table: '<path d="M4 5h16v14H4zM4 10h16M4 15h16M10 5v14"/>',
    undo: '<path d="M9 14L4 9l5-5M4 9h10a6 6 0 010 12h-3"/>',
    redo: '<path d="M15 14l5-5-5-5M20 9H10a6 6 0 000 12h3"/>',
    clear: '<path d="M6 6h12M10 6l-3 12h8M4 20l16-16"/>',
    alignleft: '<path d="M4 6h16M4 10h10M4 14h16M4 18h10"/>',
    aligncenter: '<path d="M4 6h16M7 10h10M4 14h16M7 18h10"/>',
    alignright: '<path d="M4 6h16M10 10h10M4 14h16M10 18h10"/>',
    alignjustify: '<path d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
    html: '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13 6l-2 12"/>'
  };
  function icon(name) { return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>'; }

  var buttons = {};
  function button(cmd, label, title, handler) {
    var b = el('button', { type: 'button', class: 'ed-btn', title: title || label, 'aria-label': label, 'data-cmd': cmd, 'aria-pressed': 'false', html: icon(cmd) });
    b.addEventListener('mousedown', function (e) { e.preventDefault(); rememberRange(); });
    b.addEventListener('click', function (e) { e.preventDefault(); handler(b); });
    buttons[cmd] = b;
    return b;
  }
  function group() { return el('span', { class: 'ed-group', role: 'group' }, Array.prototype.slice.call(arguments)); }

  var formatSelect = null, tableBar = null;
  function buildTools() {
    formatSelect = el('select', { class: 'ed-format', 'aria-label': 'Text style' }, [
      el('option', { value: 'p', text: 'Paragraph' }),
      el('option', { value: 'h2', text: 'Heading 2' }),
      el('option', { value: 'h3', text: 'Heading 3' }),
      el('option', { value: 'h4', text: 'Heading 4' }),
      el('option', { value: 'h1', text: 'Heading 1', hidden: true }),
      el('option', { value: 'pre', text: 'Code block' })
    ]);
    formatSelect.addEventListener('mousedown', rememberRange);
    formatSelect.addEventListener('change', function () { setBlock(formatSelect.value); });
    var inline = [button('bold', 'Bold', 'Bold (Ctrl+B)', function () { command('bold'); }), button('italic', 'Italic', 'Italic (Ctrl+I)', function () { command('italic'); }), button('strike', 'Strikethrough', 'Strikethrough', function () { command('strikeThrough'); })];
    if (canRaw) { inline.push(button('underline', 'Underline', 'Underline (written as HTML)', function () { command('underline'); })); }
    inline.push(button('code', 'Code', 'Code', function () { wrapInline('code'); }));
    var insert = [button('image', 'Image', 'Image', imageDialog), button('table', 'Table', 'Table', insertTable), button('hr', 'Divider', 'Divider', function () { var h = document.createElement('hr'); insertBlock(h); })];
    if (canRaw) {
      insert.push(button('html', 'Embed', 'Embed a page or video, or a button (written as HTML)', function (b) {
        ask(b, 'Insert', [], function () {}, { ok: 'Close' });
        pop.querySelector('.ed-pop-ok').remove();
        var row = pop.querySelector('.ed-pop-actions');
        row.insertBefore(el('button', { type: 'button', class: 'ed-pop-alt', text: 'Embed', onclick: function () { closePop(); restoreRange(); rawDialog(b, 'iframe'); } }), row.firstChild);
        row.insertBefore(el('button', { type: 'button', class: 'ed-pop-alt', text: 'Button', onclick: function () { closePop(); restoreRange(); rawDialog(b, 'button'); } }), row.children[1]);
      }));
    }
    tableBar = el('span', { class: 'ed-group ed-tablebar', hidden: true, role: 'group', 'aria-label': 'Table' }, [
      el('button', { type: 'button', class: 'ed-text', text: '+ Row', onmousedown: function (e) { e.preventDefault(); rememberRange(); }, onclick: function () { tableAction('row'); } }),
      el('button', { type: 'button', class: 'ed-text', text: '+ Column', onmousedown: function (e) { e.preventDefault(); rememberRange(); }, onclick: function () { tableAction('col'); } }),
      el('button', { type: 'button', class: 'ed-text', text: '− Row', onmousedown: function (e) { e.preventDefault(); rememberRange(); }, onclick: function () { tableAction('delrow'); } }),
      el('button', { type: 'button', class: 'ed-text', text: '− Column', onmousedown: function (e) { e.preventDefault(); rememberRange(); }, onclick: function () { tableAction('delcol'); } }),
      el('button', { type: 'button', class: 'ed-text is-danger', text: 'Delete table', onmousedown: function (e) { e.preventDefault(); rememberRange(); }, onclick: function () { tableAction('deltable'); } })
    ]);
    [
      group(formatSelect),
      group.apply(null, inline),
      group(button('link', 'Link', 'Link (Ctrl+K)', linkDialog)),
      group(button('ul', 'Bulleted list', 'Bulleted list', function () { command('insertUnorderedList'); }), button('ol', 'Numbered list', 'Numbered list', function () { command('insertOrderedList'); }), button('quote', 'Quote', 'Quote', function () { setBlock('blockquote'); }), button('codeblock', 'Code block', 'Code block', function () { setBlock('pre'); })),
      group(button('alignleft', 'Align left', 'Align left', function () { setAlign('left'); }), button('aligncenter', 'Align center', 'Align center', function () { setAlign('center'); }), button('alignright', 'Align right', 'Align right', function () { setAlign('right'); }), button('alignjustify', 'Justify', 'Justify', function () { setAlign('justify'); })),
      group.apply(null, insert),
      group(button('undo', 'Undo', 'Undo', function () { command('undo'); }), button('redo', 'Redo', 'Redo', function () { command('redo'); }), button('clear', 'Clear formatting', 'Clear formatting', function () { command('removeFormat'); })),
      tableBar
    ].forEach(function (part) { tools.appendChild(part); });
  }

  /** Shows which buttons apply to where the caret is. */
  function updateState() {
    if (state.mode !== 'visual') { return; }
    var range = currentRange();
    var pressed = function (name, on) { if (buttons[name]) { buttons[name].setAttribute('aria-pressed', on ? 'true' : 'false'); } };
    var check = function (cmd) { try { return document.queryCommandState(cmd); } catch (e) { return false; } };
    pressed('bold', check('bold'));
    pressed('italic', check('italic'));
    pressed('strike', check('strikeThrough'));
    pressed('underline', check('underline'));
    pressed('ul', check('insertUnorderedList'));
    pressed('ol', check('insertOrderedList'));
    pressed('code', !!(range && closest(range.commonAncestorContainer, 'code') && !closest(range.commonAncestorContainer, 'pre')));
    pressed('link', !!(range && closest(range.commonAncestorContainer, 'a')));
    pressed('quote', !!(range && closest(range.commonAncestorContainer, 'blockquote')));
    pressed('codeblock', !!(range && closest(range.commonAncestorContainer, 'pre')));
    var aligned = range ? closest(range.startContainer, 'p,h1,h2,h3,h4,h5,h6') : null;
    var how = aligned && !closest(aligned, 'li,td,th') ? alignOf(aligned) : '';
    pressed('alignleft', !!aligned && how === '');
    pressed('aligncenter', how === 'center');
    pressed('alignright', how === 'right');
    pressed('alignjustify', how === 'justify');
    if (range && formatSelect) {
      var h = closest(range.startContainer, 'h1,h2,h3,h4,h5,h6,pre');
      var value = h ? h.tagName.toLowerCase() : 'p';
      if (!formatSelect.querySelector('option[value="' + value + '"]')) { value = 'p'; }
      var odd = formatSelect.querySelector('option[value="h1"]');
      odd.hidden = value !== 'h1';
      formatSelect.value = value;
    }
    if (tableBar) { tableBar.hidden = !(range && closest(range.startContainer, 'td,th')); }
  }
  document.addEventListener('selectionchange', function () { if (state.mode === 'visual' && currentRange()) { updateState(); } });

  /* ---------------------------------------------------------------- the toolbar of the Markdown mode */

  function wrapSelection(before, after) {
    var start = area.selectionStart || 0, end = area.selectionEnd || 0, value = area.value;
    var selected = value.slice(start, end);
    area.value = value.slice(0, start) + before + selected + (after || '') + value.slice(end);
    area.focus();
    area.selectionStart = start + before.length;
    area.selectionEnd = start + before.length + selected.length;
    area.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function prefixLines(prefix, numbered) {
    var start = area.selectionStart || 0, end = area.selectionEnd || 0, value = area.value;
    var from = value.lastIndexOf('\n', start - 1) + 1;
    var lines = value.slice(from, end).split('\n');
    var changedText = lines.map(function (line, i) { return (numbered ? (i + 1) + '. ' : prefix) + line; }).join('\n');
    area.value = value.slice(0, from) + changedText + value.slice(end);
    area.focus();
    area.selectionStart = from;
    area.selectionEnd = from + changedText.length;
    area.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (mdTools && area) {
    mdTools.addEventListener('click', function (event) {
      var target = event.target.closest('[data-action]');
      if (!target || target.tagName === 'SELECT') { return; }
      var picked = function () { return area.value.slice(area.selectionStart || 0, area.selectionEnd || 0); };
      switch (target.getAttribute('data-action')) {
        case 'h2': prefixLines('## '); break;
        case 'h3': prefixLines('### '); break;
        case 'bold': wrapSelection('**', '**'); break;
        case 'italic': wrapSelection('*', '*'); break;
        case 'underline': wrapSelection('<u>', '</u>'); break;
        case 'strike': wrapSelection('~~', '~~'); break;
        case 'ul': prefixLines('- '); break;
        case 'ol': prefixLines('', true); break;
        case 'quote': prefixLines('> '); break;
        case 'code': wrapSelection('`', '`'); break;
        case 'link': {
          var url = window.prompt('Address of the link');
          if (url) {
            var text = picked() || 'link text';
            var blank = window.confirm('Open this link in a new tab?') ? '{target=_blank}' : '';
            wrapSelection('[' + text + '](' + url + ')' + blank, '');
          }
          break;
        }
        case 'image': {
          var put = function (src, alt) { wrapSelection('![' + (alt || 'image') + '](' + src + ')', ''); };
          if (window.FarosMediaPicker) { window.FarosMediaPicker.open(function (src, item) { put(src, item && item.alt); }); }
          else { var u = window.prompt('Address of the image'); if (u) { put(u, window.prompt('Description of the image') || ''); } }
          break;
        }
      }
    });
    var snippet = mdTools.querySelector('[data-action="snippet"]');
    if (snippet) {
      snippet.addEventListener('change', function () {
        if (snippet.value === 'iframe') { wrapSelection('<iframe src="PASTE_URL_HERE" width="100%" height="800" frameborder="0"></iframe>', ''); }
        if (snippet.value === 'button') { wrapSelection('<a class="btn" href="#">Button</a>', ''); }
        snippet.value = '';
      });
    }
    area.addEventListener('input', count);
  }

  /* ---------------------------------------------------------------- the two modes */

  var modeButtons = (box.closest('section') || document).querySelectorAll('[data-ed-mode]');
  function show(mode) {
    box.setAttribute('data-mode', mode);
    state.mode = mode;
    Array.prototype.forEach.call(modeButtons, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-ed-mode') === mode ? 'true' : 'false'); });
    var visual = mode === 'visual';
    surface.hidden = !visual;
    tools.hidden = !visual;
    area.hidden = visual;
    if (mdTools) { mdTools.hidden = visual; }
    closePop();
    unpick();
    count();
  }

  function remember(mode) { try { localStorage.setItem('faros-editor-mode', mode); } catch (e) { /* not kept, nothing lost */ } }

  function toVisual(first) {
    say('');
    if (state.loaded && !state.dirty && state.saved === area.value) { show('visual'); return Promise.resolve(); }
    box.classList.add('is-loading');
    return load(area.value).then(function () {
      state.saved = area.value;
      box.classList.remove('is-loading');
      show('visual');
      if (!first) { surface.focus(); }
    }).catch(function () {
      box.classList.remove('is-loading');
      show('markdown');
      say('The visual editor could not start, so the Markdown is shown. Nothing is lost.');
    });
  }
  function toMarkdown() {
    sync();
    if (state.dirty) { state.saved = area.value; state.loaded = true; state.dirty = false; }
    show('markdown');
    area.focus();
  }

  Array.prototype.forEach.call(modeButtons, function (b) {
    b.addEventListener('click', function () {
      var mode = b.getAttribute('data-ed-mode');
      if (mode === state.mode) { return; }
      remember(mode);
      if (mode === 'visual') { toVisual(false); } else { toMarkdown(); }
    });
  });
  if (form) { form.addEventListener('submit', sync, true); }
  window.addEventListener('pagehide', sync);

  function start() {
    count();
    if (!supported) { show('markdown'); return; }
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); document.execCommand('styleWithCSS', false, false); } catch (e) { /* the defaults do */ }
    buildTools();
    Array.prototype.forEach.call(modeButtons, function (b) { b.hidden = false; });
    var preferred = 'visual';
    try { preferred = localStorage.getItem('faros-editor-mode') === 'markdown' ? 'markdown' : 'visual'; } catch (e) { /* the default */ }
    if (preferred === 'visual') { toVisual(true); } else { show('markdown'); }
  }

  // Keep the old global available to existing integrations.
  window.GnozioEditor = window.FarosEditor = {
    /** For tests: the Markdown the visual side writes (every block written again, unless full is false). */
    markdownOf: function (full) { return serialize(full !== false); },
    load: load,
    state: state
  };
  start();
})();
