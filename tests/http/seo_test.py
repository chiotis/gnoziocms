import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
anon = Client()

def tab_form(*names):
    return lambda f: all(any(x[0] == n for x in f['fields']) for n in names)
def save(tab, probe, overrides=None, drop=None):
    return root.submit('/admin/seo?tab=' + tab, tab_form(probe), overrides, drop=drop)
def head(path, c=anon):
    st, _, html = c.get(path)
    return st, html
def title(html): return (re.search(r'<title>(.*?)</title>', html, re.S) or [None, ''])[1]
def meta(html, name, attr='name'):
    m = re.search(r'<meta %s="%s" content="([^"]*)"' % (attr, re.escape(name)), html)
    return m.group(1) if m else None
def graph(path):
    st, html = head(path)
    nodes = {}
    for raw in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        for n in json.loads(raw)['@graph']:
            nodes.setdefault(n['@type'], []).append(n)
    return nodes

# two entries with no SEO of their own, so the site's choices show
os.makedirs('app/content/pages', exist_ok=True); os.makedirs('app/content/posts', exist_ok=True)
open('app/content/pages/plain.md', 'w', encoding='utf-8').write("---\ntitle: Plain page\nstatus: published\nvisible: true\n---\n\nBody.\n")
open('app/content/posts/plain-post.md', 'w', encoding='utf-8').write("---\ntitle: Plain post\nstatus: published\nvisible: true\ndate: '2026-02-01'\n---\n\nBody.\n")

# ---- the screen
st, _, html = root.get('/admin/seo')
check('the overview opens', st == 200 and 'Open to search engines' in html and 'Published entries' in html, st)
check('with the six tabs', all(t in html for t in ('Overview', 'Search appearance', 'Social', 'Crawling &amp; sitemap', 'Identity &amp; schema', 'Verification')))
check('and SEO is in the Manage menu', 'href="/admin/seo"' in html or '/admin/seo' in html)
for tab, text in [('search', 'Title format'), ('social', 'Share image'), ('crawling', 'Publish a sitemap'), ('identity', 'Who the site is'), ('verification', 'Prove you own the site')]:
    st, _, html = root.get('/admin/seo?tab=' + tab)
    check('the %s tab opens' % tab, st == 200 and text in html, st)
st, _, html = root.get('/admin/seo?tab=nonsense')
check('a tab that does not exist is the overview', st == 200 and 'Published entries' in html)
st, _, html = root.get('/admin/seo?tab=search')
check('the search tab has a row for each content type but not for forms', all('name="types[%s][title_format]"' % t in html for t in ('pages', 'posts', 'projects')) and 'types[forms]' not in html)

# ---- who may
check('an editor cannot open it', ed.get('/admin/seo')[0] == 403)
check('nor see it in the menu', 'href="/admin/seo"' not in ed.get('/admin')[2])
check('a visitor is sent to sign in', anon.get('/admin/seo')[0] in (302, 303))
tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', ed.get('/admin')[2]).group(1)
ed.request('/admin/seo?tab=verification', {'_csrf': tok, 'verify_google': 'editor-was-here-1'})
check('nor save by sending a form', 'editor-was-here-1' not in head('/')[1])

# ---- by default nothing about the pages changes
st, html = head('/plain')
before_title = title(html)
check('a page is titled as before', before_title.endswith(' | GnozioCMS') or ' | ' in before_title, before_title)
check('open pages ask for large previews', 'max-image-preview:large' in (meta(html, 'robots') or ''), meta(html, 'robots'))
check('the search results are kept out', 'noindex' in (meta(head('/search?q=a')[1], 'robots') or ''))

# ---- search appearance
save('search', 'title_format', {'separator': '–', 'title_format': '{site} {sep} {title}', 'home_title': 'Home of the studio', 'home_description': 'About the studio home.', 'default_description': 'The site in a line.'})
st, html = head('/plain'); t = title(html)
check('the title follows the format and the separator', t.endswith('Plain page') and ' – ' in t and not t.endswith('| GnozioCMS'), t)
check('a page without a description gets the default', meta(html, 'description') == 'The site in a line.', meta(html, 'description'))
st, html = head('/')
check('the home page has a title and a description of its own (its SEO tab wins)', title(html) == 'FarosCMS Demo | Digital Studio' and 'Ενδεικτική' in (meta(html, 'description') or ''))
st, html = head('/about')
check('a page with a title written for search engines keeps it', title(html) == 'Σχετικά | FarosCMS Demo', title(html))
save('search', 'title_format', {'separator': '–', 'title_format': '{site} {sep} {title}', 'home_title': 'Home of the studio'}, drop=['types[posts][title_format]'])
check('the settings are stored as typed', 'Home of the studio' in root.get('/admin/seo?tab=search')[2])

# a content type with its own format, and one kept out
save('search', 'title_format', {'separator': '|', 'title_format': '', 'types[posts][title_format]': '{title} {sep} Blog', 'types[posts][noindex]': '1', 'types[posts][sitemap]': '1', 'types[projects][noindex]': '1'})
st, html = head('/plain-post')
check('a type has a format of its own', title(html) == 'Plain post | Blog', title(html))
check('a type can be kept out of search, its entries and its list', 'noindex' in (meta(html, 'robots') or '') and 'noindex' in (meta(head('/posts')[1], 'robots') or ''))
check('and the other types are not', 'noindex' not in (meta(head('/plain')[1], 'robots') or ''))
st, _, xml = anon.get('/sitemap.xml')
check('the sitemap leaves out a type that is kept out', 'plain-post' not in xml and 'rebrand-readiness-guide' not in xml and '/plain' in xml)
save('search', 'title_format', {'types[posts][sitemap]': None, 'types[posts][noindex]': None, 'types[projects][noindex]': None})
st, _, xml = anon.get('/sitemap.xml')
check('a type can be taken out of the sitemap alone', 'plain-post' not in xml and 'noindex' not in (meta(head('/plain-post')[1], 'robots') or ''))
save('search', 'title_format', {'types[posts][sitemap]': '1', 'noindex_search': None})
check('the search results can be left open', 'noindex' not in (meta(head('/search?q=a')[1], 'robots') or ''))

# ---- the sitemap
st, _, xml = anon.get('/sitemap.xml')
check('the sitemap lists pages, and a page that asks to be left out is not there', st == 200 and '/plain' in xml)
open('app/content/pages/secret.md', 'w', encoding='utf-8').write("---\ntitle: Secret\nstatus: published\nvisible: true\nseo:\n  noindex: true\n---\n\nBody.\n")
open('app/content/posts/with-image.md', 'w', encoding='utf-8').write("---\ntitle: With image\nstatus: published\nvisible: true\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\ntags:\n  - design\n---\n\nBody.\n")
st, _, xml = anon.get('/sitemap.xml')
check('an entry with "No index" is not in the sitemap', '/secret' not in xml)
check('the main picture of an entry is in it', '<image:loc>' in xml and '5e6915a67b9ceec5.jpg' in xml, xml[:300])
check('the pages of the terms that have entries are in it', '/tag/design' in xml or '/tags/design' in xml, re.findall(r'<loc>([^<]*(?:tag|categor)[^<]*)</loc>', xml))
save('crawling', 'sitemap_exclude', {'sitemap_exclude': '/plain\n/tag/'})
st, _, xml = anon.get('/sitemap.xml')
check('addresses can be left out of it, by their start', '/plain<' not in xml and '/en/plain<' not in xml and '/plain-post' not in xml and '/tag/design' not in xml and '/tags/design' not in xml and '/about' in xml and '/with-image' in xml, [l for l in re.findall(r'<loc>([^<]*)</loc>', xml) if re.search(r'plain|tag|with-image|about', l)])
save('crawling', 'sitemap_exclude', {'sitemap_exclude': '', 'sitemap_images': None, 'sitemap_taxonomies': None})
st, _, xml = anon.get('/sitemap.xml')
check('pictures and term pages can be left out of it', '<image:loc>' not in xml and '/tag/design' not in xml and '/tags/design' not in xml)
save('crawling', 'sitemap_exclude', {'sitemap_enabled': None})
check('a sitemap that is switched off is not there', anon.get('/sitemap.xml')[0] == 404)
robots = anon.get('/robots.txt')[2]
check('and robots.txt does not name it', st == 200 and 'Sitemap:' not in robots and robots.startswith('User-agent: *\nAllow: /'), robots)
save('crawling', 'sitemap_exclude', {'sitemap_enabled': '1', 'sitemap_images': '1', 'sitemap_taxonomies': '1'})
check('and it comes back', anon.get('/sitemap.xml')[0] == 200 and 'Sitemap:' in anon.get('/robots.txt')[2])

# ---- crawlers
save('crawling', 'robots_disallow', {'block_ai': '1'})
robots = anon.get('/robots.txt')[2]
check('AI crawlers can be closed out', 'User-agent: GPTBot\nDisallow: /\n' in robots and 'User-agent: ClaudeBot\nDisallow: /' in robots and robots.count('User-agent: *') == 1, robots)
check('before the rule for everyone', robots.index('GPTBot') < robots.index('User-agent: *'))
st, html = head('/plain')
check('and search engines are not asked to stay out', 'noindex' not in (meta(html, 'robots') or ''))
save('crawling', 'robots_disallow', {'discourage': '1', 'block_ai': None})
robots = anon.get('/robots.txt')[2]
check('a site that asks to stay out closes robots.txt', robots == 'User-agent: *\nDisallow: /\n', robots)
st, html = head('/plain')
check('every page says noindex, nofollow', meta(html, 'robots') == 'noindex, nofollow', meta(html, 'robots'))
check('and there is no sitemap', anon.get('/sitemap.xml')[0] == 404)
st, _, html = root.get('/admin/seo')
check('the overview says so', 'Search engines are asked to stay out' in html)
save('crawling', 'robots_disallow', {'discourage': None, 'snippets': None})
st, html = head('/plain')
check('open again, with large previews off, a page says nothing', meta(html, 'robots') is None, meta(html, 'robots'))
save('crawling', 'robots_disallow', {'snippets': '1'})

# ---- social
save('social', 'share_image', {'share_image': '/uploads/media/share.png', 'twitter_site': '@farosdemo', 'facebook_app_id': '123456789', 'twitter_card': 'summary'})
st, html = head('/plain')
check('a page without a picture gets the default one', meta(html, 'og:image', 'property') == BASE + '/uploads/media/share.png' or (meta(html, 'og:image', 'property') or '').endswith('/uploads/media/share.png'), meta(html, 'og:image', 'property'))
check('the account and the app are in the head', meta(html, 'twitter:site') == '@farosdemo' and meta(html, 'fb:app_id', 'property') == '123456789')
check('and the card is the one chosen', meta(html, 'twitter:card') == 'summary')
save('social', 'share_image', {'share_image': 'javascript:alert(1)', 'twitter_card': 'auto', 'twitter_site': '', 'facebook_app_id': ''})
st, html = head('/plain')
check('an image that is not an address is not kept', 'javascript:' not in html and meta(html, 'og:image', 'property') is None and meta(html, 'twitter:card') == 'summary')

# ---- identity and structured data
n = graph('/')
check('by default the site is an organization and the home page has a search', n['Organization'][0]['name'] and 'potentialAction' in n['WebSite'][0])
save('identity', 'identity_name', {'identity_type': 'LocalBusiness', 'identity_name': 'Faros Studio SA', 'identity_alternate_name': 'Faros', 'identity_street': '12 Main Street', 'identity_locality': 'Athens', 'identity_postal': '10557', 'identity_country': 'gr', 'identity_price_range': '€€', 'article_type': 'NewsArticle', 'breadcrumbs': None, 'search_box': None})
n = graph('/')
org = n.get('LocalBusiness', [{}])[0]
check('the site can be a local business, with a name and an address', org.get('name') == 'Faros Studio SA' and org.get('alternateName') == 'Faros' and org.get('address', {}).get('addressCountry') == 'GR' and org['address'].get('streetAddress') == '12 Main Street' and org.get('priceRange') == '€€', org)
check('its @id is the same, so the pages still point to it', org.get('@id', '').endswith('#organization'))
check('the home page can be without the site search', 'potentialAction' not in n['WebSite'][0])
n = graph('/plain-post')
check('posts take the type chosen', n.get('NewsArticle') and 'BlogPosting' not in n, list(n))
check('and breadcrumbs can be left out', 'BreadcrumbList' not in n)
save('identity', 'identity_name', {'identity_type': 'Person', 'identity_name': 'Jane Doe', 'article_type': 'BlogPosting', 'breadcrumbs': '1', 'search_box': '1'})
n = graph('/plain-post')
check('a person is a Person', n.get('Person') and n['Person'][0]['name'] == 'Jane Doe' and 'Organization' not in n, list(n))
check('posts take back the first type, with breadcrumbs', n.get('BlogPosting') and n.get('BreadcrumbList'))
save('identity', 'identity_name', {'identity_type': 'Organization', 'identity_name': ''})

# ---- verification
save('verification', 'verify_google', {'verify_google': 'g-code-12345', 'verify_bing': '<meta name="msvalidate.01" content="BING-ABCDEF" />', 'verify_yandex': 'x', 'verify_pinterest': '"><script>alert(1)</script>'})
st, html = head('/')
check('the codes are in every page', meta(html, 'google-site-verification') == 'g-code-12345' and meta(html, 'msvalidate.01') == 'BING-ABCDEF' and meta(head('/plain')[1], 'google-site-verification') == 'g-code-12345')
check('a pasted meta tag is reduced to its code, a bad one is dropped, nothing can leave the tag', 'yandex-verification' not in html and 'p:domain_verify' not in html and '<script>alert' not in html)
st, _, html = root.get('/admin/seo?tab=verification')
check('and shown back as the code', 'value="BING-ABCDEF"' in html)

# ---- the overview finds what to improve
st, _, html = root.get('/admin/seo')
check('missing descriptions are listed, with a way to edit', 'Missing description' in html and 'Plain page' in html and '/admin/edit?type=pages&amp;slug=plain&amp;lang=el' in html, html[html.find('Missing description'):][:200])
check('and the entry kept out of search is listed apart', 'Kept out of search' in html and 'Secret' in html)
check('the search engines are verified', 'The site is verified with 2 services' in html, re.findall(r'verified[^<]*', html))
open('app/content/pages/plain-two.md', 'w', encoding='utf-8').write("---\ntitle: Plain page\nstatus: published\nvisible: true\nexcerpt: A summary that has some length so it is neither too short nor too thin for a result, written to be read.\n---\n\nBody.\n")
st, _, html = root.get('/admin/seo')
check('two pages with the same title are found', re.search(r'The same title as another page.{0,400}?<em>(\d+)</em>', html, re.S) is not None and 'shared with 1 more' in html)

# ---- the other settings are not touched by a tab, and the General settings keep the SEO ones
st, _, html = root.get('/admin/settings')
root.submit('/admin/settings', lambda f: any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields']), {'title': 'Renamed again'})
st, html = head('/')
check('saving the General settings keeps the SEO ones', meta(html, 'google-site-verification') == 'g-code-12345')
st, _, html = root.get('/admin/activity-logs')
check('a save is in the activity log', 'SEO settings saved' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
