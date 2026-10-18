"""Public search: real content, language/visibility boundaries, safe results and navigation."""
import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urlsplit

sys.path.insert(0, '.')
from client import Client


fails = []


def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok:
        fails.append(label)


class SearchPage(HTMLParser):
    """Read the public result list without depending on its heading/paragraph layout."""
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.results = []
        self.links = []
        self.marked = []
        self.inputs = []
        self.result_depth = 0
        self.item = None
        self.link = None
        self.mark_depth = 0
        self.text = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'ul':
            if self.result_depth or 'search-results' in attrs.get('class', '').split():
                self.result_depth += 1
        if tag == 'li' and self.result_depth == 1:
            self.item = {'href': '', 'text': '', 'marks': []}
            self.results.append(self.item)
        if tag == 'a':
            self.link = {'href': attrs.get('href', ''), 'text': '', 'attrs': attrs}
            self.links.append(self.link)
            if self.item is not None and not self.item['href']:
                self.item['href'] = attrs.get('href', '')
        if tag == 'mark':
            self.mark_depth += 1
        if tag == 'input':
            self.inputs.append(attrs)

    def handle_endtag(self, tag):
        if tag == 'a':
            self.link = None
        if tag == 'li' and self.result_depth == 1:
            self.item = None
        if tag == 'ul' and self.result_depth:
            self.result_depth -= 1
        if tag == 'mark':
            self.mark_depth = max(0, self.mark_depth - 1)

    def handle_data(self, text):
        self.text.append(text)
        if self.item is not None:
            self.item['text'] += text
        if self.link is not None:
            self.link['text'] += text
        if self.mark_depth:
            self.marked.append(text)
            if self.item is not None:
                self.item['marks'].append(text)

    @property
    def paths(self):
        return [urlsplit(item['href']).path for item in self.results]


def entry(type_, slug, title, body='', lang='el', **meta):
    """Only the runner's disposable app is changed, never repository content."""
    values = {'title': title, 'status': 'published', 'visible': True}
    values.update(meta)
    suffix = '' if lang == 'el' else '.' + lang
    path = Path('app/content') / type_ / (slug + suffix + '.md')
    path.parent.mkdir(parents=True, exist_ok=True)
    # A JSON object is valid YAML, including quotes, Unicode and nested block data.
    path.write_text('---\n' + json.dumps(values, ensure_ascii=False) + '\n---\n' + body + '\n', encoding='utf-8')


visitor = Client()
editor = Client()
editor.login()


def search(query='', lang='el', client=None, **params):
    params = {'q': query, **params}
    prefix = '' if lang == 'el' else '/' + lang
    status, _, html = (client or visitor).get(prefix + '/search?' + urlencode(params))
    return status, html, SearchPage(html)


# Isolated names ensure starter content cannot accidentally satisfy the assertions.
entry('pages', 'search-greek', 'Οδηγός Θάλασσας', 'Μοναδική φράση για ακτογραμμές.')
entry('pages', 'search-multi', 'Polaribook handbook', 'The distant word is compassneedle.')
entry('pages', 'search-half', 'Polaribook without its companion', 'Nothing else matches.')
entry('pages', 'search-metadata', 'Searchable metadata', tags=['Tagonlyneedle'],
      categories=['Categoryonlyneedle'], excerpt='Excerptonlyneedle introduction.')
entry('pages', 'search-title-rank', 'Ranktoken exact title', 'Short description.')
entry('pages', 'search-body-rank', 'Body only match', 'Ranktoken ' * 20)
entry('projects', 'search-field-rank', 'Field only match', custom_fields={'client': 'Ranktoken'})

entry('pages', 'search-blocks', 'Visible block content', blocks=[
    {'type': 'text', 'heading': 'Blockonlyneedle', 'body': 'Visible copy.'},
    {'type': 'faq', 'items': [{'question': 'A question', 'answer': 'Repeateronlyneedle answer.'}]},
    {'type': 'container', 'columns': [{'blocks': [
        {'type': 'text', 'heading': 'Nestedonlyneedle', 'body': 'Inside a column.'},
        {'type': 'text', 'heading': 'Hiddenchildneedle', 'hidden': True},
    ]}]},
    {'type': 'text', 'heading': 'Hiddenblockneedle', 'hidden': True},
    {'type': 'container', 'settings': {'hidden': True}, 'columns': [{'blocks': [
        {'type': 'text', 'heading': 'Hiddencontainerneedle'},
    ]}]},
    {'type': 'text', 'heading': 'A technical value', 'actions': [
        {'label': 'Actionlabelneedle', 'url': 'https://example.test/technicalurlneedle'}
    ], 'anchor': 'technicalanchorneedle', 'class': 'technicalclassneedle'},
])

fields_path = Path('app/custom/content-types/projects.yaml')
fields_path.parent.mkdir(parents=True, exist_ok=True)
fields_path.write_text('''fields:
  search_public:
    type: text
    label: Public detail
    show: true
  search_private:
    type: text
    label: Internal detail
    show: false
  search_retired:
    type: text
    label: Retired detail
    hidden: true
  search_link:
    type: url
    label: Website
''', encoding='utf-8')
entry('projects', 'search-fields', 'Declared fields', custom_fields={
    'search_public': 'Publicfieldneedle',
    'search_private': 'Privatefieldneedle',
    'search_retired': 'Retiredfieldneedle',
    'search_link': 'https://example.test/customurlneedle',
    'undeclared_internal': 'Undeclaredfieldneedle',
})

entry('pages', 'search-published', 'Visibilityneedle public')
entry('pages', 'search-draft', 'Visibilityneedle draft', status='draft')
entry('pages', 'search-hidden', 'Visibilityneedle hidden', visible=False)
entry('books', 'search-disabled', 'Visibilityneedle disabled')
entry('forms', 'search-form', 'Visibilityneedle form')
# A populated catalogue folder is enabled automatically, so explicitly switch this one off.
status, headers, _ = editor.submit('/admin/content-types',
    lambda form: ['type', 'books'] in form['fields'] and ['action', 'toggle'] in form['fields'],
    {'enabled': '0'})
check('fixture content type is explicitly disabled while its file remains',
      status in (302, 303) and 'toggled=off' in headers.get('Location', '')
      and visitor.get('/books/search-disabled')[0] == 404, status)
entry('pages', 'search-language', 'Languageboundaryneedle Greek')
entry('pages', 'search-language', 'Languageboundaryneedle English', lang='en')
entry('pages', 'search-only-greek', 'Languageboundaryneedle untranslated')

for i in range(12):
    entry('posts', 'search-page-%02d' % i, 'Pagingneedle %02d' % i, 'Paging result body.', date='2026-01-%02d' % (i + 1))
entry('pages', 'search-page-kind', 'Pagingneedle page')

# The route is usable without a query and protects scalar input boundaries.
status, html, page = search()
check('empty search renders its form and no result list', status == 200 and not page.results and any(i.get('name') == 'q' for i in page.inputs), status)
status, html, page = search('   ')
check('whitespace search has no results', status == 200 and not page.results, status)
for path in ('/search?q[]=needle', '/search?q=needle&type[]=posts', '/search?q=needle&page[]=2'):
    status, _, html = visitor.get(path)
    check('array parameter cannot break search: ' + path, status == 200 and 'Fatal error' not in html, status)
status, _, _ = search('x' * 10000)
check('an oversized query is handled without an error', status == 200, status)

status, html, page = search('ΟΔΗΓΟΣ θαλασσασ')
check('Greek search ignores case, accents and final sigma', status == 200 and page.paths == ['/search-greek'], page.paths)
check('highlight preserves original accented Greek spelling', 'Οδηγός' in page.marked and 'Θάλασσας' in page.marked, page.marked)
check('built-in type filters use the current language', any('Σελίδες' in link['text'] and parse_qs(urlsplit(link['href']).query).get('type') == ['pages'] for link in page.links))
status, html, page = search('compassneedle polaribook')
check('all query words may match in separate document fields and any order', page.paths == ['/search-multi'], page.paths)
status, html, page = search('ranktoken')
check('a title match ranks above custom field and repeated body matches', len(page.paths) == 3 and page.paths[0] == '/search-title-rank', page.paths)
check('result titles or snippets highlight matched text', len(page.marked) >= 3, page.marked)
for query in ('tagonlyneedle', 'categoryonlyneedle', 'excerptonlyneedle'):
    status, html, page = search(query)
    check('metadata remains searchable: ' + query, status == 200 and page.paths == ['/search-metadata'], page.paths)

for query in ('blockonlyneedle', 'repeateronlyneedle', 'nestedonlyneedle', 'actionlabelneedle'):
    status, html, page = search(query)
    check('visible block text is searchable: ' + query, status == 200 and page.paths == ['/search-blocks'], page.paths)
    check('a block match provides a marked excerpt: ' + query, bool(page.marked), page.marked)
for query in ('hiddenblockneedle', 'hiddenchildneedle', 'hiddencontainerneedle', 'technicalurlneedle', 'technicalanchorneedle', 'technicalclassneedle'):
    status, html, page = search(query)
    check('hidden block or technical settings are excluded: ' + query, status == 200 and not page.results, page.paths)
status, html, page = search('publicfieldneedle')
check('declared public custom fields participate in search', page.paths == ['/projects/search-fields'], page.paths)
import re
result_label = re.search(r'search-result-type">([^<]*)<', html)
filter_label = re.search(r'<a href="[^"]*type=projects[^"]*"[^>]*>([^<]*?) <span', html)
check('a custom type result label matches its filter label', bool(result_label and filter_label and result_label.group(1) == filter_label.group(1)), (result_label and result_label.group(1), filter_label and filter_label.group(1)))
for query in ('privatefieldneedle', 'retiredfieldneedle', 'customurlneedle', 'undeclaredfieldneedle'):
    status, html, page = search(query)
    check('nonpublic or technical custom fields are excluded: ' + query, status == 200 and not page.results, page.paths)

status, html, page = search('visibilityneedle')
check('visitor sees only published visible entries of enabled types', page.paths == ['/search-published'], page.paths)
status, html, page = search('visibilityneedle', client=editor)
check('signed-in search preserves preview visibility but excludes forms and disabled types', set(page.paths) == {'/search-published', '/search-draft', '/search-hidden'}, page.paths)
status, html, page = search('languageboundaryneedle', lang='en')
check('English search does not fall back to untranslated Greek entries', page.paths == ['/en/search-language'] and 'Greek' not in page.results[0]['text'], page.paths)
status, html, page = search('languageboundaryneedle')
check('default-language search uses only default-language content', set(page.paths) == {'/search-language', '/search-only-greek'} and all('English' not in r['text'] for r in page.results), page.paths)

status, html, page = search('pagingneedle', type='posts')
check('type filter selects ten results on its first page', status == 200 and len(page.paths) == 10 and all(re.fullmatch(r'/search-page-\d{2}', p) for p in page.paths), page.paths)
count = re.search(r'<p[^>]*class="[^"]*search-count[^"]*"[^>]*>(.*?)</p>', html, re.S)
check('total count describes all matches rather than the current page', count is not None and re.search(r'\b12\b', count.group(1)) is not None, count.group(1) if count else 'missing count')
first_page = set(page.paths)
next_links = [a for a in page.links if parse_qs(urlsplit(a['href']).query).get('page') == ['2']]
check('pagination preserves query and selected content type', bool(next_links) and all(parse_qs(urlsplit(a['href']).query).get('q') == ['pagingneedle'] and parse_qs(urlsplit(a['href']).query).get('type') == ['posts'] for a in next_links), next_links)
status, html, page = search('pagingneedle', type='posts', page=2)
check('second page has only the remaining matching entries', status == 200 and len(page.paths) == 2 and not first_page.intersection(page.paths), page.paths)
filter_links = [a for a in page.links if parse_qs(urlsplit(a['href']).query).get('type') == ['pages']]
check('changing type preserves the query and resets pagination', bool(filter_links) and all(parse_qs(urlsplit(a['href']).query).get('q') == ['pagingneedle'] and parse_qs(urlsplit(a['href']).query).get('page', ['1']) == ['1'] for a in filter_links), filter_links)
status, html, page = search('pagingneedle', type='pages')
check('a different type filter returns only that type', page.paths == ['/search-page-kind'], page.paths)
status, html, page = search('pagingneedle', type='forms')
check('a forged forms filter never exposes form entries', status == 200 and all('/forms/' not in p for p in page.paths), page.paths)
status, html, page = search('pagingneedle', type='does-not-exist', page=-5)
check('unknown type and negative page remain usable', status == 200, status)

# Search results are escaped text, including around highlight boundaries.
entry('pages', 'search-safe', 'Safeneedle <img src=x onerror="alert(1)"> & text',
      'A safeneedle <script id="search-fixture-script">alert(1)</script> description.')
status, html, page = search('safeneedle')
check('markup in a matching title cannot become active while highlighting', page.paths == ['/search-safe'] and '<img src=x' not in html and bool(page.marked), page.paths)
check('a matching body never injects its script into the results', '<script id="search-fixture-script"' not in html, html[-300:])
attack = '\"><svg onload="alert(42)">'
status, html, page = search(attack)
check('query is safely echoed in the search form', status == 200 and '<svg onload=' not in html and any(i.get('name') == 'q' and i.get('value') == attack for i in page.inputs), status)

# Existing sites may override this template and still loop over the original results variable.
legacy_template = Path('app/custom/templates/search.twig')
legacy_template.parent.mkdir(parents=True, exist_ok=True)
legacy_template.write_text('''{% extends 'layouts/base.twig' %}
{% block content %}
<ul class="search-results">
{% for item in results %}
  <li><a href="{{ url(item_path(item, lang_prefix|default(''))) }}">{{ item.meta.title }}</a></li>
{% endfor %}
</ul>
<p id="modern-hit-count">{{ search.hits|length }}</p>
{% endblock %}
''', encoding='utf-8')
status, html, page = search('pagingneedle', type='posts', page=2)
check('a legacy custom search template still receives every matching filtered entry',
      status == 200 and len(page.paths) == 12
      and all(re.fullmatch(r'/search-page-\d{2}', p) for p in page.paths), page.paths)
check('legacy compatibility leaves modern hits paginated',
      re.search(r'<p id="modern-hit-count">\s*2\s*</p>', html) is not None, html[-300:])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
