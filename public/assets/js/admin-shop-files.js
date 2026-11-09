/*
 * A digital product's private files, in the Downloads card of its editor (admin/templates/edit.twig). An upload or a removal goes to
 * /admin/shop-files at once, apart from the entry's own form, and the answer holds the list to draw.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-shop-files]');
  if (!box || !box.querySelector('[data-files-list]')) { return; }
  var list = box.querySelector('[data-files-list]');
  var empty = box.querySelector('[data-files-empty]');
  var input = box.querySelector('[data-files-input]');
  var status = box.querySelector('[data-files-status]');
  var add = box.querySelector('.en-files-add');
  var label = box.querySelector('[data-files-label]');
  var csrf = document.querySelector('input[name="_csrf"]');

  function size(bytes) {
    var mb = bytes / 1048576;
    return mb < 1 ? Math.max(1, Math.round(bytes / 1024)) + ' KB' : mb.toFixed(1) + ' MB';
  }

  function draw(files) {
    list.textContent = '';
    files.forEach(function (file) {
      var row = document.createElement('li');
      row.className = 'en-files-row';
      var name = document.createElement('span');
      name.className = 'en-files-name';
      name.textContent = file.name;
      var meta = document.createElement('span');
      meta.className = 'en-files-meta';
      meta.textContent = size(file.size);
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'en-files-remove';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      var waiting = 0;
      remove.addEventListener('click', function () {
        // The first click asks; links already given for the file stop working, so it is not undone by accident.
        if (!remove.classList.contains('is-asking')) {
          remove.classList.add('is-asking');
          remove.textContent = 'Remove for good?';
          waiting = window.setTimeout(function () {
            remove.classList.remove('is-asking');
            remove.textContent = 'Remove';
          }, 4000);
          return;
        }
        window.clearTimeout(waiting);
        send({ action: 'remove', id: String(file.id) }, null);
      });
      meta.appendChild(remove);
      row.appendChild(name);
      row.appendChild(meta);
      list.appendChild(row);
    });
    empty.hidden = files.length > 0;
  }

  function send(fields, file) {
    var data = new FormData();
    data.append('_csrf', csrf ? csrf.value : '');
    data.append('ajax', '1');
    data.append('product', box.getAttribute('data-product'));
    Object.keys(fields).forEach(function (key) { data.append(key, fields[key]); });
    if (file) { data.append('file', file); }
    add.classList.add('is-busy');
    input.disabled = true;
    label.textContent = file ? 'Uploading…' : 'Working…';
    fetch(box.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (answer) {
        status.textContent = answer.message;
        if (Array.isArray(answer.files)) { draw(answer.files); }
      })
      .catch(function () { status.textContent = 'That did not go through. Check the size of the file and try again.'; })
      .then(function () {
        add.classList.remove('is-busy');
        input.disabled = false;
        input.value = '';
        label.textContent = 'Upload a file';
      });
  }

  input.addEventListener('change', function () {
    if (input.files && input.files[0]) { send({ action: 'upload' }, input.files[0]); }
  });

  draw(JSON.parse(box.getAttribute('data-files') || '[]'));
})();
