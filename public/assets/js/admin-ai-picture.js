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

  document.querySelectorAll('[data-ai-picture]').forEach(initEditor);
  document.querySelectorAll('[data-ai-watch]').forEach(initWatch);
})();
