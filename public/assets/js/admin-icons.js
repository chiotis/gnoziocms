/*
 * Icon picker: every place in the admin that chooses an icon (block fields, theme settings, the bottom bar)
 * is a <select data-icon-picker>. This turns it into a button that opens a popup of small pictures with a
 * search box. The select stays in the page (hidden), so saving, values, and change events work as before.
 * The pictures come from one library on the page: <script type="application/json" id="icon-library">.
 * Without the library or without JavaScript the plain select is still there.
 */
(function () {
  'use strict';

  var libraryEl = document.getElementById('icon-library');
  var library = {};
  try { library = libraryEl ? JSON.parse(libraryEl.textContent || '{}') : {}; } catch (e) { library = {}; }
  var names = Object.keys(library).sort();
  if (names.length === 0) return;

  var panel = null;      // the open popup, if any
  var current = null;    // { select, trigger } it belongs to

  function svg(name) {
    return library[name] || '';
  }

  function labelFor(select) {
    var label = select.closest('label');
    var text = label ? label.querySelector('span') : null;
    return (text && text.textContent.trim()) || 'Icon';
  }

  function paint(select, trigger) {
    var name = select.value;
    trigger.querySelector('[data-icon-thumb]').innerHTML = name && svg(name) ? svg(name) : '<span class="icon-picker-none" aria-hidden="true">–</span>';
    trigger.querySelector('[data-icon-name]').textContent = name || 'None';
    trigger.setAttribute('aria-label', labelFor(select) + ': ' + (name || 'None') + '. Choose an icon');
  }

  function close(restoreFocus) {
    if (!panel) return;
    var trigger = current && current.trigger;
    panel.remove();
    panel = null;
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) trigger.focus();
    }
    current = null;
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('resize', onResize);
  }

  function onOutside(event) {
    if (panel && !panel.contains(event.target) && !(current && current.trigger.contains(event.target))) close(false);
  }

  function onResize() { close(false); }

  function place(trigger) {
    var rect = trigger.getBoundingClientRect();
    var width = Math.min(22 * 16, window.innerWidth - 16);
    var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
    panel.style.width = width + 'px';
    panel.style.left = left + 'px';
    var height = panel.offsetHeight;
    var below = window.innerHeight - rect.bottom - 8;
    var above = rect.top - 8;
    if (height > below && above > below) {
      panel.style.maxHeight = Math.min(height, above) + 'px';
      panel.style.top = Math.max(8, rect.top - Math.min(height, above) - 6) + 'px';
    } else {
      panel.style.maxHeight = Math.max(160, below) + 'px';
      panel.style.top = (rect.bottom + 6) + 'px';
    }
  }

  function tiles() { return Array.prototype.slice.call(panel.querySelectorAll('[data-icon-tile]')); }
  function visibleTiles() { return tiles().filter(function (t) { return !t.hidden; }); }

  function focusTile(tile) {
    tiles().forEach(function (t) { t.tabIndex = -1; });
    tile.tabIndex = 0;
    tile.focus();
  }

  function choose(select, trigger, name) {
    select.value = name;
    paint(select, trigger);
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
    close(true);
  }

  function open(select, trigger) {
    if (panel) {
      var same = current && current.trigger === trigger;
      close(false);
      if (same) return;
    }
    current = { select: select, trigger: trigger };
    panel = document.createElement('div');
    panel.className = 'icon-picker-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Choose an icon');

    var search = document.createElement('input');
    search.type = 'search';
    search.className = 'icon-picker-search';
    search.placeholder = 'Search icons';
    search.setAttribute('aria-label', 'Search icons');
    panel.appendChild(search);

    var grid = document.createElement('div');
    grid.className = 'icon-picker-grid';
    grid.setAttribute('role', 'group');
    grid.setAttribute('aria-label', 'Icons');
    panel.appendChild(grid);

    // The set the field allows: the select's options (theme icons), so a block can offer fewer than the library has.
    var allowed = Array.prototype.map.call(select.options, function (o) { return o.value; });
    var list = [''].concat(names.filter(function (n) { return allowed.indexOf(n) !== -1; }));
    list.forEach(function (name) {
      var tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'icon-picker-tile';
      tile.dataset.iconTile = name;
      tile.tabIndex = -1;
      tile.setAttribute('aria-pressed', select.value === name ? 'true' : 'false');
      tile.title = name || 'None';
      tile.innerHTML = '<span class="icon-picker-tile-art" aria-hidden="true">' + (name ? svg(name) : '<span class="icon-picker-none">–</span>') + '</span><span class="icon-picker-tile-name">' + (name || 'None') + '</span>';
      tile.addEventListener('click', function () { choose(select, trigger, name); });
      grid.appendChild(tile);
    });

    var empty = document.createElement('p');
    empty.className = 'icon-picker-empty';
    empty.hidden = true;
    empty.textContent = 'No icon matches.';
    panel.appendChild(empty);

    document.body.appendChild(panel);
    trigger.setAttribute('aria-expanded', 'true');
    place(trigger);

    var selected = panel.querySelector('[aria-pressed="true"]') || tiles()[0];
    selected.tabIndex = 0;

    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      tiles().forEach(function (t) {
        var name = t.dataset.iconTile;
        // "None" always stays; the rest must contain what was typed.
        t.hidden = q !== '' && (name === '' || name.indexOf(q) === -1);
      });
      var shown = visibleTiles().length;
      empty.hidden = shown > 0;
    });

    panel.addEventListener('keydown', function (event) {
      var key = event.key;
      if (key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
      var here = event.target.closest && event.target.closest('[data-icon-tile]');
      if (key === 'Tab') {
        // The popup keeps focus: the search box and the current tile, one after the other.
        event.preventDefault();
        if (here) search.focus();
        else {
          var target = visibleTiles().filter(function (t) { return t.tabIndex === 0; })[0] || visibleTiles()[0];
          if (target) focusTile(target);
        }
        return;
      }
      var visible = visibleTiles();
      if (visible.length === 0) return;
      if (event.target === search) {
        if (key === 'ArrowDown') { event.preventDefault(); focusTile(visible[0]); }
        return;
      }
      if (!here) return;
      var index = visible.indexOf(here);
      var columns = visible.filter(function (t) { return t.offsetTop === visible[0].offsetTop; }).length || 1;
      var next = null;
      if (key === 'ArrowRight') next = visible[Math.min(visible.length - 1, index + 1)];
      else if (key === 'ArrowLeft') next = visible[Math.max(0, index - 1)];
      else if (key === 'ArrowDown') next = visible[Math.min(visible.length - 1, index + columns)];
      else if (key === 'ArrowUp') {
        if (index - columns < 0) { event.preventDefault(); search.focus(); return; }
        next = visible[index - columns];
      } else if (key === 'Home') next = visible[0];
      else if (key === 'End') next = visible[visible.length - 1];
      if (next) { event.preventDefault(); focusTile(next); }
    });

    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('resize', onResize);
    search.focus();
  }

  function enhance(select) {
    if (select.dataset.iconReady === '1') return;
    select.dataset.iconReady = '1';
    var wrap = document.createElement('span');
    wrap.className = 'icon-picker';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    // Kept in the form (it carries the value) but out of sight and out of the tab order.
    select.classList.add('icon-picker-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'icon-picker-trigger';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '<span class="icon-picker-thumb" data-icon-thumb></span><span class="icon-picker-name" data-icon-name></span><span class="icon-picker-caret" aria-hidden="true">▾</span>';
    wrap.appendChild(trigger);
    paint(select, trigger);

    trigger.addEventListener('click', function (event) { event.preventDefault(); open(select, trigger); });
    // Changed by something else (the block editor reloading its state): show the new choice.
    select.addEventListener('change', function () { paint(select, trigger); });
  }

  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.matches && root.matches('select[data-icon-picker]')) enhance(root);
    root.querySelectorAll && root.querySelectorAll('select[data-icon-picker]').forEach(enhance);
  }

  scan(document.body);
  // Rows and blocks are added while editing.
  new MutationObserver(function (records) {
    records.forEach(function (record) { record.addedNodes.forEach(scan); });
  }).observe(document.body, { childList: true, subtree: true });

  window.GnozioIconPicker = window.FarosIconPicker = { enhance: enhance };
})();
