// Image hotspots: a point opens its note; another point, Escape or a click elsewhere closes it.
(function () {
  // Shop the look's "Show the pieces in turn": each piece with its note and its row in the list, then a short pause, then the next. It runs
  // only while on screen, waits while the pointer or the keyboard is on the look, and ends for good when a visitor opens a note themselves.
  var SHOW = 3200;
  var GAP = 500;
  function tour(figure, buttons, close) {
    if (buttons.length < 1 || !window.IntersectionObserver || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var look = figure.closest('.look') || figure;
    var rows = look.querySelectorAll('.look-piece');
    var at = -1, timer = 0, visible = false, held = false, over = false;
    function clearLit() {
      buttons.forEach(function (b) { b.closest('.hotspot').classList.remove('is-lit'); });
      rows.forEach(function (r) { r.classList.remove('is-lit'); });
      close(null);
    }
    function step() {
      timer = 0;
      if (over || !visible || held || document.hidden) return;
      if (at >= 0) {
        clearLit();
        at = -at - 2;
        timer = setTimeout(step, GAP);
        return;
      }
      at = (-at - 1) % buttons.length;
      var button = buttons[at];
      button.closest('.hotspot').classList.add('is-lit');
      if (rows[at]) rows[at].classList.add('is-lit');
      button.setAttribute('aria-expanded', 'true');
      var card = document.getElementById(button.getAttribute('aria-controls'));
      if (card) card.hidden = false;
      timer = setTimeout(step, SHOW);
    }
    function go() { if (!timer && !over && visible && !held && !document.hidden) timer = setTimeout(step, 400); }
    function wait() {
      if (timer) { clearTimeout(timer); timer = 0; }
    }
    new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      if (visible) go(); else { wait(); if (at >= 0) { clearLit(); at = -at - 2; } }
    }, { threshold: 0.4 }).observe(figure);
    look.addEventListener('pointerenter', function () { held = true; wait(); });
    look.addEventListener('pointerleave', function () { held = false; go(); });
    look.addEventListener('focusin', function () { held = true; wait(); });
    look.addEventListener('focusout', function () { held = false; go(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) wait(); else go(); });
    figure.addEventListener('click', function (event) {
      if (!event.target.closest('.hotspot-dot')) return;
      over = true;
      wait();
      clearLit();
    }, true);
  }

  document.querySelectorAll('[data-hotspots]').forEach(function (figure) {
    var buttons = figure.querySelectorAll('.hotspot-dot');
    function close(except) {
      buttons.forEach(function (b) {
        if (b === except) return;
        b.setAttribute('aria-expanded', 'false');
        var card = document.getElementById(b.getAttribute('aria-controls'));
        if (card) card.hidden = true;
      });
    }
    buttons.forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.stopPropagation();
        var open = button.getAttribute('aria-expanded') === 'true';
        close(button);
        button.setAttribute('aria-expanded', open ? 'false' : 'true');
        var card = document.getElementById(button.getAttribute('aria-controls'));
        if (card) card.hidden = open;
      });
    });
    document.addEventListener('click', function (event) { if (!figure.contains(event.target)) close(null); });
    figure.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        var open = figure.querySelector('.hotspot-dot[aria-expanded="true"]');
        close(null);
        if (open) open.focus();
      }
    });
    if (figure.hasAttribute('data-hotspots-tour')) tour(figure, buttons, close);
  });
})();
