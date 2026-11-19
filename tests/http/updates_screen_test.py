"""The Updates screen: the release notes and the update guide are shown as Markdown (code, bold, links), not as the marks
typed in the changelog, and the step to take before updating, the verified backup, stands out."""
import re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
st, _, html = root.get('/admin/updates')
items = re.findall(r'<span class="release-item">(.*?)</span></li>', html, re.S)
check('the screen lists release notes', st == 200 and len(items) > 0, (st, len(items)))
# A code span may show marks on purpose (a note quoting `****Title****`): only the text outside code counts.
prose = [re.sub(r'<code>.*?</code>', '', i, flags=re.S) for i in items]
check('with no Markdown marks left in them', all('`' not in i and '**' not in i for i in prose), [i[:100] for i in prose if '`' in i or '**' in i][:2])
check('code and bold are shown as such', any('<code>' in i for i in items) and any('<strong>' in i for i in items), [i[:80] for i in items[:2]])
check('and nothing typed in a note can add HTML', not re.search(r'<(script|img|iframe)', ' '.join(items), re.I))
m = re.search(r'<button[^>]*class="([^"]*)"[^>]*>\s*Create verified backup\s*</button>', html)
check('the verified backup button is the one that stands out', m is not None and 'btn-attention' in m.group(1), m and m.group(1))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
