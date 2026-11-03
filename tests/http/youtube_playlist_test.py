import hashlib, json, os, re, sqlite3, sys, time
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)

PL = 'PLtestplaylist0001'
def seed(playlist, items, source='feed', title='Studio films', channel='The Studio'):
    """Puts a fetched playlist where the site keeps it, so no page asks YouTube (the tests do not reach the internet)."""
    key = 'youtube.' + hashlib.sha1((playlist + '|' + source).encode()).hexdigest()[:24]
    data = {'ok': True, 'source': source, 'title': title, 'channel': channel, 'stale': False, 'note': '', 'error': '',
            'items': [dict(i, duration=(('%d:%02d' % divmod(i['seconds'], 60)) if i.get('seconds') else ''), watch_url='https://www.youtube.com/watch?v=' + i['id']) for i in items]}
    con = db()
    con.execute('insert or replace into system_meta (key, value, updated_at) values (?, ?, ?)', (key, json.dumps({'at': int(time.time()), 'data': data}), '2026-01-01T00:00:00Z'))
    con.commit(); con.close()

def video(id_, title, published, views, seconds=None, description=''):
    return {'id': id_, 'title': title, 'description': description, 'published': published, 'views': views, 'seconds': seconds}

VIDEOS = [
    video('AAAAAAAAAAA', 'Oldest film', 1_400_000_000, 100, 185, 'The <b>first</b> one.'),
    video('BBBBBBBBBBB', 'Middle <script>alert(1)</script> film', 1_500_000_000, 9000, 3725, 'Second.'),
    video('CCCCCCCCCCC', 'Newest film', 1_700_000_000, 50, 60, 'Third.'),
    video('DDDDDDDDDDD', 'Fourth film', 1_600_000_000, 5, None, ''),
]
seed(PL, VIDEOS)

def page(slug, **block):
    fields = {'type': 'playlist', 'playlist': 'https://www.youtube.com/playlist?list=' + PL}
    fields.update(block)
    lines = ''.join('    %s: %s\n' % (k, json.dumps(v)) for k, v in fields.items() if k != 'type')
    write('pages/%s.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks:\n  - type: playlist\n%s---\n" % (slug, lines))
    return anon.get('/' + slug)

# ---- the block, as the editor is given it
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
defs = {d['type']: d for d in data['definitions']}
pl = defs.get('playlist')
check('the editor offers the playlist block, among the media blocks, with a picture', pl is not None and pl['category'] == 'Media' and pl['preview'] != '', pl and pl['category'])
check('with four layouts', pl and [v[0] for v in next(f for f in pl['common'] if f['key'] == 'variant')['options']] == ['grid', 'list', 'featured', 'strip'])
keys = [f['key'] for f in pl['fields']] if pl else []
check('and what to show, in what order, how, with what pictures', all(k in keys for k in ('playlist', 'limit', 'order', 'columns', 'ratio', 'play', 'thumbs', 'show_description', 'show_duration', 'show_views', 'show_date', 'show_numbers', 'link_label', 'use_title')), keys)

# ---- the grid
st, _, html = page('grid', limit=3, columns='2')
tiles = re.findall(r'<figure class="video-tile pl-tile[^"]*">.*?</figure>', html, re.S)
check('the page shows the videos of the playlist, as many as asked', st == 200 and len(tiles) == 3, (st, len(tiles)))
check('in the order of the playlist', re.findall(r'data-pl-title>([^<]*)<', html)[:3] == ['Oldest film', 'Middle <script>alert(1)</script> film'.replace('<', '&lt;').replace('>', '&gt;'), 'Newest film'], re.findall(r'data-pl-title>([^<]*)<', html))
check('what YouTube says is text, never markup', '<script>alert(1)</script>' not in html and '<b>first</b>' not in html and '&lt;b&gt;first&lt;/b&gt;' in html)
check('two columns', 'video-grid cols-2' in html)
check('a video plays in the viewer, from the privacy-friendly address, and nothing is loaded from YouTube until then', 'data-embed="https://www.youtube-nocookie.com/embed/AAAAAAAAAAA?' in html and 'data-mode="lightbox"' in html and '<iframe' not in html.split('<main')[1].split('</main>')[0])
check('the length is shown, with a way to read it for those who cannot see it', 'class="video-duration"' in html and '3:05' in html and 'Oldest film (3:05)' in html)
check('pictures are kept on this site, and each address is signed', re.search(r'src="/_yt/AAAAAAAAAAA\.jpg\?s=[0-9a-f]{16}"', html) is not None and 'i.ytimg.com' not in html)
check('the views and the date are not shown unless asked', 'pl-meta' not in html)
check('the stylesheet and script of the video block come with it', re.search(r'_blocks/[0-9a-f]+\.css\?b=[^"]*video[^"]*playlist|_blocks/[0-9a-f]+\.css\?b=[^"]*playlist[^"]*video', html) is not None and re.search(r'_blocks/[0-9a-f]+\.js\?b=[^"]*video', html) is not None, re.findall(r'_blocks/[0-9a-f]+\.(?:css|js)\?b=[^&"]*', html))

# ---- order, number and what is shown
st, _, html = page('newest', order='newest', limit=2)
check('newest first', re.findall(r'data-pl-title>([^<]*)<', html) == ['Newest film', 'Fourth film'], re.findall(r'data-pl-title>([^<]*)<', html))
st, _, html = page('oldest', order='oldest', limit=2)
check('oldest first', re.findall(r'data-pl-title>([^<]*)<', html)[0] == 'Oldest film')
st, _, html = page('views', order='views', limit=1)
check('most viewed first', re.findall(r'data-pl-title>([^<]*)<', html) == ['Middle &lt;script&gt;alert(1)&lt;/script&gt; film'], re.findall(r'data-pl-title>([^<]*)<', html))
st, _, html = page('shown', show_views=True, show_date=True, show_description=False, show_duration=False)
check('views and dates can be shown, in the language of the site', 'class="pl-meta"' in html and '9,000' in html.replace('.', ',') and re.search(r'\d{2}/\d{2}/\d{4}', html) is not None, re.findall(r'<span class="pl-meta"[^>]*>[^<]*', html)[:2])
check('without them there is no caption of description or length', 'video-caption' not in html and 'video-duration' not in html)
st, _, html = page('direct', thumbs='youtube', limit=1)
check('pictures can come from YouTube, when that is chosen', 'src="https://i.ytimg.com/vi/AAAAAAAAAAA/hqdefault.jpg"' in html and '/_yt/' not in html)
st, _, html = page('toyoutube', play='youtube', limit=1)
check('a video can lead to YouTube instead', 'data-video' not in html and 'href="https://www.youtube.com/watch?v=AAAAAAAAAAA"' in html)
st, _, html = page('inline', play='inline', limit=1)
check('or play in place', 'data-mode="inline"' in html)
st, _, html = page('linked', link_label='Watch the playlist on YouTube')
check('a link to the playlist, when it has words', 'href="https://www.youtube.com/playlist?list=%s"' % PL in html and 'Watch the playlist on YouTube' in html)
check('and none when it has none', 'youtube.com/playlist?list=' not in page('unlinked')[2])
st, _, html = page('titled', use_title=True)
check('the playlist\'s own title is the heading when asked', re.search(r'<h2[^>]*>\s*Studio films\s*</h2>', html) is not None, re.findall(r'<h2[^>]*>[^<]*', html)[:3])
check('and the heading typed wins', re.search(r'<h2[^>]*>\s*My heading\s*</h2>', page('titled2', use_title=True, heading='My heading')[2]) is not None)

# ---- the other layouts
st, _, html = page('list', variant='list', limit=3, show_numbers=True)
check('a list: a row for each video, with its title and its text, and numbers', len(re.findall(r'<li class="pl-row">', html)) == 3 and len(re.findall(r'class="pl-num"', html)) == 3 and 'Third.' in html)
st, _, html = page('strip', variant='strip', limit=4)
check('a strip that scrolls, which the keyboard can reach', 'pl-strip-list' in html and 'tabindex="0"' in html and len(re.findall(r'pl-tile', html)) >= 4)
st, _, html = page('featured', variant='featured', limit=4)
check('a player with a list beside it: the first video is the player, and every video is in the list', 'data-playlist-featured' in html and len(re.findall(r'data-pl-item', html)) == 4 and 'data-mode="inline"' in html and 'aria-current="true"' in html)
check('each item of the list says what it plays, for the script', 'data-embed="https://www.youtube-nocookie.com/embed/CCCCCCCCCCC' in html and 'data-title="Newest film"' in html)
check('the list works as plain links without JavaScript', re.search(r'<a class="pl-item[^"]*" href="https://www\.youtube\.com/watch\?v=BBBBBBBBBBB" target="_blank" rel="noopener"', html) is not None)

# ---- the pictures kept on this site
st, _, html = page('thumbs', limit=1)
src = re.search(r'src="(/_yt/AAAAAAAAAAA\.jpg\?s=[0-9a-f]{16})"', html).group(1)
os.makedirs('app/storage/cache/youtube', exist_ok=True)
open('app/storage/cache/youtube/AAAAAAAAAAA.jpg', 'wb').write(b'\xff\xd8\xff\xe0' + b'x' * 900)
st, hdrs, body = anon.get(src)
check('a picture is served from this site, as an image that may be kept', st == 200 and hdrs.get('Content-Type') == 'image/jpeg' and 'max-age' in hdrs.get('Cache-Control', ''), (st, dict(hdrs)))
st, _, _ = anon.get('/_yt/AAAAAAAAAAA.jpg?s=0000000000000000')
check('with a wrong signature it is not found', st == 404, st)
st, _, _ = anon.get('/_yt/AAAAAAAAAAA.jpg')
check('without one too, so the route cannot be used to fetch other pictures', st == 404, st)
st, _, _ = anon.get('/_yt/BBBBBBBBBBB.jpg?s=' + src.split('s=')[1])
check('a signature of one video does not open another', st == 404, st)
st, _, _ = anon.get('/_yt/short.jpg?s=x')
check('an address that is not a video id is not found', st == 404, st)

# ---- nothing to show
st, _, html = page('nothing', playlist='not a playlist')
check('a playlist that is not one leaves no section for a visitor', st == 200 and 'block-playlist' not in html and 'video-tile' not in html)
st, _, html = root.get('/nothing')
check('and tells the person who is signed in why', 'class="pl-note"' in html and 'not a YouTube playlist' in html, re.findall(r'pl-note[^<]*<', html))

# ---- the key, in Settings > APIs
def settings_form(f): return any(x[0] == 'youtube_cache_hours' for x in f['fields'])
st, _, html = root.get('/admin/settings?tab=apis')
check('the APIs tab has the YouTube key and how long to keep a playlist', st == 200 and 'name="youtube_key"' in html and 'name="youtube_cache_hours"' in html and 'No key' in html and 'Save and test the key' in html and 'How to get a key' in html)
check('it no longer says it is reserved for the future', 'Reserved for future integrations' not in html)
root.submit('/admin/settings?tab=apis', settings_form, {'youtube_key': 'AIzaSyTESTKEY1234567890', 'youtube_cache_hours': '24'})
st, _, html = root.get('/admin/settings?tab=apis')
check('a saved key is never sent back to the browser, only that there is one', 'AIzaSyTESTKEY1234567890' not in html and 'Key saved' in html and 'Remove the saved value' in html)
check('and how long to keep a playlist is what was chosen', re.search(r'<option value="24" selected>', html) is not None)
root.submit('/admin/settings?tab=apis', settings_form, {'youtube_key': ''})
check('a blank key keeps the one saved', 'Key saved' in root.get('/admin/settings?tab=apis')[2])
root.submit('/admin/settings?tab=basics', settings_form, {}, drop=['youtube_cache_hours'])
check('a form that does not carry the choice leaves it as it is', re.search(r'<option value="24" selected>', root.get('/admin/settings?tab=apis')[2]) is not None)
st, _, logs = root.get('/admin/activity-logs')
check('the key is not in the activity log', 'AIzaSyTESTKEY1234567890' not in logs)
root.submit('/admin/settings?tab=apis', settings_form, {'clear_secret[]': 'youtube_key'})
check('a key can be removed', 'No key' in root.get('/admin/settings?tab=apis')[2])
ed = Client()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed.login('ed1', 'Sturdy-pass-99')
check('an editor cannot reach the settings, nor the key', ed.get('/admin/settings?tab=apis')[0] == 403)
st, _, html = ed.get('/admin/edit?type=pages&slug=about&lang=en')
check('and the key is nowhere in what the editor is given', 'youtube_key' not in html and 'AIza' not in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
