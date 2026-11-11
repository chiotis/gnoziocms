# MCP for the shop: plan

Status: approved by the owner (2026-11-11), with the decisions in section 7.

## 1. Goal

An LLM connected through MCP (Claude, ChatGPT, Codex) can run the whole shop: add, change and remove products and their categories and collections, change prices and sale prices and stock, manage pictures, variants and coupons, and follow and move orders. A person can ask it to "raise all earrings by 10%", "add these 12 products from this supplier sheet", or "mark the orders that left today as shipped and add their tracking numbers". It also has to work for unattended jobs, like stock sync from a supplier feed every night.

The rules stay the ones in `docs/mcp-plan.md`: the site refuses what a connection may not do, the job's prompt doesn't. Everything the LLM reads (supplier pages, feeds, customer notes) is data, never instructions.

## 2. What MCP can do today

Products are a normal content type (`products`), so `create_entry` and `update_entry` already write a product's title, body, status, terms and simple fields. These are refused today:

| Missing | Why |
|---|---|
| `price`, `compare_at_price`, `signup_fee`, variant prices | `decimal` is not in `McpContent::WRITABLE_FIELDS` |
| `gallery`, `hover_image`, variant pictures | `image` and `gallery` are not writable |
| `options`, `variants`, `details` | `repeater` is not writable |
| `complete_the_look`, `looks` | `relation` is not writable |
| Stock | lives in SQLite (`ShopStock`), not in the entry |
| Categories and collections | `list_terms` reads them; nothing creates, edits or removes a term |
| Coupons, orders, customers, sales | no tools |
| Removing anything | "no deletes through MCP" (`docs/mcp-plan.md`, section 3) |

## 3. Design

### 3.1 Generic first: every field type through MCP

Most of the gap isn't shop-specific. `McpContent` gets the remaining field types, validated by the same `FieldSchema::clean` the admin uses:

- `decimal`: a string or number ("12.50"); refused if `clean` changes it.
- `image`: a media id from `add_image_from_url` or the media library, or `""` to clear it. `gallery`: a list of them, in order.
- `repeater`: the whole list, replacing the old one. Nested rows are validated the same way, with the existing depth limit.
- `relation`: a list of `{type, slug}`; each must exist.
- `shared: true` fields are written to every language file, as `ContentEditor` does (one `SharedFields` call, not a copy of its logic).

This helps every content type, not only products. Blogs, places and books get pictures and repeaters through MCP too.

### 3.2 Sale prices

A sale is the existing pair: `price` is what is charged and `compare_at_price` is the "was" price shown struck through. There is no new field. To save the LLM from juggling the two, `update_prices` takes, besides plain `price` and `compare_at_price`:

- `sale_price`: starts a sale. The current price moves to `compare_at_price` (unless one is already set) and `sale_price` becomes `price`. It must be lower than the price it replaces.
- `end_sale: true`: `compare_at_price` moves back to `price` and is cleared.

Variants work the same way. A variant has no `compare_at_price` field today, so it gets one (`decimal`, inside the `variants` repeater); `ShopCatalogue` and the product page show it as they show the product's.

### 3.3 Shop tools

Shown only when the shop is on, and only to connections with the shop scopes (3.4).

| Tool | What it does |
|---|---|
| `shop_info` | Currency, tax classes, product kinds, the product fields with their types, the shop's taxonomies with their extra term fields (collection tones), order statuses and the moves allowed between them |
| `list_products` | Filters: category, collection, status, kind, search, low stock, out of stock, price range. Each row: address, title, price, sale (was) price, stock, status |
| `get_product` | Everything: all fields, variants with their keys, stock per variant, pictures with URLs, terms, `updated_at` |
| `create_product` / `update_product` | Thin wrappers over `create_entry` / `update_entry` with a product-shaped schema (price, sale price, variants…), so the LLM doesn't have to guess field names. `expected_updated_at` on updates |
| `update_prices` | A batch of up to 200 `{product, variant?, price?, compare_at_price?, sale_price?, end_sale?}` (3.2). `dry_run: true` (the default) returns the before/after table and changes nothing; the job calls it again with `dry_run: false`. One revision per product |
| `set_stock` | `{product, variant?, on_hand}` or `{…, adjust: +5}`, up to 200 a call, through `ShopStock::setOnHand`, so reservations and orders in flight are respected. Also `track_stock`, `allow_backorder`, `low_stock_at` |
| `create_term` / `update_term` / `remove_term` | For a taxonomy the connection may write (product categories, collections, and blog taxonomies if the policy allows them): labels per language, slug, parent, description, picture, collection tones (with the `Contrast` check the admin runs). `remove_term` behaves as the admin does since 0.1.274: entries filed under it are unfiled, and the answer lists them |
| `archive_product` | Takes a product off sale without deleting it: status draft, kept with its history. The safe "remove" |
| `delete_product` | Real removal, as the admin's Delete (redirects included). Only with `shop:delete` (3.4) |
| `list_coupons` / `save_coupon` / `delete_coupon` | Over `ShopCoupons` |
| `list_orders` / `get_order` | Filters: status, date range, customer email, number. Lines, totals, shipping, payment state, events. No card data exists to leak; customer details are shown as they are in the admin |
| `update_order` | Status along the allowed moves only (paid → processing → shipped → completed; cancel only before shipping), tracking number and carrier, internal note, customer note (sends the existing order email). Each change goes into `shop_order_events` with the connection as the actor |
| `sales_summary` | Totals per day, week or month, per product or category: orders, units, revenue, VAT, discounts |

Not through MCP, whatever the scope: refunds, captures and anything else that moves money; payment keys and gateway settings; customer passwords; deleting a customer (GDPR erasure stays a person's act in the admin); exporting the customer list.

### 3.4 Scopes and limits

New scopes on the consent screen, all unticked by default:

| Scope | Allows |
|---|---|
| `shop:read` | `shop_info`, `list_products`, `get_product`, `list_coupons`, `sales_summary` |
| `shop:write` | Products, prices, sale prices, stock, terms, coupons, `archive_product` |
| `shop:orders` | `list_orders`, `get_order`, `update_order` |
| `shop:delete` | `delete_product`, `remove_term`, `delete_coupon` |

As today, what a call may do is the scope ∩ the role of the connection's account. Shop managers get the `shop.*` capabilities; editors don't.

The connection's policy (Admin › MCP) gets shop limits:
- **Price change limit**: the most a single price may move in one call, as a percent (default 50%). A bigger change is refused with the reason, so a misread "10" vs "1000" can't zero or explode a price. 0 = no limit.
- **Changes a day**: product, price and stock writes count against a separate daily limit (default 500), apart from the publication limit.
- Every change lands in the revision history and the MCP log, as entries do today.

### 3.5 Where the code goes

- `src/McpContent.php`: the generic field types (3.1).
- `src/McpShop.php` (new): the shop tools. It uses `ShopCatalogue`, `ShopStock`, `ShopOrders`, `ShopCoupons` and `Taxonomies` and copies none of their rules.
- `src/McpTerms.php` (new, or inside `McpContent` if it stays small): the term tools, generic for every taxonomy.
- `McpOAuth::scopes()`, `McpStore::policy()`, `McpAdmin`: scopes and limits.
- `themes/default/content-types/products.yaml`: the variant `compare_at_price` column.
- Tests: `tests/mcp-shop` (HTTP) and unit tests for each new field type, the price guard and the order moves.
- `docs/mcp.md`: the new tools.

## 4. Order of work

Each step is a merge and a version of its own.

1. **All field types through MCP** (3.1). Products become fully writable through `update_entry`, except stock.
2. **Sale prices on variants** (3.2), in the admin and on the product page.
3. **Shop scopes, `shop_info`, product and stock tools, `update_prices`** with the price guard.
4. **Term tools**, then `archive_product` / `delete_product`.
5. **Orders and coupons.**
6. **`sales_summary`.**
7. A real run on the demo shop: Claude adds a product with variants and pictures from a supplier page, puts a category on sale by 10% with a dry run first, ends the sale, sets stock, and ships a demo order.

## 5. Risks

- **Cache:** a price change must clear the product page, its archives and the feed, as a save in the admin does. The tools call the same `afterWrite` hook.
- **Prices in flight:** checkout re-prices from the content, so a change applies to carts at once. An order already placed keeps its prices.
- **Concurrent edits** with a person in the admin: `expected_updated_at` on every product write.
- **Prompt injection** from supplier pages: the price guard, the daily limit and the dry run on bulk prices limit the damage; deletes need a scope the owner ticks on purpose.

## 6. Not in this plan

Looks (hotspots on a picture) and shipping/tax settings. They can follow when someone asks.

## 7. Decisions (owner, 2026-11-11)

1. **Removing:** `archive_product` comes with `shop:write`. Real deletes of products, terms and coupons need the separate `shop:delete` scope, off by default.
2. **No cost field.** "Cost" meant price and sale price (3.2).
3. **Orders:** status, tracking and notes through MCP. Refunds and other money moves stay in the admin.
4. **Price guard:** 50% per call by default, changeable per connection.
