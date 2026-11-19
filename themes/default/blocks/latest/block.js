/* Latest content, Sliding strip: the cards are a plain scroll-snap strip that works by touch, wheel and keyboard without this
   script. Here it gets previous/next buttons, dots for each position, and optional automatic movement (data-autoplay, in
   seconds) that stops while the visitor points at it, touches it or uses it, with a pause button. A move goes one card on or a
   whole view on (data-step); after the last position it starts again from the first. Visitors who prefer reduced motion get no
   automatic movement and no smooth scrolling. */
(function () {
  var strips = document.querySelectorAll('[data-strip]');
  if (!strips.length) return;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var svg = function (path, filled) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"' + (filled ? ' fill="currentColor"' : ' fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"') + '>' + path + '</svg>';
  };
  var ICONS = {
    prev: svg('<path d="M15 5l-7 7 7 7"/>', false),
    next: svg('<path d="M9 5l7 7-7 7"/>', false),
    pause: svg('<rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/>', true),
    play: svg('<path d="M8 5.5v13l11-6.5z"/>', true)
  };

  strips.forEach(function (root) {
    var track = root.querySelector('.latest-list');
    var cards = track ? Array.prototype.slice.call(track.children) : [];
    if (cards.length < 2) return;
    var attr = function (name, fallback) { return root.getAttribute(name) || fallback; };
    var nav = attr('data-nav', 'both');
    var byPage = attr('data-step', 'one') === 'page';
    var seconds = parseInt(attr('data-autoplay', '0'), 10) || 0;
    var autoplay = seconds > 0 && !reduceMotion;
    var timer = 0;
    var paused = false;
    var holding = false;

    function button(cls, label, glyph) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = cls;
      b.setAttribute('aria-label', label);
      if (glyph) b.innerHTML = glyph;
      return b;
    }

    var controls = document.createElement('div');
    controls.className = 'latest-strip-controls';
    var prev = button('latest-strip-btn is-prev', attr('data-label-prev', 'Previous slide'), ICONS.prev);
    var next = button('latest-strip-btn is-next', attr('data-label-next', 'Next slide'), ICONS.next);
    var dots = document.createElement('div');
    dots.className = 'latest-strip-dots';
    var toggle = autoplay ? button('latest-strip-btn is-toggle', attr('data-label-pause', 'Pause automatic movement'), ICONS.pause) : null;
    // With more positions than MAX_DOTS (a long list, or a phone with one card in view) the dots would run off the screen:
    // a counter ("3 / 18") takes their place, with the arrows beside it even when only dots were asked for.
    var MAX_DOTS = 7;
    var count = document.createElement('span');
    count.className = 'latest-strip-count';
    count.setAttribute('aria-hidden', 'true');
    var wantArrows = nav === 'both' || nav === 'arrows';
    var wantDots = nav === 'both' || nav === 'dots';
    if (nav !== 'none') {
      controls.appendChild(prev);
      controls.appendChild(dots);
      controls.appendChild(count);
    }
    if (toggle) controls.appendChild(toggle);
    if (nav !== 'none') controls.appendChild(next);
    root.appendChild(controls);
    root.classList.add('is-enhanced');

    // The positions the strip can stop at, always the left edge of a card (where it snaps), up to the end of the strip: each
    // card, or by page the first card after the whole view that shows.
    function stops() {
      var max = Math.max(0, track.scrollWidth - track.clientWidth);
      var base = cards[0].offsetLeft;
      var list = [0];
      cards.forEach(function (card) {
        var x = card.offsetLeft - base;
        if (x > 2 && x < max - 2 && (!byPage || x >= list[list.length - 1] + track.clientWidth - 2)) list.push(x);
      });
      if (max > 2) list.push(max);
      return list;
    }

    var dotButtons = [];
    function drawDots() {
      var list = stops();
      if (dotButtons.length !== list.length) {
        dots.innerHTML = '';
        dotButtons = list.map(function (x, i) {
          var dot = button('latest-strip-dot', attr('data-label-goto', 'Go to slide') + ' ' + (i + 1), '');
          dot.addEventListener('click', function () { goTo(i); });
          dots.appendChild(dot);
          return dot;
        });
      }
      var many = list.length > MAX_DOTS;
      dots.hidden = !wantDots || many;
      count.hidden = !wantDots || !many;
      prev.hidden = next.hidden = !(wantArrows || many);
      // Nothing to slide (every card fits): no controls.
      controls.hidden = list.length < 2;
      return list;
    }

    function index() {
      var list = stops();
      var at = track.scrollLeft;
      var best = 0;
      list.forEach(function (x, i) { if (Math.abs(x - at) < Math.abs(list[best] - at)) best = i; });
      return best;
    }

    function mark() {
      var i = index();
      count.textContent = (i + 1) + ' / ' + dotButtons.length;
      dotButtons.forEach(function (dot, n) {
        if (n === i) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current');
      });
    }

    function goTo(i) {
      var list = stops();
      if (list.length < 2) return;
      i = i >= list.length ? 0 : (i < 0 ? list.length - 1 : i);
      track.scrollTo({ left: list[i], behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    function schedule() {
      window.clearTimeout(timer);
      if (autoplay && !paused && !holding) timer = window.setTimeout(function () { goTo(index() + 1); schedule(); }, seconds * 1000);
    }

    function syncToggle() {
      if (!toggle) return;
      toggle.setAttribute('aria-label', paused ? attr('data-label-play', 'Start automatic movement') : attr('data-label-pause', 'Pause automatic movement'));
      toggle.innerHTML = paused ? ICONS.play : ICONS.pause;
    }

    prev.addEventListener('click', function () { goTo(index() - 1); });
    next.addEventListener('click', function () { goTo(index() + 1); });
    if (toggle) toggle.addEventListener('click', function () { paused = !paused; syncToggle(); schedule(); });
    track.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowRight') { event.preventDefault(); goTo(index() + 1); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(index() - 1); }
    });
    var raf = 0;
    track.addEventListener('scroll', function () {
      if (raf) return;
      raf = window.requestAnimationFrame(function () { raf = 0; mark(); });
    }, { passive: true });
    window.addEventListener('resize', function () { drawDots(); mark(); });

    if (autoplay) {
      // Movement stops while the visitor points at, touches or uses the strip, and while it is off screen or the tab is hidden.
      ['mouseenter', 'focusin', 'touchstart'].forEach(function (name) {
        root.addEventListener(name, function () { holding = true; window.clearTimeout(timer); }, { passive: true });
      });
      ['mouseleave', 'focusout'].forEach(function (name) {
        root.addEventListener(name, function () { holding = false; schedule(); });
      });
      var visible = true;
      var tick = function () { if (visible && !document.hidden) schedule(); else window.clearTimeout(timer); };
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; tick(); }).observe(root);
      }
      document.addEventListener('visibilitychange', tick);
      syncToggle();
      schedule();
    }
    drawDots();
    mark();
  });
})();
