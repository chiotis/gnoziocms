/*
 * The menu editor (admin/templates/menus.twig). The menu is a flat list of items, each with a depth from 1 to 3; the
 * cards on the screen are drawn from it and every change (a drag, a button, a keystroke) goes through the list, so undo is
 * a stack of copies of it. Saving puts the list in one hidden field (menu_json) which the server turns back into a tree.
 *
 *   - the panel on the left lists what the site has (pages, entries, lists, categories); an entry is added with its
 *     button, ticked and added with others, or dragged into the menu. Its title in each language fills the labels.
 *   - an item moves by dragging its handle (sideways sets how deep it is), by the arrow keys on the handle, or by the buttons
 *   - an item opens to edit its labels in every language, its address, whether it opens in a new tab, looks like a button,
 *     or is hidden
 *   - a language switch shows the labels in that language and counts the items missing one; those can be filled from the
 *     titles of the pages they link to
 *   - undo and redo, and a warning before leaving with unsaved changes
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-menu-editor]');
  var dataNode = document.getElementById('menu-editor-data');
  if (!root || !dataNode) return;

  var data = JSON.parse(dataNode.textContent);
  var form = root.querySelector('[data-menu-form]');
  var listEl = root.querySelector('[data-menu-list]');
  var wrapEl = root.querySelector('[data-menu-list-wrap]');
  var dropLine = root.querySelector('[data-drop-line]');
  var emptyEl = root.querySelector('[data-menu-empty]');
  var noteEl = root.querySelector('[data-menu-note]');
  var liveEl = root.querySelector('[data-menu-live]');
  var jsonField = root.querySelector('[data-menu-json]');
  var titleField = root.querySelector('[data-menu-title]');
  var countEl = root.querySelector('[data-menu-count]');
  var langSwitch = root.querySelector('[data-lang-switch]');
  var langBanner = root.querySelector('[data-lang-banner]');
  var previewEl = root.querySelector('[data-menu-preview]');
  var placesEl = root.querySelector('[data-menu-locations]');
  var undoBtn = root.querySelector('[data-undo]');
  var redoBtn = root.querySelector('[data-redo]');
  var toggleAllBtn = root.querySelector('[data-toggle-all]');
  var sourceGroupsEl = root.querySelector('[data-source-groups]');
  var sourceSearch = root.querySelector('[data-source-search]');
  var selectedBar = root.querySelector('[data-source-selected]');
  var selectedCount = root.querySelector('[data-source-selected-count]');
  var dirtyNote = document.querySelector('[data-dirty-note]');

  var INDENT = 28;
  var MAX_DEPTH = 3;
  var languages = (data.languages || []).map(function (l) { return l.code; });
  var languageNames = {};
  (data.languages || []).forEach(function (l) { languageNames[l.code] = l.name; });
  var defaultLang = data.default_language || languages[0] || 'en';
  var multilingual = languages.length > 1;
  var maxItems = data.max_items || 300;
  var menuSlug = String(data.key || 'menu').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'menu';
  var relValues = (data.rel_values || []).filter(function (v) { return v !== 'noopener'; });
  var relLabels = { nofollow: 'Not followed by search engines', sponsored: 'Paid or sponsored link', ugc: 'Made by users', noreferrer: 'Do not send the page address' };

  var nextId = 1;
  var items = [];
  var state = { viewLang: defaultLang, onlyMissing: false, allOpen: false, query: '', selected: {}, openGroups: {}, limits: {} };
  var undoStack = [];
  var redoStack = [];
  var lastKey = '';
  var lastAt = 0;
  var dirty = false;
  var submitting = false;
  var initialState = '';
  var noteTimer = 0;

  /* ---------- small helpers ---------- */

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === false || v === null || v === undefined) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : String(v));
      });
    }
    (kids || []).forEach(function (kid) {
      if (kid === null || kid === undefined || kid === false) return;
      el.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
    });
    return el;
  }

  var ICONS = {
    grip: '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
    up: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>',
    down: '<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>',
    indent: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M12 12h9M12 18h9M3 10l4 3-4 3"/>',
    outdent: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M12 12h9M12 18h9M7 10l-4 3 4 3"/>',
    trash: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m2 0v14a1 1 0 01-1 1H7a1 1 0 01-1-1V6"/>',
    chevron: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>',
    plus: '<path stroke-linecap="round" d="M12 5v14M5 12h14"/>',
    check: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" d="M5 15V6a2 2 0 012-2h9"/>',
    external: '<path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M8 7h9v9"/>',
    eyeOff: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A9.8 9.8 0 0112 5c5 0 9 4 10 7a11 11 0 01-3.2 4.4M6.1 6.1A11 11 0 002 12c1 3 5 7 10 7a9.7 9.7 0 004-.9"/>',
    warn: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>'
  };
  function icon(name, size) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', name === 'grip' ? 'currentColor' : 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', size || 'h-4 w-4');
    svg.innerHTML = ICONS[name] || '';
    return svg;
  }

  function announce(text) {
    if (!liveEl) return;
    liveEl.textContent = '';
    window.setTimeout(function () { liveEl.textContent = text; }, 30);
  }

  function showNote(text, undoable) {
    window.clearTimeout(noteTimer);
    noteEl.textContent = text + ' ';
    if (undoable) {
      noteEl.appendChild(h('button', { type: 'button', 'class': 'font-medium text-blue-700 underline hover:text-blue-900', text: 'Undo', onclick: function () { undo(); hideNote(); } }));
    }
    noteEl.classList.remove('hidden');
    noteTimer = window.setTimeout(hideNote, 8000);
  }
  function hideNote() { noteEl.classList.add('hidden'); noteEl.textContent = ''; }

  /* ---------- the items ---------- */

  function fillLangs(source) {
    var out = {};
    languages.forEach(function (code) { out[code] = (source && source[code]) || ''; });
    return out;
  }

  function makeItem(o) {
    return {
      id: nextId++,
      depth: Math.max(1, Math.min(MAX_DEPTH, parseInt(o.depth, 10) || 1)),
      label_key: o.label_key || '',
      labels: fillLangs(o.labels),
      hints: fillLangs(o.hints),
      url: o.url || '',
      css: o.css !== undefined ? o.css : (o['class'] || ''),
      target: o.target || '',
      hidden: !!o.hidden,
      languages: (o.languages || []).slice(),
      icon: o.icon || '',
      badges: fillLangs(o.badges),
      rel: (o.rel || []).slice(),
      open: !!o.open
    };
  }

  function plain(item) {
    return {
      depth: item.depth, label_key: item.label_key, labels: item.labels, url: item.url, 'class': item.css, target: item.target, hidden: item.hidden,
      languages: item.languages, icon: item.icon, badges: item.badges, rel: item.rel
    };
  }
  function serialize() { return JSON.stringify(items.map(plain)); }

  function blockEnd(i) {
    var d = items[i].depth, j = i + 1;
    while (j < items.length && items[j].depth > d) j++;
    return j;
  }
  function spanBelow(i) {
    var d = items[i].depth, max = d, end = blockEnd(i);
    for (var j = i + 1; j < end; j++) max = Math.max(max, items[j].depth);
    return max - d;
  }
  function indexOfId(id) {
    for (var i = 0; i < items.length; i++) if (items[i].id === id) return i;
    return -1;
  }
  function prevSibling(i) {
    var d = items[i].depth, j = i - 1;
    while (j >= 0 && items[j].depth > d) j--;
    return j >= 0 && items[j].depth === d ? j : -1;
  }
  function nextSibling(i) {
    var e = blockEnd(i);
    return e < items.length && items[e].depth === items[i].depth ? e : -1;
  }

  /** Moves the block that starts at i to sit before the item that is at `before` (items.length for the end) with a new depth. */
  function relocate(i, before, depth) {
    var end = blockEnd(i);
    if (before > i && before < end) return;
    var block = items.splice(i, end - i);
    var delta = depth - block[0].depth;
    block.forEach(function (b) { b.depth += delta; });
    if (before > i) before -= block.length;
    items.splice.apply(items, [before, 0].concat(block));
  }

  /* ---------- undo and redo ---------- */

  function snapshot() {
    return JSON.stringify(items.map(function (it) {
      return { id: it.id, depth: it.depth, label_key: it.label_key, labels: it.labels, hints: it.hints, url: it.url, css: it.css, target: it.target, hidden: it.hidden, languages: it.languages, icon: it.icon, badges: it.badges, rel: it.rel, open: it.open };
    }));
  }
  function restore(text) {
    items = JSON.parse(text).map(function (o) { o.depth = o.depth || 1; var it = makeItem(o); it.id = o.id; return it; });
  }
  function record(key) {
    var now = Date.now();
    if (key && key === lastKey && now - lastAt < 1500) { lastAt = now; return; }
    undoStack.push(snapshot());
    if (undoStack.length > 120) undoStack.shift();
    redoStack = [];
    lastKey = key || '';
    lastAt = now;
  }
  function undo() {
    if (!undoStack.length) return;
    redoStack.push(snapshot());
    restore(undoStack.pop());
    lastKey = '';
    changed(true);
    announce('Undone');
  }
  function redo() {
    if (!redoStack.length) return;
    undoStack.push(snapshot());
    restore(redoStack.pop());
    lastKey = '';
    changed(true);
    announce('Redone');
  }

  /** After the list changed: refresh what depends on it, and the unsaved mark. */
  function changed(redraw, focus) {
    if (redraw) renderList(focus);
    refreshMeta();
    refreshDirty();
  }

  function refreshDirty() {
    dirty = serialize() !== initialState.items || (titleField && titleField.value !== initialState.title) || placesState() !== initialState.places;
    if (dirtyNote) {
      dirtyNote.classList.toggle('hidden', !dirty);
      dirtyNote.classList.toggle('inline-flex', dirty);
    }
    undoBtn.disabled = !undoStack.length;
    redoBtn.disabled = !redoStack.length;
  }
  function placesState() {
    return Array.prototype.map.call(placesEl.querySelectorAll('input[type=checkbox]:checked'), function (c) { return c.value; }).join(',');
  }

  /* ---------- what is known about the addresses ---------- */

  var known = {};
  var urlOptions = [];
  function norm(url) {
    var u = String(url || '').trim().replace(/[?#].*$/, '').replace(/^\/+|\/+$/g, '');
    var parts = u === '' ? [] : u.split('/');
    if (parts.length && languages.indexOf(parts[0]) !== -1 && parts[0] !== '') parts.shift();
    if (parts[0] === 'pages') parts.shift();
    return parts.join('/');
  }
  (data.groups || []).forEach(function (group) {
    group.items.forEach(function (entry) {
      var key = norm(entry.url);
      if (!known[key]) known[key] = { entry: entry, group: group.label };
      if (urlOptions.length < 900) urlOptions.push({ value: entry.url, title: entry.title });
    });
  });
  var datalist = h('datalist', { id: 'menu-url-options' }, urlOptions.map(function (o) { return h('option', { value: o.value, label: o.title }); }));
  root.appendChild(datalist);

  function urlInfo(url) {
    var u = String(url || '').trim();
    if (u === '' || u === '/') return { kind: 'home', entry: known[''] ? known[''].entry : null, group: 'Home page' };
    if (/^(https?:)?\/\//i.test(u)) return { kind: 'external' };
    if (/^(mailto:|tel:|#)/i.test(u)) return { kind: 'special' };
    var hit = known[norm(u)];
    return { kind: 'internal', entry: hit ? hit.entry : null, group: hit ? hit.group : '', known: !!hit };
  }

  /* ---------- labels and languages ---------- */

  function textFor(item, lang) { return item.labels[lang] || item.hints[lang] || ''; }
  function shown(item, lang) {
    var own = textFor(item, lang);
    if (own) return { text: own, fallback: false };
    var base = textFor(item, defaultLang);
    return { text: base, fallback: true };
  }
  function missing(item, lang) { return !textFor(item, lang); }
  function titleOf(item) {
    var s = shown(item, state.viewLang).text;
    if (s) return s;
    var info = urlInfo(item.url);
    return info.entry ? info.entry.title : (item.url || 'Untitled item');
  }
  function missingCount(lang) {
    return items.filter(function (it) { return !it.hidden && inLanguage(it, lang) && missing(it, lang); }).length;
  }

  /** An item with no language list shows in every language; one limited to some shows only in them. */
  function inLanguage(item, lang) {
    return !item.languages.length || item.languages.indexOf(lang) !== -1;
  }

  /**
   * The theme text key an item is named with, in the nav.<menu>.<item> form: the menu's key, then the item's own name,
   * which is the last part of its address ("home" for the home page). An item under another one takes the key of the
   * item above it, as the defaults do (nav.main.services.workplace_strategy). A key another item of this menu has takes
   * a number. A name is made of Latin letters and figures only, so a Greek label is never the source.
   */
  function suggestKey(item) {
    var index = items.indexOf(item);
    var prefix = 'nav.' + menuSlug + '.';
    for (var up = index - 1; up >= 0 && item.depth > 1; up--) {
      if (items[up].depth < item.depth) {
        if (items[up].label_key) prefix = items[up].label_key + '.';
        break;
      }
    }
    var address = norm(item.url);
    var name = address === '' ? 'home' : address.split('/').pop();
    name = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'item';
    var taken = {};
    items.forEach(function (it) { if (it !== item && it.label_key) taken[it.label_key] = true; });
    var key = prefix + name;
    for (var n = 2; taken[key]; n++) key = prefix + name + '_' + n;
    return key;
  }
  function langName(code) { return languageNames[code] || code.toUpperCase(); }

  /* ---------- drawing the list ---------- */

  function badge(text, tone, iconName, title) {
    var tones = {
      amber: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
      slate: 'bg-slate-100 text-slate-600',
      blue: 'bg-blue-50 text-blue-700 ring-1 ring-blue-200'
    };
    return h('span', { 'class': 'inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ' + (tones[tone] || tones.slate), title: title || false }, [iconName ? icon(iconName, 'h-3 w-3') : null, text]);
  }

  function iconButton(name, label, onclick, disabled) {
    return h('button', {
      type: 'button',
      'class': 'inline-flex h-8 w-8 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent',
      'aria-label': label,
      title: label,
      disabled: disabled,
      onclick: onclick
    }, [icon(name)]);
  }

  function buildHeader(item, index) {
    var info = urlInfo(item.url);
    var s = shown(item, state.viewLang);
    var title = titleOf(item);
    var subline = '';
    if (info.kind === 'home') subline = 'Home page';
    else if (info.kind === 'internal') subline = (info.group ? info.group + ' · ' : '') + '/' + norm(item.url);
    else subline = item.url;

    var badges = [];
    if (item.hidden) badges.push(badge('Hidden', 'slate', 'eyeOff'));
    if (info.kind === 'internal' && !info.known) badges.push(badge('Not found', 'amber', 'warn', 'No page or entry has this address'));
    if (info.entry && info.entry.draft) badges.push(badge(info.entry.detail || 'Draft', 'amber', 'warn', 'The page it links to is not published'));
    if (info.kind === 'external') badges.push(badge('External', 'slate', 'external'));
    if (item.target === '_blank') badges.push(badge('New tab', 'slate'));
    if (/(^|\s)nav-cta(\s|$)/.test(item.css)) badges.push(badge('Button', 'blue'));
    if (item.languages.length) {
      badges.push(badge(item.languages.map(function (code) { return code.toUpperCase(); }).join(' · '), 'blue', null, 'Shown only in ' + item.languages.map(langName).join(', ')));
    }
    if (multilingual) {
      languages.forEach(function (code) {
        if (inLanguage(item, code) && missing(item, code)) badges.push(badge(code.toUpperCase(), 'amber', null, 'No label in ' + langName(code)));
      });
    }

    var tools = h('div', { 'class': 'menu-card-tools hidden shrink-0 items-center sm:flex' }, [
      iconButton('up', 'Move up', function () { act(item.id, 'up'); }, prevSibling(index) === -1),
      iconButton('down', 'Move down', function () { act(item.id, 'down'); }, nextSibling(index) === -1),
      iconButton('outdent', 'Move out a level', function () { act(item.id, 'outdent'); }, item.depth === 1),
      iconButton('indent', 'Nest under the item above', function () { act(item.id, 'indent'); }, !canIndent(index)),
      iconButton('trash', 'Remove', function () { act(item.id, 'remove'); }, false)
    ]);

    var handle = h('button', {
      type: 'button',
      'class': 'menu-handle inline-flex h-9 w-7 shrink-0 cursor-grab touch-none select-none items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing',
      'aria-label': 'Move ' + title + ', level ' + item.depth,
      'aria-describedby': 'menu-drag-help',
      onpointerdown: function (e) { beginDrag(e, item); },
      onkeydown: function (e) { handleKey(e, item); }
    }, [icon('grip', 'h-4 w-4')]);

    var toggle = h('button', {
      type: 'button',
      'class': 'flex min-w-0 flex-1 items-center gap-2 rounded px-1 py-1 text-left hover:bg-slate-50',
      'aria-expanded': item.open ? 'true' : 'false',
      'aria-controls': 'menu-body-' + item.id,
      onclick: function () { item.open = !item.open; renderCard(item, { toggle: true }); }
    }, [
      h('span', { 'class': 'min-w-0 flex-1' }, [
        h('span', { 'class': 'block truncate text-sm font-medium ' + (s.text && !s.fallback ? 'text-slate-900' : 'text-slate-500 italic') }, [title]),
        h('span', { 'class': 'block truncate text-xs text-slate-500' }, [subline || ' '])
      ]),
      h('span', { 'class': 'flex shrink-0 flex-wrap items-center justify-end gap-1' }, badges),
      h('span', { 'class': 'shrink-0 text-slate-400 transition-transform' + (item.open ? ' rotate-90' : '') }, [icon('chevron')])
    ]);

    return h('div', { 'class': 'menu-card-head flex items-center gap-1.5 p-1.5 sm:gap-2' }, [handle, toggle, tools]);
  }

  /** A move button for the open card: small screens have no row of tools in the heading. */
  function positionButton(name, label, item) {
    var i = indexOfId(item.id);
    var off = name === 'up' ? prevSibling(i) === -1 : name === 'down' ? nextSibling(i) === -1 : name === 'outdent' ? item.depth === 1 : !canIndent(i);
    return h('button', {
      type: 'button',
      'class': 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40',
      'aria-label': label,
      title: label,
      disabled: off,
      onclick: function () { act(item.id, name); }
    }, [icon(name)]);
  }

  function canIndent(i) {
    return i > 0 && items[i - 1].depth >= items[i].depth && items[i].depth + spanBelow(i) < MAX_DEPTH;
  }

  function field(labelText, input, hint) {
    return h('label', { 'class': 'grid gap-1 text-xs font-medium text-slate-600' }, [labelText, input, hint ? h('span', { 'class': 'font-normal text-slate-500' }, [hint]) : null]);
  }
  var inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-300';

  function check(labelText, checked, onchange, hint) {
    var box = h('input', { type: 'checkbox', 'class': 'mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400', checked: checked ? true : false });
    box.addEventListener('change', function () { onchange(box.checked); });
    return h('label', { 'class': 'flex items-start gap-2 text-sm text-slate-700' }, [box, h('span', {}, [labelText, hint ? h('span', { 'class': 'block text-xs text-slate-500' }, [hint]) : null])]);
  }

  /**
   * The languages an item is shown in, one tick each. Every language is ticked for an item with no limit, and saving with
   * all of them ticked stores no limit. At least one stays ticked.
   */
  function languageChoice(item) {
    var group = h('div', { 'class': 'grid gap-2 border-t border-slate-100 pt-2.5', role: 'group', 'aria-label': 'Show in these languages' }, [
      h('span', { 'class': 'text-sm text-slate-700' }, ['Show in'])
    ]);
    languages.forEach(function (code) {
      var box = h('input', { type: 'checkbox', 'class': 'h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400', checked: inLanguage(item, code) ? true : false });
      box.addEventListener('change', function () {
        var now = languages.filter(function (c) { return c === code ? box.checked : inLanguage(item, c); });
        if (now.length === 0) {
          box.checked = true;
          announce('An item shows in at least one language.');
          return;
        }
        edit(item, 'languages:' + item.id, function () { item.languages = now.length === languages.length ? [] : now; });
        renderPreview();
      });
      group.appendChild(h('label', { 'class': 'flex items-center gap-2 text-sm text-slate-700' }, [box, langName(code)]));
    });
    return group;
  }

  function placeholderFor(item, lang) {
    if (item.hints[lang]) return item.hints[lang] + ' (from the theme)';
    if (lang !== defaultLang) return textFor(item, defaultLang) || 'Label';
    return 'Label';
  }

  function buildBody(item) {
    var labelInputs = languages.map(function (code) {
      var input = h('input', { type: 'text', 'class': inputClass, value: item.labels[code], placeholder: placeholderFor(item, code), 'data-lang': code });
      input.addEventListener('input', function () {
        edit(item, 'label:' + item.id + ':' + code, function () { item.labels[code] = input.value.trim() === '' ? '' : input.value; });
        if (code === defaultLang) refreshPlaceholders(item);
      });
      var caption = langName(code) + (code === defaultLang && multilingual ? ' (default)' : '');
      return field(multilingual ? 'Label · ' + caption : 'Label', input);
    });

    var urlInput = h('input', { type: 'text', 'class': inputClass, value: item.url, placeholder: 'about, posts/hello, https://…', list: 'menu-url-options', 'data-url': '1' });
    urlInput.addEventListener('input', function () { edit(item, 'url:' + item.id, function () { item.url = urlInput.value.trim(); }); });

    var cssInput = h('input', { type: 'text', 'class': inputClass, value: item.css, placeholder: 'nav-cta', 'data-css': '1' });
    var buttonBox = check('Show as a button', /(^|\s)nav-cta(\s|$)/.test(item.css), function (on) {
      edit(item, '', function () {
        var parts = item.css.split(/\s+/).filter(function (p) { return p && p !== 'nav-cta'; });
        if (on) parts.push('nav-cta');
        item.css = parts.join(' ');
      });
      cssInput.value = item.css;
    }, 'Uses the theme\'s call to action style (class nav-cta).');
    cssInput.addEventListener('input', function () {
      edit(item, 'css:' + item.id, function () { item.css = cssInput.value.trim(); });
      var box = buttonBox.querySelector('input');
      if (box) box.checked = /(^|\s)nav-cta(\s|$)/.test(item.css);
    });

    var keyInput = h('input', { type: 'text', 'class': inputClass, value: item.label_key, placeholder: suggestKey(item), 'aria-label': 'Theme text key' });
    keyInput.addEventListener('input', function () { edit(item, 'key:' + item.id, function () { item.label_key = keyInput.value.trim(); }); });
    var suggest = h('button', {
      type: 'button',
      'class': 'shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50',
      text: 'Suggest',
      onclick: function () {
        keyInput.value = suggestKey(item);
        edit(item, '', function () { item.label_key = keyInput.value; });
      }
    });
    var keyField = h('div', { 'class': 'grid gap-1 text-xs font-medium text-slate-600' }, [
      h('span', {}, ['Theme text key']),
      h('div', { 'class': 'flex gap-2' }, [keyInput, suggest]),
      h('span', { 'class': 'font-normal text-slate-500' }, ['Named nav.' + menuSlug + '.item. Used for the label in a language that has none typed, when the theme has text for it.'])
    ]);

    // An icon the theme no longer has stays in the list, so saving the item does not lose it without a choice.
    var iconNames = (data.icons || []).slice();
    if (item.icon && iconNames.indexOf(item.icon) === -1) iconNames.push(item.icon);
    var iconSelect = h('select', { 'class': inputClass, 'data-icon': '1' }, [h('option', { value: '' }, ['No icon'])].concat(iconNames.map(function (name) {
      return h('option', { value: name }, [name]);
    })));
    iconSelect.value = item.icon;
    iconSelect.addEventListener('change', function () { edit(item, '', function () { item.icon = iconSelect.value; }); });

    var badgeInputs = languages.map(function (code) {
      var input = h('input', { type: 'text', 'class': inputClass, value: item.badges[code], maxlength: 24, 'data-badge': code });
      input.addEventListener('input', function () {
        edit(item, 'badge:' + item.id + ':' + code, function () { item.badges[code] = input.value.trim(); });
      });
      return field(multilingual ? 'Badge · ' + langName(code) : 'Badge', input);
    });

    var relBoxes = relValues.map(function (value) {
      return check(relLabels[value] || value, item.rel.indexOf(value) !== -1, function (on) {
        edit(item, '', function () {
          item.rel = (data.rel_values || []).filter(function (v) { return v === value ? on : item.rel.indexOf(v) !== -1; });
        });
      }, 'rel="' + value + '"');
    });

    return h('div', { id: 'menu-body-' + item.id, 'class': 'grid gap-4 border-t border-slate-100 p-3 sm:p-4' }, [
      h('div', { 'class': 'grid gap-3 sm:grid-cols-2' }, labelInputs),
      h('div', { 'class': 'grid gap-3 sm:grid-cols-2' }, [
        field('Address', urlInput, 'A page or entry of this site (about, posts/hello), or a full link. Leave empty for the home page.'),
        h('div', { 'class': 'grid content-start gap-2.5 pt-1' }, [
          check('Open in a new tab', item.target === '_blank', function (on) { edit(item, '', function () { item.target = on ? '_blank' : ''; }); }),
          buttonBox,
          check('Hide from visitors', item.hidden, function (on) {
            edit(item, '', function () { item.hidden = on; });
            var li = cardFor(item.id);
            if (li) li.firstChild.className = cardClasses(item);
          }, 'Keeps the item here without showing it.'),
          multilingual ? languageChoice(item) : null
        ])
      ]),
      h('details', { 'class': 'rounded-md border border-slate-200 bg-slate-50/60 px-3 py-2' }, [
        h('summary', { 'class': 'cursor-pointer text-xs font-medium text-slate-600' }, ['Advanced']),
        h('div', { 'class': 'mt-3 grid gap-3 sm:grid-cols-2' }, [
          field('CSS classes', cssInput, 'Added to the link, for the theme to style.'),
          keyField,
          field('Icon', iconSelect, 'A small picture from the theme’s icons, shown before the label.'),
          h('div', { 'class': 'sm:col-span-2 grid gap-3 sm:grid-cols-2' }, badgeInputs.concat([h('span', { 'class': 'font-normal text-slate-500 sm:col-span-2 text-xs' }, ['A short word after the label, such as New or Sale. Each one is shown only in its own language.'])])),
          h('div', { 'class': 'sm:col-span-2 grid gap-2' }, [
            h('span', { 'class': 'text-xs font-medium text-slate-600' }, ['Link relations']),
            h('div', { 'class': 'grid gap-2 sm:grid-cols-2' }, relBoxes),
            h('span', { 'class': 'text-xs font-normal text-slate-500' }, ['A link that opens in a new tab always gets noopener as well.'])
          ])
        ])
      ]),
      h('div', { 'class': 'flex flex-wrap items-center justify-between gap-2' }, [
        h('div', { 'class': 'flex flex-wrap items-center gap-1.5 sm:hidden', role: 'group', 'aria-label': 'Position' }, [
          positionButton('up', 'Move up', item), positionButton('down', 'Move down', item), positionButton('outdent', 'Out a level', item), positionButton('indent', 'Nest under the item above', item)
        ]),
        h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50', onclick: function () { act(item.id, 'duplicate'); } }, [icon('copy', 'h-3.5 w-3.5'), 'Duplicate']),
        h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50', onclick: function () { act(item.id, 'remove'); } }, [icon('trash', 'h-3.5 w-3.5'), 'Remove'])
      ])
    ]);
  }

  /** An edit made in a field: recorded for undo (joined with the keystrokes before it), and the card's heading refreshed. */
  function edit(item, key, fn) {
    record(key);
    fn();
    refreshHeader(item);
    changed(false);
  }

  function refreshPlaceholders(item) {
    var li = cardFor(item.id);
    if (!li) return;
    Array.prototype.forEach.call(li.querySelectorAll('input[data-lang]'), function (input) {
      input.placeholder = placeholderFor(item, input.getAttribute('data-lang'));
    });
  }

  function cardFor(id) { return listEl.querySelector('[data-id="' + id + '"]'); }

  function refreshHeader(item) {
    var li = cardFor(item.id);
    if (!li) return;
    var index = indexOfId(item.id);
    var old = li.querySelector('.menu-card-head');
    li.firstChild.replaceChild(buildHeader(item, index), old);
  }

  function cardClasses(item) {
    return 'rounded-lg border shadow-sm transition ' + (item.hidden ? 'border-dashed border-slate-300 bg-slate-50' : 'border-slate-200 bg-white');
  }

  function buildCard(item, index) {
    var inner = h('div', { 'class': cardClasses(item) }, [buildHeader(item, index), item.open ? buildBody(item) : null]);
    var li = h('li', { 'class': 'menu-card min-w-0', 'data-id': item.id, 'data-depth': item.depth, style: 'margin-left:' + (item.depth - 1) * INDENT + 'px' }, [inner]);
    if (state.onlyMissing && !missing(item, state.viewLang)) li.classList.add('is-dim');
    return li;
  }

  /** Redraws one card (after it was opened, closed or hidden), keeping the focus on its heading button. */
  function renderCard(item, opts) {
    var li = cardFor(item.id);
    if (!li) return;
    var index = indexOfId(item.id);
    var fresh = buildCard(item, index);
    listEl.replaceChild(fresh, li);
    if (opts && opts.toggle) {
      var button = fresh.querySelector('button[aria-controls]');
      if (button) button.focus();
    }
    refreshMeta();
  }

  /** @param {{id: number, what: string}=} focus the control to give the focus back to after the redraw */
  function renderList(focus) {
    var y = window.scrollY;
    listEl.textContent = '';
    items.forEach(function (item, index) { listEl.appendChild(buildCard(item, index)); });
    emptyEl.classList.toggle('hidden', items.length > 0);
    emptyEl.classList.toggle('flex', items.length === 0);
    if (focus) {
      var li = cardFor(focus.id);
      var target = li && (focus.what === 'toggle' ? li.querySelector('button[aria-controls]') : li.querySelector('.menu-handle'));
      if (target) target.focus({ preventScroll: true });
    }
    window.scrollTo(window.scrollX, y);
    renderSources();
  }

  function refreshMeta() {
    var visible = items.filter(function (it) { return !it.hidden; }).length;
    countEl.textContent = items.length ? '· ' + items.length + (items.length === 1 ? ' item' : ' items') + (visible !== items.length ? ' (' + (items.length - visible) + ' hidden)' : '') : '';
    renderLangBar();
    renderPreview();
    var anyOpen = items.some(function (it) { return it.open; });
    toggleAllBtn.textContent = anyOpen && state.allOpen ? 'Collapse all' : 'Expand all';
    toggleAllBtn.setAttribute('aria-pressed', anyOpen && state.allOpen ? 'true' : 'false');
    toggleAllBtn.disabled = items.length === 0;
  }

  /* ---------- languages ---------- */

  function renderLangBar() {
    var bar = root.querySelector('[data-lang-bar]');
    if (!multilingual) {
      bar.classList.add('hidden');
      return;
    }
    langSwitch.textContent = '';
    languages.forEach(function (code) {
      var count = missingCount(code);
      var on = state.viewLang === code;
      langSwitch.appendChild(h('button', {
        type: 'button',
        'aria-pressed': on ? 'true' : 'false',
        'class': 'inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-semibold ' + (on ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'),
        title: langName(code),
        onclick: function () { state.viewLang = code; state.onlyMissing = false; renderList(); refreshMeta(); }
      }, [code.toUpperCase(), count ? h('span', { 'class': 'rounded-full px-1.5 text-[10px] font-bold ' + (on ? 'bg-amber-400 text-slate-900' : 'bg-amber-100 text-amber-800'), 'aria-label': count + ' missing' }, [String(count)]) : null]));
    });

    langBanner.textContent = '';
    var lang = state.viewLang;
    var n = missingCount(lang);
    if (!items.length) return;
    if (n === 0) {
      langBanner.appendChild(h('span', { 'class': 'inline-flex items-center gap-1.5 text-emerald-700' }, [icon('check', 'h-3.5 w-3.5'), 'Every item has a ' + langName(lang) + ' label.']));
      return;
    }
    langBanner.appendChild(h('span', { 'class': 'inline-flex items-center gap-1.5 text-amber-800' }, [icon('warn', 'h-3.5 w-3.5'), n + (n === 1 ? ' item has' : ' items have') + ' no ' + langName(lang) + ' label.']));
    var fillable = fillCandidates(lang).length;
    if (fillable) {
      langBanner.appendChild(h('button', { type: 'button', 'class': 'rounded border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700 hover:bg-slate-50', onclick: function () { fillFromPages(lang); } }, ['Fill ' + fillable + ' from the pages']));
    }
    langBanner.appendChild(h('button', { type: 'button', 'aria-pressed': state.onlyMissing ? 'true' : 'false', 'class': 'rounded border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700 hover:bg-slate-50', onclick: function () { state.onlyMissing = !state.onlyMissing; renderList(); refreshMeta(); } }, [state.onlyMissing ? 'Show all' : 'Show only these']));
  }

  function entryLabel(item, lang) {
    var info = urlInfo(item.url);
    if (!info.entry) return '';
    return (info.entry.labels && info.entry.labels[lang]) || (lang === defaultLang ? info.entry.title : '');
  }
  function fillCandidates(lang) {
    return items.filter(function (it) { return !it.hidden && missing(it, lang) && entryLabel(it, lang); });
  }
  function fillFromPages(lang) {
    var list = fillCandidates(lang);
    if (!list.length) return;
    record('');
    list.forEach(function (it) { it.labels[lang] = entryLabel(it, lang); });
    changed(true);
    showNote('Filled ' + list.length + (list.length === 1 ? ' label' : ' labels') + ' in ' + langName(lang) + ' from the pages.', true);
    announce('Filled ' + list.length + ' labels');
  }

  /* ---------- preview ---------- */

  function renderPreview() {
    previewEl.textContent = '';
    var lang = state.viewLang;
    var tops = [];
    var skipDepth = 0;
    items.forEach(function (it) {
      if (skipDepth && it.depth > skipDepth) return;
      skipDepth = 0;
      if (it.hidden || !inLanguage(it, lang)) { skipDepth = it.depth; return; }
      var node = { item: it, kids: [] };
      if (it.depth === 1) tops.push(node);
      else {
        var parent = tops[tops.length - 1];
        for (var d = 2; parent && d < it.depth; d++) parent = parent.kids[parent.kids.length - 1];
        if (parent) parent.kids.push(node);
      }
    });
    if (!tops.length) {
      previewEl.appendChild(h('p', { 'class': 'text-sm text-slate-500' }, ['Nothing to show yet.']));
      return;
    }
    function label(it) {
      var s = shown(it, lang).text;
      if (s) return s;
      var info = urlInfo(it.url);
      return info.entry ? info.entry.title : (it.url || '…');
    }
    function sub(nodes) {
      return h('ul', { 'class': 'mt-1 grid gap-0.5 border-l border-slate-200 pl-3' }, nodes.map(function (n) {
        return h('li', { 'class': 'text-xs text-slate-600' }, [label(n.item), n.kids.length ? sub(n.kids) : null]);
      }));
    }
    previewEl.appendChild(h('ul', { 'class': 'flex flex-wrap items-start gap-x-6 gap-y-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3' }, tops.map(function (n) {
      var button = /(^|\s)nav-cta(\s|$)/.test(n.item.css);
      return h('li', {}, [
        button
          ? h('span', { 'class': 'inline-block rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white' }, [label(n.item)])
          : h('span', { 'class': 'text-sm font-semibold text-slate-800' }, [label(n.item)]),
        n.kids.length ? sub(n.kids) : null
      ]);
    })));
  }

  /* ---------- changes from buttons and keys ---------- */

  function act(id, what) {
    var i = indexOfId(id);
    if (i < 0) return;
    var item = items[i];
    var title = titleOf(item);
    var ok = false;
    if (what === 'up') {
      var p = prevSibling(i);
      if (p >= 0) { record(''); relocate(i, p, item.depth); ok = true; }
    } else if (what === 'down') {
      var n = nextSibling(i);
      if (n >= 0) { record(''); relocate(i, blockEnd(n), item.depth); ok = true; }
    } else if (what === 'indent') {
      if (canIndent(i)) { record(''); for (var a = i; a < blockEnd(i); a++) items[a].depth++; ok = true; }
    } else if (what === 'outdent') {
      if (item.depth > 1) { record(''); for (var b = i; b < blockEnd(i); b++) items[b].depth--; ok = true; }
    } else if (what === 'remove') {
      record('');
      var end = blockEnd(i);
      for (var c = i + 1; c < end; c++) items[c].depth--;
      items.splice(i, 1);
      changed(true);
      showNote('Removed “' + title + '”.', true);
      announce('Removed ' + title);
      return;
    } else if (what === 'duplicate') {
      if (items.length + (blockEnd(i) - i) > maxItems) { showNote('A menu holds at most ' + maxItems + ' items.', false); return; }
      record('');
      var copy = items.slice(i, blockEnd(i)).map(function (it) { var c2 = makeItem(JSON.parse(JSON.stringify(it))); c2.open = false; return c2; });
      items.splice.apply(items, [blockEnd(i), 0].concat(copy));
      changed(true, { id: copy[0].id, what: 'toggle' });
      flash(copy[0].id);
      announce('Duplicated ' + title);
      return;
    }
    if (!ok) return;
    changed(true, { id: id, what: whatFocus(what) });
    var now = indexOfId(id);
    announce('Moved ' + title + ' to position ' + (now + 1) + ' of ' + items.length + ', level ' + items[now].depth);
  }
  function whatFocus() { return 'handle'; }

  function handleKey(event, item) {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    var map = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'outdent', ArrowRight: 'indent' };
    if (!map[event.key]) return;
    event.preventDefault();
    act(item.id, map[event.key]);
  }

  function flash(id) {
    var li = cardFor(id);
    if (!li) return;
    li.classList.add('is-flash');
    window.setTimeout(function () { li.classList.remove('is-flash'); }, 1500);
    if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  /* ---------- adding ---------- */

  function itemFromEntry(entry, depth) {
    var labels = fillLangs(entry.labels);
    if (!labels[defaultLang]) labels[defaultLang] = entry.title;
    return makeItem({ depth: depth || 1, labels: labels, url: entry.url });
  }

  function insertItems(list, at) {
    if (items.length + list.length > maxItems) {
      showNote('A menu holds at most ' + maxItems + ' items.', false);
      return false;
    }
    record('');
    items.splice.apply(items, [at === undefined ? items.length : at, 0].concat(list));
    changed(true);
    flash(list[0].id);
    announce('Added ' + list.length + (list.length === 1 ? ' item' : ' items'));
    return true;
  }

  function addEntries(entries) {
    insertItems(entries.map(function (e) { return itemFromEntry(e, 1); }));
  }

  var addUrl = root.querySelector('[data-custom-url]');
  var addLabel = root.querySelector('[data-custom-label]');
  function addCustom() {
    var url = addUrl.value.trim();
    var label = addLabel.value.trim();
    if (url === '' && label === '') { addUrl.focus(); return; }
    var labels = {};
    labels[defaultLang] = label;
    if (insertItems([makeItem({ depth: 1, labels: labels, url: url })])) {
      addUrl.value = '';
      addLabel.value = '';
    }
  }
  root.querySelector('[data-custom-add]').addEventListener('click', addCustom);
  [addUrl, addLabel].forEach(function (input) {
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });
  });

  /* ---------- the panel of what can be added ---------- */

  function inMenu(url) {
    var key = norm(url);
    return items.some(function (it) { return norm(it.url) === key && urlInfo(it.url).kind !== 'external'; });
  }

  function matches(entry, q) {
    if (!q) return true;
    var hay = (entry.title + ' ' + entry.url + ' ' + (entry.detail || '') + ' ' + Object.keys(entry.labels || {}).map(function (k) { return entry.labels[k]; }).join(' ')).toLowerCase();
    return q.split(/\s+/).every(function (part) { return hay.indexOf(part) !== -1; });
  }

  function selKey(group, entry) { return group.id + '|' + entry.url; }

  function renderSources() {
    var scroll = sourceGroupsEl.scrollTop;
    sourceGroupsEl.textContent = '';
    var q = state.query.trim().toLowerCase();
    var shownAny = false;
    (data.groups || []).forEach(function (group, gi) {
      var found = group.items.filter(function (e) { return matches(e, q); });
      if (!found.length) return;
      shownAny = true;
      var limit = state.limits[group.id] || (q ? 100 : 40);
      var open = q ? true : (state.openGroups[group.id] !== undefined ? state.openGroups[group.id] : gi === 0);
      var details = h('details', { 'class': 'border-b border-slate-100 last:border-b-0', 'data-group': group.id });
      if (open) details.setAttribute('open', '');
      details.addEventListener('toggle', function () { if (!q) state.openGroups[group.id] = details.open; });
      var summary = h('summary', { 'class': 'flex cursor-pointer items-center justify-between gap-2 px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50' }, [
        h('span', {}, [group.label]),
        h('span', { 'class': 'rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600' }, [String(q ? found.length : group.total)])
      ]);
      details.appendChild(summary);
      var ul = h('ul', { 'class': 'pb-2' });
      found.slice(0, limit).forEach(function (entry) {
        ul.appendChild(sourceRow(group, entry));
      });
      if (found.length > limit) {
        ul.appendChild(h('li', { 'class': 'px-4 py-1' }, [h('button', { type: 'button', 'class': 'text-xs font-medium text-blue-700 underline hover:text-blue-900', onclick: function () { state.limits[group.id] = limit + 100; renderSources(); } }, ['Show ' + Math.min(100, found.length - limit) + ' more of ' + (found.length - limit)])]));
      }
      if (!q && group.total > group.items.length) {
        ul.appendChild(h('li', { 'class': 'px-4 py-1 text-xs text-slate-500' }, ['Showing the first ' + group.items.length + ' of ' + group.total + '. Search for the rest.']));
      }
      details.appendChild(ul);
      sourceGroupsEl.appendChild(details);
    });
    if (!shownAny) {
      sourceGroupsEl.appendChild(h('p', { 'class': 'px-4 py-6 text-center text-sm text-slate-500' }, [q ? 'Nothing matches “' + state.query.trim() + '”.' : 'Nothing to add yet.']));
    }
    sourceGroupsEl.scrollTop = scroll;
    refreshSelected();
  }

  function sourceRow(group, entry) {
    var key = selKey(group, entry);
    var already = inMenu(entry.url);
    var box = h('input', { type: 'checkbox', 'class': 'h-4 w-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-slate-400', 'aria-label': 'Select ' + entry.title, checked: state.selected[key] ? true : false });
    box.addEventListener('change', function () {
      if (box.checked) state.selected[key] = { group: group, entry: entry }; else delete state.selected[key];
      refreshSelected();
    });
    var langs = multilingual ? languages.filter(function (c) { return entry.labels && entry.labels[c]; }) : [];
    var row = h('li', { 'class': 'source-row group flex select-none items-center gap-2 px-4 py-1.5 hover:bg-slate-50', 'data-source': key }, [
      box,
      h('span', { 'class': 'min-w-0 flex-1' }, [
        h('span', { 'class': 'flex items-center gap-1.5' }, [
          h('span', { 'class': 'truncate text-sm text-slate-800' }, [entry.title]),
          already ? h('span', { 'class': 'inline-flex shrink-0 items-center text-emerald-700', title: 'Already in this menu' }, [icon('check', 'h-3.5 w-3.5'), h('span', { 'class': 'sr-only' }, ['Already in this menu'])]) : null,
          entry.draft ? badge(entry.detail || 'Draft', 'amber') : null
        ]),
        h('span', { 'class': 'flex items-center gap-1.5 truncate text-[11px] text-slate-500' }, [
          entry.url === '' ? 'Home page' : '/' + entry.url,
          langs.length ? h('span', { 'class': 'font-semibold uppercase tracking-wide' }, ['· ' + langs.join(' ')]) : null
        ])
      ]),
      h('button', {
        type: 'button',
        'class': 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:border-blue-500 hover:bg-blue-50 hover:text-blue-700',
        'aria-label': 'Add ' + entry.title + ' to the menu',
        title: 'Add to the menu',
        onclick: function () { addEntries([entry]); }
      }, [icon('plus')])
    ]);
    row.addEventListener('pointerdown', function (e) { beginSourceDrag(e, group, entry, key); });
    return row;
  }

  function refreshSelected() {
    var n = Object.keys(state.selected).length;
    selectedCount.textContent = String(n);
    selectedBar.classList.toggle('hidden', n === 0);
    selectedBar.classList.toggle('flex', n > 0);
  }
  root.querySelector('[data-source-clear]').addEventListener('click', function () { state.selected = {}; renderSources(); });
  root.querySelector('[data-source-add-selected]').addEventListener('click', function () {
    var picked = Object.keys(state.selected).map(function (k) { return state.selected[k].entry; });
    state.selected = {};
    if (picked.length) addEntries(picked);
  });
  var searchTimer = 0;
  sourceSearch.addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () { state.query = sourceSearch.value; renderSources(); }, 120);
  });

  /* ---------- dragging ---------- */

  var drag = null;
  var ghost = null;

  function beginDrag(event, item) {
    if (event.button !== undefined && event.button !== 0) return;
    drag = { kind: 'row', id: item.id, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, active: false, drop: null };
    listen();
  }

  function beginSourceDrag(event, group, entry, key) {
    if (event.button !== 0 || event.pointerType === 'touch') return;
    if (event.target.closest('button, input, label, a')) return;
    var entries = state.selected[key] ? Object.keys(state.selected).map(function (k) { return state.selected[k].entry; }) : [entry];
    drag = { kind: 'source', entries: entries, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, active: false, drop: null };
    listen();
  }

  function listen() {
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
    document.addEventListener('pointercancel', onCancel);
    document.addEventListener('keydown', onDragKey);
  }
  function unlisten() {
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onCancel);
    document.removeEventListener('keydown', onDragKey);
  }

  function onMove(event) {
    if (!drag) return;
    drag.x = event.clientX;
    drag.y = event.clientY;
    if (!drag.active) {
      if (Math.abs(drag.x - drag.startX) + Math.abs(drag.y - drag.startY) < 6) return;
      activate();
    }
    event.preventDefault();
    moveGhost();
    drag.drop = computeDrop();
    paintDrop();
    autoScroll();
  }

  function activate() {
    drag.active = true;
    document.body.classList.add('menu-dragging');
    var label;
    if (drag.kind === 'row') {
      var i = indexOfId(drag.id);
      drag.start = i;
      drag.end = blockEnd(i);
      for (var k = drag.start; k < drag.end; k++) {
        var li = cardFor(items[k].id);
        if (li) li.classList.add('is-dragging');
      }
      label = titleOf(items[i]) + (drag.end - drag.start > 1 ? ' +' + (drag.end - drag.start - 1) : '');
    } else {
      label = drag.entries.length === 1 ? drag.entries[0].title : drag.entries.length + ' items';
    }
    ghost = h('div', { 'class': 'menu-drag-ghost' }, [label]);
    document.body.appendChild(ghost);
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  }

  function moveGhost() {
    ghost.style.transform = 'translate(' + (drag.x + 14) + 'px,' + (drag.y + 10) + 'px)';
  }

  /** Where the dragged thing would land: the place in the list and its depth, or null when the pointer is away from the menu. */
  function computeDrop() {
    var wrapBox = wrapEl.getBoundingClientRect();
    var listBox = listEl.getBoundingClientRect();
    if (drag.x < wrapBox.left - 30 || drag.x > wrapBox.right + 30 || drag.y < wrapBox.top - 30 || drag.y > wrapBox.bottom + 30) return null;

    var rest = [];
    items.forEach(function (it, idx) {
      if (drag.kind === 'row' && idx >= drag.start && idx < drag.end) return;
      rest.push(idx);
    });
    var k = rest.length;
    for (var n = 0; n < rest.length; n++) {
      var li = cardFor(items[rest[n]].id);
      if (!li) continue;
      var box = li.getBoundingClientRect();
      if (drag.y < box.top + box.height / 2) { k = n; break; }
    }
    var before = k < rest.length ? rest[k] : items.length;
    var prev = k > 0 ? items[rest[k - 1]] : null;
    var base;
    var span;
    if (drag.kind === 'row') {
      base = items[drag.start].depth + Math.round((drag.x - drag.startX) / INDENT);
      span = spanBelow(drag.start);
    } else {
      base = 1 + Math.round((drag.x - listBox.left - 24) / INDENT);
      span = 0;
    }
    var max = Math.min(prev ? prev.depth + 1 : 1, MAX_DEPTH - span);
    var depth = Math.max(1, Math.min(max, base));

    var top;
    if (k < rest.length) {
      top = cardFor(items[rest[k]].id).getBoundingClientRect().top - 4;
    } else if (rest.length) {
      top = cardFor(items[rest[rest.length - 1]].id).getBoundingClientRect().bottom + 4;
    } else {
      top = listBox.top + 2;
    }
    return { before: before, depth: depth, top: top - wrapBox.top, left: listBox.left - wrapBox.left + (depth - 1) * INDENT, width: listBox.width - (depth - 1) * INDENT };
  }

  function paintDrop() {
    var drop = drag.drop;
    if (!drop) { dropLine.classList.add('hidden'); return; }
    dropLine.classList.remove('hidden');
    dropLine.style.top = drop.top - 2 + 'px';
    dropLine.style.left = drop.left + 'px';
    dropLine.style.width = Math.max(40, drop.width) + 'px';
  }

  var scrollFrame = 0;
  function autoScroll() {
    if (scrollFrame || !drag || !drag.active) return;
    var step = function () {
      scrollFrame = 0;
      if (!drag || !drag.active) return;
      var speed = 0;
      if (drag.y < 90) speed = -Math.ceil((90 - drag.y) / 6);
      else if (drag.y > window.innerHeight - 90) speed = Math.ceil((drag.y - (window.innerHeight - 90)) / 6);
      if (speed) {
        window.scrollBy(0, speed);
        drag.drop = computeDrop();
        paintDrop();
        scrollFrame = window.requestAnimationFrame(step);
      }
    };
    scrollFrame = window.requestAnimationFrame(step);
  }

  function endDrag() {
    unlisten();
    document.body.classList.remove('menu-dragging');
    if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
    ghost = null;
    dropLine.classList.add('hidden');
    Array.prototype.forEach.call(listEl.querySelectorAll('.is-dragging'), function (li) { li.classList.remove('is-dragging'); });
    var finished = drag;
    drag = null;
    return finished;
  }

  function onUp() {
    var done = endDrag();
    if (!done || !done.active || !done.drop) return;
    if (done.kind === 'row') {
      var i = indexOfId(done.id);
      var item = items[i];
      var title = titleOf(item);
      var depthNow = item.depth;
      var sameSpot = done.drop.before === blockEnd(i) || done.drop.before === i;
      if (sameSpot && done.drop.depth === depthNow) return;
      record('');
      relocate(i, done.drop.before, done.drop.depth);
      changed(true, { id: done.id, what: 'handle' });
      flash(done.id);
      var at = indexOfId(done.id);
      announce('Moved ' + title + ' to position ' + (at + 1) + ' of ' + items.length + ', level ' + items[at].depth);
    } else {
      var fresh = done.entries.map(function (e) { return itemFromEntry(e, done.drop.depth); });
      state.selected = {};
      insertItems(fresh, done.drop.before);
    }
  }

  function onCancel() { endDrag(); }
  function onDragKey(event) { if (event.key === 'Escape') endDrag(); }

  /* ---------- where the menu appears ---------- */

  function renderPlaces() {
    placesEl.textContent = '';
    var places = data.locations || [];
    if (!places.length) {
      placesEl.appendChild(h('p', { 'class': 'text-sm text-slate-500' }, ['The theme has no places for a menu.']));
      return;
    }
    if (!data.can_place) {
      var here = places.filter(function (p) { return p.menu === data.key; }).map(function (p) { return p.label; });
      placesEl.appendChild(h('p', { 'class': 'text-sm text-slate-700' }, [here.length ? 'Shown in: ' + here.join(', ') + '.' : 'Not shown anywhere yet.']));
      placesEl.appendChild(h('p', { 'class': 'mt-1 text-xs text-slate-500' }, ['Changing where menus appear needs permission to change the settings.']));
      return;
    }
    placesEl.appendChild(h('input', { type: 'hidden', name: 'menu_locations_present', value: '1' }));
    var grid = h('div', { 'class': 'grid gap-3 sm:grid-cols-2' });
    places.forEach(function (place) {
      var mine = place.menu === data.key;
      var box = h('input', { type: 'checkbox', name: 'menu_locations[]', value: place.key, 'class': 'mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400', checked: mine ? true : false, disabled: place.locked ? true : false });
      box.addEventListener('change', refreshDirty);
      var note = '';
      if (place.locked) note = 'This is the theme\'s own menu for this place. To use another menu there, tick the place on that menu.';
      else if (!mine && place.menu) note = 'Now showing the menu “' + place.menu + '”. Ticking this replaces it.';
      grid.appendChild(h('label', { 'class': 'flex items-start gap-2.5 rounded-md border border-slate-200 p-3 text-sm text-slate-800' + (place.locked ? ' bg-slate-50' : '') }, [
        box,
        h('span', {}, [h('span', { 'class': 'block font-medium' }, [place.label]), note ? h('span', { 'class': 'mt-0.5 block text-xs text-slate-500' }, [note]) : null])
      ]));
      if (place.locked) {
        // A disabled box is not sent, and the server leaves a place alone that already shows this menu.
        grid.lastChild.appendChild(h('input', { type: 'hidden', name: 'menu_locations[]', value: place.key }));
      }
    });
    placesEl.appendChild(grid);
  }

  /* ---------- toolbar, keys, saving ---------- */

  undoBtn.addEventListener('click', undo);
  redoBtn.addEventListener('click', redo);
  toggleAllBtn.addEventListener('click', function () {
    var open = !(state.allOpen && items.some(function (it) { return it.open; }));
    state.allOpen = open;
    items.forEach(function (it) { it.open = open; });
    renderList();
    refreshMeta();
  });
  titleField.addEventListener('input', refreshDirty);

  document.addEventListener('keydown', function (event) {
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
    var tag = event.target && event.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    var key = event.key.toLowerCase();
    if (key === 'z' && !event.shiftKey) { event.preventDefault(); undo(); }
    else if ((key === 'z' && event.shiftKey) || key === 'y') { event.preventDefault(); redo(); }
  });

  form.addEventListener('submit', function (event) {
    jsonField.value = serialize();
    submitting = true;
    // A confirmation (delete) may stop the submit: then the warning about unsaved changes applies again.
    window.setTimeout(function () { if (event.defaultPrevented) submitting = false; }, 0);
  });
  window.addEventListener('beforeunload', function (event) {
    if (!dirty || submitting) return;
    event.preventDefault();
    event.returnValue = '';
  });

  /* ---------- start ---------- */

  items = (data.items || []).map(function (row) { return makeItem(row); });
  renderPlaces();
  renderList();
  refreshMeta();
  initialState = { items: serialize(), title: titleField.value, places: placesState() };
  refreshDirty();
})();
