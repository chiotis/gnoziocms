/* Hero slider: the pictures of the slides after the first are not fetched with the page (Images, defer: they carry data-src and
   data-srcset). They are given their addresses once the page has loaded and the browser is idle, or as soon as the visitor
   moves the strip, whichever is first. */
(function () {
  var sliders = document.querySelectorAll('[data-slider]');
  if (!sliders.length) return;
  var revealed = false;
  var reveal = function () {
    if (revealed) return;
    revealed = true;
    sliders.forEach(function (root) {
      root.querySelectorAll('source[data-srcset]').forEach(function (source) {
        source.srcset = source.getAttribute('data-srcset');
        source.removeAttribute('data-srcset');
      });
      root.querySelectorAll('img[data-src]').forEach(function (img) {
        img.src = img.getAttribute('data-src');
        img.removeAttribute('data-src');
      });
    });
  };
  var later = function () {
    if (window.requestIdleCallback) window.requestIdleCallback(reveal, { timeout: 2000 }); else window.setTimeout(reveal, 200);
  };
  if (document.readyState === 'complete') later(); else window.addEventListener('load', later, { once: true });
  sliders.forEach(function (root) {
    ['pointerdown', 'touchstart', 'focusin', 'wheel'].forEach(function (name) {
      root.addEventListener(name, reveal, { once: true, passive: true });
    });
    var track = root.querySelector('.slider-track');
    if (track) track.addEventListener('scroll', reveal, { once: true, passive: true });
  });
})();

/* Hero slider: the slides are a plain scroll-snap strip that works by touch, wheel, and keyboard without this
   script. Here we add previous/next buttons, position dots, and optional automatic movement. */
(function () {
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

  document.querySelectorAll('[data-slider]').forEach(function (root) {
    var track = root.querySelector('.slider-track');
    var slides = Array.prototype.slice.call(root.querySelectorAll('.slider-slide'));
    if (!track || slides.length < 2) return;

    var attr = function (name, fallback) { return root.getAttribute(name) || fallback; };
    var current = 0;
    var timer = null;
    var paused = false;
    var moving = 0;
    var seconds = parseInt(root.getAttribute('data-autoplay') || '0', 10);
    var autoplay = seconds > 0 && !reduceMotion;

    var controls = document.createElement('div');
    controls.className = 'slider-controls';
    var prev = button('slider-btn is-prev', attr('data-label-prev', 'Previous slide'), ICONS.prev);
    var next = button('slider-btn is-next', attr('data-label-next', 'Next slide'), ICONS.next);
    var dots = document.createElement('div');
    dots.className = 'slider-dots';
    var dotButtons = slides.map(function (slide, i) {
      var dot = button('slider-dot', attr('data-label-goto', 'Go to slide') + ' ' + (i + 1), '');
      dot.addEventListener('click', function () { goTo(i); });
      dots.appendChild(dot);
      return dot;
    });
    controls.appendChild(prev);
    controls.appendChild(dots);
    var toggle = null;
    if (autoplay) {
      toggle = button('slider-btn is-toggle', attr('data-label-pause', 'Pause automatic movement'), ICONS.pause);
      toggle.addEventListener('click', function () { paused = !paused; syncToggle(); schedule(); });
      controls.appendChild(toggle);
    }
    controls.appendChild(next);
    root.appendChild(controls);
    root.classList.add('is-enhanced');

    function button(cls, label, glyph) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = cls;
      b.setAttribute('aria-label', label);
      if (glyph) b.innerHTML = '<span aria-hidden="true">' + glyph + '</span>';
      return b;
    }

    function syncToggle() {
      if (!toggle) return;
      toggle.setAttribute('aria-label', paused ? attr('data-label-play', 'Start automatic movement') : attr('data-label-pause', 'Pause automatic movement'));
      toggle.firstChild.innerHTML = paused ? ICONS.play : ICONS.pause;
      track.setAttribute('aria-live', paused || !autoplay ? 'polite' : 'off');
    }

    function slideStep() {
      return slides.length > 1 ? slides[1].offsetLeft - slides[0].offsetLeft : track.clientWidth;
    }

    function maxIndex() {
      // The last index whose slide can still be reached by scrolling.
      var max = track.scrollWidth - track.clientWidth;
      return Math.min(slides.length - 1, Math.ceil(max / Math.max(slideStep(), 1) - 0.05));
    }

    function goTo(i) {
      var last = maxIndex();
      i = i > last ? 0 : (i < 0 ? last : i);
      // While the scroll animates, the positions it passes through must not change the target.
      window.clearTimeout(moving);
      moving = window.setTimeout(function () { moving = 0; fromScroll(); }, reduceMotion ? 50 : 700);
      track.scrollTo({ left: slides[i].offsetLeft - slides[0].offsetLeft, behavior: reduceMotion ? 'auto' : 'smooth' });
      update(i);
    }

    function update(i) {
      // The hero slider follows the slide that shows (its header).
      if (i !== current) root.dispatchEvent(new CustomEvent('slider:change', { detail: { index: i, slide: slides[i] } }));
      current = i;
      dotButtons.forEach(function (dot, n) {
        if (n === i) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current');
      });
    }

    function fromScroll() {
      if (moving) return;
      var step = Math.max(slideStep(), 1);
      var i = Math.round(track.scrollLeft / step);
      var atEnd = track.scrollLeft >= track.scrollWidth - track.clientWidth - 2;
      update(atEnd ? Math.min(slides.length - 1, Math.max(i, maxIndex())) : Math.min(i, slides.length - 1));
      // Dots beyond the last reachable position stay inactive when several slides fit at once.
      dotButtons.forEach(function (dot, n) { dot.hidden = n > maxIndex(); });
    }

    function schedule() {
      window.clearTimeout(timer);
      if (autoplay && !paused) timer = window.setTimeout(function () { goTo(current + 1); schedule(); }, seconds * 1000);
    }

    prev.addEventListener('click', function () { goTo(current - 1); });
    next.addEventListener('click', function () { goTo(current + 1); });
    var raf = 0;
    track.addEventListener('scroll', function () {
      if (raf) return;
      raf = window.requestAnimationFrame(function () { raf = 0; fromScroll(); });
    }, { passive: true });
    track.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowRight') { event.preventDefault(); goTo(current + 1); }
      if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(current - 1); }
    });
    window.addEventListener('resize', fromScroll);

    if (autoplay) {
      // Movement stops while the visitor points at or focuses the slider.
      ['mouseenter', 'focusin', 'touchstart'].forEach(function (name) {
        root.addEventListener(name, function () { window.clearTimeout(timer); }, { passive: true });
      });
      ['mouseleave', 'focusout'].forEach(function (name) {
        root.addEventListener(name, schedule);
      });
      syncToggle();
      schedule();
    }
    fromScroll();
  });
})();

/* Hero slider: when it opens the page under a transparent header, the header's text is light over a slide that is a picture
   and dark over one that is not, so it follows the slide that shows. (The server draws it for the first slide.) */
(function () {
  var header = document.querySelector('.site-header.is-transparent');
  var slider = document.querySelector('.main-shell > .block-hero-slider:first-of-type .slider-hero');
  if (!header || !slider) return;
  slider.addEventListener('slider:change', function (event) {
    header.classList.toggle('is-on-dark', event.detail.slide.hasAttribute('data-dark'));
  });
})();
