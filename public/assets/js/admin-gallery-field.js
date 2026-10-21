/*
 * A gallery field (a field of the kind `gallery` in a content type, drawn by fields.twig): the pictures of the media library an
 * entry shows, in order. The form holds them in a box, one address on each line, which is all the server reads, so a form sent
 * without this script keeps them. Here the box becomes a row of thumbnails: "Add pictures" opens the library (several at a time),
 * each picture can move earlier or later (buttons, or drag it) and be removed, and "Edit as text" shows the box again.
 *
 *   <div data-gallery-field data-max="100"><textarea data-gal-text>…</textarea></div>
 *
 * A field inside a row of a list that is added later is found too.
 */
(function () {
  'use strict';

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (attrs[key] === false || attrs[key] === null || attrs[key] === undefined) return;
      if (key === 'class') node.className = attrs[key];
      else node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
    });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function read(box) {
    var seen = [];
    box.value.split(/[\r\n]+/).forEach(function (line) {
      line = line.trim();
      if (line !== '' && seen.indexOf(line) === -1) seen.push(line);
    });
    return seen;
  }

  function enhance(root) {
    if (root.dataset.galleryReady === '1') return;
    var box = root.querySelector('[data-gal-text]');
    if (!box) return;
    root.dataset.galleryReady = '1';
    var max = parseInt(root.getAttribute('data-max') || '100', 10) || 100;
    var label = (box.getAttribute('aria-label') || 'Gallery').split(':')[0];
    var images = read(box);
    var dragged = -1;

    var list = el('ul', { class: 'gal-list', role: 'list', 'aria-label': label });
    var add = el('button', { type: 'button', class: 'gal-add', 'data-gal-add': true });
    add.appendChild(el('span', { class: 'gal-add-icon', 'aria-hidden': 'true' }, '+'));
    add.appendChild(el('span', {}, 'Add pictures'));
    var foot = el('div', { class: 'gal-foot' });
    var count = el('span', { 'data-gal-count': true, 'aria-live': 'polite' });
    var toggle = el('button', { type: 'button', class: 'gal-toggle', 'aria-expanded': 'false', 'data-gal-toggle': true }, 'Edit as text');
    foot.appendChild(count);
    foot.appendChild(toggle);
    root.insertBefore(list, box);
    root.appendChild(foot);
    box.hidden = true;

    function save() {
      var text = images.join('\n');
      if (box.value === text) return;
      box.value = text;
      // The editor notices the change (unsaved work, the live preview) as it would for typing.
      box.dispatchEvent(new Event('input', { bubbles: true }));
      box.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function move(from, to) {
      if (to < 0 || to >= images.length || from === to) return;
      var one = images.splice(from, 1)[0];
      images.splice(to, 0, one);
      save();
      draw();
    }

    function focusAt(index, which) {
      // After a move or a removal the focus stays on the same button of the picture (or the next one that can be pressed).
      var item = list.querySelectorAll('[data-gal-item]')[index];
      var target = item ? item.querySelector(which + ':not([disabled])') || item.querySelector('button:not([disabled])') : null;
      (target || add).focus();
    }

    function draw() {
      list.innerHTML = '';
      images.forEach(function (url, i) {
        var n = i + 1;
        var item = el('li', { class: 'gal-item', draggable: 'true', 'data-gal-item': true, 'data-url': url });
        item.appendChild(el('img', { src: url, alt: '', loading: 'lazy' }));
        item.appendChild(el('span', { class: 'gal-num', 'aria-hidden': 'true' }, String(n)));
        var tools = el('span', { class: 'gal-tools' });
        var name = 'picture ' + n + ' (' + url.split('/').pop() + ')';
        var left = el('button', { type: 'button', class: 'gal-btn', 'data-gal-left': true, 'aria-label': 'Move ' + name + ' earlier', disabled: i === 0 }, '‹');
        var right = el('button', { type: 'button', class: 'gal-btn', 'data-gal-right': true, 'aria-label': 'Move ' + name + ' later', disabled: i === images.length - 1 }, '›');
        var remove = el('button', { type: 'button', class: 'gal-btn is-remove', 'data-gal-remove': true, 'aria-label': 'Remove ' + name }, '✕');
        left.addEventListener('click', function () { move(i, i - 1); focusAt(i - 1, '[data-gal-left]'); });
        right.addEventListener('click', function () { move(i, i + 1); focusAt(i + 1, '[data-gal-right]'); });
        remove.addEventListener('click', function () {
          images.splice(i, 1);
          save();
          draw();
          focusAt(Math.min(i, images.length - 1), '[data-gal-remove]');
        });
        tools.appendChild(left);
        tools.appendChild(right);
        tools.appendChild(remove);
        item.appendChild(tools);
        item.addEventListener('dragstart', function (event) {
          dragged = i;
          item.classList.add('is-dragging');
          if (event.dataTransfer) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', url); }
        });
        item.addEventListener('dragend', function () { dragged = -1; item.classList.remove('is-dragging'); });
        item.addEventListener('dragover', function (event) { if (dragged !== -1) { event.preventDefault(); item.classList.add('is-over'); } });
        item.addEventListener('dragleave', function () { item.classList.remove('is-over'); });
        item.addEventListener('drop', function (event) {
          event.preventDefault();
          item.classList.remove('is-over');
          if (dragged !== -1) move(dragged, i);
        });
        list.appendChild(item);
      });
      var tile = el('li', { class: 'gal-tile' });
      tile.appendChild(add);
      list.appendChild(tile);
      add.disabled = images.length >= max;
      count.textContent = images.length === 0 ? 'No pictures yet.' : images.length + (images.length === 1 ? ' picture' : ' pictures') + (images.length >= max ? ' (the most it takes)' : '');
    }

    add.addEventListener('click', function () {
      var picker = window.GnozioMediaPicker;
      if (!picker) { toggle.click(); return; }
      picker.open(function (url) {
        if (images.length >= max || images.indexOf(url) !== -1) return;
        images.push(url);
        save();
        draw();
      }, 'image', { multiple: true, chosen: images.slice() });
    });

    toggle.addEventListener('click', function () {
      var open = box.hidden;
      box.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Done' : 'Edit as text';
      if (open) box.focus();
      else { images = read(box).slice(0, max); save(); draw(); }
    });
    box.addEventListener('input', function () {
      if (box.hidden) return;
      images = read(box).slice(0, max);
      draw();
    });

    draw();
  }

  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.matches && root.matches('[data-gallery-field]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('[data-gallery-field]').forEach(enhance);
  }

  scan(document.body);
  new MutationObserver(function (records) {
    records.forEach(function (record) { record.addedNodes.forEach(scan); });
  }).observe(document.body, { childList: true, subtree: true });

  window.GnozioGalleryField = { enhance: enhance };
})();
