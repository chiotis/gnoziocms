/*
 * The content editor screen (admin/templates/edit.twig): the tabs, the pills at the top, the custom fields, the main image, and
 * the previews of the search result and of a shared link. The text of the entry is edited by admin-editor.js; the blocks by admin-blocks.js.
 */
(function () {
  'use strict';

  var buttons = document.querySelectorAll('.tab-button');
  var panels = document.querySelectorAll('.tab-panel');
  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var target = button.getAttribute('data-tab');
      buttons.forEach(function (btn) {
        btn.classList.toggle('active', btn === button);
        btn.setAttribute('aria-selected', btn === button ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute('data-panel') === target;
        panel.classList.toggle('active', on);
        panel.classList.toggle('hidden', !on);
      });
    });
  });

  // ---- the pills beside the title
  var noindex = document.querySelector('input[name="seo_noindex"]');
  var noindexPill = document.querySelector('[data-nonindex-pill]');
  var syncNoindex = function () {
    if (noindexPill) { noindexPill.classList.toggle('hidden', !(noindex && noindex.checked)); }
  };
  if (noindex) { noindex.addEventListener('change', syncNoindex); }
  syncNoindex();

  var status = document.querySelector('[data-status-select]');
  var statusPill = document.querySelector('[data-status-pill]');
  if (status && statusPill) {
    var publishAt = document.querySelector('[data-publish-at]');
    status.addEventListener('change', function () {
      var draft = status.value === 'draft';
      var scheduled = status.value === 'scheduled';
      statusPill.textContent = draft ? 'Draft' : (scheduled ? 'Scheduled' : 'Published');
      statusPill.classList.toggle('is-draft', draft);
      statusPill.classList.toggle('is-scheduled', scheduled);
      statusPill.classList.toggle('is-live', !draft && !scheduled);
      if (publishAt) {
        publishAt.classList.toggle('hidden', !scheduled);
        publishAt.querySelector('input').required = scheduled;
      }
    });
  }

  // ---- custom fields
  var fields = document.querySelector('[data-custom-fields]');
  var addField = document.querySelector('.js-add-field');
  var template = document.querySelector('[data-custom-template]');
  if (fields) {
    fields.addEventListener('click', function (event) {
      var remove = event.target.closest('.js-remove-field');
      var row = remove ? remove.closest('.custom-field-row') : null;
      if (row) { row.remove(); }
    });
  }
  if (addField && fields && template) {
    addField.addEventListener('click', function () {
      fields.appendChild(template.content.cloneNode(true));
      var inputs = fields.querySelectorAll('input[name="custom_keys[]"]');
      if (inputs.length) { inputs[inputs.length - 1].focus(); }
    });
  }

  // ---- main image
  var input = document.getElementById('main-image-input');
  var preview = document.getElementById('main-image-preview');
  var previewImg = document.getElementById('main-image-preview-img');
  var upload = document.getElementById('main-image-upload');
  var showImage = function () {
    if (!input || !preview || !previewImg) { return; }
    var value = (input.value || '').trim();
    preview.classList.toggle('hidden', value === '');
    previewImg.setAttribute('src', value);
  };
  var library = document.querySelector('[data-media-picker-toggle]');
  if (library) {
    library.addEventListener('click', function () {
      var picker = window.GnozioMediaPicker || window.FarosMediaPicker;
      if (!picker || !input) { return; }
      picker.open(function (url) { input.value = url; showImage(); });
    });
  }
  if (input) { input.addEventListener('input', showImage); }
  var clear = document.querySelector('[data-main-image-clear]');
  if (clear && input) {
    clear.addEventListener('click', function () { input.value = ''; showImage(); });
  }
  if (upload && previewImg && preview) {
    upload.addEventListener('change', function () {
      var file = upload.files && upload.files[0];
      if (!file) { return; }
      input.value = '';
      previewImg.setAttribute('src', URL.createObjectURL(file));
      preview.classList.remove('hidden');
    });
  }
  showImage();

  // ---- the search result as it will look
  var serp = document.querySelector('[data-serp]');
  if (serp) {
    var title = document.querySelector('input[name="title"]');
    var excerpt = document.querySelector('textarea[name="excerpt"]');
    var seoTitle = document.querySelector('[data-serp-source="title"]');
    var seoDescription = document.querySelector('[data-serp-source="description"]');
    var outTitle = serp.querySelector('[data-serp-title]');
    var outDescription = serp.querySelector('[data-serp-description]');
    var cut = function (text, n) { return text.length > n ? text.slice(0, n - 1).trim() + '…' : text; };
    var draw = function () {
      var t = (seoTitle && seoTitle.value.trim()) || (title && title.value.trim()) || '';
      var d = (seoDescription && seoDescription.value.trim()) || (excerpt && excerpt.value.trim()) || '';
      outTitle.textContent = cut(t, 70) || 'Title of the page';
      outDescription.textContent = cut(d, 160) || 'No description yet. Search engines will pick a line from the page.';
    };
    [title, excerpt, seoTitle, seoDescription].forEach(function (field) { if (field) { field.addEventListener('input', draw); } });
    draw();
  }

  // ---- the shared link as it will look: an empty field shows (and uses) what it falls back to
  var share = document.querySelector('[data-share-card]');
  if (share) {
    var form = share.closest('form') || document;
    var pick = function (selector) { return form.querySelector(selector); };
    var text = function (el) { return el ? el.value.trim() : ''; };
    var ogTitle = pick('input[name="seo_og_title"]');
    var ogImage = pick('input[name="seo_og_image"]');
    var ogDescription = pick('textarea[name="seo_og_description"]');
    var mainImage = pick('input[name="main_image"]');
    var body = pick('textarea[name="body"]');
    var shareImage = share.querySelector('[data-share-image]');
    var defaultImage = share.getAttribute('data-default-image') || '';
    var defaultDescription = share.getAttribute('data-default-description') || '';
    var cutText = function (value, n) { return value.length > n ? value.slice(0, n - 1).trim() + '…' : value; };
    // The start of the text as the page gives it: the Markdown without its marks.
    var plain = function (markdown) {
      return markdown.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/<[^>]+>/g, ' ')
        .replace(/^[#>\-*+\s]+/gm, '').replace(/[*_`~]+/g, '').replace(/\s+/g, ' ').trim();
    };
    var drawShare = function () {
      var t = text(ogTitle) || text(pick('input[name="seo_title"]')) || text(pick('input[name="title"]'));
      var fallbackImage = text(mainImage) || defaultImage;
      var src = text(ogImage) || fallbackImage;
      var fallbackDescription = text(pick('textarea[name="seo_description"]')) || text(pick('textarea[name="excerpt"]')) || cutText(plain(text(body)), 160) || defaultDescription;
      share.querySelector('[data-share-title]').textContent = cutText(t, 90) || 'Title of the page';
      share.querySelector('[data-share-description]').textContent = cutText(text(ogDescription) || fallbackDescription, 200);
      shareImage.style.backgroundImage = src ? 'url("' + src.replace(/["\\]/g, '') + '")' : '';
      shareImage.classList.toggle('has-image', !!src);
      if (ogImage) { ogImage.placeholder = fallbackImage ? 'Defaults to ' + fallbackImage : '/uploads/your-image.jpg'; }
      if (ogDescription) { ogDescription.placeholder = fallbackDescription ? 'Defaults to: ' + cutText(fallbackDescription, 120) : ''; }
    };
    form.addEventListener('input', drawShare);
    form.addEventListener('change', drawShare);
    drawShare();
  }
})();
