/*
 * Shop settings > Import & export (admin/templates/partials/shop-import.twig): while the shop is read or imported, asks /admin/shop-import?work=1 again and
 * again, which works the next slice of the job, and ?status=1 every second for the progress; when the step changes, the screen is
 * drawn again.
 * Buttons are disabled once pressed, so a slow answer does not send a form twice.
 */
(function () {
  'use strict';

  document.querySelectorAll('[data-import-start], [data-import-confirm]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.classList.add('opacity-60');
        button.textContent = form.hasAttribute('data-import-start') ? 'Checking…' : 'Starting…';
      }
    });
  });

  var box = document.querySelector('[data-import-poll]');
  if (!box) { return; }
  var base = box.getAttribute('data-import-poll');
  var status = box.getAttribute('data-import-status');
  var line = box.querySelector('[data-import-line]');
  var bar = box.querySelector('[data-import-bar]');
  var done = false;

  function ask(query) {
    return fetch(base + (base.indexOf('?') < 0 ? '?' : '&') + query, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { if (!response.ok) { throw new Error(String(response.status)); } return response.json(); });
  }

  function draw(answer) {
    if (answer.status !== status) {
      done = true;
      window.location.reload();
      return;
    }
    var p = answer.progress || {};
    if (answer.status !== 'importing') { return; }
    var total = p.media_total || 0;
    var next = p.media_next || 0;
    if (line) {
      line.textContent = p.step === 'products' ? 'Writing the products, the categories and the redirects…' : 'Pictures: ' + next + ' of ' + total;
    }
    if (bar && total > 0) {
      bar.setAttribute('aria-valuenow', String(next));
      bar.firstElementChild.style.width = Math.round(100 * next / total) + '%';
    }
  }

  // The worker: each answer comes when a slice of the job is done (up to half a minute), then the next one is asked for.
  var failures = 0;
  function work() {
    if (done) { return; }
    ask('work=1')
      .then(function (answer) { failures = 0; draw(answer); setTimeout(work, 300); })
      .catch(function () {
        // A slice that ran into the server's time limit, or the network: try again, more slowly.
        failures++;
        setTimeout(work, Math.min(30000, 2000 * failures));
      });
  }

  // The progress, every second, while the worker waits for its answer.
  function watch() {
    if (done) { return; }
    ask('status=1')
      .then(function (answer) { draw(answer); })
      .catch(function () {})
      .then(function () { setTimeout(watch, 1000); });
  }

  work();
  setTimeout(watch, 1000);
})();
