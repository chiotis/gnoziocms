// Image hotspots: a point opens its note; another point, Escape or a click elsewhere closes it.
(function () {
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
  });
})();
