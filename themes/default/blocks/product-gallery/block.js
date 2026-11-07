// Product gallery, thumbnails layout: a thumbnail shows its picture in the large place.
(function () {
  document.querySelectorAll('[data-product-gallery]').forEach(function (gallery) {
    var current = gallery.querySelector('.product-gallery-current');
    if (!current) return;
    gallery.querySelectorAll('.product-gallery-thumb').forEach(function (thumb) {
      thumb.addEventListener('click', function () {
        var picture = thumb.querySelector('img');
        if (!picture) return;
        current.src = picture.currentSrc || picture.src;
        current.removeAttribute('srcset');
        gallery.querySelectorAll('.product-gallery-thumb').forEach(function (other) { other.removeAttribute('aria-current'); });
        thumb.setAttribute('aria-current', 'true');
      });
    });
  });
})();
