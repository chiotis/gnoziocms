/*
 * Texts with a version for each language (Theme > Header and Footer). Every translatable text has one input for each language
 * of the site, named name[default] (the site's own language) and name[en]… (see fields.twig); the language chips of a card choose
 * which of them show. One choice covers the whole page, so the cards stay in step. A chip carries a dot while its language has a
 * text left empty that the site's own language has filled in (the page then shows the site's own text). Nothing here is needed to
 * save: every input is in the form whether it shows or not.
 */
(function () {
  'use strict';

  var scopes = Array.prototype.slice.call(document.querySelectorAll('[data-lang-scope]'));
  if (!scopes.length) return;
  var active = '';

  function inputs(scope) { return Array.prototype.slice.call(scope.querySelectorAll('[data-lang]')); }

  /* The name an input has for the site's own language: text[en] becomes text[default]. */
  function sourceName(input) { return input.name.replace(/\[[^\[\]]+\]$/, '[default]'); }

  function missing(scope, code) {
    var found = false;
    inputs(scope).forEach(function (input) {
      if (input.getAttribute('data-lang') !== code || input.value.trim() !== '') return;
      var source = scope.querySelector('[name="' + sourceName(input).replace(/"/g, '\\"') + '"]');
      if (source && source.value.trim() !== '') found = true;
    });
    return found;
  }

  function apply() {
    // Read again each time: a new row can bring a list of its own with texts per language (a repeater inside a repeater).
    scopes = Array.prototype.slice.call(document.querySelectorAll('[data-lang-scope]'));
    scopes.forEach(function (scope) {
      inputs(scope).forEach(function (input) {
        input.hidden = input.getAttribute('data-lang') !== active;
        // An empty translation shows what the page will use instead: the site's own text.
        if (input.name !== sourceName(input)) {
          if (!input.hasAttribute('data-hint')) input.setAttribute('data-hint', input.placeholder);
          var source = scope.querySelector('[name="' + sourceName(input).replace(/"/g, '\\"') + '"]');
          input.placeholder = source && source.value.trim() !== '' ? source.value : input.getAttribute('data-hint');
        }
      });
      Array.prototype.forEach.call(scope.querySelectorAll('[data-lang-pick]'), function (button) {
        var code = button.getAttribute('data-lang-pick');
        button.setAttribute('aria-pressed', code === active ? 'true' : 'false');
        var own = code === button.parentNode.firstElementChild.getAttribute('data-lang-pick');
        var gap = !own && missing(scope, code);
        button.classList.toggle('has-missing', gap);
        button.title = gap ? 'Some texts are not translated yet: the site\'s own text shows' : (own ? 'The site\'s own language' : 'Translation');
      });
    });
  }

  var first = document.querySelector('[data-lang-pick]');
  if (!first) return;
  active = first.getAttribute('data-lang-pick');

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-lang-pick]') : null;
    if (button) {
      active = button.getAttribute('data-lang-pick');
      apply();
      return;
    }
    // A new row of a repeater is a copy of the template: it shows the language that is chosen.
    if (event.target.closest && event.target.closest('[data-repeater-add]')) setTimeout(apply, 0);
  });
  document.addEventListener('input', function (event) {
    if (event.target && event.target.hasAttribute && event.target.hasAttribute('data-lang')) apply();
  });
  apply();
})();
