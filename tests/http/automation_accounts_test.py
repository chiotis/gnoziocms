import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()

st, _, html = root.get('/admin/users')
check('Users offers an automation account', st == 200 and 'admin/users-edit?kind=automation' in html and 'Add automation account' in html)

st, _, html = root.get('/admin/users-edit?kind=automation')
check('its form says it has no sign-in', st == 200 and 'No sign-in' in html and 'name="kind" value="automation"' in html)
check('and asks for no password, email or Google link', 'name="password"' not in html and 'name="email"' not in html and 'name="google_sub"' not in html)
check('and does not offer the super admin role', re.search(r'<option value="superadmin"', html) is None and '<option value="editor"' in html)

st, hdr, _ = root.submit('/admin/users-edit?kind=automation', has_field('username'), {'username': 'newsroom', 'display_name': 'Newsroom', 'role': 'editor', 'status': 'active'})
loc = hdr.get('Location') or ''
check('it is created', st in (302, 303) and 'saved=1' in loc, (st, loc))
st, _, html = root.get(re.sub(r'^https?://[^/]+', '', loc))
check('and opens as an automation account', st == 200 and 'No sign-in' in html and 'name="kind"' not in html, st)

st, _, html = root.get('/admin/users')
row = re.search(r'newsroom.*?</tr>', html, re.S)
check('the list marks it', row is not None and 'Automation' in row.group(0), row and row.group(0)[:300])

bot = Client()
bot.login('newsroom', '')
st, _, html = bot.get('/admin')
check('nobody signs in with it', st in (302, 303) or 'name="password"' in html, st)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
