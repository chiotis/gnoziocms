/*
 * Theme > Typography > Fonts: the person's own font families.
 *
 * The files are uploaded one at a time to the screen's endpoint, which stores each in custom/assets/fonts/ and answers with the
 * entry to keep (family, file, weight, style, unicode-range, scripts covered, fallback measures). The list lives here as `state` and is
 * written back as hidden inputs named theme_settings[design][fonts][i][field], so it is saved with the rest of the Branding form and
 * followed by the live preview. Each file has an editable weight and style, chips for the scripts it covers (read from the font on the
 * server, or checked here in the browser when the server could not open the file), and a Latin and a Greek sample drawn with that file.
 */
(function () {
  'use strict';

  var GROUPS = [['latin', 'Latin'], ['greek', 'Greek'], ['greek_ext', 'Greek Extended'], ['cyrillic', 'Cyrillic'], ['symbols', 'Symbols']];
  var PROBES = { latin: 'gWao', greek: 'αβγψΩ', greek_ext: 'ἀἁᾳῶ', cyrillic: 'жщДЯ', symbols: '€•→©' };
  var WEIGHTS = [[100, 'Thin'], [200, 'Extra light'], [300, 'Light'], [400, 'Regular'], [500, 'Medium'], [600, 'Semibold'], [700, 'Bold'], [800, 'Extra bold'], [900, 'Black']];
  var LATIN_SAMPLE = 'The quick brown fox jumps over the lazy dog 0123456789 €';
  var GREEK_SAMPLE = 'Η γρήγορη καφέ αλεπού πηδά πάνω από το τεμπέλικο σκυλί ΑΒΓΔΕΖΗΘ';

  var root = document.querySelector('[data-bf]');
  if (!root) { return; }
  var form = root.closest('form');
  var prefix = root.getAttribute('data-bf-prefix') || 'theme_settings[design][';
  var endpoint = root.getAttribute('data-bf-endpoint');
  var legacy = root.getAttribute('data-bf-legacy') || '';
  var list = root.querySelector('[data-bf-list]');
  var notes = root.querySelector('[data-bf-notes]');
  var inputs = root.querySelector('[data-bf-inputs]');
  var status = root.querySelector('[data-bf-status]');
  var picker = root.querySelector('[data-bf-file]');
  var nameBox = root.querySelector('[data-bf-family]');
  var convert = root.querySelector('[data-bf-convert]');
  var rolesBox = root.querySelector('[data-bf-roles]');
  var state = { files: [], base: '', greek_site: false };
  var serial = 0;
  try { state = JSON.parse(root.getAttribute('data-bf-state') || '{}'); } catch (e) { /* an empty list */ }
  state.files = (state.files || []).map(function (file) { file._id = ++serial; return file; });
  var faces = document.createElement('style');
  document.head.appendChild(faces);

  function el(tag, attributes, text) {
    var node = document.createElement(tag);
    Object.keys(attributes || {}).forEach(function (key) { node.setAttribute(key, attributes[key]); });
    if (text != null) { node.textContent = text; }
    return node;
  }
  function field(name) { return form.querySelector('[name="' + prefix + name + ']"]'); }
  function roleSelect(role) { return field(role + '_family'); }
  function fontSelect(role) { return field(role + '_font'); }
  function usesOwn(role) { var select = fontSelect(role); return !!select && select.value === 'custom'; }
  function families() {
    var names = [];
    state.files.forEach(function (file) { if (names.indexOf(file.family) === -1) { names.push(file.family); } });
    return names;
  }
  function filesOf(name) { return state.files.filter(function (file) { return file.family === name; }); }
  function coverOf(file) { return file.cover === '' ? null : (file.cover === 'none' ? [] : file.cover.split(',')); }
  function weights(file) { var parts = String(file.weight).split(' '); return [parseInt(parts[0], 10) || 400, parseInt(parts[parts.length - 1], 10) || 400]; }
  function describeSize(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB'; }
  function say(text) { status.textContent = text || ''; }
  function cleanName(text) {
    try { text = text.replace(/[^\p{L}\p{N} _.&()-]+/gu, ''); } catch (e) { text = text.replace(/[^A-Za-z0-9 _.&()-]+/g, ''); }
    return text.replace(/\s+/g, ' ').trim().slice(0, 40);
  }

  // ---- what to tell the person: a family without Greek on a site with Greek content, a family nobody has checked
  function warnings() {
    var out = [];
    if (!state.greek_site) { return out; }
    [['heading', 'Headings'], ['body', 'Text']].forEach(function (role) {
      if (!usesOwn(role[0])) { return; }
      var select = roleSelect(role[0]);
      var name = select && select.value ? select.value : (families().length === 1 ? families()[0] : '');
      if (!name) { return; }
      var covers = filesOf(name).map(coverOf);
      var hasGreek = covers.some(function (cover) { return cover && cover.indexOf('greek') !== -1; });
      var allKnown = covers.every(function (cover) { return cover !== null; });
      if (hasGreek) { return; }
      out.push(allKnown
        ? { level: 'warning', text: role[1] + ' use “' + name + '”, which has no Greek letters. Greek text on this site will be drawn in a system font instead.' }
        : { level: 'notice', text: 'It is not known whether “' + name + '” has Greek letters (' + role[1] + '). Check the chips of its files below.' });
    });
    return out;
  }

  // ---- drawing
  function drawFaces() {
    var css = '';
    state.files.forEach(function (file) {
      if (!file.exists) { return; }
      var range = weights(file);
      var format = file.format === 'ttf' ? 'truetype' : (file.format === 'otf' ? 'opentype' : file.format);
      css += '@font-face{font-family:"bf-' + file._id + '";font-style:' + (file.style === 'italic' ? 'italic' : 'normal') + ';font-weight:' + range[0] + ' ' + range[1]
        + ';font-display:swap;src:url("' + encodeURI(file.url).replace(/"/g, '%22') + '") format("' + format + '")}';
    });
    faces.textContent = css;
  }

  function chips(file, editable) {
    var cover = coverOf(file);
    var box = el('span', { 'class': 'bf-chips', role: editable ? 'group' : 'list', 'aria-label': 'Scripts covered' });
    GROUPS.forEach(function (group) {
      var on = cover !== null && cover.indexOf(group[0]) !== -1;
      var tag = editable ? 'button' : 'span';
      var chip = el(tag, { 'class': 'bf-chip' + (cover === null ? ' is-unknown' : (on ? ' is-on' : '')), 'data-k': editable ? file._id + ':cover:' + group[0] : null });
      if (editable) {
        chip.setAttribute('type', 'button');
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        chip.title = cover === null ? 'Not checked yet: click to say that this file covers ' + group[1] : 'Click to correct whether this file covers ' + group[1];
        chip.addEventListener('click', function () { toggleCover(file, group[0]); });
      } else {
        chip.setAttribute('role', 'listitem');
        if (!on && group[0] === 'greek' && state.greek_site && cover !== null) { chip.className += ' is-warn'; }
      }
      chip.textContent = group[1] + (cover === null ? ' ?' : (on ? ' ✓' : ' ✗'));
      chip.setAttribute('aria-label', group[1] + ': ' + (cover === null ? 'not checked' : (on ? 'covered' : 'not covered')));
      box.appendChild(chip);
    });
    return box;
  }

  function select(options, current, label, key, onChange) {
    var node = el('select', { 'class': 'lc-input bf-sel', 'aria-label': label, 'data-k': key });
    options.forEach(function (option) {
      var item = el('option', { value: option[0] }, option[1]);
      if (String(option[0]) === String(current)) { item.selected = true; }
      node.appendChild(item);
    });
    node.addEventListener('change', function () { onChange(node.value); });
    return node;
  }

  function weightControls(file) {
    var range = weights(file);
    var options = WEIGHTS.map(function (w) { return [w[0], w[0] + ' ' + w[1]]; });
    var upTo = [['', 'single weight']].concat(WEIGHTS.filter(function (w) { return w[0] > range[0]; }).map(function (w) { return [w[0], 'to ' + w[0] + ' ' + w[1]]; }));
    var box = el('span', { 'class': 'bf-weight' });
    box.appendChild(select(options, range[0], file.family + ', ' + file.file + ': weight', file._id + ':weight', function (value) { setWeight(file, parseInt(value, 10), range[1] > parseInt(value, 10) ? range[1] : 0); }));
    box.appendChild(select(upTo, range[1] > range[0] ? range[1] : '', file.family + ', ' + file.file + ': highest weight of a variable font', file._id + ':weight2', function (value) { setWeight(file, range[0], value === '' ? 0 : parseInt(value, 10)); }));
    return box;
  }

  function sample(file, text, weight, lang) {
    var line = el('p', { 'class': 'bf-sample', lang: lang }, text);
    line.style.fontFamily = '"bf-' + file._id + '", system-ui, sans-serif';
    line.style.fontWeight = String(weight);
    line.style.fontStyle = file.style === 'italic' ? 'italic' : 'normal';
    return line;
  }

  function fileRow(file) {
    var row = el('li', { 'class': 'bf-file' });
    var head = el('div', { 'class': 'bf-file-head' });
    var name = el('span', { 'class': 'bf-path', title: file.file }, file.file.replace(/^fonts\//, ''));
    head.appendChild(name);
    head.appendChild(el('span', { 'class': 'bf-meta' }, file.format.toUpperCase() + (file.exists ? ' · ' + describeSize(file.size) : '')));
    if (!file.exists) { head.appendChild(el('strong', { 'class': 'bf-missing' }, 'File missing in custom/assets')); }
    var remove = el('button', { type: 'button', 'class': 'bf-x', 'aria-label': 'Remove ' + file.file }, '×');
    remove.addEventListener('click', function () { state.files = state.files.filter(function (other) { return other !== file; }); commit('Removed ' + file.file + '. Save to keep this.'); });
    head.appendChild(remove);
    row.appendChild(head);

    var tags = el('div', { 'class': 'bf-tags' });
    tags.appendChild(weightControls(file));
    tags.appendChild(select([['normal', 'Normal'], ['italic', 'Italic']], file.style, file.file + ': style', file._id + ':style', function (value) { file.style = value === 'italic' ? 'italic' : 'normal'; commit(); }));
    tags.appendChild(chips(file, true));
    row.appendChild(tags);
    (file._warn || []).forEach(function (text) { row.appendChild(el('p', { 'class': 'bf-warn' }, text)); });

    if (file.exists) {
      var range = weights(file);
      var shown = range[1] > range[0] ? [range[0], range[1]] : [range[0]];
      var samples = el('div', { 'class': 'bf-samples' });
      shown.forEach(function (weight) {
        if (shown.length > 1) { samples.appendChild(el('small', null, 'Weight ' + weight)); }
        samples.appendChild(sample(file, LATIN_SAMPLE, weight, 'en'));
        samples.appendChild(sample(file, GREEK_SAMPLE, weight, 'el'));
      });
      row.appendChild(samples);
    }
    return row;
  }

  function familyCard(name) {
    var files = filesOf(name);
    var card = el('section', { 'class': 'bf-family', 'aria-label': name });
    var head = el('div', { 'class': 'bf-family-head' });
    var rename = el('input', { type: 'text', 'class': 'lc-input bf-title', value: name, maxlength: '40', 'aria-label': 'Family name', 'data-k': 'family:' + name });
    rename.addEventListener('change', function () {
      var next = cleanName(rename.value);
      if (next === '' || next === name) { rename.value = name; return; }
      files.forEach(function (file) { file.family = next; });
      [roleSelect('heading'), roleSelect('body')].forEach(function (select) { if (select && select.value === name) { select.setAttribute('data-bf-set', next); } });
      commit();
    });
    head.appendChild(rename);
    ['heading', 'body'].forEach(function (role) {
      var select = roleSelect(role);
      var current = select && (select.value === name || (select.value === '' && families().length === 1));
      if (current && usesOwn(role)) { head.appendChild(el('span', { 'class': 'bf-used' }, role === 'heading' ? 'Headings' : 'Text')); }
    });
    var all = { cover: unionCover(files) };
    head.appendChild(chips(all, false));
    var removeFamily = el('button', { type: 'button', 'class': 'bp-reset' }, 'Remove family');
    removeFamily.addEventListener('click', function () { state.files = state.files.filter(function (file) { return file.family !== name; }); commit('Removed the family “' + name + '”. Save to keep this.'); });
    head.appendChild(removeFamily);
    card.appendChild(head);
    var rows = el('ul', { 'class': 'bf-files' });
    files.forEach(function (file) { rows.appendChild(fileRow(file)); });
    card.appendChild(rows);
    return card;
  }

  function unionCover(files) {
    var known = files.map(coverOf);
    if (known.every(function (cover) { return cover === null; })) { return ''; }
    var out = [];
    known.forEach(function (cover) { (cover || []).forEach(function (key) { if (out.indexOf(key) === -1) { out.push(key); } }); });
    return out.length ? out.join(',') : 'none';
  }

  function drawRoles() {
    var names = families();
    [['heading', 'Headings'], ['body', 'Text']].forEach(function (role) {
      var select = roleSelect(role[0]);
      var box = root.querySelector('[data-bf-role="' + role[0] + '"]');
      if (!select || !box) { return; }
      var wanted = select.getAttribute('data-bf-set');
      select.removeAttribute('data-bf-set');
      var current = wanted !== null ? wanted : select.value;
      select.textContent = '';
      select.appendChild(el('option', { value: '' }, names.length === 0 ? 'No family yet' : (names.length === 1 ? 'Automatic (' + names[0] + ')' : 'Choose a family')));
      names.forEach(function (name) { select.appendChild(el('option', { value: name }, name)); });
      select.value = names.indexOf(current) !== -1 ? current : '';
      // A role that uses "Your own font" always has a family: with none chosen it takes the first, so the page and the preview agree.
      if (select.value === '' && usesOwn(role[0]) && names.length) { select.value = names[0]; }
      box.hidden = !usesOwn(role[0]) || names.length === 0;
    });
    rolesBox.hidden = rolesBox.querySelectorAll('[data-bf-role]:not([hidden])').length === 0;
  }

  function drawInputs() {
    inputs.textContent = '';
    // The earlier single setting is always sent (empty or as it is), so saving never loses it.
    if (!form.querySelector('[name="' + prefix + 'font_file]"]')) { inputs.appendChild(el('input', { type: 'hidden', name: prefix + 'font_file]', value: '' })); }
    if (state.files.length === 0) {
      inputs.appendChild(el('input', { type: 'hidden', name: prefix + 'fonts]', value: '' }));
      return;
    }
    state.files.forEach(function (file, index) {
      ['family', 'file', 'weight', 'style', 'range', 'cover', 'adjust', 'v'].forEach(function (key) {
        inputs.appendChild(el('input', { type: 'hidden', name: prefix + 'fonts][' + index + '][' + key + ']', value: file[key] == null ? '' : String(file[key]) }));
      });
    });
  }

  function draw() {
    var focus = document.activeElement && document.activeElement.getAttribute ? document.activeElement.getAttribute('data-k') : null;
    drawFaces();
    list.textContent = '';
    var names = families();
    if (names.length === 0) { list.appendChild(el('p', { 'class': 'bf-empty' }, 'No font files yet.')); }
    names.forEach(function (name) { list.appendChild(familyCard(name)); });
    drawRoles();
    drawInputs();
    notes.textContent = '';
    warnings().forEach(function (note) {
      notes.appendChild(el('p', { 'class': 'bf-note bf-note-' + note.level }, (note.level === 'warning' ? 'Warning: ' : 'Note: ') + note.text));
    });
    var showConvert = legacy !== '' && !state.files.some(function (file) { return file.file === legacy.replace(/^\/?_custom\//, ''); });
    convert.hidden = !showConvert;
    if (focus) {
      var again = root.querySelector('[data-k="' + focus.replace(/"/g, '\\"') + '"]');
      if (again) { again.focus(); }
    }
  }

  function changed() {
    // The preview and the form's other listeners follow a change of any named field of the form.
    var target = roleSelect('heading') || roleSelect('body');
    if (target) { target.dispatchEvent(new Event('input', { bubbles: true })); }
  }

  function commit(message) {
    draw();
    if (message) { say(message); }
    changed();
  }

  function setWeight(file, low, high) {
    file.weight = high && high > low ? low + ' ' + high : String(low);
    commit();
  }

  function toggleCover(file, key) {
    var cover = coverOf(file);
    if (cover === null) { cover = []; }
    var at = cover.indexOf(key);
    if (at === -1) { cover.push(key); } else { cover.splice(at, 1); }
    var order = GROUPS.map(function (group) { return group[0]; });
    cover.sort(function (a, b) { return order.indexOf(a) - order.indexOf(b); });
    file.cover = cover.length ? cover.join(',') : 'none';
    commit();
  }

  // ---- checking what an unreadable file covers, in the browser: a character the font lacks is drawn by the fallback, and two
  // different fallbacks draw it differently; a character the font has is drawn the same whatever the fallback.
  function checkInBrowser(file) {
    if (!file.exists || file.cover !== '' || !window.FontFace || !document.fonts) { return; }
    var face = new FontFace('bf-probe-' + file._id, 'url("' + encodeURI(file.url) + '")');
    face.load().then(function (loaded) {
      document.fonts.add(loaded);
      var canvas = document.createElement('canvas');
      canvas.width = 60; canvas.height = 60;
      var context = canvas.getContext('2d');
      if (!context) { return; }
      function draw(character, fallback) {
        context.clearRect(0, 0, 60, 60);
        context.font = '40px "bf-probe-' + file._id + '", ' + fallback;
        context.fillText(character, 4, 44);
        return canvas.toDataURL();
      }
      // A browser that blanks or randomises canvas pixels would make every character look "covered" or none of them: a control
      // drawn without the probe font must differ between two fallbacks, or the answer is not trusted and the chips stay unchecked.
      context.clearRect(0, 0, 60, 60); context.font = '40px monospace'; context.fillText('W', 4, 44); var controlA = canvas.toDataURL();
      context.clearRect(0, 0, 60, 60); context.font = '40px serif'; context.fillText('W', 4, 44); var controlB = canvas.toDataURL();
      if (controlA === controlB) { return; }
      var found = [];
      GROUPS.forEach(function (group) {
        var all = true;
        PROBES[group[0]].split('').forEach(function (character) {
          if (draw(character, 'monospace') !== draw(character, 'serif') || draw(character, 'serif') !== draw(character, 'sans-serif')) { all = false; }
        });
        if (all) { found.push(group[0]); }
      });
      // A file that shows none of the groups, not even Latin, was most likely not drawn at all: that is "cannot check", not "no Greek".
      if (!found.length) { return; }
      if (file.cover === '' && state.files.indexOf(file) !== -1) {
        file.cover = found.length ? found.join(',') : 'none';
        file._warn = (file._warn || []).filter(function (text) { return text.indexOf('browser') === -1; });
        file._warn.push('Which scripts this file covers was checked in your browser. Correct the chips if it is wrong.');
        commit();
      }
    }).catch(function () { /* the chips stay as "not checked" */ });
  }

  // ---- adding files
  function post(data) {
    var token = form.querySelector('input[name="_csrf"]');
    if (token) { data.append('_csrf', token.value); }
    return fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'The server did not answer as expected (' + response.status + '). Reload the page and try again.' }; }); });
  }

  function addEntry(answer) {
    var entry = answer.entry;
    var info = answer.info || {};
    entry._id = ++serial;
    entry.exists = true;
    entry.size = info.size || 0;
    entry.format = (entry.file.split('.').pop() || '').toLowerCase();
    entry.url = (state.base || '') + '/_custom/' + entry.file + (entry.v ? '?v=' + entry.v : '');
    entry._warn = answer.warnings || [];
    // The same file twice is one file.
    state.files = state.files.filter(function (file) { return file.file !== entry.file; });
    state.files.push(entry);
    return entry;
  }

  function upload(files) {
    var queue = Array.prototype.slice.call(files);
    var family = cleanName(nameBox.value);
    var done = 0;
    var problems = [];
    function next() {
      var file = queue.shift();
      if (!file) {
        say((done ? done + (done === 1 ? ' font file added' : ' font files added') + '. Save to keep ' + (done === 1 ? 'it' : 'them') + '.' : '') + (problems.length ? ' ' + problems.join(' ') : ''));
        picker.value = '';
        return;
      }
      say('Uploading ' + file.name + '…');
      var data = new FormData();
      data.append('action', 'upload');
      data.append('font', file);
      data.append('family', family);
      post(data).then(function (answer) {
        if (answer && answer.ok && answer.entry) {
          var entry = addEntry(answer);
          // The next files of this batch join the family of the first, so a family is uploaded in one go.
          if (!family && queue.length) { family = entry.family; }
          done++;
          commit();
          checkInBrowser(entry);
        } else {
          problems.push(file.name + ': ' + ((answer && answer.error) || 'it could not be uploaded.'));
        }
        next();
      }).catch(function () { problems.push(file.name + ': the upload failed.'); next(); });
    }
    next();
  }

  picker.addEventListener('change', function () { if (picker.files.length) { upload(picker.files); } });
  convert.addEventListener('click', function () {
    var data = new FormData();
    data.append('action', 'convert');
    data.append('path', legacy);
    data.append('family', nameBox.value || 'My font');
    post(data).then(function (answer) {
      if (!answer || !answer.ok || !answer.entry) { say((answer && answer.error) || 'The file could not be read.'); return; }
      var entry = addEntry(answer);
      // Roles that used the single file keep their look: they now use the new family.
      ['heading', 'body'].forEach(function (role) {
        var select = roleSelect(role);
        if (select && usesOwn(role)) { select.setAttribute('data-bf-set', entry.family); }
      });
      var old = form.querySelector('[name="' + prefix + 'font_file]"]');
      if (old) { old.value = ''; }
      legacy = '';
      commit('The file is now the family “' + entry.family + '”. Save to keep this.');
      checkInBrowser(entry);
    });
  });

  // Choosing "Your own font" for a role shows its family; with a family already there, the first one is chosen for it.
  ['heading', 'body'].forEach(function (role) {
    var font = fontSelect(role);
    var family = roleSelect(role);
    if (font) {
      font.addEventListener('change', function () {
        if (font.value === 'custom' && family && family.value === '' && families().length > 1) { family.value = families()[0]; }
        draw();
      });
    }
    if (family) { family.addEventListener('change', draw); }
  });

  draw();
  state.files.forEach(checkInBrowser);
})();
