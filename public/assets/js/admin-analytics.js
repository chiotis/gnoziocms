/*
 * Admin > Analytics: the settings show what belongs to the choice (no tracking, your own code, GnozioCMS analytics) and the reports
 * switch between the lists of a card. Without this script every part is shown and every list is open.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-an-form]');
  if (form) {
    var parts = form.querySelectorAll('[data-when-mode]');
    var draw = function () {
      var checked = form.querySelector('input[name="mode"]:checked');
      var mode = checked ? checked.value : 'off';
      parts.forEach(function (part) {
        var modes = part.getAttribute('data-when-mode').split(' ');
        part.hidden = modes.indexOf(mode) === -1;
      });
    };
    form.addEventListener('change', draw);
    draw();
  }

  document.querySelectorAll('[data-an-card]').forEach(function (card) {
    var buttons = card.querySelectorAll('[data-an-tab]');
    var panels = card.querySelectorAll('[data-an-panel]');
    if (buttons.length < 2) { return; }
    var show = function (name) {
      buttons.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-an-tab') === name ? 'true' : 'false'); });
      panels.forEach(function (p) { p.hidden = p.getAttribute('data-an-panel') !== name; });
    };
    buttons.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-an-tab')); }); });
    show(buttons[0].getAttribute('data-an-tab'));
  });

  var live = document.querySelector('[data-an-live]');
  if (live && window.fetch) {
    var count = live.querySelector('[data-an-live-count]');
    var list = live.querySelector('[data-an-live-pages]');
    var poll = function () {
      if (document.hidden) { return; }
      fetch(live.getAttribute('data-an-live'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) { return; }
          count.textContent = data.visitors;
          list.textContent = '';
          Object.keys(data.pages).forEach(function (path) {
            var li = document.createElement('li');
            var a = document.createElement('span');
            var n = document.createElement('b');
            a.textContent = path;
            n.textContent = data.pages[path];
            li.appendChild(a);
            li.appendChild(n);
            list.appendChild(li);
          });
        })
        .catch(function () { /* the next try may work */ });
    };
    setInterval(poll, 30000);
  }
})();
