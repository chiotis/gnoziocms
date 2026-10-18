import re, sys, os
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
pub = Client()

def toggle_form(type_name):
    return lambda f: ['type', type_name] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']]
def toggle(client, type_name, on):
    return client.submit('/admin/content-types', toggle_form(type_name), {'enabled': '1' if on else '0'})

# ---- the screen
st, _, html = root.get('/admin/content-types')
check('one list holds every type, with a switch beside Edit', st == 200 and 'Ready-made content types' not in html and 'Content types in use' not in html and 'value="books"' in html and 'Enable<span' in html and 'Disable<span' in html)
check('pages are always on', 'Always on' in html)
check('pages are not in the list to switch', not any(toggle_form('pages')(f) for f in root.forms(html=html)))
check('books start off', re.search(r'name="type" value="books">\s*<input type="hidden" name="enabled" value="1"', html) is not None)
check('posts and projects start on', all(re.search(r'name="type" value="%s">\s*<input type="hidden" name="enabled" value="0"' % t, html) for t in ('posts', 'projects')))

check('the admin menu keeps listing the types while this screen is open', all('/admin/content?type=%s' % t in html for t in ('pages', 'posts', 'projects')) and '/admin/content?type=books' not in html)
st, _, edit_html = root.get('/admin/content-types?type=posts')
check('and on the screen of one type', all('/admin/content?type=%s' % t in edit_html for t in ('pages', 'posts', 'projects')))

# ---- off: no list, no pages, nothing in the admin
st, _, _ = pub.get('/books'); check('a type that is off has no archive on the site', st == 404, st)
st, hdrs, _ = root.get('/admin/content?type=books')
check('nor a list in the admin', st in (302, 303) or 'Books' not in root.last_html or 'type=books' not in root.last_html, st)
st, _, html = root.get('/admin/content?type=posts')
check('and it is not among the types in the admin menu', 'type=books' not in html)

# ---- an editor cannot switch types
st, hdrs, _ = ed.get('/admin/content-types')
check('an editor cannot open the screen that switches types', st in (302, 303, 403), st)
st, _, _ = pub.get('/books'); check('and it stays off', st == 404, st)

# ---- on: archive, admin list, a book page
st, hdrs, _ = toggle(root, 'books', True)
check('switching books on goes back to the screen', st in (302, 303) and 'toggled=on' in (hdrs.get('Location') or ''), (st, hdrs.get('Location')))
st, _, html = root.get('/admin/content-types?toggled=on&type_name=books')
check('with a message, and the type is now in use', 'Books is enabled' in html and 'content-types?type=books' in html)
st, _, html = pub.get('/books'); check('the archive page exists, with the title of the books list', st == 200 and 'Βιβλία' in html and 'Τα βιβλία μας' in html, st)
st, _, html = root.get('/admin/content?type=posts'); check('books is in the admin menu', 'type=books' in html)
st, _, html = root.get('/admin/edit?type=books')
check('its editor form has the fields of a book', st == 200 and all(k in html for k in ('Author', 'Publisher', 'ISBN')), st)
os.makedirs('app/content/books', exist_ok=True)
open('app/content/books/uncle.md', 'w', encoding='utf-8').write("---\ntitle: Uncle Petros\nstatus: published\nvisible: true\nexcerpt: A novel about a mathematician.\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\ncustom_fields:\n  author: Apostolos Doxiadis\n  publisher: Faber\n  year: 2000\n  buy_url: https://shop.example/uncle\n  buy_label: Buy it now\n---\n\nThe story.\n")
st, _, html = pub.get('/books/uncle')
check('a book page has its own layout', st == 200 and 'book-hero' in html and 'class="book-title">Uncle Petros<' in html, st)
check('with author, summary, facts and the buy link', all(k in html for k in ('Apostolos Doxiadis', 'A novel about a mathematician.', 'Faber', 'https://shop.example/uncle', 'Buy it now')))
check('and the text below', 'The story.' in html)
check('the facts are shown once', html.count('class="type-fields"') == 1, html.count('class="type-fields"'))
st, _, html = pub.get('/books'); check('the archive lists it', 'Uncle Petros' in html)
st, _, html = pub.get('/books/uncle')
check('one old link is one primary button, as before', html.count('class="btn btn-primary" href="https://shop.example/uncle"') == 1 and 'btn-secondary' not in html.split('book-buy')[1].split('</p>')[0])
st, _, html = root.get('/admin/edit?type=books&slug=uncle')
check('the editor shows the old link as the first row of the buy links', st == 200 and 'data-repeater-name="details[buy_links]"' in html and 'name="details[buy_links][0][url]" value="https://shop.example/uncle"' in html and 'name="details[buy_links][0][label]" value="Buy it now"' in html and 'details[buy_url]' not in html, st)
def book_form(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'details_present' for x in f['fields'])
root.submit('/admin/edit?type=books&slug=uncle', book_form, {'details[buy_links][0][label]': 'Buy it now', 'details[buy_links][0][url]': 'https://shop.example/uncle', 'details[buy_links][1][label]': 'Ikaros', 'details[buy_links][1][url]': 'https://ikaros.example/u', 'details[buy_links][2][label]': '', 'details[buy_links][2][url]': ''})
front = open('app/content/books/uncle.md', encoding='utf-8').read().split('---')[1]
check('saving writes buy_links and drops the old keys', 'buy_links:' in front and 'Ikaros' in front and 'buy_url' not in front and 'buy_label' not in front, front[-300:])
st, _, html = pub.get('/books/uncle')
buy = html.split('class="book-buy"')[1].split('</p>')[0] if 'class="book-buy"' in html else ''
check('the page has one button per shop, the first primary and the rest secondary', buy.count('<a ') == 2 and 'btn-primary" href="https://shop.example/uncle"' in buy and 'btn-secondary" href="https://ikaros.example/u"' in buy and 'Ikaros' in buy, buy)
open('app/content/books/trilogy.md', 'w', encoding='utf-8').write("---\ntitle: Trilogy\nstatus: published\nvisible: true\ncustom_fields:\n  buy_links:\n    - {label: One, url: 'https://a.example/1'}\n    - {label: Two, url: 'https://b.example/2'}\n    - {label: Three, url: 'https://c.example/3'}\n---\n\nText.\n")
st, _, html = pub.get('/books/trilogy')
buy = html.split('class="book-buy"')[1].split('</p>')[0] if 'class="book-buy"' in html else ''
check('three shops are three buttons in order', buy.count('<a ') == 3 and buy.count('btn-primary') == 1 and buy.index('One') < buy.index('Two') < buy.index('Three'), buy)
st, _, xml = pub.get('/sitemap.xml'); check('and the sitemap has it', 'books/uncle' in xml, st)

# ---- off again: hidden, files kept
st, hdrs, _ = toggle(root, 'books', False)
check('switching books off', st in (302, 303) and 'toggled=off' in (hdrs.get('Location') or ''))
st, _, _ = pub.get('/books'); check('the archive is gone', st == 404, st)
st, _, _ = pub.get('/books/uncle'); check('and so is the page', st == 404, st)
st, _, xml = pub.get('/sitemap.xml'); check('and it is out of the sitemap', 'books/uncle' not in xml, st)
check('the files are kept', os.path.isfile('app/content/books/uncle.md'))
toggle(root, 'books', True)
st, _, html = pub.get('/books/uncle'); check('switching it on again brings the page back', st == 200 and 'Uncle Petros' in html, st)

# ---- posts can be switched off too, pages cannot
toggle(root, 'posts', False)
st, _, _ = pub.get('/posts'); check('posts can be switched off', st == 404, st)
toggle(root, 'posts', True)
st, _, _ = pub.get('/posts'); check('and on again', st == 200, st)
st, hdrs, _ = root.submit('/admin/content-types', toggle_form('books'), {'type': 'pages', 'enabled': '0'})
check('pages cannot be switched off', 'error=type' in (hdrs.get('Location') or ''), hdrs.get('Location'))
st, _, _ = pub.get('/about'); check('and still open', st == 200, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
