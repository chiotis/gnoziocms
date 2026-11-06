/*
 * AI pictures in the admin (docs/ai-publishing-plan.md): the editor's "Generate with AI" panel next to the main image, and the test
 * pictures on Manage > AI publishing. A request is queued, then its state is asked for until it is done; asking also works the job
 * when it is due, so the picture comes even where cron runs rarely. Every wait has a deadline.
 */
(function () {
  'use strict';

  var POLL_MS = 2500;
  var DEADLINE_MS = 6 * 60 * 1000;

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function post(endpoint, fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    body.append('_csrf', csrf());
    return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The site did not answer as expected (' + r.status + ').' }; }); });
  }

  function fileUrl(endpoint, job, i) {
    return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'job=' + encodeURIComponent(job) + '&file=' + i;
  }

  /* Asks for a job's state until it is done or failed, or the deadline passes. onState gets every answer. */
  function watch(endpoint, job, onState) {
    var started = Date.now();
    return new Promise(function (resolve) {
      function ask() {
        if (Date.now() - started > DEADLINE_MS) {
          var late = { status: 'timeout', error: 'Still not ready after six minutes. It goes on in the background: look again later on Manage › AI publishing.', variants: 0 };
          onState(late);
          resolve(late);
          return;
        }
        fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'job=' + encodeURIComponent(job), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (state) {
            onState(state);
            if (state.status === 'done' || state.status === 'failed' || state.status === 'cancelled' || state.status === 'missing') {
              resolve(state);
            } else {
              setTimeout(ask, Math.max(POLL_MS, (state.wait || 0) * 1000));
            }
          })
          .catch(function () { setTimeout(ask, POLL_MS * 2); });
      }
      ask();
    });
  }

  // ---- the editor's panel
  function initEditor(panel) {
    var endpoint = panel.getAttribute('data-ai-endpoint');
    var subject = panel.querySelector('[data-ai-subject]');
    var avoid = panel.querySelector('[data-ai-avoid]');
    var count = panel.querySelector('[data-ai-count]');
    var button = panel.querySelector('[data-ai-generate]');
    var message = panel.querySelector('[data-ai-message]');
    var results = panel.querySelector('[data-ai-results]');
    var form = panel.closest('form');

    function field(selector) {
      var el = form ? form.querySelector(selector) : null;
      return el ? String(el.value || '').trim() : '';
    }
    function entry() {
      var type = field('input[name="type"]');
      var slug = field('[data-address-value]') || field('input[name="slug"]');
      var lang = field('select[name="lang"]') || field('input[name="lang"]');
      return type && slug && lang ? type + '/' + slug + '/' + lang : (panel.getAttribute('data-ai-entry') || '');
    }
    function prefill() {
      if (subject.value.trim() !== '') return;
      var title = field('input[name="title"]');
      var excerpt = field('textarea[name="excerpt"]');
      // A title that ends a sentence already (. ! ? or the Greek ;) gets no full stop of its own.
      var joined = title && excerpt ? title + (/[.!?;…]$/.test(title) ? ' ' : '. ') + excerpt : (title || excerpt);
      subject.value = joined.replace(/\s+/g, ' ').slice(0, 600);
    }
    panel.addEventListener('toggle', function () { if (panel.open) prefill(); });

    function say(text, bad) {
      message.textContent = text;
      message.className = 'text-xs ' + (bad ? 'text-red-700' : 'text-slate-500');
    }

    function use(job, index, img) {
      say('Keeping it in the Media library…');
      post(endpoint, { action: 'pick', job: job, index: index }).then(function (r) {
        if (!r.ok) { say(r.error || 'It could not be kept.', true); return; }
        var input = document.getElementById('main-image-input');
        if (input) {
          input.value = r.path;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var preview = document.getElementById('main-image-preview');
        var previewImg = document.getElementById('main-image-preview-img');
        if (previewImg) previewImg.src = r.path;
        if (preview) preview.classList.remove('hidden');
        results.querySelectorAll('[data-ai-choice]').forEach(function (el) { el.classList.remove('ring-2', 'ring-blue-600'); });
        if (img) img.closest('[data-ai-choice]').classList.add('ring-2', 'ring-blue-600');
        say('Chosen. Save the entry to keep it as the main image.');
      });
    }

    button.addEventListener('click', function () {
      prefill();
      if (subject.value.trim() === '') { say('Say what the picture shows.', true); subject.focus(); return; }
      button.disabled = true;
      results.innerHTML = '';
      say('Sending…');
      post(endpoint, { action: 'start', subject: subject.value, avoid: avoid.value, variants: count.value, entry: entry() }).then(function (r) {
        if (!r.ok) { say(r.error || 'It could not be started.', true); button.disabled = false; return; }
        var job = r.job;
        var started = Date.now();
        watch(endpoint, job, function (state) {
          var seconds = Math.round((Date.now() - started) / 1000);
          if (state.status === 'queued' || state.status === 'running') say('Making the pictures… ' + seconds + ' s (usually 15–60 s)');
        }).then(function (state) {
          button.disabled = false;
          if (state.status !== 'done') { say(state.error || 'No picture was made.', true); return; }
          say('Choose one, or change the subject and generate again.');
          for (var i = 0; i < state.variants; i++) {
            var box = document.createElement('figure');
            box.setAttribute('data-ai-choice', '');
            box.className = 'overflow-hidden rounded border border-slate-200 bg-white';
            var img = document.createElement('img');
            img.src = fileUrl(endpoint, job, i);
            img.alt = 'Variant ' + (i + 1);
            img.className = 'block w-full';
            var pick = document.createElement('button');
            pick.type = 'button';
            pick.textContent = 'Use this';
            pick.className = 'w-full border-t border-slate-200 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-50';
            (function (index, image) { pick.addEventListener('click', function () { use(job, index, image); }); })(i, img);
            box.appendChild(img);
            box.appendChild(pick);
            results.appendChild(box);
          }
        });
      });
    });
  }

  // ---- test pictures on Manage > AI publishing
  function initWatch(item) {
    var status = item.getAttribute('data-ai-status');
    if (status !== 'queued' && status !== 'running') return;
    var endpoint = item.getAttribute('data-ai-endpoint');
    var job = item.getAttribute('data-ai-watch');
    var badge = item.querySelector('[data-ai-badge]');
    var error = item.querySelector('[data-ai-error]');
    var variants = item.querySelector('[data-ai-variants]');
    watch(endpoint, job, function (state) {
      if (badge) badge.textContent = state.status === 'done' ? 'done' : (state.status === 'failed' ? 'failed' : 'working…');
    }).then(function (state) {
      if (state.status === 'done') {
        for (var i = 0; i < state.variants; i++) {
          var img = document.createElement('img');
          img.src = fileUrl(endpoint, job, i);
          img.alt = 'Test picture';
          img.className = 'w-full rounded border border-slate-200';
          variants.appendChild(img);
        }
      } else if (error) {
        error.textContent = state.error || 'No picture was made.';
      }
    });
  }

  // ---- the content list: Bulk action > Generate main images
  function initBulkApply(button) {
    var form = button.closest('form');
    var select = form.querySelector('select[name="bulk_action"]');
    var replaceLabel = form.querySelector('[data-ai-bulk-replace]');
    var replace = replaceLabel ? replaceLabel.querySelector('input') : null;
    var plain = { text: button.getAttribute('data-confirm'), label: button.getAttribute('data-confirm-button') };
    var cost = parseFloat(button.getAttribute('data-ai-picture-cost') || '');
    var total = parseInt(button.getAttribute('data-ai-total') || '0', 10);
    var blocked = button.getAttribute('data-ai-blocked') || '';

    function chosen() { return select && select.value === 'generate_images'; }
    function showReplace() {
      if (!replaceLabel) return;
      replaceLabel.classList.toggle('hidden', !chosen());
      replaceLabel.classList.toggle('inline-flex', chosen());
    }
    // Set just before the form's own confirmation reads it (a click comes before the submit).
    function update() {
      if (!chosen()) {
        button.setAttribute('data-confirm', plain.text);
        button.setAttribute('data-confirm-button', plain.label);
        return;
      }
      var all = form.querySelector('[data-select-rest-input]');
      var everyPage = all && all.value === '1';
      var ticked = form.querySelectorAll('input[name="selected[]"]:checked');
      var count = everyPage ? total : ticked.length;
      var withImage = everyPage ? 0 : form.querySelectorAll('input[name="selected[]"][data-has-image]:checked').length;
      var making = replace && replace.checked ? count : count - withImage;
      var text;
      if (blocked) {
        text = blocked;
      } else if (count === 0) {
        text = 'Select the entries to make pictures for first.';
      } else {
        text = 'Make ' + (everyPage && !(replace && replace.checked) ? 'up to ' : '') + making + ' AI main image' + (making === 1 ? '' : 's') + '?';
        if (withImage && !(replace && replace.checked)) text += ' ' + withImage + ' of the selected already ' + (withImage === 1 ? 'has one and is' : 'have one and are') + ' left as they are (tick "Replace existing images" to make new ones).';
        if (everyPage && !(replace && replace.checked)) text += ' Entries that have a main image are left as they are.';
        if (replace && replace.checked) text += ' Existing main images are replaced; the old pictures stay in the Media library and in the history.';
        text += isNaN(cost) ? ' The cost is shown once a priced picture has been made (Manage > AI publishing > Usage).' : ' About $' + (making * cost).toFixed(2) + ' at the average cost of recent pictures.';
        text += ' Each picture is made from the entry\'s title and excerpt, one at a time in the background.';
      }
      button.setAttribute('data-confirm', text);
      button.setAttribute('data-confirm-button', 'Generate');
    }
    if (select) select.addEventListener('change', showReplace);
    button.addEventListener('click', update);
    showReplace();
  }

  function initBulkProgress(box) {
    if (parseInt(box.getAttribute('data-ai-live') || '0', 10) === 0) return;
    var endpoint = box.getAttribute('data-ai-endpoint');
    var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'bulk=' + encodeURIComponent(box.getAttribute('data-ai-type')) + '&lang=' + encodeURIComponent(box.getAttribute('data-ai-lang'));
    var line = box.querySelector('[data-ai-bulk-line]');
    var started = Date.now();
    var BULK_DEADLINE_MS = 45 * 60 * 1000;
    function ask() {
      if (Date.now() - started > BULK_DEADLINE_MS) {
        line.textContent = 'Still making AI pictures. Reload this page to see how far it is.';
        return;
      }
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (p) {
          var left = (p.waiting || 0) + (p.working || 0);
          line.textContent = (left > 0 ? 'Making AI pictures: ' + left + ' left' : 'AI pictures finished') + (p.done ? ' · ' + p.done + ' done' : '') + (p.failed ? ' · ' + p.failed + ' failed' : '');
          if (left > 0) {
            setTimeout(ask, POLL_MS);
          } else {
            // Every picture is in: the list again, with each row's main image and chip.
            setTimeout(function () { window.location.reload(); }, 800);
          }
        })
        .catch(function () { setTimeout(ask, POLL_MS * 4); });
    }
    ask();
  }

  // ---- the Runs tab while posts or pictures wait: each request works the next one, then the page shows the result
  function initRunsLive(box) {
    var endpoint = box.getAttribute('data-ai-endpoint');
    var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'work=1';
    var line = box.querySelector('[data-ai-runs-line]');
    var started = Date.now();
    var RUNS_DEADLINE_MS = 30 * 60 * 1000;
    var before = parseInt(box.getAttribute('data-ai-runs-live') || '0', 10);
    function ask() {
      if (Date.now() - started > RUNS_DEADLINE_MS) {
        if (line) line.textContent = 'Still working. Reload this page to see how far it is.';
        return;
      }
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (p) {
          var live = p.live || 0;
          if (live !== before || live === 0) {
            // Something finished: the table again, with its result.
            window.location.reload();
            return;
          }
          if (line) line.textContent = 'Working: ' + live + ' waiting. Keep this page open, or the cron job finishes them.';
          setTimeout(ask, POLL_MS);
        })
        .catch(function () { setTimeout(ask, POLL_MS * 4); });
    }
    ask();
  }

  document.querySelectorAll('[data-ai-picture]').forEach(initEditor);
  document.querySelectorAll('[data-ai-runs-live]').forEach(initRunsLive);
  document.querySelectorAll('[data-ai-bulk-apply]').forEach(initBulkApply);
  document.querySelectorAll('[data-ai-bulk-progress]').forEach(initBulkProgress);
  document.querySelectorAll('[data-ai-watch]').forEach(initWatch);
})();
