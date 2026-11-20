/* Banner: a dismiss button, remembered for 30 days. The key includes a hash of the text, so a changed
   announcement shows again. Without JavaScript the banner is simply always shown. */
(function () {
  var DAY = 24 * 60 * 60 * 1000;

  /* A dismissal kept under the old name (0.1.316 and older: faros-banner-…) is read once and moved. */
  function read(key) {
    try {
      var old = 'faros-' + key.slice('gnozio-'.length);
      if (window.localStorage.getItem(key) === null && window.localStorage.getItem(old) !== null) {
        window.localStorage.setItem(key, window.localStorage.getItem(old));
        window.localStorage.removeItem(old);
      }
      return parseInt(window.localStorage.getItem(key) || '0', 10);
    } catch (e) { return 0; }
  }
  function write(key) {
    try { window.localStorage.setItem(key, String(Date.now() + 30 * DAY)); } catch (e) { /* private mode */ }
  }

  document.querySelectorAll('[data-banner]').forEach(function (box) {
    var key = 'gnozio-banner-' + box.getAttribute('data-banner');
    var section = box.closest('.block') || box;
    if (read(key) > Date.now()) {
      section.hidden = true;
      return;
    }
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'banner-close';
    close.setAttribute('aria-label', box.getAttribute('data-label-dismiss') || 'Dismiss');
    close.innerHTML = '<span aria-hidden="true">×</span>';
    close.addEventListener('click', function () {
      write(key);
      section.hidden = true;
    });
    box.appendChild(close);
  });
})();
