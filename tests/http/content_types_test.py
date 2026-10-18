import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

def save_form(f): return ['action', 'save'] in [list(x) for x in f['fields']]
def yaml(name):
    try: return open('app/custom/content-types/%s.yaml' % name, encoding='utf-8').read()
    except FileNotFoundError: return ''

# ---- the list
st, _, html = root.get('/admin/content-types')
cards = re.findall(r'<li class="ct-card( is-off)?">', html)
check('every type is a card, with the ones that are off marked', st == 200 and len(cards) >= 4 and 'is-off' in ''.join(cards), len(cards))
check('with its fields and entries', re.search(r'<b>\d+</b> fields?', html) is not None and re.search(r'<b>\d+</b> entr(?:y|ies)', html) is not None)
check('pages are always on', 'Always on' in html and 'role="switch"' in html)
check('a type that is off has a switch that says so, and no Edit', re.search(r'<li class="ct-card is-off">.*?aria-checked="false".*?</li>', html, re.S) is not None and 'content-types?type=books' not in html)
check('a type that is on has Entries and Edit', 'admin/content?type=posts' in html and 'content-types?type=posts' in html)
check('there is a small form to make a new type', 'New content type' in html and 'name="name"' in html and 'pattern="[a-z][a-z0-9-]*"' in html)
check('an editor cannot open it', ed.get('/admin/content-types')[0] == 403)

# ---- a type of the site's own, and a field in it
root.submit('/admin/content-types', lambda f: ['action', 'create'] in [list(x) for x in f['fields']], {'name': 'recipes', 'label': 'Recipes'})
st, _, html = root.get('/admin/content-types?type=recipes&saved=1')
check('a new type opens on its editor, with no fields yet and a place to add one', st == 200 and 'No fields yet' in html and 'data-add-field' in html and 'field-row-template' in html)
check('the template of a new row has a key, a label, a kind, the ways it is used, and its options and help', all(k in html for k in ('fields[__i__][key]', 'fields[__i__][label]', 'fields[__i__][type]', 'fields[__i__][options]', 'fields[__i__][help]', 'fields[__i__][filterable]', 'fields[__i__][card]', 'fields[__i__][show]', 'fields[__i__][retired]')))
root.submit('/admin/content-types?type=recipes', save_form, {'fields[0][key]': 'cuisine', 'fields[0][label]': 'Cuisine', 'fields[0][type]': 'select', 'fields[0][options]': "it|Italian\ngr|Greek", 'fields[0][help]': 'Pick one', 'fields[0][filterable]': '1', 'fields[0][card]': '1', 'fields[0][show]': '1',
                                                           'fields[1][key]': 'minutes', 'fields[1][label]': 'Minutes', 'fields[1][type]': 'number', 'fields[1][show]': '1'})
y = yaml('recipes')
check('the fields are saved with their kind, options, help and how they are used', all(k in y for k in ('cuisine:', 'type: select', 'Italian', 'Greek', 'Pick one', 'filterable: true', 'card: true', 'minutes:', 'type: number')), y)
st, _, html = root.get('/admin/content-types?type=recipes')
check('they come back as rows: the key, the label, the kind, the help and the options', all(k in html for k in ('<code>cuisine</code>', 'value="Cuisine"', 'value="Pick one"', 'it|Italian')) and html.count('data-field data-kind') >= 2, html.count('data-field'))
check('a select field has its filter and options shown, a number field has not', re.search(r'data-kind="select">.*?data-only-kind="select"(?! hidden)', html, re.S) is not None and re.search(r'data-kind="number">.*?data-only-kind="select" hidden', html, re.S) is not None)
check('and the chips are ticked as they were saved', re.search(r'name="fields\[0\]\[filterable\]" value="1" checked', html) is not None and re.search(r'name="fields\[0\]\[card\]" value="1" checked', html) is not None and re.search(r'name="fields\[1\]\[card\]" value="1" checked', html) is None)

# ---- saving the form as it is changes nothing, and keeps the help text
before = yaml('recipes')
root.submit('/admin/content-types?type=recipes', save_form)
check('saving it again as it is changes nothing, and the help stays', yaml('recipes') == before and 'Pick one' in yaml('recipes'), yaml('recipes'))

# ---- retired, removed
root.submit('/admin/content-types?type=recipes', save_form, {'fields[1][retired]': '1', 'fields[1][show]': None})
st, _, html = root.get('/admin/content-types?type=recipes')
check('a field can be retired: it is dimmed and its chip is ticked', 'ct-field is-retired' in html and re.search(r'name="fields\[1\]\[retired\]" value="1" checked', html) is not None)
form = next(f for f in root.forms(html=html) if save_form(f))
kept = [x for x in form['fields'] if not x[0].startswith('fields[1]')]
root.request('/admin/content-types?type=recipes', data=[tuple(x) for x in kept])
y = yaml('recipes')
check('a field whose row is left out (removed) is gone from the type, the other is not', 'minutes' not in y and 'cuisine' in y, y)
st, _, html = root.get('/admin/content-types?type=recipes')
check('and the editor lists one field', st == 200 and 'ct-count">1<' in html)

# ---- a list of rows with a list in each row (a repeater in a repeater), written in the type's file
with open('app/custom/content-types/recipes.yaml', 'w', encoding='utf-8') as fh:
    fh.write('''label: Recipes
fields:
  cuisine: {type: select, label: Cuisine, options: {it: Italian, gr: Greek}}
  steps:
    type: repeater
    label: Steps
    item_label: Step
    fields:
      title: {type: text, label: Title}
      tools:
        type: repeater
        label: Tools
        item_label: Tool
        max: 3
        fields:
          name: {type: text, label: Name}
          deeper: {type: repeater, fields: {x: {type: text}}}
''')
st, _, html = root.get('/admin/content-types?type=recipes')
check('the type editor shows the list read-only, with the fields of its rows', st == 200 and 'list of rows' in html and 'Title (text), Tools (list: Name (text))' in html and 'name="fields[1][type]"' not in html, html.count('data-field'))
check('and says that the list three levels down is ignored', 'fields.steps.tools.deeper' in html and 'is ignored' in html)
before = yaml('recipes')
root.submit('/admin/content-types?type=recipes', save_form)
check('saving the type keeps the list and its inner list', 'type: repeater' in yaml('recipes') and 'tools:' in yaml('recipes') and 'name:' in yaml('recipes'), yaml('recipes'))

st, _, html = root.get('/admin/edit?type=recipes&slug=&lang=el')
check('the editor has the list, its inner list in the template, each with its own row number placeholder',
      st == 200 and 'data-repeater-name="details[steps]"' in html and 'name="details[steps][__INDEX__][title]"' in html
      and 'data-repeater-name="details[steps][__INDEX__][tools]"' in html and 'name="details[steps][__INDEX__][tools][__INDEX2__][name]"' in html, st)
root.submit('/admin/edit?type=recipes&slug=&lang=el', has_field('details_present'), {
    'slug': 'pasta', 'title': 'Pasta', 'status': 'published', 'visible': '1', 'body': 'Cook.',
    'details[steps][0][title]': 'Mix', 'details[steps][0][tools]': '', 'details[steps][0][tools][0][name]': 'Bowl', 'details[steps][0][tools][1][name]': '<b>Spoon</b>', 'details[steps][0][tools][2][name]': '',
    'details[steps][1][title]': 'Rest', 'details[steps][1][tools]': '',
    'details[steps][2][title]': '', 'details[steps][2][tools]': '', 'details[steps][2][tools][0][name]': '',
})
try: raw = open('app/content/recipes/pasta.md', encoding='utf-8').read()
except FileNotFoundError: raw = ''
check('saved through the admin, the rows are YAML lists under custom_fields, empty rows and values left out',
      'custom_fields:' in raw and re.search(r"steps:\n\s+-\n\s+title: Mix\n\s+tools:\n\s+-\n\s+name: Bowl\n\s+-\n\s+name: '<b>Spoon</b>'\n\s+-\n\s+title: Rest\n", raw) is not None and raw.count('title:') == 3, raw)
st, _, html = root.get('/admin/edit?type=recipes&slug=pasta&lang=el')
check('opened again, the rows and inner rows are in the form, in order, escaped',
      'name="details[steps][0][tools][1][name]" value="&lt;b&gt;Spoon&lt;/b&gt;"' in html and 'name="details[steps][1][title]" value="Rest"' in html and 'data-repeater-name="details[steps][1][tools]"' in html, st)
st, _, page = root.get('/recipes/pasta')
check('the page shows the list with the inner list as a list inside it, escaped',
      st == 200 and page.count('class="type-rows"') == 2 and '&lt;b&gt;Spoon&lt;/b&gt;' in page and '<b>Spoon</b>' not in page and page.index('Mix') < page.index('Bowl') < page.index('Rest'), st)

# ---- a field of the theme
st, _, html = root.get('/admin/content-types?type=projects')
fields_part = html.split('data-field-rows')[1].split('<template')[0]
check('a field from the theme is marked, and it can be retired but not removed or renamed', 'class="ct-chip">Theme<' in fields_part and 'data-remove-field' not in fields_part and 'name="fields[0][label]"' not in fields_part and 'name="fields[0][retired]"' in fields_part, fields_part[:300])
check('the editor of a type links to its entries, its list and the layouts', all(k in html for k in ('admin/content?type=projects', 'archive_layouts', 'single_layouts', 'View archive')))
check('an editor cannot open one', ed.get('/admin/content-types?type=projects')[0] == 403)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
