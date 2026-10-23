import json, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

def matrix_form(f): return ['action', 'save'] in f['fields'] and any(x[0].startswith('caps[') for x in f['fields'])
def code(c, path): return c.get(path)[0]

def set_caps(root, role_caps, drop=()):
    """Submit the roles form with extra capabilities on, and named ones off (as unticking a box would)."""
    fields = [list(x) for x in next(f for f in root.forms('/admin/roles') if matrix_form(f))['fields']]
    keep = [f for f in fields if f[0] not in drop]
    extra = [[f'caps[{role}][{cap}]', '1'] for role, caps in role_caps.items() for cap in caps]
    return root.request('/admin/roles', data=[tuple(x) for x in keep + extra])

def reset(root, role):
    forms = root.forms('/admin/roles')
    form = next(f for f in forms if ['action', 'reset'] in f['fields'] and ['role', role] in f['fields'])
    return root.request('/admin/roles', data=[tuple(x) for x in form['fields']])

root = Client(); root.login()
for username, role in (('ed1', 'editor'), ('adm1', 'admin'), ('bob', 'user')):
    st, hdr, _ = root.submit('/admin/users-edit', has_field('username'),
        {'username': username, 'email': username + '@example.test', 'display_name': username.title(), 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
    check(f'create {role}', st in (302, 303), st)
ed, adm, bob = Client(), Client(), Client()
ed.login('ed1', 'Sturdy-pass-99'); adm.login('adm1', 'Sturdy-pass-99'); bob.login('bob', 'Sturdy-pass-99')

# ---- who can see the screen
st, _, html = root.get('/admin/roles')
check('superadmin opens the roles screen', st == 200 and 'Roles and permissions' in html, st)
check('nav shows Roles to superadmin', '>\n            Roles\n' in html or 'admin/roles' in html)
check('matrix lists switchable permissions', 'caps[editor][content.manage]' in html and 'caps[admin][settings.manage]' in html)
check('users/roles/restore are not switches', 'caps[editor][users.manage]' not in html and 'caps[admin][roles.manage]' not in html and 'caps[admin][backups.restore]' not in html)
check('risky ones are labelled', 'Critical' in html and 'Sensitive' in html)
for name, c in (('admin', adm), ('editor', ed), ('basic user', bob)):
    check(f'{name} cannot open /admin/roles', code(c, '/admin/roles') == 403)
tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', adm.get('/admin/settings')[2]).group(1)
st, _, _ = adm.request('/admin/roles', data=[('action', 'save'), ('caps[editor][settings.manage]', '1'), ('_csrf', tok)])
check('admin cannot post to roles even with a valid token', st == 403, st)
st, _, html = adm.get('/admin/settings'); check('admin menu has no Roles entry', 'admin/roles' not in html)
st, _, html = ed.get('/admin/content'); check('editor menu has no Roles entry', 'admin/roles' not in html)
st, hdr, html = root.get('/admin/roles'); check('nothing changed by the admin attempt', 'caps[editor][settings.manage]" value="1" checked' not in html)

# ---- defaults
check('editor default: no menus', code(ed, '/admin/menus') == 403)
check('editor default: no media if removed later (has media now)', code(ed, '/admin/media') == 200)
check('basic user default: no content', code(bob, '/admin/content') == 403)

# ---- grant menus + translations to editor, dashboard/content to basic user
st, hdr, _ = set_caps(root, {'editor': ['menus.manage', 'translations.manage'], 'user': ['dashboard.view', 'content.manage']})
check('save redirects with saved', st in (302, 303) and 'saved=1' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
check('editor now opens menus (live session)', code(ed, '/admin/menus') == 200)
check('editor now opens translations', code(ed, '/admin/translations') == 200)
check('editor still blocked from settings', code(ed, '/admin/settings') == 403)
check('editor still blocked from users', code(ed, '/admin/users') == 403)
check('editor still blocked from forms', code(ed, '/admin/forms') == 403)
check('basic user now opens content', code(bob, '/admin/content') == 200)
check('basic user opens dashboard', code(bob, '/admin/dashboard') == 200 or code(bob, '/admin') == 200)
check('basic user still no media', code(bob, '/admin/media') == 403)
st, _, html = root.get('/admin/roles')
check('changed roles are flagged', html.count('>changed<') >= 2 or html.count('changed</span>') >= 2)
check('admin role untouched, not flagged as changed', html.count('changed</span>') == 2, html.count('changed</span>'))
st, _, html = ed.get('/admin/menus'); check('editor menu shows Menus link', 'admin/menus' in html)
st, _, html = root.get('/admin/activity-logs'); check('change is in the activity log', 'Role permissions changed' in html or 'roles.update' in html)

# ---- tampering: things that can never be given away
st, hdr, _ = set_caps(root, {'editor': ['users.manage', 'roles.manage', 'backups.restore', 'made.up']})
check('tampered save still succeeds', st in (302, 303))
check('editor still cannot manage users', code(ed, '/admin/users') == 403)
check('editor still cannot open roles', code(ed, '/admin/roles') == 403)
check('editor cannot reach restore', code(ed, '/admin/backups') == 403)
st, _, html = ed.get('/admin/menus'); check('no users link for editor', 'admin/users"' not in html)

# ---- locked capabilities cannot be removed
st, hdr, _ = set_caps(root, {}, drop=[f'caps[editor][{c}]' for c in ('admin.access', 'users.self')])
check('editor keeps sign-in and own profile', code(ed, '/admin/users-edit?id=2') == 200 and code(ed, '/admin/content') == 200)

# ---- remove a default permission
st, hdr, _ = set_caps(root, {}, drop=['caps[editor][media.manage]'])
check('editor loses media when unticked', code(ed, '/admin/media') == 403)
check('editor keeps content', code(ed, '/admin/content') == 200)

# ---- raw HTML can be granted, and then it works
def save(client, slug, body, type_='pages', lang='en'):
    fields = {'type': type_, 'slug': slug, 'lang': lang, 'title': 'Role test ' + slug, 'status': 'published', 'visible': '1', 'body': body}
    return client.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), fields)
save(ed, 'before-grant', '<div class="ok" data-x="1">html</div>')
html = Client().get('/en/before-grant')[2]
check('editor HTML neutralised before the grant', '<div class="ok" data-x="1">html</div>' not in html)
set_caps(root, {'editor': ['content.raw_html']})
save(ed, 'after-grant', '<div class="ok" data-x="1">html</div>')
html = Client().get('/en/after-grant')[2]
check('editor HTML kept after the grant', '<div class="ok" data-x="1">html</div>' in html)
st, hdr, html = root.get('/admin/roles')
# ---- reset
st, hdr, _ = reset(root, 'editor')
check('reset saves', st in (302, 303) and 'saved=1' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
check('reset editor: menus closed again', code(ed, '/admin/menus') == 403)
check('reset editor: media back', code(ed, '/admin/media') == 200)
save(ed, 'after-reset', '<div class="ok" data-x="1">html</div>')
html = Client().get('/en/after-reset')[2]
check('reset editor: HTML neutralised again', '<div class="ok" data-x="1">html</div>' not in html)
check('reset leaves the basic user change alone', code(bob, '/admin/content') == 200)
reset(root, 'user')
check('reset user: content closed', code(bob, '/admin/content') == 403)
st, _, html = root.get('/admin/roles'); check('nothing flagged after resets', 'changed</span>' not in html)

# ---- superadmin unaffected by anything above
for path in ('/admin/users', '/admin/settings', '/admin/roles', '/admin/backups', '/admin/updates', '/admin/forms'):
    check('superadmin still opens ' + path, code(root, path) == 200, path)

# ---- corrupt stored data fails safe
import sqlite3
db = sqlite3.connect('app/storage/db/app.sqlite')
db.execute("INSERT INTO system_meta(key,value,updated_at) VALUES('role_permissions',?,'x') ON CONFLICT(key) DO UPDATE SET value=excluded.value",
           (json.dumps({'editor': ['users.manage', 'roles.manage', 'nonsense', 5], 'admin': 'oops', 'superadmin': []}),))
db.commit()
check('stored junk cannot grant users.manage', code(ed, '/admin/users') == 403)
check('stored junk cannot grant roles', code(ed, '/admin/roles') == 403)
check('stored junk on admin ignored', code(adm, '/admin/settings') == 200)
check('superadmin cannot be overridden away', code(root, '/admin/roles') == 200)
check('editor with only locked caps has no content', code(ed, '/admin/content') == 403)
db.execute("UPDATE system_meta SET value='not json' WHERE key='role_permissions'"); db.commit()
check('unreadable stored value falls back to defaults', code(ed, '/admin/content') == 200 and code(ed, '/admin/menus') == 403)
db.execute("DELETE FROM system_meta WHERE key='role_permissions'"); db.commit()

# ---- an account that is deactivated stops seeing drafts on the public site at once, not only in the admin
st, _, _ = root.submit('/admin/users-edit', has_field('username'),
    {'username': 'gone1', 'email': 'gone1@example.test', 'display_name': 'Gone', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
gone = Client(); gone.login('gone1', 'Sturdy-pass-99')
root.submit('/admin/edit?type=pages&slug=secret-draft&lang=en', has_field('body'),
    {'type': 'pages', 'slug': 'secret-draft', 'lang': 'en', 'title': 'Secret draft', 'status': 'draft', 'visible': '1', 'body': 'Not yet public'})
check('a draft is hidden from visitors', Client().get('/en/secret-draft')[0] == 404)
check('and shown to a signed-in editor', gone.get('/en/secret-draft')[0] == 200)
gone_id = re.search(r'users-edit\?id=(\d+)', next(m for m in re.findall(r'<tr.*?</tr>', root.get('/admin/users')[2], re.S) if 'gone1' in m)).group(1)
root.submit(f'/admin/users-edit?id={gone_id}', has_field('username'), {'status': 'inactive', 'password': '', 'password_confirm': ''})
check('after the account is deactivated the same cookie sees no draft', gone.get('/en/secret-draft')[0] == 404)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
