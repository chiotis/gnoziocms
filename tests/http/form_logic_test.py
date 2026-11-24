"""
Forms with conditions, steps and a rating, their look, and archived submissions, over HTTP: a form saved from the builder
with "show only when", steps, a rating and the stacked look; its page (the conditions and steps in the markup, the rating's
stars, the script only where it is needed); a submission where a hidden required field is not asked for and nothing hidden is
kept; a rating out of range refused; the Submissions screen's Inbox and Archived, archiving one and several, restoring,
exporting the archive and deleting from it; and a form without any of this unchanged.
"""
import csv, glob, io, json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

FIELDS = [
    {'type': 'select', 'name': 'topic', 'label': 'Topic', 'required': True, 'options': [{'value': 'sales', 'label': 'Sales'}, {'value': 'support', 'label': 'Support'}]},
    {'type': 'text', 'name': 'company', 'label': 'Company', 'required': True, 'width': 'half', 'condition': {'field': 'topic', 'op': 'is', 'value': 'sales'}},
    {'type': 'text', 'name': 'order', 'label': 'Order number', 'width': 'half', 'condition': {'field': 'topic', 'op': 'is', 'value': 'support'}},
    {'type': 'step', 'label': 'How did we do?'},
    {'type': 'rating', 'name': 'stars', 'label': 'Your rating', 'required': True, 'max': '4'},
    {'type': 'checkbox', 'name': 'call', 'label': 'Call me back'},
    {'type': 'tel', 'name': 'phone', 'label': 'Phone', 'required': True, 'condition': {'field': 'call', 'op': 'filled'}},
    {'type': 'step', 'label': 'Anything else', 'condition': {'field': 'topic', 'op': 'is', 'value': 'support'}},
    {'type': 'textarea', 'name': 'message', 'label': 'Message', 'required': True},
]

# ---- saved from the builder
st, hdrs, _ = root.submit('/admin/edit?type=forms&slug=&lang=el', has_field('form_fields_json'), {
    'form_fields_json': json.dumps(FIELDS), 'title': 'Logic', 'form_style': 'stacked', 'form_rate_limit_seconds': '0'})
check('a form with conditions, steps and a rating is saved', st in (302, 303) and 'slug=logic' in (hdrs.get('Location') or ''), (st, hdrs.get('Location')))
text = open('app/content/forms/logic.md', encoding='utf-8').read()
check('its file has the conditions, the steps, the stars and the look', all(x in text for x in ('condition:', 'op: is', 'value: sales', 'type: step', 'name: step-2', 'type: rating', "max: '4'", 'style: stacked')), text[:300])
st, _, html = root.get('/admin/edit?type=forms&slug=logic&lang=el')
m = re.search(r'<script type="application/json" id="form-builder-data">(.*?)</script>', html, re.S)
fields = json.loads(m.group(1))['fields'] if m else []
check('the builder gets them back', len(fields) == 9 and fields[1].get('condition') == {'field': 'topic', 'op': 'is', 'value': 'sales'} and fields[4].get('max') == '4' and fields[3]['type'] == 'step', fields[:2])
check('the Look is offered on Settings, stacked chosen', re.search(r'name="form_style" value="stacked"[^>]*checked', html) is not None and 'name="form_style" value="compact"' in html)

# ---- the page
st, _, html = pub.get('/forms/logic')
check('the form page opens with the look and the script for steps and conditions', st == 200 and 'form-grid form-style-stacked' in html and 'form-logic.js' in html and 'data-form-logic' in html, st)
check('a part shown only for an answer says so', 'data-condition="{&quot;field&quot;:&quot;topic&quot;,&quot;op&quot;:&quot;is&quot;,&quot;value&quot;:&quot;sales&quot;}"' in html)
check('three steps, the later ones with their titles; a step shown only for an answer says so', html.count('data-form-step ') + html.count('data-form-step>') == 3 and '>How did we do?</h3>' in html and re.search(r'data-form-step[^>]*data-condition="[^"]*support', html) is not None, html.count('data-form-step'))
check('Back and Next start hidden (without JavaScript every step is shown)', re.search(r'data-form-step-nav hidden', html) is not None and 'data-form-next' in html)
check('the rating is four stars to choose from, each named for a screen reader', html.count('name="stars"') == 4 and 'class="form-rating-star"' in html and '4 από 4' in html, html.count('name="stars"'))
st, _, plain = pub.get('/forms/contact')
check('a form without steps or conditions loads no script and has no steps', st == 200 and 'form-logic' not in plain and 'data-form-step' not in plain and 'form-style-' not in plain, st)
st, _, js = pub.get('/_themes/default/js/form-logic.js')
check('the script is served', st == 200 and 'data-condition' in js, st)

# ---- what is sent
def subs(view=''):
    return sorted(glob.glob('app/content/forms-submissions/logic/' + (view + '/' if view else '') + '*.json'))
def send(values):
    data = [('form_slug', 'logic'), ('form_lang', 'el'), ('website', '')] + list(values)
    return Client().request('/forms/logic', data=data)

st, _, html = send([('topic', 'sales'), ('stars', '3'), ('company', '')])
check('a required field shown for the answer is asked for', st == 200 and 'form-error' in html and subs() == [], st)
st, _, html = send([('topic', 'sales'), ('stars', '9'), ('company', 'ACME')])
check('a rating out of its stars is refused', st == 200 and 'form-error' in html and subs() == [], st)
st, hdrs, html = send([('topic', 'sales'), ('company', 'ACME'), ('order', 'should not be kept'), ('stars', '3'), ('phone', ''), ('message', '')])
check('hidden required fields (a phone without a callback, a skipped step) are not asked for', st == 200 and 'form-success' in html and len(subs()) == 1, (st, subs()))
kept = json.load(open(subs()[0], encoding='utf-8'))['fields'] if subs() else {}
check('and nothing sent for a hidden field is kept', kept == {'topic': 'sales', 'company': 'ACME', 'stars': '3', 'call': ''}, kept)
st, _, html = send([('topic', 'support'), ('order', 'A-1'), ('stars', '4'), ('call', '1'), ('phone', '210 000')])
check('the step shown for support is asked for', st == 200 and 'form-error' in html and len(subs()) == 1, st)
st, _, html = send([('topic', 'support'), ('order', 'A-1'), ('stars', '4'), ('call', '1'), ('phone', '210 000'), ('message', 'Thanks')])
check('and kept with it', st == 200 and 'form-success' in html and len(subs()) == 2, st)

# ---- archiving
st, _, html = root.get('/admin/form-submissions?slug=logic')
check('the Submissions screen has Inbox and Archived, with Archive on each row and for the ticked ones', st == 200 and 'Inbox' in html and 'view=archived' in html and 'value="bulk_archive"' in html and 'title="Archive"' in html, st)
check('a rating is shown as so many of so many', '3 / 4' in html and '4 / 4' in html)
ids = [os.path.basename(p)[:-5] for p in subs()]
rowcount = lambda h: len(re.findall(r'<input type="checkbox" name="selected_ids\[\]"', h))
tk = lambda: re.search(r'name="_csrf" value="([0-9a-f]+)"', root.get('/admin/form-submissions?slug=logic')[2]).group(1)
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk()), ('slug', 'logic'), ('submission_action', 'archive'), ('id', ids[0]), ('return_to', '/admin/form-submissions?slug=logic')])
check('one is archived, and the screen says so', st in (302, 303) and 'archived=1' in (hdrs.get('Location') or '') and len(subs()) == 1 and len(subs('archive')) == 1, (st, hdrs.get('Location')))
st, _, html = root.get('/admin/form-submissions?slug=logic&archived=1')
check('the inbox lists the rest, and counts the archived one', 'Submission archived.' in html and '· 1 archived' in html and rowcount(html) == 1, rowcount(html))
st, _, html = root.get('/admin/form-submissions?slug=logic&view=archived')
check('Archived lists it, with Restore and Delete', st == 200 and rowcount(html) == 1 and 'value="bulk_restore"' in html and 'title="Restore"' in html and 'value="restore"' in html, st)
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk()), ('slug', 'logic'), ('submission_action', 'bulk_archive'), ('selected_ids[]', ids[1]), ('return_to', '/admin/form-submissions?slug=logic')])
check('the ticked ones are archived', st in (302, 303) and subs() == [] and len(subs('archive')) == 2, st)
st, _, html = root.get('/admin/forms')
check('the Forms list says how many are archived', '2 archived' in html)
st, hdrs, body = root.get('/admin/forms-export?slug=logic&lang=el&view=archived')
rows = list(csv.reader(io.StringIO(body)))
check('the archive is exported on its own', st == 200 and 'archived-submissions.csv' in (hdrs.get('Content-Disposition') or '') and len(rows) == 3, (st, len(rows)))
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk()), ('slug', 'logic'), ('submission_action', 'restore'), ('id', ids[1]), ('return_to', '/admin/form-submissions?slug=logic&view=archived')])
check('one is restored, back to the archive view', st in (302, 303) and 'restored=1' in (hdrs.get('Location') or '') and 'view=archived' in (hdrs.get('Location') or '') and len(subs()) == 1, (st, hdrs.get('Location')))
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk()), ('slug', 'logic'), ('submission_action', 'bulk_delete'), ('selected_ids[]', ids[0]), ('return_to', '/admin/form-submissions?slug=logic&view=archived')])
check('an archived one is deleted', st in (302, 303) and 'deleted=1' in (hdrs.get('Location') or '') and subs('archive') == [], st)
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk()), ('slug', 'logic'), ('submission_action', 'restore'), ('id', 'nope'), ('return_to', '/admin/form-submissions?slug=logic')])
check('restoring what is not there says nothing was restored', 'Nothing+was+restored' in (hdrs.get('Location') or ''), hdrs.get('Location'))

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
