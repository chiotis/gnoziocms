import re, sys, sqlite3, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def loc(h): return h.get('Location') or ''
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def role_row(key):
    r = db().execute("select value from system_meta where key='custom_roles'").fetchone()
    import json
    data = json.loads(r[0]) if r else {}
    return data.get(key) if isinstance(data, dict) else None
def user_role(name):
    r = db().execute('select role from users where username=?', (name,)).fetchone()
    return r[0] if r else None
def post(c, **fields):
    return c.request('/admin/roles', data=[('_csrf', token(c))] + list(fields.items()))
def is_matrix(f): return ['action', 'save'] in f['fields'] and any(x[0].startswith('caps[') for x in f['fields'])
def save_matrix(c, add=(), drop=(), leave_out=()):
    """Submit the permission table as a browser would, with extra boxes ticked and others cleared."""
    f = next(f for f in c.forms('/admin/roles') if is_matrix(f))
    fields = [tuple(x) for x in f['fields'] if x[0] not in drop and not (x[0] == 'in_form[]' and x[1] in leave_out)]
    fields += [(f'caps[{r}][{cap}]', '1') for r, caps in add for cap in caps]
    return c.request('/admin/roles', data=fields)
def new_user(root, name, role):
    return root.submit('/admin/users-edit', has_field('username'), {'username': name, 'email': name + '@example.test', 'display_name': name, 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})

root = Client(); root.login()
new_user(root, 'adm1', 'admin')
adm = Client(); adm.login('adm1', 'Sturdy-pass-99')

# ---- the screen
st, _, html = root.get('/admin/roles')
check('the screen offers roles of your own', st == 200 and 'Your own roles' in html and 'value="create_role"' in html)
check('starting points are offered', all(x in html for x in ('Only signing in', 'A copy of Editor', 'A copy of Admin', 'A copy of Basic user')))

# ---- creating
st, hdr, _ = post(root, action='create_role', label='Φωτογράφος', description='Ανεβάζει και οργανώνει φωτογραφίες', **{'from': 'blank'})
check('a role can be created', st == 302 and 'created=fotografos' in loc(hdr), loc(hdr))
row = role_row('fotografos')
check('its key is made from the name in Latin letters', row is not None and row['label'] == 'Φωτογράφος' and row['description'].startswith('Ανεβάζει'), row)
check('it starts with only signing in and the profile', row['capabilities'] == ['admin.access', 'users.self'], row)
st, _, html = root.get('/admin/roles')
check('it is a column in the table', 'caps[fotografos][media.manage]' in html and 'Φωτογράφος' in html and 'yours' in html)
check('it has no reset button', 'Reset Φωτογράφος' not in html)
check('it is listed with its details', 'value="Φωτογράφος"' in html and 'name="role" value="fotografos"' in html)
st, hdr, _ = post(root, action='create_role', label='Φωτογράφος', **{'from': 'blank'})
check('the same name gets another key', 'created=fotografos-2' in loc(hdr), loc(hdr))
st, hdr, _ = post(root, action='create_role', label='Editor', **{'from': 'editor'})
check('a built-in name gets another key too', 'created=editor-2' in loc(hdr), loc(hdr))
check('a copy of Editor starts with what Editor has', 'content.manage' in role_row('editor-2')['capabilities'] and 'content.raw_html' not in role_row('editor-2')['capabilities'])
st, hdr, _ = post(root, action='create_role', label='  ', **{'from': 'blank'}); check('a role needs a name', 'error=label' in loc(hdr), loc(hdr))
st, hdr, _ = post(root, action='create_role', label='Copy of admin', **{'from': 'admin'})
key = re.search(r'created=([^&]+)', loc(hdr)).group(1)
check('a copy of Admin never carries the super admin only permissions', not set(role_row(key)['capabilities']) & {'users.manage', 'roles.manage', 'backups.restore'}, role_row(key))
post(root, action='delete_role', role='fotografos-2'); post(root, action='delete_role', role='editor-2'); post(root, action='delete_role', role=key)

# ---- who may
st, _, _ = adm.get('/admin/roles'); check('an admin cannot open the roles screen', st in (302, 403), st)
st, _, _ = post(adm, action='create_role', label='Sneaky', **{'from': 'admin'}); check('an admin cannot create a role', st in (302, 403) and role_row('sneaky') is None, st)
st, _, _ = post(adm, action='delete_role', role='fotografos'); check('nor delete one', role_row('fotografos') is not None)

# ---- choosing what it may do
st, hdr, _ = save_matrix(root, add=[('fotografos', ['media.manage'])])
check('permissions are saved', st == 302 and 'saved=1' in loc(hdr), loc(hdr))
check('the role has what was ticked', role_row('fotografos')['capabilities'] == ['admin.access', 'users.self', 'media.manage'], role_row('fotografos'))
rp = db().execute("select value from system_meta where key='role_permissions'").fetchone()
check('built-in roles are unchanged by it', rp is None or rp[0] in ('[]', '{}'), rp)
st, hdr, _ = save_matrix(root, add=[('fotografos', ['users.manage', 'roles.manage', 'backups.restore', 'nonsense'])])
check('permissions only the super admin has cannot be given', role_row('fotografos')['capabilities'] == ['admin.access', 'users.self', 'media.manage'], role_row('fotografos'))
st, _, html = root.get('/admin/activity-logs'); check('creating a role is in the activity log', 'roles.create' in html or 'Role created' in html)

# ---- giving it to someone
st, _, html = root.get('/admin/users-edit?id=0')
check('the user form offers the role', 'value="fotografos"' in html and 'Φωτογράφος' in html)
new_user(root, 'phot1', 'fotografos')
check('the person has the role', user_role('phot1') == 'fotografos', user_role('phot1'))
st, _, html = root.get('/admin/users'); check('the users list shows it', 'Φωτογράφος' in html)
st, _, html = root.get('/admin/users?role=fotografos'); check('and can filter by it', 'phot1' in html and 'adm1' not in html)
new_user(root, 'odd1', 'hacker'); check('an unknown role is not accepted', user_role('odd1') == 'user', user_role('odd1'))
phot = Client(); phot.login('phot1', 'Sturdy-pass-99')
check('the person can sign in', phot.get('/admin/users-edit?id=' + str(db().execute("select id from users where username='phot1'").fetchone()[0]))[0] == 200)
st, _, html = phot.get('/admin/media'); check('and use what the role allows', st == 200, st)
for label, path in (('content', '/admin/content?type=pages'), ('settings', '/admin/settings'), ('roles', '/admin/roles'), ('users', '/admin/users'), ('menus', '/admin/menus'), ('backups', '/admin/backups'), ('redirects', '/admin/redirects')):
    st, _, _ = phot.get(path); check(f'but not {label}', st in (302, 403), (path, st))
st, _, html = phot.get('/admin/media'); check('the sidebar shows only what the role reaches', 'admin/settings' not in html and 'admin/roles' not in html and 'admin/backups' not in html)

# ---- changing what it may do applies at once
save_matrix(root, add=[('fotografos', ['content.manage'])])
check('a new permission applies on the next click', phot.get('/admin/content?type=pages')[0] == 200)
check('a raw HTML permission is a separate one', 'content.raw_html' not in role_row('fotografos')['capabilities'])
save_matrix(root, drop=['caps[fotografos][content.manage]'])
check('and so does taking one away', phot.get('/admin/content?type=pages')[0] in (302, 403))

# ---- Theme has a switch of its own: the look of the site without the site's settings
save_matrix(root, add=[('fotografos', ['theme.manage'])])
check('Theme can be given alone', phot.get('/admin/theme')[0] == 200 and phot.get('/admin/settings')[0] in (302, 403))
st, _, html = phot.get('/admin/theme'); check('with Theme in the menu, under Site, and no Settings entry', '>Site</p>' in html and 'admin/theme"' in html and 'admin/settings"' not in html)
save_matrix(root, drop=['caps[fotografos][theme.manage]'])
check('and taken away', phot.get('/admin/theme')[0] in (302, 403))
save_matrix(root, add=[('fotografos', ['settings.manage'])])
check('Settings brings Theme with it', 'theme.manage' in role_row('fotografos')['capabilities'] and phot.get('/admin/theme')[0] == 200, role_row('fotografos'))
save_matrix(root, drop=['caps[fotografos][settings.manage]', 'caps[fotografos][theme.manage]'])
check('(both taken away again)', phot.get('/admin/theme')[0] in (302, 403) and 'settings.manage' not in role_row('fotografos')['capabilities'], role_row('fotografos'))

# ---- Backups' settings go with Backups, not with Settings
save_matrix(root, add=[('fotografos', ['backups.manage'])])
st, _, html = phot.get('/admin/backups?view=settings')
check('a role with Backups but not Settings opens Backups > Settings', st == 200 and 'name="backup_schedule"' in html, st)
st, hdr, _ = phot.submit('/admin/backups?view=settings', lambda f: any(x[0] == 'backup_schedule' for x in f['fields']), {'backup_schedule': 'monthly'})
check('and saves it', st in (302, 303) and 'saved=1' in loc(hdr) and 'monthly' in (db().execute("select value from system_meta where key='site_settings'").fetchone() or [''])[0], (st, loc(hdr)))
check('but still not Settings or Updates > Source', phot.get('/admin/settings')[0] in (302, 403) and phot.get('/admin/updates?view=settings')[0] in (302, 403))
save_matrix(root, drop=['caps[fotografos][backups.manage]'])

# ---- a role that was not on the form is left alone
post(root, action='create_role', label='Late arrival', **{'from': 'editor'})
before = role_row('late-arrival')['capabilities']
save_matrix(root, leave_out=['late-arrival'], drop=['caps[late-arrival][content.manage]'])
check('a role missing from the submitted table keeps its permissions', role_row('late-arrival')['capabilities'] == before, role_row('late-arrival'))
post(root, action='delete_role', role='late-arrival')

# ---- renaming
st, hdr, _ = post(root, action='update_role', role='fotografos', label='Photographer', description='Photos')
row = role_row('fotografos')
check('name and description can change', 'saved=1' in loc(hdr) and row['label'] == 'Photographer' and row['description'] == 'Photos', row)
check('its permissions and key did not', row['capabilities'] == ['admin.access', 'users.self', 'media.manage'])
st, hdr, _ = post(root, action='update_role', role='fotografos', label='', description=''); check('an empty name keeps the old one', role_row('fotografos')['label'] == 'Photographer')
st, hdr, _ = post(root, action='update_role', role='admin', label='Boss', description=''); check('a built-in role cannot be renamed this way', 'error=unknown' in loc(hdr) and role_row('admin') is None, loc(hdr))
st, _, html = root.get('/admin/users'); check('people see the new name', 'Photographer' in html)

# ---- deleting
st, hdr, _ = post(root, action='delete_role', role='fotografos')
check('a role that people have cannot be deleted', 'error=in_use' in loc(hdr) and role_row('fotografos') is not None, loc(hdr))
st, _, html = root.get('/admin/roles?error=in_use&role=fotografos'); check('the reason is shown', 'still given to people' in html)
st, _, html = root.get('/admin/roles'); check('the delete button is disabled for it', re.search(r'value="delete_role"[^>]*disabled', html) is not None)
uid = db().execute("select id from users where username='phot1'").fetchone()[0]
root.submit(f'/admin/users-edit?id={uid}', has_field('username'), {'role': 'editor'})
check('the person can be given another role', user_role('phot1') == 'editor', user_role('phot1'))
st, hdr, _ = post(root, action='delete_role', role='fotografos')
check('then the role can be deleted', 'deleted=1' in loc(hdr) and role_row('fotografos') is None, loc(hdr))
st, _, html = root.get('/admin/users-edit?id=0'); check('and is no longer offered', 'value="fotografos"' not in html)
st, hdr, _ = post(root, action='delete_role', role='editor'); check('a built-in role cannot be deleted', 'error=unknown' in loc(hdr))
st, hdr, _ = post(root, action='delete_role', role='nothing'); check('an unknown role is refused', 'error=unknown' in loc(hdr))

# ---- someone whose role disappears from the settings cannot do anything
conn = db(); conn.execute("update users set role='ghost' where username='odd1'"); conn.commit(); conn.close()  # one connection, closed: an open write transaction would lock the site's database
odd = Client(); st, _, _ = odd.login('odd1', 'Sturdy-pass-99')
st, _, _ = odd.get('/admin/content?type=pages'); check('a person whose role no longer exists reaches nothing', st in (302, 403), st)

# ---- the limit
for n in range(25):
    post(root, action='create_role', label=f'Bulk {n}', **{'from': 'blank'})
raw = db().execute("select value from system_meta where key='custom_roles'").fetchone()[0]
import json
check('a site can have at most twenty roles of its own', len(json.loads(raw)) == 20, len(json.loads(raw)))
st, hdr, _ = post(root, action='create_role', label='One too many', **{'from': 'blank'}); check('and says so', 'error=limit' in loc(hdr), loc(hdr))
st, _, html = root.get('/admin/roles'); check('the form is replaced by a note', 'at most 20 roles' in html and 'value="create_role"' not in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
