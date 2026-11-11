# Shop: plan

Status: approved by the owner on 2026-11-07. Each phase (section 11) is one branch, one merge and one VERSION bump. **Phase 1 (catalogue) is built** on branch `shop-catalogue` and **phase 2 (design blocks)** on `design-blocks`, which starts from it; what differs from this plan is noted in section 11.

## Why

The ROADMAP's last big item is "A shop, later … It starts with a short design the owner approves before code is written." This plan is that design. The owner sells physical goods (apparel, jewellery) and also needs digital products and subscriptions. Most clients are Greek, so the shop's edge is Greek bank payments: **Viva Wallet** and **Eurobank via Cardlink** come first. A client runs the Cardlink WordPress plugin on a WooCommerce site that will move to GnozioCMS. The ALALEON design (Claude Design, Concept v3) supplies the look. Its sections become reusable blocks: generic blocks for every site, and shop blocks that only show when the shop is on.

Decided with the owner (2026-11-07):
- **Customer accounts from day one.** Shoppers sign up and sign in, separately from admin users. Guest checkout is a setting, on by default. Subscriptions need an account.
- **myDATA (AADE) later**, through one certified e-invoicing provider. v1 stores VAT per order line and exports CSV.
- **Payments v1:** Viva Wallet Smart Checkout, Cardlink redirect (Worldline/Eurobank, Nexi/Alpha, Cardlink), bank transfer, cash on delivery. Stripe and PayPal come later.
- **Collection colours only for the shop's Collections taxonomy**, not for every taxonomy.

---

## 1. Shape of the module

- **Switch:** the shop is on when the catalogue type `products` is on (`SiteSettings::setContentType`, `ContentRepository::getTypes()`). A helper, `Shop::enabled()`, is the single check every gate uses.
- **When it is on:**
  - a **Shop** sidebar group appears (Orders, Customers, Subscriptions, Coupons);
  - **System › Shop settings** appears;
  - the shop blocks appear in the editors;
  - the `/cart`, `/checkout` and `/account` routes start answering.
  - Every item is wrapped as `{% if can('shop.…') and shop_enabled %}` in `admin/templates/base.twig`, where `shop_enabled` is a new Twig global set in `src/SiteTwig.php`.
- **Where the data lives,** following `docs/system-database.md`:
  - **Markdown** (the content): products, collections and looks.
  - **SQLite** (`storage/db/app.sqlite`, migrations in `SystemDatabase::migrate()`): stock, carts, orders, payments, customers, coupons, subscriptions, downloads and the gateway event log. Backups and the update snapshot already cover this file.
  - **Money** is stored as integer cents. EUR is the default currency. Prices include VAT, which is the EU consumer norm.
- **Caching stays intact.** Visitors still get no session on ordinary pages (`App::needsSession()`); a session starts only on `/cart`, `/checkout`, `/account` and `/_shop/*`. The cart lives in SQLite, keyed by a random cookie. The header's cart count and account link are filled by a small script from a readable cookie (`gz_cart_n`) and `/_shop/cart.json`, so product pages stay cacheable. The server re-prices everything from the content, so a cached price can never be charged.
- **Code layout:** new classes under `src/Shop/` with the namespace `Gnozio\Shop`. If the autoloader doesn't map subfolders yet, they use a `Shop` prefix in `src/` instead (checked first in `src/autoload.php`). `App.php` only gets routes and lazy factories, because App.php growth is watched.

## 2. Products content type

The type is `themes/default/content-types/products.yaml`. It is a catalogue type, off by default, labelled in el and en. Its fields:

| Group | Fields |
|---|---|
| Kind | `kind` select: physical, digital, subscription |
| Price | `price` (decimal), `compare_at_price` (the "was" price), `tax_class` (standard 24%, reduced 13%, super-reduced 6%, editable in settings) |
| Identity | `sku`, `barcode` (GTIN, for feeds and structured data), `brand` |
| Media | `gallery` (it already adds the gallery strip), `hover_image` (the second picture a card shows on hover, as in the design's "still · hover: worn") |
| Options and variants | `options`: a repeater of up to 3 options (name plus values, e.g. Finish: Gold, Silver). `variants`: a nested repeater with the values, SKU, price override, image and weight. The admin gets a **Generate variants** button. |
| Shipping | `weight_g`, `dimensions`, `ships_separately`, `gift_wrap` (allowed) |
| Stock | edited in the product editor, but **stored in SQLite** `shop_stock` (per product × variant) so concurrent orders can't oversell. Also `track_stock`, `allow_backorder` and `low_stock_at`. |
| Digital | `files` (private files, `kind: download`), `download_limit`, `download_days` |
| Subscription | `interval` (week, month, year), `interval_count`, `trial_days`, `signup_fee`, `renewals` (empty = until cancelled) |
| Story | `short_description`, plus a `details` repeater (title + markdown) that the design draws as an accordion: Details, Care, Shipping & returns, Warranty |
| Links | `collection` and `product_category` (taxonomies), `complete_the_look` (a relation to products), `looks` (a relation to looks) |

**New, generic field flag: `shared: true`.** A field marked this way has one value for every translation: it is written to all language files on save and read from the default-language file. Price, SKU, options, variants, kind, weight and files are shared. Titles and texts stay per language. The flag lives in `FieldSchema` and `ContentEditor`/`EntryForm`, and is unit-tested on its own.

**Looks** (`themes/default/content-types/looks.yaml`) is a small type that comes with the shop. It holds a picture, a title, the products (a relation) and a hotspot position for each product. The design uses a look on the home page, the collection page and the product page, so it is content, not something re-entered in every block.

**Taxonomies:**
- **Collections** (`collections`, hierarchical off) adds to the existing term fields (picture, hero, description, SEO):
  - a chapter number;
  - three tones: background, surface and deep (text and buttons), with the deep tone checked for 4.5:1 contrast through `Contrast`;
  - an intro quote and the quote's author.
  - The term page and every collection-scoped block set `--c-bg`, `--c-surface` and `--c-deep`. Elsewhere the site's base palette applies.
- **Product categories** (`product-categories`): Earrings, Necklaces and so on. These feed the filter chips.

Starting templates: `themes/default/single-templates/products.yaml` (the product page of design 3d) and `themes/default/archive-templates/products.yaml` (the shop list and the collection page of design 3c), built with the blocks in section 9.

## 3. Cart and checkout

- **Cart:** `/_shop/cart` takes JSON add, update and remove calls, protected by CSRF on the shop session. There's a cart drawer (off-canvas, using the existing `admin-panel.js` pattern on the front end) and a `/cart` page. Shipping cost and delivery time show on the product page and in the cart, as the design brief asks. **Add the whole look** puts every product of a look in the cart at once.
- **Checkout** (`/checkout`) is one page with these sections: contact (sign in or create an account; guest if allowed), shipping address, shipping method, gift wrap and gift note, coupon, payment method, terms consent, then **Place order**.
- **Placing an order:**
  1. The server re-prices the cart and checks stock.
  2. It creates the order as `pending_payment` and **reserves stock** for 30 minutes (configurable).
  3. It hands over to the payment provider.
  - A JobRunner job releases reservations that expired unpaid.
- **Order statuses:** pending_payment → paid → processing → shipped → completed. The other states are on_hold (bank transfer, cash on delivery), cancelled, refunded and partially_refunded. Every change is written to `shop_order_events`.
- **Order numbers** are sequential, with a prefix set in settings, e.g. `AL-2026-00042`. The prefix keeps Cardlink's order ids from colliding with the old WooCommerce ids (Cardlink refuses a duplicate `orderid`).
- **Shipping:**
  - zones by country, with an optional postcode-prefix rule for Greek islands;
  - methods: flat rate, free over an amount, weight table, local pickup (a list of stores, e.g. Athens, Glyfada, Kifisia, Paros, Spetses).
- **Taxes:** VAT-inclusive prices, rates per tax class, and the VAT stored on each order line. OSS (rates by EU destination country) is a later setting.
- **Coupons:** percentage, fixed amount or free shipping, with a minimum total, dates, a usage limit, a per-customer limit, and an optional limit to collections or products.

## 4. Customer accounts

- Customers live in a separate `shop_customers` table. They are never admin `users`, have no admin capabilities, and get their own session cookie name.
- Accounts are at `/account`: sign up, sign in, email verification, password reset, profile, addresses, orders (with status and tracking), downloads, subscriptions (cancel, change card), and delete my account (GDPR).
- **Sign-in protection** reuses `LoginThrottle` (with a separate bucket) and `password_hash`.
- **Migration from WooCommerce:** imported customers keep their WordPress phpass hash. On first sign-in it is verified and then rehashed, so nobody has to reset their password.
- Guest orders are tied to an email address. Signing up with the same email takes them over.

## 5. Payments

One interface, `PaymentProvider`, in `src/Shop/Payments/`:

```
id(), label(), capabilities(): [installments, preauth, refund, recurring, apple_pay…]
start(Order): Redirect|PostForm
handleReturn(Request): PaymentResult       // the browser coming back
handleNotification(Request): PaymentResult // server to server, where the provider has one
refund(Payment, cents), charge(SubscriptionToken, cents)   // where supported
check(): string                            // "Check connection" in settings
```

**Shared rules:**
- An order is marked paid **only after** the CMS has confirmed the payment itself, either by asking the provider's API or by checking a signed digest.
- The amount and currency must match the order exactly.
- Every incoming call is logged in `shop_gateway_events` with an idempotency key, so a duplicate notification changes nothing.
- Secrets go in their own `system_meta` document (`shop_secrets`), as `AiSettings` does. They are never sent to the browser, never exported and never logged. A blank input keeps the stored value.
- HTTP calls go through an allow-listed client modelled on `src/AiHttp.php`.
- Card data never touches the site, because both providers use hosted pages (PCI SAQ-A).
- **Test mode** on each provider switches its hosts.

### Viva Wallet (Smart Checkout)

- **Auth:** an OAuth2 client-credentials token from `accounts.vivapayments.com/connect/token` (demo: `demo-accounts.vivapayments.com`), cached until it expires. Basic auth (Merchant ID + API key) is used for refunds, recurring charges and the webhook key.
- **Start:** `POST /checkout/v2/orders` on `api.vivapayments.com` (demo: `demo-api.vivapayments.com`) with:
  - `amount` (cents) and `customerTrns`;
  - `customer` (email, full name, phone, country, request language el or en);
  - `merchantTrns` = the order number, `sourceCode` (from settings) and `maxInstallments`;
  - `allowRecurring` for subscriptions and `preauth` (optional).
  - The visitor is then sent to `/web/checkout?ref={orderCode}`. Cards, Apple Pay, Google Pay, IRIS and installments come with Smart Checkout.
- **Return:** the success URL gets `t` (the transaction) and `s` (the order code). `GET /checkout/v2/transactions/{t}` confirms the status, amount and `merchantTrns`.
- **Webhooks:** at `/_shop/pay/viva/webhook`. A GET answers with the verification key (fetched from `/api/messages/config/token`). A POST takes *Transaction Payment Created* and *Transaction Failed*, and each event is checked against the API again before anything changes.
- **Refunds** (`DELETE /api/transactions/{id}?amount=`) can be full or partial, from the order screen.
- **Recurring:** `POST /api/transactions/{initialTransactionId}` with the amount, for subscription renewals.
- Endpoint names and event ids are checked against developer.viva.com when the class is written. It is tested with a Viva demo account.

### Cardlink (Eurobank/Worldline, Nexi/Alpha, Cardlink)

- **One provider, with an Acquirer setting** that chooses the host: `ecommerce`, `eurocommerce` (Worldline, formerly Eurobank acquiring) or `alphaecommerce` `.cardlink.gr`, each with a `-test` variant. The path is `/vpos/shophandlermpi`. The Merchant ID and shared secret are the ones the WordPress plugin uses today, so the client's account moves as it is.
- **Start:** an auto-submitted POST form with the fields **in the spec's fixed order**:
  - version, mid, lang, orderid, orderDesc, orderAmount, currency;
  - payer email, phone and billing fields;
  - trType: 1 = sale, 2 = pre-auth;
  - installment fields `extInstallmentoffset`/`extInstallmentperiod`, from an amount → maximum installments table in settings;
  - confirmUrl and cancelUrl.
  - `digest = base64(sha256(utf8(concat(values) + sharedSecret)))`.
- **Return:** a POST to `/_shop/pay/cardlink/confirm` or `/cancel`. The digest of the response is checked the same way. The status `AUTHORIZED` or `CAPTURED` means paid; `CANCELED`, `REFUSED` and `ERROR` mean not paid. `paymentRef` and `txId` are stored.
  - Cardlink has no separate server notification, so the return POST is the signal; the digest makes it trustworthy.
  - A JobRunner job checks orders still pending after 1 hour through the VPOS XML status request, so a closed browser doesn't lose a payment.
- **Pre-auth capture and refunds** go through the XML API (an XML digest), from the order screen, when the acquirer allows them.
- **Recurring and tokenization** (stored cards, `extToken…`) need the acquirer to turn them on. They are supported in the subscription phase where the merchant's contract allows them; otherwise subscriptions on Cardlink are not offered.
- The field order, the XML endpoints and the token fields are taken from the current Cardlink spec PDF (developer.cardlink.gr) at build time. The plugin's behaviour is the compatibility reference. It is tested on the `-test` hosts with Cardlink's test cards.

### Offline methods

- **Bank transfer** shows IBANs (several banks), and the order waits as on_hold until it is marked paid.
- **Cash on delivery** has an optional fee and is limited by zone.

## 6. Digital products

- Files are stored outside `public/`, in `storage/shop-files/`.
- After payment the customer gets links to `/account/downloads/{token}`. Each token is signed, tied to the order line, and has a download limit and an expiry date.
- Links appear in the email and under Account › Downloads.
- Files are streamed by PHP with `X-Content-Type-Options` set, and served with `X-Accel-Redirect` where nginx is set up for it.

## 7. Subscriptions

- A subscription product creates a `shop_subscriptions` row with the customer, product, price, interval, next date, the provider token (the initial Viva transaction or a Cardlink token) and the status: active, past_due, cancelled or expired.
- **Renewals** are a daily JobRunner job: it creates a renewal order, charges the token, and marks the order paid or past_due. A failed charge is retried after 1, 3 and 7 days, the customer is emailed each time, and the subscription is then cancelled.
- The customer cancels or updates the card under Account (updating the card means a new checkout with `allowRecurring`). The admin can pause, cancel or skip.
- Viva comes first; Cardlink only where the merchant has tokenization. Members-only content tied to a subscription is a later item.

## 8. Emails, admin screens, permissions

**Emails** are templates per language, editable on Shop settings › Emails:
- order received (with the bank details for a transfer), paid, shipped (with tracking), refunded, cancelled;
- account welcome, verify email, password reset;
- download ready;
- subscription renewed, failed and cancelled;
- a new-order notice to the admin.
- `Mailer` gains an optional HTML part (multipart/alternative) with a simple branded layout; plain text stays.
- Abandoned-cart emails are a later item and need consent.
- **As built (HTML emails and the Emails tab):**
  - `Mailer::send()` takes an optional HTML body: over SMTP it goes as multipart/alternative (the text first, the HTML in base64, inside multipart/mixed when there are attachments); over SES as the Html body. The email log records whether an email had one.
  - `ShopMail` builds each email once as parts (a greeting, paragraphs, a box for the bank accounts or the tracking, the downloads' links, the order with its totals, a button) and renders them as plain text and as HTML: a 600px table layout with inline styles, the shop's colour on the top line and the button, its logo or name, and a footer with the store's name, phone, email and a line of its own. Everything in it is escaped.
  - **Shop settings › Emails:** HTML on or off (off: plain text only), the colour, a logo (a site path or an https address), a footer line, and for each email (order received, paid, shipped, cancelled, refunded, confirm the address, a new password, subscription not renewed or cancelled) its subject and opening text in each language, with its `{placeholders}`; blank keeps the theme's words, shown greyed. Each email has a preview per language (as HTML or `&format=text`, served with a CSP that allows no scripts) and a test sent with a made-up order.
  - Not yet: editing the whole body, attachments such as an invoice PDF.

**The Shop group in the sidebar** (only when the shop is on):
- **Orders:** a list with status chips, search and date and status filters, and CSV export (with VAT columns for the accountant). The order screen shows the timeline, payments, refund, capture, mark shipped (carrier and tracking number), notes, resend email, and a printable packing slip.
- **Customers:** a list and a detail page with their orders, addresses and subscriptions, plus export and delete for GDPR.
- **Subscriptions** and **Coupons.**
- Stock is edited in the product editor, and a **Stock** tab on Orders lists low stock.

**System › Shop settings** (`/admin/shop-settings`), in the condensed admin style. Its tabs:
- **General:** store name and address, currency, order number prefix, reservation minutes, guest checkout, terms and returns pages.
- **Payments:** one card per provider, each with an on switch, test mode, the keys, Check connection, the installments table and a "start here" help box.
- **Shipping:** zones, methods and pickup stores.
- **Taxes.**
- **Emails.**
- **Accounts:** registration, email verification.

**Permissions** (`src/PermissionService.php`, the Roles catalogue):
- `shop.orders`: orders, customers and subscriptions. Given to admins; editors can be given it.
- `shop.manage`: settings and keys. Admins only, and marked sensitive.

## 9. Blocks from the ALALEON design

`BlockRegistry::load()` gets a new key, **`requires: shop`** (generic in form, `requires: {feature: shop}`, so later features can use it too). `all()` and `editorDefinitions()` leave out blocks whose requirement is off, through a closure passed in like `optionSources`. Rendering such a block while the shop is off outputs nothing.

**Generic blocks and variants, for every site:**

| Design element | Becomes |
|---|---|
| The colour "thread" (a five-tone band at the top and above the footer) | `divider` variant **Thread**: up to 6 colours, or the collection tones when the shop is on |
| The five dots | a reusable `dots` partial and an "eyebrow dots" option on quote, text-image and the newsletter |
| Announcement line ("Handmade in Greece…") | a header option: **top line** text per language |
| Centred logo, links left, search/account/cart right | a header layout **Centred logo**; account and cart icons appear when the shop is on |
| Hero 3a (full image, big serif title, two links) | already covered by `hero`; checked against the design and given a **Quiet editorial** preset |
| Hero 3b Atelier (text left, a three-picture collage right) | `hero` variant **Mosaic** |
| Quote band with a second-language line | `quote` variant **Statement**: big centred serif, an optional translation line, dots |
| "Five coasts, five stories" tiles | a new **Term tiles** block: the terms of any taxonomy as numbered tiles with a picture; variants Grid and **List with preview** (3b: a hover list that swaps a large picture) |
| Story split (portrait plus myth text) | `text-image` variant **Story** (eyebrow, serif pull quote, link) |
| Trust strip (four items with dots) | `features` variant **Strip** |
| Newsletter "Letters from the Aegean" | a new **Newsletter** block, a letter-style signup posting to a chosen form (`options_from: forms`); inline and centred variants |
| Collection header "Chapter 02 / Balos / quote" | `archive-title` variant **Chapter**: term number, title, intro quote and author |
| "Next chapter: Kampos →" | a new **Next term** block (archive scope): the next or previous term in the taxonomy's order |
| Filter chips (All, Earrings…) and count/sort | the `filter-bar` variant **Chips** plus the existing `result-count` |
| Hotspots on a worn photo | a new **Image hotspots** block: points on a picture linking to entries or addresses (the shop variant shows the product, price and add button) |

**Shop blocks (`requires: shop`):**

| Block | Used on | Notes |
|---|---|---|
| **Product grid** | home, collection, shop | products by collection, category, hand-picked or related; card with the hover picture, price and "was" price; **an editorial tile every N cards** (a picture with text, as in 3c) |
| **Shop the look** | home, collection, product | one Look: the picture with hotspots, a product list with prices, a total and **Add the look**; variants Split and Complete the look (the compact list on the product page) |
| **Looks** | home | a grid of looks with "3 pieces · €460" (3b "Wear It Together") |
| **Product gallery** (template scope) | product | the 4-picture stack or a thumbnail strip, with zoom |
| **Product summary** (template scope) | product | collection eyebrow, title, price, variant chips, quantity, add to cart, notes line ("Handmade in Greece · Gift wrapping"), shipping estimate |
| **Product details** (template scope) | product | the `details` repeater as an accordion (it reuses the `accordion` styles) |
| **Mini cart** | header, footer | the cart count and link; usually automatic in the header |

All blocks use the theme tokens. Collection tones only change the CSS variables. The design's fonts (Noto Serif Display, Commissioner, IBM Plex Mono) are a site's Branding choice, not hard-coded.

## 10. SEO and migration

- **Structured data:** Product JSON-LD with Offer, price, availability, GTIN and brand in `StructuredData`, and BreadcrumbList on products.
- **Sitemap and feeds:** products and collections go in the sitemap. A Google Merchant XML feed comes later.
- **Addresses:** clean collection URLs (taxonomy addresses already exist).
- **WooCommerce import:** a WordPress importer profile (`docs/wordpress-profiles/`) for products. It maps price, sale price, SKU, gallery, product categories to the taxonomy, variations to variants, and stock to `shop_stock`. It writes 301s for `/product/…` and `/product-category/…`. Customers come across with their phpass hashes. Past orders are imported as read-only history, optionally.
  - **As built:** `scripts/import-woocommerce.php`, on a live site `php src/Cli/import-woocommerce.php` (from 0.1.270; the code is in `src/Cli/` so it ships with releases) (classes `WooCommerceReader`, `WooCommerceImporter`), a script of its own rather than a profile, since products, stock and customers are not posts.
    - **Reading:** with read-only REST keys in `WC_KEY`/`WC_SECRET` (environment only, sent only over https) it reads `/wp-json/wc/v3`: everything, stock quantities, weights, drafts, downloads and each variation's own price, SKU, picture and weight. Without them, the public Store API: prices from minor units, variations by their attribute slugs, no stock quantities or weights.
    - **The dry run** (the default) reads everything and says what it would do: categories, products new, to update or left alone (a file of the site's own), physical and digital, variants, stock, pictures, redirects, customers; and what cannot be carried over (grouped and external products, a variation for "any" value, a product without a price). It writes `woocommerce-report.json`, `report.txt` and `woocommerce-redirects.txt` in `--out`.
    - **`--apply`:** pictures into the library (one copy whatever its size in the address), product categories with their tree (the taxonomy turns hierarchical when they nest) and pictures, products as Markdown (id `woo` + the WooCommerce id, so a second run updates them), stock into `shop_stock`, and with `--redirects=apply` the 301s for `/product/…` and `/product-category/…` (a nested category to its whole path).
    - **Customers:** a CSV of the old site's users (the script's header has the SQL for phpMyAdmin), found by column names. They are made confirmed with the old hash; `ShopCustomers::authenticate()` checks WordPress's portable phpass, its 6.8 bcrypt (`$wp$2y$`) and bare MD5, then hashes the password again. An address that has an account here is left as it is.
    - **Not yet:** past orders as history, product reviews, attributes that are not variations (they stay in the description).
    - **The client's dry run** is theirs to run on a copy of the site, with read keys: the report says what to fix in WooCommerce first.
## 11. Phases (each is one branch, one merge, one VERSION bump)

1. **Catalogue.**
   - Products and looks types, the `shared` field flag, Collections (with tones) and Product categories, `shop_stock`.
   - `requires:` gating, `Shop::enabled()`, the Shop sidebar group, and Shop settings › General.
   - The product page and list starting templates; the Product grid, Product gallery, Product summary and Product details blocks (Add to cart hidden until phase 2).
   - Product JSON-LD.
   - **As built:** the Shop sidebar group waits for phase 3, when Orders and Customers exist; phase 1 adds only System › Shop settings (General). Switching Products on also switches Looks on and writes the two taxonomies; switching it off switches Looks off. Options and variants are written in the site's language and shared; a translation names them in its own `option_labels` ("Gold = Χρυσό"). A sixth block, **Product price**, draws the price on a list's card. Stock is keyed by the product's translation_id and the variant's combination (`ShopStock`). The digital and subscription fields arrive with phases 6 and 7; collection tones are stored and exposed as `--c-bg/--c-surface/--c-deep` (`ShopCatalogue::toneStyle()`), applied to pages with the design blocks of phase 2.
2. **Design blocks (generic):** Thread, Statement, Term tiles, Mosaic hero, Story, Strip, Newsletter, Chapter, Next term, Chips, Image hotspots, the Centred logo header and the top line. These can run in parallel with phase 3, because they touch theme blocks, not shop code.
   - **As built (phase 2):** Thread, Statement, Mosaic, Story, Strip, Chapter, Chips, Term tiles (tiles and a list with a changing picture), Next term, Newsletter and Image hotspots, plus a `components/dots.twig` partial. The header already had both pieces: its *Split* layout is the centred logo, and its top bar text is the announcement line; it gains a **band of colours** above everything (Header › Band of colours). A collection's page and a product in a collection take the collection's tones in light mode (`page_tones()`, `.has-tones`): the content only, never the header, the footer or dark mode, and only when all three tones are set. Twig gains `term_info()` and `taxonomy_terms()`. Image hotspots link to any address; tying a point to a product and its price comes with Shop the look in phase 3.
3. **Cart, checkout, orders, accounts:**
   - sessions on shop paths, carts, checkout, shipping, taxes, coupons;
   - orders and customers admin, emails with the HTML part;
   - bank transfer and cash on delivery; Shop the look, Looks and Mini cart.
   - The shop works end to end with offline payments.
   - **As built (phase 3):**
     - **Pages:** `/cart`, `/checkout`, `/checkout/order/{number}?key=…` and `/account/…`, under each language's prefix (`src/Shop/ShopFront.php`, `templates/shop/*.twig`). While the shop is on these addresses are the shop's, even when a page has the same slug. They have their own session (cookie `gz_shop`), so a shopper's other pages stay cacheable. The cart is found by its own cookie (`gz_cart`, 30 days); the header's count comes from `gz_cart_n` through `shop.js`.
     - **Adding to the cart:** `POST /_shop/cart` is answered as JSON for the script and as a redirect without it. It is checked by its Origin (or Referer), not by a session token, because product pages are cached.
     - **Product pages:** switching the shop on gives Products and Looks their starting templates, unless the site already chose a layout for them; otherwise a product page would have no Add to cart.
     - **Pricing:** prices, variants and stock rules always come from the product in the site's own language; a translation gives only its name, address and option names. Everything is priced again on every view and when the order is placed (`ShopPricing`). A line whose product is gone, not sold or sold out leaves the cart, and a quantity above what is free is lowered; the cart says which. Only physical products are sold until the payment providers arrive.
     - **Money and VAT:** money is in cents (`ShopMoney`). VAT is per line, of what was paid after the line's share of the discount; shipping and fees carry the standard rate.
     - **Shipping:** zones are matched first-come by country, optionally narrowed by postcode prefix. Methods are flat (optionally free over an amount), free over an amount, by weight, or pickup at a store (`ShopShipping`). Free-over thresholds compare the items before the discount.
     - **Stock and statuses:** placing an order holds its stock. An offline payment takes it at once (`on_hold` for a transfer, `processing` for cash on delivery). Cancelling gives the stock back, and the coupon's use too. Orders waiting for an online payment are cancelled when their hold runs out; this is checked whenever Orders is opened (the job comes with phase 4).
     - **Order numbers:** they follow on within a year after the prefix, through `shop_sequences`.
     - **Emails:** plain text in the order's language: order received (with the bank accounts), the shop's notice, paid, shipped (with tracking), cancelled, refunded, confirm the address, a new password. **The HTML part and the Emails tab are deferred**; the texts are theme strings (`shop.mail_*`), editable in Translations.
     - **Accounts:** guest orders join an account only once its address is confirmed by a link (sign-up sends one; setting a new password also confirms). "Confirm before the first sign-in" is a setting, off by default. Sign-in is throttled like the admin's, in its own bucket.
     - **Admin:** the Shop group holds Orders (status chips, search, dates, a CSV with a row per line and its VAT; each order with its timeline, status changes, shipping with tracking, notes, its email again and a packing slip), Customers (with their orders; deleting an account leaves its orders, unlinked) and Coupons.
     - **Shop settings:** gains Shipping, Payments and Accounts.
     - **Payments:** bank transfer and cash on delivery implement `Payments/PaymentProvider`, the shape Viva and Cardlink will fill.
     - **Blocks:** Shop the look (a look's picture with its pieces, Image hotspots' styles, the total and Add the look) and its Complete the look list; Looks; Mini cart. The header shows the account and the cart while the shop is on.
4. **Viva Wallet:** payments, the webhook, refunds and installments.
   - **As built (phase 4):**
     - **The flow:** `Payments/VivaWallet` places a Smart Checkout payment order and sends the customer to Viva's page. The amount is in cents; `merchantTrns` is the order number; it carries the customer, the source code, the most installments for the amount (a table on the Payments tab), and `paymentTimeout` equal to the stock hold. The OAuth token is kept until a minute before it runs out.
     - **The order:** while it waits for payment, its stock is held, not taken (`pending_payment`). The order-received email goes only once it is paid.
     - **Confirming:** Viva sends the customer to `/_shop/pay/viva/success` or `/failure`, the addresses set in Viva's payment source, and calls `/_shop/pay/viva/webhook`. A GET there is answered with the verification key (kept a day); events 1796 and 1798 are taken. Either way the transaction is asked of `GET /checkout/v2/transactions/{t}`. `ShopCheckout::confirm()` pays the order only for that payment's reference, its exact amount in euros and its number, and only once (`shop_payments`); every webhook call is written to `shop_gateway_events`. A mismatch is written on the timeline and changes nothing. A webhook that cannot ask Viva answers 503, so Viva calls again.
     - **Not paid in time:** an order can be paid again from its page (Pay now) while its stock is held, and is cancelled when the hold runs out. This is checked on every shop page and on Shop › Orders. A payment that arrives after cancellation is written on the timeline for the shop to refund.
     - **If Viva can't be reached at checkout:** the order is cancelled at once, with its stock and coupon given back, and the customer is asked to choose another way to pay.
     - **Refunds:** in part or in full, from the order's screen, through `DELETE /api/transactions/{id}?amount=` with the Merchant ID and API key. A full refund makes the order refunded.
     - **Keys:** the API key and Client secret live in `shop_secrets`. A screen sees only their last four characters, and a blank input keeps them. Check connection tries the token and the webhook key.
     - **HTTP:** `ShopHttp` calls only Viva's hosts over https, and falls back to PHP streams where a host switched curl off. Tests point it at a fake on this machine through a `base` secret no screen can set.
     - **Still to check against a real demo account:** endpoints, field names and the refund call were taken from Viva's documented API (and a maintained client library); the real-key check will confirm them.
5. **Cardlink:** the three acquirers, the digest, the pending-order status job, capture and refund via XML, installments. Then a dry run of the client's WooCommerce import on a copy of their site.
   - **As built (phase 5):**
     - **The reference:** Cardlink's own WooCommerce plugin (github.com/Cardlink-SA/cardlink-payment-gateway-woocommerce), since the spec pages need a sign-in. The hosts, the field order, both digests and the XML messages follow it, so a merchant's Merchant ID and shared secret move over as they are.
     - **Acquirers:** Cardlink (`ecommerce.cardlink.gr`), Nexi/Alpha (`www.alphaecommerce.gr`) and Worldline/Eurobank (`vpos.eurocommerce.gr`), each with its `-test.cardlink.gr` host. The Payments tab has the acquirer, Test, the Merchant ID (digits), the shared secret (in `shop_secrets`) and an installments table.
     - **The flow:** placing the order starts a payment under its own Cardlink order id (the order number's letters and digits, then a time mark, so Pay again never repeats one) and sends the customer to `/_shop/pay/cardlink/go?ref=…&k=…`. The `k` is an HMAC of the reference with the shared secret, so no one else's order can be shown there. Where the amount allows installments, the page asks how many; then it posts the form to `/vpos/shophandlermpi` on its own (`shop.js`, `data-shop-autosubmit`), with a button for when scripts are off.
     - **The form:** version 2, mid, lang, orderid, orderDesc, orderAmount, currency, payer, bill and ship fields, trType 1 (sale), `extInstallmentoffset`/`extInstallmentperiod`, confirmUrl, cancelUrl; empty fields left out. The digest is base64(sha256(the values in that order, then the secret)).
     - **The answer:** the bank posts to `/_shop/pay/cardlink/confirm` or `/cancel` from the customer's browser (no session needed). It is believed only when its digest over the fields, in the order they came, is right, the loyalty bonus's own digest too when there is one, and the mid is this shop's. `ShopCheckout::confirm()` then pays the order only for its exact amount and only once. CAPTURED pays; CANCELED, REFUSED and ERROR fail the payment and leave the order waiting with Pay now; AUTHORIZED (a pre-authorisation, which this shop does not ask for) leaves it waiting with a note to capture it in Cardlink's back office.
     - **No answer (the browser closed):** Cardlink calls no address of its own. A provider that implements `ChecksPayments` is asked with an XML `StatusRequest` before its expired order is cancelled. Paid after all: the order is paid. Unknown at the bank: it is let go. The bank not reachable: the order waits and is asked again every ten minutes, for six hours at most, then let go with a note to check the back office.
     - **Refunds:** in part or in full from the order's screen with an XML `RefundRequest`. On the day of the payment, when the bank refuses a refund because it is not settled yet and all of it is given back, a `CancelRequest` (a void) is sent instead.
     - **XML messages:** posted to `/vpos/xmlpayvpos`, signed with base64(sha256(the Message element with its namespaces, then the secret)). Check connection sends a StatusRequest for an order that does not exist: "not found" means the keys work, an error about the digest or merchant means they don't.
     - **Not in this phase:** pre-authorisation and capture (trType 2), IRIS through Nexi, stored cards (tokens, for subscriptions in phase 7), and the paid email for an order recovered by the status check (the timeline shows it; the shop can resend the email from the order's screen). The client's WooCommerce import dry run waits for their copy.
     - **Still to check against a real test account:** the field limits, the status answer's shape and the refund and void answers; the user's demo install on a real domain is where both gateways are checked.
6. **Digital products:** private files and download tokens.
   - **As built (phase 6):**
     - **Selling:** a product whose Kind is Digital product is sold like any other. It has two new shared fields, *Downloads per purchase* (default 5) and *Days to download* (default 30, from the payment); 0 means no limit. Its lines weigh nothing and cannot be gift-wrapped.
     - **Files:** **Shop › Files** (`shop.manage`) uploads a product's files, rather than a field in the product editor. They are kept in `storage/shop-files` under a random name (table `shop_files`), with a `.htaccess` that refuses them should the folder ever be under the web root. A file is at most 512 MB, and the server's upload limit still applies.
     - **The checkout:** a cart of downloads only has no shipping step (one "Download" way, nothing to pay for it), no second address, no gift, and no cash on delivery; the billing address is still asked, in any country the shop knows. A mixed cart is shipped as usual.
     - **Downloads:** when an order becomes paid (or completed, for one paid on delivery), each file of each of its products becomes a row of `shop_downloads` with the product's count and days, once. A cancel or refund turns them off; paying again turns them back on. The order's timeline says how many were given.
     - **The link:** `/_shop/download/{id}-{hmac}`, signed with the shop's own key (`system_meta` `shop_download_key`, made on first use), so the same link works in the email, on the order's page and in the account. It is counted before the file is sent (an atomic update, so two at once cannot pass the limit) and the file goes as an attachment with `nosniff` and a sandboxing CSP. A link used up, past its days or turned off answers 410 with a page that says why; a link that never was answers 404.
     - **Where it shows:** the paid email lists each file with its link and the limits; the order's page and the account (Your downloads) list them with what is left; the order's screen shows the count, the last time, and **Allow again from the start**.
     - **Not in this phase:** `X-Accel-Redirect`/`X-Sendfile` for very large files (PHP streams them), files picked in the product editor itself, a download's own email.
7. **Subscriptions:** Viva recurring first, then Cardlink tokens where enabled.
   - **As built (phase 7):**
     - **The product:** Kind Subscription, with *Charged every* (week, month, year) and *How many of them* (1–12), an optional *Sign-up fee* (with the first payment only) and *Renewals* (0: until cancelled). The product page shows the period and that it is charged again. A subscription is sent nothing (like a download); boxes shipped every period are a later item.
     - **The checkout:** a cart with a subscription needs a signed-in account, and offers only the ways to pay that can charge again (`RecurringPayments`: Viva Wallet). Placing the order makes a subscription per line (`shop_subscriptions`, status pending, linked in `shop_subscription_orders`) and asks Viva with `allowRecurring`.
     - **Starting:** when its first order is paid, the subscription is active, the payment's transaction is its token, and it is next due a period on (the same day of the month, or the month's last day when shorter). A first order never paid cancels it.
     - **Renewals:** `App::shopTick()` runs from the cron script (`runJobs`) and, at most once an hour, from Shop › Subscriptions; it also lets go of unpaid orders. Each due subscription is claimed first (so two runs never charge one period twice), then a renewal order of its own is made with the address of its latest order and charged with `POST /api/transactions/{token}`. Paid: the order is paid and the subscription moves a period on from when it was due. Refused: the order is cancelled, the subscription is past due and tried again after 1, 3 and 7 days, the customer emailed each time; after the fourth refusal it is cancelled and the customer told. With a number of renewals, the last one ends it.
     - **The customer:** the account lists their subscriptions, with Cancel, and **Pay now** for one past due: an order sent to Viva with `allowRecurring`, so the card used becomes the new token.
     - **The shop:** **Shop › Subscriptions** (`shop.orders`) lists them by status; one shows its customer, price, next charge, renewals and orders, with Pause, Resume, Skip the next period and Cancel.
     - **Not in this phase:** Cardlink tokens (stored cards need the acquirer to turn them on), trials, changing the card of an active subscription, shipped subscription boxes, members-only content.
     - **Still to check against a real Viva demo account:** the recurring charge's answer.
8. **Later (ROADMAP):** a myDATA provider, couriers (ACS, ELTA Courier, Geniki, BoxNow lockers), Stripe, PayPal, abandoned cart, the Merchant feed, reviews, wishlist, OSS VAT, members-only content.

## 12. Critical files

**New:**
- `src/Shop/` (Shop, Cart, Checkout, Orders, Customers, Money, Tax, Shipping, Coupons, Stock, Payments/{PaymentProvider, Viva, Cardlink, BankTransfer, CashOnDelivery}, ShopHttp, ShopAdmin, ShopSettings, ShopMail, Subscriptions, Downloads)
- `admin/templates/shop-*.twig`
- `themes/default/content-types/{products,looks}.yaml`
- `themes/default/blocks/{product-grid,product-gallery,product-summary,product-details,shop-the-look,looks,mini-cart,term-tiles,newsletter,next-term,image-hotspots}/`
- `themes/default/templates/shop/{cart,checkout,account*}.twig`
- `public/assets/js/shop.js`
- `docs/shop-plan.md`, `docs/shop.md`

**Changed:**
- `src/App.php` (routes, `needsSession()`, factories, job registration)
- `src/BlockRegistry.php` (`requires`)
- `src/FieldSchema.php`, `src/ContentEditor.php`, `src/EntryForm.php` (`shared`)
- `src/Taxonomies.php`, `src/TaxonomyEditor.php` (collection term fields)
- `src/SystemDatabase.php` (migrations `2026111xxxxx_shop_*`)
- `src/PermissionService.php`
- `src/SiteTwig.php` (`shop_enabled`)
- `admin/templates/base.twig`
- `src/Mailer.php` (HTML part)
- `src/StructuredData.php`
- `src/cron-backup.php` (shop jobs run through `runJobs`)
- `themes/default/blocks/{divider,quote,hero,text-image,features,archive-title,filter-bar}/block.yaml` (new variants)
- `themes/default/components/header.twig`
- `themes/default/lang/{el,en}.php`

**Reused:** `JobRunner`, `AiHttp` (as the model for `ShopHttp`), `LoginThrottle`, `Contrast`, `SystemMetaRepository`, `PageCache`, `Relations`, `ArchiveQuery`, `StartingTemplates`/`StartingArchives`, `WordPressImporter`.

## 13. Verification

- **Unit tests** (`tests/unit/shop-*.php`): Money and VAT rounding, shipping rules, coupons, stock reservation and release, the Cardlink digest (request and response vectors from the spec), Viva response parsing, idempotent events, `shared` fields, `requires` gating.
- **HTTP tests** (`tests/http/shop_test.py`) with a built-in **Fake** provider (test mode only): add to cart → checkout → pay → order paid → stock lowered → email logged. Also an account sign-up and sign-in, the download token limit, and the shop admin screens and sidebar hidden while `products` is off.
- **Real gateways:**
  - a Viva demo account and the Cardlink `-test` host with test cards;
  - the keys are pasted only in a terminal tab (`read -s`), never in chat;
  - success, refusal, cancel, closed-browser recovery and a refund are each checked once.
- **Preview:** the product, collection and home pages built from the new blocks, compared with design Concept v3 (3a, 3c, 3d), on desktop and mobile and in dark mode.
- Run `tests/run.sh` for the touched areas per phase, with CI on push.
