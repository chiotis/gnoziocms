# Audit work plan, November 2026

How the tasks of [audit-2026-11-tasks.md](audit-2026-11-tasks.md) are handed to agents. The findings behind them are in
[audit-2026-11.md](audit-2026-11.md). The work is cut into 21 packages; each package is one agent, one branch, one merge.

## How the packages fit together

Two agents never write the same file at the same time. So the packages are in **lanes**: inside a lane they run one after
another, in the order given, each starting from `main` after the one before it is merged. Different lanes run at the same time.

| Lane | Owns | Packages, in order |
|---|---|---|
| 0. Gate | the test suite | W00 (before anything else) |
| A. Request edge | `src/App.php`, `public/index.php`, `src/SiteSettings.php`, `src/Auth.php`, `src/PageCache.php` | W01 → W02 → W03 → W04 → W05 → W06 → W07 → W08 |
| B. Shop | `src/Shop/ShopFront.php`, `ShopCheckout.php`, `ShopCustomers.php`, `ShopCoupons.php`, `ShopStock.php`, `ShopSubscriptions.php`, `ShopDownloads.php`, `ShopCatalogue.php`, `ShopMail.php` | W09 → W10 → W11 → W12 → W13 |
| C. Singles | one area each, named in the package | W14, W15, W16, W17, W18, W19, W20 (any order, all at once) |
| D. Reading | nothing (read-only) | W21 (any time) |

Rules between lanes:

- **W00 first.** Nobody starts while Tests is red: a red suite hides what a package breaks.
- **W08 last of all** (it moves the shop's code out of `App.php`): after lane B is finished and W01–W07 are merged.
- **W18 before W05**: both edit `public/index.php`.
- A lane B package may add one line to the shop's wiring in `App.php` (`shopServices()`, `handleShop()`) when it has to pass
  something new in. Nothing else in `App.php`. If lane A has an open branch then, say so in the report so the merge is watched.
- `src/SystemDatabase.php`: new migrations are added at the end of `migrate()`, with today's date in the id. Two packages
  adding one each will conflict in that one place; the later merge keeps both.
- `docs/security.md` is edited by many packages: each adds or changes only its own paragraph.

What to start first, when there are four agents: **W00**, then **W01** (lane A), **W09** (lane B), **W14, W15, W16** (singles).
Together those are all six "Do first" tasks, plus the first shop package.

## The same for every agent (paste above each brief)

> You work in the GnozioCMS repository. Read `AGENTS.md` first and follow it.
>
> - Work in your own git worktree, on a branch named `audit/<package id>-<short name>` cut from the current `main`. Other
>   agents work in the same repository at the same time; never commit to `main`, never switch another checkout's branch.
> - Leave `VERSION` and `CHANGELOG.md` alone. Whoever merges bumps them.
> - Read your tasks in `docs/audit-2026-11-tasks.md` and the finding behind each in `docs/audit-2026-11.md` before you read
>   code. The finding names the file and the function.
> - Edit only the files your brief says you own. If the fix needs another file, stop and report it instead of editing.
> - Run `php -v` first (8.5 or newer). Write a test that fails before your change and passes after, in the test file your brief
>   names. Run the tests your brief names, and say exactly what you ran and what it printed. A test you could not run is
>   reported as not run, never as passed.
> - Code stays generic: nothing for one client site. Comments and messages in the plain style of the code around them.
> - If your change alters what `docs/security.md` or another doc says, change that paragraph too.
> - Tick your tasks in `docs/audit-2026-11-tasks.md`.
> - Do not merge and do not push. End with a report: what changed (files), what you tested and the output, what you left
>   undone and why, anything you found that is not in the audit.

## For whoever merges

One package, one merge to `main`: bump the third digit of `VERSION`, add the `CHANGELOG.md` entry, push (the release is
published by the push). Read the agent's diff against its "files owned" list first; a file outside the list is a question to
ask before merging. After a lane A or lane B merge, the next package of that lane may start.

---

## Lane 0. Gate

### W00. Tests green again
- **Tasks:** CI-1, CI-2. **Size:** S.
- **Owns:** `skills/gnoziocms/` (the snapshot), `tests/run.sh`, `tests/js/shift-select.test.js`, `.github/workflows/tests.yml`.
- **Do:** refresh the skill's snapshot (`python3 skills/gnoziocms/scripts/refresh_snapshot.py --root .`). Find why the runner
  prints `FAIL shift-select 0 failed (exit 0)` and fix the runner or the test. Say why run 43095585227 stayed queued, if it
  can be told from GitHub.
- **Done when:** `tests/run.sh skill` and `tests/run.sh shift-select` pass locally, and the Tests run of the merge is green.

## Lane A. Request edge (in this order)

### W01. What the site trusts from a request
- **Tasks:** S1, S6, S12. **Size:** M.
- **Owns:** `src/App.php` (`getBaseUrl`, `buildAbsoluteUrl`, `clientIpAddress`, `isHttpsRequest`, `handleMcp`'s address),
  `src/SiteSettings.php`, `src/LoginThrottle.php`, the Settings template that holds the site address, `src/Shop/ShopSettingsAdmin.php`
  (the refusal only), `docs/security.md`.
- **Do:** a "trusted proxies" setting (addresses or ranges, empty by default). `X-Forwarded-For`, `-Proto` and `-Host` count
  only when `REMOTE_ADDR` is trusted; one helper gives the client's address and everything uses it. An address for an email, a
  gateway or a download is never built from `Host`: with the site address blank, the shop's gateways and customer accounts
  cannot be turned on, and the screen says why.
- **Done when:** a test shows "forgot password" sent with a foreign `Host` mails no link to that host; a test shows a forged
  `X-Forwarded-For` does not change the address MCP limits by. Docs say how to set the proxies for CloudPanel, Varnish and
  cloudflared.
- **Tests:** `site-settings`, `settings-and-front`, `mcp`, `mcp_oauth_test`, `login-controller`, `shop_test`, `shop-emails`.

### W02. Security headers
- **Tasks:** S7. **Size:** M. **After:** W01.
- **Owns:** `src/App.php` (`sendSecurityHeaders`), `src/SiteSettings.php` (the new settings), their Settings template,
  `docs/security.md`.
- **Do:** HSTS as a setting, off by default, sent only over HTTPS. A Content-Security-Policy for the admin and for the cart,
  checkout and account pages, report-only at first, that the pages pass as they are (Viva's and Cardlink's form posts, Google
  sign-in, the Pixel). A Permissions-Policy.
- **Done when:** tests assert the headers on an admin page, the checkout and an ordinary page; the checkout and one admin
  screen load in a browser with no report-only violation in the console.
- **Tests:** `public-site`, `admin-screens`, `shop_checkout_test`, `page_cache_test`.

### W03. Admin sessions end
- **Tasks:** S9a. **Size:** S. **After:** W02.
- **Owns:** `src/Auth.php`, `src/App.php` (where the session is refreshed), `src/SiteSettings.php`, `docs/security.md`.
- **Do:** a session ends after a time without use (a setting, 2 hours by default) and 24 hours after sign-in in any case. An
  expired session goes to the sign-in page and comes back to where it was.
- **Done when:** a test with a moved clock shows both limits.
- **Tests:** `login-controller`, `accounts`, `roles_test`.

### W04. Secrets encrypted at rest
- **Tasks:** S8. **Size:** M. **After:** W03.
- **Owns:** `src/Shop/ShopSecrets.php`, a new `src/SecretBox.php`, the places in `src/SiteSettings.php` and `src/App.php` that
  read or write the SMTP, SES, S3, Google and update secrets, `src/BackupService.php` (leaving the key file out), `docs/security.md`,
  `docs/backups.md`.
- **Do:** a key in `storage/key` (made on first use, mode 0600, never in a backup, never in an update package). Secrets are
  encrypted with it (libsodium, or OpenSSL AES-256-GCM); a value stored as plain text is read as it is and encrypted on the
  next save. A restore without the key shows each secret as "to be entered again", and nothing fails.
- **Done when:** tests cover read of old plain values, round trip, a restore without the key, and that a backup archive has no
  key file.
- **Tests:** `backup-manager`, `backup-admin`, `site-settings`, `shop-viva`, `shop-cardlink`, `update-installer`.

### W05. A cached page without booting the application
- **Tasks:** P2, P3. **Size:** M. **After:** W04 and W18.
- **Owns:** `public/index.php`, `src/PageCache.php`, `src/App.php` (`handle`, `runScheduledAfterResponse`, the forms
  migration call), `docs/performance.md`.
- **Do:** for a GET with no session cookie, look for the cached page before `new App`; a hit sends it and ends. The key and
  the generation must be the same as today's, read without the application. One "next due at" time decides whether the work
  after a response asks anything. The forms migration check runs from the update, not from each request.
- **Done when:** every existing page-cache test passes unchanged; a new test shows a hit loads no `App`; time to first byte of
  a hit is given before and after (same machine, PHP built-in server, 50 requests, median).
- **Tests:** `page-cache`, `page_cache_test`, `front-cache`, `scheduled_test`, `backup_schedule_test`, `form_translation_test`,
  then the full suite (shared code).

### W06. A second factor for the admin
- **Tasks:** S9b. **Size:** L. **After:** W05.
- **Owns:** `src/LoginController.php`, `src/SignIn.php`, `src/Auth.php`, `src/UserRepository.php`, `src/UserAdmin.php`, a new
  `src/Totp.php`, the sign-in and profile templates, a migration, `docs/security.md`.
- **Do:** TOTP (RFC 6238) for password sign-in, set up from the profile with a QR code and recovery codes (hashes kept). The
  super admin can require it for roles; a required role without it is taken to set-up after sign-in. Google sign-in is left as
  it is. Attempts are throttled like passwords. A super admin can clear another user's second factor; it is logged.
- **Done when:** tests cover set-up, a right and a wrong code, a used recovery code, the requirement, and the throttle.
- **Tests:** `login-controller`, `accounts`, `first-admin`, `roles_test`, `automation_accounts_test`, `fresh_install_test`.

### W07. The old name
- **Tasks:** B4. **Size:** M. **After:** W06.
- **Owns:** every place the old name is left (`grep -ri faros src themes admin public/assets`), except the changelog and old
  docs.
- **Do:** rename. The update header (`X-Faros-Update`) and `_faros_check` accept both names in this release, so a site
  updating from the version before still passes its check; a comment says when the old ones can go. Browser storage keys are
  read under the old name once and written under the new.
- **Done when:** the grep finds only the two compatibility lines and history; an update from the previous release passes.
- **Tests:** `update-installer`, `update_install_test`, `omni_update_test`, `brand-compatibility` (js),
  `namespace-compatibility`, then the full suite.

### W08. The shop out of `App.php`
- **Tasks:** B1. **Size:** L. **After:** everything in lanes A and B.
- **Owns:** `src/App.php` (the shop's parts), a new `src/Shop/ShopController.php`.
- **Do:** move the shop's wiring (`shopServices`, `shopRoute`, `handleShop`, `shopGoogle`, `shopTick`, the feed) and its seven
  admin handlers into one class that `App` calls. No change in behaviour, addresses or permissions.
- **Done when:** `php scripts/check-permissions.php` prints the same as before; every shop test passes unchanged; `App.php` is
  shorter by what moved.
- **Tests:** every `shop*` and `mcp_shop*`/`mcp_orders*` test, `roles_test`, then the full suite.

## Lane B. Shop (in this order)

### W09. Limits for anonymous visitors
- **Tasks:** S4a, S4b, S4c, S4d, S11. **Size:** M to L.
- **Owns:** `src/Shop/ShopFront.php`, `src/Shop/ShopCheckout.php` (`place` only), `src/Shop/ShopMail.php`,
  `src/Shop/ShopSettings.php` and `ShopSettingsAdmin.php` (the new numbers), `src/LoginThrottle.php` (if it needs a general
  counter; if W01 is open on that file, add a new class instead), the shop's account template and strings, `docs/security.md`.
- **Do:** limits by address and by target email for sign-up, forgot password and verify again, with the same answer whether
  limited or not. Orders per address and per cart in a window (settings); at most a few unpaid card orders holding stock per
  visitor. The guest's confirmation of an order not yet paid carries no free text from the form. The Viva webhook takes an
  event once by its key before it calls Viva. Sign-up with an address that has an account answers like a new one and mails
  the owner instead.
- **Done when:** one test per limit (reached, then refused, then free again after the window); a test that sign-up no longer
  tells whether an address exists.
- **Tests:** `shop-checkout`, `shop_checkout_test`, `shop_test`, `shop-emails`, `shop_emails_test`, `shop-viva`, `shop_viva_test`.

### W10. Customer accounts
- **Tasks:** S5, S13, S14, S16. **Size:** M. **After:** W09.
- **Owns:** `src/Shop/ShopCustomers.php`, `src/Shop/ShopFront.php` (`signIn`, `customer`, the reset and verify branches),
  `src/Shop/ShopCoupons.php`, the coupon form's template, `src/SystemDatabase.php` (one migration), `src/Shop/WooCommerceImport.php`
  (only if the hash's date is needed).
- **Do:** a session version on the customer, raised when the password changes or is cleared, checked on each request. Separate
  storage for the verify and the reset token. Imported WordPress hashes unused for 6 months are cleared by the shop's tick.
  A coupon option "signed-in customers only", which then counts uses by customer.
- **Done when:** tests: a reset in one session signs out another; "verify again" leaves a waiting reset link working; an old
  imported hash is cleared and a fresh one is not; the coupon option holds for a second order from another address.
- **Tests:** `shop-checkout`, `shop_test`, `shop_checkout_test`, `woocommerce-import`.

### W11. Orders that survive a crash
- **Tasks:** S10a, S10b, S10c. **Size:** M. **After:** W10.
- **Owns:** `src/Shop/ShopCheckout.php`, `src/Shop/ShopStock.php`, `src/Shop/ShopSubscriptions.php`.
- **Do:** `changeStatus()` writes the status, the stock and the coupon in one transaction. The tick compares each stock row's
  `reserved` with what open orders hold, puts it right and logs it. A subscription claimed (negative `next_at`) for more than
  an hour is put back and marked for a look, with an event on its last order; it is not charged again on its own.
- **Done when:** tests: a failure thrown in the middle of `changeStatus()` leaves nothing half done; a stock row with a stray
  reservation is put right; a stuck subscription is put back and flagged.
- **Tests:** `shop-checkout`, `shop-subscriptions`, `shop_subscriptions_test`, `shop_checkout_test`, `mcp_orders_test`.

### W12. Downloads
- **Tasks:** S15, P7, P8 (the key read once). **Size:** S to M. **After:** W11.
- **Owns:** `src/Shop/ShopDownloads.php`, `src/Shop/ShopFront.php` (`download`), `src/Shop/ShopSettings.php` (one setting),
  `docs/shop-demo-checklist.md` or the shop's doc for the server lines.
- **Do:** answer `Range`. A link counts once within ten minutes, so a resumed or retried download is not a second one. A
  setting hands the file to the web server (`X-Accel-Redirect` or `X-Sendfile`); the nginx lines are in the docs. The signing
  key is read once per request.
- **Done when:** tests: a ranged request gets 206 and the right bytes; two requests in ten minutes count once; with the
  setting on, the header is sent and no body.
- **Tests:** `shop-downloads`, `shop_downloads_test`.

### W13. The shop's speed
- **Tasks:** P4, P5, P8 (expiry on every request). **Size:** M. **After:** W12.
- **Owns:** `src/Shop/ShopCatalogue.php`, `src/Shop/ShopFront.php` (`add`, `cartSummary`, the account's home),
  `src/Shop/ShopStock.php` (a per-request memory), `src/Shop/ShopPricing.php`; one line in `App::handleShop()`.
- **Do:** one product by its id through the content index, parsing that file only. `add()` prices once and reuses it. Stock
  rows of a product are kept for the request and dropped on any write. The account page reads the orders once. Expired orders
  are looked for on the checkout and by the tick, not on every shop request.
- **Done when:** a script that makes 5,000 products shows add-to-cart and the cart's JSON before and after (median of 20); no
  shop test changes its expectations.
- **Tests:** `shop-catalogue`, `catalogue_test`, `shop-checkout`, `shop_test`, `shop_checkout_test`, `shop-marketing`.

## Lane C. Singles (all at once)

### W14. The Pixel sees no keys
- **Tasks:** S2. **Size:** S.
- **Owns:** `themes/default/layouts/base.twig` (the Pixel's lines), `themes/default/assets/js/shop-pixel.js`,
  `themes/default/templates/shop/`.
- **Do:** no Pixel on `/account` pages or on a page whose address has `token=`. On the order's page the address is cut to its
  path (no `key`) before Meta's script loads, and the Purchase event still goes once.
- **Done when:** a test renders the order's page with the Pixel on and finds the key nowhere in what the script reads; the
  reset page has no Pixel tag.
- **Tests:** `shop-marketing`, `shop_marketing_test`, `shop_checkout_test`.

### W15. Cardlink's answers
- **Tasks:** S3, B7. **Size:** M.
- **Owns:** `src/Shop/Payments/Cardlink.php`, `src/Shop/Payments/VivaWallet.php` (the test address only),
  `src/Shop/ShopServices.php` (passing the transport), their tests and fakes.
- **Do:** in `lookup()`, only the bank's own "no such order" answer means not paid; any other answer without a status is
  "could not ask", which the checkout already retries. Check the digest and the merchant id of the XML answer. The address of
  a local fake comes from the injected transport, not from the secrets.
- **Done when:** tests with the fake bank: an error answer for a paid order leaves the order waiting; a wrong digest is
  refused; "no such order" still lets the order go.
- **Tests:** `shop-cardlink`, `shop_cardlink_test`, `shop-viva`, `shop_viva_test`, `shop-checkout`.

### W16. Twig's cache
- **Tasks:** P1. **Size:** S.
- **Owns:** `src/SiteTwig.php`, the cache-clearing code of System › Cache and of `src/UpdateInstaller.php`, `docs/performance.md`.
- **Do:** compile to `storage/cache/twig` with `auto_reload` on; fall back to no cache when the folder cannot be written. The
  cache is cleared by an update, a change of theme and System › Cache, and it counts in the storage figures like the others.
- **Done when:** a test shows the second render compiles nothing and an edited template is picked up; the checkout and one
  admin screen are timed before and after (median of 50) and the numbers are in the report.
- **Tests:** `site-twig`, `twig-site-functions`, `storage_cache_test`, `storage_test`, `update-installer`, then the full suite.

### W17. Picture sizes, a few at a time
- **Tasks:** P6. **Size:** M.
- **Owns:** `src/Images.php`, `docs/performance.md`.
- **Do:** at most N encodes at once (lock files, N from the settings, 2 by default). A request over the limit is sent the next
  smaller size that exists, or 503 with `Retry-After` and `no-store`. Each wait has a deadline.
- **Done when:** a test holds the slots and shows the fallback and the 503; nothing is cached for either.
- **Tests:** `image-variants`, `image-width`, `media_test`, `public-assets`.

### W18. Polyfills out
- **Tasks:** B2. **Size:** S.
- **Owns:** `composer.json`, `composer.lock`, `vendor/`, `public/index.php` (the `SmartObject` lines), `scripts/build-release.php`
  if it lists vendor paths.
- **Do:** `replace` the three Symfony polyfills, rebuild `vendor/` the way the repository keeps it (its own `autoload.php`),
  remove the hand-loaded `SmartObject` if the autoload now finds it.
- **Done when:** the three folders are gone, no file requires them, the release package builds.
- **Tests:** `release-package`, `namespace-compatibility`, `installer`, `fresh_install_test`, then the full suite.

### W19. The skill's snapshot made by the build
- **Tasks:** B3. **Size:** M. **After:** W00.
- **Owns:** `skills/`, `scripts/build-release.php`, `tests/unit/skill.php`, `.gitignore`, `.github/workflows/release.yml`.
- **Do:** the snapshot is made when the release is built and is no longer tracked; the test checks that the build makes it
  and that it matches the theme. Say in the report how the skill is installed from a release after this.
- **Done when:** adding a block needs no second step, and `tests/run.sh skill` passes on a clean checkout.
- **Tests:** `skill`, `release-package`.

### W20. Housekeeping
- **Tasks:** B5, B6. **Size:** S.
- **Owns:** `starter/uploads/`, the finished plans in `docs/`, comments that name them.
- **Do:** recompress the starter's pictures and replace the two DOCX files with one small one (under 1.5 MB in all, the
  starter's content still pointing at files that exist). Move the finished plans to `docs/plans/` and fix every reference.
- **Done when:** `grep -r "docs/.*-plan.md" src docs` finds only the new paths.
- **Tests:** `starter-content`, `demo_content_test`, `release-package`.

## Lane D. Reading

### W21. The second audit
- **Tasks:** S-next. **Size:** L. Read-only, any time.
- **Owns:** one new file, `docs/audit-2026-11-part-2.md`, and new lines at the end of the tasks file.
- **Do:** the same review for what the first did not read: the admin screens and their permissions, MCP (`McpServer`,
  `McpOAuth`, `McpContent`, `McpShop`, `McpShopOrders`), the importers, backups and restore, updates, AI publishing, uploads,
  the admin's JavaScript. Each finding with the file, the line, how it was confirmed, and a task with a size. No code changes.
- **Done when:** the report says for each area what was read and what was not.

## Packages from part 2

W21 is done ([audit-2026-11-part-2.md](audit-2026-11-part-2.md)). Its tasks make six more packages. Briefs are short here;
the finding in part 2 names the file and the function.

| Package | Tasks | Lane | Owns | Tests |
|---|---|---|---|---|
| **W22.** SVG uploads | S17 | C (single) | `src/MediaLibrary.php`, `src/SystemStatus.php`, `docs/security.md` | `media_upload_test`, `media_test`, `upload_limits_test`, `system-status` |
| **W23.** Importers through `SafeHttp` | S18 | C (single) | `src/WordPressHttp.php`, `src/SafeHttp.php`, `src/Cli/` | `wordpress-import`, `woocommerce-import`, `importer-media`, `import_test`, `mcp-images` |
| **W24.** Retention and MCP clean-up | S21, S22, S23, P11 (MCP part) | C (single) | `src/ActivityLogRepository.php`, `src/EmailLogRepository.php`, `src/McpStore.php`, `src/McpServer.php`, `src/Shop/ShopPayments.php`; one line in the daily tick | `mcp`, `mcp_oauth_test`, `mcp_tools_test`, `logs_test` |
| **W25.** Signed releases | S20 | C (single), your key needed | `scripts/build-release.php`, `.github/workflows/release.yml`, `src/UpdateService.php`, `src/UpdateInstaller.php`, `installer.php` | `release-package`, `update-installer`, `update-source`, `update_install_test`, `installer_test` |
| **W26.** Encrypted backups | S19 | A, right after W04 (same key handling) | `src/BackupService.php`, `src/BackupManager.php`, `src/BackupAdmin.php`, `src/S3BackupStorage.php`, `docs/backups.md` | `backup-manager`, `backup-admin`, `backup_remote_test`, `backup_schedule_test` |
| **W27.** Admin edge, small things | S24, S25, S26, P9, P10, P11 (forms part) | A, right after W05 | `src/App.php` (`handleAdmin`, the list of posts that change nothing), `src/LoginController.php`, `src/AnalyticsStore.php`, `src/FormSubmissionRepository.php`, `installer.php` (after W25) | `page_cache_test`, `front-cache`, `login-controller`, `analytics`, `forms`, `first-admin`, `fresh_install_test` |

W25 needs a decision first: where the signing key lives and who can sign (it must not be a GitHub secret, or S20 is not closed).

## Not for an agent

- **B8**: remove the three old worktrees and move `storage/alaleon/` out of the checkout. On this machine, by hand.
- **Real keys**: W15 and W09 end with a payment on a demo shop with real test keys (Viva demo, Cardlink test), as the shop's
  checklist already asks.
