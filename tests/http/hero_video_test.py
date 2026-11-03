import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def put(path, text):
    full = 'app/content/' + path
    os.makedirs(os.path.dirname(full), exist_ok=True)
    open(full, 'w', encoding='utf-8').write(text)
def page(name, variant, extra):
    put('pages/%s.md' % name, "---\ntitle: %s\nstatus: published\nvisible: true\nblocks:\n  - type: hero\n    variant: %s\n    heading: Opening\n    text: Words\n%s---\n\nBody\n" % (name, variant, extra))
    return pub.get('/' + name)[2]
def block(html):
    m = re.search(r'<section class="block block-hero.*?</section>', html, re.S)
    return m.group(0) if m else ''

# ---- the block editor knows the fields
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=el')
m = re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S) or re.search(r'<script type="application/json"[^>]*>(\{"definitions".*?)</script>', edit, re.S)
defs = json.loads(m.group(1)) if m else {}
hero = next((d for d in defs.get('definitions', []) if d['type'] == 'hero'), {})
fields = {f['key']: f for f in hero.get('fields', [])}
check('the Hero block offers a choice of picture, and a video with its still image', all(k in fields for k in ('background', 'video', 'video_poster')) and fields.get('video', {}).get('type') == 'video', list(fields))
check('the choice is an image or a video, an image to begin with', [o[0] for o in fields['background']['options']] == ['image', 'video'] and fields['background']['default'] == 'image')
check('the image shows only for an image, the video and its still image only for a video, both only for typed text (the editor reads "when")', fields['image'].get('when') == {'background': ['image'], 'source': ['manual']} and fields['video'].get('when') == {'background': ['video'], 'source': ['manual']} and fields['video_poster'].get('when') == {'background': ['video'], 'source': ['manual']} and fields['image_alt'].get('when') == {'background': ['image'], 'source': ['manual']}, [fields[k].get('when') for k in ('image', 'video', 'video_poster', 'image_alt')])
js = root.get('/assets/js/admin-blocks.js')[2]
check('and the editor script handles a video field (the Library for videos) and the "when" of a field', "type === 'video'" in js and 'field.when' in js and "'video')" in js)
pj = root.get('/assets/js/admin-media-picker.js')[2]
check('the picker can choose a video, and says so', "kind === 'video'" in pj and "Choose a ' + noun" in pj and 'data-media-kind' in pj)
st, _, body = root.request('/admin/media-picker?kind=video')
check('the picker asks the library for videos and not images', st == 200 and json.loads(body)['items'] == [], body[:100])

# ---- a video file from the library or from elsewhere
html = page('hv-file', 'cover', "    background: video\n    video: /uploads/media/clip.mp4\n    video_poster: /uploads/media/5e6915a67b9ceec5.jpg\n")
b = block(html)
check('a video file plays in a <video>: silent, in a loop, started by itself, inline on a phone', '<video muted loop playsinline autoplay preload="metadata"' in b and '<source src="/uploads/media/clip.mp4" type="video/mp4">' in b, b[:600])
check('it is decoration for a screen reader and cannot take the focus', 'aria-hidden="true" tabindex="-1"><source' in b)
check('behind the text, where the cover image would be, with a still image under it', 'hero-cover-media' in b and 'class="hero-video"' in b and re.search(r'<picture|<img', b) is not None and b.index('hero-cover-media') < b.index('hero-inner'))
check('a pause button is there for everyone: hidden until the script has it', 'data-hero-video-toggle' in b and ' hidden>' in b and 'Pause the background video' in b or 'Παύση του βίντεο φόντου' in b)
check('the page loads no frame and nothing from another site', '<iframe' not in b and 'youtube' not in b)
check('the block script is part of the page when a hero is there', 'blocks/hero' in html or '_blocks.js' in html or re.search(r'_blocks/[0-9a-f]+\.js', html) is not None, re.findall(r'<script[^>]*src="[^"]*"', html)[-2:])
check('the Hero image field is not drawn when there is a video', 'hero-media' not in b)

html = page('hv-webm', 'cover', "    background: video\n    video: https://cdn.example.test/loop.webm\n")
b = block(html)
check('a file on another site works the same way, with its type', '<source src="https://cdn.example.test/loop.webm" type="video/webm">' in b)
check('without a still image there is none to draw, and the cover is ink', '<picture' not in b and '<img' not in b)

# ---- YouTube and Vimeo: a frame the page opens after it has loaded
html = page('hv-yt', 'cover', "    background: video\n    video: https://www.youtube.com/watch?v=dQw4w9WgXcQ\n    video_poster: /uploads/media/5e6915a67b9ceec5.jpg\n")
b = block(html)
check('YouTube is a place for a frame, not a frame: nothing is asked of YouTube until the script opens it', 'data-provider="youtube"' in b and '<iframe' not in b and 'data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;mute=1&amp;controls=0&amp;loop=1&amp;playlist=dQw4w9WgXcQ' in b, b[:500])
check('it is the privacy host, silent, without controls, in a loop', 'youtube-nocookie.com' in b and 'mute=1' in b and 'controls=0' in b and 'loop=1' in b and 'youtube.com/embed' not in b.replace('youtube-nocookie.com/embed', ''))
check('the still image is under it', '<picture' in b or '<img' in b)
html = page('hv-vimeo', 'split', "    background: video\n    video: https://vimeo.com/76979871\n")
b = block(html)
check('Vimeo is the same, in its background mode', 'data-provider="vimeo"' in b and 'background=1' in b and 'autoplay=1' in b and 'dnt=1' in b and '<iframe' not in b, b[:300])

# ---- the other layouts: the video takes the place of the image
html = page('hv-split', 'split', "    background: video\n    video: /uploads/media/clip.mp4\n")
b = block(html)
check('in the split layout the video sits in the frame where the image would be, with its button', 'hero-media media-frame' in b and '<video muted loop' in b and b.count('data-hero-video-toggle') == 1 and 'hero-cover-media' not in b)
html = page('hv-centered', 'centered', "    background: video\n    video: /uploads/media/clip.mp4\n")
check('and in the centered one', 'hero-media media-frame' in block(html) and '<video muted loop' in block(html))
html = page('hv-minimal', 'minimal', "    background: video\n    video: /uploads/media/clip.mp4\n")
b = block(html)
check('the minimal hero has no picture, so no video either', '<video' not in b and 'no-media' in b)
html = page('hv-steps', 'steps', "    background: video\n    video: /uploads/media/clip.mp4\n    items:\n      - title: One\n        text: First\n")
b = block(html)
check('the steps layout has the video behind it, and the steps', '<video muted loop' in b and 'hero-steps' in b and 'hero-cover-media' in b)

# ---- what is not a video the site can play
html = page('hv-bad', 'cover', "    background: video\n    video: https://example.test/page.html\n    image: /uploads/media/5e6915a67b9ceec5.jpg\n")
b = block(html)
check('a link that is not a video is not used: the image is the picture', 'data-hero-video' not in b and 'hero-cover-media' in b and '<video' not in b)
html = page('hv-js', 'cover', "    background: video\n    video: 'javascript:alert(1)'\n")
check('a script address is never used', 'javascript:' not in html and 'data-hero-video' not in block(html))
html = page('hv-iframe', 'cover', "    background: video\n    video: https://evil.example.test/embed/x\n")
check('and no other site gets a frame', '<iframe' not in html and 'evil.example' not in html)
html = page('hv-image', 'cover', "    background: image\n    video: /uploads/media/clip.mp4\n    image: /uploads/media/5e6915a67b9ceec5.jpg\n")
b = block(html)
check('with the picture on "image" a video that was typed is ignored', '<video' not in b and 'data-hero-video' not in b and 'hero-cover-media' in b)
html = page('hv-old', 'cover', "    image: /uploads/media/5e6915a67b9ceec5.jpg\n")
b = block(html)
check('a hero saved before there were videos is as it was', 'hero-cover-media' in b and 'data-hero-video' not in b)

# ---- the page it opens: share image, transparent header
html = page('hv-share', 'cover', "    background: video\n    video: /uploads/media/clip.mp4\n    video_poster: /uploads/media/5e6915a67b9ceec5.jpg\n")
check('the still image is the share image of the page when it has none', re.search(r'<meta property="og:image" content="[^"]*5e6915a67b9ceec5', html) is not None)
html = page('hv-header', 'cover', "    background: video\n    video: /uploads/media/clip.mp4\n")
check('the page opens with a hero: the header can be over it, light, as over a picture', 'block-hero' in html)

# ---- the script and the style
sheet = ''
m = re.search(r'(?:href)="([^"]*_blocks[./][^"]*\.css[^"]*)"', pub.get('/hv-file')[2])
if m:
    sheet = pub.get(m.group(1))[2]
check('the style makes the video fill the space and crops a frame to cover it', '.hero-video' in sheet and 'object-fit: cover' in sheet and '177.78cqh' in sheet and '.hero-video-toggle' in sheet, sheet[:80])
m = re.search(r'src="([^"]*_blocks[./][^"]*\.js[^"]*)"', pub.get('/hv-file')[2])
script = pub.get(m.group(1))[2] if m else ''
check('the script puts the frame in after the page has opened, respects reduced motion and data saving, and has the pause button', 'prefers-reduced-motion' in script and 'saveData' in script and 'data-hero-video-toggle' in script and "createElement('iframe')" in script, script[:60])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
