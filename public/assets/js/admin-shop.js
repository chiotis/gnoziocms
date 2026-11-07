// Shop settings > Shipping: adds and removes zones and their methods, and shows each method's fields for its kind.
(function () {
  'use strict';
  var form = document.querySelector('[data-shop-shipping]');
  if (!form) return;
  var zones = form.querySelector('[data-zones]');
  var zoneTemplate = form.querySelector('[data-zone-template]');
  var methodTemplate = form.querySelector('[data-method-template]');
  var next = Date.now();

  function showFor(method) {
    var kind = method.querySelector('[data-method-kind]').value;
    method.querySelectorAll('[data-show-for]').forEach(function (part) {
      part.hidden = part.getAttribute('data-show-for').split(' ').indexOf(kind) === -1;
    });
  }
  function fromTemplate(template, z, m) {
    var html = template.innerHTML.replace(/__Z__/g, z).replace(/__M__/g, m);
    var holder = document.createElement('div');
    holder.innerHTML = html.trim();
    return holder.firstElementChild;
  }
  form.querySelectorAll('[data-method]').forEach(showFor);

  form.addEventListener('change', function (event) {
    if (event.target.matches('[data-method-kind]')) showFor(event.target.closest('[data-method]'));
  });
  form.addEventListener('click', function (event) {
    var target = event.target.closest('button');
    if (!target) return;
    if (target.hasAttribute('data-add-zone')) {
      var zone = fromTemplate(zoneTemplate, 'n' + (next++), '0');
      zones.appendChild(zone);
      zone.querySelectorAll('[data-method]').forEach(showFor);
      zone.querySelector('input:not([type=hidden])').focus();
    } else if (target.hasAttribute('data-add-method')) {
      var section = target.closest('[data-zone]');
      var method = fromTemplate(methodTemplate, section.getAttribute('data-z'), 'n' + (next++));
      section.querySelector('[data-methods]').appendChild(method);
      showFor(method);
    } else if (target.hasAttribute('data-remove-method')) {
      target.closest('[data-method]').remove();
    } else if (target.hasAttribute('data-remove-zone')) {
      target.closest('[data-zone]').remove();
    }
  });
})();
