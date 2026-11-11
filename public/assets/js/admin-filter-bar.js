/* GnozioCMS Admin — the filter bar over a content list (form[data-filter-bar], admin/templates/list.twig).
 *
 *   details[data-multiselect]   -> a dropdown of checkboxes; one open at a time, closed by a click outside or Escape.
 *                                  Closing it with something changed sends the bar.
 *   [data-multiselect-find]     -> narrows the dropdown's options as one types
 *   [data-multiselect-clear]    -> unticks every option of its dropdown and closes it
 *   [data-multiselect-done]     -> closes its dropdown (and so sends the bar when something changed)
 *   [data-filter-auto]          -> sends the bar when changed (selects, dates)
 *   [data-filter-search]        -> sends the bar on Enter or after a pause in typing; focus comes back after the reload
 *
 * The bar is sent as an address with each filter once, its values joined by commas, and empty ones left out.
 */
(function () {
  'use strict';

  var FOCUS_KEY = 'admin.filterSearchFocus';

  function snapshot(details) {
    return Array.prototype.map.call(details.querySelectorAll('input[type="checkbox"]'), function (box) { return box.checked ? '1' : '0'; }).join('');
  }

  function send(form) {
    var params = new URLSearchParams();
    var joined = {};
    var order = [];
    new FormData(form).forEach(function (value, name) {
      value = String(value).trim();
      var key = name.replace(/\[\]$/, '');
      if (!(key in joined)) { joined[key] = []; order.push(key); }
      if (value !== '') joined[key].push(value);
    });
    order.forEach(function (key) {
      if (joined[key].length) params.set(key, joined[key].join(','));
    });
    window.location.href = form.getAttribute('action') + '?' + params.toString();
  }

  function close(details) {
    if (details && details.open) details.open = false;
  }

  function init(form) {
    var opened = null;

    form.querySelectorAll('details[data-multiselect]').forEach(function (details) {
      details.addEventListener('toggle', function () {
        if (details.open) {
          details._before = snapshot(details);
          if (opened && opened !== details) close(opened);
          opened = details;
          // Opens to the left when there is no room on the right.
          var panel = details.querySelector('summary + div');
          if (panel) {
            panel.style.left = '0';
            panel.style.right = 'auto';
            if (panel.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
              panel.style.left = 'auto';
              panel.style.right = '0';
            }
          }
          var find = details.querySelector('[data-multiselect-find]');
          if (find) find.focus();
          return;
        }
        if (opened === details) opened = null;
        if (details._before !== undefined && details._before !== snapshot(details)) send(form);
      });
    });

    form.addEventListener('click', function (e) {
      var clear = e.target.closest('[data-multiselect-clear]');
      if (clear) {
        var box = clear.closest('details');
        box.querySelectorAll('input[type="checkbox"]').forEach(function (input) { input.checked = false; });
        close(box);
        return;
      }
      var done = e.target.closest('[data-multiselect-done]');
      if (done) close(done.closest('details'));
    });

    form.addEventListener('input', function (e) {
      if (!e.target.matches('[data-multiselect-find]')) return;
      var needle = e.target.value.trim().toLowerCase();
      e.target.closest('details').querySelectorAll('[data-multiselect-option]').forEach(function (row) {
        row.classList.toggle('hidden', needle !== '' && row.getAttribute('data-multiselect-option').indexOf(needle) === -1);
      });
    });

    form.addEventListener('change', function (e) {
      if (e.target.matches('[data-filter-auto]')) send(form);
    });

    // A click anywhere else, or Escape, closes the open dropdown.
    document.addEventListener('click', function (e) {
      if (opened && !opened.contains(e.target)) close(opened);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && opened) {
        var summary = opened.querySelector('summary');
        close(opened);
        if (summary) summary.focus();
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      send(form);
    });

    // Searching: on Enter (the form's submit) or once typing stops for a moment.
    var search = form.querySelector('[data-filter-search]');
    if (search) {
      var initial = search.value;
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          if (search.value.trim() === initial.trim()) return;
          try { sessionStorage.setItem(FOCUS_KEY, '1'); } catch (err) {}
          send(form);
        }, 700);
      });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { try { sessionStorage.setItem(FOCUS_KEY, '1'); } catch (err) {} }
      });
      var refocus = false;
      try { refocus = sessionStorage.getItem(FOCUS_KEY) === '1'; sessionStorage.removeItem(FOCUS_KEY); } catch (err) {}
      if (refocus) {
        search.focus();
        var end = search.value.length;
        try { search.setSelectionRange(end, end); } catch (err) {}
      }
    }
  }

  function start() {
    document.querySelectorAll('form[data-filter-bar]').forEach(init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
