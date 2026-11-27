# A cache in front of the site (Varnish)

Public pages that a visitor sees without a session say `Cache-Control: public, max-age=0, s-maxage=60`, so a cache in front of the
site may keep them. Whatever is saved in the admin (content, settings, menus, media, redirects, an update) empties that cache, so
the change shows at once, however long the cache keeps a page. **Settings > Cache** shows what the site found, explains how each cache works and how to set
it up, and has a *Clear cache now* button.

Not kept: what an editor sees (they have a session and may see drafts), the maintenance page during an update, the analytics
beacon and a picture that could not be made. Nor the MCP server and its OAuth (`/mcp`, `/_mcp/token`, `/_mcp/register`,
`/.well-known/oauth-*`, `docs/mcp.md`): they answer `Cache-Control: no-store` and carry an `Authorization` header or a POST, which
Varnish passes by default; a cache or CDN that keeps POSTs or ignores `no-store` must be told to pass these paths, and must forward
the `Authorization` header to the site.

## CloudPanel

Nothing to set up. With Varnish Cache on for the site, CloudPanel runs its controller (`~/.varnish-cache/controller.php`) before
the CMS; the CMS finds it and hands it a purge of the site's cache tag (the Cache Tag Prefix in CloudPanel), which it sends when
the request ends. CloudPanel's own Cache Lifetime decides how long a page is kept (its controller replaces the CMS's
`Cache-Control`); with the purge on every change it can stay long.

## Any other Varnish

Write its address (`127.0.0.1:6081`) under Settings > Cache > Varnish address. On every change the CMS sends `PURGE /` with the site's host;
the VCL turns that into a ban of everything of that host:

```vcl
acl purgers { "127.0.0.1"; "::1"; }

sub vcl_recv {
    if (req.method == "PURGE") {
        if (client.ip !~ purgers) { return (synth(405, "Not allowed")); }
        ban("req.http.host == " + req.http.host);
        return (synth(200, "Purged"));
    }
}
```

## No cache in front: the site's own copy

For a host with no Varnish or CDN, **Settings > Cache > Built-in page cache** (off until it is turned on) makes the site keep what
a cache in front would keep. A page seen without a session is the same for everyone, so its answer is written to
`storage/cache/pages` and sent again: a visit then reads a file and does not run the site.

What is kept: a GET on the site's own address (the `base_url` of the settings, so the site must have one), with no query string (a
filter, a sort or a search is made each time), that answered 200 with a page, a sitemap, a feed or `robots.txt`, set no cookie and
did not say it must not be kept (the same `FrontCache::doNotKeep()` and `Cache-Control: private` that keep a Varnish from keeping
it). Not kept: anything with a session (an editor sees drafts), a page that is not found or redirects, a picture or download, an
answer over 2 MB. At most 3,000 copies are kept. The answer carries `X-Page-Cache: hit` or `miss`.

Emptying: by the same call that empties the cache in front, on every change saved in the admin and by the button. Copies are not
deleted on the spot: when the request that saved ends, a new generation number is written and the files are removed; an answer that
began under the old number is not kept, so a visit that overlaps a save cannot put the old page back. A copy is also not used for more
than its lifetime, for what changes outside the admin (a file edited by hand, a script): **Keep each page for**, 5 minutes to a week,
10 minutes until it is set (`PageCache::ttl()`, `page_cache_ttl` in the system database). With a cache in front the switch does
nothing the cache does not already do.

**Refresh only what changed** (on until it is turned off, `page_cache_targeted`): saving an entry drops only the copies made from it.
While a page is made, the places that read entries say what they read (`CacheTags`): `type:<type>` for a list of a type
(`ContentRepository::getItems()`, an archive's query, related entries, the choices of a relation), `entry:<type>/<slug>` for one entry
(`find()`, an entry looked up by address), `id:<translation id>` for an entry a relation points at or the translations of an entry, and
`*` for what is made from the whole content (the maps' data, the search index). The copy keeps that list. `ContentIndex::upsert()`
says what a request wrote; when it ends, those tags are stamped in `storage/cache/pages/stamps.json` and the copies carrying one are
removed. A copy made before a stamp of one of its tags is neither sent nor kept, so a visit that overlaps the save cannot put the old
page back. Only the editor's Save (not of a form), an MCP save, AI publishing's status and main picture work this way; an entry
deleted or moved to another address (`ContentIndex::remove()`), and every other change saved in the admin, still empty everything.

**Build cleared pages again at once** (on until it is turned off, `page_cache_rebuild`): after the response is sent (PHP-FPM or
LiteSpeed only, `CronStatus::finishFunction()`), the site asks itself for the addresses it just dropped, the home pages first, then the
newest, at most 50 and for at most a minute (`PageCache::rebuild()`): on this server first (the host resolved to 127.0.0.1, an answer
must carry `X-Page-Cache`), else through the public address. **Build cache now** lists the public addresses (home pages, archives,
every listed entry, term pages, the sitemap, robots.txt: `?cache_addresses=1` on Settings) and the admin's browser opens each without
its cookie, three at a time, counting what was built, already ready or never kept.
