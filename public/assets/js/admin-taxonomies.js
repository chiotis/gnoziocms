/*
 * The Taxonomies screen. The terms are rows of a list; each row carries the hidden fields the server reads
 * (term_id[], term_slug[], term_label[lang][], term_description[lang][]) in the order the rows are shown, so the whole
 * screen is still one form that is saved with the Save button. Here:
 *
 *   - adding and editing a term in a dialog (its fields have no names: Apply copies them into the row)
 *   - search, select and remove several, reorder (drag the handle, or the arrow buttons), sort A to Z
 *   - removals wait for Save and can be undone: a removed row has all its fields disabled, so it is not sent
 *   - a note when there are unsaved changes, and a warning before leaving with them
 *   - a taxonomy with a tree (data-hierarchical): each row also carries term_parent[], the rows are kept in tree order
 *     (a term followed by its children, indented), the dialog picks a parent that is never the term or below it, and a
 *     term's page address is shown with its parents' addresses in front
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-taxonomy-form]');
  if (!form) return;

  var body = form.querySelector('[data-term-rows]');
  var tableWrap = form.querySelector('[data-term-table]');
  var emptyState = form.querySelector('[data-term-empty]');
  var noMatch = form.querySelector('[data-term-nomatch]');
  var search = form.querySelector('[data-term-search]');
  var selection = form.querySelector('[data-term-selection]');
  var selectAll = form.querySelector('[data-term-select-all]');
  var dirtyNote = form.querySelector('[data-dirty-note]');
  var live = form.querySelector('[data-taxonomy-live]');
  var template = document.querySelector('[data-term-row-template]');
  var dialog = document.querySelector('[data-term-dialog]');
  var dlgHero = dialog ? dialog.querySelector('[data-dialog-hero]') : null;
  var dialogForm = dialog.querySelector('[data-term-dialog-form]');
  var kind = form.getAttribute('data-kind') || 'tag';
  var defaultLang = form.getAttribute('data-default-language') || '';
  var canRedirects = form.getAttribute('data-can-redirects') === '1';
  var tree = form.getAttribute('data-hierarchical') === '1';
  var dlgParent = dialog.querySelector('[data-dialog-parent]');
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var dirty = false;
  var submitting = false;
  var editing = null; // the row being edited, or null while adding
  var slugTouched = false;

  function rows() { return Array.prototype.slice.call(body.querySelectorAll('[data-term-row]')); }
  function field(row, name, lang) {
    return row.querySelector('[data-field="' + name + '"]' + (lang ? '[data-lang="' + lang + '"]' : ''));
  }
  function langs() {
    return Array.prototype.map.call(dialog.querySelectorAll('[data-dialog-label]'), function (input) { return input.getAttribute('data-dialog-label'); });
  }
  function isRemoved(row) { return row.hasAttribute('data-removed'); }
  function announce(text) { if (live) { live.textContent = ''; setTimeout(function () { live.textContent = text; }, 30); } }

  /* ---- the tree ---------------------------------------------------------------------------------------- */

  function idOf(row) { return field(row, 'id').value; }
  function parentOf(row) { var f = field(row, 'parent'); return f ? f.value : ''; }
  function rowById(id) {
    if (id === '') return null;
    var all = rows();
    for (var i = 0; i < all.length; i++) if (idOf(all[i]) === id) return all[i];
    return null;
  }
  function childrenOf(row) {
    var id = idOf(row);
    return id === '' ? [] : rows().filter(function (other) { return parentOf(other) === id; });
  }
  function descendantsOf(row) {
    var found = [];
    (function walk(current) {
      childrenOf(current).forEach(function (child) { if (found.indexOf(child) === -1) { found.push(child); walk(child); } });
    })(row);
    return found;
  }
  // The address after the taxonomy's word: the parents' slugs and the term's own ('' while it has none yet).
  function pathOf(row) {
    var own = field(row, 'slug').value.trim();
    if (own === '') return '';
    if (!tree) return own;
    var parts = [own];
    var at = rowById(parentOf(row));
    for (var guard = 0; at && guard < 20; guard++) {
      var slug = field(at, 'slug').value.trim();
      if (slug === '') return '';
      parts.unshift(slug);
      at = rowById(parentOf(at));
    }
    return parts.join('/');
  }
  // Puts the rows in tree order (a term, then its children, siblings as they were) and indents them.
  function rebuildTree() {
    if (!tree) return;
    var all = rows();
    var byParent = {};
    all.forEach(function (row) {
      var parent = parentOf(row);
      if (parent === '' || !rowById(parent) || parent === idOf(row)) parent = '';
      (byParent[parent] = byParent[parent] || []).push(row);
    });
    var placed = [];
    (function walk(parent, depth) {
      (byParent[parent] || []).forEach(function (row) {
        if (placed.indexOf(row) !== -1) return;
        placed.push(row);
        row.setAttribute('data-depth', String(depth));
        var indent = row.querySelector('[data-term-indent]');
        if (indent) indent.style.marginLeft = depth > 0 ? (depth * 1.25) + 'rem' : '';
        walk(idOf(row), depth + 1);
      });
    })('', 0);
    all.forEach(function (row) { if (placed.indexOf(row) === -1) placed.push(row); });
    placed.forEach(function (row) { body.appendChild(row); });
  }

  function markDirty() {
    dirty = true;
    if (dirtyNote) { dirtyNote.classList.remove('hidden'); dirtyNote.classList.add('inline-flex'); }
  }

  /* ---- what the row shows ------------------------------------------------------------------------------ */

  function primaryName(row) {
    var first = field(row, 'label', defaultLang);
    if (first && first.value.trim() !== '') return first.value.trim();
    var all = row.querySelectorAll('[data-field="label"]');
    for (var i = 0; i < all.length; i++) if (all[i].value.trim() !== '') return all[i].value.trim();
    return '';
  }

  function refreshRow(row) {
    var name = primaryName(row);
    var slug = field(row, 'slug').value.trim();
    var fullPath = pathOf(row);
    var isNew = field(row, 'id').value === '';
    var nameButton = row.querySelector('[data-term-name]');
    nameButton.textContent = name || slug || 'Untitled term';

    var others = row.querySelector('[data-term-langs]');
    others.innerHTML = '';
    langs().forEach(function (lang) {
      if (lang === defaultLang) return;
      var value = field(row, 'label', lang).value.trim();
      if (value === '' && isNew) return;
      var span = document.createElement('span');
      span.setAttribute('data-lang', lang);
      var code = document.createElement('span');
      code.className = 'font-medium uppercase';
      code.textContent = lang;
      span.appendChild(code);
      span.appendChild(document.createTextNode(' ' + (value === '' ? 'not translated' : value)));
      if (value === '') span.className = 'text-amber-700';
      others.appendChild(span);
    });

    var heroField = field(row, 'hero_layout');
    var heroNote = row.querySelector('[data-term-hero]');
    if (heroNote) {
      var chosen = heroField && dlgHero ? dlgHero.querySelector('option[value="' + heroField.value + '"]') : null;
      heroNote.classList.toggle('hidden', !chosen || heroField.value === '');
      if (chosen) heroNote.querySelector('[data-term-hero-label]').textContent = chosen.textContent;
    }

    var extras = row.querySelector('[data-term-extras]');
    if (extras) {
      var picture = field(row, 'image').value.trim() !== '';
      var search = row.querySelectorAll('[data-field="seo_title"],[data-field="seo_description"]');
      var hasSearch = Array.prototype.some.call(search, function (input) { return input.value.trim() !== ''; });
      extras.textContent = [picture ? 'Picture' : '', hasSearch ? 'Search text' : ''].filter(function (part) { return part !== ''; }).join(' · ');
      extras.classList.toggle('hidden', !picture && !hasSearch);
    }

    var text = field(row, 'description', defaultLang);
    var describe = text && text.value.trim() !== '' ? text.value.trim() : '';
    if (describe === '') {
      var any = row.querySelectorAll('[data-field="description"]');
      for (var i = 0; i < any.length; i++) if (any[i].value.trim() !== '') { describe = any[i].value.trim(); break; }
    }
    row.querySelector('[data-term-description]').textContent = describe;

    var address = row.querySelector('[data-term-address]');
    var original = row.getAttribute('data-original-path') || '';
    address.innerHTML = '';
    if (fullPath !== '') {
      var link = document.createElement('span');
      link.className = 'font-mono';
      link.textContent = '/' + kind + '/' + fullPath;
      address.appendChild(link);
    } else {
      var made = document.createElement('span');
      made.className = 'text-slate-500';
      made.textContent = 'Made from the name';
      address.appendChild(made);
    }

    var badge = row.querySelector('[data-term-badge]');
    var changed = !isNew && original !== '' && fullPath !== '' && fullPath !== original;
    if (isNew) { badge.textContent = 'New'; badge.classList.remove('hidden'); }
    else if (row.hasAttribute('data-edited') || changed) { badge.textContent = changed ? 'Address changed' : 'Edited'; badge.classList.remove('hidden'); }
    else badge.classList.add('hidden');

    var words = [];
    row.querySelectorAll('[data-field="label"],[data-field="description"]').forEach(function (input) { words.push(input.value); });
    words.push(slug);
    row.setAttribute('data-search', words.join(' ').toLowerCase());
  }

  function refreshChrome() {
    var all = rows();
    var kept = all.filter(function (row) { return !isRemoved(row); });
    var count = form.querySelector('[data-term-count]');
    var word = form.querySelector('[data-term-count-word]');
    if (count) count.textContent = String(kept.length);
    if (word) word.textContent = kept.length === 1 ? 'term' : 'terms';
    tableWrap.hidden = all.length === 0;
    emptyState.classList.toggle('hidden', all.length !== 0);
    emptyState.classList.toggle('flex', all.length === 0);
    all.forEach(function (row, index) {
      var up = row.querySelector('[data-term-up]');
      var down = row.querySelector('[data-term-down]');
      var first = index === 0;
      var last = index === all.length - 1;
      if (tree) {
        // Only among the terms of the same parent.
        var same = all.filter(function (other) { return parentOf(other) === parentOf(row); });
        first = same[0] === row;
        last = same[same.length - 1] === row;
      }
      if (up) up.disabled = first || searching();
      if (down) down.disabled = last || searching();
      [up, down].forEach(function (button) { if (button) button.classList.toggle('opacity-30', button.disabled); });
    });
    updateSelection();
  }

  /* ---- search ------------------------------------------------------------------------------------------ */

  function searching() { return search && search.value.trim() !== ''; }

  function applySearch() {
    var query = (search.value || '').trim().toLowerCase();
    var shown = 0;
    rows().forEach(function (row) {
      var match = query === '' || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
      row.hidden = !match;
      if (match) shown++;
      var grip = row.querySelector('[data-term-grip]');
      if (grip) grip.classList.toggle('invisible', query !== '');
    });
    noMatch.classList.toggle('hidden', !(query !== '' && shown === 0 && rows().length > 0));
    noMatch.querySelector('[data-term-nomatch-query]').textContent = search.value.trim();
    tableWrap.hidden = rows().length === 0 || (query !== '' && shown === 0);
    refreshChrome();
  }

  /* ---- selecting and removing -------------------------------------------------------------------------- */

  function selectedRows() {
    return rows().filter(function (row) { return !isRemoved(row) && !row.hidden && row.querySelector('[data-term-select]').checked; });
  }

  function updateSelection() {
    var chosen = selectedRows();
    selection.classList.toggle('hidden', chosen.length === 0);
    selection.classList.toggle('flex', chosen.length > 0);
    selection.querySelector('[data-selected-count]').textContent = String(chosen.length);
    var visible = rows().filter(function (row) { return !isRemoved(row) && !row.hidden; });
    selectAll.checked = visible.length > 0 && chosen.length === visible.length;
    selectAll.indeterminate = chosen.length > 0 && chosen.length < visible.length;
  }

  function setRemoved(row, removed) {
    var used = parseInt(row.getAttribute('data-used') || '0', 10);
    var note = row.querySelector('[data-term-removed-note]');
    var name = row.querySelector('[data-term-name]');
    row.querySelectorAll('input').forEach(function (input) {
      if (input.hasAttribute('data-term-select')) { input.checked = false; input.disabled = removed; return; }
      input.disabled = removed;
    });
    if (removed) row.setAttribute('data-removed', ''); else row.removeAttribute('data-removed');
    row.classList.toggle('bg-slate-50', removed);
    name.classList.toggle('line-through', removed);
    row.querySelector('[data-term-actions]').classList.toggle('hidden', removed);
    var restore = row.querySelector('[data-term-restore]');
    restore.classList.toggle('hidden', !removed);
    restore.classList.toggle('inline-flex', removed);
    note.classList.toggle('hidden', !removed);
    var below = tree && removed ? childrenOf(row).length : 0;
    note.textContent = removed
      ? (used > 0 ? 'Removed when you save. ' + used + (used === 1 ? ' entry keeps' : ' entries keep') + ' this term in its file.' : 'Removed when you save.')
        + (below > 0 ? ' Its ' + (below === 1 ? 'sub-term moves' : below + ' sub-terms move') + ' up a level.' : '')
      : '';
    markDirty();
  }

  function removeRow(row) {
    // A term that was never saved has nothing to undo: it just goes.
    if (field(row, 'id').value === '') {
      row.remove();
      markDirty();
      return;
    }
    setRemoved(row, true);
  }

  /* ---- order ------------------------------------------------------------------------------------------- */

  function move(row, delta) {
    if (tree) { moveInTree(row, delta); return; }
    var target = delta < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!target) return;
    if (delta < 0) body.insertBefore(row, target); else body.insertBefore(target, row);
    markDirty();
    refreshChrome();
    var button = row.querySelector(delta < 0 ? '[data-term-up]' : '[data-term-down]');
    if (button && !button.disabled) button.focus(); else row.querySelector('[data-term-edit]').focus();
    announce(primaryName(row) + ' moved ' + (delta < 0 ? 'up' : 'down') + '. Position ' + (rows().indexOf(row) + 1) + ' of ' + rows().length + '.');
  }

  // In a tree a term moves among the terms of its own parent, and takes its children with it.
  function moveInTree(row, delta) {
    var parent = parentOf(row);
    var siblings = rows().filter(function (other) { return parentOf(other) === parent; });
    var at = siblings.indexOf(row);
    var target = siblings[at + delta];
    if (!target) return;
    var block = [row].concat(descendantsOf(row));
    if (delta < 0) {
      block.forEach(function (item) { body.insertBefore(item, target); });
    } else {
      var after = [target].concat(descendantsOf(target));
      var anchor = after[after.length - 1].nextElementSibling;
      block.forEach(function (item) { body.insertBefore(item, anchor); });
    }
    rebuildTree();
    markDirty();
    refreshChrome();
    var button = row.querySelector(delta < 0 ? '[data-term-up]' : '[data-term-down]');
    if (button && !button.disabled) button.focus(); else row.querySelector('[data-term-edit]').focus();
    announce(primaryName(row) + ' moved ' + (delta < 0 ? 'up' : 'down') + '.');
  }

  function sortAlphabetically() {
    var collator = new Intl.Collator(document.documentElement.lang || undefined, { sensitivity: 'base', numeric: true });
    rows().sort(function (a, b) { return collator.compare(primaryName(a), primaryName(b)); }).forEach(function (row) { body.appendChild(row); });
    rebuildTree();
    markDirty();
    refreshChrome();
    announce('Terms sorted from A to Z. Press Save to keep the order.');
  }

  var dragging = null;
  body.addEventListener('pointerdown', function (event) {
    var grip = event.target.closest('[data-term-grip]');
    if (grip) grip.closest('tr').draggable = true;
  });
  body.addEventListener('dragstart', function (event) {
    var row = event.target.closest && event.target.closest('[data-term-row]');
    if (!row || !row.draggable) return;
    dragging = row;
    event.dataTransfer.effectAllowed = 'move';
    try { event.dataTransfer.setData('text/plain', primaryName(row)); } catch (e) { /* some browsers refuse */ }
    setTimeout(function () { row.classList.add('opacity-40'); }, 0);
  });
  body.addEventListener('dragover', function (event) {
    if (!dragging) return;
    var over = event.target.closest('[data-term-row]');
    if (!over || over === dragging) { event.preventDefault(); return; }
    event.preventDefault();
    var box = over.getBoundingClientRect();
    if (event.clientY < box.top + box.height / 2) body.insertBefore(dragging, over); else body.insertBefore(dragging, over.nextElementSibling);
  });
  function endDrag() {
    if (!dragging) return;
    dragging.classList.remove('opacity-40');
    dragging.draggable = false;
    dragging = null;
    rebuildTree();
    markDirty();
    refreshChrome();
  }
  body.addEventListener('dragend', endDrag);
  body.addEventListener('drop', function (event) { if (dragging) event.preventDefault(); });

  /* ---- the dialog -------------------------------------------------------------------------------------- */

  var dlgTitle = dialog.querySelector('[data-dialog-title]');
  var dlgSub = dialog.querySelector('[data-dialog-sub]');
  var dlgApply = dialog.querySelector('[data-dialog-apply]');
  var dlgError = dialog.querySelector('[data-dialog-error]');
  var dlgSlug = dialog.querySelector('[data-dialog-slug]');
  var dlgNote = dialog.querySelector('[data-dialog-slug-note]');
  var dlgOld = dialog.querySelector('[data-dialog-old-slug]');
  var dlgImage = dialog.querySelector('[data-dialog-image]');

  function dialogInput(kindName, lang) { return dialog.querySelector('[data-dialog-' + kindName + '="' + lang + '"]'); }

  // The parents' part of an address, "books/" for a child of Books.
  function parentPrefix() {
    if (!tree || !dlgParent || dlgParent.value === '') return '';
    var above = rowById(dlgParent.value);
    var path = above ? pathOf(above) : '';
    return path === '' ? '' : path + '/';
  }

  function updateSlugNote() {
    var original = editing ? (editing.getAttribute('data-original-path') || '') : '';
    var typed = dlgSlug.value.trim();
    var next = typed !== '' ? parentPrefix() + typed : (editing ? parentPrefix() + field(editing, 'slug').value.trim() : '');
    var show = original !== '' && next !== '' && next !== original;
    dlgNote.classList.toggle('hidden', !show);
    dlgOld.textContent = '/' + kind + '/' + original;
    var lead = dialog.querySelector('[data-dialog-slug-lead]');
    if (lead) lead.textContent = '/' + kind + '/' + parentPrefix();
  }

  // The parents a term can be given: any saved term that is not the term itself or below it.
  function fillParents(row) {
    if (!dlgParent) return;
    var blocked = row ? [row].concat(descendantsOf(row)) : [];
    dlgParent.innerHTML = '';
    var none = document.createElement('option');
    none.value = '';
    none.textContent = 'None (top level)';
    dlgParent.appendChild(none);
    rows().forEach(function (other) {
      if (idOf(other) === '' || isRemoved(other) || blocked.indexOf(other) !== -1) return;
      var option = document.createElement('option');
      option.value = idOf(other);
      var depth = parseInt(other.getAttribute('data-depth') || '0', 10);
      option.textContent = new Array(depth + 1).join('– ') + primaryName(other);
      dlgParent.appendChild(option);
    });
    dlgParent.value = row ? parentOf(row) : '';
  }

  function openDialog(row) {
    editing = row || null;
    slugTouched = !!row;
    dlgError.classList.add('hidden');
    langs().forEach(function (lang) {
      dialogInput('label', lang).value = row ? field(row, 'label', lang).value : '';
      dialogInput('description', lang).value = row ? field(row, 'description', lang).value : '';
    });
    dlgSlug.value = row ? field(row, 'slug').value : '';
    dlgImage.value = row ? field(row, 'image').value : '';
    langs().forEach(function (lang) {
      dialogInput('image-alt', lang).value = row ? field(row, 'image_alt', lang).value : '';
      dialogInput('seo-title', lang).value = row ? field(row, 'seo_title', lang).value : '';
      dialogInput('seo-description', lang).value = row ? field(row, 'seo_description', lang).value : '';
    });
    if (dlgHero) dlgHero.value = row && field(row, 'hero_layout') ? field(row, 'hero_layout').value : '';
    fillParents(row);
    dlgTitle.textContent = row ? 'Edit term' : 'Add term';
    dlgSub.textContent = row ? 'Changes are applied when you press Save on the page.' : 'Give it a name in each language you use.';
    dlgApply.textContent = row ? 'Update term' : 'Add term';
    updateSlugNote();
    dialog.showModal();
    var first = dialogInput('label', defaultLang) || dialog.querySelector('[data-dialog-label]');
    if (first) { first.focus(); first.select(); }
  }

  // A picture is an address in the library ("/uploads/…") or a full link, with no spaces or markup (the server checks it too).
  var PICTURE_PATTERN = /^(\/[^\s"'<>]*|https?:\/\/[^\s"'<>]+)$/;

  function applyDialog() {
    var names = {};
    var descriptions = {};
    var hasName = false;
    langs().forEach(function (lang) {
      names[lang] = dialogInput('label', lang).value.trim();
      descriptions[lang] = dialogInput('description', lang).value.trim();
      if (names[lang] !== '') hasName = true;
    });
    if (!hasName) {
      dlgError.textContent = 'Give the term a name in at least one language.';
      dlgError.classList.remove('hidden');
      var target = dialogInput('label', defaultLang) || dialog.querySelector('[data-dialog-label]');
      if (target) target.focus();
      return;
    }
    var picture = dlgImage.value.trim();
    if (picture !== '' && !PICTURE_PATTERN.test(picture)) {
      dlgError.textContent = 'The picture needs an address that starts with / (from the library) or https://, with no spaces.';
      dlgError.classList.remove('hidden');
      dlgImage.focus();
      return;
    }
    var row = editing;
    var added = false;
    if (!row) {
      row = template.content.querySelector('[data-term-row]').cloneNode(true);
      body.appendChild(row);
      added = true;
    }
    var changed = false;
    langs().forEach(function (lang) {
      var label = field(row, 'label', lang);
      var text = field(row, 'description', lang);
      if (label.value !== names[lang] || text.value !== descriptions[lang]) changed = true;
      label.value = names[lang];
      text.value = descriptions[lang];
    });
    var heroField = field(row, 'hero_layout');
    if (heroField && dlgHero && heroField.value !== dlgHero.value) { heroField.value = dlgHero.value; changed = true; }
    var imageField = field(row, 'image');
    if (imageField.value !== picture) { imageField.value = picture; changed = true; }
    langs().forEach(function (lang) {
      [['image-alt', 'image_alt'], ['seo-title', 'seo_title'], ['seo-description', 'seo_description']].forEach(function (pair) {
        var input = dialogInput(pair[0], lang);
        var target = field(row, pair[1], lang);
        var value = input.value.trim();
        if (target.value !== value) { target.value = value; changed = true; }
      });
    });
    var parentField = field(row, 'parent');
    if (parentField && dlgParent && parentField.value !== dlgParent.value) { parentField.value = dlgParent.value; changed = true; }
    var slugField = field(row, 'slug');
    var typed = dlgSlug.value.trim();
    if (slugField.value !== typed && (added || typed !== '')) changed = true;
    // An emptied address on an existing term means "keep the one it has": the server does that.
    slugField.value = added ? typed : (typed !== '' ? typed : slugField.value);
    if (changed || added) {
      if (!added) row.setAttribute('data-edited', '');
      markDirty();
    }
    rebuildTree();
    rows().forEach(refreshRow); // a changed address changes the address of everything below it
    dialog.close();
    applySearch();
    if (added) {
      if (search.value.trim() !== '') { search.value = ''; applySearch(); }
      row.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' });
      if (!reducedMotion) {
        row.classList.add('bg-blue-50');
        setTimeout(function () { row.classList.remove('bg-blue-50'); }, 1600);
      }
      announce('Term added. Press Save to keep it.');
    } else if (changed) {
      announce('Term updated. Press Save to keep the change.');
    }
    var focusTarget = row.querySelector('[data-term-edit]');
    if (focusTarget) focusTarget.focus();
  }

  dialogForm.addEventListener('submit', function (event) { event.preventDefault(); applyDialog(); });
  dialog.querySelectorAll('[data-dialog-close]').forEach(function (button) { button.addEventListener('click', function () { dialog.close(); }); });
  // A click on the dimmed area (the dialog element itself) closes it.
  dialog.addEventListener('mousedown', function (event) { if (event.target === dialog) dialog.close(); });
  dlgSlug.addEventListener('input', function () { slugTouched = true; updateSlugNote(); });
  if (dlgParent) dlgParent.addEventListener('change', updateSlugNote);
  dialog.addEventListener('close', function () { editing = null; });

  /* ---- wiring ------------------------------------------------------------------------------------------ */

  form.addEventListener('click', function (event) {
    var button = event.target.closest('button');
    if (!button) return;
    var row = button.closest('[data-term-row]');
    if (button.hasAttribute('data-term-add')) { event.preventDefault(); openDialog(null); }
    else if (button.hasAttribute('data-term-sort')) sortAlphabetically();
    else if (button.hasAttribute('data-term-remove-selected')) {
      var chosen = selectedRows();
      chosen.forEach(removeRow);
      refreshChrome();
      announce(chosen.length + (chosen.length === 1 ? ' term' : ' terms') + ' marked for removal. Press Save to apply, or Undo to keep them.');
    }
    else if (button.hasAttribute('data-term-clear-selection')) {
      rows().forEach(function (item) { item.querySelector('[data-term-select]').checked = false; });
      updateSelection();
    }
    else if (row && button.hasAttribute('data-term-edit')) openDialog(row);
    else if (row && button.hasAttribute('data-term-remove')) {
      var next = row.nextElementSibling;
      removeRow(row);
      refreshChrome();
      announce('Term marked for removal. Press Save to apply, or Undo to keep it.');
      var undo = document.body.contains(row) ? row.querySelector('[data-term-restore]') : null;
      if (undo) undo.focus(); else if (next) { var edit = next.querySelector('[data-term-edit]'); if (edit) edit.focus(); }
    }
    else if (row && button.hasAttribute('data-term-restore')) { setRemoved(row, false); refreshChrome(); row.querySelector('[data-term-edit]').focus(); announce('Term kept.'); }
    else if (row && button.hasAttribute('data-term-up')) move(row, -1);
    else if (row && button.hasAttribute('data-term-down')) move(row, 1);
  });

  form.addEventListener('change', function (event) {
    if (event.target.hasAttribute('data-term-select-all')) {
      rows().forEach(function (row) { if (!isRemoved(row) && !row.hidden) row.querySelector('[data-term-select]').checked = event.target.checked; });
      updateSelection();
    } else if (event.target.hasAttribute('data-term-select')) updateSelection();
    else if (event.target.name) markDirty();
  });
  form.addEventListener('input', function (event) {
    if (event.target === search) applySearch();
    else if (event.target.name && !event.target.closest('[data-term-row]')) markDirty();
  });
  // Pressing Enter in the search box must not submit the page.
  search.addEventListener('keydown', function (event) { if (event.key === 'Enter') event.preventDefault(); });

  form.addEventListener('submit', function () { submitting = true; });
  window.addEventListener('beforeunload', function (event) {
    if (!dirty || submitting) return;
    event.preventDefault();
    event.returnValue = '';
  });

  // The server drew the rows (in tree order), so they are only counted and given their arrow buttons' state here.
  refreshChrome();
})();
