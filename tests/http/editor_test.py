import json, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

# ---- superadmin creates an editor and a basic user
root = Client()
st, _, html = root.login()
check('superadmin can log in', st in (302, 303), st)
st, _, html = root.get('/admin/users-edit')
check('superadmin sees editor in role list', 'value="editor"' in html and 'Editor' in html)
check('role descriptions shown', 'data-role-help' in html)
for username, role in (('ed1', 'editor'), ('bob', 'user')):
    st, hdr, _ = root.submit('/admin/users-edit', has_field('username'),
        {'username': username, 'email': username + '@example.test', 'display_name': username.title(), 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
    check(f'create {role} {username}', st in (302, 303) and 'saved=1' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
st, _, html = root.get('/admin/users')
check('users list shows Editor badge', '>Editor<' in html)
ed_id = int(re.search(r'users-edit\?id=(\d+)[^"]*"[^>]*>[^<]*(?:<[^>]+>\s*)*ed1', html, re.S).group(1)) if re.search(r'users-edit\?id=(\d+)[^"]*"[^>]*>[^<]*(?:<[^>]+>\s*)*ed1', html, re.S) else None
if ed_id is None:
    m = re.findall(r'users-edit\?id=(\d+)', html); ed_id = max(map(int, m))

# ---- editor session
ed = Client()
st, _, _ = ed.login('ed1', 'Sturdy-pass-99')
check('editor can log in', st in (302, 303), st)

allowed = ['/admin', '/admin/content', '/admin/content?type=posts', '/admin/content?type=projects', '/admin/edit?type=pages&slug=about&lang=en',
           '/admin/edit?type=posts&slug=rebrand-readiness-guide&lang=en', '/admin/media', '/admin/taxonomies', f'/admin/users-edit?id={ed_id}', '/admin/search?q=web']
for path in allowed:
    st, _, html = ed.get(path)
    check('editor may open ' + path, st == 200, st)
st, hdr, _ = ed.get('/admin/new?type=pages&slug=zz&lang=en')
check('editor may start a new page', st in (302, 303) and '/admin/edit' in (hdr.get('Location') or ''), (st, hdr.get('Location')))

denied = ['/admin/settings', '/admin/system', '/admin/content-types', '/admin/content-types?type=posts', '/admin/translations', '/admin/users', '/admin/users-edit?id=1', '/admin/users-edit',
          '/admin/activity-logs', '/admin/email-logs', '/admin/backups', '/admin/updates', '/admin/menus', '/admin/menus-new', '/admin/forms', '/admin/form-submissions',
          '/admin/import', '/admin/export', '/admin/forms-export', '/admin/no-such-action',
          '/admin/edit?type=forms&slug=contact&lang=en', '/admin/edit?type=forms&slug=contact&lang=el', '/admin/new?type=forms&slug=x&lang=en']
for path in denied:
    st, _, html = ed.get(path)
    check('editor blocked from ' + path, st == 403, st)
st, hdr, _ = ed.get('/admin/content?type=forms')
follow = hdr.get('Location') or ''
st2, _, _ = ed.get(follow) if follow else (0, 0, '')
check('forms list is out of reach (redirect leads to 403)', st2 == 403, (st, follow, st2))

# ---- what the editor sees
st, _, html = ed.get('/admin')
check('dashboard shows content', 'Content entries' in html and 'Recent content' in html)
for leak in ('>Users<', '>Backups<', 'System status', 'Recent activity', 'Recent emails', 'System checks', 'Free disk', 'SQLite'):
    check('dashboard hides "%s"' % leak.strip('<>'), leak not in html)
for link in ('admin/settings', 'admin/users"', 'admin/backups', 'admin/updates', 'admin/activity-logs', 'admin/email-logs', 'admin/menus', 'admin/forms', 'admin/translations', 'admin/content-types', 'admin/import', 'admin/export', 'admin/system'):
    check('menu has no link to ' + link.strip('"'), link not in html)
check('menu links content, media, categories', 'admin/content' in html and 'admin/media' in html and 'admin/taxonomies' in html)
check('no notifications bell', 'aria-label="Notifications"' not in html)
st, _, page = ed.get('/admin/edit?type=pages&slug=about&lang=en')
check('page editor loads its tabs', 'data-tab="basics"' in page and 'data-tab="seo"' in page)
st, _, html = ed.get('/admin/search?q=contact')
check('search excludes forms', '/admin/edit?type=forms' not in html and 'type=forms' not in html)

# ---- an editor cannot promote themselves
st, _, _ = ed.submit(f'/admin/users-edit?id={ed_id}', has_field('username'), {'role': 'superadmin', 'status': 'active', 'display_name': 'Ed One'})
st, _, html = root.get(f'/admin/users-edit?id={ed_id}')
check('role stays editor after self-edit with role=superadmin', re.search(r'<option value="editor"\s+selected', html) is not None)
check('display name could be changed', 'Ed One' in html)

# ---- POSTs to forbidden actions are refused, even with a valid token
tok = re.search(r'name="_csrf" value="([^"]+)"', ed.get('/admin')[2]).group(1)
for path, data in (('/admin/settings', {'tab': 'general'}), ('/admin/users-delete', {'id': '1'}), ('/admin/content-types', {'action': 'create', 'name': 'hax', 'label': 'x'}),
                   ('/admin/translations', {'lang': 'en'}), ('/admin/menus-new', {'title': 'x'}), ('/admin/backups', {'action': 'create'}),
                   ('/admin/save', {'type': 'forms', 'slug': 'contact', 'lang': 'el', 'title': 'Hacked'}), ('/admin/delete', {'type': 'forms', 'slug': 'contact', 'lang': 'el'})):
    st, _, _ = ed.request(path, data={**data, '_csrf': tok})
    check('editor POST refused: ' + path + ' ' + data.get('type', ''), st == 403, st)
import os
check('forms file untouched', 'Hacked' not in open('app/content/forms/contact.md').read())
check('no rogue content type created', not os.path.isdir('app/content/hax'))

# ---- raw HTML and script links written by an editor are neutralised
def save(client, slug, body, extra=None, lang='en', type_='pages', blocks=None):
    fields = {'type': type_, 'slug': slug, 'lang': lang, 'title': 'Role test ' + slug, 'status': 'published', 'visible': '1', 'body': body}
    if blocks is not None:
        fields['blocks_editor'] = '1'; fields['blocks_json'] = json.dumps(blocks)
    fields.update(extra or {})
    return client.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), fields, drop=['body'] if False else None)

st, hdr, _ = save(ed, 'ed-xss', "Hello\n\n<script>alert('pwn')</script>\n\n<div onclick=\"x()\">block</div>\n\nInline <b>bold</b> and <img src=x onerror=alert(1)>.\n\n[bad](javascript:alert(2)) and [good](https://example.test/)\n\n`<code>` stays as code\n")
loc = hdr.get('Location') or ''
check('editor save succeeds and warns about HTML', st in (302, 303) and 'saved=1' in loc and 'notice=html' in loc, (st, loc))
public = Client(); st, _, html = public.get('/en/ed-xss')
check('public page renders', st == 200, st)
check('no script tag reaches the page', '<script>alert' not in html and 'onerror' not in html.replace('&lt;img src=x onerror', '') and 'onclick' not in html.replace('&lt;div onclick', ''), re.findall(r'<script[^>]*>[^<]*alert[^<]*', html))
check('HTML is shown as text', '&lt;script&gt;alert' in html or '&lt;script>alert' in html)
check('javascript: link has no address', 'javascript:' not in html.lower().replace('&lt;', ''), re.findall(r'href="javascript[^"]*"', html))
check('safe link kept', 'href="https://example.test/"' in html)
check('inline code stays code', '<code>&lt;code&gt;</code>' in html)
stored = open('app/content/pages/ed-xss.en.md').read()
check('stored file has no raw script tag', '<script' not in stored, stored[:300])

# an editor's edit keeps HTML an administrator put in that page
st, hdr, _ = save(root, 'admin-embed', 'Intro\n\n<iframe src="https://example.test/embed" title="Embed"></iframe>\n\nText')
st, _, html = Client().get('/en/admin-embed'); check('admin may embed raw HTML', '<iframe src="https://example.test/embed"' in html, st)
st, hdr, _ = save(ed, 'admin-embed', 'Intro edited\n\n<iframe src="https://example.test/embed" title="Embed"></iframe>\n\nText\n\n<iframe src="https://evil.test/x"></iframe>')
st, _, html = Client().get('/en/admin-embed')
check('editor edit keeps the admin embed', '<iframe src="https://example.test/embed"' in html)
check('editor cannot add a new embed', 'evil.test' not in html.replace('&lt;iframe src="https://evil.test', '') or '<iframe src="https://evil.test' not in html, re.findall(r'<iframe[^>]*>', html))
check('editor edit text saved', 'Intro edited' in html)

# blocks written through the editor
blocks = [{'type': 'text', 'heading': 'Blocks', 'body': 'Fine **bold**\n\n<script>alert("blk")</script>'},
          {'type': 'faq', 'items': [{'question': 'Q?', 'answer': 'A <img src=x onerror=alert(3)> b'}]}]
st, hdr, _ = save(ed, 'ed-blocks', 'Body', blocks=blocks)
st, _, html = Client().get('/en/ed-blocks')
check('block markdown is neutralised too', '<script>alert("blk")' not in html and '<img src=x onerror' not in html and 'Fine <strong>bold</strong>' in html, re.findall(r'<script[^>]*>[^<]*blk', html))
# raw front matter route
raw = "blocks:\n  - type: text\n    heading: Raw\n    body: 'x <script>alert(9)</script>'\n"
st, hdr, _ = save(ed, 'ed-raw', 'Body', extra={'frontmatter': raw})
st, _, html = Client().get('/en/ed-raw'); check('blocks typed into raw front matter are neutralised', '<script>alert(9)' not in html, st)

# an admin keeps full HTML power
st, hdr, _ = save(root, 'admin-html', '<div class="ok" data-x="1">admin html</div>')
st, _, html = Client().get('/en/admin-html'); check('admin raw HTML preserved', '<div class="ok" data-x="1">admin html</div>' in html)
check('admin save shows no warning', 'notice=html' not in (hdr.get('Location') or ''))

# ---- deactivating the editor ends their access
root.submit(f'/admin/users-edit?id={ed_id}', has_field('username'), {'status': 'inactive'})
st, _, _ = ed.get('/admin/content')
check('inactive editor is locked out', st in (302, 303, 401, 403), st)

# ---- a basic user still has no admin
bob = Client(); bob.login('bob', 'Sturdy-pass-99')
st, _, _ = bob.get('/admin/content'); check('basic user cannot open content', st == 403, st)
st, _, _ = bob.get('/admin'); check('basic user has no dashboard', st == 403, st)

# ---- the SEO tab counts the characters of the search and sharing texts
st, _, html = root.get('/admin/edit?type=pages&slug=about&lang=en')
check('the SEO tab of an entry asks for a count on its four texts, and loads the script', html.count('data-count="') == 4 and 'js/admin-seo-count.js' in html and all(('name="%s"' % n) in html for n in ('seo_title', 'seo_description', 'seo_og_title', 'seo_og_description')), html.count('data-count="'))
check('with the ranges that suit them (search title, search description, share title, share description)', all(r in html for r in ('data-count="30,60,70"', 'data-count="70,160,180"', 'data-count="30,70,90"', 'data-count="60,200,250"')))
st, _, html = root.get('/admin/edit?type=forms&slug=contact&lang=el')
check('and so does a form', html.count('data-count="') == 4 and 'js/admin-seo-count.js' in html, html.count('data-count="'))

# ---- a picture beside the text
open('app/content/pages/beside.md', 'w', encoding='utf-8').write("---\ntitle: Beside\nstatus: published\nvisible: true\n---\n\n![A cat](/uploads/media/5e6915a67b9ceec5.jpg){align=left} The text goes around it.\n\nMore ![B](/uploads/media/5e6915a67b9ceec5.jpg){align=right} words.\n\n![Plain](/uploads/media/5e6915a67b9ceec5.jpg)\n")
pub = Client()
st, _, html = pub.get('/beside')
check('Markdown puts a picture beside the text with {align=left} or {align=right}, and nothing else changes', st == 200 and '<img align="left"' in html and '<img align="right"' in html and html.count('<img') >= 3 and '<img src="/uploads/media/5e6915a67b9ceec5.jpg" alt="Plain"' in html, st)
css_href = re.search(r'href="([^"]*site\.css[^"]*)"', html).group(1)
sheet = pub.get(css_href)[2]
flat = lambda s: re.sub(r'\s+', '', s)  # stylesheets are sent minified: compare without white space
check('the theme floats it, keeps it in a part of the width, and puts it back in the flow on a phone', 'img[align="left"]' in sheet and 'float:right' in flat(sheet) and 'max-width:45%' in flat(sheet) and re.search(r'@media\(max-width:640px\)\{\.proseimg\[align="left"\]', flat(sheet)) is not None)
st, _, html = root.get('/admin/edit?type=pages&slug=beside&lang=el')
check('the visual editor draws it in the same way', st == 200, st)
check('and the editor\'s style sheet floats it', 'img[align="left"]' in root.get(re.search(r'href="([^"]*app\.css[^"]*)"', html).group(1))[2])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
