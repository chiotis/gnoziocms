/*
 * Theme > Export and import: the whole theme, with a preview of each part, the parts chosen, and undo.
 * The server does the work (POST /admin/theme?transfer=preview|import|undo); this script only asks, shows the answer and reloads.
 * Nothing here touches the unsaved choices of the screen, and the page is reloaded after a change, so it asks first when the form was edited.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-theme-transfer]');
  if (!root) { return; }
  var endpoint = root.getAttribute('data-endpoint');
  var file = root.querySelector('[data-tt-file]');
  var preview = root.querySelector('[data-tt-preview]');
  var status = root.querySelector('[data-tt-status]');
  var apply = root.querySelector('[data-tt-apply]');
  var partTemplate = root.querySelector('template[data-tt-part]');
  var dirty = false;
  var form = root.closest('form');
  if (form) {
    form.addEventListener('input', function (event) { if (!root.contains(event.target)) { dirty = true; } });
  }

  function token() {
    var field = document.querySelector('input[name="_csrf"]');
    return field ? field.value : '';
  }
  function say(text, bad) {
    status.textContent = text;
    status.className = 'text-xs ' + (bad ? 'text-red-700' : 'text-slate-600');
  }
  function post(action, body) {
    body.append('_csrf', token());
    return fetch(endpoint + '?transfer=' + action, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': token() } })
      .then(function (response) { return response.json().then(function (data) { data.status = response.status; return data; }); });
  }
  function proceed() {
    return !dirty || window.confirm('Your unsaved choices on this screen will be lost. Continue?');
  }
  function reload(note) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'transfer');
    url.searchParams.set('transfer_done', note);
    window.location.href = url.toString();
  }
  function fileBody() {
    var body = new FormData();
    body.append('theme_file', file.files[0]);
    return body;
  }
  function cell(row, text, tag) {
    var td = document.createElement(tag || 'td');
    td.className = 'py-1 pr-3 align-top';
    td.textContent = text === '' ? '—' : text;
    if (tag === 'th') { td.scope = 'row'; }
    row.appendChild(td);
  }
  function plural(n, word) { return n + ' ' + word + (n === 1 ? '' : 's'); }
  function ticked() {
    return Array.prototype.filter.call(root.querySelectorAll('[data-tt-tick]'), function (box) { return box.checked && !box.disabled; });
  }
  function refresh() {
    apply.disabled = ticked().length === 0;
  }

  file.addEventListener('change', function () {
    if (!file.files.length) { return; }
    say('Reading the file…');
    post('preview', fileBody()).then(function (answer) {
      if (!answer.ok) { preview.hidden = true; say(answer.error || 'The file could not be read.', true); return; }
      var list = root.querySelector('[data-tt-parts]');
      list.textContent = '';
      var changing = 0;
      answer.parts.forEach(function (part) {
        var node = partTemplate.content.firstElementChild.cloneNode(true);
        var box = node.querySelector('[data-tt-tick]');
        var count = part.changes.length;
        box.value = part.key;
        box.checked = part.ticked && count > 0;
        box.disabled = count === 0;
        box.setAttribute('aria-label', 'Import ' + part.label);
        // A click on the box ticks it; it does not open or close the row.
        box.addEventListener('click', function (event) { event.stopPropagation(); });
        box.addEventListener('change', refresh);
        node.querySelector('[data-tt-label]').textContent = part.label;
        node.querySelector('[data-tt-count]').textContent = count === 0 ? (part.in_file ? 'no change' : 'not in the file') : plural(count, 'change') + (part.ticked ? '' : ' · not ticked: belongs to the other site');
        var rows = node.querySelector('[data-tt-rows]');
        part.changes.forEach(function (change) {
          var tr = document.createElement('tr');
          cell(tr, change.label, 'th');
          cell(tr, change.from);
          cell(tr, change.to);
          rows.appendChild(tr);
        });
        if (count === 0) { node.querySelector('table').hidden = true; }
        if (count > 0) { changing++; }
        list.appendChild(node);
      });
      var files = answer.files.filter(function (item) { return item.status !== 'same'; });
      root.querySelector('[data-tt-summary]').textContent = changing === 0 && files.length === 0
        ? 'Nothing would change: this site already has this theme.'
        : plural(changing, 'part') + ' would change. Open a part to see each setting; untick what you want to keep.';
      var warnings = root.querySelector('[data-tt-warnings]');
      warnings.textContent = '';
      answer.warnings.forEach(function (text) {
        var li = document.createElement('li');
        li.textContent = text;
        warnings.appendChild(li);
      });
      var extra = [];
      if (answer.theme) { extra.push('Exported from the theme "' + answer.theme + '"' + (answer.from ? ' on GnozioCMS ' + answer.from : '') + '.'); }
      if (files.length) { extra.push('Font files (with Branding): ' + files.map(function (item) { return item.path + ' (' + item.status + ')'; }).join(', ') + '.'); }
      if (answer.skipped.length) { extra.push('Not in this theme, skipped: ' + answer.skipped.join(', ') + '.'); }
      root.querySelector('[data-tt-extra]').textContent = extra.join(' ');
      refresh();
      preview.hidden = false;
      say('');
    }).catch(function () { say('The file could not be sent.', true); });
  });

  root.querySelector('[data-tt-cancel]').addEventListener('click', function () {
    preview.hidden = true;
    file.value = '';
    say('');
  });
  apply.addEventListener('click', function () {
    var parts = ticked();
    if (!parts.length || !proceed()) { return; }
    var body = fileBody();
    parts.forEach(function (box) { body.append('parts[]', box.value); });
    apply.disabled = true;
    say('Importing…');
    post('import', body).then(function (answer) {
      if (!answer.ok) { refresh(); say(answer.error || 'The import failed.', true); return; }
      reload(answer.changed ? 'imported' : 'unchanged');
    }).catch(function () { refresh(); say('The file could not be sent.', true); });
  });
  root.querySelector('[data-tt-undo]').addEventListener('click', function () {
    if (!window.confirm('Put the theme back as it was before the last import?') || !proceed()) { return; }
    post('undo', new FormData()).then(function (answer) {
      if (!answer.ok) { say('There is nothing to undo.', true); return; }
      reload('undone');
    });
  });

  var done = new URL(window.location.href).searchParams.get('transfer_done');
  var notes = { imported: 'Imported. The theme from before is kept: use Undo to put it back.', unchanged: 'Nothing changed: this site already had those parts.', undone: 'Put back as before the import.' };
  if (done && notes[done]) { say(notes[done]); }
})();
