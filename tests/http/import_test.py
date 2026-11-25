import sys, re, uuid, urllib.request, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field, BASE
from roles_test_lib import set_caps, matrix_form  # noqa
fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

HEAD = 'site_title,content_type,language,slug,title,status,visible,date,author,tags,categories,translation_id,main_image,excerpt,updated_at,body\n'
def row(slug, body): return f'FarosCMS,pages,en,{slug},Imp {slug},published,true,,,,,,,,,"{body}"\n'

def upload(c, csv):
    token = re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin/import?type=pages')[2]).group(1)
    b = uuid.uuid4().hex
    parts = []
    for k, v in (('_csrf', token), ('type', 'pages')):
        parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n')
    parts.append(f'--{b}\r\nContent-Disposition: form-data; name="csv_file"; filename="x.csv"\r\nContent-Type: text/csv\r\n\r\n{csv}\r\n--{b}--\r\n')
    req = urllib.request.Request(BASE + '/admin/import?type=pages', data=''.join(parts).encode(), headers={'Content-Type': f'multipart/form-data; boundary={b}'})
    try: r = c.opener.open(req); html = r.read().decode()
    except urllib.error.HTTPError as e: html = e.read().decode()
    forms = c.forms(html=html)
    apply = next((f for f in forms if ['apply', '1'] in f['fields'] or any(x[0] == 'preview_token' for x in f['fields'])), None)
    if not apply: return None, html
    return c.request('/admin/import?type=pages', data=[tuple(x) for x in apply['fields']]), html

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'Ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
check('editor cannot import by default', ed.get('/admin/import')[0] == 403)
set_caps(root, {'editor': ['imports.manage']})
check('editor can import once granted', ed.get('/admin/import')[0] == 200)
res, html = upload(ed, HEAD + row('imp-ed', '<script>alert(7)</script>\n\nSafe *text*'))
check('import applied', res is not None and res[0] in (302, 303), html[:300] if res is None else res[0])
page = Client().get('/en/imp-ed')[2]
check('import by editor: script neutralised', '<script>alert(7)' not in page and 'Safe' in page)
res, html = upload(root, HEAD + row('imp-admin', '<div class=""ok"">admin html</div>'))
page = Client().get('/en/imp-admin')[2]
check('import by admin-level user keeps HTML', '<div class="ok">admin html</div>' in page, res and res[0])
# The history of the imports: the editor's import is a run, and its one entry can be undone on its own.
status, _, listing = ed.get('/admin/imports')
check('Imports lists the CSV imports', status == 200 and 'pages: x.csv' in listing and 'CSV' in listing, status)
run_id = (re.findall(r'/admin/imports\?run=([0-9]{8}-[0-9]{6}-[0-9a-f]{6})', listing) or [''])[-1]
status, _, detail = ed.get('/admin/imports?run=' + run_id)
check('a run shows the entry it made, as imported', status == 200 and re.search(r'pages/imp-ed(\.[a-z]{2})?\.md', detail) is not None and 'As imported' in detail, (status, re.findall(r'<code[^>]*>([^<]*imp-ed[^<]*)</code>', detail), re.findall(r'ring-1 [^"]*">([^<]+)<', detail)))
undo = next((f for f in ed.forms(html=detail) if any(x[0] == 'key' for x in f['fields'])), None)
res = ed.request('/admin/imports', data=[tuple(x) for x in undo['fields']]) if undo else None
check('undo answers with the run again', res is not None and res[0] in (302, 303), res and res[0])
check('and the entry the import made is gone', Client().get('/en/imp-ed')[0] == 404)
check('the row says it was undone', 'Undone' in ed.get('/admin/imports?run=' + run_id)[2])
check('a visitor cannot open Imports', Client().get('/admin/imports')[0] in (302, 303, 403))
print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
