// The shop on every page while it is on (docs/shop-plan.md, phase 3): the header's count of pieces (from the gz_cart_n cookie,
// so pages stay the same for everyone), Add to cart without leaving the page and the cart drawer it opens, and the cart and the
// checkout working out their totals again when a quantity, the country, the shipping or the payment changes.
(function () {
  'use strict';

  function cookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function setCount(count) {
    document.querySelectorAll('[data-shop-count]').forEach(function (badge) {
      var showZero = badge.hasAttribute('data-show-zero');
      badge.textContent = count > 0 || showZero ? String(count) : '';
      badge.hidden = !(count > 0 || showZero);
    });
  }
  setCount(parseInt(cookie('gz_cart_n'), 10) || 0);

  var link = document.querySelector('[data-shop-cart-link]');
  var strings = {};
  try { strings = JSON.parse(link ? link.getAttribute('data-shop-strings') || '{}' : '{}'); } catch (e) { strings = {}; }

  // ---- the drawer
  var drawer = null;
  var opener = null;
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function closeDrawer() {
    if (!drawer) return;
    var d = drawer;
    drawer = null;
    d.classList.remove('is-open');
    document.removeEventListener('keydown', onKey);
    setTimeout(function () { d.remove(); }, 250);
    if (opener && opener.focus) opener.focus();
  }
  function onKey(event) {
    if (event.key === 'Escape') closeDrawer();
  }
  function openDrawer(data, message) {
    if (drawer) drawer.remove();
    opener = document.activeElement;
    drawer = el('div', 'shop-drawer');
    var panel = el('div', 'shop-drawer-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', strings.title || 'Cart');
    var head = el('div', 'shop-drawer-head');
    head.appendChild(el('h2', '', strings.title || 'Cart'));
    var close = el('button', 'shop-remove');
    close.type = 'button';
    close.setAttribute('aria-label', strings.close || 'Close');
    close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    close.addEventListener('click', closeDrawer);
    head.appendChild(close);
    panel.appendChild(head);
    var body = el('div');
    body.style.overflowY = 'auto';
    if (message) {
      var note = el('p', 'shop-flash is-success shop-drawer-message', message);
      note.setAttribute('role', 'status');
      body.appendChild(note);
    }
    var list = el('ul', 'shop-drawer-lines');
    (data.lines || []).forEach(function (line) {
      var item = el('li');
      var pic = el('a');
      pic.href = line.url;
      pic.tabIndex = -1;
      if (line.image) {
        var img = el('img');
        img.src = line.image;
        img.alt = '';
        img.loading = 'lazy';
        pic.appendChild(img);
      }
      item.appendChild(pic);
      var text = el('div');
      var name = el('a', 'shop-line-title', line.title);
      name.href = line.url;
      text.appendChild(name);
      if (line.variant) text.appendChild(el('div', 'shop-muted', line.variant));
      text.appendChild(el('div', 'shop-muted', '× ' + line.quantity));
      item.appendChild(text);
      item.appendChild(el('span', '', line.total));
      list.appendChild(item);
    });
    if (!(data.lines || []).length) list.appendChild(el('li', 'shop-muted', strings.empty || ''));
    body.appendChild(list);
    panel.appendChild(body);
    var foot = el('div', 'shop-drawer-foot');
    var total = el('div', 'shop-drawer-total');
    total.appendChild(el('span', '', strings.subtotal || ''));
    total.appendChild(el('span', '', data.subtotal || ''));
    foot.appendChild(total);
    var go = el('a', 'btn btn-primary shop-wide', strings.checkout || 'Checkout');
    go.href = data.checkout_url;
    var view = el('a', 'btn btn-secondary shop-wide', strings.view || 'Cart');
    view.href = data.cart_url;
    foot.appendChild(go);
    foot.appendChild(view);
    panel.appendChild(foot);
    drawer.appendChild(panel);
    drawer.addEventListener('click', function (event) { if (event.target === drawer) closeDrawer(); });
    document.body.appendChild(drawer);
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(function () { if (drawer) { drawer.classList.add('is-open'); close.focus(); } });
  }

  // ---- Add to cart
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-shop-add') || !window.fetch) return;
    event.preventDefault();
    var button = form.querySelector('[type="submit"]');
    var message = form.querySelector('[data-shop-message]');
    if (button) { button.disabled = true; button.textContent = button.getAttribute('data-adding') || button.textContent; }
    if (message) message.textContent = '';
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        setCount(data.count || 0);
        if (data.ok) openDrawer(data, data.message);
        else if (message) message.textContent = data.message || '';
      })
      .catch(function () { form.submit(); })
      .then(function () {
        if (button) { button.disabled = false; button.textContent = button.getAttribute('data-add-label') || button.textContent; }
      });
  });

  // ---- the cart and the checkout work out their totals again
  document.querySelectorAll('[data-shop-cart]').forEach(function (form) {
    var update = form.querySelector('[data-shop-update]');
    form.addEventListener('change', function (event) {
      if (event.target.matches('[data-shop-qty]') && update) form.requestSubmit ? form.requestSubmit(update) : update.click();
    });
  });
  document.querySelectorAll('[data-shop-checkout]').forEach(function (form) {
    var refresh = form.querySelector('[data-shop-refresh-button]');
    if (refresh) refresh.hidden = true;
    form.addEventListener('change', function (event) {
      if (!event.target.matches('[data-shop-refresh]') || !refresh) return;
      // Pressing Update the totals keeps everything written so far, and comes back to where it was.
      try { sessionStorage.setItem('gz_checkout_y', String(window.scrollY)); } catch (e) {}
      if (form.requestSubmit) form.requestSubmit(refresh); else refresh.click();
    });
    try {
      var y = sessionStorage.getItem('gz_checkout_y');
      if (y !== null) { sessionStorage.removeItem('gz_checkout_y'); window.scrollTo(0, parseInt(y, 10) || 0); }
    } catch (e) {}
  });

  // A form that asks first (cancelling a subscription).
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm') || '')) event.preventDefault();
    });
  });

  // The step before the bank's card page: its signed form goes on its own (the button stays for when it does not).
  var bank = document.querySelector('form[data-shop-autosubmit]');
  if (bank) {
    setTimeout(function () { bank.submit(); }, 300);
  }
})();
