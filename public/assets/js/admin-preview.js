/*
 * The live preview of the Theme screen: the real site in a frame beside the settings (Branding, Header, Footer), which follows
 * every choice that is not saved yet. The frame can be shown at a computer, tablet or phone width, in light or dark, and the
 * page can be reloaded. The settings are plain fields, so the form works without this script.
 *
 * Three ways to follow the choices, set by data-mode on the root ([data-preview]):
 *   css   (Branding) the unsaved choices go to the server (data-endpoint), which answers with the CSS and the attributes the
 *         pages would get; they are put into the frame's document at once, with no reload.
 *   page  (Header, Footer) the choices change what the page is made of, so the server draws the page that is in the frame
 *         with them (nothing is stored, no form is processed) and the frame shows that. Where it was scrolled is kept, and
 *         when the visitor follows a link inside the frame the next page is drawn the same way.
 *   entry (the content editor, admin-entry-preview.js) the whole form of the entry (data-form: its selector) is sent to the server
 *         (data-endpoint), which draws the page as saving the form would make it, without saving. It is asked for 600ms after
 *         the last change of the form, on 'bk:change' (the block editor changed a block) and on 'preview:refresh' (sent to the root);
 *         the root gets 'preview:drawn' whenever the frame holds a new picture.
 */
(function () {
  'use strict';

  function init(root) {
    var form = root.getAttribute('data-form') ? document.querySelector(root.getAttribute('data-form')) : root.closest('form');
    var frame = root.querySelector('[data-bp-frame]');
    var stage = root.querySelector('[data-bp-stage]');
    var endpoint = root.getAttribute('data-endpoint');
    var mode = ({ page: 'page', entry: 'entry' })[root.getAttribute('data-mode')] || 'css';
    if (!form || !frame || !stage || !endpoint) { return; }

    var latest = null;       // css: the last answer of the server
    var dirty = false;       // page: a choice was changed, so what the frame shows must be drawn by the server
    var path = '';           // page: the address of the page in the frame
    var sequence = 0;
    var timer = null;
    var scrollTo = 0;
    var originalModes = new WeakMap();
    var siteAddress = frame.getAttribute('src') || '';
    var viewSequence = 0;

    function frameDocument() {
      try { return frame.contentDocument; } catch (e) { return null; }
    }

    function selected(name) {
      var el = root.querySelector('[' + name + ']:checked');
      return el ? el.value : '';
    }

    function applyMode(doc) {
      var forced = selected('data-bp-mode');
      var attributes = latest ? latest.attributes : {};
      if (!originalModes.has(doc)) { originalModes.set(doc, doc.documentElement.getAttribute('data-mode') || 'system'); }
      var colour = forced || attributes['data-mode'] || originalModes.get(doc);
      var dark = colour === 'dark' || (colour === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      doc.documentElement.setAttribute('data-mode', colour);
      doc.documentElement.classList.toggle('dark', dark);
      doc.documentElement.classList.toggle('light', !dark);
    }

    function node(doc, tag, attributes, text) {
      var el = doc.createElement(tag);
      Object.keys(attributes || {}).forEach(function (key) { el.setAttribute(key, attributes[key]); });
      if (text) { el.textContent = text; }
      return el;
    }

    function applyBrand(doc) {
      var brand = doc.querySelector('.site-brand');
      if (!brand || !latest) { return; }
      brand.textContent = '';
      brand.removeAttribute('aria-label');
      if (latest.logo) {
        brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo' + (latest.logo_dark ? ' logo-on-light' : ''), alt: '', src: latest.logo }));
        if (latest.logo_dark) { brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo logo-on-dark', alt: '', src: latest.logo_dark })); }
        if (latest.show_name) { brand.appendChild(node(doc, 'span', { 'class': 'site-brand-title' }, latest.name)); }
        else { brand.setAttribute('aria-label', latest.name); }
      } else {
        brand.appendChild(node(doc, 'span', { 'class': 'site-brand-title' }, latest.name));
      }
    }

    function formData() {
      var data = new FormData();
      new FormData(form).forEach(function (value, key) {
        if (mode === 'entry') {
          // The whole form as Save would read it, except the files: a picture chosen but not uploaded is not in the preview.
          if (typeof value === 'string') { data.append(key, value); }
        } else if (key.indexOf('theme_settings[') === 0 || key === '_csrf') { data.append(key, value); }
      });
      return data;
    }

    function request() {
      if (mode === 'entry' && root.hidden) { return; } // a panel nobody sees is not drawn; showing it asks again
      var data = formData();
      if (mode === 'page') { data.append('preview_path', path); }
      var mine = ++sequence;
      fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (answer) {
          if (!answer || mine !== sequence) { return; }
          if (answer.error) { return; }
          if (mode === 'page' || mode === 'entry') {
            if (typeof answer.html !== 'string') { return; }
            if (mode === 'page') { path = answer.path || path; }
            try { scrollTo = frame.contentWindow.scrollY || 0; } catch (e) { scrollTo = 0; }
            frame.srcdoc = answer.html;
          } else {
            if (typeof answer.css !== 'string' || !answer.attributes || typeof answer.attributes !== 'object') { return; }
            latest = answer;
            apply();
          }
        })
        .catch(function () { /* the preview is a convenience: a failed request leaves the last picture */ });
    }

    function apply() {
      var doc = frameDocument();
      if (!doc || !doc.documentElement || !doc.head) { return; }
      if (mode === 'css' && latest) {
        // A theme copied before the rename (0.1.316 and older) still names its branding style faros-branding.
        var style = doc.getElementById('gnozio-branding') || doc.getElementById('faros-branding');
        if (!style) {
          style = doc.createElement('style');
          style.id = 'gnozio-branding';
          // Keep the same cascade as a saved page: the site's custom CSS still comes last.
          var custom = doc.querySelector('link[rel="stylesheet"][href*="/_custom/css/custom.css"]');
          doc.head.insertBefore(style, custom);
        }
        style.textContent = latest.css;
        Object.keys(latest.attributes).forEach(function (key) { doc.documentElement.setAttribute(key, latest.attributes[key]); });
        applyBrand(doc);
      }
      applyMode(doc);
      // The page may have been drawn at another width: let it lay itself out again (sticky header, slider).
      try { frame.contentWindow.dispatchEvent(new Event('resize')); } catch (e) { /* the frame went somewhere else */ }
      root.dispatchEvent(new CustomEvent('preview:drawn', { bubbles: true }));
    }

    function onLoad() {
      var address = '';
      try { address = frame.contentWindow.location.href; } catch (e) { address = ''; }
      if (mode === 'entry') {
        if (scrollTo > 0) {
          try { frame.contentWindow.scrollTo(0, scrollTo); } catch (e) { /* nothing to scroll */ }
        }
      } else if (mode === 'page') {
        if (address && address.indexOf('about:') !== 0) {
          // The frame went to a page of the site (a link was followed, or Reload): that is the page to draw from now on.
          try { var url = new URL(address); path = url.pathname + url.search; } catch (e) { /* keep the last */ }
          if (dirty) { request(); return; }
        } else if (scrollTo > 0) {
          try { frame.contentWindow.scrollTo(0, scrollTo); } catch (e) { /* nothing to scroll */ }
        }
      }
      apply();
    }

    function schedule() {
      dirty = true;
      clearTimeout(timer);
      timer = setTimeout(request, mode === 'entry' ? 600 : mode === 'page' ? 400 : 250);
    }

    if (mode === 'entry') {
      // Anything typed or chosen in the form, and whatever the block editor reports; the panel may ask for a picture at once.
      form.addEventListener('input', schedule);
      form.addEventListener('change', schedule);
      document.addEventListener('bk:change', schedule);
    } else {
      var relevant = function (event) {
        return event.target.name && event.target.name.indexOf('theme_settings[') === 0;
      };
      root.addEventListener('input', function (event) { if (relevant(event)) { schedule(); } });
      root.addEventListener('change', function (event) { if (relevant(event)) { schedule(); } });
    }
    root.addEventListener('preview:refresh', function () { clearTimeout(timer); request(); });

    root.querySelectorAll('[data-bp-view]').forEach(function (input) {
      input.addEventListener('change', function () {
        if (!input.checked) { return; }
        var viewRequest = ++viewSequence;
        if (input.value !== 'site') {
          try {
            var address = new URL(frame.contentWindow.location.href);
            if (address.origin === window.location.origin) { siteAddress = address.href; }
          } catch (e) { /* keep the last site address */ }
          // Admin pages cannot be framed. Read the specimen and draw it like the other HTML previews,
          // keeping the admin's frame protection intact and the visitor-facing site address separate.
          fetch(input.getAttribute('data-url'), { credentials: 'same-origin' })
            .then(function (response) {
              if (!response.ok || response.redirected) { throw new Error('Preview unavailable'); }
              return response.text();
            })
            .then(function (html) {
              if (viewRequest !== viewSequence || !input.checked) { return; }
              frame.title = input.getAttribute('data-title') || 'Style samples';
              frame.srcdoc = html;
            })
            .catch(function () {
              if (viewRequest === viewSequence) {
                var site = root.querySelector('[data-bp-view][value="site"]');
                if (site) { site.checked = true; }
              }
            });
        } else {
          frame.title = 'Preview of the site';
          frame.removeAttribute('srcdoc');
          frame.src = siteAddress;
        }
      });
    });

    // The frame shows the page at a computer, tablet or phone width, scaled down to fit the space.
    function fit() {
      var width = parseInt(selected('data-bp-size'), 10) || 1280;
      var space = stage.clientWidth;
      var height = stage.clientHeight;
      if (!space || !height) { return; }
      var scale = Math.min(1, space / width);
      frame.style.width = width + 'px';
      frame.style.height = Math.ceil(height / scale) + 'px';
      frame.style.transform = 'scale(' + scale + ')';
      frame.style.left = Math.max(0, Math.floor((space - width * scale) / 2)) + 'px';
    }

    root.querySelectorAll('[data-bp-size]').forEach(function (input) { input.addEventListener('change', fit); });
    root.querySelectorAll('[data-bp-mode]').forEach(function (input) {
      input.addEventListener('change', function () { var doc = frameDocument(); if (doc && doc.documentElement) { applyMode(doc); } });
    });
    root.querySelector('[data-bp-reload]').addEventListener('click', function () {
      if (mode === 'entry' || (mode === 'page' && dirty)) { request(); } else { frame.contentWindow.location.reload(); }
    });
    frame.addEventListener('load', onLoad);
    window.addEventListener('resize', fit);
    if (typeof ResizeObserver === 'function') { new ResizeObserver(fit).observe(stage); }
    fit();
  }

  document.querySelectorAll('[data-preview]').forEach(init);
})();
