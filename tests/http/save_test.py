"""
Saving content, field by field. Each scenario posts the editor form and compares the file that is written with a
recorded one in tests/fixtures/golden, so a refactor of the save code cannot change what ends up on disk unnoticed.

To accept a deliberate change:  UPDATE_GOLDEN=1 tests/run.sh save   (then review the diff of tests/fixtures/golden).
"""
import json, os, re, sys, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

HERE = os.path.dirname(os.path.abspath(__file__))
GOLDEN = os.path.join(HERE, '..', 'fixtures', 'golden')
UPDATE = os.environ.get('UPDATE_GOLDEN') == '1'
fails = []

def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

def normalise(text):
    import datetime
    # The id is 16 random hex characters; one that reads as a number (digits with a single e, as 7220e14467316353) is written in quotes.
    text = re.sub(r"translation_id: '?[0-9a-f]{16}'?", 'translation_id: <generated>', text)
    # A new post or form is dated today by the editor form.
    return text.replace("date: '" + datetime.date.today().isoformat() + "'", "date: <today>")

def compare(name, path):
    if not os.path.exists(path):
        check(name + ': file written', False, path)
        return
    got = normalise(open(path, encoding='utf-8').read())
    gold = os.path.join(GOLDEN, name + '.md')
    if UPDATE or not os.path.exists(gold):
        os.makedirs(GOLDEN, exist_ok=True)
        open(gold, 'w', encoding='utf-8').write(got)
        print('note ' + name + ': golden file ' + ('updated' if UPDATE else 'created'))
    want = open(gold, encoding='utf-8').read()
    check(name + ': file matches the recorded one', got == want, '\n--- got ---\n' + got + '\n--- want ---\n' + want)

def save(client, type_, slug, lang, fields, extra=(), drop=()):
    """Open the editor for this item, post it with overrides, and return (status, slug saved, Location)."""
    st, hdr, _ = client.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), fields, drop=list(drop))
    loc = hdr.get('Location') or ''
    m = re.search(r'slug=([^&]+)', loc)
    return st, urllib.parse.unquote(m.group(1)) if m else None, loc

def post_raw(client, fields):
    """Post to /admin/save with exactly these fields (a token is taken from a page)."""
    page = client.get('/admin')[2]
    token = re.search(r'name="_csrf" value="([0-9a-f]+)"', page).group(1)
    return client.request('/admin/save', data=[('_csrf', token)] + fields)

def content_path(type_, slug, lang='el'):
    return f'app/content/{type_}/{slug}' + ('' if lang == 'el' else '.' + lang) + '.md'

root = Client(); root.login()

# 1. A page with every editor field
BLOCKS = [{'type': 'text', 'heading': 'Heading', 'body': 'Hello **world** <b>x</b>'},
          {'type': 'cta', 'heading': 'Go', 'actions': [{'label': 'Talk', 'url': 'contact'}]}]
st, slug, loc = save(root, 'pages', '', 'el', {
    'title': 'Πλήρης σελίδα δοκιμής', 'status': 'draft', 'excerpt': 'A short summary', 'author': 'Test Author', 'date': '15/03/2026',
    'seo_title': 'SEO title', 'seo_description': 'SEO description', 'seo_canonical': 'https://example.test/canonical',
    'seo_og_title': 'OG title', 'seo_og_description': 'OG description', 'seo_og_image': '/uploads/media/og.jpg', 'seo_noindex': '1',
    'main_image': '/uploads/media/hero.jpg', 'template': 'landing',
    'custom_keys[]': ['k_bool', 'k_num', 'k_float', 'k_json', 'k_text', 'title', ''], 'custom_values[]': ['true', '12', '1.5', '{"a": 1}', 'plain text', 'reserved', 'x'],
    'blocks_editor': '1', 'blocks_json': json.dumps(BLOCKS), 'body': "# Heading\n\nParagraph <b>html</b> and [link](https://example.test)\n",
}, drop=['visible'])
check('page saved', st in (302, 303) and slug == 'pliris-selida-dokimis', (st, slug, loc))
compare('page-full', content_path('pages', slug))

# 2. A post with dates in the site's format and category/tag terms
st, slug, loc = save(root, 'posts', '', 'el', {
    'title': 'Post με ετικέτες', 'date': '01/02/2026', 'taxonomy_terms[categories][]': ['news', 'news', 'case-studies'], 'taxonomy_terms[tags][]': ['strategy'],
    'body': 'Body text',
})
check('post saved', st in (302, 303), (st, loc))
compare('post-taxonomies', content_path('posts', slug))

# 3. A project: fields its type declares, plus a custom field
st, slug, loc = save(root, 'projects', '', 'el', {
    'title': 'Έργο με πεδία', 'details_present': '1', 'details[client]': 'ACME', 'details[sector]': 'retail', 'details[year]': '2024', 'details[location]': '',
    'custom_keys[]': ['extra'], 'custom_values[]': ['v'], 'body': 'Project body',
})
check('project saved', st in (302, 303), (st, loc))
compare('project-details', content_path('projects', slug))

# 4. A form with several field types, notifications, and anti-spam
FORM = {
    'title': 'Φόρμα δοκιμής', 'body': 'Intro',
    'form_fields[f0][type]': 'email', 'form_fields[f0][name]': 'Email Address', 'form_fields[f0][label]': '', 'form_fields[f0][required]': '1', 'form_fields[f0][placeholder]': 'you@example.test',
    'form_fields[f1][type]': 'select', 'form_fields[f1][name]': 'topic', 'form_fields[f1][label]': 'Topic', 'form_fields[f1][options]': "A, B\nC", 'form_fields[f1][default]': 'B',
    'form_fields[f2][type]': 'textarea', 'form_fields[f2][name]': 'message', 'form_fields[f2][label]': 'Message', 'form_fields[f2][rows]': '6', 'form_fields[f2][help]': 'Tell us more',
    'form_fields[f3][type]': 'number', 'form_fields[f3][name]': 'qty', 'form_fields[f3][label]': 'Qty', 'form_fields[f3][min]': '1', 'form_fields[f3][max]': '9', 'form_fields[f3][step]': '2',
    'form_fields[f4][type]': 'text', 'form_fields[f4][name]': 'form_reserved', 'form_fields[f4][label]': 'Skipped',
    'form_fields[f5][type]': 'unknown', 'form_fields[f5][name]': 'ok', 'form_fields[f5][label]': 'Falls back to text',
    'form_notifications[enabled]': '1', 'form_notifications[to]': 'team@example.test', 'form_notifications[subject]': 'New message', 'form_notifications[reply_to_field]': 'email-address',
    'form_notifications[cc]': 'cc@example.test', 'form_notifications[auto_reply]': '1', 'form_notifications[auto_reply_subject]': 'Thanks', 'form_notifications[auto_reply_message]': 'We got it',
    'form_success_message': 'Sent!', 'form_submit_label': 'Send it', 'form_redirect_url': '/thanks', 'form_honeypot': 'website', 'form_rate_limit_seconds': '30', 'form_store_submissions': '1',
}
st, slug, loc = save(root, 'forms', '', 'el', FORM)
check('form saved', st in (302, 303), (st, loc))
compare('form-full', content_path('forms', slug))

# 5. Raw front matter: unknown keys are dropped, blocks typed by hand are kept
st, slug, loc = save(root, 'pages', 'about', 'el', {
    'frontmatter': "blocks:\n  - type: text\n    body: hi\nunknown_key: dropped\nseo:\n  title: From raw\n", 'title': 'Σχετικά', 'blocks_editor': '0',
})
check('raw front matter saved', st in (302, 303), (st, loc))
compare('page-raw-frontmatter', content_path('pages', 'about'))

# 6. Blocks are kept when the editor script sent none
before = open(content_path('pages', 'services'), encoding='utf-8').read()
save(root, 'pages', 'services', 'el', {'title': 'Υπηρεσίες', 'frontmatter': ''}, drop=['blocks_editor', 'blocks_json'])
after = open(content_path('pages', 'services'), encoding='utf-8').read()
check('blocks survive a save without editor data', ('blocks:' in before) == ('blocks:' in after) and 'blocks:' in after)
compare('page-blocks-kept', content_path('pages', 'services'))

# 7. Someone without the raw HTML permission: the same input, neutralised
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'Ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
st, slug, loc = save(ed, 'pages', '', 'el', {
    'title': 'Editor page', 'blocks_editor': '1', 'blocks_json': json.dumps([{'type': 'text', 'heading': 'H', 'body': "<script>alert(1)</script> and <i>italic</i>\n\n[bad](javascript:alert(2))"}]),
    'body': "Text <div onclick=\"x()\">block</div> and `<code>`\n",
})
check('editor page saved with a notice', st in (302, 303) and 'notice=html' in loc, (st, loc))
compare('page-editor-neutralised', content_path('pages', slug))

# 8. Saving an item again: the translation id stays, the file settles after the first re-save, and it is recorded
path = content_path('pages', 'pliris-selida-dokimis')
tid = re.search(r"translation_id: '?([0-9a-f]{16})'?", open(path, encoding='utf-8').read()).group(1)
def resave(): save(root, 'pages', 'pliris-selida-dokimis', 'el', {'blocks_editor': '1', 'blocks_json': json.dumps(BLOCKS)}, drop=['visible'])
resave(); second = open(path, encoding='utf-8').read()
resave(); third = open(path, encoding='utf-8').read()
check('re-saving keeps the translation id', re.search(r"translation_id: '?" + tid + "'?", second) is not None)
check('re-saving twice gives the same file', second == third)
compare('page-resaved', path)

# 9. Opening /admin/save as an address (from another site, say) writes nothing: only a posted form saves
def listing():
    out = []
    for d in ('pages', 'posts'):
        base = 'app/content/' + d
        out += [os.path.join(d, f) for f in os.listdir(base)] if os.path.isdir(base) else []
    return sorted(out)
before = listing()
for method_path in ('/admin/save', '/admin/save?type=posts&title=Gift'):
    st, hdr, _ = root.get(method_path)
    check(f'GET {method_path} is refused', st == 405 and hdr.get('Allow') == 'POST', (st, hdr.get('Allow')))
check('and no file was written', listing() == before)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
