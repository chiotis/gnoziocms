/*
 * The form builder (admin/templates/form-edit.twig). A form is a list of fields; the canvas draws it as the visitor will
 * see it, the palette adds fields, and the inspector edits the one that is selected. Every change goes through the list, so
 * undo is a stack of copies of it, and saving puts the list in one hidden field (form_fields_json).
 *
 *   - add a field by clicking it in the palette or dragging it into the form; move a field by dragging its handle (or with
 *     the arrow keys on it: up and down move it, left and right make it narrower or wider)
 *   - a field can take the whole row, a half, a third or two thirds
 *   - the choices of a dropdown, single or multiple choice are a list that is reordered by dragging
 *   - names are made from the labels (Greek included) until the field has been saved; the Email tab offers each field as an
 *     answer to put in a subject or message, and as the address to reply to
 *   - Edit and Preview, Desktop and Phone; undo and redo; a warning before leaving with unsaved changes
 *   - languages: the form is built in the first language only. A chip for each other language switches the builder to
 *     translating: the canvas shows that language (what is not translated yet shows the first language and a badge), the
 *     inspector edits the texts of the selected field (label, placeholder, help, the choices' labels), and the texts of
 *     the form as a whole (the button, the messages, the emails) swap in the Settings, Email and Page tabs. Structure
 *     cannot change while translating. Saving writes the translations next to the fields (form_fields_json) and the
 *     form's own (form_translations_json).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-form-builder]');
  var dataNode = document.getElementById('form-builder-data');
  if (!root || !dataNode) return;

  var data = JSON.parse(dataNode.textContent);
  var form = root.querySelector('[data-form-builder-form]');
  var palette = root.querySelector('[data-fb-palette]');
  var canvas = root.querySelector('[data-fb-canvas]');
  var wrap = root.querySelector('[data-fb-canvas-wrap]');
  var stage = root.querySelector('[data-fb-stage]');
  var dropLine = root.querySelector('[data-fb-drop]');
  var emptyEl = root.querySelector('[data-fb-empty]');
  var quickEl = root.querySelector('[data-fb-quick]');
  var inspector = root.querySelector('[data-fb-inspector]');
  var inspectorTitle = root.querySelector('[data-fb-inspector-title]');
  var inspectorWrap = root.querySelector('[data-fb-inspector-wrap]');
  var paletteWrap = root.querySelector('[data-fb-palette-wrap]');
  var layout = root.querySelector('[data-fb-layout]');
  var undoBtn = root.querySelector('[data-fb-undo]');
  var redoBtn = root.querySelector('[data-fb-redo]');
  var jsonField = root.querySelector('[data-fb-json]');
  var countEl = root.querySelector('[data-fb-count]');
  var submitPreview = root.querySelector('[data-fb-submit-preview]');
  var submitLabelInput = root.querySelector('[data-fb-submit-label]');
  var dirtyNote = document.querySelector('[data-dirty-note]');
  var replyTo = root.querySelector('[data-fb-reply-to]');
  var notifyBox = root.querySelector('[data-fb-notify]');
  var notifyTo = root.querySelector('[data-fb-notify-to]');
  var notifyWarning = root.querySelector('[data-fb-notify-warning]');
  var langBar = root.querySelector('[data-fb-langbar]');
  var langChips = root.querySelector('[data-fb-chips-lang]');
  var langNote = root.querySelector('[data-fb-lang-note]');
  var transField = root.querySelector('[data-fb-translations-json]');
  var translateField = root.querySelector('[data-fb-translate-lang]');

  var LAYOUT_EDIT = 'grid items-start gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_20rem]';
  var LAYOUT_PREVIEW = 'grid items-start gap-4 grid-cols-1';
  var LAYOUT_TRANSLATE = 'grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]';
  var SPANS = { '': 6, 'two-thirds': 4, half: 3, third: 2 };
  var WIDTH_ORDER = ['third', 'half', 'two-thirds', ''];
  var WIDTH_NAMES = { '': 'Full', half: 'Half', third: 'Third', 'two-thirds': 'Two thirds' };

  /* ---------- what a field can be ---------- */

  var TYPES = {
    text: { label: 'Short text', glyph: 'Aa', group: 'Text', placeholder: true, value: true },
    textarea: { label: 'Long text', glyph: '¶', group: 'Text', placeholder: true, rows: true },
    email: { label: 'Email', glyph: '@', group: 'Text', placeholder: true, value: true },
    tel: { label: 'Phone', glyph: '☎', group: 'Text', placeholder: true, value: true },
    number: { label: 'Number', glyph: '123', group: 'Text', placeholder: true, value: true, range: true },
    url: { label: 'Website', glyph: 'www', group: 'Text', placeholder: true, value: true },
    select: { label: 'Dropdown', glyph: '▾', group: 'Choices', choice: true },
    radio: { label: 'Single choice', glyph: '◉', group: 'Choices', choice: true },
    checkboxes: { label: 'Multiple choice', glyph: '☑', group: 'Choices', choice: true },
    checkbox: { label: 'Checkbox', glyph: '✓', group: 'Choices', check: true },
    rating: { label: 'Rating', glyph: '★', group: 'Choices', rating: true },
    date: { label: 'Date', glyph: '31', group: 'Date and time', value: true },
    time: { label: 'Time', glyph: '12:00', group: 'Date and time', value: true },
    'datetime-local': { label: 'Date and time', glyph: '31·12', group: 'Date and time', value: true },
    heading: { label: 'Heading', glyph: 'H', group: 'Layout', display: true },
    paragraph: { label: 'Text', glyph: '¶', group: 'Layout', display: true },
    step: { label: 'New step', glyph: '⇥', group: 'Layout', display: true, step: true },
    file: { label: 'File upload', glyph: '⇪', group: 'Files', file: true },
    range: { label: 'Slider', glyph: '⇆', group: 'Other', range: true, value: true },
    color: { label: 'Colour', glyph: '◐', group: 'Other', value: true },
    hidden: { label: 'Hidden value', glyph: '•••', group: 'Other', value: true }
  };
  var GROUPS = ['Text', 'Choices', 'Date and time', 'Files', 'Layout', 'Other'];
  /** The texts of a field that are translated. */
  var TEXT_KEYS = ['label', 'placeholder', 'help', 'button', 'error_size', 'error_type', 'error_max', 'error_min'];
  var UPLOAD = data.upload || { server_file: 0, server_post: 0, site_file: 0, max_files: 20, default_mb: 10, presets: {}, blocked: [] };
  var QUICK = [
    { label: 'Full name', type: 'text', name: 'full-name', required: true, width: 'half' },
    { label: 'Email', type: 'email', name: 'email', required: true, width: 'half' },
    { label: 'Phone', type: 'tel', name: 'phone', width: 'half' },
    { label: 'Message', type: 'textarea', name: 'message', required: true, rows: 5 },
    { label: 'Consent', type: 'checkbox', name: 'consent', required: true, text: 'I agree to the processing of my information.' }
  ];
  /** The tests a field can be shown for, and how the inspector says them. */
  var OPS = { is: 'is', not: 'is not', filled: 'is answered', empty: 'is not answered' };
  var CHANGE_TO = [['text', 'email', 'tel', 'url', 'number', 'textarea'], ['select', 'radio', 'checkboxes'], ['date', 'time', 'datetime-local']];

  var GREEK = { 'α': 'a', 'β': 'v', 'γ': 'g', 'δ': 'd', 'ε': 'e', 'ζ': 'z', 'η': 'i', 'θ': 'th', 'ι': 'i', 'κ': 'k', 'λ': 'l', 'μ': 'm', 'ν': 'n', 'ξ': 'x', 'ο': 'o', 'π': 'p', 'ρ': 'r', 'σ': 's', 'ς': 's', 'τ': 't', 'υ': 'y', 'φ': 'f', 'χ': 'ch', 'ψ': 'ps', 'ω': 'o', 'ά': 'a', 'έ': 'e', 'ή': 'i', 'ί': 'i', 'ό': 'o', 'ύ': 'y', 'ώ': 'o', 'ϊ': 'i', 'ϋ': 'y', 'ΐ': 'i', 'ΰ': 'y' };
  function slugify(text) {
    var out = '';
    String(text || '').toLowerCase().split('').forEach(function (ch) { out += GREEK[ch] !== undefined ? GREEK[ch] : ch; });
    return out.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

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
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" d="M5 15V6a2 2 0 012-2h9"/>',
    trash: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2m2 0v14a1 1 0 01-1 1H7a1 1 0 01-1-1V6"/>',
    plus: '<path stroke-linecap="round" d="M12 5v14M5 12h14"/>',
    chevron: '<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>',
    x: '<path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>',
    bell: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 8a6 6 0 1112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0"/>'
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

  var live = document.createElement('p');
  live.className = 'sr-only';
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  root.appendChild(live);
  function announce(text) { live.textContent = ''; window.setTimeout(function () { live.textContent = text; }, 30); }

  /* ---------- the fields ---------- */

  var nextId = 1;
  var fields = [];
  var state = { selected: 0, mode: 'edit', device: 'desktop', lang: '' };
  var languages = data.languages || [];
  var formTexts = {};     // language => { 'submit_label': text, 'notifications.subject': text, ... }
  var defaultTexts = {};  // the first language's texts of the form, kept while a translation is shown in the inputs
  var undoStack = [];
  var redoStack = [];
  var lastKey = '';
  var lastAt = 0;
  var dirty = false;
  var otherDirty = false;
  var submitting = false;
  var initial = '';

  function def(type) { return TYPES[type] || TYPES.text; }

  function makeField(o) {
    var f = {
      id: nextId++,
      type: TYPES[o.type] ? o.type : 'text',
      name: o.name || '',
      label: o.label || '',
      required: !!o.required,
      placeholder: o.placeholder || '',
      help: o.help || '',
      'default': o['default'] === undefined || o['default'] === null ? '' : String(o['default']),
      options: (o.options || []).map(function (opt) { return { value: String(opt.value), label: String(opt.label), touched: o.saved !== false }; }),
      rows: parseInt(o.rows, 10) > 0 ? parseInt(o.rows, 10) : 4,
      min: o.min || '',
      max: o.max || '',
      step: o.step || '',
      width: WIDTH_ORDER.indexOf(o.width || '') !== -1 ? (o.width || '') : '',
      upload: normalizeUpload(o.upload, o.type),
      button: o.button || '', error_size: o.error_size || '', error_type: o.error_type || '', error_max: o.error_max || '', error_min: o.error_min || '',
      condition: o.condition && o.condition.field ? { field: String(o.condition.field), op: OPS[o.condition.op] ? o.condition.op : 'is', value: String(o.condition.value === undefined ? '' : o.condition.value) } : null,
      saved: !!o.saved,
      nameTouched: !!o.saved || !!o.nameTouched,
      tr: JSON.parse(JSON.stringify(o.translations && !Array.isArray(o.translations) ? o.translations : {}))
    };
    return f;
  }

  /** The settings of a file field; a new one starts with pictures, PDF and documents. */
  function normalizeUpload(u, type) {
    u = u && typeof u === 'object' ? u : {};
    var exts = Array.isArray(u.extensions) ? u.extensions.slice() : [];
    if (!exts.length && type === 'file') {
      ['images', 'pdf', 'documents'].forEach(function (k) { if (UPLOAD.presets[k]) exts = exts.concat(UPLOAD.presets[k].extensions); });
    }
    return {
      multiple: !!u.multiple,
      min: parseInt(u.min, 10) > 0 ? parseInt(u.min, 10) : 0,
      max: parseInt(u.max, 10) > 0 ? parseInt(u.max, 10) : 5,
      max_size_mb: parseFloat(u.max_size_mb) > 0 ? parseFloat(u.max_size_mb) : UPLOAD.default_mb,
      total_mb: parseFloat(u.total_mb) > 0 ? parseFloat(u.total_mb) : 0,
      extensions: exts,
      rename: String(u.rename || '')
    };
  }

  function plain(f) {
    return {
      type: f.type, name: f.name, label: f.label, required: f.required, placeholder: f.placeholder, help: f.help, 'default': f['default'],
      options: f.options.map(function (o) { return { value: o.value, label: o.label }; }), rows: f.rows, min: f.min, max: f.max, step: f.step, width: f.width,
      upload: f.type === 'file' ? f.upload : undefined, button: f.button, error_size: f.error_size, error_type: f.error_type, error_max: f.error_max, error_min: f.error_min,
      condition: f.condition && f.condition.field ? f.condition : undefined,
      translations: cleanTranslations(f)
    };
  }

  /** The translations of a field without what is empty or belongs to a choice that is gone. */
  function cleanTranslations(f) {
    var out = {};
    Object.keys(f.tr || {}).forEach(function (lang) {
      var t = f.tr[lang] || {};
      var row = {};
      TEXT_KEYS.forEach(function (k) { if (String(t[k] || '').trim()) row[k] = String(t[k]).trim(); });
      var options = {};
      f.options.forEach(function (o) { var v = t.options && t.options[o.value]; if (v && String(v).trim()) options[o.value] = String(v).trim(); });
      if (Object.keys(options).length) row.options = options;
      if (Object.keys(row).length) out[lang] = row;
    });
    return out;
  }

  /* ---------- languages ---------- */

  function filled(value) { return String(value === undefined || value === null ? '' : value).trim() !== ''; }

  /** How many texts of a field the language still lacks (the same texts the server counts: what the first language has filled in). */
  function fieldMissing(f, lang) {
    var t = (f.tr && f.tr[lang]) || {};
    var n = 0;
    TEXT_KEYS.forEach(function (k) { if (filled(f[k]) && !filled(t[k])) n++; });
    f.options.forEach(function (o) { if (filled(o.label) && !filled(t.options && t.options[o.value])) n++; });
    return n;
  }

  /** The texts of the form as a whole in the first language, wherever they are now (in the boxes, or kept while a translation is in them). */
  function defaultText(path) {
    if (state.lang === '') {
      var el = root.querySelector('[data-tr="' + path + '"]');
      return el ? el.value : '';
    }
    return defaultTexts[path] || '';
  }
  function formText(lang, path) {
    if (lang === state.lang && state.lang !== '') {
      var el = root.querySelector('[data-tr="' + path + '"]');
      return el ? el.value : '';
    }
    return (formTexts[lang] && formTexts[lang][path]) || '';
  }
  function formMissing(lang) {
    var n = 0;
    Array.prototype.forEach.call(root.querySelectorAll('[data-tr]'), function (el) {
      var path = el.getAttribute('data-tr');
      if (filled(defaultText(path)) && !filled(formText(lang, path))) n++;
    });
    return n;
  }
  function missingFor(lang) {
    return fields.reduce(function (sum, f) { return sum + fieldMissing(f, lang); }, 0) + formMissing(lang);
  }

  /** The form's own translations as the server stores them. */
  function nestedTexts() {
    var out = {};
    Object.keys(formTexts).forEach(function (lang) {
      var row = {};
      Object.keys(formTexts[lang]).forEach(function (path) {
        var v = String(formTexts[lang][path] || '').trim();
        if (!v) return;
        var parts = path.split('.');
        if (parts.length === 2) { row[parts[0]] = row[parts[0]] || {}; row[parts[0]][parts[1]] = v; } else row[path] = v;
      });
      if (Object.keys(row).length) out[lang] = row;
    });
    return out;
  }

  function langName(code) {
    if (code === '') return data.default_name || data['default'];
    var hit = languages.filter(function (l) { return l.code === code; })[0];
    return hit ? hit.name : code;
  }

  /** Puts the texts of one language into the boxes of the form's own texts (title, button, messages, emails), keeping the others. */
  function swapTexts(next) {
    var boxes = root.querySelectorAll('[data-tr]');
    var previous = state.lang;
    Array.prototype.forEach.call(boxes, function (el) {
      var path = el.getAttribute('data-tr');
      if (!el.hasAttribute('data-orig-ph')) el.setAttribute('data-orig-ph', el.placeholder || '');
      if (previous === '') defaultTexts[path] = el.value;
      else { formTexts[previous] = formTexts[previous] || {}; formTexts[previous][path] = el.value; }
    });
    Array.prototype.forEach.call(boxes, function (el) {
      var path = el.getAttribute('data-tr');
      if (next === '') {
        el.value = defaultTexts[path] || '';
        el.placeholder = el.getAttribute('data-orig-ph');
        el.classList.remove('ring-2', 'ring-amber-200');
      } else {
        el.value = (formTexts[next] && formTexts[next][path]) || '';
        el.placeholder = defaultTexts[path] || el.getAttribute('data-orig-ph');
        el.classList.add('ring-2', 'ring-amber-200');
      }
    });
  }

  function setLang(next) {
    if (next === state.lang) return;
    swapTexts(next);
    state.lang = next;
    if (next !== '') state.mode = 'edit';
    Array.prototype.forEach.call(root.querySelectorAll('[data-default-only]'), function (el) { el.classList.toggle('hidden', next !== ''); });
    var active = root.querySelector('.tab-button.active');
    if (next !== '' && active && active.hasAttribute('data-default-only') && window.fbShowTab) window.fbShowTab('build');
    if (translateField) translateField.value = next;
    applyView();
    refreshLangBar();
    announce(next === '' ? 'Editing the form in ' + langName('') : 'Translating into ' + langName(next));
  }

  function refreshLangBar() {
    if (!langBar) return;
    langBar.classList.toggle('hidden', !languages.length);
    langBar.classList.toggle('flex', languages.length > 0);
    if (!languages.length) return;
    langChips.textContent = '';
    var all = [{ code: '', name: langName(''), short: data['default'] }].concat(languages.map(function (l) { return { code: l.code, name: l.name, short: l.code }; }));
    all.forEach(function (l) {
      var on = state.lang === l.code;
      var missing = l.code === '' ? 0 : missingFor(l.code);
      var kids = [h('span', { 'class': 'font-semibold uppercase' }, [l.short]), h('span', { 'class': 'hidden sm:inline' }, [l.name])];
      if (l.code === '') kids.push(h('span', { 'class': 'rounded-full px-1.5 text-[10px] font-semibold ' + (on ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600') }, ['built here']));
      else if (missing > 0) kids.push(h('span', { 'class': 'rounded-full px-1.5 text-[10px] font-semibold ' + (on ? 'bg-amber-300 text-slate-900' : 'bg-amber-100 text-amber-800') }, [missing + ' missing']));
      else kids.push(h('span', { 'class': 'rounded-full px-1.5 text-[10px] font-semibold ' + (on ? 'bg-emerald-300 text-slate-900' : 'bg-emerald-100 text-emerald-800') }, ['done']));
      langChips.appendChild(h('button', {
        type: 'button',
        'class': 'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ' + (on ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'),
        'aria-pressed': on ? 'true' : 'false',
        title: l.code === '' ? 'Build and edit the form' : 'Translate the form into ' + l.name,
        onclick: function () { setLang(l.code); }
      }, kids));
    });
    langNote.textContent = state.lang === ''
      ? 'Fields, choices, order, validation and emails are built here, once, for every language. Pick another language to translate its texts.'
      : 'Translating into ' + langName(state.lang) + '. Only texts change here; add, remove or reorder fields in ' + langName('') + '. A text left empty is shown in ' + langName('') + '.';
  }

  /** The field as the visitor sees it in the language being edited: translated texts where there are some, the first language's where not. */
  function view(f) {
    if (state.lang === '') return f;
    var t = (f.tr && f.tr[state.lang]) || {};
    var v = Object.assign({}, f);
    TEXT_KEYS.forEach(function (k) { if (filled(t[k])) v[k] = t[k]; });
    v.options = f.options.map(function (o) { return filled(t.options && t.options[o.value]) ? Object.assign({}, o, { label: t.options[o.value] }) : o; });
    return v;
  }
  function serialize() { return JSON.stringify(fields.map(plain)); }
  function indexOf(id) { for (var i = 0; i < fields.length; i++) if (fields[i].id === id) return i; return -1; }
  function byId(id) { var i = indexOf(id); return i < 0 ? null : fields[i]; }

  function uniqueName(base, exceptId) {
    var root0 = base || 'field';
    var name = root0;
    for (var n = 2; fields.some(function (f) { return f.id !== exceptId && f.name === name; }); n++) name = root0 + '-' + n;
    return name;
  }
  function uniqueValue(list, base, except) {
    var value = base || 'option';
    for (var n = 2; list.some(function (o) { return o !== except && o.value === value; }); n++) value = (base || 'option') + '-' + n;
    return value;
  }

  function newField(type, extra) {
    var t = def(type);
    var o = { type: type, label: (extra && extra.label) || t.label, saved: false };
    Object.keys(extra || {}).forEach(function (k) { o[k] = extra[k]; });
    if (type === 'heading') o.label = (extra && extra.label) || 'Section heading';
    if (type === 'paragraph') o.label = (extra && extra.label) || 'A few words of explanation for the person filling in the form.';
    if (type === 'step') o.label = (extra && extra.label) || 'Next step';
    if (type === 'rating') o.max = (extra && extra.max) || '5';
    if (extra && extra.text) o.label = extra.text;
    if (t.choice) {
      o.options = (extra && extra.options) || [{ value: 'option-1', label: 'Option 1' }, { value: 'option-2', label: 'Option 2' }, { value: 'option-3', label: 'Option 3' }];
    }
    var f = makeField(o);
    f.nameTouched = !!(extra && extra.name);
    f.name = uniqueName((extra && extra.name) || slugify(f.label) || type, f.id);
    f.options.forEach(function (opt) { opt.touched = false; });
    return f;
  }

  /* ---------- undo ---------- */

  function snapshot() { return JSON.stringify({ fields: fields, selected: state.selected }); }
  function restore(text) {
    var s = JSON.parse(text);
    fields = s.fields;
    state.selected = byId(s.selected) ? s.selected : 0;
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

  function changed(redraw) {
    if (redraw) { renderCanvas(); renderInspector(); }
    refreshAnswers();
    refreshAttach();
    refreshDirty();
    refreshLangBar();
  }

  function refreshDirty() {
    dirty = otherDirty || serialize() !== initial;
    if (dirtyNote) {
      dirtyNote.classList.toggle('hidden', !dirty);
      dirtyNote.classList.toggle('inline-flex', dirty);
    }
    undoBtn.disabled = !undoStack.length;
    redoBtn.disabled = !redoStack.length;
  }

  /* ---------- drawing a field ---------- */

  var inputBase = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900';

  function control(f, live) {
    var off = !live;
    var type = f.type;
    var shared = { disabled: off, tabindex: off ? '-1' : false, 'aria-label': f.label || f.name || def(f.type).label };
    if (type === 'heading') return h('div', { 'class': 'text-lg font-semibold text-slate-900' }, [f.label || 'Heading']);
    if (type === 'paragraph') return h('p', { 'class': 'whitespace-pre-line text-sm text-slate-600' }, [f.label || 'Text']);
    if (type === 'step') {
      return h('div', { 'class': 'flex items-center gap-3' }, [
        h('span', { 'class': 'h-px flex-1 bg-slate-300' }),
        h('span', { 'class': 'rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white' }, ['Step: ' + (f.label || 'Untitled')]),
        h('span', { 'class': 'h-px flex-1 bg-slate-300' })
      ]);
    }
    if (type === 'rating') {
      var stars = [];
      for (var n = 1; n <= ratingMax(f); n++) stars.push(h('span', { 'class': 'text-2xl leading-none text-slate-300', 'aria-hidden': 'true' }, ['★']));
      return h('div', { 'class': 'flex gap-1', role: 'img', 'aria-label': ratingMax(f) + ' stars' }, stars);
    }
    if (type === 'hidden') return h('div', { 'class': 'inline-flex items-center gap-2 rounded-md border border-dashed border-slate-300 bg-slate-50 px-2.5 py-1.5 text-xs text-slate-500' }, ['Hidden field ', h('code', { 'class': 'font-medium text-slate-700' }, [f.name]), f['default'] ? ' = ' + f['default'] : '']);
    if (type === 'textarea') return h('textarea', Object.assign({ 'class': inputBase, rows: f.rows, placeholder: f.placeholder }, shared));
    if (type === 'select') {
      return h('select', Object.assign({ 'class': inputBase }, shared), [h('option', {}, ['Select'])].concat(f.options.map(function (o) { return h('option', { selected: o.value === f['default'] }, [o.label]); })));
    }
    if (type === 'radio' || type === 'checkboxes') {
      var list = f.options.length ? f.options : [{ value: '', label: 'Add choices in the panel on the right' }];
      return h('div', { 'class': 'grid gap-1.5' }, list.map(function (o) {
        var picked = type === 'radio' ? o.value === f['default'] : f['default'].split(',').map(function (s) { return s.trim(); }).indexOf(o.value) !== -1;
        return h('label', { 'class': 'flex items-center gap-2 text-sm text-slate-800' }, [h('input', Object.assign({ type: type === 'radio' ? 'radio' : 'checkbox', name: live ? 'fb-' + f.id : false, checked: picked, 'class': 'h-4 w-4 border-slate-300' }, shared, { 'aria-label': o.label })), o.label]);
      }));
    }
    if (type === 'checkbox') {
      return h('label', { 'class': 'flex items-start gap-2 text-sm text-slate-800' }, [h('input', Object.assign({ type: 'checkbox', checked: f['default'] === '1', 'class': 'mt-0.5 h-4 w-4 rounded border-slate-300' }, shared, { 'aria-label': f.label || 'Checkbox' })), h('span', {}, [f.label || 'Checkbox', f.required ? h('span', { 'class': 'ml-0.5 text-red-600', 'aria-hidden': 'true' }, ['*']) : null])]);
    }
    if (type === 'file') {
      var up = f.upload;
      return h('div', { 'class': 'grid justify-items-center gap-1.5 rounded-md border-2 border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center' }, [
        h('span', { 'class': 'text-sm text-slate-600' }, [up.multiple ? 'Drag files here, or' : 'Drag a file here, or']),
        h('span', { 'class': 'inline-flex rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white' }, [f.button || (up.multiple ? 'Choose files' : 'Choose a file')]),
        h('span', { 'class': 'text-xs text-slate-500' }, [uploadHint(f)])
      ]);
    }
    if (type === 'range') return h('input', Object.assign({ type: 'range', min: f.min || '0', max: f.max || '100', step: f.step || '1', 'class': 'w-full' }, shared));
    if (type === 'color') return h('input', Object.assign({ type: 'color', 'class': 'h-9 w-16 rounded border border-slate-300 bg-white' }, shared));
    return h('input', Object.assign({ type: type === 'datetime-local' ? 'datetime-local' : type, 'class': inputBase, placeholder: f.placeholder, value: f['default'] || false, min: type === 'number' ? (f.min || false) : false, max: type === 'number' ? (f.max || false) : false }, shared));
  }

  function ratingMax(f) {
    var n = parseInt(f.max, 10);
    return n > 0 ? Math.max(2, Math.min(10, n)) : 5;
  }

  /** The condition of a field in words, for its card: "Shown when Topic is Sales". */
  function conditionText(f) {
    var c = f.condition;
    if (!c || !c.field) return '';
    var target = fields.filter(function (x) { return x.name === c.field; })[0];
    if (!target) return 'Shown when a field that is gone is answered (choose another)';
    var shown = view(target);
    var value = c.value;
    if (target.type === 'checkbox') return 'Shown when ' + (shown.label || target.name) + (c.op === 'empty' || c.op === 'not' ? ' is not ticked' : ' is ticked');
    var opt = target.options.filter(function (o) { return o.value === c.value; })[0];
    if (opt) value = opt.label;
    return 'Shown when ' + (shown.label || target.name) + ' ' + OPS[c.op] + (c.op === 'is' || c.op === 'not' ? ' “' + value + '”' : '');
  }

  function fieldBody(f, live) {
    var t = def(f.type);
    var showLabel = !t.display && !t.check && f.type !== 'hidden';
    return [
      showLabel ? h('div', { 'class': 'mb-1 text-sm font-medium text-slate-800' }, [f.label || 'Untitled', f.required ? h('span', { 'class': 'ml-0.5 text-red-600', 'aria-hidden': 'true' }, ['*']) : null]) : null,
      control(f, live),
      f.help && !t.display ? h('p', { 'class': 'mt-1 text-xs text-slate-500' }, [f.help]) : null,
      f.condition && f.condition.field && !live ? h('p', { 'class': 'mt-1.5 inline-flex items-center gap-1 rounded bg-violet-50 px-1.5 py-0.5 text-[11px] font-medium text-violet-700 ring-1 ring-inset ring-violet-200' }, [conditionText(f)]) : null
    ];
  }

  function summary(f) {
    return def(f.type).label + ': ' + (f.label || f.name) + (f.required ? ', required' : '');
  }

  function buildCard(f) {
    var selected = state.mode === 'edit' && f.id === state.selected;
    var inEdit = state.mode === 'edit';
    var translating = state.lang !== '';
    var lacking = translating ? fieldMissing(f, state.lang) : 0;
    var shown = view(f);
    var badge = translating && inEdit ? h('span', {
      'class': 'absolute -top-2.5 left-2 z-10 rounded-full px-2 py-0.5 text-[10px] font-semibold ' + (lacking ? 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-300' : 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'),
      'data-fb-badge': lacking ? 'missing' : 'done'
    }, [lacking ? lacking + (lacking === 1 ? ' text to translate' : ' texts to translate') : 'Translated']) : null;
    var bar = inEdit && !translating ? h('div', { 'class': 'fb-bar absolute -top-3 right-2 z-10 flex items-center gap-0.5 rounded-md border border-slate-200 bg-white p-0.5 shadow-sm' }, [
      h('button', {
        type: 'button',
        'class': 'fb-handle inline-flex h-7 w-6 cursor-grab touch-none select-none items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-800 active:cursor-grabbing',
        'aria-label': 'Move ' + (f.label || f.name) + '. ' + (WIDTH_NAMES[f.width] || 'Full') + ' width',
        'aria-describedby': 'fb-drag-help',
        title: 'Drag to move',
        onpointerdown: function (e) { beginDrag(e, f); },
        onkeydown: function (e) { handleKey(e, f); }
      }, [icon('grip', 'h-4 w-4')]),
      h('button', { type: 'button', 'class': 'inline-flex h-7 w-7 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-800', 'aria-label': 'Duplicate ' + (f.label || f.name), title: 'Duplicate', onclick: function (e) { e.stopPropagation(); act(f.id, 'duplicate'); } }, [icon('copy', 'h-4 w-4')]),
      h('button', { type: 'button', 'class': 'inline-flex h-7 w-7 items-center justify-center rounded text-slate-500 hover:bg-red-50 hover:text-red-600', 'aria-label': 'Delete ' + (f.label || f.name), title: 'Delete', onclick: function (e) { e.stopPropagation(); act(f.id, 'remove'); } }, [icon('trash', 'h-4 w-4')])
    ]) : null;
    var li = h('li', {
      'class': 'fb-field group relative rounded-lg border-2 p-3 transition ' + (selected ? 'border-blue-500 bg-blue-50/30' : (inEdit ? 'border-transparent hover:border-slate-300' : 'border-transparent')),
      'data-id': f.id,
      'data-width': f.width,
      style: 'grid-column: span ' + SPANS[f.width] + ' / span ' + SPANS[f.width],
      tabindex: inEdit ? '0' : false,
      'aria-label': inEdit ? summary(shown) : false,
      'aria-current': selected ? 'true' : false,
      onclick: inEdit ? function () { select(f.id); } : false,
      onkeydown: inEdit ? function (e) { cardKey(e, f); } : false
    }, [badge, bar, h('div', { 'class': inEdit ? 'pointer-events-none' : '' }, fieldBody(shown, !inEdit))]);
    return li;
  }

  function renderCanvas() {
    var y = window.scrollY;
    canvas.textContent = '';
    fields.forEach(function (f) { canvas.appendChild(buildCard(f)); });
    emptyEl.classList.toggle('hidden', fields.length > 0);
    emptyEl.classList.toggle('flex', fields.length === 0);
    countEl.textContent = String(fields.filter(function (f) { return !def(f.type).display; }).length || '');
    submitPreview.textContent = submitText();
    window.scrollTo(window.scrollX, y);
  }

  function submitText() {
    return (submitLabelInput && submitLabelInput.value.trim()) || (state.lang !== '' && defaultTexts.submit_label) || 'Submit';
  }

  function refreshCard(f) {
    var li = canvas.querySelector('[data-id="' + f.id + '"]');
    if (!li) return;
    li.replaceWith(buildCard(f));
  }

  function select(id, opts) {
    if (state.selected === id && !(opts && opts.force)) return;
    var previous = canvas.querySelector('[data-id="' + state.selected + '"]');
    state.selected = id;
    if (previous) { var f0 = byId(Number(previous.getAttribute('data-id'))); if (f0) previous.replaceWith(buildCard(f0)); }
    var f = byId(id);
    var li = canvas.querySelector('[data-id="' + id + '"]');
    if (f && li) li.replaceWith(buildCard(f));
    renderInspector();
    if (opts && opts.focus) focusInspector();
  }

  function focusInspector() {
    var first = inspector.querySelector('textarea, input[type=text]');
    if (first) { first.focus(); first.select && first.select(); }
    if (window.innerWidth < 1280 && inspectorWrap.scrollIntoView) inspectorWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  /* ---------- the inspector ---------- */

  var cls = {
    input: 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-300',
    label: 'grid gap-1 text-xs font-medium text-slate-600'
  };

  function field(labelText, input, hint) {
    return h('label', { 'class': cls.label }, [labelText, input, hint ? h('span', { 'class': 'font-normal text-slate-500' }, [hint]) : null]);
  }

  function edit(f, key, fn, rerenderInspector) {
    record(key);
    fn();
    refreshCard(f);
    if (rerenderInspector) renderInspector();
    changed(false);
  }

  /** While translating: the texts of the selected field, each with the first language's text under it. */
  function renderTranslationInspector(f) {
    var lang = state.lang;
    var t = def(f.type);
    inspectorTitle.textContent = t.label + ' · ' + lang.toUpperCase();
    var items = [h('p', { 'class': 'rounded-md bg-slate-50 p-2.5 text-xs text-slate-600' }, ['Translating into ' + langName(lang) + '. The kind of field, its choices, width and order are the same in every language; change them in ' + langName('') + '.'])];

    function text(key, labelText, multiline) {
      var box = multiline ? h('textarea', { 'class': cls.input, rows: 3 }) : h('input', { type: 'text', 'class': cls.input });
      box.value = (f.tr[lang] && f.tr[lang][key]) || '';
      box.placeholder = f[key] || '';
      box.setAttribute('lang', lang);
      box.addEventListener('input', function () {
        edit(f, 'tr:' + key + ':' + f.id, function () { f.tr[lang] = f.tr[lang] || {}; f.tr[lang][key] = box.value; });
      });
      return field(labelText, box, langName('') + ': ' + (f[key] || '—'));
    }

    items.push(text('label', f.type === 'heading' ? 'Heading' : (f.type === 'paragraph' ? 'Text' : (f.type === 'step' ? 'Title of the step' : 'Label')), f.type === 'paragraph'));
    if (t.placeholder && filled(f.placeholder)) items.push(text('placeholder', 'Placeholder'));
    if (!t.display && filled(f.help)) items.push(text('help', 'Help text'));
    if (t.file) {
      [['button', 'Button text'], ['error_size', 'Message: too big'], ['error_type', 'Message: wrong kind of file'], ['error_max', 'Message: too many files'], ['error_min', 'Message: too few files']].forEach(function (k) {
        if (filled(f[k[0]])) items.push(text(k[0], k[1]));
      });
    }
    if (t.choice && f.options.length) {
      var list = h('ul', { 'class': 'grid gap-2' }, f.options.map(function (o, i) {
        var box = h('input', { type: 'text', 'class': cls.input + ' py-1.5', 'aria-label': 'Choice ' + (i + 1) + ' in ' + langName(lang), lang: lang });
        box.value = (f.tr[lang] && f.tr[lang].options && f.tr[lang].options[o.value]) || '';
        box.placeholder = o.label;
        box.addEventListener('input', function () {
          edit(f, 'tro:' + f.id + ':' + i, function () {
            f.tr[lang] = f.tr[lang] || {};
            f.tr[lang].options = f.tr[lang].options || {};
            f.tr[lang].options[o.value] = box.value;
          });
        });
        return h('li', { 'class': 'grid gap-0.5' }, [box, h('span', { 'class': 'text-xs text-slate-500' }, [langName('') + ': ' + o.label])]);
      }));
      items.push(h('div', { 'class': 'grid gap-1.5' }, [h('span', { 'class': 'text-xs font-medium text-slate-600' }, ['Choices']), list]));
    }
    inspector.appendChild(h('div', { 'class': 'grid gap-4' }, items));
  }

  function renderInspector() {
    var f = byId(state.selected);
    inspector.textContent = '';
    if (f && state.mode === 'edit' && state.lang !== '') { renderTranslationInspector(f); return; }
    if ((!f || state.mode !== 'edit') && state.lang !== '') {
      inspectorTitle.textContent = 'Translation · ' + state.lang.toUpperCase();
      var lacking = fields.filter(function (x) { return fieldMissing(x, state.lang) > 0; });
      inspector.appendChild(h('div', { 'class': 'grid gap-3 text-sm text-slate-600' }, [
        h('p', {}, [fields.length ? 'Select a field in the form to translate its texts.' : 'There are no fields to translate yet. Add them in ' + langName('') + '.']),
        lacking.length ? h('p', { 'class': 'rounded-md bg-amber-50 p-3 text-xs text-amber-800' }, [lacking.length + (lacking.length === 1 ? ' field has' : ' fields have') + ' texts still shown in ' + langName('') + '. They are marked on the form.']) : null,
        h('p', { 'class': 'rounded-md bg-slate-50 p-3 text-xs text-slate-600' }, ['The button, the messages and the emails are translated on the ', h('button', { type: 'button', 'class': 'font-medium text-blue-700 underline', onclick: function () { window.fbShowTab && window.fbShowTab('settings'); } }, ['Settings']), ' and ', h('button', { type: 'button', 'class': 'font-medium text-blue-700 underline', onclick: function () { window.fbShowTab && window.fbShowTab('email'); } }, ['Email']), ' tabs.'])
      ]));
      return;
    }
    if (!f || state.mode !== 'edit') {
      inspectorTitle.textContent = 'Form';
      var count = fields.filter(function (x) { return !def(x.type).display; }).length;
      inspector.appendChild(h('div', { 'class': 'grid gap-3 text-sm text-slate-600' }, [
        h('p', {}, [fields.length ? 'Select a field in the form to change its label, choices, width and more.' : 'Add a field to start.']),
        fields.length ? h('ul', { 'class': 'grid gap-1 text-xs text-slate-500' }, [
          h('li', {}, [count + (count === 1 ? ' field' : ' fields') + ' to fill in']),
          h('li', {}, [fields.filter(function (x) { return x.required; }).length + ' required'])
        ]) : null,
        h('p', { 'class': 'rounded-md bg-slate-50 p-3 text-xs text-slate-600' }, ['The button, the thank-you message, spam protection and where answers go are on the ', h('button', { type: 'button', 'class': 'font-medium text-blue-700 underline', onclick: function () { window.fbShowTab && window.fbShowTab('settings'); } }, ['Settings']), ' tab; the emails on the ', h('button', { type: 'button', 'class': 'font-medium text-blue-700 underline', onclick: function () { window.fbShowTab && window.fbShowTab('email'); } }, ['Email']), ' tab.'])
      ]));
      return;
    }
    var t = def(f.type);
    inspectorTitle.textContent = t.label;
    var items = [];

    // The kind of field, when it can be turned into a similar one.
    var group = CHANGE_TO.filter(function (g) { return g.indexOf(f.type) !== -1; })[0];
    if (group) {
      var kind = h('select', { 'class': cls.input }, group.map(function (ty) { return h('option', { value: ty, selected: ty === f.type }, [def(ty).label]); }));
      kind.addEventListener('change', function () {
        edit(f, '', function () {
          f.type = kind.value;
          if (def(f.type).choice && !f.options.length) f.options = [{ value: 'option-1', label: 'Option 1', touched: false }, { value: 'option-2', label: 'Option 2', touched: false }];
        }, true);
        renderCanvas();
      });
      items.push(field('Type', kind));
    }

    // The label (the text, for a heading or a paragraph).
    var labelInput = t.display && f.type === 'paragraph'
      ? h('textarea', { 'class': cls.input, rows: 4 })
      : h('input', { type: 'text', 'class': cls.input });
    labelInput.value = f.label;
    labelInput.addEventListener('input', function () {
      edit(f, 'label:' + f.id, function () {
        f.label = labelInput.value;
        if (!f.nameTouched) renameField(f, uniqueName(slugify(f.label) || f.type, f.id));
      });
      fields.forEach(function (x) { if (x.condition && x.condition.field === f.name) refreshCard(x); });
      var nameBox = inspector.querySelector('[data-fb-name]');
      if (nameBox && !f.nameTouched) nameBox.value = f.name;
    });
    items.push(field(f.type === 'heading' ? 'Heading' : (f.type === 'paragraph' ? 'Text' : (f.type === 'step' ? 'Title of the step' : 'Label')), labelInput, f.type === 'step' ? 'Everything below, up to the next step, is shown as one step: the visitor goes on with Next.' : ''));

    if (!t.display) {
      items.push(toggle('This field is required', f.required, function (on) { edit(f, '', function () { f.required = on; }); }));
    }
    if (t.placeholder) {
      var ph = h('input', { type: 'text', 'class': cls.input, value: f.placeholder });
      ph.addEventListener('input', function () { edit(f, 'ph:' + f.id, function () { f.placeholder = ph.value; }); });
      items.push(field('Placeholder', ph, 'Shown inside the empty field.'));
    }
    if (!t.display) {
      var help = h('input', { type: 'text', 'class': cls.input, value: f.help });
      help.addEventListener('input', function () { edit(f, 'help:' + f.id, function () { f.help = help.value; }); });
      items.push(field('Help text', help, 'A line of explanation under the field.'));
    }

    if (t.choice) items.push(optionsEditor(f));
    if (t.file) items.push(uploadEditor(f));
    if (t.rating) {
      var starsInput = h('input', { type: 'number', min: '2', max: '10', step: '1', 'class': cls.input, value: String(ratingMax(f)) });
      starsInput.addEventListener('input', function () { edit(f, 'stars:' + f.id, function () { f.max = String(Math.max(2, Math.min(10, parseInt(starsInput.value, 10) || 5))); }); });
      items.push(field('Number of stars', starsInput, 'From 2 to 10. The answer is a number, such as 4 of 5.'));
    }

    if (t.rows) {
      var rows = h('input', { type: 'number', min: '2', max: '30', 'class': cls.input, value: f.rows });
      rows.addEventListener('input', function () { edit(f, 'rows:' + f.id, function () { f.rows = Math.max(2, parseInt(rows.value, 10) || 4); }); });
      items.push(field('Height (lines)', rows));
    }
    if (t.range) {
      var mm = h('div', { 'class': 'grid grid-cols-3 gap-2' }, ['min', 'max', 'step'].map(function (key) {
        var inp = h('input', { type: 'text', 'class': cls.input, value: f[key], placeholder: key === 'step' ? '1' : '' });
        inp.addEventListener('input', function () { edit(f, key + ':' + f.id, function () { f[key] = inp.value.trim(); }); });
        return field(key === 'min' ? 'Smallest' : (key === 'max' ? 'Largest' : 'Step'), inp);
      }));
      items.push(mm);
    }
    if (t.value || f.type === 'checkbox') {
      if (f.type === 'checkbox') {
        items.push(toggle('Ticked to start with', f['default'] === '1', function (on) { edit(f, '', function () { f['default'] = on ? '1' : ''; }); }));
      } else {
        var dv = h('input', { type: 'text', 'class': cls.input, value: f['default'] });
        dv.addEventListener('input', function () { edit(f, 'dv:' + f.id, function () { f['default'] = dv.value; }); });
        items.push(field(f.type === 'hidden' ? 'Value' : 'Starting value', dv));
      }
    }
    if (t.choice) {
      var pick = h('select', { 'class': cls.input }, [h('option', { value: '' }, [f.type === 'checkboxes' ? 'None ticked' : 'Nothing chosen'])].concat(f.options.map(function (o) { return h('option', { value: o.value, selected: f['default'] === o.value }, [o.label]); })));
      if (f.type !== 'checkboxes') {
        pick.addEventListener('change', function () { edit(f, '', function () { f['default'] = pick.value; }); });
        items.push(field('Chosen to start with', pick));
      }
    }

    items.push(conditionEditor(f));

    // How wide the field is in its row.
    if (f.type !== 'hidden' && f.type !== 'step') {
      items.push(h('div', { 'class': cls.label }, [
        'Width',
        h('div', { 'class': 'inline-flex w-full rounded-md border border-slate-300 bg-white p-0.5', role: 'group', 'aria-label': 'Width' }, ['', 'two-thirds', 'half', 'third'].map(function (w) {
          var on = f.width === w;
          return h('button', { type: 'button', 'aria-pressed': on ? 'true' : 'false', 'class': 'flex-1 rounded px-1.5 py-1 text-xs font-semibold ' + (on ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'), onclick: function () { setWidth(f, w); } }, [WIDTH_NAMES[w]]);
        })),
        h('span', { 'class': 'font-normal text-slate-500' }, ['On a phone every field has a row to itself.'])
      ]));
    }

    // The name: what the answer is called in the emails and in the stored answers.
    var nameInput = h('input', { type: 'text', 'class': cls.input + ' font-mono text-xs', value: f.name, 'data-fb-name': '1' });
    nameInput.addEventListener('input', function () {
      edit(f, 'name:' + f.id, function () { f.nameTouched = true; renameField(f, slugify(nameInput.value) || f.name); });
    });
    nameInput.addEventListener('change', function () { renameField(f, uniqueName(f.name, f.id)); nameInput.value = f.name; changed(false); });
    items.push(h('details', { 'class': 'rounded-md border border-slate-200 bg-slate-50/60 px-3 py-2' }, [
      h('summary', { 'class': 'cursor-pointer text-xs font-medium text-slate-600' }, ['Advanced']),
      h('div', { 'class': 'mt-3 grid gap-2' }, [
        field('Field name', nameInput, f.saved ? 'Changing the name of a field that was saved separates it from the answers already received.' : 'Used for the answers and in emails, as {' + f.name + '}.')
      ])
    ]));

    items.push(h('div', { 'class': 'flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3' }, [
      h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50', onclick: function () { act(f.id, 'duplicate'); } }, [icon('copy', 'h-3.5 w-3.5'), 'Duplicate']),
      h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-white px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50', onclick: function () { act(f.id, 'remove'); } }, [icon('trash', 'h-3.5 w-3.5'), 'Delete'])
    ]));

    inspector.appendChild(h('div', { 'class': 'grid gap-4' }, items));
  }

  /** A new name for a field, and the same name in what is shown only for its answers. */
  function renameField(f, name) {
    if (name === f.name) return;
    var before = f.name;
    f.name = name;
    fields.forEach(function (x) { if (x !== f && x.condition && x.condition.field === before) x.condition.field = name; });
  }

  /**
   * "Show only when": a field earlier in the form, a test (is, is not, is answered, is not answered) and, for is and is not,
   * one of its choices (or a value typed in). The form on the site and the server follow it.
   */
  function conditionEditor(f) {
    var index = indexOf(f.id);
    var candidates = fields.slice(0, index).filter(function (x) { var d = def(x.type); return !d.display && !d.file && x.name; });
    var c = f.condition;
    var box = h('div', { 'class': 'grid gap-2 rounded-md border border-slate-200 bg-slate-50/60 p-3' });
    var on = h('input', { type: 'checkbox', 'class': 'h-4 w-4 rounded border-slate-300', checked: !!(c && c.field), disabled: !candidates.length && !(c && c.field) });
    on.addEventListener('change', function () {
      edit(f, '', function () {
        f.condition = on.checked && candidates.length ? { field: candidates[candidates.length - 1].name, op: 'is', value: '' } : null;
        if (f.condition) {
          var target = candidates[candidates.length - 1];
          if (target.options.length) f.condition.value = target.options[0].value;
          else if (target.type === 'checkbox') f.condition.op = 'filled';
          else if (target.type !== 'rating') f.condition.op = 'filled';
          else f.condition.value = String(ratingMax(target));
        }
      }, true);
    });
    box.appendChild(h('label', { 'class': 'flex items-center gap-2 text-xs font-medium text-slate-700' }, [on, f.type === 'step' ? 'Show this step only when…' : 'Show only when…']));
    if (!candidates.length && !(c && c.field)) {
      box.appendChild(h('p', { 'class': 'text-xs text-slate-500' }, ['Needs a field above this one that takes an answer.']));
      return box;
    }
    if (!c || !c.field) {
      box.appendChild(h('p', { 'class': 'text-xs text-slate-500' }, [f.type === 'step' ? 'Shown always. Tick to skip the step for some answers.' : 'Shown always. Tick to show it only for some answer to a field above.']));
      return box;
    }
    var target = fields.filter(function (x) { return x.name === c.field; })[0];
    var pickField = h('select', { 'class': cls.input, 'aria-label': 'The field it depends on' }, (target && candidates.indexOf(target) === -1 ? [target] : []).concat(candidates).map(function (x) {
      return h('option', { value: x.name, selected: x.name === c.field }, [(x.label || x.name)]);
    }).concat(target ? [] : [h('option', { value: c.field, selected: true }, [c.field + ' (gone)'])]));
    pickField.addEventListener('change', function () {
      edit(f, '', function () {
        var t2 = fields.filter(function (x) { return x.name === pickField.value; })[0];
        f.condition.field = pickField.value;
        f.condition.op = t2 && (t2.options.length || t2.type === 'rating') ? 'is' : 'filled';
        f.condition.value = t2 && t2.options.length ? t2.options[0].value : (t2 && t2.type === 'rating' ? String(ratingMax(t2)) : '');
      }, true);
    });
    box.appendChild(pickField);
    var isCheck = target && target.type === 'checkbox';
    var opNames = isCheck ? { filled: 'is ticked', empty: 'is not ticked' } : OPS;
    var pickOp = h('select', { 'class': cls.input, 'aria-label': 'Test' }, Object.keys(opNames).map(function (op) { return h('option', { value: op, selected: op === c.op }, [opNames[op]]); }));
    pickOp.addEventListener('change', function () { edit(f, '', function () { f.condition.op = pickOp.value; }, true); });
    box.appendChild(pickOp);
    if (c.op === 'is' || c.op === 'not') {
      var choices = target ? (target.type === 'rating' ? Array.apply(null, Array(ratingMax(target))).map(function (_, n) { return { value: String(n + 1), label: (n + 1) + ' of ' + ratingMax(target) }; }) : target.options) : [];
      var pickValue;
      if (choices.length) {
        pickValue = h('select', { 'class': cls.input, 'aria-label': 'Answer' }, choices.map(function (o) { return h('option', { value: o.value, selected: o.value === c.value }, [o.label]); }));
        if (!choices.some(function (o) { return o.value === c.value; })) { c.value = choices[0].value; }
        pickValue.addEventListener('change', function () { edit(f, '', function () { f.condition.value = pickValue.value; }); });
      } else {
        pickValue = h('input', { type: 'text', 'class': cls.input, value: c.value, placeholder: 'The answer', 'aria-label': 'Answer' });
        pickValue.addEventListener('input', function () { edit(f, 'cond:' + f.id, function () { f.condition.value = pickValue.value.trim(); }); });
      }
      box.appendChild(pickValue);
      if (!String(c.value || '').trim()) box.appendChild(h('p', { 'class': 'text-xs text-amber-700' }, ['Give the answer, or it is shown always.']));
    }
    if (target && target.type === 'checkboxes' && (c.op === 'is' || c.op === 'not')) {
      box.appendChild(h('p', { 'class': 'text-xs text-slate-500' }, [c.op === 'is' ? 'Shown when this choice is among those ticked.' : 'Shown when this choice is not ticked.']));
    }
    return box;
  }

  /* ---------- file upload fields ---------- */

  var GREEK_LETTERS = { 'α': 'a', 'β': 'v', 'γ': 'g', 'δ': 'd', 'ε': 'e', 'ζ': 'z', 'η': 'i', 'θ': 'th', 'ι': 'i', 'κ': 'k', 'λ': 'l', 'μ': 'm', 'ν': 'n', 'ξ': 'x', 'ο': 'o', 'π': 'p', 'ρ': 'r', 'σ': 's', 'τ': 't', 'υ': 'y', 'φ': 'f', 'χ': 'ch', 'ψ': 'ps', 'ω': 'o', 'ϊ': 'i', 'ϋ': 'y' };
  var VOICELESS = ['κ', 'ξ', 'π', 'σ', 'τ', 'φ', 'χ', 'ψ'];

  /** The address slug of the server (Slug::fromText): Greek to Latin with the ELOT digraphs, accents dropped, anything else a dash. */
  function elotSlug(text) {
    text = String(text || '').trim().toLowerCase().replace(/[άέήίόύώςΐΰ]/g, function (c) { return { 'ά': 'α', 'έ': 'ε', 'ή': 'η', 'ί': 'ι', 'ό': 'ο', 'ύ': 'υ', 'ώ': 'ω', 'ς': 'σ', 'ΐ': 'ϊ', 'ΰ': 'ϋ' }[c]; });
    text = text.replace(/[À-ÿ]/g, function (c) { return c.normalize('NFD').replace(/[̀-ͯ]/g, ''); });
    var chars = Array.from(text);
    var out = '';
    for (var i = 0; i < chars.length; i++) {
      var c = chars[i], next = chars[i + 1] || '', pair = c + next;
      var start = i === 0 || !GREEK_LETTERS[chars[i - 1]];
      var after = chars[i + 2];
      var voiceless = next === '' || after === undefined || VOICELESS.indexOf(after) !== -1 || !GREEK_LETTERS[after];
      var digraph = null;
      switch (pair) {
        case 'ου': digraph = 'ou'; break;
        case 'ει': digraph = 'ei'; break;
        case 'οι': digraph = 'oi'; break;
        case 'αι': digraph = 'ai'; break;
        case 'υι': digraph = 'yi'; break;
        case 'αυ': digraph = voiceless ? 'af' : 'av'; break;
        case 'ευ': digraph = voiceless ? 'ef' : 'ev'; break;
        case 'ηυ': digraph = voiceless ? 'if' : 'iv'; break;
        case 'μπ': digraph = start ? 'b' : 'mp'; break;
        case 'ντ': digraph = start ? 'd' : 'nt'; break;
        case 'γκ': digraph = start ? 'g' : 'gk'; break;
        case 'γγ': digraph = 'ng'; break;
        case 'γξ': digraph = 'nx'; break;
        case 'γχ': digraph = 'nch'; break;
      }
      if (digraph !== null) { out += digraph; i++; continue; }
      out += GREEK_LETTERS[c] !== undefined ? GREEK_LETTERS[c] : c;
    }
    out = out.replace(/[^a-z0-9\-_]+/g, '-').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '');
    if (out.length > 80) {
      var cut = out.slice(0, 80), dash = cut.lastIndexOf('-');
      out = (dash > 20 ? cut.slice(0, dash) : cut).replace(/[-_]+$/, '');
    }
    return out;
  }

  /** What the server will accept, as one line of text: the number, the size and the kinds. */
  function uploadHint(f) {
    var u = f.upload, size = effectiveMb(u);
    var kinds = u.extensions.length ? u.extensions.map(function (e) { return '.' + e; }).join(' ') : 'documents, pictures and archives';
    return (u.multiple ? 'Up to ' + u.max + ' files, ' : 'Up to ') + formatMb(size) + (u.multiple ? ' each' : '') + '. ' + kinds;
  }

  function formatMb(mb) { return (Math.round(mb * 10) / 10) + ' MB'; }

  /** The most one file can be here: the size asked for, never more than the server or the site allows. */
  function effectiveMb(u) {
    var caps = [UPLOAD.server_file, UPLOAD.server_post, UPLOAD.site_file].filter(function (n) { return n > 0; }).map(function (n) { return n / 1048576; });
    return Math.min.apply(null, [u.max_size_mb].concat(caps));
  }
  function capMb() {
    var caps = [UPLOAD.server_file, UPLOAD.server_post, UPLOAD.site_file].filter(function (n) { return n > 0; }).map(function (n) { return n / 1048576; });
    return caps.length ? Math.min.apply(null, caps) : 0;
  }

  /** A made-up answer for a field, to show what a file name pattern will make. */
  function sampleAnswer(g) {
    var key = (g.name + ' ' + g.label).toLowerCase();
    if (/sur|last|eponym|επώνυμο/.test(key)) return 'Παπαδόπουλος';
    if (/mail/.test(key)) return 'giorgos@example.com';
    if (/phone|tel|τηλ/.test(key)) return '6900000000';
    if (/name|first|όνομα/.test(key)) return 'Γιώργος';
    if (/city|πόλη/.test(key)) return 'Αθήνα';
    return g['default'] || g.label || 'value';
  }

  function phpDate(format, d) {
    var two = function (n) { return (n < 10 ? '0' : '') + n; };
    var map = { Y: d.getFullYear(), y: two(d.getFullYear() % 100), m: two(d.getMonth() + 1), n: d.getMonth() + 1, d: two(d.getDate()), j: d.getDate(), H: two(d.getHours()), G: d.getHours(), i: two(d.getMinutes()), s: two(d.getSeconds()) };
    return String(format).replace(/./g, function (c) { return map[c] !== undefined ? map[c] : c; });
  }

  var PATTERN_TOKENS = /\{(field:[A-Za-z0-9_-]+|form|date(?::[^{}]*)?|time|submission|index|original|random|lang)\}/g;

  /** The name a pattern makes for a sample file, the way the server makes it. */
  function previewName(f, original, index) {
    var u = f.upload, now = new Date();
    var ext = (original.split('.').pop() || 'pdf').toLowerCase();
    var base = original.replace(/\.[^.]+$/, '');
    var text = (u.rename.trim() || '{original}').replace(PATTERN_TOKENS, function (m, token) {
      if (token.indexOf('field:') === 0) {
        var name = token.slice(6), g = fields.filter(function (x) { return x.name === name && x.type !== 'file'; })[0];
        return g ? sampleAnswer(g) : '';
      }
      if (token === 'date') return phpDate('Y-m-d', now);
      if (token.indexOf('date:') === 0) return phpDate(token.slice(5) || 'Y-m-d', now);
      switch (token) {
        case 'time': return phpDate('His', now);
        case 'form': return data.slug || 'contact';
        case 'submission': return '20261015-143005-a1b2c3d4';
        case 'index': return String(index);
        case 'original': return base;
        case 'random': return 'a3f9c1';
        case 'lang': return state.lang || data.default || 'en';
      }
      return '';
    });
    return (elotSlug(text) || elotSlug(base) || 'file') + '.' + ext;
  }

  function uploadEditor(f) {
    var u = f.upload;
    var parts = [];
    var num = function (key, labelText, attrs, hint, parse) {
      var inp = h('input', Object.assign({ type: 'number', 'class': cls.input, value: u[key] || '' }, attrs));
      inp.addEventListener('input', function () { edit(f, 'up-' + key + ':' + f.id, function () { u[key] = parse(inp.value); }, false); refreshUploadNotes(); });
      return field(labelText, inp, hint);
    };

    parts.push(toggle('Allow several files', u.multiple, function (on) { edit(f, '', function () { u.multiple = on; }, true); }));
    if (u.multiple) {
      parts.push(h('div', { 'class': 'grid grid-cols-2 gap-2' }, [
        num('min', 'At least', { min: '0', max: String(UPLOAD.max_files), step: '1', placeholder: '0' }, '', function (v) { return Math.max(0, Math.min(UPLOAD.max_files, parseInt(v, 10) || 0)); }),
        num('max', 'At most', { min: '1', max: String(UPLOAD.max_files), step: '1' }, '', function (v) { return Math.max(1, Math.min(UPLOAD.max_files, parseInt(v, 10) || 1)); })
      ]));
    }
    parts.push(h('div', { 'class': 'grid grid-cols-2 gap-2' }, [
      num('max_size_mb', 'Size of each file (MB)', { min: '0.1', step: '0.1' }, '', function (v) { return parseFloat(v) > 0 ? parseFloat(v) : UPLOAD.default_mb; }),
      num('total_mb', 'All files together (MB)', { min: '0', step: '0.1', placeholder: 'No limit' }, '', function (v) { return parseFloat(v) > 0 ? parseFloat(v) : 0; })
    ]));
    var limitNote = h('p', { 'class': 'text-xs text-slate-500', 'data-fb-limit-note': '1', role: 'status' });
    parts.push(limitNote);
    function refreshUploadNotes() {
      var cap = capMb();
      var eff = effectiveMb(u);
      limitNote.textContent = 'Effective limit for one file: ' + formatMb(eff) + (cap > 0 ? '. This server and site allow up to ' + formatMb(cap) + ' (upload_max_filesize, post_max_size and the site\'s upload limit).' : '.');
      limitNote.className = 'text-xs ' + (cap > 0 && u.max_size_mb > cap ? 'font-medium text-amber-700' : 'text-slate-500');
    }
    refreshUploadNotes();

    // Allowed kinds: a chip for each group, and a free list for the rest.
    var chips = h('div', { 'class': 'flex flex-wrap gap-1.5', role: 'group', 'aria-label': 'Allowed kinds of file' });
    var presetNames = Object.keys(UPLOAD.presets);
    var onKeys = [];
    /** What the chips that are on stand for; the list below holds the rest (a group that is only partly chosen too). */
    function covered() {
      var all = [];
      onKeys.forEach(function (key) { all = all.concat(UPLOAD.presets[key].extensions); });
      return all;
    }
    function drawChips() {
      onKeys = presetNames.filter(function (key) { return UPLOAD.presets[key].extensions.every(function (e) { return u.extensions.indexOf(e) !== -1; }); });
      chips.textContent = '';
      presetNames.forEach(function (key) {
        var preset = UPLOAD.presets[key];
        var on = preset.extensions.every(function (e) { return u.extensions.indexOf(e) !== -1; });
        chips.appendChild(h('button', {
          type: 'button', 'aria-pressed': on ? 'true' : 'false', title: preset.extensions.map(function (e) { return '.' + e; }).join(' '),
          'class': 'rounded-full border px-2.5 py-1 text-xs font-medium ' + (on ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'),
          onclick: function () {
            edit(f, '', function () {
              u.extensions = on ? u.extensions.filter(function (e) { return preset.extensions.indexOf(e) === -1; }) : u.extensions.concat(preset.extensions.filter(function (e) { return u.extensions.indexOf(e) === -1; }));
            });
            drawChips(); extra.value = extras();
          }
        }, [preset.label]));
      });
    }
    function extras() { return u.extensions.filter(function (e) { return covered().indexOf(e) === -1; }).join(', '); }
    drawChips();
    var extra = h('input', { type: 'text', 'class': cls.input + ' font-mono text-xs', value: extras(), placeholder: 'dwg, mp3, …' });
    var refusedNote = h('span', { 'class': 'font-normal text-amber-700', 'data-fb-refused': '1' });
    extra.addEventListener('input', function () {
      var list = extra.value.split(/[\s,;]+/).map(function (e) { return e.replace(/^[.*]+/, '').toLowerCase(); }).filter(function (e) { return /^[a-z0-9]{1,8}$/.test(e); });
      var refused = list.filter(function (e) { return UPLOAD.blocked.indexOf(e) !== -1; });
      refusedNote.textContent = refused.length ? refused.map(function (e) { return '.' + e; }).join(' ') + ' can never be accepted.' : '';
      edit(f, 'up-ext:' + f.id, function () {
        var keep = covered().filter(function (e) { return u.extensions.indexOf(e) !== -1; });
        list.forEach(function (e) { if (UPLOAD.blocked.indexOf(e) === -1 && keep.indexOf(e) === -1) keep.push(e); });
        u.extensions = keep;
      }, false);
      drawChips();
    });
    drawChips();
    parts.push(h('div', { 'class': 'grid gap-1.5' }, [
      h('span', { 'class': 'text-xs font-medium text-slate-600' }, ['Allowed kinds']),
      chips,
      field('Other extensions', extra, 'The content of every file is checked against its extension. Programs, scripts, web pages and SVG are never accepted.'),
      refusedNote
    ]));

    // The name of the files: text and tokens, with a preview.
    var rename = h('input', { type: 'text', 'class': cls.input + ' font-mono text-xs', value: u.rename, placeholder: '{original}', maxlength: '200', 'aria-describedby': 'fb-rename-preview' });
    var preview = h('p', { id: 'fb-rename-preview', 'class': 'break-all rounded-md bg-slate-50 px-2.5 py-1.5 font-mono text-xs text-slate-700', 'data-fb-rename-preview': '1' });
    var unknown = h('p', { 'class': 'text-xs text-amber-700', 'data-fb-rename-unknown': '1' });
    function drawPreview() {
      var samples = u.multiple ? ['My CV.pdf', 'Photo.jpg', 'Photo.jpg'] : ['My CV.pdf'];
      preview.textContent = '';
      samples.slice(0, u.multiple ? 3 : 1).forEach(function (name, i) {
        var made = previewName(f, name, i + 1);
        preview.appendChild(h('span', { 'class': 'block' }, [h('span', { 'class': 'text-slate-400' }, [name + '  →  ']), made]));
      });
      var missing = [];
      (u.rename.match(PATTERN_TOKENS) || []).forEach(function (m) {
        if (m.indexOf('{field:') === 0) { var n = m.slice(7, -1); if (!fields.some(function (x) { return x.name === n && x.type !== 'file' && !def(x.type).display; })) missing.push(n); }
      });
      unknown.textContent = missing.length ? missing.map(function (n) { return '{field:' + n + '}'; }).join(' ') + ' is not a field of this form, so it stays empty.' : '';
    }
    rename.addEventListener('input', function () { edit(f, 'up-rename:' + f.id, function () { u.rename = rename.value; }, false); drawPreview(); });
    var tokenList = [['{original}', 'the name it had'], ['{form}', 'the form'], ['{date}', 'the date, or {date:d-m-Y}'], ['{time}', 'the time'], ['{submission}', 'the submission'], ['{index}', 'the number of the file in this field'], ['{random}', 'a short random code'], ['{lang}', 'the language']];
    fields.filter(function (g) { return g.id !== f.id && g.type !== 'file' && !def(g.type).display && g.name; }).slice(0, 12).forEach(function (g) {
      tokenList.push(['{field:' + g.name + '}', 'the answer to “' + (g.label || g.name) + '”']);
    });
    var tokens = h('div', { 'class': 'flex flex-wrap gap-1', role: 'group', 'aria-label': 'Add to the name' }, tokenList.map(function (t) {
      return h('button', {
        type: 'button', title: t[1], 'class': 'rounded border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-[11px] text-slate-700 hover:bg-slate-50',
        onclick: function () {
          var at = typeof rename.selectionStart === 'number' ? rename.selectionStart : rename.value.length;
          var to = typeof rename.selectionEnd === 'number' ? rename.selectionEnd : at;
          rename.value = rename.value.slice(0, at) + t[0] + rename.value.slice(to);
          rename.focus();
          rename.setSelectionRange(at + t[0].length, at + t[0].length);
          rename.dispatchEvent(new Event('input'));
        }
      }, [t[0]]);
    }));
    drawPreview();
    parts.push(h('div', { 'class': 'grid gap-1.5' }, [
      field('Name of the files', rename, 'Text and the parts below. The extension is always the real one; a name already used gets -2, -3.'),
      tokens, preview, unknown
    ]));

    // Texts: the button and the messages (translated like the label).
    function textBox(key, labelText, placeholder) {
      var inp = h('input', { type: 'text', 'class': cls.input, value: f[key], placeholder: placeholder });
      inp.addEventListener('input', function () { edit(f, key + ':' + f.id, function () { f[key] = inp.value; }, false); });
      return field(labelText, inp);
    }
    parts.push(textBox('button', 'Button text', u.multiple ? 'Choose files' : 'Choose a file'));
    parts.push(h('details', { 'class': 'rounded-md border border-slate-200 bg-slate-50/60 px-3 py-2' }, [
      h('summary', { 'class': 'cursor-pointer text-xs font-medium text-slate-600' }, ['Messages when something is wrong']),
      h('div', { 'class': 'mt-3 grid gap-2' }, [
        h('p', { 'class': 'text-xs text-slate-500' }, ['Leave empty for the site\'s own wording. You can use {name}, {max}, {types} and {count}.']),
        textBox('error_size', 'Too big', '"{name}" is larger than {max}.'),
        textBox('error_type', 'Wrong kind of file', '"{name}" is not an accepted kind of file. Allowed: {types}.'),
        textBox('error_max', 'Too many files', 'You can add at most {count} files.'),
        textBox('error_min', 'Too few files', 'Please add at least {count} files.')
      ])
    ]));
    return h('div', { 'class': 'grid gap-3 rounded-md border border-slate-200 p-3', 'data-fb-upload': '1' }, parts);
  }

  function toggle(text, checked, onchange) {
    var box = h('input', { type: 'checkbox', 'class': 'peer sr-only', checked: checked ? true : false });
    box.addEventListener('change', function () { onchange(box.checked); });
    return h('label', { 'class': 'flex cursor-pointer items-center gap-3 text-sm text-slate-800' }, [
      h('span', { 'class': 'relative inline-flex shrink-0' }, [
        box,
        h('span', { 'class': 'h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-1', 'aria-hidden': 'true' }),
        h('span', { 'class': 'absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4', 'aria-hidden': 'true' })
      ]),
      text
    ]);
  }

  function setWidth(f, width) {
    record('');
    f.width = width;
    var li = canvas.querySelector('[data-id="' + f.id + '"]');
    if (li) li.replaceWith(buildCard(f));
    renderInspector();
    changed(false);
  }

  /* ---------- the choices of a field ---------- */

  function optionsEditor(f) {
    var showValues = false;
    var box = h('div', { 'class': 'grid gap-2' });
    var list = h('ul', { 'class': 'grid gap-1.5' });

    function redraw() {
      list.textContent = '';
      f.options.forEach(function (opt, i) {
        var labelInput = h('input', { type: 'text', 'class': cls.input + ' py-1.5', value: opt.label, 'aria-label': 'Choice ' + (i + 1) });
        labelInput.addEventListener('input', function () {
          edit(f, 'opt:' + f.id + ':' + i, function () {
            var before = opt.value;
            opt.label = labelInput.value;
            if (!opt.touched) opt.value = uniqueValue(f.options, slugify(opt.label) || 'option-' + (i + 1), opt);
            renameChoice(f, before, opt.value);
          });
          if (showValues && !opt.touched) { var vi = list.children[i] && list.children[i].querySelector('[data-value]'); if (vi) vi.value = opt.value; }
        });
        var valueInput = showValues ? h('input', { type: 'text', 'class': cls.input + ' w-24 py-1.5 font-mono text-xs', value: opt.value, 'data-value': '1', 'aria-label': 'Value of choice ' + (i + 1), title: 'The value that is stored and sent' }) : null;
        if (valueInput) valueInput.addEventListener('input', function () { edit(f, 'optv:' + f.id + ':' + i, function () { var before = opt.value; opt.touched = true; opt.value = valueInput.value.replace(/\|/g, '/'); renameChoice(f, before, opt.value); }); });
        var grip = h('button', {
          type: 'button',
          'class': 'inline-flex h-8 w-6 shrink-0 cursor-grab touch-none select-none items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing',
          'aria-label': 'Move choice ' + (i + 1),
          onpointerdown: function (e) { beginOptionDrag(e, i, list, f); },
          onkeydown: function (e) {
            if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
            e.preventDefault();
            var to = e.key === 'ArrowUp' ? i - 1 : i + 1;
            if (to < 0 || to >= f.options.length) return;
            record('');
            f.options.splice(to, 0, f.options.splice(i, 1)[0]);
            refreshCard(f);
            changed(false);
            redraw();
            var again = list.children[to] && list.children[to].querySelector('button');
            if (again) again.focus();
          }
        }, [icon('grip', 'h-4 w-4')]);
        var remove = h('button', { type: 'button', 'class': 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-slate-400 hover:bg-red-50 hover:text-red-600', 'aria-label': 'Remove choice ' + (i + 1), onclick: function () { record(''); f.options.splice(i, 1); refreshCard(f); changed(false); redraw(); if (f['default'] && !f.options.some(function (o) { return o.value === f['default']; })) f['default'] = ''; } }, [icon('x', 'h-4 w-4')]);
        list.appendChild(h('li', { 'class': 'flex items-center gap-1', 'data-option': i }, [grip, labelInput, valueInput, remove]));
      });
    }
    redraw();

    var add = h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50', onclick: function () {
      record('');
      var n = f.options.length + 1;
      f.options.push({ value: uniqueValue(f.options, 'option-' + n), label: 'Option ' + n, touched: false });
      refreshCard(f);
      changed(false);
      redraw();
      var last = list.lastChild && list.lastChild.querySelector('input');
      if (last) { last.focus(); last.select(); }
    } }, [icon('plus', 'h-3.5 w-3.5'), 'Add a choice']);
    var values = h('button', { type: 'button', 'class': 'rounded-md px-2 py-1.5 text-xs font-medium text-slate-600 underline hover:text-slate-900', 'aria-pressed': 'false', onclick: function () { showValues = !showValues; values.setAttribute('aria-pressed', showValues ? 'true' : 'false'); values.textContent = showValues ? 'Hide values' : 'Show values'; redraw(); } }, ['Show values']);
    var bulk = h('details', { 'class': 'rounded-md border border-slate-200 bg-slate-50/60 px-3 py-2' }, [
      h('summary', { 'class': 'cursor-pointer text-xs font-medium text-slate-600' }, ['Paste a list']),
      (function () {
        var area = h('textarea', { 'class': cls.input + ' mt-2', rows: 4, placeholder: 'One choice per line' });
        var apply = h('button', { type: 'button', 'class': 'mt-2 rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-700', onclick: function () {
          var lines = area.value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
          if (!lines.length) return;
          record('');
          lines.forEach(function (line) {
            var parts = line.split('|');
            var label = (parts[1] !== undefined ? parts[1] : parts[0]).trim();
            var value = parts[1] !== undefined ? slugify(parts[0]) || parts[0].trim() : slugify(label);
            f.options.push({ value: uniqueValue(f.options, value || 'option'), label: label, touched: parts[1] !== undefined });
          });
          area.value = '';
          refreshCard(f);
          changed(false);
          redraw();
        } }, ['Add these']);
        return h('div', {}, [area, apply]);
      })()
    ]);
    box.appendChild(h('div', { 'class': 'flex items-center justify-between' }, [h('span', { 'class': 'text-xs font-medium text-slate-600' }, ['Choices']), values]));
    box.appendChild(list);
    box.appendChild(h('div', {}, [add]));
    box.appendChild(bulk);
    return box;
  }

  /** A choice whose value changed keeps its translations. */
  function renameChoice(f, from, to) {
    if (from === to) return;
    fields.forEach(function (x) { if (x.condition && x.condition.field === f.name && x.condition.value === from) x.condition.value = to; });
    Object.keys(f.tr || {}).forEach(function (lang) {
      var o = f.tr[lang] && f.tr[lang].options;
      if (o && Object.prototype.hasOwnProperty.call(o, from)) { o[to] = o[from]; delete o[from]; }
    });
  }

  /** Reordering the choices of a field by dragging the grip of one (vertical, within the list). */
  function beginOptionDrag(event, index, list, f) {
    if (event.button !== undefined && event.button !== 0) return;
    var rows = Array.prototype.slice.call(list.children);
    var start = event.clientY;
    var moved = false;
    var target = index;
    var line = h('div', { 'class': 'pointer-events-none absolute left-0 right-0 hidden h-0.5 rounded-full bg-blue-500' });
    list.style.position = 'relative';
    list.appendChild(line);
    function onMove(e) {
      if (!moved && Math.abs(e.clientY - start) < 4) return;
      moved = true;
      rows[index].classList.add('opacity-40');
      var best = rows.length;
      for (var n = 0; n < rows.length; n++) {
        var box = rows[n].getBoundingClientRect();
        if (e.clientY < box.top + box.height / 2) { best = n; break; }
      }
      target = best;
      var box2 = list.getBoundingClientRect();
      var y = best < rows.length ? rows[best].getBoundingClientRect().top - box2.top - 3 : rows[rows.length - 1].getBoundingClientRect().bottom - box2.top + 1;
      line.style.top = y + 'px';
      line.classList.remove('hidden');
    }
    function onUp() {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      if (line.parentNode) line.parentNode.removeChild(line);
      rows[index].classList.remove('opacity-40');
      if (!moved) return;
      var to = target > index ? target - 1 : target;
      if (to === index) return;
      record('');
      f.options.splice(to, 0, f.options.splice(index, 1)[0]);
      refreshCard(f);
      changed(false);
      renderInspector();
    }
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  }

  /* ---------- changes made with buttons and keys ---------- */

  function act(id, what) {
    if (state.lang !== '') return; // the structure is the first language's
    var i = indexOf(id);
    if (i < 0) return;
    var f = fields[i];
    if (what === 'remove') {
      record('');
      fields.splice(i, 1);
      // What was shown only for an answer of this field is shown always again.
      if (!fields.some(function (x) { return x.name === f.name; })) fields.forEach(function (x) { if (x.condition && x.condition.field === f.name) x.condition = null; });
      if (state.selected === id) state.selected = fields.length ? fields[Math.min(i, fields.length - 1)].id : 0;
      changed(true);
      announce('Removed ' + (f.label || f.name));
    } else if (what === 'duplicate') {
      record('');
      var copy = makeField(JSON.parse(JSON.stringify(plain(f))));
      copy.saved = false;
      copy.nameTouched = false;
      copy.label = f.label + (def(f.type).display ? '' : ' (copy)');
      copy.name = uniqueName(slugify(f.name) + '-copy', copy.id);
      copy.options.forEach(function (o, n) { o.touched = f.options[n] ? f.options[n].touched : false; });
      fields.splice(i + 1, 0, copy);
      state.selected = copy.id;
      changed(true);
      announce('Duplicated');
    } else if (what === 'up' || what === 'down') {
      var to = what === 'up' ? i - 1 : i + 1;
      if (to < 0 || to >= fields.length) return;
      record('');
      fields.splice(to, 0, fields.splice(i, 1)[0]);
      changed(true);
      refocusHandle(id);
      announce('Moved ' + (f.label || f.name) + ' to position ' + (to + 1) + ' of ' + fields.length);
    } else if ((what === 'narrower' || what === 'wider') && f.type !== 'step') {
      var at = WIDTH_ORDER.indexOf(f.width);
      var next = what === 'narrower' ? Math.max(0, at - 1) : Math.min(WIDTH_ORDER.length - 1, at + 1);
      if (next === at) return;
      record('');
      f.width = WIDTH_ORDER[next];
      changed(true);
      refocusHandle(id);
      announce((f.label || f.name) + ': ' + WIDTH_NAMES[f.width] + ' width');
    }
  }

  function refocusHandle(id) {
    var li = canvas.querySelector('[data-id="' + id + '"] .fb-handle');
    if (li) li.focus();
  }

  function handleKey(event, f) {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    var map = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'narrower', ArrowRight: 'wider' };
    if (!map[event.key]) return;
    event.preventDefault();
    select(f.id);
    act(f.id, map[event.key]);
  }

  function cardKey(event, f) {
    if (event.target !== event.currentTarget) return;
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(f.id, { focus: true, force: true }); }
    else if (event.key === 'Delete' || event.key === 'Backspace') { event.preventDefault(); act(f.id, 'remove'); }
  }

  /* ---------- adding fields ---------- */

  function addField(type, extra, at) {
    if (state.lang !== '') return null;
    var f = newField(type, extra);
    record('');
    fields.splice(at === undefined ? fields.length : at, 0, f);
    state.selected = f.id;
    changed(true);
    var li = canvas.querySelector('[data-id="' + f.id + '"]');
    if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    announce('Added ' + def(type).label);
    return f;
  }

  function renderPalette() {
    palette.textContent = '';
    palette.appendChild(h('div', { 'class': 'mb-4' }, [
      h('p', { 'class': 'mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500' }, ['Common']),
      h('div', { 'class': 'flex flex-wrap gap-1.5' }, QUICK.map(function (q) { return paletteItem(q.label, q.type, q, true); }))
    ]));
    GROUPS.forEach(function (g) {
      var types = Object.keys(TYPES).filter(function (k) { return TYPES[k].group === g; });
      palette.appendChild(h('div', { 'class': 'mb-4' }, [
        h('p', { 'class': 'mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500' }, [g]),
        h('div', { 'class': 'grid grid-cols-2 gap-1.5 lg:grid-cols-1' }, types.map(function (k) { return paletteItem(TYPES[k].label, k, null, false); }))
      ]));
    });
    quickEl.textContent = '';
    QUICK.slice(0, 4).forEach(function (q) {
      quickEl.appendChild(h('button', { type: 'button', 'class': 'inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50', onclick: function () { addField(q.type, q); } }, [icon('plus', 'h-3.5 w-3.5'), q.label]));
    });
  }

  function paletteItem(label, type, extra, compact) {
    var t = def(type);
    var btn = h('button', {
      type: 'button',
      'class': 'fb-palette-item flex select-none items-center gap-2 rounded-md border border-slate-200 bg-white text-left text-sm text-slate-800 hover:border-blue-400 hover:bg-blue-50 ' + (compact ? 'px-2 py-1 text-xs font-medium' : 'px-2 py-1.5'),
      'aria-label': 'Add ' + label,
      onclick: function () { addField(type, extra, insertionAfterSelected()); },
      onpointerdown: function (e) { beginPaletteDrag(e, label, type, extra); }
    }, [compact ? null : h('span', { 'class': 'inline-flex h-6 min-w-7 items-center justify-center rounded bg-slate-100 px-1 text-[11px] font-semibold text-slate-600', 'aria-hidden': 'true' }, [t.glyph]), label]);
    return btn;
  }

  /** A field added with a click goes after the selected one, or at the end. */
  function insertionAfterSelected() {
    var i = indexOf(state.selected);
    return i < 0 ? fields.length : i + 1;
  }

  /* ---------- dragging ---------- */

  var drag = null;
  var ghost = null;

  function beginDrag(event, f) {
    if (event.button !== undefined && event.button !== 0) return;
    drag = { kind: 'field', id: f.id, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, active: false, drop: null, label: f.label || f.name };
    listen();
  }
  function beginPaletteDrag(event, label, type, extra) {
    if (event.button !== 0 || event.pointerType === 'touch') return;
    drag = { kind: 'palette', type: type, extra: extra, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, active: false, drop: null, label: label };
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
      drag.active = true;
      document.body.classList.add('fb-dragging');
      if (drag.kind === 'field') {
        var li = canvas.querySelector('[data-id="' + drag.id + '"]');
        if (li) li.classList.add('opacity-40');
      }
      ghost = h('div', { 'class': 'fb-drag-ghost' }, [drag.label]);
      document.body.appendChild(ghost);
      if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
    }
    event.preventDefault();
    ghost.style.transform = 'translate(' + (drag.x + 14) + 'px,' + (drag.y + 10) + 'px)';
    drag.drop = computeDrop();
    paintDrop();
    autoScroll();
  }

  /** Where the dragged field would go: an index in the list, and the line to draw (a vertical one between two fields in a row). */
  function computeDrop() {
    var box = wrap.getBoundingClientRect();
    if (drag.x < box.left - 30 || drag.x > box.right + 30 || drag.y < box.top - 30 || drag.y > box.bottom + 30) return null;
    var cards = Array.prototype.slice.call(canvas.children).filter(function (li) { return !(drag.kind === 'field' && Number(li.getAttribute('data-id')) === drag.id); });
    if (!cards.length) {
      return { index: 0, vertical: false, top: canvas.getBoundingClientRect().top - box.top, left: canvas.getBoundingClientRect().left - box.left, size: canvas.getBoundingClientRect().width };
    }
    var best = null;
    var bestDistance = Infinity;
    cards.forEach(function (li) {
      var r = li.getBoundingClientRect();
      var dx = drag.x < r.left ? r.left - drag.x : (drag.x > r.right ? drag.x - r.right : 0);
      var dy = drag.y < r.top ? r.top - drag.y : (drag.y > r.bottom ? drag.y - r.bottom : 0);
      var distance = dx * dx + dy * dy * 4;
      if (distance < bestDistance) { bestDistance = distance; best = li; }
    });
    var r2 = best.getBoundingClientRect();
    var narrow = best.getAttribute('data-width') !== '' && !stage.classList.contains('fb-narrow');
    var before = narrow ? drag.x < r2.left + r2.width / 2 : drag.y < r2.top + r2.height / 2;
    var at = indexOf(Number(best.getAttribute('data-id')));
    // The index is in the list as it is now; a move takes the dragged field out first (see onUp).
    var index = at + (before ? 0 : 1);
    if (narrow) {
      return { index: index, vertical: true, adopt: best.getAttribute('data-width'), top: r2.top - box.top, left: (before ? r2.left - 7 : r2.right + 5) - box.left, size: r2.height };
    }
    return { index: index, vertical: false, top: (before ? r2.top - 7 : r2.bottom + 5) - box.top, left: r2.left - box.left, size: r2.width };
  }

  function paintDrop() {
    var d = drag.drop;
    if (!d) { dropLine.classList.add('hidden'); return; }
    dropLine.classList.remove('hidden');
    dropLine.style.top = d.top + 'px';
    dropLine.style.left = d.left + 'px';
    if (d.vertical) { dropLine.style.width = '3px'; dropLine.style.height = d.size + 'px'; }
    else { dropLine.style.width = d.size + 'px'; dropLine.style.height = '3px'; }
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
    document.body.classList.remove('fb-dragging');
    if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
    ghost = null;
    dropLine.classList.add('hidden');
    Array.prototype.forEach.call(canvas.querySelectorAll('.opacity-40'), function (li) { li.classList.remove('opacity-40'); });
    var done = drag;
    drag = null;
    return done;
  }

  function onUp() {
    var done = endDrag();
    if (!done || !done.active || !done.drop) return;
    if (done.kind === 'palette') {
      // Dropped beside a field that is narrower than the row, the new field is as wide as that one.
      var extra = Object.assign({}, done.extra);
      if (done.drop.adopt && extra.width === undefined && def(done.type).group !== 'Layout') extra.width = done.drop.adopt;
      addField(done.type, extra, done.drop.index);
      return;
    }
    var from = indexOf(done.id);
    var to = done.drop.index;
    if (to > from) to -= 1;
    if (to === from) return;
    record('');
    fields.splice(to, 0, fields.splice(from, 1)[0]);
    changed(true);
    announce('Moved to position ' + (to + 1) + ' of ' + fields.length);
  }
  function onCancel() { endDrag(); }
  function onDragKey(event) { if (event.key === 'Escape') endDrag(); }

  /* ---------- the tabs that use the fields ---------- */

  var lastTarget = null;
  root.addEventListener('focusin', function (e) { if (e.target.hasAttribute && e.target.hasAttribute('data-fb-merge-target')) lastTarget = e.target; });

  function refreshAnswers() {
    // The answers that can be put in a subject or a message.
    var named = fields.filter(function (f) { return !def(f.type).display && f.name; });
    Array.prototype.forEach.call(root.querySelectorAll('[data-fb-chips]'), function (box) {
      box.textContent = '';
      if (!named.length) { box.appendChild(h('span', { 'class': 'text-xs text-slate-500' }, ['Add fields to the form first.'])); return; }
      named.forEach(function (f) {
        box.appendChild(h('button', {
          type: 'button',
          'class': 'rounded-full border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:border-blue-400 hover:bg-blue-50',
          title: '{' + f.name + '}',
          onclick: function () {
            var section = box.closest('[data-fb-merge]');
            var target = lastTarget && section && section.contains(lastTarget) ? lastTarget : (section && section.querySelector('[data-fb-merge-target]'));
            if (!target) return;
            var token = '{' + f.name + '}';
            var start = typeof target.selectionStart === 'number' ? target.selectionStart : target.value.length;
            var end = typeof target.selectionEnd === 'number' ? target.selectionEnd : start;
            target.value = target.value.slice(0, start) + token + target.value.slice(end);
            target.focus();
            target.setSelectionRange(start + token.length, start + token.length);
            target.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }, [f.label || f.name]));
      });
    });

    // The address to reply to: one of the email fields.
    if (replyTo) {
      var current = replyTo.value || replyTo.getAttribute('data-value') || '';
      replyTo.textContent = '';
      replyTo.appendChild(h('option', { value: '' }, ['The first email field']));
      var emails = fields.filter(function (f) { return f.type === 'email' && f.name; });
      emails.forEach(function (f) { replyTo.appendChild(h('option', { value: f.name, selected: f.name === current }, [(f.label || f.name) + ' (' + f.name + ')'])); });
      if (current && !emails.some(function (f) { return f.name === current; })) replyTo.appendChild(h('option', { value: current, selected: true }, [current + ' (no such field)']));
    }
    refreshNotifyWarning();
  }

  function refreshAttach() {
    var box = root.querySelector('[data-fb-attach]');
    if (box) box.classList.toggle('hidden', !fields.some(function (f) { return f.type === 'file'; }));
  }

  function refreshNotifyWarning() {
    if (!notifyBox || !notifyWarning) return;
    notifyWarning.classList.toggle('hidden', !(notifyBox.checked && !notifyTo.value.trim()));
  }
  if (notifyBox) { notifyBox.addEventListener('change', refreshNotifyWarning); notifyTo.addEventListener('input', refreshNotifyWarning); }

  /* ---------- the toolbar, the keys, saving ---------- */

  undoBtn.addEventListener('click', undo);
  redoBtn.addEventListener('click', redo);

  Array.prototype.forEach.call(root.querySelectorAll('[data-fb-mode]'), function (btn) {
    btn.addEventListener('click', function () { state.mode = btn.getAttribute('data-fb-mode'); applyView(); });
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-fb-device]'), function (btn) {
    btn.addEventListener('click', function () { state.device = btn.getAttribute('data-fb-device'); applyView(); });
  });
  function applyView() {
    var preview = state.mode === 'preview';
    stage.classList.toggle('fb-narrow', state.device === 'phone');
    stage.style.maxWidth = state.device === 'phone' ? '24rem' : '';
    stage.classList.toggle('fb-preview', preview);
    if (layout) layout.className = preview ? LAYOUT_PREVIEW : (translating ? LAYOUT_TRANSLATE : LAYOUT_EDIT);
    var translating = state.lang !== '';
    paletteWrap.classList.toggle('hidden', preview || translating);
    inspectorWrap.classList.toggle('hidden', preview);
    Array.prototype.forEach.call(root.querySelectorAll('[data-fb-mode], [data-fb-device]'), function (btn) {
      var on = btn.hasAttribute('data-fb-mode') ? btn.getAttribute('data-fb-mode') === state.mode : btn.getAttribute('data-fb-device') === state.device;
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.className = 'rounded px-2.5 py-1 text-xs font-semibold ' + (on ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100');
    });
    root.querySelector('[data-fb-submit-row] [data-fb-edit-button]').classList.toggle('hidden', preview);
    renderCanvas();
    renderInspector();
  }

  root.querySelector('[data-fb-edit-button]').addEventListener('click', function () {
    if (window.fbShowTab) window.fbShowTab('settings');
    if (submitLabelInput) submitLabelInput.focus();
  });
  if (submitLabelInput) submitLabelInput.addEventListener('input', function () { submitPreview.textContent = submitText(); });

  document.addEventListener('keydown', function (event) {
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
    var tag = event.target && event.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    var panel = root.querySelector('[data-panel="build"]');
    if (!panel || panel.classList.contains('hidden')) return;
    var key = event.key.toLowerCase();
    if (key === 'z' && !event.shiftKey) { event.preventDefault(); undo(); }
    else if ((key === 'z' && event.shiftKey) || key === 'y') { event.preventDefault(); redo(); }
  });

  // Pressing Enter in a field of the builder must not send the form.
  root.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    var el = event.target;
    if (el.tagName === 'INPUT' && el.type !== 'submit' && el.closest('[data-panel="build"]')) event.preventDefault();
  });

  // Anything else on the screen that changes marks the form as changed.
  form.addEventListener('input', function (event) { if (!event.target.closest('[data-panel="build"]')) { otherDirty = true; refreshDirty(); } if (event.target.hasAttribute && event.target.hasAttribute('data-tr')) refreshLangBar(); });
  form.addEventListener('change', function (event) { if (!event.target.closest('[data-panel="build"]')) { otherDirty = true; refreshDirty(); } });

  form.addEventListener('submit', function (event) {
    // The boxes go back to the first language's texts (they are what the form's settings are), the translations travel in their own field.
    var open = state.lang;
    if (open !== '') { swapTexts(''); state.lang = ''; }
    if (translateField) translateField.value = open;
    if (transField) transField.value = JSON.stringify(nestedTexts());
    jsonField.value = serialize();
    submitting = true;
    window.setTimeout(function () { if (event.defaultPrevented) submitting = false; }, 0);
  });
  window.addEventListener('beforeunload', function (event) {
    if (!dirty || submitting) return;
    event.preventDefault();
    event.returnValue = '';
  });

  /* ---------- start ---------- */

  Object.keys(data.translations || {}).forEach(function (lang) {
    var row = data.translations[lang] || {};
    formTexts[lang] = {};
    Object.keys(row).forEach(function (key) {
      if (row[key] && typeof row[key] === 'object') Object.keys(row[key]).forEach(function (sub) { formTexts[lang][key + '.' + sub] = row[key][sub]; });
      else formTexts[lang][key] = row[key];
    });
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-fb-translate]'), function (btn) {
    btn.addEventListener('click', function () { setLang(btn.getAttribute('data-fb-translate')); if (window.fbShowTab) window.fbShowTab('build'); });
  });
  fields = (data.fields || []).map(function (row) { return makeField(row); });
  state.selected = fields.length ? fields[0].id : 0;
  renderPalette();
  applyView();
  refreshAnswers();
  refreshAttach();
  initial = serialize();
  // A form that starts from a template is not saved yet, so leaving it is a change.
  otherDirty = !data.existing;
  refreshDirty();
  refreshLangBar();
  if (data.open && languages.some(function (l) { return l.code === data.open; })) setLang(data.open);
})();
