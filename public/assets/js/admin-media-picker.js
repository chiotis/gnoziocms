/*
 * The image picker: one dialog, used wherever the admin chooses a picture from the media library (the main image,
 * image fields in blocks, image settings in the theme and in content types). It asks the server for a page of
 * pictures at a time (/admin/media-picker) for the words and tag typed, so a library of any size stays quick, and
 * nothing is embedded in the page.
 *
 *   GnozioMediaPicker.open(function (url, item) { ... })     open it; the callback gets the chosen picture
 *   GnozioMediaPicker.open(fn, 'image', { multiple: true, chosen: [urls] })
 *                                                            stays open: each picture clicked is given to fn, the ones in
 *                                                            `chosen` (and those picked since) are marked and not given twice
 *   <input data-image-field>                                 gets a thumbnail and a Library button automatically
 *
 * The dialog has an Upload button (and takes files dropped on it) for those who may add to the library: the files go to the
 * library by a POST to the same address, and a new picture is chosen at once (in `multiple`, each one is added).
 *
 * Without JavaScript the address can still be typed into the field.
 */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="media-picker-url"]');
  var endpoint = meta ? meta.getAttribute('content') : '';
  if (!endpoint) return;

  var CLS = {
    btn: 'inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500/30',
    input: 'rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20'
  };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      var value = attrs[key];
      if (value === false || value === null || value === undefined) return;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key === 'html') node.innerHTML = value;
      else if (key.slice(0, 2) === 'on') node.addEventListener(key.slice(2), value);
      else node.setAttribute(key, value === true ? '' : value);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(child); });
    return node;
  }

  var dialog = null;
  var state = { q: '', tag: '', page: 1, kind: 'image', multiple: false, chosen: [] };
  var callback = null;
  var canUpload = false;
  var ui = {};

  function csrf() {
    var tag = document.querySelector('meta[name="csrf-token"]');
    return tag ? tag.getAttribute('content') : '';
  }

  var ACCEPT = { image: 'image/*', video: 'video/*', audio: 'audio/*', track: '.gpx,.kml,.geojson,.json', all: '' };
  var timer = null;
  var request = 0;

  function build() {
    ui.search = el('input', { type: 'search', placeholder: 'Search by name or tag…', class: CLS.input + ' min-w-0 flex-1', 'aria-label': 'Search images' });
    ui.tag = el('select', { class: CLS.input + ' w-40', 'aria-label': 'Filter by tag' }, [el('option', { value: '', text: 'All tags' })]);
    ui.grid = el('div', { class: 'grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6', role: 'group', 'aria-label': 'Images' });
    ui.status = el('p', { class: 'px-4 pb-1 text-sm text-slate-500', role: 'status', 'aria-live': 'polite' });
    ui.info = el('span', { class: 'text-xs text-slate-500' });
    ui.prev = el('button', { type: 'button', class: CLS.btn, text: 'Previous', onclick: function () { go(state.page - 1); } });
    ui.next = el('button', { type: 'button', class: CLS.btn, text: 'Next', onclick: function () { go(state.page + 1); } });
    ui.file = el('input', { type: 'file', multiple: true, class: 'hidden', tabindex: '-1', 'aria-hidden': 'true' });
    ui.upload = el('button', { type: 'button', class: CLS.btn + ' shrink-0', style: 'display:none', onclick: function () { ui.file.click(); } });
    ui.upload.innerHTML = '<svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg><span>Upload</span>';
    ui.file.addEventListener('change', function () {
      var files = Array.prototype.slice.call(ui.file.files || []);
      ui.file.value = '';
      upload(files);
    });

    dialog = el('dialog', { class: 'w-[min(94vw,62rem)] rounded-lg border border-slate-200 p-0 shadow-xl backdrop:bg-slate-900/40', 'aria-label': 'Choose an image' }, [
      el('div', { class: 'flex flex-wrap items-center gap-3 border-b border-slate-200 p-4' }, [
        el('h2', { class: 'shrink-0 text-sm font-semibold text-slate-900', text: 'Choose an image' }),
        ui.search,
        ui.tag,
        ui.upload,
        ui.file,
        ui.close = el('button', { type: 'button', class: CLS.btn, text: 'Close', onclick: function () { dialog.close(); } })
      ]),
      el('div', { class: 'max-h-[58vh] overflow-y-auto' }, [ui.grid, ui.status]),
      el('div', { class: 'flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3' }, [ui.info, el('div', { class: 'flex gap-2' }, [ui.prev, ui.next])])
    ]);
    document.body.appendChild(dialog);

    ui.search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { state.q = ui.search.value.trim(); state.page = 1; load(); }, 250);
    });
    ui.tag.addEventListener('change', function () { state.tag = ui.tag.value; state.page = 1; load(); });
    // Files dropped anywhere on the dialog are uploaded as the button's are.
    dialog.addEventListener('dragover', function (event) {
      if (!canUpload || !event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') === -1) return;
      event.preventDefault();
      dialog.style.outline = '2px dashed #3b82f6';
    });
    dialog.addEventListener('dragleave', function (event) {
      if (event.target === dialog || !dialog.contains(event.relatedTarget)) dialog.style.outline = '';
    });
    dialog.addEventListener('drop', function (event) {
      dialog.style.outline = '';
      if (!canUpload || !event.dataTransfer || !event.dataTransfer.files.length) return;
      event.preventDefault();
      upload(Array.prototype.slice.call(event.dataTransfer.files));
    });
    // The close event comes a moment later: a dialog opened again by then keeps its new callback.
    dialog.addEventListener('close', function () { if (!dialog.open) callback = null; });
  }

  function upload(files) {
    if (!files.length || !canUpload) return;
    var body = new FormData();
    files.forEach(function (file) { body.append('upload_file[]', file, file.name); });
    body.append('kind', state.kind);
    body.append('_csrf', csrf());
    var label = ui.upload.querySelector('span');
    ui.upload.disabled = true;
    ui.upload.classList.add('opacity-50');
    label.textContent = 'Uploading…';
    ui.status.textContent = files.length === 1 ? 'Uploading ' + files[0].name + '…' : 'Uploading ' + files.length + ' files…';
    var finish = function () {
      ui.upload.disabled = false;
      ui.upload.classList.remove('opacity-50');
      label.textContent = 'Upload';
    };
    fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() } })
      .then(function (response) {
        return response.json().catch(function () { return { ok: false, items: [], message: response.status === 413 || response.status === 419 ? 'The upload was larger than the server allows.' : 'The upload failed.' }; });
      })
      .then(function (result) {
        finish();
        var items = result.items || [];
        if (items.length && !state.multiple) {
          // One picture to choose: the first one uploaded is the choice, as if it had been clicked.
          var done = callback;
          dialog.close();
          if (done) done(items[0].url, items[0]);
          return;
        }
        items.forEach(function (item) {
          if (state.chosen.indexOf(item.url) !== -1) return;
          state.chosen.push(item.url);
          if (callback) callback(item.url, item);
        });
        state.q = '';
        state.tag = '';
        ui.search.value = '';
        ui.tag.value = '';
        state.page = 1;
        load(result.message || (items.length ? (items.length === 1 ? 'Uploaded and added.' : 'Uploaded and added ' + items.length + ' files.') : ''));
      })
      .catch(function () {
        finish();
        ui.status.textContent = 'The upload failed. Check the connection and try again.';
      });
  }

  function go(page) {
    state.page = Math.max(1, page);
    load();
  }

  function fillTags(tags) {
    if (ui.tag.options.length - 1 === tags.length) return;
    while (ui.tag.options.length > 1) ui.tag.remove(1);
    tags.forEach(function (name) { ui.tag.appendChild(el('option', { value: name, text: name })); });
    ui.tag.value = state.tag;
  }

  function render(payload, note) {
    ui.grid.innerHTML = '';
    canUpload = !!payload.can_upload;
    ui.upload.style.display = canUpload ? '' : 'none';
    fillTags(payload.tags || []);
    state.page = payload.page;
    (payload.items || []).forEach(function (item) {
      var button = el('button', { type: 'button', class: 'overflow-hidden rounded-md border border-slate-200 bg-white text-left transition hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/40' }, [
        item.kind === 'video' || !(item.thumb || item.url)
          ? el('span', { class: 'flex h-24 w-full items-center justify-center bg-slate-100 text-slate-500', 'aria-hidden': 'true', html: '<svg viewBox="0 0 24 24" class="h-8 w-8" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' })
          : item.kind !== 'image'
          ? el('span', { class: 'flex h-24 w-full items-center justify-center bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-500', 'aria-hidden': 'true', text: (String(item.url).split('?')[0].split('.').pop() || 'file').slice(0, 5) })
          : el('img', { src: item.thumb || item.url, alt: '', class: 'h-24 w-full object-cover', loading: 'lazy' }),
        el('span', { class: 'block truncate px-2 py-1 text-[11px] text-slate-600', text: item.name })
      ]);
      button.setAttribute('aria-label', item.name + (item.alt ? ': ' + item.alt : ''));
      var mark = function () {
        button.setAttribute('aria-pressed', 'true');
        button.style.borderColor = '#3b82f6';
        button.style.boxShadow = '0 0 0 1px #3b82f6';
        button.insertBefore(el('span', { class: 'block bg-blue-600 px-2 py-0.5 text-[11px] font-medium text-white', text: '✓ Added' }), button.firstChild);
      };
      if (state.multiple) {
        button.setAttribute('aria-pressed', 'false');
        if (state.chosen.indexOf(item.url) !== -1) mark();
      }
      button.addEventListener('click', function () {
        if (state.multiple) {
          // Several pictures in one visit: each is given as it is clicked, and the dialog stays open.
          if (state.chosen.indexOf(item.url) !== -1) return;
          state.chosen.push(item.url);
          mark();
          if (callback) callback(item.url, item);
          return;
        }
        var done = callback;
        dialog.close();
        if (done) done(item.url, item);
      });
      ui.grid.appendChild(button);
    });
    var from = payload.total === 0 ? 0 : (payload.page - 1) * payload.per_page + 1;
    var to = Math.min(payload.total, payload.page * payload.per_page);
    ui.info.textContent = payload.total === 0 ? '' : 'Showing ' + from + '–' + to + ' of ' + payload.total + ' (page ' + payload.page + ' of ' + payload.pages + ')';
    ui.prev.disabled = payload.page <= 1;
    ui.next.disabled = payload.page >= payload.pages;
    ui.prev.classList.toggle('opacity-50', ui.prev.disabled);
    ui.next.classList.toggle('opacity-50', ui.next.disabled);
    var where = canUpload ? 'Upload one here, or drop it on this window.' : 'Upload them in Media.';
    ui.status.textContent = note ? note : payload.total === 0
      ? (state.q || state.tag ? 'Nothing matches.' : (state.kind === 'video' ? 'The media library has no videos yet. ' + where : state.kind === 'audio' ? 'The media library has no sound files yet (MP3, WAV, OGG or M4A). ' + where : state.kind === 'all' ? 'The media library has no files yet. ' + where : state.kind === 'track' ? 'The media library has no route files yet (GPX, KML or GeoJSON). ' + where : 'The media library has no images yet. ' + where))
      : '';
  }

  function load(note) {
    var mine = ++request;
    ui.status.textContent = 'Loading…';
    var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'kind=' + state.kind + '&page=' + state.page + '&q=' + encodeURIComponent(state.q) + '&tag=' + encodeURIComponent(state.tag);
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('status ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        if (mine !== request) return; // a newer search is on its way
        render(payload, note);
      })
      .catch(function () {
        if (mine !== request) return;
        ui.grid.innerHTML = '';
        ui.info.textContent = '';
        ui.status.textContent = 'The images could not be loaded. Close this and try again.';
      });
  }

  function open(onPick, kind, options) {
    if (!dialog) build();
    callback = onPick;
    options = options || {};
    state = { q: '', tag: '', page: 1, kind: kind === 'video' || kind === 'all' || kind === 'track' || kind === 'audio' ? kind : 'image', multiple: !!options.multiple, chosen: (options.chosen || []).slice() };
    var noun = state.kind === 'video' ? 'video' : (state.kind === 'all' ? 'file' : (state.kind === 'track' ? 'route file' : (state.kind === 'audio' ? 'sound file' : 'image')));
    var title = state.multiple ? 'Add ' + noun + 's' : 'Choose a ' + noun;
    dialog.setAttribute('aria-label', title);
    dialog.querySelector('h2').textContent = title;
    ui.close.textContent = state.multiple ? 'Done' : 'Close';
    ui.search.setAttribute('aria-label', 'Search ' + noun + 's');
    ui.search.value = '';
    ui.tag.value = '';
    ui.file.setAttribute('accept', ACCEPT[state.kind] || '');
    ui.file.multiple = state.multiple;
    dialog.showModal();
    ui.search.focus();
    load();
  }

  /* Image fields: a thumbnail, the address, and a Library button ---------------------------------- */

  function enhance(input) {
    if (input.dataset.imageReady === '1') return;
    input.dataset.imageReady = '1';
    var wrap = el('span', { class: 'flex items-center gap-2' });
    var kind = input.getAttribute('data-media-kind') || 'image';
    // Only a picture has a thumbnail to show; a video, a route or any other file is named by its address.
    var video = kind !== 'image';
    var thumb = el('img', { alt: '', class: 'h-9 w-9 shrink-0 rounded border border-slate-200 bg-slate-50 object-cover' });
    var choose = el('button', { type: 'button', class: CLS.btn + ' shrink-0 lc-library', text: 'Library' });
    choose.setAttribute('aria-label', kind === 'video' ? 'Choose a video from the media library' : (kind === 'image' ? 'Choose from the media library' : (kind === 'audio' ? 'Choose a sound file from the media library' : 'Choose a file from the media library')));
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(thumb);
    wrap.appendChild(input);
    wrap.appendChild(choose);
    input.classList.add('min-w-0', 'flex-1');
    var sync = function () {
      var value = input.value.trim();
      // A video has no thumbnail to show here.
      thumb.hidden = video || value === '';
      if (!video && value !== '') thumb.setAttribute('src', value);
    };
    input.addEventListener('input', sync);
    choose.addEventListener('click', function () {
      open(function (url) {
        input.value = url;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
      }, kind);
    });
    sync();
  }

  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.matches && root.matches('input[data-image-field]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('input[data-image-field]').forEach(enhance);
  }

  scan(document.body);
  // Rows are added while editing.
  new MutationObserver(function (records) {
    records.forEach(function (record) { record.addedNodes.forEach(scan); });
  }).observe(document.body, { childList: true, subtree: true });

  // Both names share one picker, including callbacks and open state.
  window.GnozioMediaPicker = window.FarosMediaPicker = { open: open, enhance: enhance };
})();
