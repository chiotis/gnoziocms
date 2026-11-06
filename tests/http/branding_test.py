import json, os, re, sys, sqlite3, urllib.parse
from html import unescape
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:260]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''

root = Client(); root.login()
pub = Client()
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

def post_theme(overrides, tab='branding'):
    """Submit the Theme form the way the browser does: its own values, then what the test changes."""
    form = next(f for f in root.forms('/admin/theme?tab=' + tab) if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] != 'active_tab']
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            if item is not None:
                fields.append((k, item))
    fields.append(('active_tab', tab))
    return root.request('/admin/theme', data=fields), fields
def d(**v): return {'theme_settings[design][%s]' % k: val for k, val in v.items()}
def b(**v): return {'theme_settings[brand][%s]' % k: val for k, val in v.items()}
def a(**v): return {'theme_settings[appearance][%s]' % k: val for k, val in v.items()}
def page(path='/about'):
    return pub.get(path)[2]
def style(html):
    m = re.search(r'<style id="faros-branding">(.*?)</style>', html, re.S)
    return m.group(1) if m else ''
def header(html):
    m = re.search(r'<header class="site-header[^"]*"[^>]*>.*?</header>', html, re.S)
    return m.group(0) if m else ''
def footer(html):
    m = re.search(r'<footer class="site-footer.*?</footer>', html, re.S)
    return m.group(0) if m else ''
def asset(kind):
    m = re.search(r'(?:href|src)="([^"]*site\.%s[^"]*)"' % kind, page())
    u = urllib.parse.urlparse(m.group(1)) if m else None
    return pub.get(u.path + ('?' + u.query if u.query else ''))[2] if u else ''

# ---- a site that sets nothing is styled by the theme alone
html = page()
check('without any choice the page has no branding style and no icons', 'faros-branding' not in html and 'rel="icon"' not in html and 'theme-color' not in html and 'apple-touch-icon' not in html)

# ---- the tab
st, _, html = root.get('/admin/theme')
labels = re.findall(r'role="tab"[^>]*data-tab="([a-z_]+)"[^>]*>([^<]+)</button>', html)
check('Branding is the first tab and replaces Appearance and Brand', st == 200 and labels[0] == ('branding', 'Branding') and not any(k in ('appearance', 'brand', 'design') for k, _ in labels), labels)
check('it is open first', re.search(r'class="[^"]*\bactive\b[^"]*" data-tab="branding"', html) is not None and 'name="active_tab" value="branding"' in html)
panels = dict(re.findall(r'data-panel="([a-z_]+)" role="tabpanel">(.*?)(?=<div class="tab-panel|<div class="flex flex-col gap-1 rounded-lg)', html, re.S))
typo, brand = panels.get('typography', ''), panels.get('branding', '')
check('Typography is the tab after Branding', [k for k, _ in labels][:2] == ['branding', 'typography'] and labels[1][1] == 'Typography', labels[:3])
check('it holds the fonts, the text, the headings and the elements, which Branding no longer has', all(('name="theme_settings[design][%s]"' % k) in typo and ('name="theme_settings[design][%s]"' % k) not in brand for k in ('heading_font', 'body_font', 'base_size', 'line_height', 'scale', 'heading_weight', 'heading_case', 'h1_step', 'h3_tracking', 'lead_step', 'small_weight', 'body_weight', 'paragraph_space')) and 'name="theme_settings[appearance][font]"' in typo and 'data-bf ' in typo, [k for k in ('heading_font', 'base_size', 'h1_step') if ('name="theme_settings[design][%s]"' % k) not in typo])
check('the heading colour stays in Branding, and each tab points at the other', 'name="theme_settings[design][heading_color]"' in brand and 'name="theme_settings[design][heading_color]"' not in typo and 'data-goto-tab="typography"' in brand and 'data-goto-tab="branding"' in typo)
check('Typography has its own live preview, its own reset metadata and resets for each card', 'data-bp-frame' in typo and 'theme?preview=branding' in typo and 'theme_settings[design][base_size]' in unescape(re.search(r'data-bp-defaults="([^"]+)"', typo).group(1)) and all(('data-bp-reset="%s"' % k) in typo for k in ('fonts', 'text', 'headings', 'elements')))
check('the Navigation card sets size, weight, case and spacing for menus, top bar, footer, breadcrumbs and the site name', all(('name="theme_settings[design][%s_%s]"' % (r, c)) in typo for r in ('nav', 'dropdown', 'drawer', 'topbar', 'footer_nav', 'footer_title', 'crumbs') for c in ('size', 'weight', 'case', 'tracking')) and all(('name="theme_settings[design][name_%s]"' % c) in typo for c in ('weight', 'case', 'tracking')) and 'data-bp-reset="navigation"' in typo)
check('the size of the site name moved there from Identity', 'name="theme_settings[brand][name_size]"' in typo and 'name="theme_settings[brand][name_size]"' not in brand and html.count('name="theme_settings[brand][name_size]"') == 1)
check('Headings has H1 to H6 and Text elements the eyebrow, meta, quote, caption and card title, each with a Case', all(('name="theme_settings[design][%s_%s]"' % (r, c)) in typo for r in ('h1', 'h4', 'h6', 'lead', 'eyebrow', 'meta', 'quote', 'caption', 'card_title') for c in ('step', 'weight', 'leading', 'tracking', 'case')))
names = re.findall(r'<(?:input|select|textarea)\b[^>]*\bname="(theme_settings\[[^"]+\])"', html)
dupes = sorted({n for n in names if names.count(n) > 1 and not n.endswith('[]') and 'type="radio"' not in n})
radio_names = set(re.findall(r'<input\b[^>]*type="radio"[^>]*name="(theme_settings\[[^"]+\])"', html)) | set(re.findall(r'<input\b[^>]*name="(theme_settings\[[^"]+\])"[^>]*type="radio"', html))
dupes = [n for n in dupes if n not in radio_names]
check('Typography has the interface and code fonts, numbers, ligatures, phone size, alignment, hyphenation, heading breaks, and the text of buttons and forms, which Branding no longer has', all(('name="theme_settings[design][%s]"' % k) in typo and ('name="theme_settings[design][%s]"' % k) not in brand for k in ('ui_font', 'code_font', 'numerals', 'ligatures', 'phone_text_size', 'text_align', 'hyphens', 'heading_wrap', 'button_size', 'button_weight', 'button_case', 'button_tracking', 'label_size', 'label_weight', 'input_size')) and 'data-bp-reset="buttons-forms"' in typo and 'name="theme_settings[design][button_height]"' in brand and 'name="theme_settings[design][input_bg]"' in brand)
check('no setting of the theme has two fields on the screen (Typography is not repeated in Branding > More)', dupes == [], dupes[:10])
check('a field appears once on the screen', html.count('name="theme_settings[design][heading_font]"') == 1 and html.count('name="theme_settings[design][h1_step]"') == 1)
for key in ('heading_font', 'body_font', 'font_file', 'base_size', 'line_height', 'scale', 'heading_weight', 'heading_tracking', 'heading_leading', 'heading_case', 'heading_color',
            'container', 'reading_width', 'gutter', 'section_space', 'header_height', 'spacing', 'radius', 'button_radius', 'shadows', 'button_height', 'button_pad', 'button_size', 'button_weight', 'button_case'):
    if ('name="theme_settings[design][%s]"' % key) not in html:
        check('a field for ' + key, False)
        break
else:
    check('a field for every design choice', True)
check('a choice among numbers (a weight) offers the numbers themselves', re.findall(r'name="theme_settings\[design\]\[heading_weight\]" value="([^"]+)"', html) == ['auto', '400', '500', '600', '700', '800'] and re.findall(r'name="theme_settings\[design\]\[button_weight\]" value="([^"]+)"', html) == ['auto', '500', '600', '700', '800'], re.findall(r'name="theme_settings\[design\]\[heading_weight\]" value="([^"]+)"', html))
check('and for the logo, its dark version, the sizes, the icons, the browser colour and the share image', all(('name="theme_settings[brand][%s]"' % k) in html for k in (
    'logo', 'logo_dark', 'show_name', 'logo_height', 'logo_height_mobile', 'footer_logo_height', 'name_size', 'favicon', 'touch_icon', 'theme_color', 'share_image')))
check('and for palette, mode, font pairing, corners and decoration', all(('name="theme_settings[appearance][%s]"' % k) in html for k in ('palette', 'mode', 'font', 'shape', 'glow')))
check('the palettes are chips with a dot of their colour', html.count('class="bp-pal"') == 1 and len(re.findall(r'name="theme_settings\[appearance\]\[palette\]" value="[a-z]+"', html)) == 7)
check('colours can be picked and typed and cleared', html.count('data-colour') >= 5 and 'type="color"' in html and 'lc-colour-x' in html)
check('numbers say their unit and may be empty', 'class="bp-num"' in html and 'class="lc-unit"' in html and re.search(r'name="theme_settings\[design\]\[radius\]" value=""', html) is not None)
check('the colours of a palette are a table that says what is light and what is dark', 'data-pl-roles' in html and 'scope="col">Light<' in html and 'scope="col">Dark<' in html)
check('the Colour card has no colour fields of its own besides the palette and the headings', 'name="theme_settings[design][light_accent]"' not in html and 'name="theme_settings[design][dark_background]"' not in html and 'name="theme_settings[design][ink]"' not in html)
check('the real site is shown in a frame beside it, with a size and a mode to choose', 'data-bp-frame' in html and 'title="Preview of the site"' in html and html.count('data-bp-size') >= 3 and html.count('data-bp-mode') >= 3)
check('it asks for the preview at the address of the screen, and loads its script', 'data-endpoint="' in html and 'theme?preview=branding' in html and 'admin-branding.js' in html and html.count('js/admin-preview.js') == 1)
js = root.get('/assets/js/admin-preview.js')
check('the script of the preview is served', js[0] == 200 and 'faros-branding' in js[2] and 'preview_path' in js[2], js[0])
check('and the one of the colours and the resets', root.get('/assets/js/admin-branding.js')[0] == 200)
check('the old tabs are gone, an old link opens the first', root.get('/admin/theme?tab=brand')[2].count('name="active_tab" value="branding"') == 1)
defaults_match = re.search(r'data-bp-defaults="([^"]+)"', html)
defaults = json.loads(unescape(defaults_match.group(1))) if defaults_match else {}
check('reset metadata comes from the schema, including heading colour text', defaults.get('theme_settings[design][heading_color]', {}).get('value') == 'text' and defaults.get('theme_settings[design][radius]', {}).get('value') == '')
check('equivalent choices preserve inheritance and only map supported shapes', defaults.get('theme_settings[design][button_weight]', {}).get('equivalent') == {'value': '600'} and defaults.get('theme_settings[design][button_radius]', {}).get('equivalent') == {'field': 'theme_settings[appearance][shape]', 'values': {'soft': 'pill', 'rounded': 'pill'}} and defaults.get('theme_settings[design][heading_weight]', {}).get('equivalent') is None)
check('inherited choices explain their actual behavior', all(label in html for label in ('>By element<', '>Theme scale<', '>From shape<', '>Soft<', '>Normal<')) and 'Reset restores the theme value' in html)
os.makedirs('app/custom/assets/css', exist_ok=True)
with open('app/custom/assets/css/custom.css', 'w') as f: f.write(':root { --btn-weight: 700; --radius-button: 0; }')
custom_html = root.get('/admin/theme')[2]
custom_defaults = json.loads(unescape(re.search(r'data-bp-defaults="([^"]+)"', custom_html).group(1)))
check('custom CSS keeps inherited choices explicit rather than guessing their value', custom_defaults['theme_settings[design][button_weight]']['equivalent'] is None and custom_defaults['theme_settings[design][button_radius]']['equivalent'] is None and '>By element<' not in custom_html)
os.unlink('app/custom/assets/css/custom.css')
cards = re.findall(r'<details class="bp-card"[^>]*>(.*?)</details>', html, re.S)
buttons_card = next((card for card in cards if '<span>Buttons</span>' in card), '')
colour_card = next((card for card in cards if '<span>Colour</span>' in card), '')
check('button corners live with buttons and heading colour with its colours', 'name="theme_settings[design][button_radius]"' in buttons_card and 'name="theme_settings[design][heading_color]"' in colour_card)
check('Branding can switch to style samples and preview controls have separate names per tab', 'data-bp-view' in html and 'theme?preview=styles' in html and all('name="bp_size_%s"' % tab in html for tab in ('branding', 'header', 'footer')))
before_samples = stored()
st, hdr, samples = root.get('/admin/theme?preview=styles')
check('style samples use the real theme, typography, buttons, card and fields', st == 200 and '<body class="theme-body">' in samples and 'site.css' in samples and all(tag in samples for tag in ('<h1>', '<h6>', 'class="prose"', 'class="btn btn-primary"', 'class="content-item"', 'class="form-grid"', 'tone-accent', 'tone-contrast')))
check('samples have no submit form, keep admin frame protection, cannot be indexed or cached, and store nothing', '<form' not in samples and hdr.get('X-Frame-Options') == 'DENY' and 'no-store' in (hdr.get('Cache-Control') or '') and 'noindex' in (hdr.get('X-Robots-Tag') or '') and stored() == before_samples)
check('the samples show every element of the Typography tab: H1 to H6, eyebrow, lead, meta, quote, caption, a card, the menus, breadcrumbs and the footer', all(x in samples for x in ('<h4>', '<h6>', 'class="eyebrow"', 'class="lead"', 'class="content-meta"', '<blockquote>', '<figcaption>', 'class="content-item-title"', 'class="nav-link"', 'nav-list-2', 'class="crumbs"', 'class="footer-title"', 'class="footer-link"')))
check('style samples require theme access', ed.get('/admin/theme?preview=styles')[0] in (302, 403) and pub.get('/admin/theme?preview=styles')[0] in (302, 403))

# ---- saving: colours
(res, hdr, _), sent = post_theme({**d(light_accent='#C026D3', dark_accent='93c5fd', light_background='#fffdf8', ink='#323130'), **b(theme_color='#1e293b')})
check('saving returns to the Branding tab', res == 302 and 'tab=branding' in (hdr.get('Location') or ''), (res, hdr.get('Location')))
check('colours sent the old way become a site palette on top of the ready-made one, stored as #rrggbb in lower case, and the override fields stay empty', all(x in stored() for x in ('"accent":"#c026d3"', '"accent":"#93c5fd"', '"background":"#fffdf8"', '"ink":"#323130"', 'Slate (custom)', "light_accent: ''", "ink: ''")), stored()[:900])
css = style(page())
check('the page has them in a style of its own, with the shades worked out', '--accent-l:#c026d3;' in css and '--accent-l-hover:' in css and '--accent-l-soft:' in css and '--accent-d:#93c5fd;' in css and '--n-bg:#fffdf8;' in css and '--ink:#323130;' in css and '--ink-rgb:50 49 48;' in css, css[:300])
html = page()
check('after the theme\'s style sheet and before the site\'s own', html.index('site.css') < html.index('id="faros-branding"'), None)
check('and the browser colour is in the head', '<meta name="theme-color" content="#1e293b">' in html)
(res, hdr, _), _ = post_theme(d(light_accent='not a colour', light_text='red', light_surface='#12'))
check('a colour that is not hex changes nothing', '"accent":"#c026d3"' in stored() and 'not a colour' not in stored() and '"text"' not in stored().split('site_palettes')[1].split('site_palette:')[0], stored()[:900])
(res, hdr, _), _ = post_theme(d(site_palette='', site_palettes=''))
check('taking the site palette away gives the page back to the ready-made palette', style(page()) == '', style(page())[:200])

# ---- saving: type, spacing, shape, buttons
(res, hdr, _), _ = post_theme({**d(heading_font='geometric', body_font='slab', base_size='18', scale='1.25', line_height='1.8', heading_weight='800', heading_tracking='wide', heading_leading='tight', heading_case='uppercase',
                                    container='1400', reading_width='800', gutter='48', section_space='80', header_height='80', spacing='airy', radius='6', button_radius='square', shadows='strong',
                                    button_height='54', button_pad='30', button_size='16', button_weight='700', button_case='uppercase'),
                               **b(logo_height='44', logo_height_mobile='30', footer_logo_height='40', name_size='26')})
css = style(page())
check('type: families, size, scale, weight, spacing, case', all(x in css for x in ('--font-heading:Avenir,', '--font-body:Rockwell,', '--type-scale:1.125;', '--step-5:clamp(', '--heading-weight:800;', '--heading-tracking:0.025em;',
      'h1,h2,h3,h4,h5,h6{line-height:1.05;text-transform:uppercase}', 'body.theme-body{line-height:1.8}')), css[:600])
check('layout: widths, margin, sections, spacing', all(x in css for x in ('--container:87.5rem;', '--container-narrow:50rem;', '--gutter:clamp(', '--section-scale:0.7143;', '--header-min:5rem;', '--space-scale:1.2;')), css[:900])
check('shape: radius, button corners, shadows', all(x in css for x in ('--radius-m:0.375rem;', '--radius-button:0px;', '--shadow-m:0 30px 60px')), css[:900])
check('buttons: height, padding, size, weight, capitals', all(x in css for x in ('--btn-height:3.375rem;', '--btn-pad-x:1.875rem;', '--btn-size:1rem;', '--btn-weight:700;', '--btn-case:uppercase;')), css[:1200])
check('the logo sizes and the size of the name', all(x in css for x in ('--logo-height:2.75rem;', '--logo-height-mobile:1.875rem;', '--footer-logo-height:2.5rem;', '--brand-name-size:1.625rem;')), css[:1400])
check('nothing of it can break out of the style', css.count('{') == css.count('}') and '<' not in css, css[-120:])
check('values out of range are held inside it', 'radius' in stored() and re.search(r'^  radius: 6$', stored(), re.M) is not None, stored()[:200])
(res, hdr, _), _ = post_theme(d(radius='999', button_height='3', base_size='abc'))
check('out of range numbers are held to the limits, text keeps the last value', re.search(r'^  radius: 48$', stored(), re.M) and re.search(r'^  button_height: 28$', stored(), re.M) and re.search(r'^  base_size: 18$', stored(), re.M), stored()[:600])
(res, hdr, _), _ = post_theme(d(heading_font='nonsense', scale='9', button_case='zzz', spacing='huge'))
css = style(page())
check('a word that is not on the list keeps the last choice', '--font-heading:Avenir,' in css and '--step-5:' in css and '--btn-case:uppercase;' in css and '--space-scale:1.2;' in css, css[:300])

# ---- saving: more elements and a case, from the Typography tab
(res, hdr, _), _ = post_theme(d(h5_step='1', eyebrow_case='none', card_title_case='small_caps'), tab='typography')
css = style(page())
check('H5, the eyebrow and the card titles take their rules on the site', '[data-mode] h5{font-size:var(--step-1)}' in css and '[data-mode] .eyebrow{text-transform:none;font-variant-caps:normal}' in css and '.latest-item:not(.is-lead) .latest-title' in css and 'font-variant-caps:all-small-caps' in css, css[-500:])
post_theme(d(h5_step='auto', eyebrow_case='auto', card_title_case='auto'), tab='typography')

# ---- saving: navigation text, saved from the Typography tab
(res, hdr, _), _ = post_theme(d(nav_size='17', nav_weight='700', nav_case='uppercase', footer_title_case='none', crumbs_size='13'), tab='typography')
css = style(page())
check('the menu and the other navigation take their variables, and the screen opens on Typography again', '--type-nav-size:1.0625rem;--type-nav-weight:700;--type-nav-case:uppercase' in css and '--type-footer-title-case:none' in css and '--type-crumbs-size:0.8125rem' in css and 'tab=typography' in (hdr.get('Location') or hdr.get('location') or ''), css[-400:])
(res, hdr, _), _ = post_theme(d(nav_size='', nav_weight='auto', nav_case='auto', footer_title_case='auto', crumbs_size=''), tab='typography')
check('emptied, they add nothing', not re.search(r'--type-(nav|footer-title|crumbs)-', style(page())))

# ---- the theme's style sheet follows the tokens
sheet = asset('css')
check('the stylesheet reads the tokens: type, spacing, sections, buttons, logo', all(x in sheet for x in ('--type-scale', '--space-scale', '--section-scale', 'var(--header-min', 'var(--btn-height', 'var(--btn-pad-x', 'var(--btn-case', 'var(--logo-h)', 'var(--footer-logo-height', 'var(--brand-name-size')))
check('and shows the logo for dark backgrounds where the header or the mode is dark', '.logo-on-dark' in sheet and ':root.dark .logo-on-light' in sheet and '.site-header.tone-contrast .logo-on-dark' in sheet and 'is-on-dark:not([data-sticky="always"]) .logo-on-dark' in sheet)

# ---- the colour of headings
st, _, html = root.get('/admin/theme')
check('the screen has the choice of the colour of headings and one colour for each mode', all(('name="theme_settings[design][%s]"' % k) in html for k in ('heading_color', 'heading_color_light', 'heading_color_dark')))
(res, hdr, _), _ = post_theme(d(heading_color='accent'))
css = style(page())
check('headings in the palette colour', '--heading-color:var(--accent);' in css, css[:300])
(res, hdr, _), _ = post_theme(d(heading_color='custom', heading_color_light='#b00020', heading_color_dark='#ff8a80'))
css = style(page())
check('headings in colours of their own, light and dark', '--heading-color:#b00020;' in css and ':root.dark[data-theme][data-mode]{--heading-color:#ff8a80}' in css, css[:500])
sheet = asset('css')
flat = lambda s: re.sub(r'\s+', '', s)  # stylesheets are sent minified: compare without white space
check('the style sheet gives them to the text, the section titles and the title of a page, and not to a dark panel', '.section-title' in sheet and 'var(--heading-color,inherit)' in flat(sheet) and re.search(r'\.tone-contrast,\.tone-accent\{[^}]*--heading-color:initial', flat(sheet)) is not None)
(res, hdr, _), _ = post_theme(d(heading_color='text'))
check('and back to the colour of the text', '--heading-color' not in style(page()))

# ---- everything back to the theme
(res, hdr, _), _ = post_theme({**d(heading_font='pairing', body_font='pairing', base_size='', scale='auto', line_height='', heading_weight='auto', heading_tracking='auto', heading_leading='auto', heading_case='auto',
                                    container='', reading_width='', gutter='', section_space='', header_height='', spacing='auto', radius='', button_radius='auto', shadows='auto',
                                    button_height='', button_pad='', button_size='', button_weight='auto', button_case='auto'),
                               **b(logo_height='', logo_height_mobile='', footer_logo_height='', name_size='')})
check('emptying the numbers and choosing "Theme" everywhere leaves the page styled by the theme alone', style(page()) == '', style(page())[:300])

# ---- palette, mode, font pairing, corners, decoration
(res, hdr, _), _ = post_theme(a(palette='garnet', mode='dark', font='serif', shape='sharp', glow='solid'))
html = page()
check('the choices of Appearance are still on the page, as they were', all(x in html for x in ('data-theme="garnet"', 'data-mode="dark"', 'data-font="serif"', 'data-shape="sharp"', 'data-glow="solid"')), re.findall(r'<html[^>]*>', html))
post_theme(a(palette='slate', mode='system', font='sans', shape='soft', glow='soft'))

# ---- the icons
(res, hdr, _), _ = post_theme(b(favicon='/uploads/media/fav.svg', touch_icon='/uploads/media/app.png', theme_color='#0F172A'))
html = page()
check('a favicon, an app icon and the colour of the browser', '<link rel="icon" href="/uploads/media/fav.svg" type="image/svg+xml">' in html and '<link rel="apple-touch-icon" href="/uploads/media/app.png">' in html and '<meta name="theme-color" content="#0f172a">' in html, re.findall(r'<link rel="(?:icon|apple)[^>]*>', html))
(res, hdr, _), _ = post_theme(b(favicon='javascript:alert(1)', touch_icon='/x" onerror="y'))
check('an address that could break out is not accepted', 'javascript' not in page() and 'onerror' not in page())
post_theme(b(favicon='', touch_icon='', theme_color=''))
check('and nothing is left when they are emptied', 'rel="icon"' not in page() and 'theme-color' not in page())

# ---- the logo, and the logo for dark backgrounds
post_theme(b(logo='/uploads/media/logo.svg', logo_dark='', show_name=None))
h = header(page())
check('a logo alone is drawn once, with no name', h.count('site-brand-logo') == 1 and 'logo-on-light' not in h and 'logo-on-dark' not in h and 'site-brand-title' not in h, h[:400])
post_theme(b(logo='/uploads/media/logo.svg', logo_dark='/uploads/media/logo-light.svg', show_name='1'))
h = header(page())
check('with a logo for dark backgrounds both are drawn, each for its own place, and the name can follow', 'logo-on-light' in h and 'logo-on-dark' in h and 'logo-light.svg' in h and 'site-brand-title' in h, h[:600])
check('a logo with a name beside it is not given the name as its label', 'aria-label' not in re.search(r'<a class="site-brand"[^>]*>', h).group(0), re.search(r'<a class="site-brand"[^>]*>', h).group(0))
post_theme({**b(logo='/uploads/media/logo.svg', logo_dark='/uploads/media/logo-light.svg', show_name=None), 'theme_settings[footer][brand]': 'logo', 'theme_settings[footer][tone]': 'dark'}, tab='footer')
f = footer(page())
check('a dark footer wears the logo for dark backgrounds alone', 'logo-light.svg' in f and 'logo-on-' not in f and f.count('footer-logo') == 1, f[:300])
post_theme({'theme_settings[footer][brand]': 'logo', 'theme_settings[footer][tone]': 'muted'}, tab='footer')
f = footer(page())
check('a light footer wears both, each for its mode', 'logo-on-light' in f and 'logo-on-dark' in f, f[:300])
post_theme({**b(logo_dark=''), 'theme_settings[footer][tone]': 'dark'}, tab='footer')
f = footer(page())
check('without a dark logo the logo does for the footer', f.count('footer-logo') == 1 and 'logo-light.svg' not in f and 'logo.svg' in f)
post_theme(b(logo='', logo_dark=''))

# ---- a font file of the site's own
os.makedirs('app/custom/assets/fonts', exist_ok=True)
open('app/custom/assets/fonts/brand.woff2', 'wb').write(b'wOF2' + b'\0' * 40)
st, hdr, _ = pub.request('/_custom/fonts/brand.woff2')
check('a font file in custom/assets is served as a font', st == 200 and 'font/woff2' in (hdr.get('Content-Type') or ''), (st, hdr.get('Content-Type')))
post_theme(d(heading_font='custom', font_file='fonts/brand.woff2'))
html = page(); css = style(html)
check('chosen for the headings it is loaded, and fetched early', '@font-face{font-family:"Brand Font"' in css and 'url("/_custom/fonts/brand.woff2") format("woff2")' in css and '--font-heading:"Brand Font"' in css and 'as="font"' in html, css[:300])
check('the screen says the file is there', 'The file is there.' in root.get('/admin/theme?tab=branding')[2])
post_theme(d(heading_font='custom', font_file='fonts/missing.woff2'))
check('and says when it is not', 'There is no such file' in root.get('/admin/theme?tab=branding')[2])
post_theme(d(heading_font='custom', font_file='fonts/brand.php'))
check('only font files are used', 'Brand Font' not in style(page()))
post_theme(d(heading_font='pairing', font_file=''))

# ---- the preview: what the unsaved choices would make, and nothing stored
before = stored()
form = next(f for f in root.forms('/admin/theme?tab=branding') if any(x[0] == 'active_tab' for x in f['fields']))
token = next(x[1] for x in form['fields'] if x[0] == '_csrf')
def preview(client, **fields):
    data = [('_csrf', token)] + [(k, v) for k, v in fields.items()]
    st, hdr, body = client.request('/admin/theme?preview=branding', data=data)
    return st, hdr, body
st, hdr, body = preview(root, **{'theme_settings[design][light_accent]': '#ff0000', 'theme_settings[design][radius]': '20', 'theme_settings[appearance][palette]': 'rose', 'theme_settings[brand][logo]': '/uploads/media/p.svg', 'theme_settings[brand][show_name]': '1'})
data = json.loads(body) if st == 200 else {}
check('asks come back as JSON with the CSS, the attributes and the logo', st == 200 and 'application/json' in (hdr.get('Content-Type') or '') and '--accent-l:#ff0000;' in data.get('css', '') and '--radius-m:1.25rem;' in data.get('css', '') and data['attributes']['data-theme'] == 'rose' and data['logo'] == '/uploads/media/p.svg' and data['show_name'] is True, body[:300])
check('and store nothing', stored() == before and 'ff0000' not in stored())
st, hdr, body = preview(root, **{'theme_settings[design][light_accent]': 'nonsense'})
check('a colour that is not hex is not previewed either', st == 200 and 'nonsense' not in body and json.loads(body)['css'] == '', body[:200])
st, _, body = ed.request('/admin/theme?preview=branding', data=[('_csrf', token)])
check('an editor cannot ask', st in (302, 403, 419), st)
st, _, body = root.request('/admin/theme?preview=branding', data=[('theme_settings[design][radius]', '9')])
check('nor can a request without the form token', st in (302, 403, 419) and '"css"' not in body, st)
st, _, _ = pub.request('/admin/theme?preview=branding', data=[('_csrf', token)])
check('nor someone who is not signed in', st in (302, 403, 419), st)

# ---- links and button styles
html = root.get('/admin/theme?tab=branding')[2]
check('Branding has the Links and Button styles cards, with the colours as roles, and the specimen view', 'data-bt-card="links"' in html and 'data-bt-card="buttons"' in html and html.count('data-tone-role') >= 20 and 'theme?preview=specimen' in html and 'admin-branding-buttons.js' in html)
check('the specimen and its screen are not in a site that sets nothing: the page has no button or link CSS', 'btn-text' not in style(page()) and 'where(:root' not in style(page()))
post_theme(d(btn_primary_bg='ink', btn_primary_radius='square', btn_secondary_border_width='2', btn_text_text='accent', link_color='text', link_underline='hover', btn_primary_hover_bg='#ffff00', btn_primary_hover_text='#ffffff'))
css = style(page())
check('saved choices reach every page', '--btn-bg:var(--ink)' in css and 'border-radius:0px' in css and 'border-width:2px' in css and ':is(.prose,.prose-block) a' in css and 'color:var(--color-text)' in css, css[:300])
check('a custom pair that does not read is not used', '#ffff00' not in css)
html = root.get('/admin/theme?tab=branding')[2]
check('and the screen shows them chosen', re.search(r'name="theme_settings\[design\]\[btn_primary_bg\]" value="ink"', html) is not None and re.search(r'name="theme_settings\[design\]\[link_underline\]" value="hover" checked', html) is not None)
st, hdr, body = root.get('/admin/theme?preview=specimen')
check('the specimen draws the three styles in light and dark with their states, using the unsaved-free current CSS', st == 200 and body.count('btn-primary') >= 8 and 'btn-text' in body and 'data-state="focus"' in body and ' disabled>' in body and 'mode-dark' in body and 'id="faros-branding"' in body and '--btn-bg:var(--ink)' in body)
check('it needs theme access', ed.get('/admin/theme?preview=specimen')[0] in (302, 403) and pub.get('/admin/theme?preview=specimen')[0] in (302, 403))
st, _, body = preview(root, **{'theme_settings[design][btn_secondary_text]': 'accent', 'theme_settings[design][link_color]': 'javascript:x'})
check('the live preview follows an unsaved button choice and refuses a bad one', st == 200 and '.btn:not(.btn-primary):not(.btn-text){--btn-text:var(--accent)' in json.loads(body)['css'] and 'javascript' not in body)
post_theme(d(btn_primary_bg='', btn_primary_radius='auto', btn_secondary_border_width='auto', btn_text_text='', link_color='', link_underline='auto', btn_primary_hover_bg='', btn_primary_hover_text=''))
check('emptying them restores the page to the theme\'s own', 'where(:root' not in style(page()) and 'btn-primary' not in style(page()))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
