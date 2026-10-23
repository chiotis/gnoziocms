# A cache in front of the site (Varnish)

Public pages that a visitor sees without a session say `Cache-Control: public, max-age=0, s-maxage=60`, so a cache in front of the
site may keep them. Whatever is saved in the admin (content, settings, menus, media, redirects, an update) empties that cache, so
the change shows at once, however long the cache keeps a page. **System > Page cache** shows what the site found and has a button
that empties the cache by hand.

Not kept: what an editor sees (they have a session and may see drafts), the maintenance page during an update, the analytics
beacon and a picture that could not be made.

## CloudPanel

Nothing to set up. With Varnish Cache on for the site, CloudPanel runs its controller (`~/.varnish-cache/controller.php`) before
the CMS; the CMS finds it and hands it a purge of the site's cache tag (the Cache Tag Prefix in CloudPanel), which it sends when
the request ends. CloudPanel's own Cache Lifetime decides how long a page is kept (its controller replaces the CMS's
`Cache-Control`); with the purge on every change it can stay long.

## Any other Varnish

Write its address (`127.0.0.1:6081`) under System > Page cache. On every change the CMS sends `PURGE /` with the site's host;
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

For a host with no Varnish or CDN, **System > Page cache > The site's own copy** (off until it is turned on) makes the site keep what
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
than 10 minutes (`PageCache::TTL`), for what changes outside the admin (a file edited by hand, a script). With a cache in front the
switch does nothing the cache does not already do.
