// Product summary: the chosen options pick the variant, whose price, SKU and stock are shown.
(function () {
  document.querySelectorAll('[data-product-summary]').forEach(function (summary) {
    var data = summary.querySelector('[data-variants]');
    if (!data) return;
    var variants;
    try { variants = JSON.parse(data.textContent || '[]'); } catch (e) { return; }
    var price = summary.querySelector('[data-price]');
    var from = summary.querySelector('[data-from]');
    var sku = summary.querySelector('[data-sku]');
    var stock = summary.querySelector('[data-stock]');
    var key = function (values) { return values.map(function (v) { return v.trim().toLowerCase(); }).join(' / '); };
    function update() {
      var chosen = Array.prototype.map.call(summary.querySelectorAll('[data-option]'), function (set) {
        var input = set.querySelector('input:checked');
        return input ? input.value : '';
      });
      var match = null;
      variants.forEach(function (variant) { if (variant.key === key(chosen)) match = variant; });
      if (from) from.hidden = true;
      if (match && price && match.price) price.textContent = match.price;
      if (sku) sku.textContent = match && match.sku ? match.sku : sku.textContent;
      if (stock) stock.textContent = !match ? stock.getAttribute('data-none') : (match.available ? stock.getAttribute('data-in') : stock.getAttribute('data-out'));
      var add = summary.querySelector('.product-add');
      if (add) add.disabled = !match || !match.available;
    }
    summary.addEventListener('change', function (event) {
      if (event.target && event.target.matches('[data-option] input')) update();
    });
    update();
  });
})();
