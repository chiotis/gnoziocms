/*
 * The live preview beside the content editor (Admin > Edit). admin-preview.js, in its "entry" mode, draws the page from the
 * unsaved form; this script is the panel around it:
 *   - the toggle in the editor's header shows and hides the panel (aria-expanded); the choice is remembered per browser
 *   - the grip on the panel's edge resizes it (pointer, or the arrow keys); the width is remembered
 *   - below 1200px the panel is a drawer over the editor (Escape or Close hides it); it never opens by itself there
 *   - a click on a block in the picture opens that block in the Blocks tab, and a click on a block in the builder scrolls the
 *     picture to it and outlines it for a moment. Blocks are told apart by their place in the list (data-bk-block in the
 *     picture, the order of [data-block-card] in the builder), so this does not care how the builder draws them.
 * Nothing here is needed to edit: without the script the form works as before.
 */
(function () {
  'use strict';

  var split = document.querySelector('[data-entry-split]');
  var panel = document.querySelector('[data-entry-preview]');
  var toggle = document.querySelector('[data-entry-preview-toggle]');
  if (!split || !panel || !toggle) { return; }
  var grip = panel.querySelector('[data-entry-preview-grip]');
  var close = panel.querySelector('[data-entry-preview-close]');
  var frame = panel.querySelector('[data-bp-frame]');

  var KEY_OPEN = 'faros.entryPreview.open';
  var KEY_WIDTH = 'faros.entryPreview.width';
  var MIN = 320;
  var MAX = 900;
  var narrow = window.matchMedia('(max-width: 1199.98px)');

  function read(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } }
  function write(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* private window: the choice is not kept */ } }

  var width = parseInt(read(KEY_WIDTH), 10) || 560;

  function setWidth(value, remember) {
    width = Math.max(MIN, Math.min(MAX, Math.round(value)));
    split.style.setProperty('--ep-width', width + 'px');
    if (grip) { grip.setAttribute('aria-valuenow', String(width)); }
    if (remember) { write(KEY_WIDTH, String(width)); }
  }

  function isOpen() { return !panel.hidden; }

  function show(open, remember) {
    panel.hidden = !open;
    split.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (remember) { write(KEY_OPEN, open ? '1' : '0'); }
    if (open) { panel.dispatchEvent(new CustomEvent('preview:refresh')); }
  }

  toggle.addEventListener('click', function () { show(!isOpen(), true); });
  if (close) { close.addEventListener('click', function () { show(false, false); toggle.focus(); }); }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen() && narrow.matches && panel.contains(document.activeElement)) { show(false, false); toggle.focus(); }
  });

  setWidth(width, false);
  // The drawer covers the form, so a remembered "open" only applies to the split.
  if (read(KEY_OPEN) === '1' && !narrow.matches) { show(true, false); }

  /* ---------------------------------------------------------------- resizing */

  if (grip) {
    grip.addEventListener('pointerdown', function (event) {
      event.preventDefault();
      var startX = event.clientX;
      var start = width;
      split.classList.add('is-resizing');
      try { grip.setPointerCapture(event.pointerId); } catch (e) { /* older browsers: the move is followed on the document */ }
      function move(e) { setWidth(start + (startX - e.clientX), false); }
      function end() {
        split.classList.remove('is-resizing');
        grip.removeEventListener('pointermove', move);
        grip.removeEventListener('pointerup', end);
        grip.removeEventListener('pointercancel', end);
        write(KEY_WIDTH, String(width));
      }
      grip.addEventListener('pointermove', move);
      grip.addEventListener('pointerup', end);
      grip.addEventListener('pointercancel', end);
    });
    grip.addEventListener('keydown', function (event) {
      var step = event.shiftKey ? 80 : 24;
      if (event.key === 'ArrowLeft') { setWidth(width + step, true); event.preventDefault(); }
      if (event.key === 'ArrowRight') { setWidth(width - step, true); event.preventDefault(); }
    });
  }

  /* ---------------------------------------------------------------- the picture and the builder, block to block */

  var outlined = { index: -1, until: 0 };

  function frameDocument() {
    try { return frame.contentDocument; } catch (e) { return null; }
  }

  function blockInFrame(index) {
    var doc = frameDocument();
    return doc ? doc.querySelector('[data-bk-block="' + index + '"]') : null;
  }

  function outline(index, scroll) {
    var doc = frameDocument();
    var el = blockInFrame(index);
    if (!doc || !el) { return; }
    if (!doc.getElementById('faros-entry-preview')) {
      var style = doc.createElement('style');
      style.id = 'faros-entry-preview';
      style.textContent = '.faros-bk-hit{outline:3px solid #2563eb !important;outline-offset:-3px;transition:outline-color .4s}';
      doc.head.appendChild(style);
    }
    Array.prototype.forEach.call(doc.querySelectorAll('.faros-bk-hit'), function (other) { other.classList.remove('faros-bk-hit'); });
    el.classList.add('faros-bk-hit');
    if (scroll) {
      try { frame.contentWindow.scrollTo({ top: Math.max(0, el.getBoundingClientRect().top + frame.contentWindow.scrollY - 72), behavior: 'smooth' }); } catch (e) { /* no scrolling */ }
    }
    outlined = { index: index, until: Date.now() + 1600 };
    setTimeout(function () {
      var again = blockInFrame(index);
      if (again && Date.now() >= outlined.until - 50) { again.classList.remove('faros-bk-hit'); }
    }, 1650);
  }

  function cards() { return document.querySelectorAll('[data-block-editor] [data-block-card]'); }

  /** The card of a place in the list: '3' is the fourth block, '3.1.0' the first block of the second column of the fourth block (a container). */
  function cardFor(path) {
    var found = document.querySelector('[data-block-editor] [data-block-card][data-block-path="' + path + '"]');
    return found || (/^\d+$/.test(String(path)) ? cards()[parseInt(path, 10)] : null);
  }

  // The picture is drawn again after each change: the outline that is still due is put back, and clicks are caught.
  panel.addEventListener('preview:drawn', function () {
    var doc = frameDocument();
    if (!doc) { return; }
    doc.addEventListener('click', function (event) {
      var link = event.target.closest ? event.target.closest('a, button, input, select, textarea, label, form') : null;
      var block = event.target.closest ? event.target.closest('[data-bk-block]') : null;
      // Nothing inside the picture is followed or sent: it shows the page, the builder changes it.
      if (link) { event.preventDefault(); }
      if (block) { openBlock(block.getAttribute('data-bk-block')); }
    }, true);
    doc.addEventListener('submit', function (event) { event.preventDefault(); }, true);
    if (outlined.index !== -1 && Date.now() < outlined.until) { outline(outlined.index, false); }
  });

  function openBlock(index) {
    var tab = document.querySelector('[data-tab="blocks"]');
    if (tab && !tab.classList.contains('active')) { tab.click(); }
    if (narrow.matches) { show(false, false); }
    // The builder opens the block's settings in its panel; the list keeps the row in view.
    if (window.GnozioBlocks && window.GnozioBlocks.open(index)) {
      var row = cardFor(index);
      if (row) { try { row.scrollIntoView({ block: 'nearest' }); } catch (e) { /* no scrolling */ } }
      outline(index, false);
      return;
    }
    // An older builder: a block inside a container opens the container first, then the block.
    var steps = String(index).split('.');
    if (steps.length === 3) {
      var outer = cardFor(steps[0]);
      var outerButton = outer ? outer.querySelector('[aria-expanded]') : null;
      if (outerButton && outerButton.getAttribute('aria-expanded') !== 'true') { outerButton.click(); }
    }
    var card = cardFor(index);
    if (!card) { return; }
    var button = card.querySelector('[aria-expanded]');
    if (button && button.getAttribute('aria-expanded') !== 'true') { button.click(); }
    var now = cardFor(index) || card;
    try { now.scrollIntoView({ block: 'nearest' }); } catch (e) { /* no scrolling */ }
    var target = now.querySelector('[aria-expanded]');
    if (target) { target.focus({ preventScroll: true }); }
    outline(index, false);
  }

  // A click on a block's header in the builder shows that block in the picture (caught before the builder draws itself again).
  document.addEventListener('click', function (event) {
    if (!isOpen() || !event.target.closest) { return; }
    var head = event.target.closest('[data-block-editor] .bk-toggle');
    var card = head ? head.closest('[data-block-card]') : null;
    if (!card) { return; }
    var path = card.getAttribute('data-block-path');
    var index = path !== null ? path : Array.prototype.indexOf.call(cards(), card);
    if (index !== -1) { outline(index, true); }
  }, true); // while the card is still there: opening it draws the builder again
})();
