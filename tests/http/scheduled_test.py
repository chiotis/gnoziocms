import re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def save(slug, status, publish_at=''):
    fields = {'type': 'posts', 'slug': slug, 'lang': 'en', 'title': 'Scheduled ' + slug, 'status': status, 'publish_at': publish_at, 'visible': '1', 'body': 'Text of ' + slug}
    return root.submit(f'/admin/edit?type=posts&slug={slug}&lang=en', has_field('body'), fields)
def text(slug): return open(f'app/content/posts/{slug}.en.md', encoding='utf-8').read()
def check_due():
    # The admin looks at scheduled entries at most once a minute; the test forgets when it last did.
    c = sqlite3.connect('app/storage/db/app.sqlite')
    c.execute("DELETE FROM system_meta WHERE key = 'schedule_checked_at'"); c.commit(); c.close()

# ---- the editor offers it
st, _, html = root.get('/admin/edit?type=posts&slug=rebrand-readiness-guide&lang=en')
check('the status offers Scheduled', st == 200 and '<option value="scheduled"' in html)
check('with a Publish at box, hidden while the entry is published', re.search(r'class="en-field is-wide hidden" data-publish-at', html) is not None and 'type="datetime-local" name="publish_at"' in html)

# ---- a time that has passed
st, hdr, _ = save('sched-past', 'scheduled', '2020-01-02T09:00')
check('a scheduled entry saves', st in (302, 303) and 'saved=1' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
check('the file says scheduled, with its time', 'status: scheduled' in text('sched-past') and "publish_at: '2020-01-02 09:00'" in text('sched-past'), text('sched-past')[:300])
check('the public feed does not list it yet', 'Scheduled sched-past' not in anon.get('/en/feed')[2])
st, _, html = root.get('/admin/content?type=posts&lang=en&status=scheduled')
check('the content list filters by Scheduled', st == 200 and 'Scheduled sched-past' in html, st)
root.get('/admin')
check('within a minute of the last look it waits', 'status: scheduled' in text('sched-past'))

check_due()
root.get('/admin')
check('opening the admin publishes it', 'status: published' in text('sched-past') and 'publish_at' not in text('sched-past'), text('sched-past')[:300])
check('with the day as its date', "date: '2020-01-02'" in text('sched-past'), text('sched-past')[:300])
check('the public feed lists it now', 'Scheduled sched-past' in anon.get('/en/feed')[2])
st, _, html = root.get('/admin/revisions?actor=Schedule')
check('the history says it was published on schedule', st == 200 and 'Published on schedule' in html, st)

# ---- a time to come
save('sched-future', 'scheduled', '2099-01-02T09:00')
check_due()
root.get('/admin')
check('a time to come stays scheduled', 'status: scheduled' in text('sched-future'))
st, _, html = root.get('/admin/edit?type=posts&slug=sched-future&lang=en')
check('the editor shows it as scheduled, with the time', 'data-status-pill>Scheduled<' in html and 'value="2099-01-02T09:00"' in html, re.findall(r'data-status-pill>[^<]*<', html))
check('the public feed does not list it', 'Scheduled sched-future' not in anon.get('/en/feed')[2])

# ---- no time
save('sched-none', 'scheduled', '')
check('scheduled without a time is saved as a draft', 'status: draft' in text('sched-none'), text('sched-none')[:200])

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
