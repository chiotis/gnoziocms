// Term tiles, list layout: the large picture follows the term the visitor points at or tabs to.
(function () {
  document.querySelectorAll('[data-term-list]').forEach(function (list) {
    var pictures = list.querySelectorAll('.term-list-picture');
    function show(index) {
      pictures.forEach(function (p) { p.classList.toggle('is-current', p.getAttribute('data-picture') === index); });
    }
    list.querySelectorAll('.term-list-link').forEach(function (link) {
      var index = link.getAttribute('data-picture');
      link.addEventListener('mouseenter', function () { show(index); });
      link.addEventListener('focus', function () { show(index); });
    });
  });
})();
