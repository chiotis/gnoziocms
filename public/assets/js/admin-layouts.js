/*
 * Theme > Single Layouts, Archive Layouts, Header and Footer. The controls of a card are plain radio buttons, checkboxes and
 * fields, so the form works without this script; it only keeps the little page of a single layout card, and the
 * summary line of every card in step with what is chosen.
 */
(function () {
  'use strict';

  function chosen(card, key) {
    var found = null;
    card.querySelectorAll('[data-lc="' + key + '"]').forEach(function (el) {
      if (el.type === 'radio' && !el.checked) { return; }
      found = el;
    });
    return found;
  }

  /** The words of a choice: the label of the chosen segment, or the chosen option of a list. */
  function words(el, full) {
    if (!el) { return ''; }
    if (el.tagName === 'SELECT') { return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text.trim() : ''; }
    if (el.type === 'radio') {
      var label = el.parentElement;
      if (full && label.getAttribute('title')) { return label.getAttribute('title'); }
      var span = el.nextElementSibling;
      return span ? span.textContent.trim() : '';
    }
    return el.value;
  }

  function update(card) {
    var preview = card.querySelector('[data-lc-preview]');
    ['template', 'title', 'header', 'sidebar'].forEach(function (key) {
      var el = chosen(card, key);
      if (el && preview) { preview.setAttribute('data-' + key, el.value); }
      if (el && (key === 'template' || key === 'sidebar')) { card.setAttribute('data-' + key, el.value); }
    });
    // The page is the theme's template or one built with blocks: the card's own choices (below) are for the theme's.
    var built = chosen(card, 'built');
    if (built) { card.setAttribute('data-built', built.value); }

    var out = card.querySelector('[data-lc-summary]');
    if (!out) { return; }
    var parts = [];
    if (chosen(card, 'archive')) {
      parts.push(words(chosen(card, 'archive'), true));
      var columns = chosen(card, 'columns');
      if (columns) { parts.push(columns.value + ' columns'); }
      var per = chosen(card, 'per_page');
      if (per) { parts.push(parseInt(per.value, 10) > 0 ? per.value + ' per page' : 'all on one page'); }
    } else {
      var template = chosen(card, 'template');
      if (template) { parts.push(words(template)); }
      if (!template || template.value !== 'landing') { parts.push(words(chosen(card, 'title'))); }
      var sidebar = chosen(card, 'sidebar');
      if (sidebar && sidebar.value !== 'none' && (!template || template.value !== 'landing')) { parts.push('Sidebar ' + words(sidebar).toLowerCase()); }
      var header = chosen(card, 'header');
      if (header && header.value !== 'site') { parts.push(words(header)); }
    }
    out.textContent = parts.filter(Boolean).join(' · ');
  }

  /** The Header and Footer tabs: rows that belong to one layout only (data-only) follow the layout that is chosen. */
  document.querySelectorAll('[data-hf-root]').forEach(function (root) {
    var sync = function () {
      var chosen = root.querySelector('input[name$="[layout]"]:checked');
      if (chosen) { root.setAttribute('data-layout', chosen.value); }
    };
    root.addEventListener('change', sync);
    sync();
  });

  document.querySelectorAll('[data-layout-card]').forEach(function (card) {
    var reset = card.querySelector('[data-built-reset]');
    if (reset) {
      // Reset is the choice "The theme's" in one click; the built template stays stored (Save keeps it for later).
      reset.addEventListener('click', function () {
        var theme = card.querySelector('input[data-lc="built"][value="theme"]');
        if (theme) { theme.checked = true; update(card); theme.focus(); }
      });
    }
    update(card);
    card.addEventListener('change', function () { update(card); });
    card.addEventListener('input', function () { update(card); });
  });
}());
