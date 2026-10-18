/* GnozioCMS Admin — vanilla JS interactions.
 * No framework. All behaviors are delegated and data-attribute driven
 * so partials can be composed freely in PHP views.
 *
 *   data-toggle="sidebar"            -> show/hide mobile sidebar
 *   data-dropdown                    -> wrapper; [data-dropdown-trigger] + [data-dropdown-menu]
 *   data-tabs                        -> wrapper; [data-tab="key"] buttons + [data-tab-panel="key"]
 *   data-toggle="filter" data-target -> show/hide a filter panel by id
 *   data-offcanvas-open="id"         -> open off-canvas panel #id
 *   data-offcanvas-close             -> close nearest open off-canvas
 *   data-modal-open="id"             -> open modal #id
 *   data-modal-close                 -> close nearest modal
 *   window.adminToast(msg, type)     -> show a message in the bottom bar (text only)
 *   <template data-flash data-type>  -> server flash message, shown in the bottom bar on load
 *   table[data-sortable] th[data-sort] -> client-side column sorting (td[data-sort-value] optional)
 *   form[data-confirm="message"]     -> confirmation modal before submitting
 *   [data-copy="text"]               -> copy text to the clipboard
 *   [data-select-all="name"]         -> checkbox toggling every input[name] in its form
 */
(function () {
  'use strict';

  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }

  /* ---- Theme (light / dark / system) ---- */
  var SUN = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.5-6.5l-1.4 1.4M6.9 17.1l-1.4 1.4m12.6 0l-1.4-1.4M6.9 6.9L5.5 5.5"/></svg>';
  var MOON = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>';
  function prefValue() { try { return localStorage.getItem('admin.theme') || 'light'; } catch (e) { return 'light'; } }
  function effectiveTheme() {
    var p = prefValue();
    if (p === 'system') return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    return p;
  }
  function refreshToggleIcon(eff) {
    var btn = document.querySelector('[data-theme-toggle]');
    if (!btn) return;
    btn.innerHTML = eff === 'dark' ? SUN : MOON;
    btn.setAttribute('title', eff === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
  }
  function applyTheme() {
    var eff = effectiveTheme();
    document.documentElement.classList.toggle('dark', eff === 'dark');
    refreshToggleIcon(eff);
  }
  window.applyTheme = applyTheme;
  window.toggleTheme = function () {
    var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('admin.theme', next); } catch (e) {}
    applyTheme();
  };
  applyTheme(); // run ASAP to minimize flash
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(function () {
      if (prefValue() === 'system') applyTheme();
    });
  }
  function injectThemeToggle() {
    if (document.querySelector('[data-theme-toggle]')) return;
    var host = document.querySelector('header .ml-auto');
    if (!host) return;
    var btn = document.createElement('button');
    btn.setAttribute('data-theme-toggle', '');
    btn.setAttribute('type', 'button');
    btn.className = 'rounded-md p-1.5 text-slate-500 hover:bg-slate-100';
    btn.addEventListener('click', window.toggleTheme);
    host.insertBefore(btn, host.firstChild);
    refreshToggleIcon(effectiveTheme());
  }

  /* ---- Sidebar (mobile) ---- */
  function setSidebar(open) {
    var sb = document.querySelector('[data-sidebar]');
    var bd = document.querySelector('[data-sidebar-backdrop]');
    if (!sb) return;
    sb.classList.toggle('-translate-x-full', !open);
    if (bd) bd.classList.toggle('hidden', !open);
  }

  /* ---- Dropdowns ---- */
  function closeAllDropdowns(except) {
    document.querySelectorAll('[data-dropdown-menu]').forEach(function (m) {
      if (m !== except) m.classList.add('hidden');
    });
  }

  /* ---- Tabs ---- */
  function activateTab(wrap, key) {
    wrap.querySelectorAll('[data-tab]').forEach(function (b) {
      var on = b.getAttribute('data-tab') === key;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.classList.toggle('active', on);
    });
    wrap.querySelectorAll('[data-tab-panel]').forEach(function (p) {
      p.classList.toggle('hidden', p.getAttribute('data-tab-panel') !== key);
    });
  }

  /* ---- Off-canvas ---- */
  function openOffcanvas(id) {
    var p = document.getElementById(id);
    if (!p) return;
    var bd = p.querySelector('[data-offcanvas-backdrop]') ||
             document.querySelector('[data-offcanvas-backdrop][data-for="' + id + '"]');
    p.removeAttribute('hidden');
    var panel = p.querySelector('[data-offcanvas]') || p;
    requestAnimationFrame(function () { panel.classList.remove('translate-x-full'); });
    if (bd) bd.removeAttribute('hidden');
  }
  function closeOffcanvas(root) {
    if (!root) return;
    var panel = root.querySelector('[data-offcanvas]') || root;
    panel.classList.add('translate-x-full');
    var bd = root.querySelector('[data-offcanvas-backdrop]');
    if (bd) bd.setAttribute('hidden', '');
    setTimeout(function () { root.setAttribute('hidden', ''); }, 250);
  }

  /* ---- Modal ---- */
  function openModal(id) { var m = document.getElementById(id); if (m) m.removeAttribute('hidden'); }
  function closeModal(m) { if (m) m.setAttribute('hidden', ''); }

  /* ---- Messages ----
   * Every message in the admin shows in the fixed bar at the bottom of the window: the bar changes colour, its buttons
   * slide away, and the message takes their place. On a screen with an action bar (Save and friends) that is the same
   * bar; on any other screen a bar with no buttons slides up for the message and away again. Messages queue, so two
   * flashes on one page show one after the other. Errors stay until dismissed; the rest go back by themselves once
   * there was time to read them. */
  var MESSAGE_TYPES = ['success', 'warning', 'error', 'info'];
  var BAR_ICONS = {
    success: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>',
    warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
    error: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/>',
    info: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8h.01M11 12h1v5h1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
  };
  var barQueue = [];
  var barBusy = false;

  function showBarMessage(bar, msg, type) {
    barQueue.push({ bar: bar, msg: msg, type: type });
    if (!barBusy) nextBarMessage();
  }

  function nextBarMessage() {
    var item = barQueue.shift();
    if (!item) { barBusy = false; return; }
    barBusy = true;
    var bar = item.bar;
    var content = bar.querySelector('[data-action-bar-content]');
    var text = bar.querySelector('[data-action-bar-text]');
    var icon = bar.querySelector('[data-action-bar-icon]');
    var progress = bar.querySelector('[data-action-bar-progress]');
    var live = bar.querySelector('[data-action-bar-live]');
    var dismiss = bar.querySelector('[data-action-bar-dismiss]');
    var layer = bar.querySelector('[data-action-bar-message]');
    var timer = null;
    var done = false;

    text.textContent = item.msg;
    icon.innerHTML = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">' + BAR_ICONS[item.type] + '</svg>';
    ['success', 'warning', 'error', 'info'].forEach(function (t) { bar.classList.remove('tone-' + t); });
    bar.classList.add('tone-' + item.type);
    // Screen readers are told by the live region. The visible layer is hidden from them while it rests, but shown to
    // them while it is up, since its Dismiss button is focusable (and takes focus for an error).
    if (layer) layer.removeAttribute('aria-hidden');
    live.setAttribute('aria-live', item.type === 'error' ? 'assertive' : 'polite');
    live.textContent = '';
    setTimeout(function () { live.textContent = item.msg; }, 50);
    if (content) content.setAttribute('inert', '');
    dismiss.removeAttribute('tabindex');
    bar.classList.add('is-notifying');

    function finish() {
      if (done) return;
      done = true;
      clearTimeout(timer);
      document.removeEventListener('keydown', onKey);
      bar.classList.remove('is-notifying');
      if (layer) layer.setAttribute('aria-hidden', 'true');
      if (content) content.removeAttribute('inert');
      dismiss.setAttribute('tabindex', '-1');
      progress.style.transition = 'none';
      progress.style.width = '0';
      setTimeout(function () {
        if (!barQueue.length) bar.classList.remove('tone-' + item.type);
        nextBarMessage();
      }, 450);
    }
    function onKey(event) { if (event.key === 'Escape') finish(); }
    dismiss.onclick = finish;
    document.addEventListener('keydown', onKey);

    if (item.type === 'error') {
      progress.style.width = '0';
      dismiss.focus();
      return;
    }
    // Three seconds, longer only when the message is too long to read in that time.
    var duration = Math.max(3000, 1000 + item.msg.length * 40);
    progress.style.transition = 'none';
    progress.style.width = '100%';
    void progress.offsetWidth;
    progress.style.transition = 'width ' + duration + 'ms linear';
    progress.style.width = '0';
    timer = setTimeout(finish, duration);
  }

  /* The bar messages use: the screen's action bar, or a buttonless one made on the spot. */
  function messageBar() {
    var bar = document.querySelector('[data-action-bar]');
    if (bar) return bar;
    bar = document.createElement('div');
    bar.setAttribute('data-action-bar', '');
    bar.setAttribute('data-action-bar-floating', '');
    bar.className = 'fixed inset-x-0 bottom-0 z-50' + (document.querySelector('[data-sidebar]') ? ' lg:left-64' : '');
    bar.innerHTML =
      '<div class="ab-stage">' +
        '<div class="ab-layer ab-content px-4 py-3 lg:px-6" aria-hidden="true" data-action-bar-content><span class="block h-8"></span></div>' +
        '<div class="ab-layer ab-message flex items-center gap-3 px-4 py-3 lg:px-6" aria-hidden="true" data-action-bar-message>' +
          '<span class="shrink-0" data-action-bar-icon></span>' +
          '<p class="min-w-0 flex-1 text-sm font-medium" data-action-bar-text></p>' +
          '<button type="button" class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium" data-action-bar-dismiss tabindex="-1">Dismiss</button>' +
        '</div>' +
      '</div>' +
      '<div class="ab-progress" data-action-bar-progress></div>' +
      '<div class="sr-only" role="status" aria-live="polite" data-action-bar-live></div>';
    document.body.appendChild(bar);
    return bar;
  }

  window.adminToast = function (msg, type) {
    type = MESSAGE_TYPES.indexOf(type) !== -1 ? type : 'info';
    showBarMessage(messageBar(), String(msg), type);
  };

  function showServerFlashes() {
    document.querySelectorAll('template[data-flash]').forEach(function (tpl) {
      var message = (tpl.content ? tpl.content.textContent : tpl.textContent || '').trim();
      if (message) window.adminToast(message, tpl.getAttribute('data-type') || 'success');
      tpl.remove();
    });
  }

  /* ---- Sortable tables ---- */
  function sortTable(th) {
    var table = closest(th, 'table');
    var body = table && table.tBodies[0];
    if (!body) return;
    var index = Array.prototype.indexOf.call(th.parentElement.children, th);
    var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
    table.querySelectorAll('th[data-sort]').forEach(function (other) {
      other.removeAttribute('aria-sort');
      var icon = other.querySelector('[data-sort-icon]');
      if (icon) icon.textContent = '\u2195';
    });
    th.setAttribute('aria-sort', dir);
    var ownIcon = th.querySelector('[data-sort-icon]');
    if (ownIcon) ownIcon.textContent = dir === 'ascending' ? '\u2191' : '\u2193';
    var numeric = th.getAttribute('data-sort') === 'number';
    var rows = Array.prototype.slice.call(body.rows);
    rows.sort(function (a, b) {
      var ca = a.cells[index], cb = b.cells[index];
      var va = ca ? (ca.getAttribute('data-sort-value') || ca.textContent).trim() : '';
      var vb = cb ? (cb.getAttribute('data-sort-value') || cb.textContent).trim() : '';
      var result = numeric ? (parseFloat(va) || 0) - (parseFloat(vb) || 0) : va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
      return dir === 'ascending' ? result : -result;
    });
    rows.forEach(function (row) { body.appendChild(row); });
  }
  function decorateSortableHeaders() {
    document.querySelectorAll('table[data-sortable] th[data-sort]').forEach(function (th) {
      if (th.querySelector('[data-sort-icon]')) return;
      th.classList.add('cursor-pointer', 'select-none', 'hover:text-slate-600');
      th.setAttribute('tabindex', '0');
      var icon = document.createElement('span');
      icon.setAttribute('data-sort-icon', '');
      icon.setAttribute('aria-hidden', 'true');
      icon.className = 'ml-1 text-[10px] text-slate-300';
      icon.textContent = '\u2195';
      th.appendChild(icon);
    });
  }

  /* ---- Confirmation modal (replaces window.confirm for forms and submit buttons with data-confirm) ---- */
  var pendingConfirm = null;
  function ensureConfirmModal() {
    var modal = document.getElementById('admin-confirm-modal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'admin-confirm-modal';
    modal.setAttribute('data-modal', '');
    modal.setAttribute('hidden', '');
    modal.className = 'fixed inset-0 z-[1000] flex items-center justify-center p-4';
    modal.innerHTML =
      '<div data-modal-backdrop class="absolute inset-0 bg-slate-900/40"></div>' +
      '<div role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" class="relative w-full max-w-sm rounded-lg border border-slate-200 bg-white p-5 shadow-xl">' +
      '<h2 id="admin-confirm-title" class="text-sm font-semibold text-slate-900">Please confirm</h2>' +
      '<p data-confirm-message class="mt-2 text-sm text-slate-600"></p>' +
      '<div class="mt-5 flex justify-end gap-2">' +
      '<button type="button" data-modal-close class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>' +
      '<button type="button" data-confirm-accept class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Confirm</button>' +
      '</div></div>';
    document.body.appendChild(modal);
    modal.querySelector('[data-confirm-accept]').addEventListener('click', function () {
      var accept = pendingConfirm;
      pendingConfirm = null;
      closeModal(modal);
      if (accept) accept();
    });
    return modal;
  }
  function askConfirm(source, onAccept) {
    pendingConfirm = onAccept;
    var modal = ensureConfirmModal();
    modal.querySelector('[data-confirm-message]').textContent = source.getAttribute('data-confirm') || 'Are you sure?';
    modal.querySelector('[data-confirm-accept]').textContent = source.getAttribute('data-confirm-button') || 'Confirm';
    openModal('admin-confirm-modal');
    modal.querySelector('[data-confirm-accept]').focus();
  }
  function submitConfirmed(form, submitter) {
    form.setAttribute('data-confirmed', '1');
    if (submitter) submitter.setAttribute('data-confirmed', '1');
    if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
    form.removeAttribute('data-confirmed');
    if (submitter) submitter.removeAttribute('data-confirmed');
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.getAttribute('data-confirmed') === '1') return;
    var submitter = e.submitter || null;
    var source = submitter && submitter.hasAttribute('data-confirm') ? submitter : (form.hasAttribute('data-confirm') ? form : null);
    if (!source) return;
    e.preventDefault();
    askConfirm(source, function () { submitConfirmed(form, submitter); });
  });

  /* ---- Global delegated click handler ---- */
  document.addEventListener('click', function (e) {
    var t = e.target;

    var sbToggle = closest(t, '[data-toggle="sidebar"]');
    if (sbToggle) { setSidebar(true); return; }
    if (closest(t, '[data-sidebar-backdrop]')) { setSidebar(false); return; }
    if (closest(t, '[data-sidebar-close]')) { setSidebar(false); return; }

    var dTrigger = closest(t, '[data-dropdown-trigger]');
    if (dTrigger) {
      e.preventDefault();
      var menu = dTrigger.parentElement.querySelector('[data-dropdown-menu]');
      var willOpen = menu && menu.classList.contains('hidden');
      closeAllDropdowns(menu);
      if (menu) menu.classList.toggle('hidden', !willOpen);
      return;
    }

    var tabBtn = closest(t, '[data-tab]');
    if (tabBtn) {
      var wrap = closest(tabBtn, '[data-tabs]');
      if (wrap) activateTab(wrap, tabBtn.getAttribute('data-tab'));
      return;
    }

    var fToggle = closest(t, '[data-toggle="filter"]');
    if (fToggle) {
      var target = document.getElementById(fToggle.getAttribute('data-target'));
      if (target) target.classList.toggle('hidden');
      return;
    }

    var ocOpen = closest(t, '[data-offcanvas-open]');
    if (ocOpen) { openOffcanvas(ocOpen.getAttribute('data-offcanvas-open')); return; }
    if (closest(t, '[data-offcanvas-close]') || closest(t, '[data-offcanvas-backdrop]')) {
      closeOffcanvas(closest(t, '[data-offcanvas-root]')); return;
    }

    var mOpen = closest(t, '[data-modal-open]');
    if (mOpen) { openModal(mOpen.getAttribute('data-modal-open')); return; }
    if (closest(t, '[data-modal-close]') || closest(t, '[data-modal-backdrop]')) {
      closeModal(closest(t, '[data-modal]')); return;
    }


    var sortHeader = closest(t, 'table[data-sortable] th[data-sort]');
    if (sortHeader) { sortTable(sortHeader); return; }

    var copyBtn = closest(t, '[data-copy]');
    if (copyBtn) {
      var value = copyBtn.getAttribute('data-copy') || '';
      var done = function () { window.adminToast('Copied: ' + value, 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copy:', value); });
      } else {
        window.prompt('Copy:', value);
      }
      return;
    }

    /* ---- Media library: filter by type ---- */
    var mediaFilter = closest(t, '[data-media-filter]');
    if (mediaFilter) {
      var scope = closest(mediaFilter, '[data-media-scope]') || document;
      var val = mediaFilter.getAttribute('data-media-filter');
      scope.querySelectorAll('[data-media-filter]').forEach(function (b) {
        var on = b === mediaFilter;
        b.classList.toggle('bg-slate-900', on);
        b.classList.toggle('text-white', on);
        b.classList.toggle('text-slate-600', !on);
        b.classList.toggle('hover:bg-slate-100', !on);
      });
      scope.querySelectorAll('[data-file-type]').forEach(function (item) {
        var match = val === 'all' || item.getAttribute('data-file-type') === val;
        item.classList.toggle('hidden', !match);
      });
      var counter = scope.querySelector('[data-media-count]');
      if (counter) {
        var shown = scope.querySelectorAll('[data-media-pane="list"] [data-file-type]:not(.hidden)').length;
        counter.textContent = shown + (shown === 1 ? ' file' : ' files');
      }
      return;
    }

    /* ---- Media library: list / grid view toggle ---- */
    var viewBtn = closest(t, '[data-media-view]');
    if (viewBtn) {
      var vscope = closest(viewBtn, '[data-media-scope]') || document;
      var mode = viewBtn.getAttribute('data-media-view');
      vscope.querySelectorAll('[data-media-view]').forEach(function (b) {
        var on = b === viewBtn;
        b.classList.toggle('bg-white', on);
        b.classList.toggle('text-slate-900', on);
        b.classList.toggle('shadow-sm', on);
        b.classList.toggle('text-slate-400', !on);
      });
      vscope.querySelectorAll('[data-media-pane]').forEach(function (p) {
        p.classList.toggle('hidden', p.getAttribute('data-media-pane') !== mode);
      });
      return;
    }

    if (!closest(t, '[data-dropdown]')) closeAllDropdowns(null);
  });

  document.addEventListener('change', function (e) {
    var toggle = closest(e.target, '[data-select-all]');
    if (!toggle) return;
    var scope = toggle.form || document;
    var name = toggle.getAttribute('data-select-all');
    scope.querySelectorAll('input[type="checkbox"][name="' + name + '"]').forEach(function (box) {
      if (!box.disabled) box.checked = toggle.checked;
    });
  });

  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.matches && e.target.matches('table[data-sortable] th[data-sort]')) {
      e.preventDefault();
      sortTable(e.target);
      return;
    }
    if (e.key === 'Escape') {
      closeAllDropdowns(null);
      setSidebar(false);
      document.querySelectorAll('[data-offcanvas-root]:not([hidden])').forEach(closeOffcanvas);
      document.querySelectorAll('[data-modal]:not([hidden])').forEach(closeModal);
    }
  });

  /* ---- CSRF safety net ----
   * Every server-rendered POST form already carries {{ csrf_field() }}; this covers forms
   * built or moved by scripts so they cannot silently fail verification. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || String(form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="_csrf"]')) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_csrf';
    input.value = meta.getAttribute('content') || '';
    form.appendChild(input);
  }, true);

  /* Repeaters in server-rendered forms (theme settings, content type fields): add, remove, and move rows. Rows are cloned from the
   * template. A row may hold a list of its own (two levels): every action works on the nearest list only, and the names of a row's
   * fields (also those of its inner list, and of that list's template) start with the list's name and the row's number, which is
   * rewritten whenever rows move, so the saved lists keep the order shown. */
  function ownRows(box) {
    var host = box.querySelector(':scope > [data-repeater-rows]');
    return host ? Array.prototype.slice.call(host.children).filter(function (row) { return row.hasAttribute('data-repeater-row'); }) : [];
  }

  /** The first element that matches inside a row of a list and belongs to that row, not to a row of an inner list. */
  function ownPart(row, selector) {
    var found = row.querySelectorAll(selector);
    for (var i = 0; i < found.length; i++) {
      if (found[i].closest('[data-repeater-row]') === row) return found[i];
    }
    return null;
  }

  /** Gives every name in a row that starts with base[<anything>] the prefix base[index], also inside the templates of inner lists. */
  function renameRow(row, base, index) {
    var prefix = base + '[' + index + ']';
    var start = base + '[';
    var swap = function (value) {
      if (value.indexOf(start) !== 0) return value;
      var close = value.indexOf(']', start.length);
      return close < 0 ? value : prefix + value.slice(close + 1);
    };
    row.querySelectorAll('[name]').forEach(function (control) { control.name = swap(control.name); });
    row.querySelectorAll('[data-repeater-name]').forEach(function (inner) {
      inner.setAttribute('data-repeater-name', swap(inner.getAttribute('data-repeater-name')));
    });
    row.querySelectorAll('template').forEach(function (template) {
      var pattern = new RegExp(base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]"]*\\]', 'g');
      template.innerHTML = template.innerHTML.replace(pattern, prefix);
    });
  }

  function renumberRepeater(box) {
    var rows = ownRows(box);
    var base = box.getAttribute('data-repeater-name') || '';
    var min = parseInt(box.getAttribute('data-repeater-min'), 10) || 0;
    var max = parseInt(box.getAttribute('data-repeater-max'), 10) || 0;
    rows.forEach(function (row, index) {
      if (base) renameRow(row, base, index);
      var title = ownPart(row, '[data-repeater-title]');
      if (title) title.textContent = box.getAttribute('data-repeater-item') + ' ' + (index + 1);
      var up = ownPart(row, '[data-repeater-up]');
      var down = ownPart(row, '[data-repeater-down]');
      var remove = ownPart(row, '[data-repeater-remove]');
      if (up) up.disabled = index === 0;
      if (down) down.disabled = index === rows.length - 1;
      if (remove) remove.disabled = min > 0 && rows.length <= min;
    });
    var add = box.querySelector(':scope > [data-repeater-add]');
    if (add) add.disabled = max > 0 && rows.length >= max;
  }

  document.addEventListener('click', function (event) {
    var box = event.target.closest && event.target.closest('[data-repeater]');
    if (!box) return;
    var rowsHost = box.querySelector(':scope > [data-repeater-rows]');
    if (!rowsHost) return;
    if (event.target.closest('[data-repeater-add]')) {
      var template = box.querySelector(':scope > [data-repeater-template]');
      var count = ownRows(box).length;
      var max = parseInt(box.getAttribute('data-repeater-max'), 10) || 0;
      if (!template || (max > 0 && count >= max)) return;
      var holder = document.createElement('div');
      holder.innerHTML = template.innerHTML.split(box.getAttribute('data-repeater-placeholder') || '__INDEX__').join(String(count));
      var row = holder.firstElementChild;
      rowsHost.appendChild(row);
      renumberRepeater(box);
      row.querySelectorAll('[data-repeater]').forEach(renumberRepeater);
      var first = row.querySelector('input:not([type=hidden]), textarea, button.icon-picker-trigger, select');
      if (first) first.focus();
      return;
    }
    var row = event.target.closest('[data-repeater-row]');
    // Only the buttons of a row of this list: a click in an inner list is that list's.
    if (!row || row.parentElement !== rowsHost) return;
    if (event.target.closest('[data-repeater-remove]')) {
      if (event.target.closest('[data-repeater-remove]').disabled) return;
      var next = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      renumberRepeater(box);
      var focusTarget = next ? ownPart(next, '[data-repeater-remove]') : box.querySelector(':scope > [data-repeater-add]');
      if (focusTarget) focusTarget.focus();
    } else if (event.target.closest('[data-repeater-up]') && row.previousElementSibling) {
      rowsHost.insertBefore(row, row.previousElementSibling);
      renumberRepeater(box);
      ownPart(row, '[data-repeater-up]').focus();
    } else if (event.target.closest('[data-repeater-down]') && row.nextElementSibling) {
      rowsHost.insertBefore(row.nextElementSibling, row);
      renumberRepeater(box);
      ownPart(row, '[data-repeater-down]').focus();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-repeater]').forEach(renumberRepeater);
  });

  /* ---- Tabs, for assistive technology ----
   * Several screens switch their own tabs (the editor, Settings, Theme). This gives every tab bar the same
   * semantics on top of that: each button is a tab that controls its panel, the selected one is the only one in the
   * tab order, and the arrow keys, Home and End move between them (the WAI-ARIA tabs pattern). It follows the `active`
   * class the screens already toggle, so it works with any of them. */
  function enhanceTabs() {
    document.querySelectorAll('[role="tablist"]').forEach(function (list, listIndex) {
      var tabs = Array.prototype.slice.call(list.querySelectorAll('[data-tab]'));
      if (tabs.length === 0) return;
      var scope = list.closest('form') || document;
      if (!list.hasAttribute('aria-label') && !list.hasAttribute('aria-labelledby')) list.setAttribute('aria-label', 'Sections');
      tabs.forEach(function (tab) {
        var key = tab.getAttribute('data-tab');
        if (!tab.id) tab.id = 'tab-' + listIndex + '-' + key;
        tab.setAttribute('role', 'tab');
        var panel = scope.querySelector('[data-panel="' + key + '"]') || scope.querySelector('[data-tab-panel="' + key + '"]');
        if (panel) {
          if (!panel.id) panel.id = 'tabpanel-' + listIndex + '-' + key;
          panel.setAttribute('role', 'tabpanel');
          panel.setAttribute('aria-labelledby', tab.id);
          tab.setAttribute('aria-controls', panel.id);
        }
      });
      function sync() {
        var current = tabs.filter(function (tab) { return tab.classList.contains('active'); })[0] || tabs[0];
        tabs.forEach(function (tab) {
          var on = tab === current;
          tab.setAttribute('aria-selected', on ? 'true' : 'false');
          tab.tabIndex = on ? 0 : -1;
        });
      }
      sync();
      new MutationObserver(sync).observe(list, { attributes: true, attributeFilter: ['class'], subtree: true });
      list.addEventListener('keydown', function (event) {
        var index = tabs.indexOf(document.activeElement);
        if (index === -1) return;
        var next = -1;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        if (next === -1) return;
        event.preventDefault();
        tabs[next].focus();
        tabs[next].click();
      });
    });
  }

  /* ---- Scrollable tables ----
   * A table wider than a phone scrolls sideways inside its box. If nothing in it can take focus, a keyboard user could
   * never scroll it, so such a box becomes focusable (arrow keys then scroll it). Checked again when the window changes. */
  function makeScrollersFocusable() {
    document.querySelectorAll('.overflow-x-auto').forEach(function (box) {
      var scrolls = box.scrollWidth > box.clientWidth + 1;
      var hasFocusable = !!box.querySelector('a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
      if (scrolls && !hasFocusable) box.setAttribute('tabindex', '0');
      else if (box.getAttribute('data-scroll-focus') === '1' && !scrolls) box.removeAttribute('tabindex');
      if (scrolls && !hasFocusable) box.setAttribute('data-scroll-focus', '1');
    });
  }
  window.addEventListener('resize', makeScrollersFocusable);

  /* Activate first tab in each tab group on load */
  document.addEventListener('DOMContentLoaded', function () {
    injectThemeToggle();
    showServerFlashes();
    decorateSortableHeaders();
    enhanceTabs();
    makeScrollersFocusable();
    document.querySelectorAll('[data-tabs]').forEach(function (wrap) {
      var first = wrap.querySelector('[data-tab][data-tab-default]') || wrap.querySelector('[data-tab]');
      if (first) activateTab(wrap, first.getAttribute('data-tab'));
    });
  });
})();
