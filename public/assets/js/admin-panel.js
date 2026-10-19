/*
 * GnozioCMS settings panel: a fixed, off-canvas place for the settings of one thing at a time (a block of the block editor today,
 * a block of the template builder later). It knows nothing about blocks: the caller hands it a title, tabs and a function that draws
 * a tab, and keeps its own data.
 *
 *   var panel = GnozioPanel.create({ mount: element, id: 'bk-panel', label: 'Block settings', empty: 'Select a block…' });
 *   panel.open({ key, title, icon, crumbs, tabs: [{key, label}], tab, render(tabKey) -> node, returnFocus, onTab, onPrev, onNext,
 *                onClose, focus, announce });
 *   panel.update({ title, crumbs, onPrev, onNext })   the header only
 *   panel.refresh()                                   the open tab again, keeping the scroll and the focused control
 *   panel.close(restoreFocus)  panel.isOpen()  panel.current()  panel.mode()
 * Options of create(): mount, id, name (for the remembered width), label (its name while empty), empty (the hint), backLabel (the
 * Back button of the phone sheet), above (a selector of a bar fixed at the bottom of the screen that the overlay stops above).
 *
 * Where it shows (the mount gets the class of the mode):
 *   docked   the mount is wide enough (880px and more): a column at its right that stays in view while the page scrolls; with
 *            nothing open it says what to do. A region, not a dialog: Escape takes the focus back to what opened it.
 *   overlay  a narrower mount (the live preview beside it, a tablet): a sheet over the right edge of the mount, never over the
 *            preview. Escape or Close hides it and gives the focus back.
 *   sheet    a phone (below 768px): the whole screen, a modal dialog that keeps the focus inside.
 * The width of the docked and overlay panel is dragged on its left edge (or the arrow keys on that edge) and remembered.
 */
(function () {
  'use strict';

  var MIN = 340;
  var MAX = 560;
  var DEFAULT = 400;
  var DOCK_AT = 880;
  var PHONE = '(max-width: 767px)';

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'class') node.className = attrs[key];
      else if (key === 'text') node.textContent = attrs[key];
      else if (key === 'html') node.innerHTML = attrs[key];
      else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
    });
    (children || []).forEach(function (child) { if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child); });
    return node;
  }

  function icon(path) {
    return '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }
  var ICON = {
    close: icon('<path d="M6 6l12 12M18 6L6 18"/>'),
    up: icon('<path d="m6 15 6-6 6 6"/>'),
    down: icon('<path d="m6 9 6 6 6-6"/>'),
    back: icon('<path d="m15 6-6 6 6 6"/>')
  };

  function read(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
  function write(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* private window */ } }

  var FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

  function visible(node) {
    return !node.closest('[hidden]') && node.getClientRects !== undefined;
  }

  function create(options) {
    var mount = options.mount;
    var id = options.id || 'gz-panel';
    var widthKey = 'gnozio.panel.' + (options.name || id) + '.width';
    var phone = window.matchMedia ? window.matchMedia(PHONE) : { matches: false, addEventListener: function () {} };
    var state = null; // what is open: the options given to open()
    var tab = '';
    var mode = '';
    var width = Math.min(MAX, Math.max(MIN, Number(read(widthKey)) || DEFAULT));

    var titleId = id + '-title';
    var titleNode = el('h2', { class: 'sp-title', id: titleId, tabindex: '-1' });
    var iconNode = el('span', { class: 'sp-icon', 'aria-hidden': 'true' });
    var crumbsNode = el('p', { class: 'sp-crumbs' });
    var prev = el('button', { type: 'button', class: 'sp-btn', 'aria-label': 'Previous', title: 'Previous', html: ICON.up, 'data-panel-prev': '' });
    var next = el('button', { type: 'button', class: 'sp-btn', 'aria-label': 'Next', title: 'Next', html: ICON.down, 'data-panel-next': '' });
    var close = el('button', { type: 'button', class: 'sp-btn', 'aria-label': 'Close the settings', title: 'Close', html: ICON.close, 'data-panel-close': '' });
    var back = el('button', { type: 'button', class: 'sp-back', html: ICON.back + '<span>' + (options.backLabel || 'Back') + '</span>', 'data-panel-back': '' });
    var tabsNode = el('div', { class: 'sp-tabs', role: 'tablist' });
    var body = el('div', { class: 'sp-body', role: 'tabpanel', id: id + '-body', tabindex: '-1' });
    var empty = el('div', { class: 'sp-empty' }, [el('p', { text: options.empty || 'Nothing selected.' })]);
    var live = el('p', { class: 'sp-sr', 'aria-live': 'polite' });
    var grip = el('div', { class: 'sp-grip', role: 'separator', 'aria-orientation': 'vertical', tabindex: '0', 'aria-label': 'Width of the settings. Use the left and right arrow keys.', 'aria-valuemin': String(MIN), 'aria-valuemax': String(MAX) });
    var head = el('div', { class: 'sp-head' }, [
      back,
      el('div', { class: 'sp-headline' }, [iconNode, el('div', { class: 'sp-names' }, [titleNode, crumbsNode])]),
      el('div', { class: 'sp-nav' }, [prev, next, close])
    ]);
    var frame = el('div', { class: 'sp-frame' }, [head, tabsNode, body]);
    var panel = el('div', { class: 'sp-panel', id: id, 'aria-labelledby': titleId, 'data-panel': '' }, [grip, empty, frame, live]);
    mount.classList.add('sp-host');
    mount.appendChild(panel);

    function setWidth(value, remember) {
      width = Math.round(Math.min(MAX, Math.max(MIN, value)));
      mount.style.setProperty('--sp-width', width + 'px');
      grip.setAttribute('aria-valuenow', String(width));
      if (remember) write(widthKey, String(width));
    }
    setWidth(width, false);

    /* Where the panel shows ---------------------------------------------------------------------- */

    function layout() {
      var box = mount.getBoundingClientRect();
      var wanted = phone.matches ? 'sheet' : (box.width >= DOCK_AT ? 'docked' : 'overlay');
      // The overlay sits on the right edge of the mount, so the live preview beside it stays in view, and above a bar fixed at the
      // bottom of the screen (options.above: the Save bar), so that stays in reach.
      mount.style.setProperty('--sp-right', Math.max(0, Math.round((window.innerWidth || 0) - box.right)) + 'px');
      var bar = options.above ? document.querySelector(options.above) : null;
      var barBox = bar ? bar.getBoundingClientRect() : null;
      mount.style.setProperty('--sp-bottom', (barBox && barBox.height ? Math.max(0, Math.round((window.innerHeight || 0) - barBox.top)) : 0) + 'px');
      if (wanted === mode) return;
      var hadFocus = panel.contains(document.activeElement);
      mode = wanted;
      mount.classList.toggle('is-docked', mode === 'docked');
      mount.classList.toggle('is-overlay', mode === 'overlay');
      mount.classList.toggle('is-sheet', mode === 'sheet');
      sync();

    }

    /** The roles and visibility that follow from the mode and whether something is open. */
    function sync() {
      var open = !!state;
      panel.classList.toggle('is-open', open);
      // Docked, the panel is always there (with a hint while nothing is open); otherwise it shows only while something is open.
      panel.hidden = !open && mode !== 'docked';
      empty.hidden = open;
      frame.hidden = !open;
      // With nothing open the title is empty, so the panel is named by its label.
      if (open) { panel.setAttribute('aria-labelledby', titleId); panel.removeAttribute('aria-label'); }
      else { panel.removeAttribute('aria-labelledby'); panel.setAttribute('aria-label', options.label || 'Settings'); }
      if (mode === 'sheet' && open) {
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        document.documentElement.classList.add('sp-locked');
      } else {
        panel.setAttribute('role', open ? 'region' : 'complementary');
        panel.removeAttribute('aria-modal');
        document.documentElement.classList.remove('sp-locked');
      }
      back.hidden = mode !== 'sheet';
      grip.hidden = mode === 'sheet';
      close.setAttribute('aria-label', mode === 'docked' ? 'Close the settings' : 'Close the settings and go back');
    }

    if (window.ResizeObserver) new window.ResizeObserver(layout).observe(mount);
    window.addEventListener('resize', layout);
    if (phone.addEventListener) phone.addEventListener('change', layout);

    /* Drawing ------------------------------------------------------------------------------------ */

    function drawHead() {
      titleNode.textContent = state.title || '';
      iconNode.innerHTML = state.icon || '';
      iconNode.hidden = !state.icon;
      crumbsNode.textContent = (state.crumbs || []).join(' › ');
      crumbsNode.hidden = !(state.crumbs && state.crumbs.length);
      prev.disabled = !state.onPrev;
      next.disabled = !state.onNext;
      prev.setAttribute('aria-label', state.prevLabel || 'Previous');
      next.setAttribute('aria-label', state.nextLabel || 'Next');
    }

    function drawTabs() {
      tabsNode.innerHTML = '';
      var tabs = state.tabs || [];
      tabsNode.hidden = tabs.length < 2;
      tabs.forEach(function (t, i) {
        var button = el('button', { type: 'button', role: 'tab', id: id + '-tab-' + t.key, 'data-tab': t.key, 'aria-controls': id + '-body', 'aria-selected': t.key === tab ? 'true' : 'false', tabindex: t.key === tab ? '0' : '-1', text: t.label });
        button.addEventListener('click', function () { choose(t.key, false); });
        button.addEventListener('keydown', function (event) {
          var to = -1;
          if (event.key === 'ArrowRight') to = (i + 1) % tabs.length;
          else if (event.key === 'ArrowLeft') to = (i - 1 + tabs.length) % tabs.length;
          else if (event.key === 'Home') to = 0;
          else if (event.key === 'End') to = tabs.length - 1;
          if (to === -1) return;
          event.preventDefault();
          choose(tabs[to].key, true);
        });
        tabsNode.appendChild(button);
      });
      if (tabs.length > 1) body.setAttribute('aria-labelledby', id + '-tab-' + tab); else body.setAttribute('aria-labelledby', titleId);
    }

    function drawBody() {
      body.innerHTML = '';
      var node = state.render(tab);
      if (node) body.appendChild(node);
    }

    function choose(key, focusTab) {
      if (!state || key === tab) return;
      tab = key;
      drawTabs();
      drawBody();
      body.scrollTop = 0;
      if (state.onTab) state.onTab(key);
      if (focusTab) { var b = tabsNode.querySelector('[data-tab="' + key + '"]'); if (b) b.focus(); }
    }

    function open(given) {
      var same = state && state.key === given.key;
      var keepTab = given.tab || tab;
      state = given;
      var keys = (given.tabs || []).map(function (t) { return t.key; });
      tab = keys.indexOf(keepTab) !== -1 ? keepTab : (keys[0] || '');
      layout();
      sync();
      drawHead();
      drawTabs();
      var scroll = same ? body.scrollTop : 0;
      drawBody();
      body.scrollTop = scroll;
      if (given.announce) live.textContent = given.announce;
      if (given.focus !== false) {
        try { panel.scrollIntoView({ block: 'nearest' }); } catch (e) { /* no layout */ }
        titleNode.focus({ preventScroll: true });
      }
    }

    function update(changes) {
      if (!state) return;
      Object.keys(changes || {}).forEach(function (key) { state[key] = changes[key]; });
      drawHead();
    }

    /** The open tab again (after a change that alters what it offers), keeping the scroll and the control that had the focus. */
    function refresh() {
      if (!state) return;
      var active = document.activeElement;
      var inside = active && body.contains(active);
      var all = inside ? Array.prototype.slice.call(body.querySelectorAll(FOCUSABLE)) : [];
      var at = all.indexOf(active);
      var activeId = inside ? active.id : '';
      var scroll = body.scrollTop;
      drawBody();
      body.scrollTop = scroll;
      if (!inside) return;
      var again = activeId ? body.querySelector('#' + cssEscape(activeId)) : null;
      if (!again && at !== -1) again = body.querySelectorAll(FOCUSABLE)[at] || null;
      if (again) again.focus({ preventScroll: true });
    }

    function cssEscape(value) {
      return window.CSS && window.CSS.escape ? window.CSS.escape(value) : String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    /** What gets the focus back: a node, or a function that finds it (the caller may have drawn it again since). */
    function returnTarget(given) {
      var node = typeof given.returnFocus === 'function' ? given.returnFocus() : given.returnFocus;
      return node && document.contains(node) ? node : null;
    }

    function closePanel(restoreFocus) {
      if (!state) return;
      var was = state;
      var hadFocus = panel.contains(document.activeElement);
      state = null;
      body.innerHTML = '';
      sync();
      if (was.onClose) was.onClose();
      var target = (restoreFocus || hadFocus) ? returnTarget(was) : null;
      if (target) target.focus();
    }

    function isOpen() { return !!state; }

    /* Keyboard ----------------------------------------------------------------------------------- */

    close.addEventListener('click', function () { closePanel(true); });
    back.addEventListener('click', function () { closePanel(true); });
    prev.addEventListener('click', function () { if (state && state.onPrev) state.onPrev('prev'); });
    next.addEventListener('click', function () { if (state && state.onNext) state.onNext('next'); });

    panel.addEventListener('keydown', function (event) {
      if (!state) return;
      if (event.key === 'Escape' && !event.defaultPrevented) {
        // A popup inside (a menu, the icon picker) closes itself first and says so with preventDefault.
        event.preventDefault();
        if (mode === 'docked') {
          var target = returnTarget(state);
          if (target) target.focus();
        } else {
          closePanel(true);
        }
        return;
      }
      if (event.key === 'Tab' && mode === 'sheet') {
        // The sheet is a modal dialog: the focus goes round inside it.
        var all = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), visible);
        if (!all.length) return;
        var first = all[0];
        var last = all[all.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === titleNode)) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });

    /* Width -------------------------------------------------------------------------------------- */

    grip.addEventListener('pointerdown', function (event) {
      event.preventDefault();
      var startX = event.clientX;
      var start = width;
      mount.classList.add('is-resizing');
      try { grip.setPointerCapture(event.pointerId); } catch (e) { /* the move is followed on the grip */ }
      function move(e) { setWidth(start + (startX - e.clientX), false); }
      function end() {
        mount.classList.remove('is-resizing');
        grip.removeEventListener('pointermove', move);
        grip.removeEventListener('pointerup', end);
        grip.removeEventListener('pointercancel', end);
        write(widthKey, String(width));
      }
      grip.addEventListener('pointermove', move);
      grip.addEventListener('pointerup', end);
      grip.addEventListener('pointercancel', end);
    });
    grip.addEventListener('keydown', function (event) {
      var step = event.shiftKey ? 48 : 16;
      if (event.key === 'ArrowLeft') { event.preventDefault(); setWidth(width + step, true); }
      else if (event.key === 'ArrowRight') { event.preventDefault(); setWidth(width - step, true); }
      else if (event.key === 'Home') { event.preventDefault(); setWidth(MAX, true); }
      else if (event.key === 'End') { event.preventDefault(); setWidth(MIN, true); }
    });

    layout();
    sync();

    return {
      element: panel,
      open: open,
      update: update,
      refresh: refresh,
      close: closePanel,
      isOpen: isOpen,
      current: function () { return state ? state.key : null; },
      tab: function () { return tab; },
      mode: function () { return mode; },
      announce: function (text) { live.textContent = text; },
      layout: layout
    };
  }

  window.GnozioPanel = { create: create };
})();
