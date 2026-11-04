# Speed: what the CMS does and what a site should turn on

PageSpeed Insights and Lighthouse measure how fast a page arrives and is drawn. Most of it the CMS does by itself; a few
things are settings of the site or of its host, listed under [What a site should turn on](#what-a-site-should-turn-on).

## What the CMS does

**Pictures** (`src/Images.php`)
- Every picture under `/uploads` is offered in sizes from 360 to 2400 px wide (`srcset`), in AVIF where the server's PHP can
  write it and in WebP, so a browser takes the smallest one that fills its place.
- The picture that opens a page (a hero, a title area) loads first (`fetchpriority="high"`); the others load as they come into
  view. In a Hero slider, the pictures of the slides after the first wait until the page has loaded.
- A size is written the first time a page asks for it, right after that page is sent (PHP-FPM, LiteSpeed), and by the cron job.
  Until it exists the page names it by a `/_image/…` address that PHP answers; the page cache keeps such a page for one minute
  only, so it soon names the file itself, which the web server sends.

**Stylesheets** (`src/ThemeAssets.php`, `src/CssMinify.php`)
- The theme's and the site's stylesheets are sent minified; the files under `themes/` and `custom/` stay as they are written.
- The styles of a page's blocks are one stylesheet, and when it is small (up to 32 KB, about 6 KB compressed) it is written into
  the page itself, so the browser waits for one stylesheet, not two, before it draws the page.

**Scripts**
- The theme's script is at the end of the page; block scripts are `defer`. Nothing in the header asks for the size of the page
  while it loads or scrolls (no forced reflows).
- A Google tag or Tag Manager container can load after the page (Admin > Analytics, **Load the tag**).

**Headers** (`src/EarlyHints.php`)
- Every public page sends `Link: …; rel=preload` for its stylesheet and its opening picture. A browser starts on them before it
  reads the page; Cloudflare turns them into **103 Early Hints**, sent while the server is still making the page.
- `Server-Timing` says how long the page took to make (`app;dur=…` in milliseconds), or `cache;desc="page cache hit"` when the site
  sent its own copy. Browsers show it in the developer tools (Network > Timing); `curl -sI https://example.com/` shows it too.

## What a site should turn on

1. **A page cache.** Making a page takes PHP a few hundred milliseconds; sending a kept copy takes a few. Check what answers:
   ```
   curl -sI https://example.com/ | grep -iE 'x-page-cache|server-timing|cf-cache-status|age'
   ```
   - Varnish in front (CloudPanel, see [varnish.md](varnish.md)): nothing else to do.
   - No cache in front: turn on **System > Page cache > The site's own copy**. The answer then says `X-Page-Cache: hit` from the
     second visit to an address on.
2. **The cron job** (`php src/cron-backup.php`, shown under System): besides backups and scheduled entries, it writes the picture
   sizes that are missing. Run it once by hand after an update that changes how pictures are written.
3. **Analytics:** with a Google tag, set **Load the tag** to **After the page has loaded**.
4. **Cloudflare** (when the site is behind it):
   - *Speed > Optimization > Early Hints*: on.
   - Cloudflare does not keep HTML by default (`cf-cache-status: DYNAMIC`). To let it keep public pages, add a *Cache Rule*:
     when the hostname is the site's **and** the path does not start with `/admin`, `/_a/`, `/_omni`, `/_mcp`, `/mcp` or
     `/.well-known/` **and** the request has no `PHPSESSID` cookie: *Eligible for cache*, *Edge TTL: use the cache-control header*
     (the site says `s-maxage=60`), *Browser TTL: respect origin*. Pages then come from the nearest Cloudflare server for up to a
     minute after a change; an editor (signed in, with the cookie) always sees the site itself.
