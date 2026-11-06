"""
Manage > AI publishing over HTTP, without calling OpenAI: the menu entry, the tabs, a key saved and never sent back, the
instructions and limits saved, the usage tab and its CSV, the editor's Generate panel once there is a key, the picture
endpoint refusing a request without the form token, an empty subject, and while paused, and who may open what (an editor
neither sees the module nor the panel's settings, but may make pictures from the editor).
"""
import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

KEY = 'sk-test-offline-0123456789abcdefWXYZ'
root = Client(); root.login()

st, _, html = root.get('/admin')
check('the menu has AI publishing under Manage', st == 200 and 'admin/ai-publishing' in html and 'AI publishing' in html)

st, _, html = root.get('/admin/ai-publishing')
check('with no key it opens on Settings', st == 200 and 'name="openai_key"' in html and 'Getting an OpenAI key' in html, st)
check('it says there is no key yet', 'No key yet' in html)

st, hdr, _ = root.submit('/admin/ai-publishing?tab=settings', has_field('openai_key'),
                         {'openai_key': KEY, 'monthly_cost_cap': '12.5', 'monthly_picture_cap': '40', 'cap_action': 'pause', 'keep_logs_days': '30'},
                         button=('ai_action', 'save_settings'))
loc = hdr.get('Location') or ''
check('settings are saved', st in (302, 303) and 'done=ok' in loc and 'tab=settings' in loc, (st, loc))
st, _, html = root.get(re.sub(r'^https?://[^/]+', '', loc))
check('the key is never sent back, only its end', KEY not in html and 'Key …WXYZ' in html, st)
check('the caps are shown as saved', 'value="12.5"' in html and 'value="40"' in html)
check('it says to check the key', 'Press Check key' in html)

st, hdr, _ = root.submit('/admin/ai-publishing?tab=instructions', has_field('house_style'),
                         {'house_style': 'Watercolour, teal and sand, one subject.', 'picture_avoid': 'people\ncars', 'aspect': '3:2', 'quality': 'low', 'format': 'webp', 'variants': '3',
                          'references': '/uploads/media/one.webp\nhttps://elsewhere.example/a.png'},
                         button=('ai_action', 'save_instructions'))
st, _, html = root.get('/admin/ai-publishing?tab=instructions')
check('the house style is kept', 'Watercolour, teal and sand, one subject.' in html, st)
check('avoid lines and choices are kept', 'people\ncars' in html and '<option value="3:2" selected' in html and '<option value="low" selected' in html)
check('only library pictures are kept as references', '/uploads/media/one.webp' in html and 'elsewhere.example' not in html)

st, _, html = root.get('/admin/ai-publishing?tab=usage')
check('the usage tab opens', st == 200 and 'Estimated cost' in html and 'Export CSV' in html, st)
st, hdr, body = root.get('/admin/ai-publishing?tab=usage&export=csv')
check('usage exports as CSV', st == 200 and 'text/csv' in (hdr.get('Content-Type') or '') and body.startswith('time,source,ref,entry'), (st, body[:80]))

# The editor offers Generate once there is a key.
st, _, html = root.get('/admin/edit?type=posts&slug=rebrand-readiness-guide&lang=el')
check('the editor has the Generate panel', st == 200 and 'data-ai-picture' in html and 'admin-ai-picture.js' in html, st)
st, _, js = root.get('/assets/js/admin-ai-picture.js')
check('its script is served', st == 200 and 'data-ai-generate' in js, st)
token = re.search(r'name="csrf-token" content="([^"]+)"', html)
token = token.group(1) if token else ''

st, _, body = root.request('/admin/ai-picture', data={'action': 'start', 'subject': 'A harbour.'})
check('the picture endpoint refuses a request without the form token', st == 419, st)
st, _, body = root.request('/admin/ai-picture', data={'action': 'start', 'subject': '  ', '_csrf': token})
check('an empty subject is refused', st == 422 and 'Say what the picture shows' in body, (st, body[:120]))

# Bulk: Content list > Generate main images. The test server cannot make outside calls, so a picture fails with a plain sentence.
st, _, html = root.get('/admin/content?type=posts&lang=el')
check('the list offers Generate main images', st == 200 and 'value="generate_images"' in html and 'data-ai-bulk-apply' in html, st)
check('a row with a main image is marked for the confirmation', 'data-has-image' in html)
st, hdr, _ = root.submit('/admin/content?type=posts&lang=el', has_field('bulk_action'), {'bulk_action': 'generate_images', 'selected[]': ['rebrand-readiness-guide']})
loc = hdr.get('Location') or ''
st, _, html = root.get(re.sub(r'^https?://[^/]+', '', loc))
check('an entry with a main image is left as it is', 'bulk=fail' in loc and 'Every selected entry has a main image' in html, (loc, st))

st, hdr, _ = root.submit('/admin/content?type=posts&lang=en', has_field('bulk_action'), {'bulk_action': 'generate_images', 'selected[]': ['rebrand-readiness-guide']})
loc = hdr.get('Location') or ''
check('one picture is queued', 'bulk=ok' in loc and '1+picture+queued' in loc, loc)
st, _, html = root.get('/admin/content?type=posts&lang=en')
check('the list shows the progress line and the row waiting', 'data-ai-bulk-progress' in html and 'Making AI pictures: 1 left' in html and 'Picture waiting' in html, st)
st, _, body = root.request('/admin/ai-picture?bulk=posts&lang=en')
check('polling works the job; without outside calls it fails', st == 200 and '"failed":1' in body and '"waiting":0' in body, (st, body[:160]))
st, _, html = root.get('/admin/content?type=posts&lang=en')
check('the row says the picture failed, and why', 'Picture failed' in html and 'curl' in html, st)
st, _, html = root.get('/admin/ai-publishing?tab=runs')
check('the Runs tab lists it, from Bulk, with the reason and Retry', st == 200 and 'Bulk' in html and 'posts/rebrand-readiness-guide' in html and 'curl' in html and 'value="retry_job"' in html, st)
root.submit('/admin/ai-publishing?tab=runs', lambda f: any(b[1] == 'retry_job' for b in f['buttons']), button=('ai_action', 'retry_job'))
st, _, html = root.get('/admin/ai-publishing?tab=runs&show=live')
check('Retry puts it back in the queue', 'value="cancel_job"' in html and 'Cancel the 1 waiting' in html, st)
root.submit('/admin/ai-publishing?tab=runs', lambda f: any(b[1] == 'cancel_waiting' for b in f['buttons']), button=('ai_action', 'cancel_waiting'))
st, _, html = root.get('/admin/ai-publishing?tab=runs&show=live')
check('Cancel the waiting empties the queue', 'No pictures yet with this status' in html, st)

# Paused: nothing is queued (so no test ever calls OpenAI).
root.submit('/admin/ai-publishing?tab=instructions', lambda f: any(b[1] == 'pause' for b in f['buttons']), button=('ai_action', 'pause'))
st, _, html = root.get('/admin/ai-publishing?tab=instructions')
check('Pause all pauses', 'Paused' in html and 'Resume' in html)
st, _, body = root.request('/admin/ai-picture', data={'action': 'start', 'subject': 'A harbour.', '_csrf': token})
check('while paused no picture is queued', st == 422 and 'paused' in body, (st, body[:120]))
st, _, body = root.request('/admin/ai-picture?job=999999')
check('an unknown job answers missing', st == 404 and '"missing"' in body, (st, body[:120]))

# An editor: no module, but the panel in the editor.
root.submit('/admin/users-edit', has_field('username'), {'username': 'ai_ed', 'email': 'ai_ed@example.test', 'display_name': 'ai_ed', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ai_ed', 'Sturdy-pass-99')
st, _, html = ed.get('/admin/ai-publishing')
check('an editor cannot open the module', st == 403, st)
st, _, html = ed.get('/admin')
check('and does not see it in the menu', 'admin/ai-publishing' not in html)
st, _, html = ed.get('/admin/edit?type=posts&slug=rebrand-readiness-guide&lang=el')
check('but has Generate in the editor', st == 200 and 'data-ai-picture' in html, st)
check('which says it is paused', 'AI publishing is paused' in html)
st, _, html = ed.get('/admin/content?type=posts&lang=el')
check('and Generate main images in the list', 'value="generate_images"' in html, st)

root.submit('/admin/ai-publishing?tab=instructions', lambda f: any(b[1] == 'resume' for b in f['buttons']), button=('ai_action', 'resume'))
# Leave no key behind for the other tests.
root.submit('/admin/ai-publishing?tab=settings', has_field('openai_key'), {'clear_key': '1'}, button=('ai_action', 'save_settings'))
st, _, html = root.get('/admin/ai-publishing?tab=settings')
check('the key can be removed', 'No key' in html and '…WXYZ' not in html, st)

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
