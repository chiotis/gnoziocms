/*
 * The picker of a relation field (admin/templates/partials/fields.twig, macro relation): chips for the entries chosen and a search box
 * that offers more. Everything is read from the markup, so a field cloned into a new row of a list works at once:
 *
 *   [data-relation]            data-types (content types it may point at), data-multiple, data-max, data-url (the search address)
 *   [data-rel-name]            a disabled input that keeps the field's name; a repeater renames it with the row
 *   [data-rel-chips]           the chosen entries, each [data-rel-chip] with a hidden input holding the id
 *   [data-rel-search]          the text box, [data-rel-results] its list of answers
 *
 * What is chosen is the hidden inputs inside the chips: nothing is stored anywhere else. An entry that has no id yet is sent as
 * `@type/slug`, which the server turns into an id when the form is saved.
 */
(function () {
  'use strict';

  var timers = new WeakMap();
  var requests = new WeakMap();

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function chosen(box) {
    return Array.prototype.map.call(box.querySelectorAll('[data-rel-chips] > [data-rel-chip] input[type=hidden]'), function (input) { return input.value; });
  }

  function limit(box) {
    return parseInt(box.getAttribute('data-max'), 10) || 0;
  }

  /** The text box is off while a field that takes several is full. */
  function syncFull(box) {
    var search = box.querySelector('[data-rel-search]');
    var max = limit(box);
    var full = box.getAttribute('data-multiple') === '1' && max > 0 && chosen(box).length >= max;
    search.disabled = full;
    if (full) close(box);
  }

  function results(box) { return box.querySelector('[data-rel-results]'); }

  function close(box) {
    var list = results(box);
    list.hidden = true;
    list.textContent = '';
    box.querySelector('[data-rel-search]').setAttribute('aria-expanded', 'false');
  }

  function chip(box, item) {
    var multiple = box.getAttribute('data-multiple') === '1';
    var base = box.querySelector('[data-rel-name]').name;
    var li = el('li', { 'class': 'rel-chip' + (item.hidden ? ' is-hidden' : ''), 'data-rel-chip': '' });
    li.appendChild(el('input', { type: 'hidden', name: base + (multiple ? '[]' : ''), value: item.value }));
    li.appendChild(el('span', { 'class': 'rel-title' }, item.title));
    if (item.hidden) li.appendChild(el('span', { 'class': 'rel-state' }, item.status || 'hidden'));
    if (item.edit_url) {
      var open = el('a', { 'class': 'rel-btn', href: item.edit_url, target: '_blank', rel: 'noopener', 'aria-label': 'Open ' + item.title + ' in a new tab' }, '↗');
      li.appendChild(open);
    }
    if (multiple) {
      li.appendChild(el('button', { type: 'button', 'class': 'rel-btn', 'data-rel-left': '', 'aria-label': 'Move earlier' }, '‹'));
      li.appendChild(el('button', { type: 'button', 'class': 'rel-btn', 'data-rel-right': '', 'aria-label': 'Move later' }, '›'));
    }
    li.appendChild(el('button', { type: 'button', 'class': 'rel-btn', 'data-rel-remove': '', 'aria-label': 'Remove ' + item.title }, '✕'));
    return li;
  }

  function choose(box, item) {
    var chips = box.querySelector('[data-rel-chips]');
    var multiple = box.getAttribute('data-multiple') === '1';
    if (chosen(box).indexOf(item.value) >= 0) return;
    if (!multiple) chips.textContent = '';
    else if (limit(box) > 0 && chosen(box).length >= limit(box)) return;
    chips.appendChild(chip(box, item));
    var search = box.querySelector('[data-rel-search]');
    search.value = '';
    close(box);
    syncFull(box);
    search.dispatchEvent(new Event('change', { bubbles: true }));
    if (!search.disabled) search.focus();
  }

  function show(box, items) {
    var list = results(box);
    var taken = chosen(box);
    list.textContent = '';
    var fresh = items.filter(function (item) { return taken.indexOf(item.value) < 0; });
    if (!fresh.length) {
      list.appendChild(el('li', { 'class': 'rel-none', role: 'presentation' }, items.length ? 'Everything found is already chosen.' : 'No entries found.'));
    }
    fresh.forEach(function (item, index) {
      var li = el('li', { role: 'option', 'class': 'rel-result', id: 'rel-' + Math.random().toString(36).slice(2, 8), 'aria-selected': index === 0 ? 'true' : 'false' });
      li.appendChild(el('span', { 'class': 'rel-title' }, item.title));
      li.appendChild(el('span', { 'class': 'rel-type' }, item.type + (item.hidden ? ' · ' + (item.status || 'hidden') : '')));
      li._item = item;
      list.appendChild(li);
    });
    list.hidden = false;
    var search = box.querySelector('[data-rel-search]');
    search.setAttribute('aria-expanded', 'true');
    var first = list.querySelector('[role=option]');
    if (first) search.setAttribute('aria-activedescendant', first.id); else search.removeAttribute('aria-activedescendant');
  }

  function look(box) {
    var search = box.querySelector('[data-rel-search]');
    if (requests.get(box)) requests.get(box).abort();
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    requests.set(box, controller);
    var url = box.getAttribute('data-url') + '?q=' + encodeURIComponent(search.value.trim()) + '&types=' + encodeURIComponent(box.getAttribute('data-types') || '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
      .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error(String(response.status))); })
      .then(function (items) { if (document.activeElement === search) show(box, Array.isArray(items) ? items : []); })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        var list = results(box);
        list.textContent = '';
        list.appendChild(el('li', { 'class': 'rel-none', role: 'presentation' }, 'The search did not answer. Try again.'));
        list.hidden = false;
      });
  }

  function later(box) {
    clearTimeout(timers.get(box));
    timers.set(box, setTimeout(function () { look(box); }, 150));
  }

  function move(box, direction) {
    var options = Array.prototype.slice.call(results(box).querySelectorAll('[role=option]'));
    if (!options.length) return;
    var current = options.findIndex(function (o) { return o.getAttribute('aria-selected') === 'true'; });
    var next = (current + direction + options.length) % options.length;
    options.forEach(function (o, i) { o.setAttribute('aria-selected', i === next ? 'true' : 'false'); });
    options[next].scrollIntoView({ block: 'nearest' });
    box.querySelector('[data-rel-search]').setAttribute('aria-activedescendant', options[next].id);
  }

  document.addEventListener('input', function (event) {
    var search = event.target.closest && event.target.closest('[data-rel-search]');
    if (search) later(search.closest('[data-relation]'));
  });

  document.addEventListener('focusin', function (event) {
    var search = event.target.closest && event.target.closest('[data-rel-search]');
    if (search && !search.disabled) later(search.closest('[data-relation]'));
  });

  document.addEventListener('keydown', function (event) {
    var search = event.target.closest && event.target.closest('[data-rel-search]');
    if (!search) return;
    var box = search.closest('[data-relation]');
    var open = !results(box).hidden;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!open) look(box); else move(box, event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter') {
      // Enter in the box never sends the form.
      event.preventDefault();
      var active = open ? results(box).querySelector('[role=option][aria-selected=true]') : null;
      if (active && active._item) choose(box, active._item);
    } else if (event.key === 'Escape' && open) {
      event.preventDefault();
      event.stopPropagation();
      close(box);
    }
  });

  document.addEventListener('mousedown', function (event) {
    var option = event.target.closest && event.target.closest('[data-rel-results] [role=option]');
    if (option && option._item) {
      // mousedown, not click: the box loses focus first, which would close the list.
      event.preventDefault();
      choose(option.closest('[data-relation]'), option._item);
    }
  });

  document.addEventListener('click', function (event) {
    var button = event.target.closest && event.target.closest('[data-rel-remove], [data-rel-left], [data-rel-right]');
    if (button) {
      var box = button.closest('[data-relation]');
      var li = button.closest('[data-rel-chip]');
      if (button.hasAttribute('data-rel-remove')) {
        var next = li.nextElementSibling || li.previousElementSibling;
        li.remove();
        syncFull(box);
        var focus = next ? next.querySelector('button, a') : box.querySelector('[data-rel-search]');
        if (focus) focus.focus();
        box.querySelector('[data-rel-search]').dispatchEvent(new Event('change', { bubbles: true }));
      } else if (button.hasAttribute('data-rel-left') && li.previousElementSibling) {
        li.parentNode.insertBefore(li, li.previousElementSibling);
        button.focus();
      } else if (button.hasAttribute('data-rel-right') && li.nextElementSibling) {
        li.parentNode.insertBefore(li.nextElementSibling, li);
        button.focus();
      }
    }
    // A click anywhere else puts the lists away.
    document.querySelectorAll('[data-relation]').forEach(function (box) {
      if (!box.contains(event.target)) close(box);
    });
  });

  document.addEventListener('focusout', function (event) {
    var search = event.target.closest && event.target.closest('[data-rel-search]');
    if (search) {
      var box = search.closest('[data-relation]');
      setTimeout(function () { if (!box.contains(document.activeElement)) close(box); }, 120);
    }
  });
})();
