# The shop

A GnozioCMS site has a shop when the **Products** content type is on (Admin > Content types). Switching it on brings **Looks**, the taxonomies **Collections** and **Product categories**, **System › Shop settings**, the **Shop** group of the admin menu and the shop blocks (`product-grid`, `product-card`, `product-price`, `product-gallery`, `product-summary`, `product-details`, `shop-the-look`, `looks`, `mini-cart`). Shop blocks draw nothing while the shop is off. The installed definition is `themes/default/content-types/products.yaml` (and `custom/content-types/products.yaml`); `site_info.py` or MCP `shop_info` list the fields of the installation. The repository's `docs/shop-plan.md` is the full design.

## Products are entries

`content/products/<slug>.md` (and `.en.md`…), like pages, with the shop fields under `custom_fields`. The main ones:

| Field | Takes |
|---|---|
| `kind` | `physical` (default), `digital` (sells files, uploaded in Shop › Files), `subscription` (charged every `interval` / `interval_count`, by card through Viva Wallet) |
| `price`, `compare_at_price` | decimals; `compare_at_price` is the struck-through "was" price, so a product is on sale when it is higher than `price` |
| `tax_class` | one of the shop's VAT classes |
| `gallery`, `hover_image` | library pictures; the hover picture shows on the card |
| `short_description` | one or two sentences beside the price (per language) |
| `details` | rows `{title, text}` (Care, Materials…), up to 8, per language |
| `options` | up to 3 rows `{name, values}` (values one per line), for example Finish: Gold, Silver |
| `variants` | up to 100 rows `{values: "Gold / M", sku, price, compare_at_price, image, weight_g}`; an empty price or "was" price takes the product's, so one variant can be on sale alone |
| `option_labels` | in a translation, the names of options and values in its language: `Gold = Χρυσό`, one per line |
| `track_stock`, `allow_backorder`, `low_stock_at`, `weight_g`, `gift_wrap`, `sku`, `barcode`, `brand` | as named |
| `complete_the_look`, `looks` | relations: up to 6 products / Looks |

Fields marked `shared` (price, variants, options, stock settings, gallery, SKU…) have **one value for every translation**: write them in the default-language file; a save in the admin copies them across. Never give two languages different prices.

**Stock is not in the file.** It is kept in the database per product and variant; change it in the product editor or with MCP `set_stock` (the WooCommerce import brings it too). Do not invent stock numbers.

Options and variants are written in the site's default language; a variant's `values` lists one value per option, in order, separated by ` / `.

## Looks

`content/looks/<slug>.md`: a picture with `pieces`, rows `{product: <product>, x, y}` placing each product on the picture in % from the top left (up to 8). A piece's picker offers only published products (`published_only: true`, a setting any relation field may have). The **Shop the look** block shows a Look; on a product page it uses the Look picked in the block, then the product's `looks`, then the newest Look that has the product.

## Collections and product categories

Terms of the taxonomies `collections` and `product-categories` (`content/taxonomies/*.yaml`, or Taxonomies in the admin, or MCP `create_term`). A collection has a number, colour tones and a quote for its chapter-style page. To file many products at once: the content list's Bulk action **Add to / Remove from** a term, or MCP `file_entries`.

## Admin screens

| Screen | Holds |
|---|---|
| Content › Products, Looks | products and Looks, with a filter bar (status, every taxonomy, stock, main image, dates) and bulk actions |
| Shop › Orders | orders, their timeline, shipping, refunds, packing slip |
| Shop › Customers, Subscriptions, Files, Coupons | as named; coupons can be limited to chosen products or collections |
| System › Shop settings | tabs **General** (store, currency, order prefix, guest checkout, terms pages), **Shipping** (zones, methods, pickup), **Payments** (Viva Wallet, Cardlink, bank transfer, cash on delivery: on/off, test mode, keys, *Check connection*), **Emails**, **Accounts** (sign-up, email confirmation, **Sign in with Google**), **Marketing** (product feed for Google and Meta, Pixel), **Import & export** (WooCommerce import, products CSV) |

Permissions: `shop.orders` (orders, customers, subscriptions) and `shop.manage` (settings and keys, admins only).

## Rules for the shop

- **Never set prices, stock, VAT or shipping costs you were not given.** Leave a `TODO` and a draft product.
- **Payment keys, Google client secrets and Meta tokens are the person's**: tell them the tab (Shop settings › Payments / Accounts / Marketing); do not type them. Sign in with Google needs `https://<site>/account/google/callback` in the Google client's redirect URIs.
- **Money stays with a person.** Do not mark orders paid, refund, or charge anyone. Gateways are tested by the person with test mode first.
- Orders and customers are personal data: read only what the task needs, never copy them elsewhere.
- **Moving a WooCommerce shop**: Shop settings › Import & export (connect with read-only keys, review, import), not hand-written files. It brings categories, products with variants, prices, pictures and stock, and the redirects; run again, it updates instead of copying.
- Products, Looks and terms are created as drafts unless told to publish; a draft product cannot be picked in a Look.
