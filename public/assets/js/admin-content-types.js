/*
 * Admin > Content types > one type: adds a field row, removes one of the site's own fields (the row is dimmed and its inputs are
 * disabled so they are not sent, and it can be brought back until the type is saved) and shows the parts that belong to the kind
 * of field (the options and the filter of a select). Without this script the form is the plain rows.
 */
(function () {
  'use strict';

  var list = document.querySelector('[data-field-rows]');
  var template = document.getElementById('field-row-template');
  var add = document.querySelector('[data-add-field]');
  var count = document.querySelector('.ct-count');
  if (!list) { return; }
  var next = list.querySelectorAll('[data-field]').length;

  function kind(row) {
    var select = row.querySelector('[data-kind-select]');
    if (!select) { return; }
    row.setAttribute('data-kind', select.value);
    row.querySelectorAll('[data-only-kind]').forEach(function (part) {
      part.hidden = part.getAttribute('data-only-kind').split(' ').indexOf(select.value) === -1;
    });
  }

  function recount() {
    if (count) { count.textContent = list.querySelectorAll('[data-field]:not(.is-removed)').length; }
  }

  function remove(row, button) {
    var gone = !row.classList.contains('is-removed');
    if (gone && row.hasAttribute('data-new-row')) { row.remove(); recount(); return; }
    row.classList.toggle('is-removed', gone);
    row.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = gone; });
    button.textContent = gone ? 'Undo' : 'Remove';
    recount();
  }

  list.addEventListener('change', function (e) {
    if (e.target.matches('[data-kind-select]')) { kind(e.target.closest('[data-field]')); }
    if (e.target.matches('input[name$="[retired]"]')) { e.target.closest('[data-field]').classList.toggle('is-retired', e.target.checked); }
  });
  list.addEventListener('click', function (e) {
    var button = e.target.closest('[data-remove-field]');
    if (button) { remove(button.closest('[data-field]'), button); }
  });

  if (add && template) {
    add.addEventListener('click', function () {
      var empty = list.querySelector('[data-empty]');
      if (empty) { empty.remove(); }
      list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, String(next++)));
      var row = list.lastElementChild;
      var key = row && row.querySelector('input[type="text"]');
      if (key) { key.focus(); row.scrollIntoView({ block: 'nearest' }); }
      recount();
    });
  }
})();
