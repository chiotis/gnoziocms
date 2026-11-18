# Working through the site's MCP connection

A GnozioCMS site (0.1.185 or later) can have an **MCP server** at `https://<site>/mcp`. When your tools include `site_info`, `create_entry`, `list_products`… from such a server, you are connected to the site itself, as one of its accounts. You cannot run the scripts of this skill there (no files), but every write goes through the admin's own editor code, so history, translations, redirects and caches are handled for you. The repository's `docs/mcp.md` is the full description.

## First call: `site_info`

It gives the site's name, address, languages, time zone and current time, every content type with its fields (kind, what each takes, whether MCP may write it, whether it is **shared** by the translations, the types a relation may point at, a repeater's own fields), the taxonomies, and the connection itself: the account it acts as, its **scopes**, whether it is paused, its **rules** (types and languages it may write, publications left in 24 hours, the byline) and, with the shop scopes, its shop limits. Plan from what it says, not from this file.

## Scopes (ticked by a person on the consent screen)

| Scope | Allows |
|---|---|
| `content:read` | read entries and terms, drafts included |
| `content:write` | write drafts and scheduled entries |
| `content:publish` | publish, schedule and change published entries |
| `media:write` | add pictures to the media library, have the site make a main picture |
| `shop:read` | read products, prices, variants and stock (shop on) |
| `shop:write` | prices, sales, stock, product categories and collections, take products off sale |
| `shop:delete` | delete products and coupons, remove categories and collections |
| `shop:orders` | read orders (customers' names and addresses), move them along, sales figures |

A tool that is missing or refused means the scope or the account's role does not allow it. Say which and that the person can change it in Admin > MCP; never look for a way around it.

## Content tools

| Tool | Use |
|---|---|
| `list_entries`, `get_entry`, `search_entries`, `list_terms` | read. `get_entry` gives the text as Markdown, the fields (a relation as `type/slug`), the translations and `updated_at` |
| `validate_entry` | the arguments of `create_entry`, checked, nothing saved. Use it before a batch |
| `create_entry` | `status` is required: `draft`, `published`, or `scheduled` with `publish_at`. `source_key` (for example the old address) makes a rerun return the entry made before instead of a copy; `find_by_source` looks it up. `translation_of` makes it a translation |
| `update_entry` | changes only what is given; pass `expected_updated_at` from `get_entry` so a change made meanwhile is refused, not overwritten |
| `file_entries` | puts up to 500 entries of one type and language under a term, or takes them out; their other terms stay |
| `add_image_from_url` | a picture from a public address (`url`) or a file from the chat (`file`) into the library, with alt text (required); gives the path for `main_image` or the body |
| `generate_main_image` | the site makes the main picture in its own house style (Manage > AI publishing), in the background. Give `brief`, the subject only, no style words. Do not draw the picture in the chat |

**Writes are strict.** Unlike the files, nothing is silently fixed: an unknown argument, field, term or language, a value a field does not take, a date not `YYYY-MM-DD` are errors, all named at once with what to send instead. Read the error and correct the call. Text is Markdown (HTML is shown as text). Field values:

- a decimal (price) takes a number inside the field's minimum and maximum;
- an image takes a library path (`add_image_from_url` first); a gallery a list of them, replacing the old one;
- a relation takes `type/slug` or a list of them;
- a repeater takes **every row** it keeps (rows replace the old ones), each an object of its fields;
- `""` or `[]` clears a field;
- a **shared** field (a product's price, its variants) changes in every language at once;
- video, file, location, link, icon and tone fields are a person's job in the admin; new taxonomy terms too (except the shop's own, below).

New entries get `generated_by` (marked *MCP* in the admin) and the connection's byline as author. Respect the connection's rules: types and languages it may write, and its daily limit of publications (drafts never count). Create drafts unless the task says publish.

## Shop tools (only while the shop is on)

| Tool | Use |
|---|---|
| `shop_info` | currency, VAT rates, a product's fields, the shop's taxonomies with term ids, the connection's shop limits |
| `list_products`, `get_product` | products with price, "was" price, sale, variants and stock; filters by category, collection, status, kind, words or SKU, stock (out, low, in), on sale |
| `update_prices` | up to 200 changes: `price`, `compare_at_price`, `sale_price` (starts a sale: the current price becomes the "was" price) or `end_sale`, per product or variant. **`dry_run` is the default**: show the person the table, then call again with `dry_run: false` |
| `set_stock` | up to 200 rows: `on_hand`, or `adjust` (+/−, safe while orders come in); a product with variants needs the variant |
| `create_term`, `update_term`, `remove_term` | product categories and collections (names and texts per language, address, parent, picture, SEO texts; a collection's number, tones and quote). A new address keeps the old one working |
| `archive_product` | off sale in every language, prices and stock kept |
| `delete_product` | as Delete in the admin; visitors of its address go to `redirect_to` (the shop by default) |
| `list_coupons`, `save_coupon`, `delete_coupon` | coupons; products by address, collections by id |
| `list_orders`, `get_order` | orders with lines, money, addresses, payments, timeline |
| `update_order` | on hold, processing, shipped (`carrier`, `tracking`), completed, cancelled (gives stock back); `note`, `notify_customer`. **Never paid or refunded**: money stays with a person, refunds are made in Shop › Orders |
| `sales_summary` | what was sold between two days, per day, week or month, and best sellers; names no customer |

Products themselves are entries of type `products`: make and change them with `create_entry` / `update_entry` (fields in `shop_info`, format in `shop.md`). Two rules of the connection always hold: a price may move at most a set share in one change (50% by default, also through `update_entry`), and a set number of shop changes in 24 hours (500 by default). A refusal for either is the person's limit, not a bug.

Orders hold personal data: read only what the task needs and do not copy customers' names, emails or addresses into other places.

## Scheduled jobs

A job that runs on a schedule (a Claude routine, n8n) works through the same tools. It cannot see the page, so: use `source_key` so a rerun never makes duplicates, `validate_entry` before writing, `expected_updated_at` when changing, and stay inside the publication limit `site_info` reports. In Claude, the write tools must be set to *Always allow* or the run stops at the first write.

## When the connection does not work

- No `/mcp` tools at all, or "Couldn't discover OAuth settings" in ChatGPT: the person checks Admin > MCP (on, discovery checks green). On nginx (CloudPanel) ChatGPT needs the vhost block in `docs/mcp.md`.
- `401`: the token expired or was revoked; reconnect. `429`: too many requests; wait as `Retry-After` says.
- Paused connection: `site_info` says so; the person resumes it in Admin > MCP.
