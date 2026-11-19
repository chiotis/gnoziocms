import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

def sidebar(html):
    m = re.search(r'<aside.*?</aside>', html, re.S) or re.search(r'<nav.*?</nav>', html, re.S)
    return m.group(0) if m else html

st, _, html = root.get('/admin')
side = sidebar(html)
check('the sidebar has one Logs entry', side.count('>\n            Logs') + side.count('Logs\n') >= 1 and re.search(r'href="[^"]*admin/activity-logs"[^>]*>\s*<svg.*?</svg>\s*Logs', side, re.S) is not None, st)
check('and no separate Activity or Email logs entries', not re.search(r'</svg>\s*Activity\s*</a>', side) and not re.search(r'</svg>\s*Email logs\s*</a>', side))

for path, current in (('/admin/activity-logs', 'Activity'), ('/admin/email-logs', 'Email')):
    st, _, html = root.get(path)
    tabs = re.search(r'<nav class="[^"]*" aria-label="Logs">(.*?)</nav>', html, re.S)
    check(path + ' shows the Logs tabs', st == 200 and tabs is not None, st)
    body = tabs.group(1) if tabs else ''
    check('with Activity and Email as tabs', 'admin/activity-logs' in body and 'admin/email-logs' in body and '>Activity<' in body and '>Email<' in body)
    check('the one you are on is marked', re.search(r'class="[^"]*\bactive\b[^"]*" aria-current="page">' + current + '<', body) is not None and body.count('aria-current="page"') == 1, body)
    check('the page is titled Logs and the sidebar entry is the current one', re.search(r'<h1[^>]*>\s*Logs\s*</h1>', html) is not None and 'aria-current="page"' in sidebar(html), re.findall(r'<h1[^>]*>[^<]*', html)[:2])
    check('the old screen is still there, with its filters and list', 'Level' in html or 'Status' in html)

st, _, html = root.get('/admin/activity-logs?level=warning')
check('filters keep working inside the tab', st == 200 and 'aria-label="Logs"' in html)
st, hdr, _ = root.request('/admin/email-logs', data=[('_csrf', token(root)), ('email_log_action', 'clear')])
check('clearing the email log still works and returns to its tab', st == 302 and 'admin/email-logs' in (hdr.get('Location') or ''), st)

st, _, html = root.get('/admin/activity-logs')
check('the activity log has a Clear logs button', 'name="activity_log_action" value="clear"' in html and 'Clear logs' in html)
st, hdr, _ = root.request('/admin/activity-logs', data=[('_csrf', token(root)), ('activity_log_action', 'clear')])
m = re.search(r'cleared=(\d+)', hdr.get('Location') or '')
check('clearing it returns to its tab and says how many went', st == 302 and m is not None and int(m.group(1)) > 0, (st, hdr.get('Location')))
st, _, html = root.get('/admin/activity-logs?cleared=' + (m.group(1) if m else '0'))
check('what is left is the entry saying who cleared it', 'Cleared ' in html and 'activity_logs.clear' in html and 'Activity logs cleared.' in html, st)
st, _, _ = ed.request('/admin/activity-logs', data=[('_csrf', token(ed)), ('activity_log_action', 'clear')])
check('an editor cannot clear it', st in (302, 403) and root.get('/admin/activity-logs')[2].count('Activity logs cleared.') >= 1, st)

# people without the permissions see neither the entry nor the tabs
st, _, html = ed.get('/admin')
check('an editor has no Logs entry', not re.search(r'</svg>\s*Logs\s*</a>', html), st)
st, _, _ = ed.get('/admin/activity-logs'); check('and cannot open the activity log', st in (302, 403), st)
st, _, _ = ed.get('/admin/email-logs'); check('or the email log', st in (302, 403), st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
