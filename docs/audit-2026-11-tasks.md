# Audit tasks, November 2026

The tasks from [audit-2026-11.md](audit-2026-11.md), which explains each one. Same ids. Ordered by what to do first inside each
group. Size: S (an hour or two), M (half a day to a day), L (more).

## Do first

- [x] **CI-1** (S) Refresh the skill's snapshot so Tests is green again: `python3 skills/gnoziocms/scripts/refresh_snapshot.py --root .`
      Done before 0.1.307: the Tests run of 0.1.307 is green.
- [x] **CI-2** (S) Find why `tests/run.sh` lists `shift-select` as FAIL with "0 failed (exit 0)", and why the newest run stays queued.
      Gone by 0.1.307: `tests/run.sh js` passes shift-select (8 checks) and CI is green; the queued run cannot be told from here.
- [x] **S1** (S) Never build an emailed link, a gateway's return address or a download link from the `Host` header. With the shop
      on and the site address blank: refuse to turn on a gateway and customer accounts, and show why. Test: "forgot password"
      with a foreign `Host` mails a link to the site's own address or nothing.
- [x] **S2** (S) Keep the Pixel off `/account/*` and off any page whose address carries `key=` or `token=`; for the order's
      page, send the Purchase event with the address cut to its path. Test: the order page with the Pixel on names no key in
      what the script is given.
- [x] **S3** (M) Cardlink `lookup()`: only the bank's "no such order" answer counts as not paid; any other error means "could
      not ask" (try again, as for a network error). Check the digest of the XML answer and its merchant id. Test with a fake
      bank answering an error for a paid order: the order is not cancelled.
- [x] **P1** (S) Turn on Twig's compile cache (`storage/cache/twig`, `auto_reload` on), cleared by an update, a theme change and
      System › Cache. Measure the checkout and one admin screen before and after.

## Security

- [x] **S4a** (M) Limit by address and by target email: sign-up, forgot password, verify again (the `LoginThrottle` table can
      hold them). The answer stays the same either way.
- [x] **S4b** (M) Limit orders placed per address and per cart in a time window; a setting for the number. Count unpaid card
      orders that hold stock, so one visitor holds at most a few.
- [x] **S4c** (S) Send the guest's order confirmation without the customer's free text (note, gift note) for orders not yet
      paid, or strip links from it.
- [x] **S4d** (S) Viva webhook: take an event once by its key before calling Viva, and limit calls per address.
- [x] **S5** (M) A session version on `shop_customers`, kept in the session and raised when the password changes or is cleared:
      other browsers are signed out. Test: reset in one browser signs out the other.
- [x] **S6 + S12** (M) One "trusted proxies" setting. `X-Forwarded-For`, `-Proto` and `-Host` count only when `REMOTE_ADDR` is
      one of them; `clientIpAddress()` and the sign-in throttle use the same helper. Document it for CloudPanel, Varnish and
      cloudflared.
- [x] **S7** (M) Headers: HSTS when the site is on HTTPS (a setting, off by default); a Content-Security-Policy for the admin
      and for the cart, checkout and account pages (report-only first); a Permissions-Policy.
- [x] **S8** (M) Encrypt `shop_secrets` (and the SMTP, S3 and Google secrets) with a key kept in a file outside the database
      and outside backups (`storage/key`), so a backup alone opens nothing. A restore on another server asks for the keys again.
- [x] **S9a** (S) Admin sessions end after a time without use (a setting, 2 hours by default) and after 24 hours in any case.
- [x] **S9b** (L) A second factor (TOTP) for password sign-in, required for roles that hold `shop.manage`, settings or users.
- [x] **S10a** (M) A check in the shop's tick: `reserved` of each stock row equals what open orders hold; put right and log
      when it does not.
      Done in 0.1.334: `ShopStock::reconcile()` from the shop's tick; rows changed in the last 5 minutes wait for the next tick.
- [x] **S10b** (S) A subscription whose `next_at` has been negative for more than an hour is put back and flagged for a look
      (was the card charged?). Send Viva an idempotency reference with each renewal charge if its API takes one.
      Done in 0.1.334: `ShopSubscriptions::recoverStuck()` puts the period back and pauses the subscription for a look. Viva's
      recurring charge takes no idempotency key; `merchantTrns` carries the renewal order's number.
- [x] **S10c** (S) `changeStatus()`: the status, the stock and the coupon in one transaction.
      Done in 0.1.334: `ShopOrders::atomically()`.
- [x] **S11** (S) Sign-up with an address that has an account answers like a new one ("check your email") and mails the owner
      a sign-in link instead.
- [x] **S13** (S) Separate columns (or a small table) for the verify and the reset token.
- [x] **S14** (S) Clear imported WordPress hashes not used after 6 months; those customers set a password by email.
- [ ] **S15** (S) Downloads: answer `Range`, and count a download when it finishes, or only once within ten minutes per link.
- [x] **S16** (S) Coupons: an option "signed-in customers only", which makes the per-customer limit firm.
- [x] **S-next** (L) Review what this audit did not read: the admin screens, MCP, the importers, backups, updates, AI
      publishing, the admin's JavaScript. Done: [audit-2026-11-part-2.md](audit-2026-11-part-2.md); its tasks follow.

### From part 2

- [x] **S17** (M) SVG uploads: parse the file and keep only known elements and attributes (no script, no event attributes, no
      SMIL that sets them, no `style` that imports, links only to `#…`); refuse what does not parse. Send the SVG
      Content-Security-Policy from PHP too where PHP serves the file, and make the nginx line a System status check. Tests
      with the entity-encoded, `<set>` and `@import` cases.
      Done: `SvgSanitizer` on upload; PHP serves no uploaded SVG (theme SVGs already get the policy from `ThemeAssets`);
      System > Status › SVG uploads header asks the site for a probe drawing once a day.
- [ ] **S18** (M) The WordPress and WooCommerce imports fetch through `SafeHttp` (public addresses only, redirects checked).
      A setting or a command-line flag allows a local address for a site being moved on the same server. Test: an address that
      redirects to `127.0.0.1` is refused.
- [x] **S19** (M) Backups encrypted with a passphrase (a setting; kept out of the backup). Restore asks for it. Without one,
      the Backups screen says the archives are plain. Goes with S8.
- [ ] **S20** (M) Signed releases: the build signs `release.json` (Ed25519, libsodium) with a key kept off GitHub; the
      installer and the update check verify it against a public key in the code and refuse an unsigned one. One release
      accepts both, so sites can move over.
- [ ] **S21** (M) Retention: activity log 180 days, email log 90, gateway events 180 (settings), cleared by the daily tick.
      MCP: one activity row per failed token per address per minute, not per request. Unused MCP clients removed after 7 days
      (S23).
- [ ] **S22** (S) MCP: a replaced refresh token used after its grace ends the connection and writes a warning.
- [ ] **S24** (S) Analytics: a daily limit of rows per address, whatever the browser says it is.
- [ ] **S25** (S) Admin Google sign-in: recognise a linked user by `sub` first; an email that matches a user linked to another
      `sub` is refused. `hash_equals` for the state.
- [ ] **S26** (S) The installer gives a one-time code; the first-administrator form asks for it.

## Speed

- [x] **P2** (M) Serve a page-cache hit from `public/index.php` before the application boots, for requests with no session
      cookie; the generation and the key need only the settings file. Measure time to first byte before and after.
- [x] **P3** (S) One "next due at" timestamp for the work after a response, so a visit with nothing due asks nothing. Run the
      forms migration check from the update, not from every request.
- [ ] **P4** (M) Look a product up by its id through the content index and parse that one file; keep the whole-catalogue load
      for listings only. Test with 5,000 generated products.
- [ ] **P5** (S) `ShopFront::add()`: price once and reuse the result for the answer and the Pixel's event; keep a product's
      stock rows for the length of the request; read the customer's orders once on the account page.
- [ ] **P6** (M) At most N picture encodes at once (a lock file per slot); a request over the limit gets the next smaller size
      that exists, or 503 with `Retry-After`.
- [ ] **P7** (S) Downloads through `X-Accel-Redirect` or `X-Sendfile` where a setting says the server has it.
- [ ] **P8** (S) Read the download key once per request; drop `expire()` from every shop request where cron runs (keep it on
      the checkout only).

- [ ] **P9** (S) Admin pages: the due backup, scheduled entries and the AI tick run after the response is sent (as on the
      public site), never before the screen.
- [ ] **P10** (M) Empty the page cache only when what visitors see changed: add the shop's order, customer, coupon and
      subscription screens to the posts that change nothing; saving a draft that stays a draft purges nothing. Test: a cached
      page survives an order's status change and a draft's save, and does not survive a publish.
- [ ] **P11** (S) Form submissions listed from an index (newest N), not by reading every file; MCP's `last_used_at` written at
      most once a minute.

## Bloat

- [ ] **B2** (S) `composer.json`: `replace` the three polyfills, rebuild `vendor/`, drop the hand-loaded `SmartObject` from
      `public/index.php`.
- [ ] **B3** (M) Build the skill's snapshot in `scripts/build-release.php` and stop tracking it; the test then checks that the
      build makes it.
- [x] **B1** (L) Move the shop's wiring and its seven admin handlers out of `App.php` into a `ShopController`, as the first
      step of the App split on the roadmap.
- [x] **B4** (M) Rename what is left of the old name. The update header and `_faros_check` accept both names for one release.
- [ ] **B5** (S) Starter media: resize and recompress the pictures, one small DOCX; aim under 1.5 MB.
- [ ] **B6** (S) Move the finished plans to `docs/plans/` and fix the references in comments.
- [ ] **B7** (S) Gateways: the local test address comes from the injected transport, not from the secrets.
      Left in 0.1.309: the `base` secret also sends the shopper's browser to the fake's checkout page in three HTTP tests, so
      the transport alone cannot replace it; the audit found it safe (local hosts only). Worth it only with those tests reworked.
- [ ] **B8** (S) On this machine: remove the three old worktrees (`git worktree remove`), move `storage/alaleon/` out of the
      checkout.
