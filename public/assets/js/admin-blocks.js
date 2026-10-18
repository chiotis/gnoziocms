/*
 * GnozioCMS block editor (Admin > Edit > Blocks).
 *
 * Reads block definitions and the page's blocks from #block-editor-data, renders a form per block
 * from its block.yaml fields, and writes the result as JSON into [data-block-editor-output] before
 * the page is saved. The server checks every value again (BlockRegistry::sanitizeForStorage).
 * Rendering happens only on structural changes (add, move, remove, open); typing updates state
 * directly so the field keeps focus.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('block-editor-data');
  var root = document.querySelector('[data-block-editor]');
  var output = document.querySelector('[data-block-editor-output]');
  var flag = document.querySelector('[data-block-editor-flag]');
  if (!dataEl || !root || !output || !flag) return;

  var data;
  try {
    data = JSON.parse(dataEl.textContent);
  } catch (e) {
    return;
  }

  var definitions = {};
  (data.definitions || []).forEach(function (def) { definitions[def.type] = def; });
  // The container row (BlockContainer): not a block type of the theme, so the page hands over its fields; it gets a definition of its
  // own here so the lists, the picker and the labels treat it like a block.
  var containerDef = data.container || null;
  var MAX_COLUMNS = containerDef ? containerDef.max_columns : 6;
  var CONTAINER_PREVIEW = '<rect x="6" y="8" width="52" height="32" rx="3"/><rect x="10" y="12" width="12" height="24" rx="2" fill="currentColor" fill-opacity=".14"/><rect x="26" y="12" width="12" height="24" rx="2" fill="currentColor" fill-opacity=".14"/><rect x="42" y="12" width="12" height="24" rx="2" fill="currentColor" fill-opacity=".14"/>';
  if (containerDef) {
    definitions.container = { type: 'container', label: containerDef.label, description: containerDef.description, category: 'Layout', origin: 'theme', preview: CONTAINER_PREVIEW, variant_when: {}, variant_previews: {}, design_fields: [], common: [], fields: [] };
  }
  // The editor of Theme > Footer Blocks (data.context 'footer') is for the blocks above the footer, not for a page: no whole-page layouts.
  var footerMode = data.context === 'footer';
  var presets = (data.presets || []).filter(function (p) { return !footerMode || p.kind !== 'page'; });
  var selected = {};
  var nextId = 1;
  // The footer's blocks can have a set for each language (data.languages: [{key: 'default', code: 'el'}, {key: 'en', code: 'en'}], the
  // site's own first; data.blocks is then a map of key to blocks). One editor shows one set at a time; the output holds them all.
  var languages = footerMode && Array.isArray(data.languages) && data.languages.length > 1 ? data.languages : [];
  var sets = null;
  var activeKey = '';
  var blocks;
  if (languages.length) {
    sets = {};
    languages.forEach(function (language) {
      var own = data.blocks && !Array.isArray(data.blocks) ? data.blocks[language.key] : (language.key === 'default' ? data.blocks : []);
      sets[language.key] = (Array.isArray(own) ? own : []).map(function (block) { return withId(block, false); });
    });
    activeKey = languages[0].key;
    blocks = sets[activeKey];
  } else {
    blocks = (data.blocks || []).map(function (block) { return withId(block, false); });
  }
  var lastRemoved = null;

  var CLS = {
    input: 'lc-input',
    btn: 'bk-btn',
    btnPrimary: 'bk-btn is-primary',
    icon: 'bk-icon',
    iconDanger: 'bk-icon is-danger'
  };


  var ICONS = {
    grip: '<circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/>',
    more: '<circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/>',
    top: '<path d="M6 5h12M6 15l6-6 6 6M12 9v10"/>',
    bottom: '<path d="M6 19h12M6 9l6 6 6-6M12 15V5"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="m9 6 6 6-6 6"/>',
    eyeOff: '<path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A9.8 9.8 0 0 1 12 5c5 0 9 5 9 7a9.6 9.6 0 0 1-2.5 3.5M6.2 6.2C4.3 7.5 3 9.5 3 12c0 2 4 7 9 7a9.3 9.3 0 0 0 4.3-1"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    wrap: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M9 5v14M15 5v14"/>',
    out: '<path d="M4 12h10M10 8l4 4-4 4M19 5v14"/>',
    minus: '<path d="M5 12h14"/>'
  };

  /* The wireframe a block shows in the picker: the markup of its preview.svg (see BlockRegistry::previewMarkup), or a plain
     stack of boxes for a block that has none. */
  var PREVIEW_FALLBACK = '<rect x="8" y="8" width="48" height="12" rx="3"/><rect x="8" y="24" width="48" height="16" rx="3" fill="currentColor" fill-opacity=".14"/>';

  function thumb(markup) {
    return el('span', {
      class: 'bk-pthumb',
      html: '<svg viewBox="0 0 64 48" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (markup || PREVIEW_FALLBACK) + '</svg>'
    });
  }

  function svg(name, cls) {
    return '<svg class="' + (cls || 'h-4 w-4') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'class') node.className = attrs[key];
      else if (key === 'text') node.textContent = attrs[key];
      else if (key === 'html') node.innerHTML = attrs[key];
      else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), attrs[key]);
      else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
    });
    (children || []).forEach(function (child) {
      if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function withId(block, open) {
    return prepare(JSON.parse(JSON.stringify(block)), open);
  }

  /** The editor-only state of a block (its id, whether it is open, its tab) lives in properties that are never saved. */
  function tag(node, open, tab) {
    Object.defineProperty(node, '_id', { value: nextId++, enumerable: false, writable: true });
    Object.defineProperty(node, '_open', { value: !!open, enumerable: false, writable: true });
    Object.defineProperty(node, '_tab', { value: tab || 'content', enumerable: false, writable: true });
    return node;
  }

  function plain(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  }

  /** A block as the builder holds it; a container is given its settings and one to six columns, each with its blocks (one level: no container inside). */
  function prepare(block, open) {
    tag(block, open);
    if (block.type === 'container') {
      block.settings = plain(block.settings);
      var columns = Array.isArray(block.columns) && block.columns.length ? block.columns.slice(0, MAX_COLUMNS) : [{}];
      block.columns = columns.map(function (given) {
        given = plain(given);
        var column = { settings: plain(given.settings), blocks: [] };
        column.blocks = (Array.isArray(given.blocks) ? given.blocks : []).filter(function (inner) {
          return inner && typeof inner === 'object' && inner.type !== 'container';
        }).map(function (inner) { return prepare(inner, false); });
        Object.defineProperty(column, '_id', { value: nextId++, enumerable: false, writable: true });
        return column;
      });
    }
    return block;
  }

  function isContainer(block) {
    return !!block && block.type === 'container';
  }

  /** A new container with columns of the given widths ('' for an equal share). */
  function newContainer(widths) {
    return prepare({
      type: 'container',
      settings: {},
      columns: (widths && widths.length ? widths : ['', '']).map(function (width) { return { settings: width ? { width: width } : {}, blocks: [] }; })
    }, true);
  }

  /** Every block of a list, the ones inside containers too. */
  function everyBlock(list) {
    var all = [];
    list.forEach(function (block) {
      all.push(block);
      if (isContainer(block)) block.columns.forEach(function (column) { column.blocks.forEach(function (inner) { all.push(inner); }); });
    });
    return all;
  }

  function hasContainers() {
    return blocks.some(isContainer);
  }

  function defaultsFor(fields) {
    var values = {};
    (fields || []).forEach(function (field) {
      values[field.key] = field.type === 'repeater' ? [] : field['default'];
    });
    return values;
  }

  function newBlock(type) {
    var def = definitions[type];
    var block = { type: type };
    Object.assign(block, defaultsFor(def.common), defaultsFor(def.fields));
    return withId(block, true);
  }

  function serialize() {
    var total = blocks.length;
    if (sets) {
      var all = {};
      total = 0;
      languages.forEach(function (language) { all[language.key] = sets[language.key]; total += sets[language.key].length; });
      output.value = JSON.stringify(all);
      languageButtons.forEach(function (button) {
        var key = button.getAttribute('data-set');
        var size = sets[key].length;
        button.classList.toggle('has-blocks', size > 0);
        button.title = size ? size + ' block(s)' : (key === languages[0].key ? 'No blocks' : 'No blocks of its own: the site\'s own blocks show');
      });
    } else {
      output.value = JSON.stringify(blocks);
    }
    var count = document.querySelector('[data-block-count]');
    if (count) count.textContent = total ? String(total) : '';
    // Whatever changed the blocks (add, remove, move, a field, a design setting) ends here: the live preview listens for it.
    document.dispatchEvent(new CustomEvent('bk:change', { detail: { count: total } }));
  }

  /** The share of the row a block takes (25, 50, 75 or 100). */
  function widthOf(block) {
    var value = Number(block.width);
    return value === 25 || value === 33 || value === 50 || value === 66 || value === 75 ? value : 100;
  }

  function summaryOf(block) {
    var keys = ['heading', 'eyebrow', 'text', 'intro', 'body'];
    for (var i = 0; i < keys.length; i++) {
      var value = block[keys[i]];
      if (typeof value === 'string' && value.trim() !== '') {
        var text = value.replace(/\s+/g, ' ').trim();
        return text.length > 70 ? text.slice(0, 70) + '…' : text;
      }
    }
    return '';
  }

  function optionLabel(field, value) {
    var match = (field.options || []).filter(function (pair) { return pair[0] === String(value); })[0];
    return match ? match[1] : String(value);
  }

  /* Fields ------------------------------------------------------------------ */

  // A field a person fills in is a small label over a compact control; short fields sit two to a row.
  var WIDE = { textarea: 1, markdown: 1, image: 1, video: 1, file: 1, repeater: 1 };

  /** A choice of a few short words is a row of buttons; a longer list is a menu. */
  function isShortChoice(field) {
    var options = field.options || [];
    return options.length > 1 && options.length <= 4 && options.every(function (pair) { return String(pair[1]).length <= 14; });
  }

  function choiceButtons(field, value, onChange, cls, labelOf) {
    var group = el('div', { class: cls, role: 'group', 'aria-label': field.label });
    var buttons = [];
    (field.options || []).forEach(function (pair) {
      var on = String(value) === pair[0];
      var button = el('button', { type: 'button', 'aria-pressed': on ? 'true' : 'false', 'data-value': pair[0] });
      if (labelOf) { labelOf(button, pair); } else { button.textContent = pair[1]; }
      button.addEventListener('click', function () {
        buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        onChange(pair[0]);
      });
      buttons.push(button);
      group.appendChild(button);
    });
    return group;
  }

  /** The help of a field: short help is shown under it; long help opens from a small button beside the label. */
  function helpNode(field, id) {
    if (!field.help) return null;
    return el('span', { class: 'bk-help', id: id + '-help', text: field.help });
  }

  function fieldControl(field, value, onChange, idBase) {
    var id = idBase + '-' + field.key;
    var type = field.type;
    var control;

    if (type === 'repeater') return repeaterControl(field, Array.isArray(value) ? value : [], onChange, id);

    if (type === 'toggle') {
      var checkbox = el('input', { type: 'checkbox', id: id, checked: !!value });
      checkbox.addEventListener('change', function () { onChange(checkbox.checked); });
      return el('div', { class: 'bk-field bk-field-toggle' + (field.span === 'full' ? ' is-wide' : '') }, [
        el('label', { class: 'lc-chip', title: field.help || null }, [checkbox, el('span', { text: field.label })]),
        field.help && field.help.length > 60 ? helpNode(field, id) : null
      ]);
    }

    var labelText = field.label + (type === 'markdown' ? ' (Markdown)' : '') + (field.required ? ' *' : '');
    var wide = field.span === 'full' || WIDE[type] === 1;
    // A field with `span: third` shares its row with two others (a button's text, link and style).
    var wrapper = el('div', { class: 'bk-field' + (wide ? ' is-wide' : (field.span === 'third' ? ' is-third' : '')) }, [
      el('label', { class: 'bk-label', for: id, text: labelText })
    ]);

    if (type === 'select' && field.drawings && Object.keys(field.drawings).length) {
      // A choice drawn as small pictures (the layout of a slide in the hero slider), like the layouts of a block.
      control = choiceButtons(field, value == null ? field['default'] : value, onChange, 'bk-choices bk-vtiles', function (button, pair) {
        button.appendChild(thumb(field.drawings[pair[0]]));
        button.appendChild(el('span', { text: pair[1] }));
      });
      control.id = id;
      wrapper.querySelector('label').removeAttribute('for');
      wrapper.querySelector('label').setAttribute('id', id + '-label');
      control.setAttribute('aria-labelledby', id + '-label');
      wrapper.appendChild(control);
    } else if ((type === 'select') && isShortChoice(field)) {
      control = choiceButtons(field, value == null ? '' : value, onChange, 'bk-seg');
      control.id = id;
      wrapper.querySelector('label').removeAttribute('for');
      wrapper.querySelector('label').setAttribute('id', id + '-label');
      control.setAttribute('aria-labelledby', id + '-label');
      wrapper.appendChild(control);
    } else if (type === 'select' || type === 'icon') {
      // An icon is a select the icon picker (admin-icons.js) turns into a popup of small pictures.
      control = el('select', { id: id, class: 'lc-input lc-select' });
      if (type === 'icon') control.setAttribute('data-icon-picker', '');
      (field.options || []).forEach(function (pair) {
        control.appendChild(el('option', { value: pair[0], text: pair[1], selected: String(value) === pair[0] }));
      });
      control.addEventListener('change', function () { onChange(control.value); });
      wrapper.appendChild(control);
    } else if (type === 'textarea' || type === 'markdown') {
      control = el('textarea', {
        id: id,
        rows: type === 'markdown' ? 5 : 2,
        placeholder: field.placeholder || '',
        class: 'lc-input bk-area' + (type === 'markdown' ? ' font-mono text-[12px] leading-5' : '')
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
      wrapper.appendChild(control);
    } else if (type === 'image') {
      control = el('input', { id: id, type: 'text', placeholder: field.placeholder || '/uploads/…', class: 'lc-input' });
      control.value = value == null ? '' : String(value);
      var thumbBox = el('span', { class: 'bk-thumb' }, [el('img', { alt: '' }), el('span', { html: svg('image'), class: 'bk-thumb-empty' })]);
      var img = thumbBox.querySelector('img');
      var sync = function () {
        var has = control.value.trim() !== '';
        thumbBox.classList.toggle('has-image', has);
        if (has) img.src = control.value; else img.removeAttribute('src');
      };
      control.addEventListener('input', function () { onChange(control.value); sync(); });
      var choose = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      choose.addEventListener('click', function () {
        openMediaPicker(function (url) { control.value = url; onChange(url); sync(); });
      });
      var clear = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Clear ' + field.label, title: 'Clear', html: svg('close') });
      clear.addEventListener('click', function () { control.value = ''; onChange(''); sync(); });
      sync();
      wrapper.appendChild(el('div', { class: 'bk-image' }, [thumbBox, control, choose, clear]));
    } else if (type === 'video') {
      // A video: its address (the library, a YouTube or Vimeo link, a file elsewhere) with a Library button for the videos.
      control = el('input', { id: id, type: 'text', placeholder: field.placeholder || '', class: 'lc-input' });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
      var chooseVideo = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      chooseVideo.setAttribute('aria-label', 'Choose a video from the media library');
      chooseVideo.addEventListener('click', function () {
        openMediaPicker(function (url) { control.value = url; onChange(url); }, 'video');
      });
      wrapper.appendChild(el('div', { class: 'bk-image' }, [control, chooseVideo]));
    } else if (type === 'file') {
      // A file to download: its address with a Library button for every kind of file.
      control = el('input', { id: id, type: 'text', placeholder: field.placeholder || '/uploads/…', class: 'lc-input' });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
      var chooseFile = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      chooseFile.setAttribute('aria-label', 'Choose a file from the media library');
      chooseFile.addEventListener('click', function () {
        openMediaPicker(function (url) { control.value = url; onChange(url); }, 'all');
      });
      wrapper.appendChild(el('div', { class: 'bk-image' }, [control, chooseFile]));
    } else {
      var inputType = type === 'number' ? 'number' : (type === 'email' ? 'email' : 'text');
      control = el('input', {
        id: id,
        type: inputType,
        placeholder: field.placeholder || '',
        min: type === 'number' && field.min !== null ? field.min : null,
        max: type === 'number' && field.max !== null ? field.max : null,
        inputmode: type === 'decimal' ? 'decimal' : null,
        class: 'lc-input'
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () {
        var raw = control.value;
        onChange(type === 'number' && raw !== '' ? Number(raw) : raw);
      });
      wrapper.appendChild(control);
    }
    if (control && field.help) control.setAttribute('aria-describedby', id + '-help');
    var help = helpNode(field, id);
    if (help) wrapper.appendChild(help);
    return wrapper;
  }

  /* A list of items (people, plans, steps…): each is one compact line that opens to its fields. */
  /** Whether a value is one a `when` lists: a plain value matches itself, and one that starts with ! matches anything but that. */
  function whenMatches(wanted, have) {
    var text = String(have);
    return wanted.some(function (value) {
      return value.charAt(0) === '!' ? text !== value.slice(1) : text === value;
    });
  }

  /** The value a block has for a field, or the field's default while nothing is stored (the shared fields count too). */
  function valueFor(def, block, key) {
    var other = def.fields.concat(def.common || []).filter(function (f) { return f.key === key; })[0];
    return block[key] == null ? (other && other.default != null ? other.default : '') : block[key];
  }

  /** The layouts a block can have now: some belong to one kind of content only (`variant_when`). */
  function variantOptions(def, block, options) {
    var when = def.variant_when || {};
    return (options || []).filter(function (pair) {
      var wanted = when[pair[0]];
      return !wanted || Object.keys(wanted).every(function (key) { return whenMatches(wanted[key], valueFor(def, block, key)); });
    });
  }

  var rowOpen = new WeakMap();

  /* Reordering: a grip on each block and each row (drag it, or Space and the arrow keys), see admin-sortable.js ------------------ */

  var Sortable = window.AdminSortable;
  var HINT_ID = 'bk-sort-hint';

  /** The grip button of an item: it is the handle the sortable lists look for. */
  function grip(name) {
    return el('button', {
      type: 'button',
      class: 'bk-grip',
      'data-grip': '',
      'aria-label': 'Reorder ' + name,
      'aria-describedby': HINT_ID,
      title: 'Drag to reorder',
      html: svg('grip')
    });
  }

  function sayMoved(name, to, total) {
    if (Sortable) Sortable.announce(name + ' moved to position ' + (to + 1) + ' of ' + total);
  }

  /** The "…" menu of a block: a button that opens a short list of actions (arrow keys, Home and End to move, Enter to choose, Escape to close). */
  function moreMenu(name, actions) {
    var open = false;
    var button = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'More actions for ' + name, title: 'More', 'aria-haspopup': 'menu', 'aria-expanded': 'false', html: svg('more'), 'data-action': 'more' });
    var menu = el('div', { class: 'bk-menu', role: 'menu', 'aria-label': 'Actions for ' + name, hidden: true });
    var entries = actions.map(function (action) {
      var item = el('button', { type: 'button', role: 'menuitem', tabindex: '-1', class: 'bk-menu-item', disabled: !!action.disabled, html: svg(action.icon) + '<span>' + action.label + '</span>' });
      item.addEventListener('click', function () { close(false); action.run(); });
      menu.appendChild(item);
      return item;
    });
    function enabled() { return entries.filter(function (b) { return !b.disabled; }); }
    function outside(event) { if (!wrap.contains(event.target)) close(false); }
    function close(refocus) {
      if (!open) return;
      open = false;
      menu.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      document.removeEventListener('pointerdown', outside, true);
      if (refocus) button.focus();
    }
    function show() {
      open = true;
      menu.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      document.addEventListener('pointerdown', outside, true);
      var first = enabled()[0];
      if (first) first.focus();
    }
    button.addEventListener('click', function () { if (open) close(true); else show(); });
    button.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && !open) { event.preventDefault(); show(); }
    });
    menu.addEventListener('keydown', function (event) {
      var list = enabled();
      var at = list.indexOf(document.activeElement);
      var target = null;
      if (event.key === 'ArrowDown') target = list[(at + 1) % list.length];
      else if (event.key === 'ArrowUp') target = list[(at - 1 + list.length) % list.length];
      else if (event.key === 'Home') target = list[0];
      else if (event.key === 'End') target = list[list.length - 1];
      else if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
      else if (event.key === 'Tab') { close(false); return; }
      if (target) { event.preventDefault(); target.focus(); }
    });
    var wrap = el('span', { class: 'bk-menu-wrap' }, [button, menu]);
    return wrap;
  }

  /** A list of one short text (`inline: true`): the texts in a row of boxes, three to a row, each with a button to remove it. */
  function inlineRepeater(field, rows, onChange, id) {
    var item = field.item_label || 'Item';
    var sub = field.fields[0];
    var grid = el('div', { class: 'bk-inline-rows' });
    var wrapper = el('div', { class: 'bk-field bk-rep bk-rep-inline is-wide' }, [
      el('div', { class: 'bk-rep-head' }, [el('span', { class: 'bk-label', text: field.label }), el('span', { class: 'bk-count', text: rows.length + (field.max ? ' / ' + field.max : '') })]),
      grid
    ]);
    function commit() {
      onChange(rows);
      var fresh = inlineRepeater(field, rows, onChange, id);
      wrapper.replaceWith(fresh);
      return fresh;
    }
    rows.forEach(function (row, index) {
      var input = el('input', { type: 'text', class: CLS.input, id: id + '-' + index, value: row[sub.key] == null ? '' : String(row[sub.key]), placeholder: sub.placeholder || '', 'aria-label': item + ' ' + (index + 1) });
      input.addEventListener('input', function () { row[sub.key] = input.value; onChange(rows); });
      var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove ' + item.toLowerCase() + ' ' + (index + 1), html: svg('trash') });
      remove.addEventListener('click', function () { rows.splice(index, 1); commit(); });
      grid.appendChild(el('div', { class: 'bk-inline-item' }, [rows.length > 1 ? grip(item.toLowerCase() + ' ' + (index + 1)) : null, input, remove]));
    });
    if (Sortable && rows.length > 1) {
      Sortable.create({
        container: grid,
        items: '.bk-inline-item',
        handle: '.bk-grip',
        label: function (node, at) { return item + ' ' + (at + 1); },
        onSort: function (info) {
          Sortable.reorder(rows, info.from, info.to);
          var fresh = commit();
          if (info.via === 'keyboard') { var g = fresh.querySelectorAll('.bk-grip')[info.to]; if (g) g.focus(); }
        }
      });
    }
    if (!field.max || rows.length < field.max) {
      var add = el('button', { type: 'button', class: 'bk-add', html: svg('plus') + '<span>Add ' + item.toLowerCase() + '</span>' });
      add.addEventListener('click', function () {
        rows.push(defaultsFor(field.fields));
        var fresh = commit();
        var boxes = fresh.querySelectorAll('input');
        if (boxes.length) boxes[boxes.length - 1].focus();
      });
      grid.appendChild(add);
    }
    return wrapper;
  }

  function repeaterControl(field, rows, onChange, id) {
    if (field.inline) return inlineRepeater(field, rows, onChange, id);
    var item = field.item_label || 'Item';
    var list = el('div', { class: 'bk-rows' });
    var count = el('span', { class: 'bk-count', text: rows.length + (field.max ? ' / ' + field.max : '') });
    var allOpen = rows.length > 0 && rows.every(function (r) { return rowOpen.get(r); });
    var toggleAll = rows.length > 1 ? el('button', { type: 'button', class: 'bk-link', text: allOpen ? 'Collapse all' : 'Expand all' }) : null;
    var wrapper = el('div', { class: 'bk-field bk-rep' + (rows.length ? ' is-wide' : ' is-empty') }, [
      el('div', { class: 'bk-rep-head' }, [el('span', { class: 'bk-label', text: field.label }), count, toggleAll]),
      list
    ]);

    function commit() {
      onChange(rows);
      var fresh = repeaterControl(field, rows, onChange, id);
      wrapper.replaceWith(fresh);
      return fresh;
    }
    if (toggleAll) {
      toggleAll.addEventListener('click', function () {
        rows.forEach(function (r) { rowOpen.set(r, !allOpen); });
        commit();
      });
    }

    rows.forEach(function (row, index) {
      if (!rowOpen.has(row)) rowOpen.set(row, rows.length === 1);
      var open = rowOpen.get(row);
      var rowId = id + '-' + index;
      var texts = [];
      var picture = '';
      var fileName = '';
      (field.fields || []).forEach(function (sub) {
        var v = row[sub.key];
        if (typeof v !== 'string' || v.trim() === '') return;
        if (sub.type === 'image') { if (!picture) picture = v; return; }
        // A file is named by its file name when the row has no title of its own.
        if (sub.type === 'file') { if (!fileName) fileName = decodeURIComponent(v.split('?')[0].split('/').pop() || v); return; }
        if (sub.type === 'select' || sub.type === 'icon' || sub.type === 'link' || sub.type === 'video') return;
        texts.push(v.trim().replace(/\s+/g, ' '));
      });
      var first = (field.fields || [])[0];
      if (fileName && first && (typeof row[first.key] !== 'string' || row[first.key].trim() === '')) texts.unshift(fileName);

      var head = el('button', { type: 'button', class: 'bk-row-toggle', 'data-drag-surface': '', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': rowId + '-body' }, [
        el('span', { class: 'bk-chev' + (open ? ' is-open' : ''), html: svg('chevron', 'h-3.5 w-3.5') }),
        picture ? el('img', { class: 'bk-row-pic', src: picture, alt: '' }) : null,
        el('span', { class: 'bk-row-n', text: String(index + 1) }),
        el('span', { class: 'bk-row-title', text: texts[0] || item }),
        texts[1] ? el('span', { class: 'bk-row-sub', text: texts[1] }) : null
      ]);
      head.addEventListener('click', function () {
        rowOpen.set(row, !open);
        commit();
      });

      var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove ' + item.toLowerCase() + ' ' + (index + 1), html: svg('trash') });
      remove.addEventListener('click', function () {
        rows.splice(index, 1);
        commit();
      });

      var card = el('div', { class: 'bk-row' + (open ? ' is-open' : '') }, [
        el('div', { class: 'bk-row-head' }, [rows.length > 1 ? grip(item.toLowerCase() + ' ' + (index + 1)) : null, head, el('span', { class: 'bk-row-actions' }, [remove])])
      ]);
      if (open) {
        var body = el('div', { id: rowId + '-body', class: 'bk-grid bk-row-body' });
        var conditional = [];
        var apply = function () {
          conditional.forEach(function (entry) {
            var shown = Object.keys(entry.when).every(function (key) {
              var other = (field.fields || []).filter(function (f) { return f.key === key; })[0];
              var have = row[key] == null ? (other && other.default != null ? other.default : '') : row[key];
              return whenMatches(entry.when[key], have);
            });
            entry.node.hidden = !shown;
          });
        };
        (field.fields || []).forEach(function (sub) {
          var node = fieldControl(sub, row[sub.key], function (value) {
            row[sub.key] = value;
            onChange(rows);
            apply();
          }, rowId);
          if (sub.when) conditional.push({ node: node, when: sub.when });
          body.appendChild(node);
        });
        apply();
        card.appendChild(body);
      }
      list.appendChild(card);
    });

    if (Sortable && rows.length > 1) {
      Sortable.create({
        container: list,
        items: '.bk-row',
        handle: '.bk-grip',
        surface: '.bk-row-head',
        surfaceActive: function (node) { return !node.classList.contains('is-open'); },
        ghost: '.bk-row-head',
        label: function (node, at) { return item + ' ' + (at + 1); },
        onSort: function (info) {
          Sortable.reorder(rows, info.from, info.to);
          var fresh = commit();
          if (info.via === 'keyboard') { var g = fresh.querySelectorAll('.bk-row .bk-grip')[info.to]; if (g) g.focus(); }
        }
      });
    }

    var full = field.max && rows.length >= field.max;
    var add = el('button', { type: 'button', class: 'bk-add', disabled: !!full, html: svg('plus') + '<span>Add ' + item.toLowerCase() + '</span>' });
    add.addEventListener('click', function () {
      var fresh = defaultsFor(field.fields);
      rowOpen.set(fresh, true);
      rows.push(fresh);
      var node = commit();
      var rowsNow = node.querySelectorAll('.bk-row');
      var last = rowsNow[rowsNow.length - 1];
      var focusable = last ? last.querySelector('input:not([type=checkbox]), textarea, select') : null;
      if (focusable) focusable.focus();
    });
    if (rows.length === 0) {
      // Nothing yet: the list is one line, with its button beside the name.
      add.className = 'bk-btn';
      wrapper.querySelector('.bk-rep-head').appendChild(add);
      list.remove();
      return wrapper;
    }
    wrapper.appendChild(add);
    return wrapper;
  }

  /* Blocks ------------------------------------------------------------------ */

  var TONE_SWATCH = { 'default': '#ffffff', muted: '#f1f5f9', contrast: '#0f172a', accent: '#2563eb' };

  /** A size with a unit, in one control: preset chips (percentages), a number box and a unit switch. Empty is the whole column. */
  function sizeControl(field, unitField, block, def, idBase, changed) {
    var id = idBase + '-' + field.key;
    var unitOf = function () { return String(valueFor(def, block, unitField.key)) === 'px' ? 'px' : '%'; };
    var wrapper = el('div', { class: 'bk-field is-wide' }, [el('label', { class: 'bk-label', for: id, text: field.label })]);
    var row = el('div', { class: 'bk-size' });
    var chips = el('div', { class: 'bk-choices bk-widths', role: 'group', 'aria-label': field.label });
    var buttons = [];
    var input = el('input', { type: 'number', id: id, class: 'lc-input bk-size-input', step: '1', inputmode: 'numeric' });
    var refresh = function () {
      var unit = unitOf();
      var range = (field.ranges || {})[unit] || [field.min, field.max];
      var size = block[field.key] == null ? '' : block[field.key];
      var percent = size === '' ? 100 : Number(size);
      chips.hidden = unit === 'px';
      buttons.forEach(function (b) { b.setAttribute('aria-pressed', unit === '%' && Number(b.getAttribute('data-value')) === percent ? 'true' : 'false'); });
      input.min = range[0];
      input.max = range[1];
      input.placeholder = unit === 'px' ? 'auto' : '100';
      input.setAttribute('aria-label', field.label + ' (' + unit + ')');
      if (document.activeElement !== input) input.value = size === '' ? '' : String(size);
    };
    var set = function (value) {
      block[field.key] = value;
      serialize();
      refresh();
      changed();
    };
    (field.presets || []).forEach(function (percent) {
      var button = el('button', { type: 'button', 'data-value': String(percent), text: percent + '%' });
      button.addEventListener('click', function () {
        if (unitOf() !== '%') { block[unitField.key] = '%'; unitSeg.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-value') === '%' ? 'true' : 'false'); }); }
        input.value = percent >= 100 ? '' : String(percent);
        set(percent >= 100 ? '' : percent);
      });
      buttons.push(button);
      chips.appendChild(button);
    });
    input.addEventListener('input', function () {
      var number = parseInt(input.value, 10);
      set(input.value === '' || isNaN(number) ? '' : number);
    });
    input.addEventListener('change', function () {
      // Left empty or out of range, the box shows what will be kept.
      var number = parseInt(input.value, 10);
      var range = (field.ranges || {})[unitOf()] || [field.min, field.max];
      if (input.value !== '' && !isNaN(number)) {
        number = Math.max(range[0], Math.min(range[1], number));
        input.value = unitOf() === '%' && number >= 100 ? '' : String(number);
        set(unitOf() === '%' && number >= 100 ? '' : number);
      }
    });
    var unitSeg = choiceButtons(unitField, unitOf(), function (value) {
      block[unitField.key] = value;
      // The number is kept inside the range of the new unit (320 px is the whole column in percent).
      var range = (field.ranges || {})[value] || [field.min, field.max];
      var size = block[field.key] == null || block[field.key] === '' ? '' : Math.max(range[0], Math.min(range[1], Number(block[field.key])));
      if (value === '%' && size !== '' && size >= 100) size = '';
      input.value = size === '' ? '' : String(size);
      set(size);
    }, 'bk-seg');
    unitSeg.setAttribute('aria-label', unitField.label);
    row.appendChild(chips);
    row.appendChild(input);
    row.appendChild(unitSeg);
    wrapper.appendChild(row);
    if (field.help) wrapper.appendChild(el('span', { class: 'bk-help', text: field.help }));
    refresh();
    return wrapper;
  }

  function designPanel(block, def, idBase, inColumn) {
    var panel = el('div', { class: 'bk-design', role: 'tabpanel' });
    var field = function (key) { return def.common.filter(function (f) { return f.key === key; })[0]; };
    var change = function (key) {
      return function (value) {
        block[key] = value;
        serialize();
        if (key === 'hidden' || key === 'variant') render(block._id, 'keep');
      };
    };
    var variant = field('variant');
    if (variant) variant = Object.assign({}, variant, { options: variantOptions(def, block, variant.options) });
    if (variant && variant.options.length > 1) {
      var layout = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', text: variant.label })]);
      var previews = def.variant_previews || {};
      if (Object.keys(previews).length) {
        // Each layout is drawn (variants/<layout>.svg of the block); the drawing follows the design fields that change the layout (<layout>-<value>.svg).
        var drawing = function (value) {
          var name = String(value);
          (def.design_fields || []).forEach(function (key) {
            var chosen = valueFor(def, block, key);
            if (previews[name + '-' + chosen]) name = name + '-' + chosen;
          });
          return previews[name] || PREVIEW_FALLBACK;
        };
        layout.appendChild(choiceButtons(variant, block.variant == null ? variant['default'] : block.variant, change('variant'), 'bk-choices bk-vtiles', function (button, pair) {
          button.appendChild(thumb(drawing(pair[0])));
          button.appendChild(el('span', { text: pair[1] }));
        }));
      } else {
        layout.appendChild(choiceButtons(variant, block.variant == null ? variant['default'] : block.variant, change('variant'), 'bk-choices'));
      }
      panel.appendChild(layout);
    }
    // Fields of the block that change how its layout looks (the hero's text alignment), beside the layout; they show while their `when` holds.
    var beside = (def.design_fields || []).map(function (key) { return def.fields.filter(function (f) { return f.key === key; })[0]; }).filter(function (f) {
      if (!f) return false;
      return !f.when || Object.keys(f.when).every(function (other) { return whenMatches(f.when[other], valueFor(def, block, other)); });
    });
    if (beside.length) {
      var besideRow = el('div', { class: 'bk-grid' });
      // A size with a unit (the picture width) takes its unit field into one control; a field with `needs_size` shows only while that size is narrower than the column.
      var unitKeys = beside.map(function (f) { return f.units; }).filter(Boolean);
      var dependents = [];
      var narrower = function (key) {
        var sizeField = def.fields.filter(function (f) { return f.key === key; })[0];
        var size = block[key];
        if (size === '' || size == null || isNaN(Number(size))) return false;
        return valueFor(def, block, sizeField && sizeField.units) === 'px' || Number(size) < 100;
      };
      var showDependents = function () {
        dependents.forEach(function (entry) { entry.node.hidden = !narrower(entry.key); });
      };
      beside.forEach(function (f) {
        if (unitKeys.indexOf(f.key) !== -1) return;
        var node;
        if (f.units) {
          var unitField = def.fields.filter(function (u) { return u.key === f.units; })[0];
          node = unitField ? sizeControl(f, unitField, block, def, idBase, showDependents) : null;
        }
        if (!node) {
          node = fieldControl(Object.assign({}, f, { span: '' }), block[f.key], function (value) {
            block[f.key] = value;
            serialize();
            render(block._id, 'keep');
          }, idBase);
        }
        if (f.needs_size) dependents.push({ key: f.needs_size, node: node });
        besideRow.appendChild(node);
      });
      showDependents();
      panel.appendChild(besideRow);
    }
    var row = el('div', { class: 'bk-grid' });
    var tone = field('tone');
    if (tone) {
      var swatches = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: tone.label })]);
      swatches.appendChild(choiceButtons(tone, block.tone == null ? tone['default'] : block.tone, change('tone'), 'bk-swatches', function (button, pair) {
        button.appendChild(el('i', { style: 'background:' + (TONE_SWATCH[pair[0]] || '#fff'), 'aria-hidden': 'true' }));
        button.appendChild(el('span', { text: pair[1] }));
      }));
      row.appendChild(swatches);
    }
    // How much of the row the block takes; under 100% it sits beside the next blocks.
    var width = inColumn ? null : field('width');
    if (width) {
      var chips = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: width.label })]);
      chips.appendChild(choiceButtons(width, block.width == null ? width['default'] : block.width, function (value) {
        block.width = value;
        serialize();
        render(block._id, 'keep');
      }, 'bk-choices bk-widths'));
      if (width.help) chips.appendChild(el('span', { class: 'bk-help', text: width.help }));
      row.appendChild(chips);
    }
    var spacing = field('spacing');
    if (spacing) row.appendChild(fieldControl(Object.assign({}, spacing, { span: '' }), block.spacing == null ? spacing['default'] : block.spacing, change('spacing'), idBase));
    ['anchor', 'hidden'].forEach(function (key) {
      var f = field(key);
      if (f) row.appendChild(fieldControl(Object.assign({}, f, { span: '' }), block[key], change(key), idBase));
    });
    def.common.forEach(function (f) {
      if (['variant', 'tone', 'width', 'spacing', 'anchor', 'hidden'].indexOf(f.key) === -1) row.appendChild(fieldControl(Object.assign({}, f, { span: '' }), block[f.key], change(f.key), idBase));
    });
    panel.appendChild(row);
    return panel;
  }

  function contentPanel(block, def, idBase) {
    var panel = el('div', { class: 'bk-content', role: 'tabpanel' });
    if (def.description) panel.appendChild(el('p', { class: 'bk-desc', text: def.description }));
    if (!def.fields.length) return panel;
    var fields = el('div', { class: 'bk-grid' });
    // A field with `when: {other: value}` shows only while the other field has one of those values.
    var conditional = [];
    var applyWhen = function () {
      conditional.forEach(function (entry) {
        var shown = Object.keys(entry.when).every(function (key) {
          // The other field can be one of the block's own or a shared one (the layout), whose default counts while nothing is stored.
          var other = def.fields.concat(def.common || []).filter(function (f) { return f.key === key; })[0];
          var have = block[key] == null ? (other && other.default != null ? other.default : '') : block[key];
          return whenMatches(entry.when[key], have);
        });
        entry.node.hidden = !shown;
      });
    };
    def.fields.forEach(function (field) {
      if ((def.design_fields || []).indexOf(field.key) !== -1) return;
      var shown = field;
      var current = block[field.key];
      // A choice that fits only with some other value (`options_when`): the others are not offered, and a saved one that no longer fits shows the default.
      if (field.options_when) {
        shown = Object.assign({}, field, { options: field.options.filter(function (pair) {
          var wanted = field.options_when[pair[0]];
          return !wanted || Object.keys(wanted).every(function (key) { return whenMatches(wanted[key], valueFor(def, block, key)); });
        }) });
        if (!shown.options.some(function (pair) { return pair[0] === String(current); })) current = field['default'];
      }
      var node = fieldControl(shown, current, function (value) {
        block[field.key] = value;
        serialize();
        applyWhen();
        // A field that decides which layouts fit (what a map shows): a layout that no longer fits gives way to the first, and the card is drawn again.
        var decides = Object.keys(def.variant_when || {}).some(function (name) { return Object.keys(def.variant_when[name]).indexOf(field.key) !== -1; });
        if (decides) {
          var allowed = variantOptions(def, block, (def.common.filter(function (f) { return f.key === 'variant'; })[0] || {}).options).map(function (pair) { return pair[0]; });
          if (allowed.indexOf(String(valueFor(def, block, 'variant'))) === -1) delete block.variant;
          serialize();
          render(block._id, 'keep');
        }
      }, idBase);
      if (field.when) conditional.push({ node: node, when: field.when });
      fields.appendChild(node);
    });
    applyWhen();
    panel.appendChild(fields);
    return panel;
  }

  /**
   * A block as a card. `where` says which list it is in: {list: the array, path: its place in the editor ('2' or '2.0.1'), column: the
   * column object when it is inside a container}. Inside a column the block takes the room of the column, so its own Width is not offered.
   */
  function renderBlock(block, index, where) {
    var def = definitions[block.type];
    var idBase = 'blk-' + block._id;
    var list = where.list;
    var inColumn = !!where.column;
    var card = el('section', { class: 'bk-card' + (block.hidden ? ' is-hidden' : '') + (block._open ? ' is-open' : ''), 'data-block-card': block._id, 'data-block-path': where.path });

    var label = def ? def.label : 'Unknown block: ' + block.type;
    var summary = def ? summaryOf(block) : 'Kept as is. Its definition is not installed.';
    var toggle = el('button', {
      type: 'button',
      class: 'bk-toggle',
      'data-drag-surface': '',
      'aria-expanded': block._open ? 'true' : 'false',
      'aria-controls': idBase + '-body',
      html: '<span class="bk-chev' + (block._open ? ' is-open' : '') + '">' + svg('chevron', 'h-3.5 w-3.5') + '</span>'
    });
    if (def) toggle.appendChild(el('span', { class: 'bk-mini', html: '<svg viewBox="0 0 64 48" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + (def.preview || PREVIEW_FALLBACK) + '</svg>' }));
    toggle.appendChild(el('span', { class: 'bk-name', text: (index + 1) + '. ' + label }));
    if (def && block.variant && def.common) {
      var variantField = def.common.filter(function (f) { return f.key === 'variant'; })[0];
      if (variantField && variantField.options.length > 1) {
        toggle.appendChild(el('span', { class: 'bk-tag', text: optionLabel(variantField, block.variant) }));
      }
    }
    var share = inColumn ? 100 : widthOf(block);
    if (def && share < 100) toggle.appendChild(el('span', { class: 'bk-tag bk-width-tag', title: 'Width: ' + share + '% of the row', text: share + '%' }));
    if (block.hidden) {
      toggle.appendChild(el('span', { class: 'bk-tag is-warn', html: svg('eyeOff', 'h-3 w-3') + 'Hidden' }));
    }
    if (summary) toggle.appendChild(el('span', { class: 'bk-summary', text: summary }));
    toggle.addEventListener('click', function () {
      block._open = !block._open;
      render(block._id, 'toggle');
    });

    var actions = el('div', { class: 'bk-actions' });
    var eye = el('button', { type: 'button', class: CLS.icon, 'aria-label': block.hidden ? 'Show block on the page' : 'Hide block from the page', title: block.hidden ? 'Show on the page' : 'Hide from the page', 'aria-pressed': block.hidden ? 'true' : 'false', html: svg(block.hidden ? 'eyeOff' : 'eye'), disabled: !def, 'data-action': 'hide' });
    var actionsMenu = [
      { label: 'Move to top', icon: 'top', disabled: index === 0, run: function () { move(list, index, 0, 'more'); sayMoved('Block ' + label, 0, list.length); } },
      { label: 'Move to bottom', icon: 'bottom', disabled: index === list.length - 1, run: function () { move(list, index, list.length - 1, 'more'); sayMoved('Block ' + label, list.length - 1, list.length); } }
    ];
    if (containerDef && def) {
      actionsMenu.push(inColumn
        ? { label: 'Move out of the container', icon: 'out', run: function () { moveOut(block, where); } }
        : { label: 'Wrap in container', icon: 'wrap', run: function () { wrap(block, index); } });
    }
    var more = moreMenu(label, actionsMenu);
    var insert = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Add a block below', title: 'Add a block below', html: svg('plus'), 'data-action': 'insert' });
    var duplicate = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Duplicate block', title: 'Duplicate', html: svg('copy'), disabled: !def, 'data-action': 'duplicate' });
    var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove block', title: 'Remove', html: svg('trash'), 'data-action': 'remove' });
    eye.addEventListener('click', function () {
      block.hidden = !block.hidden;
      render(block._id, 'keep');
    });
    insert.addEventListener('click', function () { openPicker(index + 1, insert, list, where.column); });
    duplicate.addEventListener('click', function () {
      var copy = withId(block, true);
      list.splice(index + 1, 0, copy);
      render(copy._id, 'toggle');
    });
    remove.addEventListener('click', function () {
      lastRemoved = { block: block, index: index, list: list };
      list.splice(index, 1);
      render(null);
      showUndo(label);
    });
    [eye, insert, duplicate, more, remove].forEach(function (b) { actions.appendChild(b); });

    var select = inColumn ? null : el('input', { type: 'checkbox', class: 'bk-select', 'aria-label': 'Select ' + label + ' to save as a section', title: 'Select to save as a section', checked: !!selected[block._id], disabled: !def });
    if (select) {
      select.addEventListener('change', function () {
        if (select.checked) selected[block._id] = true;
        else delete selected[block._id];
        syncSaveBar();
      });
    }
    // A list of one has no grip, except in a column: a block there can still be dragged to another column or out of the container.
    var gripNode = inColumn || list.length > 1 || (!inColumn && hasContainers()) ? grip('block ' + label) : null;
    card.appendChild(el('div', { class: 'bk-head' }, [gripNode, select, toggle, actions]));

    if (block._open && def) {
      var body = el('div', { id: idBase + '-body', class: 'bk-body' });
      var panels = { content: contentPanel(block, def, idBase), design: designPanel(block, def, idBase, inColumn) };
      var tabs = el('div', { class: 'bk-tabs', role: 'tablist' });
      var show = function (name) {
        block._tab = name;
        Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== name; });
        Array.prototype.forEach.call(tabs.children, function (b) {
          b.setAttribute('aria-selected', b.getAttribute('data-tab') === name ? 'true' : 'false');
          b.tabIndex = b.getAttribute('data-tab') === name ? 0 : -1;
        });
      };
      [['content', 'Content'], ['design', 'Design']].forEach(function (pair) {
        var tab = el('button', { type: 'button', role: 'tab', 'data-tab': pair[0], text: pair[1], 'aria-controls': idBase + '-' + pair[0] });
        tab.addEventListener('click', function () { show(pair[0]); });
        tab.addEventListener('keydown', function (event) {
          if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            var other = pair[0] === 'content' ? 'design' : 'content';
            show(other);
            tabs.querySelector('[data-tab="' + other + '"]').focus();
          }
        });
        tabs.appendChild(tab);
      });
      panels.content.id = idBase + '-content';
      panels.design.id = idBase + '-design';
      body.appendChild(tabs);
      body.appendChild(panels.content);
      body.appendChild(panels.design);
      show(block._tab === 'design' ? 'design' : 'content');
      card.appendChild(body);
    }
    return card;
  }

  function move(list, from, to, focusWhat) {
    if (to < 0 || to >= list.length || from === to) return;
    var block = list.splice(from, 1)[0];
    list.splice(to, 0, block);
    render(block._id, focusWhat);
  }

  /** Puts a block in a container of its own (one column), where it was. */
  function wrap(block, index) {
    var container = prepare({ type: 'container', settings: {}, columns: [{ settings: {}, blocks: [] }] }, true);
    container.columns[0].blocks.push(block);
    blocks.splice(index, 1, container);
    block._open = false;
    render(container._id, 'toggle');
    var name = definitions[block.type] ? definitions[block.type].label : block.type;
    if (Sortable) Sortable.announce('Block ' + name + ' wrapped in a new container at position ' + (index + 1) + ' of ' + blocks.length + '.');
  }

  /** Takes a container apart: its blocks go to the top level in order (column by column), where the container was. */
  function unwrap(container, index) {
    var inside = [];
    container.columns.forEach(function (column) { column.blocks.forEach(function (inner) { inside.push(inner); }); });
    Array.prototype.splice.apply(blocks, [index, 1].concat(inside));
    render(inside.length ? inside[0]._id : null, 'toggle');
    if (Sortable) Sortable.announce('Container unwrapped: ' + inside.length + (inside.length === 1 ? ' block is' : ' blocks are') + ' now at the top level.');
  }

  /** A block of a column goes to the top level, right after its container. */
  function moveOut(block, where) {
    var at = blocks.indexOf(where.container);
    var from = where.list.indexOf(block);
    if (at < 0 || from < 0) return;
    where.list.splice(from, 1);
    blocks.splice(at + 1, 0, block);
    render(block._id, 'more');
    var name = definitions[block.type] ? definitions[block.type].label : block.type;
    if (Sortable) Sortable.announce('Block ' + name + ' moved out of the container, to the top level at position ' + (at + 2) + ' of ' + blocks.length + '.');
  }

  /* Containers: a row of columns that hold blocks ------------------------------------------------- */

  var ui = new WeakMap(); // what the Design tab of a container remembers while it is open: the screen, the column, the advanced part
  var SPACE_LABELS = { none: '0', xs: 'XS', s: 'S', m: 'M', l: 'L', xl: 'XL' };
  var STACK_ICON_ORDER = ['desktop', 'tablet', 'phone'];

  function uiOf(container) {
    if (!ui.has(container)) ui.set(container, { screen: 'desktop', column: 0, advanced: false });
    return ui.get(container);
  }

  function settingDef(key) {
    return containerDef.settings.filter(function (f) { return f.key === key; })[0];
  }

  function columnDef(key) {
    return containerDef.columns.filter(function (f) { return f.key === key; })[0];
  }

  /** What a setting is now: the stored value, or the field's default while nothing is stored. */
  function settingValue(holder, def) {
    return holder.settings[def.key] == null ? def['default'] : holder.settings[def.key];
  }

  /** The share of the row a column asks for, as a weight for drawing it (a column without one takes an equal share). */
  function columnWeight(column, count) {
    var width = column.settings.width || 'fill';
    var m = /^(\d)\/(\d)$/.exec(width);
    if (m) return Number(m[1]) / Number(m[2]);
    if (/%$/.test(width)) return Math.max(0.05, parseFloat(width) / 100);
    return 1 / count;
  }

  function widthLabel(column) {
    var width = column.settings.width || 'fill';
    return width === 'fill' ? 'Fill' : (width === 'auto' ? 'Auto' : width);
  }

  function containerLabel(container) {
    var blocksInside = 0;
    container.columns.forEach(function (column) { blocksInside += column.blocks.length; });
    return container.columns.length + (container.columns.length === 1 ? ' column' : ' columns') + ' · ' + blocksInside + (blocksInside === 1 ? ' block' : ' blocks');
  }

  /** The bars of a preset (or of the columns now): each as wide as its share. */
  function bars(widths) {
    var weights = widths.map(function (w) {
      var m = /^(\d)\/(\d)$/.exec(w);
      return m ? Number(m[1]) / Number(m[2]) : 1 / widths.length;
    });
    var node = el('span', { class: 'bk-bars', 'aria-hidden': 'true' });
    weights.forEach(function (weight) { node.appendChild(el('i', { style: 'flex-grow:' + Math.round(weight * 1000) })); });
    return node;
  }

  function removeColumn(container, index) {
    if (container.columns.length < 2) return;
    var gone = container.columns.splice(index, 1)[0];
    // Its blocks are not lost: they go to the end of the column before it (or the one after, for the first).
    var keep = container.columns[Math.max(0, index - 1)];
    if (index === 0) Array.prototype.unshift.apply(keep.blocks, gone.blocks); else gone.blocks.forEach(function (inner) { keep.blocks.push(inner); });
    var state = uiOf(container);
    state.column = Math.min(state.column, container.columns.length - 1);
    if (Sortable) Sortable.announce('Column ' + (index + 1) + ' removed' + (gone.blocks.length ? '; its blocks moved to column ' + (container.columns.indexOf(keep) + 1) : '') + '. ' + container.columns.length + ' columns left.');
  }

  function addColumn(container) {
    if (container.columns.length >= MAX_COLUMNS) return;
    var column = { settings: {}, blocks: [] };
    Object.defineProperty(column, '_id', { value: nextId++, enumerable: false, writable: true });
    container.columns.push(column);
    if (Sortable) Sortable.announce('Column added. ' + container.columns.length + ' columns.');
  }

  /** A ready-made layout: the number of columns and the width of each. The blocks of columns that go are kept, in the last column. */
  function applyPreset(container, widths) {
    while (container.columns.length < widths.length) addColumn(container);
    while (container.columns.length > widths.length) removeColumn(container, container.columns.length - 1);
    container.columns.forEach(function (column, c) {
      if (widths[c]) column.settings.width = widths[c]; else delete column.settings.width;
    });
  }

  function matchesPreset(container, widths) {
    return container.columns.length === widths.length && container.columns.every(function (column, c) { return (column.settings.width || '') === (widths[c] || ''); });
  }

  /** A choice as a row of buttons, whatever the number of options; `short` gives the text on a button, the full label is its name. */
  function chipsField(def, value, onChange, short) {
    var wrapper = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: def.label })]);
    var group = choiceButtons(def, value, onChange, 'bk-seg', function (button, pair) {
      button.textContent = short && short[pair[0]] ? short[pair[0]] : pair[1];
      if (short && short[pair[0]]) button.setAttribute('aria-label', pair[1]);
    });
    wrapper.appendChild(group);
    if (def.help) wrapper.appendChild(el('span', { class: 'bk-help', text: def.help }));
    return wrapper;
  }

  /** A column's width: a kind (fill, auto, a fraction, a percentage, a fixed size) and, for the last three, the value. */
  function widthControl(column, idBase, changed) {
    var current = column.settings.width || 'fill';
    var kind = current === 'fill' || current === 'auto' ? current : (/^\d\/\d$/.test(current) ? 'fraction' : (/%$/.test(current) ? 'percent' : 'fixed'));
    var FRACTIONS = ['1/2', '1/3', '2/3', '1/4', '3/4', '1/5', '2/5', '3/5', '4/5', '1/6', '5/6'];
    var wrapper = el('div', { class: 'bk-field is-wide bk-widthctl' }, [el('span', { class: 'bk-label', id: idBase + '-wlabel', text: 'Width' })]);
    var kinds = [['fill', 'Fill'], ['auto', 'Auto'], ['fraction', 'Fraction'], ['percent', 'Percent'], ['fixed', 'Fixed']];
    var row = el('div', { class: 'bk-widthrow' });
    var kindSelect = el('select', { class: 'lc-input lc-select', id: idBase + '-wkind', 'aria-labelledby': idBase + '-wlabel' });
    kinds.forEach(function (pair) { kindSelect.appendChild(el('option', { value: pair[0], text: pair[1], selected: pair[0] === kind })); });
    kindSelect.addEventListener('change', function () {
      var next = kindSelect.value;
      var value = { fill: 'fill', auto: 'auto', fraction: '1/2', percent: '50%', fixed: '20rem' }[next];
      if (value === 'fill') delete column.settings.width; else column.settings.width = value;
      changed(true);
    });
    row.appendChild(kindSelect);
    if (kind === 'fraction') {
      var fractions = el('select', { class: 'lc-input lc-select', id: idBase + '-wvalue', 'aria-label': 'Fraction of the row' });
      FRACTIONS.forEach(function (f) { fractions.appendChild(el('option', { value: f, text: f, selected: f === current })); });
      fractions.addEventListener('change', function () { column.settings.width = fractions.value; changed(false); });
      row.appendChild(fractions);
    } else if (kind === 'percent') {
      var percent = el('input', { type: 'number', class: 'lc-input', id: idBase + '-wvalue', min: '5', max: '100', step: '1', 'aria-label': 'Percent of the row', value: String(parseFloat(current) || 50) });
      percent.addEventListener('input', function () {
        var n = Number(percent.value);
        if (n >= 5 && n <= 100) { column.settings.width = n + '%'; changed(false); }
      });
      row.appendChild(percent);
    } else if (kind === 'fixed') {
      var size = el('input', { type: 'number', class: 'lc-input', id: idBase + '-wvalue', min: '1', step: 'any', 'aria-label': 'Size', value: String(parseFloat(current) || 20) });
      var unit = el('select', { class: 'lc-input lc-select', id: idBase + '-wunit', 'aria-label': 'Unit' });
      var have = (/[a-z]+$/.exec(current) || ['rem'])[0];
      ['rem', 'px', 'em', 'ch'].forEach(function (u) { unit.appendChild(el('option', { value: u, text: u, selected: u === have })); });
      var write = function () {
        var n = Number(size.value);
        if (n > 0) { column.settings.width = n + unit.value; changed(false); }
      };
      size.addEventListener('input', write);
      unit.addEventListener('change', write);
      row.appendChild(size);
      row.appendChild(unit);
    }
    wrapper.appendChild(row);
    return wrapper;
  }

  function textSetting(def, holder, idBase, changed) {
    return fieldControl(Object.assign({}, def, { span: '' }), holder.settings[def.key], function (value) {
      if (value === '' || value == null) delete holder.settings[def.key]; else holder.settings[def.key] = value;
      changed(false);
    }, idBase);
  }

  function valueSetting(def, holder, idBase, changed, redraw) {
    return fieldControl(Object.assign({}, def, { span: '' }), settingValue(holder, def), function (value) {
      holder.settings[def.key] = value;
      changed(!!redraw);
    }, idBase);
  }

  var groupCount = 0;

  function group(title, children, note) {
    var titleId = 'bk-dg-' + (++groupCount);
    var node = el('div', { class: 'bk-dgroup', role: 'group', 'aria-labelledby': titleId }, [el('p', { class: 'bk-dtitle', id: titleId, text: title })]);
    if (note) node.appendChild(el('p', { class: 'bk-help', text: note }));
    var grid = el('div', { class: 'bk-grid' });
    children.forEach(function (child) { if (child) grid.appendChild(child); });
    node.appendChild(grid);
    return node;
  }

  /** A small picture of how the columns sit on a screen: `columns` boxes in a row, in halves or one above the other. */
  function stackDrawing(mode, count) {
    var per = mode === 'one' ? 1 : (mode === 'two' ? 2 : count);
    var node = el('span', { class: 'bk-stackdraw', 'aria-hidden': 'true', style: 'grid-template-columns:repeat(' + per + ',1fr)' });
    for (var i = 0; i < count; i++) node.appendChild(el('i'));
    return node;
  }

  /** The Design tab of a container. `redrawAll` draws the columns again too; `changed(redraw)` saves and, when asked, draws this tab again. */
  function containerDesign(container, idBase) {
    var state = uiOf(container);
    var host = el('div', { class: 'bk-design bk-cdesign', role: 'tabpanel' });
    var changed = function (redraw) {
      serialize();
      if (redraw) draw();
    };
    function draw() {
      var focusId = document.activeElement && host.contains(document.activeElement) ? document.activeElement.id : '';
      host.innerHTML = '';
      build();
      var again = focusId ? host.querySelector('#' + focusId) : null;
      if (again) again.focus();
    }
    function build() {
      var count = container.columns.length;

      // Columns: how many, and a ready-made layout.
      var countRow = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', id: idBase + '-count-label', text: 'Columns' })]);
      var minus = el('button', { type: 'button', class: CLS.icon, id: idBase + '-minus', 'aria-label': 'Remove the last column', title: 'Remove the last column', disabled: count <= 1, html: svg('minus') });
      var plus = el('button', { type: 'button', class: CLS.icon, id: idBase + '-plus', 'aria-label': 'Add a column', title: 'Add a column', disabled: count >= MAX_COLUMNS, html: svg('plus') });
      minus.addEventListener('click', function () { removeColumn(container, container.columns.length - 1); changed(true); });
      plus.addEventListener('click', function () { addColumn(container); changed(true); });
      countRow.appendChild(el('div', { class: 'bk-stepper', role: 'group', 'aria-labelledby': idBase + '-count-label' }, [minus, el('output', { class: 'bk-count', 'aria-live': 'polite', text: String(count) }), plus]));
      var presetsRow = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', id: idBase + '-presets-label', text: 'Layouts' })]);
      var chips = el('div', { class: 'bk-presets', role: 'group', 'aria-labelledby': idBase + '-presets-label' });
      containerDef.presets.forEach(function (preset) {
        var chip = el('button', { type: 'button', class: 'bk-preset', 'aria-pressed': matchesPreset(container, preset.widths) ? 'true' : 'false' }, [bars(preset.widths), el('span', { text: preset.label })]);
        chip.addEventListener('click', function () { applyPreset(container, preset.widths); changed(true); });
        chips.appendChild(chip);
      });
      presetsRow.appendChild(chips);
      host.appendChild(group('Columns', [countRow, presetsRow]));

      // The column being edited.
      var pick = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', id: idBase + '-pick-label', text: 'Column' })]);
      var picker = el('div', { class: 'bk-seg', role: 'group', 'aria-labelledby': idBase + '-pick-label' });
      container.columns.forEach(function (column, c) {
        var b = el('button', { type: 'button', 'aria-pressed': c === state.column ? 'true' : 'false', text: String(c + 1), 'aria-label': 'Column ' + (c + 1) + ', width ' + widthLabel(column) });
        b.addEventListener('click', function () { state.column = c; draw(); });
        picker.appendChild(b);
      });
      pick.appendChild(picker);
      var column = container.columns[Math.min(state.column, count - 1)];
      var cid = idBase + '-c' + container.columns.indexOf(column);
      var colChanged = function (redraw) { changed(redraw); };
      var minmax = el('div', { class: 'bk-field is-wide bk-minmax' }, [
        textSetting(columnDef('min_width'), column, cid, colChanged),
        textSetting(columnDef('max_width'), column, cid, colChanged)
      ]);
      var advanced = el('details', { class: 'bk-advanced', open: state.advanced });
      advanced.appendChild(el('summary', { text: 'Advanced: grow, shrink and basis' }));
      advanced.addEventListener('toggle', function () { state.advanced = advanced.open; });
      advanced.appendChild(el('p', { class: 'bk-help', text: 'When one of these is set it replaces the width (flex: grow shrink basis).' }));
      var advGrid = el('div', { class: 'bk-grid' });
      ['grow', 'shrink', 'basis'].forEach(function (key) { advGrid.appendChild(textSetting(columnDef(key), column, cid, colChanged)); });
      advanced.appendChild(advGrid);
      var colTone = columnDef('tone');
      var toneField = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: 'Column ' + colTone.label.toLowerCase() })]);
      toneField.appendChild(choiceButtons(colTone, settingValue(column, colTone), function (value) { column.settings.tone = value; changed(false); }, 'bk-swatches', function (button, pair) {
        button.appendChild(el('i', { style: 'background:' + (TONE_SWATCH[pair[0]] || '#fff'), 'aria-hidden': 'true' }));
        button.appendChild(el('span', { text: pair[1] }));
      }));
      host.appendChild(group('Column ' + (container.columns.indexOf(column) + 1) + ' of ' + count, [
        pick,
        widthControl(column, cid, colChanged),
        minmax,
        valueSetting(columnDef('align_self'), column, cid, colChanged),
        chipsField(columnDef('valign'), settingValue(column, columnDef('valign')), function (value) { column.settings.valign = value; changed(false); }),
        valueSetting(columnDef('order'), column, cid, colChanged),
        chipsField(columnDef('padding'), settingValue(column, columnDef('padding')), function (value) { column.settings.padding = value; changed(false); }, SPACE_LABELS),
        toneField,
        chipsField(columnDef('radius'), settingValue(column, columnDef('radius')), function (value) { column.settings.radius = value; changed(false); }),
        advanced
      ]));

      // Direction and alignment.
      var d = function (key) { return settingDef(key); };
      host.appendChild(group('Direction and alignment', [
        chipsField(d('direction'), settingValue(container, d('direction')), function (value) { container.settings.direction = value; changed(false); }),
        chipsField(d('wrap'), settingValue(container, d('wrap')), function (value) { container.settings.wrap = value; changed(false); }),
        valueSetting(d('justify'), container, idBase, changed),
        valueSetting(d('align'), container, idBase, changed),
        valueSetting(d('align_content'), container, idBase, changed)
      ]));

      // Gaps from the theme's spacing steps.
      host.appendChild(group('Gaps', [
        chipsField(d('gap'), settingValue(container, d('gap')), function (value) { container.settings.gap = value; changed(false); }, SPACE_LABELS),
        chipsField(d('row_gap'), settingValue(container, d('row_gap')), function (value) { container.settings.row_gap = value; changed(false); }, SPACE_LABELS)
      ]));

      // How the columns stack on each screen.
      var screenBar = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', id: idBase + '-screen-label', text: 'Screen' })]);
      var screens = el('div', { class: 'bk-seg', role: 'group', 'aria-labelledby': idBase + '-screen-label' });
      [['desktop', 'Desktop'], ['tablet', 'Tablet'], ['phone', 'Phone']].forEach(function (pair) {
        var b = el('button', { type: 'button', 'aria-pressed': state.screen === pair[0] ? 'true' : 'false', text: pair[1] });
        b.addEventListener('click', function () { state.screen = pair[0]; draw(); });
        screens.appendChild(b);
      });
      screenBar.appendChild(screens);
      var stackDef = Object.assign({}, d('stack_' + state.screen), { label: 'On a ' + state.screen + ' the columns' });
      var stackNow = settingValue(container, d('stack_' + state.screen));
      var stackField = chipsField(stackDef, stackNow, function (value) { container.settings['stack_' + state.screen] = value; changed(true); });
      var shown = stackNow === 'auto' ? (count <= 2 ? 'row' : 'two') : stackNow;
      stackField.appendChild(stackDrawing(shown, count));
      var responsive = [screenBar, stackField];
      if (state.screen === 'phone') {
        responsive.push(fieldControl(Object.assign({}, d('reverse_phone'), { span: '' }), !!container.settings.reverse_phone, function (value) { container.settings.reverse_phone = value; changed(false); }, idBase));
      }
      host.appendChild(group('Responsive', responsive));

      // The section itself.
      var tone = d('tone');
      var swatches = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: tone.label })]);
      swatches.appendChild(choiceButtons(tone, settingValue(container, tone), function (value) { container.settings.tone = value; changed(false); }, 'bk-swatches', function (button, pair) {
        button.appendChild(el('i', { style: 'background:' + (TONE_SWATCH[pair[0]] || '#fff'), 'aria-hidden': 'true' }));
        button.appendChild(el('span', { text: pair[1] }));
      }));
      var picture = d('image');
      var pictureField = fieldControl(Object.assign({}, picture, { span: 'full' }), container.settings.image, function (value) {
        if (value === '') delete container.settings.image; else container.settings.image = value;
        changed(true);
      }, idBase);
      var section = [swatches, pictureField];
      if (container.settings.image) section.push(chipsField(d('overlay'), settingValue(container, d('overlay')), function (value) { container.settings.overlay = value; changed(false); }));
      section.push(
        chipsField(d('inner'), settingValue(container, d('inner')), function (value) { container.settings.inner = value; changed(false); }),
        valueSetting(d('spacing'), container, idBase, changed),
        valueSetting(d('min_height'), container, idBase, changed),
        textSetting(d('anchor'), container, idBase, changed),
        textSetting(d('class'), container, idBase, changed),
        fieldControl(Object.assign({}, d('hidden'), { span: '' }), !!container.settings.hidden, function (value) {
          container.settings.hidden = value;
          serialize();
          render(container._id, 'keep');
        }, idBase)
      );
      host.appendChild(group('Container', section));
    }
    build();
    return host;
  }

  /** The Columns tab: the columns side by side, each a list of blocks that can be dragged between columns and out of the container. */
  function containerColumns(container, index) {
    var host = el('div', { class: 'bk-cols', role: 'group', 'aria-label': 'Columns of the container' });
    var count = container.columns.length;
    var ordinal = blocks.filter(isContainer).indexOf(container) + 1;
    container.columns.forEach(function (column, c) {
      var area = el('div', { class: 'bk-col-list' });
      column.blocks.forEach(function (inner, k) {
        area.appendChild(renderBlock(inner, k, { list: column.blocks, path: index + '.' + c + '.' + k, column: column, container: container }));
      });
      var add = el('button', { type: 'button', class: 'bk-add', html: svg('plus') + '<span>Add block</span>', 'aria-label': 'Add a block to column ' + (c + 1) });
      add.addEventListener('click', function () { openPicker(column.blocks.length, add, column.blocks, column); });
      var empty = el('p', { class: 'bk-col-empty', text: 'Drop blocks here' });
      empty.hidden = column.blocks.length > 0;
      area.appendChild(empty);
      var remove = count > 1 ? el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove column ' + (c + 1), title: 'Remove column (its blocks move to the previous one)', html: svg('trash') }) : null;
      if (remove) {
        remove.addEventListener('click', function () { removeColumn(container, c); render(container._id, 'keep'); });
      }
      var head = el('div', { class: 'bk-col-head' }, [
        el('span', { class: 'bk-col-title', text: 'Column ' + (c + 1) }),
        el('span', { class: 'bk-tag', title: 'Width', text: widthLabel(column) }),
        remove
      ]);
      var node = el('div', { class: 'bk-col', style: 'flex:' + Math.round(columnWeight(column, count) * 1000) + ' 1 0', 'data-column': c }, [head, area, add]);
      host.appendChild(node);
      if (Sortable) {
        // The whole column is the list (its head and its Add button too), so a block can be dropped anywhere on it.
        Sortable.create({
          container: node,
          items: '.bk-card',
          handle: '.bk-grip',
          surface: '.bk-head',
          surfaceActive: function (node) { return !node.classList.contains('is-open'); },
          ghost: '.bk-head',
          group: 'blocks',
          meta: { get: function () { return column.blocks; } },
          // A container is a top-level thing: it is not dropped into a column.
          accept: function (item) { return !item.classList.contains('bk-container'); },
          listName: function () { return 'column ' + (c + 1) + ' of ' + count + ' in container ' + ordinal; },
          label: function (node, at) {
            var inner = column.blocks[at];
            var def = inner && definitions[inner.type];
            return 'Block ' + (def ? def.label : (inner ? inner.type : ''));
          },
          onSort: onSortBlocks
        });
      }
    });
    if (count < MAX_COLUMNS) {
      var more = el('button', { type: 'button', class: 'bk-col-new', 'aria-label': 'Add a column', title: 'Add a column', html: svg('plus') });
      more.addEventListener('click', function () { addColumn(container); render(container._id, 'keep'); });
      host.appendChild(more);
    }
    return host;
  }

  function renderContainer(container, index) {
    var idBase = 'blk-' + container._id;
    var hidden = !!container.settings.hidden;
    var label = 'Container';
    var card = el('section', { class: 'bk-card bk-container' + (hidden ? ' is-hidden' : '') + (container._open ? ' is-open' : ''), 'data-block-card': container._id, 'data-block-path': String(index) });
    var toggle = el('button', {
      type: 'button',
      class: 'bk-toggle',
      'data-drag-surface': '',
      'aria-expanded': container._open ? 'true' : 'false',
      'aria-controls': idBase + '-body',
      html: '<span class="bk-chev' + (container._open ? ' is-open' : '') + '">' + svg('chevron', 'h-3.5 w-3.5') + '</span>'
    });
    toggle.appendChild(el('span', { class: 'bk-mini', html: '<svg viewBox="0 0 64 48" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + CONTAINER_PREVIEW + '</svg>' }));
    toggle.appendChild(el('span', { class: 'bk-name', text: (index + 1) + '. ' + label }));
    toggle.appendChild(el('span', { class: 'bk-tag', text: containerLabel(container) }));
    if (hidden) toggle.appendChild(el('span', { class: 'bk-tag is-warn', html: svg('eyeOff', 'h-3 w-3') + 'Hidden' }));
    toggle.addEventListener('click', function () {
      container._open = !container._open;
      render(container._id, 'toggle');
    });

    var eye = el('button', { type: 'button', class: CLS.icon, 'aria-label': hidden ? 'Show container on the page' : 'Hide container from the page', title: hidden ? 'Show on the page' : 'Hide from the page', 'aria-pressed': hidden ? 'true' : 'false', html: svg(hidden ? 'eyeOff' : 'eye'), 'data-action': 'hide' });
    eye.addEventListener('click', function () {
      container.settings.hidden = !hidden;
      render(container._id, 'keep');
    });
    var more = moreMenu(label, [
      { label: 'Move to top', icon: 'top', disabled: index === 0, run: function () { move(blocks, index, 0, 'more'); sayMoved(label, 0, blocks.length); } },
      { label: 'Move to bottom', icon: 'bottom', disabled: index === blocks.length - 1, run: function () { move(blocks, index, blocks.length - 1, 'more'); sayMoved(label, blocks.length - 1, blocks.length); } },
      { label: 'Unwrap (blocks to the top level)', icon: 'out', run: function () { unwrap(container, index); } }
    ]);
    var insert = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Add a block below', title: 'Add a block below', html: svg('plus'), 'data-action': 'insert' });
    insert.addEventListener('click', function () { openPicker(index + 1, insert, blocks); });
    var duplicate = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Duplicate container', title: 'Duplicate', html: svg('copy'), 'data-action': 'duplicate' });
    duplicate.addEventListener('click', function () {
      var copy = withId(container, true);
      blocks.splice(index + 1, 0, copy);
      render(copy._id, 'toggle');
    });
    var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove container', title: 'Remove', html: svg('trash'), 'data-action': 'remove' });
    remove.addEventListener('click', function () {
      lastRemoved = { block: container, index: index, list: blocks };
      blocks.splice(index, 1);
      render(null);
      showUndo(label);
    });
    var select = el('input', { type: 'checkbox', class: 'bk-select', 'aria-label': 'Select container to save as a section', title: 'Select to save as a section', checked: !!selected[container._id] });
    select.addEventListener('change', function () {
      if (select.checked) selected[container._id] = true; else delete selected[container._id];
      syncSaveBar();
    });
    card.appendChild(el('div', { class: 'bk-head' }, [
      blocks.length > 1 ? grip('container') : null, select, toggle,
      el('div', { class: 'bk-actions' }, [eye, insert, duplicate, more, remove])
    ]));

    if (container._open) {
      var body = el('div', { id: idBase + '-body', class: 'bk-body' });
      var tabs = el('div', { class: 'bk-tabs', role: 'tablist' });
      var holder = el('div');
      var show = function (name) {
        container._tab = name;
        holder.innerHTML = '';
        var panel = name === 'design' ? containerDesign(container, idBase + '-d') : containerColumns(container, index);
        panel.id = idBase + '-' + name;
        panel.setAttribute('role', 'tabpanel');
        holder.appendChild(panel);
        Array.prototype.forEach.call(tabs.children, function (b) {
          b.setAttribute('aria-selected', b.getAttribute('data-tab') === name ? 'true' : 'false');
          b.tabIndex = b.getAttribute('data-tab') === name ? 0 : -1;
        });
      };
      [['content', 'Columns'], ['design', 'Design']].forEach(function (pair) {
        var tab = el('button', { type: 'button', role: 'tab', 'data-tab': pair[0], text: pair[1], 'aria-controls': idBase + '-' + pair[0] });
        tab.addEventListener('click', function () { show(pair[0]); });
        tab.addEventListener('keydown', function (event) {
          if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            var other = pair[0] === 'content' ? 'design' : 'content';
            show(other);
            tabs.querySelector('[data-tab="' + other + '"]').focus();
          }
        });
        tabs.appendChild(tab);
      });
      body.appendChild(tabs);
      body.appendChild(holder);
      show(container._tab === 'design' ? 'design' : 'content');
      card.appendChild(body);
    }
    return card;
  }

  /** A block was dropped: within its list, between two lists (columns, or a column and the top level). The data changes, then everything is drawn again. */
  function onSortBlocks(info) {
    var source = info.fromList.options.meta.get();
    var target = info.toList.options.meta.get();
    if (source === target) {
      move(source, info.from, info.to, info.via === 'keyboard' ? 'move' : '');
      return;
    }
    var item = source.splice(info.from, 1)[0];
    target.splice(info.to, 0, item);
    render(item._id, info.via === 'keyboard' ? 'move' : '');
  }

  var list = el('div', { class: 'bk-list', 'data-block-list': '' });
  var sortHint = el('p', { id: HINT_ID, class: 'bk-sr', text: 'Drag the grip to reorder. With the keyboard, press Space to pick up, the arrow keys to move, Space to drop and Escape to cancel.' });
  if (Sortable) {
    Sortable.create({
      container: list,
      items: '.bk-card',
      handle: '.bk-grip',
      surface: '.bk-head',
      surfaceActive: function (node) { return !node.classList.contains('is-open'); },
      ghost: '.bk-head',
      group: 'blocks',
      meta: { get: function () { return blocks; } },
      listName: function () { return 'the top level'; },
      label: function (node, at) {
        var block = blocks[at];
        var def = block && definitions[block.type];
        return (isContainer(block) ? '' : 'Block ') + (def ? def.label : (block ? block.type : ''));
      },
      // Blocks that share a row through their Width are drawn side by side and dragging only changes the order; a block can also be
      // dropped into a column of a container, and out of it.
      onSort: onSortBlocks
    });
  }
  var empty = el('div', { class: 'bk-empty' }, [
    el('p', { class: 'bk-empty-title', text: footerMode ? 'No blocks above the footer yet.' : 'This page has no blocks yet.' }),
    el('p', { class: 'bk-help', text: footerMode ? 'Add blocks such as a row of logos or a call to action; they show above the footer on every page.' : 'Without blocks the page shows its Markdown text as before. Add blocks to build the page from sections.' }),
    presets.some(function (p) { return p.kind === 'page'; })
      ? el('button', { type: 'button', class: CLS.btn, text: 'Start from a page layout', onclick: function (event) { pickerTab = 'pages'; openPicker(blocks.length, event.currentTarget); } })
      : null
  ]);
  var undoBar = el('div', { class: 'bk-note hidden', role: 'status' });

  /* One set of blocks for each language (footer only) ------------------------------ */

  var languageButtons = [];
  var languageBar = null;
  var copyNote = null;
  if (sets) {
    languageBar = el('span', { class: 'bk-pills is-light', role: 'group', 'aria-label': 'Language of the blocks' });
    languages.forEach(function (language) {
      var button = el('button', { type: 'button', 'data-set': language.key, 'aria-pressed': language.key === activeKey ? 'true' : 'false', text: String(language.code).toUpperCase() });
      button.addEventListener('click', function () { switchSet(language.key); });
      languageButtons.push(button);
      languageBar.appendChild(button);
    });
    // A language with no blocks of its own shows the site's own: say so, and offer a copy to start from.
    var copyButton = el('button', { type: 'button', class: CLS.btn, text: 'Start from a copy of the site\'s own blocks' });
    copyButton.addEventListener('click', function () {
      var source = sets[languages[0].key];
      if (!source.length) return;
      insertBlocks(source, blocks.length);
      render(blocks.length ? blocks[0]._id : null, 'toggle');
    });
    copyNote = el('div', { class: 'bk-note hidden', role: 'status' }, [
      el('span', { text: 'This language has no blocks of its own: its pages show the blocks of the site\'s own language. Add blocks to give it a set.' }),
      copyButton
    ]);
  }

  function switchSet(key) {
    if (!sets || !sets[key]) return;
    activeKey = key;
    blocks = sets[key];
    selected = {};
    lastRemoved = null;
    undoBar.classList.add('hidden');
    closePicker(true);
    pickerTarget = blocks.length;
    languageButtons.forEach(function (button) { button.setAttribute('aria-pressed', button.getAttribute('data-set') === key ? 'true' : 'false'); });
    expandAll.textContent = blocks.length && everyBlock(blocks).every(function (b) { return b._open; }) ? 'Collapse all' : 'Expand all';
    render(null);
    syncSaveBar();
  }

  function showUndo(label) {
    undoBar.innerHTML = '';
    undoBar.appendChild(el('span', { text: label + ' removed.' }));
    var undo = el('button', { type: 'button', class: 'bk-link', text: 'Undo' });
    undo.addEventListener('click', function () {
      if (!lastRemoved) return;
      if (lastRemoved.many) {
        blocks.length = 0;
        lastRemoved.many.forEach(function (b) { blocks.push(b); });
        lastRemoved = null;
        undoBar.classList.add('hidden');
        render(blocks.length ? blocks[0]._id : null, 'toggle');
        return;
      }
      (lastRemoved.list || blocks).splice(lastRemoved.index, 0, lastRemoved.block);
      var id = lastRemoved.block._id;
      lastRemoved = null;
      undoBar.classList.add('hidden');
      render(id, 'toggle');
    });
    undoBar.appendChild(undo);
    undoBar.classList.remove('hidden');
  }

  function render(focusId, focusWhat) {
    list.innerHTML = '';
    // Blocks narrower than the page that follow each other share a row on the page, so they are drawn side by side here too.
    var row = null;
    blocks.forEach(function (block, index) {
      var card = isContainer(block) ? renderContainer(block, index) : renderBlock(block, index, { list: blocks, path: String(index) });
      if (!isContainer(block) && widthOf(block) < 100 && definitions[block.type]) {
        if (!row) {
          row = el('div', { class: 'bk-sharing', role: 'group', 'aria-label': 'Blocks sharing a row' });
          list.appendChild(row);
        }
        // An open block takes the whole row to have room for its fields.
        card.style.gridColumn = 'span ' + (block._open ? 12 : ({ 25: 3, 33: 4, 50: 6, 66: 8, 75: 9 })[widthOf(block)]);
        row.appendChild(card);
      } else {
        row = null;
        list.appendChild(card);
      }
    });
    empty.classList.toggle('hidden', blocks.length > 0);
    if (copyNote) copyNote.classList.toggle('hidden', blocks.length > 0 || activeKey === languages[0].key || !sets[languages[0].key].length);
    serialize();
    if (focusId) {
      var card = list.querySelector('[data-block-card="' + focusId + '"]');
      if (!card) return;
      var target = null;
      if (focusWhat === 'move') {
        target = card.querySelector('[data-grip]') || card.querySelector('[aria-expanded]');
        card.scrollIntoView({ block: 'nearest' });
      } else if (focusWhat === 'more') {
        target = card.querySelector('[data-action="more"]');
        card.scrollIntoView({ block: 'nearest' });
      } else if (focusWhat === 'toggle') {
        target = card.querySelector('[aria-expanded]');
        card.scrollIntoView({ block: 'nearest' });
      }
      if (target) target.focus();
    }
  }

  /* Block picker ------------------------------------------------------------- */

  var picker = el('div', { class: 'bk-picker hidden', 'data-block-picker': '' });
  var pickerTarget = blocks.length;
  var pickerReturn = null;
  // Where the picker puts what is chosen: the top level (null) or a column of a container.
  var pickerList = null;
  var pickerColumn = null;
  function targetList() { return pickerList || blocks; }

  var pickerTab = 'blocks';

  function insertBlocks(list, position, into) {
    var added = list.map(function (b) { return withId(b, false); });
    Array.prototype.splice.apply(into || blocks, [position, 0].concat(added));
    if (added.length) added[0]._open = true;
    return added;
  }

  function applyTemplate(template) {
    var selectEl = document.querySelector('[data-template-select]');
    if (!selectEl || !template) return;
    var option = selectEl.querySelector('option[value="' + template + '"]');
    if (!option) return;
    selectEl.value = template;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function presetCard(preset, onChoose) {
    var card = el('div', { class: 'bk-pcard' });
    var first = preset.blocks[0] && definitions[preset.blocks[0].type];
    var choose = el('button', { type: 'button', class: 'bk-pchoose' }, [
      thumb(first ? first.preview : ''),
      el('span', { class: 'bk-ptext' }, [
        el('span', { class: 'bk-pname', text: preset.label }),
        el('span', { class: 'bk-pdesc', text: preset.description || '' }),
        el('span', { class: 'bk-pmeta', text: preset.blocks.map(function (b) { return definitions[b.type] ? definitions[b.type].label : b.type; }).join(' · ') + (preset.origin === 'custom' ? ' · saved on this site' : '') })
      ])
    ]);
    choose.addEventListener('click', function () { onChoose(preset); });
    card.appendChild(choose);
    if (preset.origin === 'custom') {
      var remove = el('button', { type: 'button', class: CLS.iconDanger + ' bk-pdelete', 'aria-label': 'Delete saved section ' + preset.label, html: svg('trash') });
      remove.addEventListener('click', function () {
        remove.disabled = true;
        postPresets({ preset_action: 'delete', id: preset.id }).then(function (res) {
          if (!res.ok) throw new Error('delete');
          presets = presets.filter(function (p) { return p.id !== preset.id; });
          renderPicker();
          if (window.adminToast) window.adminToast('Saved section deleted.', 'success');
        }).catch(function () {
          remove.disabled = false;
          if (window.adminToast) window.adminToast('Could not delete the section.', 'error');
        });
      });
      card.appendChild(remove);
    }
    return card;
  }

  var CATEGORY_ORDER = ['Openers', 'Content', 'Media', 'Showcase', 'Convert', 'Layout'];
  var FIRST_BLOCKS = ['image', 'text'];
  var pickerCategory = '';

  function renderPicker() {
    var position = pickerTarget;
    picker.innerHTML = '';
    var tabs = [['blocks', 'Blocks'], ['sections', 'Ready-made sections']].concat(footerMode || pickerColumn ? [] : [['pages', 'Page layouts']]);
    if (pickerTab === 'pages' && pickerColumn) pickerTab = 'blocks';
    var tabBar = el('div', { class: 'bk-pills', role: 'tablist' });
    tabs.forEach(function (tab) {
      var active = pickerTab === tab[0];
      var button = el('button', { type: 'button', role: 'tab', 'aria-selected': active ? 'true' : 'false', text: tab[1] });
      button.addEventListener('click', function () { pickerTab = tab[0]; renderPicker(); var t = picker.querySelector('[aria-selected="true"]'); if (t) t.focus(); });
      tabBar.appendChild(button);
    });
    picker.appendChild(el('div', { class: 'bk-picker-head' }, [
      tabBar,
      el('div', { class: 'bk-picker-side' }, [
        el('span', { class: 'bk-help', text: (pickerColumn ? 'In column ' + (pickerColumnNumber() + 1) + ': ' : '') + (position < targetList().length ? 'inserts at position ' + (position + 1) : 'adds at the end') }),
        el('button', { type: 'button', class: CLS.btn, text: 'Cancel', onclick: closePicker })
      ])
    ]));
    var grid = el('div', { class: 'bk-picker-grid' });

    if (pickerTab === 'blocks') {
      var options = [];
      var categoryOf = function (def) { return def.category || (def.origin === 'custom' ? 'Custom' : 'Other'); };
      var present = [];
      Object.keys(definitions).forEach(function (type) {
        if (type === 'container' && pickerColumn) return;
        var cat = categoryOf(definitions[type]);
        if (present.indexOf(cat) === -1) present.push(cat);
      });
      present.sort(function (a, b) {
        var ia = CATEGORY_ORDER.indexOf(a), ib = CATEGORY_ORDER.indexOf(b);
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib) || a.localeCompare(b);
      });
      Object.keys(definitions).filter(function (type) { return type !== 'container'; }).sort(function (x, y) {
        var ix = FIRST_BLOCKS.indexOf(x), iy = FIRST_BLOCKS.indexOf(y);
        var cx = present.indexOf(categoryOf(definitions[x])), cy = present.indexOf(categoryOf(definitions[y]));
        return (ix === -1 ? FIRST_BLOCKS.length : ix) - (iy === -1 ? FIRST_BLOCKS.length : iy) || cx - cy;
      }).forEach(function (type) {
        var def = definitions[type];
        var option = el('button', { type: 'button', class: 'bk-pcard bk-pchoose' }, [
          thumb(def.preview),
          el('span', { class: 'bk-ptext' }, [
            el('span', { class: 'bk-pname', text: def.label + (def.origin === 'custom' ? ' (custom)' : '') }),
            el('span', { class: 'bk-pdesc', text: def.description })
          ])
        ]);
        option.addEventListener('click', function () {
          var block = newBlock(type);
          targetList().splice(pickerTarget, 0, block);
          closePicker(true);
          render(block._id, 'toggle');
        });
        options.push({ node: option, category: categoryOf(def), text: (def.label + ' ' + def.description + ' ' + type + ' ' + categoryOf(def)).toLowerCase() });
        grid.appendChild(option);
      });
      // A container, with its ready-made column layouts (not inside a column: one level only).
      if (containerDef && !pickerColumn) {
        var row = el('div', { class: 'bk-pcontainers', role: 'group', 'aria-label': 'Add a container' }, [
          el('span', { class: 'bk-pname', text: 'Container' }),
          el('span', { class: 'bk-pdesc', text: 'A row with columns; blocks go inside the columns. Pick a layout:' })
        ]);
        var chipsRow = el('span', { class: 'bk-presets' });
        containerDef.presets.forEach(function (preset) {
          var chip = el('button', { type: 'button', class: 'bk-preset', 'aria-label': 'Container, ' + preset.label }, [bars(preset.widths), el('span', { text: preset.label })]);
          chip.addEventListener('click', function () {
            var container = newContainer(preset.widths);
            blocks.splice(pickerTarget, 0, container);
            closePicker(true);
            render(container._id, 'toggle');
          });
          chipsRow.appendChild(chip);
        });
        row.appendChild(chipsRow);
        options.push({ node: row, category: 'Layout', text: 'container columns row layout flexbox grid' });
        grid.insertBefore(row, grid.children[FIRST_BLOCKS.filter(function (type) { return !!definitions[type]; }).length] || null);
      }
      var none = el('p', { class: 'bk-help hidden', text: 'No block matches.' });
      var search = el('input', { type: 'search', class: 'lc-input bk-search', placeholder: 'Search blocks', 'aria-label': 'Search blocks' });
      var chips = el('div', { class: 'bk-pills is-light', role: 'group', 'aria-label': 'Kinds of block' });
      var apply = function () {
        var term = search.value.trim().toLowerCase();
        var shown = 0;
        options.forEach(function (o) {
          var match = (term === '' || o.text.indexOf(term) !== -1) && (pickerCategory === '' || o.category === pickerCategory);
          o.node.classList.toggle('hidden', !match);
          if (match) shown++;
        });
        none.classList.toggle('hidden', shown > 0);
        Array.prototype.forEach.call(chips.children, function (c) { c.setAttribute('aria-pressed', (c.getAttribute('data-cat') || '') === pickerCategory ? 'true' : 'false'); });
      };
      [''].concat(present).forEach(function (cat) {
        var chip = el('button', { type: 'button', 'data-cat': cat, text: cat === '' ? 'All' : cat });
        chip.addEventListener('click', function () { pickerCategory = cat; apply(); });
        chips.appendChild(chip);
      });
      search.addEventListener('input', apply);
      picker.appendChild(el('div', { class: 'bk-picker-tools' }, [chips, search]));
      grid.appendChild(none);
      apply();
    } else if (pickerTab === 'sections') {
      // A section that holds a container does not go into a column.
      var sections = presets.filter(function (p) { return p.kind === 'section' && !(pickerColumn && p.blocks.some(isContainer)); });
      if (!sections.length) grid.appendChild(el('p', { class: 'bk-help', text: 'No sections yet.' }));
      sections.forEach(function (preset) {
        grid.appendChild(presetCard(preset, function () {
          var added = insertBlocks(preset.blocks, pickerTarget, targetList());
          closePicker(true);
          render(added[0]._id, 'toggle');
          if (window.adminToast) window.adminToast(preset.label + ' added.', 'success');
        }));
      });
    } else {
      var note = blocks.length
        ? 'This page already has ' + blocks.length + ' block(s). A layout can replace them or be added after them.'
        : 'Pick a layout to start the page. Replace the placeholder text and add images before publishing.';
      picker.appendChild(el('p', { class: 'bk-note-line', text: note }));
      presets.filter(function (p) { return p.kind === 'page'; }).forEach(function (preset) {
        grid.appendChild(presetCard(preset, function (chosen) {
          if (!blocks.length) {
            usePage(chosen, 'replace');
            return;
          }
          // Ask how to combine with existing blocks, inside the picker.
          picker.querySelectorAll('[data-page-choice]').forEach(function (n) { n.remove(); });
          var bar = el('div', { class: 'bk-note', 'data-page-choice': '' }, [
            el('span', { class: 'bk-grow', text: 'Use "' + chosen.label + '":' }),
            el('button', { type: 'button', class: CLS.btn, text: 'Add after existing blocks', onclick: function () { usePage(chosen, 'append'); } }),
            el('button', { type: 'button', class: CLS.btnPrimary, text: 'Replace existing blocks', onclick: function () { usePage(chosen, 'replace'); } })
          ]);
          picker.insertBefore(bar, grid);
          bar.querySelector('button').focus();
        }));
      });
    }
    picker.appendChild(grid);
  }

  function usePage(preset, mode) {
    var removed = null;
    if (mode === 'replace' && blocks.length) {
      removed = blocks.slice();
      blocks.length = 0;
    }
    var added = insertBlocks(preset.blocks, blocks.length);
    applyTemplate(preset.template);
    closePicker(true);
    render(added[0]._id, 'toggle');
    if (removed) {
      lastRemoved = { many: removed };
      showUndo('Previous blocks');
    }
    if (window.adminToast) window.adminToast(preset.label + ' added' + (preset.template ? ' (template: ' + preset.template + ')' : '') + '.', 'success');
  }

  function pickerColumnNumber() {
    var found = 0;
    blocks.forEach(function (block) {
      if (isContainer(block)) block.columns.forEach(function (column, c) { if (column === pickerColumn) found = c; });
    });
    return found;
  }

  function openPicker(position, returnTo, into, column) {
    pickerTarget = position;
    pickerReturn = returnTo || null;
    pickerList = into && into !== blocks ? into : null;
    pickerColumn = column || null;
    if (!blocks.length && presets.some(function (p) { return p.kind === 'page'; }) && pickerTab === 'blocks' && returnTo === addButton) {
      pickerTab = 'pages';
    }
    renderPicker();
    picker.classList.remove('hidden');
    var first = picker.querySelector('[aria-selected="true"]');
    if (first) first.focus();
  }

  function closePicker(silent) {
    picker.classList.add('hidden');
    if (silent !== true && pickerReturn) pickerReturn.focus();
  }

  picker.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      closePicker();
    }
  });

  /* Media picker ------------------------------------------------------------- */

  // Choosing a picture is the shared picker (admin-media-picker.js): a dialog that asks the server a page at a time.
  function openMediaPicker(callback, kind) {
    if (window.FarosMediaPicker) window.FarosMediaPicker.open(callback, kind);
  }

  /* Save selected blocks as a reusable section ---------------------------------- */

  function postPresets(fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });
    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    var token = tokenMeta ? tokenMeta.getAttribute('content') : '';
    body.append('_csrf', token);
    return fetch(data.presets_url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' }
    }).then(function (response) { return response.json(); });
  }

  var saveName = el('input', { type: 'text', class: CLS.input + ' bk-save-input', placeholder: 'Section name', 'aria-label': 'Section name' });
  var saveDescription = el('input', { type: 'text', class: CLS.input + ' bk-save-input is-wide', placeholder: 'Short description (optional)', 'aria-label': 'Section description' });
  var saveButton = el('button', { type: 'button', class: CLS.btnPrimary, text: 'Save as section' });
  var clearSelection = el('button', { type: 'button', class: CLS.btn, text: 'Clear selection' });
  var saveCount = el('span', { class: 'bk-save-count' });
  var saveBar = el('div', { class: 'bk-savebar hidden', role: 'region', 'aria-label': 'Save selected blocks as a section' }, [saveCount, saveName, saveDescription, saveButton, clearSelection]);

  function syncSaveBar() {
    var count = blocks.filter(function (b) { return selected[b._id]; }).length;
    saveBar.classList.toggle('hidden', count === 0);
    saveCount.textContent = count + ' selected';
  }

  clearSelection.addEventListener('click', function () {
    selected = {};
    render(null);
    syncSaveBar();
  });

  saveButton.addEventListener('click', function () {
    var chosen = blocks.filter(function (b) { return selected[b._id]; });
    if (!saveName.value.trim()) {
      saveName.focus();
      if (window.adminToast) window.adminToast('Give the section a name.', 'error');
      return;
    }
    saveButton.disabled = true;
    postPresets({
      preset_action: 'save',
      name: saveName.value,
      description: saveDescription.value,
      lang: data.lang || '',
      blocks_json: JSON.stringify(chosen)
    }).then(function (res) {
      saveButton.disabled = false;
      if (!res.ok) {
        if (window.adminToast) window.adminToast(res.message || 'Could not save the section.', 'error');
        return;
      }
      if (res.preset) presets.push(res.preset);
      selected = {};
      saveName.value = '';
      saveDescription.value = '';
      render(null);
      syncSaveBar();
      if (window.adminToast) window.adminToast('Section saved. Find it under Add block → Ready-made sections.', 'success');
    }).catch(function () {
      saveButton.disabled = false;
      if (window.adminToast) window.adminToast('Could not save the section.', 'error');
    });
  });

  /* Template description (Basics tab) ------------------------------------------ */

  var templateSelect = document.querySelector('[data-template-select]');
  var templateDescription = document.querySelector('[data-template-description]');
  if (templateSelect && templateDescription) {
    templateSelect.addEventListener('change', function () {
      var option = templateSelect.options[templateSelect.selectedIndex];
      templateDescription.textContent = option ? option.getAttribute('data-description') || '' : '';
    });
  }

  /* Mount -------------------------------------------------------------------- */

  var addButton = el('button', { type: 'button', class: CLS.btnPrimary, html: svg('plus') + '<span>Add block</span>' });
  addButton.addEventListener('click', function () { openPicker(blocks.length, addButton); });
  var expandAll = el('button', { type: 'button', class: CLS.btn, text: 'Expand all' });
  expandAll.addEventListener('click', function () {
    var open = !everyBlock(blocks).every(function (b) { return b._open; });
    everyBlock(blocks).forEach(function (b) { b._open = open; });
    expandAll.textContent = open ? 'Collapse all' : 'Expand all';
    render(null);
  });

  root.innerHTML = '';
  root.appendChild(el('div', { class: 'bk-bar' }, [
    el('p', { class: 'bk-help', text: footerMode ? 'Top to bottom, above the footer. Tick blocks to save them as a reusable section.' : 'Sections of this page, top to bottom. An opening Hero becomes the page title. Tick blocks to save them as a reusable section.' }),
    el('div', { class: 'bk-bar-actions' }, [languageBar, expandAll, addButton])
  ]));
  if (copyNote) root.appendChild(copyNote);
  root.appendChild(undoBar);
  root.appendChild(saveBar);
  root.appendChild(picker);
  root.appendChild(empty);
  root.appendChild(sortHint);
  root.appendChild(list);

  flag.value = '1';
  render(null);
})();
