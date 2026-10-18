import re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
def head(html):
    m = re.search(r'<aside.*?<nav', html, re.S)
    return m.group(0) if m else ''
st, _, html = root.get('/admin')
top = head(html)
check('the sidebar top has no logo square', st == 200 and '>FC<' not in top and 'bg-slate-900 text-xs font-bold' not in top, top[:300])
name = re.search(r'<span class="[^"]*truncate[^"]*"[^>]*>([^<]*)</span>', top)
check('it shows the site name from Settings', name is not None and name.group(1).strip() != '', top[:300])
aside = re.search(r'<aside.*?</aside>', html, re.S).group(0)
bottom = aside.split('</nav>')[-1]
check('the top has the View site button, opening a new tab', 'View site' in top and 'target="_blank"' in top and 'rel="noopener"' in top and '(opens in a new tab)' in top, top[-500:])
check('and the version, now at the bottom, reads "GnozioCMS v…"', re.search(r'>\s*(?:<span[^>]*></span>)?GnozioCMS v[^<]+<', bottom) is not None and 'GnozioCMS v' not in top, bottom[:400])
check('the bottom no longer has the big View site button', 'View site' not in bottom)

# change the name in Settings and see it here
form = next(f for f in root.forms('/admin/settings') if any(x[0] == 'title' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] != 'title'] + [('title', 'Acme Studio')]
root.request(form['attrs'].get('action') or '/admin/settings', data=fields)
st, _, html = root.get('/admin/content?type=pages')
top = head(html)
check('a new site name shows on every screen', 'Acme Studio' in top, top[:400])
check('the name has a tooltip for when it is cut off', 'title="Acme Studio"' in top)
check('the browser tab title is unchanged', '- GnozioCMS</title>' in html)

# ---- Content types, Redirects and Translations are in Manage
def groups(side):
    out, cur = {}, None
    for m in re.finditer(r'<p class="px-2 pb-1\.5[^>]*>([^<]+)</p>|<a href="([^"]*)"[^>]*>(.*?)</a>', side, re.S):
        if m.group(1):
            cur = m.group(1).strip(); out[cur] = []
        elif cur is not None:
            name = re.sub(r'<[^>]+>', '', m.group(3)).strip()
            if name: out[cur].append(name)
    return out
st, _, html = root.get('/admin')
g = groups(re.search(r'<aside.*?</aside>', html, re.S).group(0))
check('Manage lists Forms, Menus, Theme, Taxonomies, Content types, SEO, Redirects, Translations, History in that order', g.get('Manage') == ['Forms', 'Menus', 'Theme', 'Taxonomies', 'Content types', 'SEO', 'Redirects', 'Translations', 'History'], g.get('Manage'))
check('and System no longer lists them', not {'Content types', 'Redirects', 'Translations'} & set(g.get('System', [])), g.get('System'))
for path, name in (('/admin/content-types', 'Content types'), ('/admin/redirects', 'Redirects'), ('/admin/translations', 'Translations')):
    st, _, html = root.get(path)
    cur = [re.sub(r'<[^>]+>', '', c).strip() for c in re.findall(r'<a href="[^"]*"[^>]*aria-current="page"[^>]*>(.*?)</a>', re.search(r'<aside.*?</aside>', html, re.S).group(0), re.S)]
    check(path + ': the item is current', st == 200 and cur == [name], (st, cur))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
