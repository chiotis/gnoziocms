"""Tiny browser-like client for exercising the GnozioCMS admin in the scratch copy."""
import http.cookiejar
import json
import os
import re
import socket
import sys
import time
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = os.environ.get('FAROS_TEST_BASE', 'http://127.0.0.1:8102')
# No request may wait for ever: a stuck server fails the test with an error instead of hanging it (override per call or with the variable).
REQUEST_TIMEOUT = float(os.environ.get('GNOZIO_TEST_REQUEST_TIMEOUT', '60'))
# Also the default for every other socket the tests open through urllib (urlopen, opener.open) and for sockets without their own timeout.
socket.setdefaulttimeout(REQUEST_TIMEOUT)


def min_php():
    """The lowest PHP the app supports, read from composer.json (require.php), so tests never hardcode it."""
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'composer.json')
    with open(path) as f:
        found = re.search(r'\d+(?:\.\d+)*', json.load(f)['require']['php'])
    return found.group(0)


class WaitTimeout(Exception):
    pass


def wait_until(fn, timeout=30, interval=0.1, what='the condition'):
    """Calls fn() until it returns something true and returns that. After `timeout` seconds it prints a FAIL line (the runner counts
    it) and raises WaitTimeout saying what was waited for and the last value seen, so a loop can never hang a test."""
    deadline = time.monotonic() + timeout
    last = None
    while True:
        last = fn()
        if last:
            return last
        if time.monotonic() >= deadline:
            message = 'timed out after %s s waiting for %s (last value: %r)' % (timeout, what, last)
            print('FAIL ' + message, flush=True)
            raise WaitTimeout(message)
        time.sleep(interval)


def port_open(port, host='127.0.0.1'):
    """True when something accepts connections on host:port (for wait_until after starting a server)."""
    try:
        socket.create_connection((host, port), 0.2).close()
        return True
    except OSError:
        return False


class Flow:
    """Checks that depend on each other. A critical check that fails is recorded once; every later check is printed as
    `skip <label>  (skipped: <that check> failed)` instead of failing again, until clear() starts an independent part.
    `check` is used like the tests' own check(label, ok, detail=''); failed labels are kept in `fails`."""
    def __init__(self, fails=None):
        self.fails = fails if fails is not None else []
        self.blocked = None
        self.skipped = []

    @property
    def active(self):
        """False while a failed step makes the next ones pointless: put the actions of dependent steps under `if flow.active:`."""
        return self.blocked is None

    def check(self, label, ok, detail='', critical=False):
        if self.blocked is not None:
            self.skipped.append(label)
            print('skip %s  (skipped: %s failed)' % (label, self.blocked), flush=True)
            return None
        print(('ok   ' + label) if ok else ('FAIL ' + label + '  ' + str(detail)), flush=True)
        if not ok:
            self.fails.append(label)
            if critical:
                self.blocked = label
        return bool(ok)

    def clear(self):
        """Ends the dependency chain: what follows does not need the steps before."""
        self.blocked = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None
        self.select = None
        self.textarea = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.current = {'attrs': a, 'fields': [], 'buttons': []}
            self.forms.append(self.current)
        if self.current is None:
            return
        if tag == 'input':
            t = (a.get('type') or 'text').lower()
            name = a.get('name')
            if not name:
                return
            if t in ('checkbox', 'radio'):
                if 'checked' in a:
                    self.current['fields'].append([name, a.get('value', 'on')])
            elif t in ('submit', 'button', 'image'):
                self.current['buttons'].append([name, a.get('value', '')])
            elif t == 'file':
                return
            else:
                self.current['fields'].append([name, a.get('value', '')])
        elif tag == 'button':
            if a.get('name') and (a.get('type') or 'submit') == 'submit':
                self.current['buttons'].append([a['name'], a.get('value', '')])
        elif tag == 'select' and a.get('name'):
            self.select = {'name': a['name'], 'value': None, 'first': None}
        elif tag == 'option' and self.select is not None:
            v = a.get('value', '')
            if self.select['first'] is None:
                self.select['first'] = v
            if 'selected' in a:
                self.select['value'] = v
        elif tag == 'textarea' and a.get('name'):
            self.textarea = [a['name'], '']

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None
        elif tag == 'select' and self.select is not None and self.current is not None:
            v = self.select['value'] if self.select['value'] is not None else (self.select['first'] or '')
            self.current['fields'].append([self.select['name'], v])
            self.select = None
        elif tag == 'textarea' and self.textarea is not None and self.current is not None:
            self.current['fields'].append(self.textarea)
            self.textarea = None

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea[1] += data


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
        self.last_html = ''

    def request(self, path, data=None, headers=None, timeout=None):
        url = path if path.startswith('http') else BASE + path
        body = None
        if data is not None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
        req = urllib.request.Request(url, data=body, headers=headers or {})
        try:
            resp = self.opener.open(req, timeout=timeout or REQUEST_TIMEOUT)
            status, hdrs, content = resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as e:
            status, hdrs, content = e.code, e.headers, e.read()
        text = content.decode('utf-8', 'replace')
        self.last_html = text
        return status, hdrs, text

    def get(self, path):
        return self.request(path)

    def forms(self, path=None, html=None):
        if html is None:
            _, _, html = self.get(path)
        p = FormParser()
        p.feed(html)
        return p.forms

    def submit(self, path, pick, overrides=None, button=None, drop=None):
        """Load `path`, choose a form via `pick(form)`, apply overrides and POST it."""
        forms = self.forms(path)
        form = next(f for f in forms if pick(f))
        fields = [list(x) for x in form['fields']]
        overrides = overrides or {}
        drop = set(drop or [])
        fields = [f for f in fields if f[0] not in overrides and f[0] not in drop]
        for k, v in overrides.items():
            if isinstance(v, list):
                for item in v:
                    fields.append([k, item])
            elif v is not None:
                fields.append([k, v])
        if button:
            fields.append(list(button))
        action = form['attrs'].get('action') or path
        return self.request(action, data=[tuple(f) for f in fields])

    def login(self, username='admin', password='1234'):
        return self.submit('/admin/login', lambda f: any(x[0] == 'username' for x in f['fields']),
                           {'username': username, 'password': password})


def has_field(name):
    return lambda f: any(x[0] == name for x in f['fields']) or any(x[0] == name for x in f['buttons'])
