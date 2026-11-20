"""The admin's second factor through the site (audit S9b): the super admin requires it for a role, a person of that role is taken
to set it up after signing in, then signing in asks for the code (a wrong one, a right one, a recovery code), and the super admin
clears it. Everything is put back at the end, since the other tests share the site."""
import base64, hashlib, hmac, re, struct, sys, time
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

def totp(key, offset=0):
    secret = base64.b32decode(key.replace(' ', '').upper())
    step = int(time.time()) // 30 + offset
    digest = hmac.new(secret, struct.pack('>Q', step), hashlib.sha1).digest()
    o = digest[19] & 15
    return '%06d' % ((struct.unpack('>I', digest[o:o + 4])[0] & 0x7fffffff) % 1000000)

def location(hdr): return hdr.get('Location') or ''
def csrf(html): return re.search(r'name="_csrf" value="([0-9a-f]+)"', html).group(1)

root = Client(); root.login()
st, hdr, _ = root.submit('/admin/users-edit', has_field('username'),
    {'username': 'sfa', 'email': 'sfa@example.test', 'display_name': 'Sef', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
check('an admin is made', st in (302, 303), st)
uid = re.search(r'id=(\d+)', location(hdr)).group(1)

# ---- the requirement
st, _, html = root.get('/admin/roles')
check('the roles screen lets the super admin require it, with suggestions', 'data-second-factor-roles' in html and 'name="second_factor_roles[]" value="admin"' in html and 'suggested' in html)
st, hdr, _ = root.request('/admin/roles', data=[('action', 'second_factor_roles'), ('second_factor_roles[]', 'admin'), ('_csrf', csrf(html))])
check('saving the roles that require it', st in (302, 303) and 'saved=1' in location(hdr), (st, location(hdr)))
st, _, html = root.get('/admin/roles')
check('and they are ticked', re.search(r'value="admin"\s+class="[^"]*"\s+checked', html) is not None)
st, _, _ = root.get('/admin/content')
check('the super admin, whose role is not ticked, goes on as before', st == 200, st)

person = Client()
st, hdr, _ = person.login('sfa', 'Sturdy-pass-99')
check('the password signs in', st in (302, 303), st)
st, hdr, _ = person.get('/admin/content')
check('but any admin page leads to setting up the second factor', st in (302, 303) and location(hdr).endswith('/admin/second-factor'), (st, location(hdr)))
st, _, html = person.get('/admin/second-factor')
key = re.search(r'data-setup-key>([A-Z2-7 ]+)<', html)
check('which shows a QR code and the key, and why', st == 200 and '<svg' in html and key is not None and 'Your role requires a second factor' in html, st)
key = key.group(1) if key else ''
st, hdr, _ = person.submit('/admin/second-factor', has_field('code'), {'code': '000000'})
check('a wrong code does not turn it on', 'error=code' in location(hdr), location(hdr))
st, hdr, _ = person.submit('/admin/second-factor', has_field('code'), {'code': totp(key)})
check('the code from the app does', 'on=1' in location(hdr), location(hdr))
st, _, html = person.get('/admin/second-factor?on=1')
codes = re.findall(r'<li>([a-z2-9]{5}-[a-z2-9]{5})</li>', html)
check('the ten recovery codes are shown, once', len(codes) == 10 and 'data-recovery-codes' not in person.get('/admin/second-factor')[2], len(codes))
check('and the rest of the admin opens', person.get('/admin/content')[0] == 200)

# ---- signing in again
again = Client()
st, _, html = again.login('sfa', 'Sturdy-pass-99')
check('the password alone now asks for the code', st == 200 and 'name="code"' in html and 'Your code' in html, st)
st, hdr, _ = again.get('/admin/content')
check('and signs nobody in', st in (302, 303) and '/admin/login' in location(hdr), (st, location(hdr)))
st, _, html = again.submit('/admin/login', has_field('code'), {'code': '111111'})
check('a wrong code is refused', st == 200 and 'not right' in html, st)
st, hdr, _ = again.submit('/admin/login', has_field('code'), {'code': totp(key, 1)})
check('the next code from the app signs in', st in (302, 303) and again.get('/admin/content')[0] == 200, (st, location(hdr)))

third = Client()
third.login('sfa', 'Sturdy-pass-99')
st, hdr, _ = third.submit('/admin/login', has_field('code'), {'code': codes[0]})
check('a recovery code signs in', st in (302, 303) and third.get('/admin/content')[0] == 200, st)
fourth = Client()
fourth.login('sfa', 'Sturdy-pass-99')
st, _, html = fourth.submit('/admin/login', has_field('code'), {'code': codes[0]})
check('the same recovery code no more', st == 200 and 'not right' in html, st)
st, _, html = person.get('/admin/second-factor')
check('9 left, the screen says', 'Recovery codes left' in html and '9 of 10' in html)

# ---- the super admin clears it
st, _, html = root.get('/admin/users-edit?id=' + uid)
check('the profile shows it on, and the super admin can clear it', 'data-second-factor' in html and 'id="clearSecondFactor"' in html, st)
st, hdr, _ = root.submit('/admin/users-edit?id=' + uid, lambda f: ['action', 'clear'] in f['fields'])
check('cleared', 'second_factor_cleared=1' in location(hdr), location(hdr))
st, _, html = root.get('/admin/activity-logs')
check('and logged', 'Second factor cleared' in html or 'users.second_factor_cleared' in html)
st, hdr, _ = person.get('/admin/content')
check('the person (still of a role that requires it) is asked to set it up again', st in (302, 303) and location(hdr).endswith('/admin/second-factor'), (st, location(hdr)))

# ---- put back
st, hdr, _ = root.request('/admin/roles', data=[('action', 'second_factor_roles'), ('_csrf', csrf(root.get('/admin/roles')[2]))])
check('no role requires it any more', st in (302, 303) and person.get('/admin/content')[0] == 200, st)
root.submit('/admin/users-edit?id=' + uid, has_field('username'), {'status': 'inactive'})

print('\nALL PASSED' if not fails else f'\n{len(fails)} FAILED')
sys.exit(1 if fails else 0)
