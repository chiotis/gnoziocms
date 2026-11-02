import base64, hashlib, json, os, re, sqlite3, sys, urllib.error, urllib.parse, urllib.request
sys.path.insert(0, '.')
from client import BASE, Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def raw(method, path, body=None, headers=None):
    """A request outside the admin: JSON or form bodies, no cookies, no redirects followed."""
    req = urllib.request.Request(BASE + path, data=body, method=method, headers=headers or {})
    try:
        r = urllib.request.urlopen(req, timeout=30)
        return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()

def post_json(path, payload, headers=None):
    return raw('POST', path, json.dumps(payload).encode(), {'Content-Type': 'application/json', **(headers or {})})

def post_form(path, fields):
    return raw('POST', path, urllib.parse.urlencode(fields).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})

def rpc(token, method, params=None, id_=1, headers=None):
    st, h, body = post_json('/mcp', {'jsonrpc': '2.0', 'id': id_, 'method': method, 'params': params or {}}, {'Authorization': 'Bearer ' + token, **(headers or {})})
    try: return st, h, json.loads(body)
    except ValueError: return st, h, body

def location(hdr): return hdr.get('Location') or ''

root = Client(); root.login()

# ---- off: nothing answers
check('while MCP is off the discovery address is an ordinary one', raw('GET', '/.well-known/oauth-protected-resource/mcp')[0] == 404)
st, _, html = root.get('/admin/mcp')
check('Admin > MCP is there for the super admin, off', st == 200 and 'Turn on' in html and '/mcp</dd>' in html, st)
st, _, html = root.get('/admin')
check('and in the menu', 'admin/mcp"' in html)
root.submit('/admin/mcp', has_field('mcp_action'), button=('mcp_action', 'enable'))
check('it is turned on', 'Turn off' in root.get('/admin/mcp')[2])

# ---- discovery
st, h, body = raw('GET', '/.well-known/oauth-protected-resource/mcp')
meta = json.loads(body) if st == 200 else {}
check('the protected resource names the endpoint and the server', st == 200 and meta.get('resource', '').endswith('/mcp') and meta.get('authorization_servers'), body[:200])
st, h, body = raw('GET', '/.well-known/oauth-authorization-server')
asm = json.loads(body) if st == 200 else {}
check('the authorization server lists its endpoints, S256 only, public clients', st == 200 and asm.get('authorization_endpoint', '').endswith('/admin/mcp-authorize') and asm.get('code_challenge_methods_supported') == ['S256'] and asm.get('token_endpoint_auth_methods_supported') == ['none'], body[:300])
check('without a cookie or a cache', 'Set-Cookie' not in h and 'no-store' in h.get('Cache-Control', ''), h)
resource = meta.get('resource', BASE + '/mcp')

# ---- the endpoint without a token
st, h, body = post_json('/mcp', {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize'})
check('no token: 401 with where to find the metadata', st == 401 and 'resource_metadata=' in h.get('WWW-Authenticate', ''), (st, h.get('WWW-Authenticate')))
check('GET is not allowed', raw('GET', '/mcp')[0] == 405)

# ---- registration
st, _, body = post_json('/_mcp/register', {'client_name': 'Test assistant', 'redirect_uris': ['http://evil.example/cb']})
check('a redirect address that is not HTTPS is refused', st == 400 and 'invalid_client_metadata' in body, body)
st, _, body = post_json('/_mcp/register', {'client_name': 'Test assistant', 'redirect_uris': ['https://assistant.example/callback'], 'token_endpoint_auth_method': 'none'})
client = json.loads(body) if st == 201 else {}
check('a public client registers', st == 201 and client.get('client_id', '').startswith('gnozio_'), (st, body[:200]))
cid = client.get('client_id', '')

# ---- consent
verifier = base64.urlsafe_b64encode(os.urandom(40)).rstrip(b'=').decode()
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()
query = urllib.parse.urlencode({'response_type': 'code', 'client_id': cid, 'redirect_uri': 'https://assistant.example/callback', 'code_challenge': challenge, 'code_challenge_method': 'S256', 'state': 'xyz', 'resource': resource, 'scope': 'content:read content:write'})

anon = Client()
st, h, _ = anon.get('/admin/mcp-authorize?' + query)
check('someone not signed in is sent to sign in', st in (302, 303) and location(h).endswith('/admin/login'), (st, location(h)))
st, h, _ = anon.login()
check('and comes back to the consent after signing in', location(h).startswith('/admin/mcp-authorize?') and 'client_id=' + cid in location(h), location(h))

st, _, html = root.get('/admin/mcp-authorize?' + query.replace('https%3A%2F%2Fassistant.example%2Fcallback', 'https%3A%2F%2Fother.example%2Fcb'))
check('an address the client did not register is shown, not followed', st == 200 and 'data-mcp-problem' in html, st)
st, h, _ = root.get('/admin/mcp-authorize?' + query.replace('S256', 'plain'))
check('a request without S256 goes back to the client with an error', st in (302, 303) and location(h).startswith('https://assistant.example/callback?error=invalid_request') and 'state=xyz' in location(h), location(h))

st, _, html = root.get('/admin/mcp-authorize?' + query)
check('the consent names the client and the scopes it asked for', st == 200 and 'Test assistant' in html and 'value="content:read"' in html and 'value="content:write"' in html and 'value="content:publish"' not in html, st)
st, h, _ = root.submit('/admin/mcp-authorize?' + query, has_field('decision'), {'scopes[]': ['content:read', 'content:publish']}, button=('decision', 'approve'))
back = urllib.parse.urlparse(location(h)); params = urllib.parse.parse_qs(back.query)
check('approving sends the browser back with a code and the state', st in (302, 303) and back.netloc == 'assistant.example' and params.get('code') and params.get('state') == ['xyz'], location(h))
code = (params.get('code') or [''])[0]

# ---- tokens
st, _, body = post_form('/_mcp/token', {'grant_type': 'authorization_code', 'client_id': cid, 'code': code, 'redirect_uri': 'https://assistant.example/callback', 'code_verifier': 'x' * 43, 'resource': resource})
check('a wrong PKCE verifier is refused', st == 400 and 'invalid_grant' in body, body)
st, _, body = post_form('/_mcp/token', {'grant_type': 'authorization_code', 'client_id': cid, 'code': code, 'redirect_uri': 'https://assistant.example/callback', 'code_verifier': verifier, 'resource': resource})
check('the code, once used, is gone even for the right verifier', st == 400, body)

st, h, _ = root.submit('/admin/mcp-authorize?' + query, has_field('decision'), {'scopes[]': ['content:read']}, button=('decision', 'approve'))
code = urllib.parse.parse_qs(urllib.parse.urlparse(location(h)).query).get('code', [''])[0]
st, _, body = post_form('/_mcp/token', {'grant_type': 'authorization_code', 'client_id': cid, 'code': code, 'redirect_uri': 'https://assistant.example/callback', 'code_verifier': verifier, 'resource': resource})
tokens = json.loads(body) if st == 200 else {}
check('the code and its verifier give tokens, only for the scopes left ticked', st == 200 and tokens.get('token_type') == 'Bearer' and tokens.get('refresh_token') and tokens.get('scope') == 'content:read', body[:200])
access = tokens.get('access_token', '')

# ---- the endpoint with a token
st, h, out = rpc(access, 'initialize', {'protocolVersion': '2025-06-18'})
check('initialize answers with the protocol and instructions', st == 200 and out.get('result', {}).get('protocolVersion') == '2025-06-18' and 'site_info' in out['result'].get('instructions', ''), out)
check('the endpoint starts no session', 'Set-Cookie' not in h)
st, _, out = rpc(access, 'tools/list')
names = [t['name'] for t in out['result']['tools']]
check('tools/list has site_info and, for content:read, the tools that read but none that write', st == 200 and names[0] == 'site_info' and 'get_entry' in names and 'create_entry' not in names, names)
st, _, out = rpc(access, 'tools/call', {'name': 'site_info', 'arguments': {}})
info = out.get('result', {}).get('structuredContent', {})
check('site_info tells the languages, types and the connection', st == 200 and info.get('site', {}).get('default_language') and any(t['type'] == 'posts' for t in info.get('content_types', [])) and info.get('connection', {}).get('scopes') == ['content:read'], out)
check('forms are not offered', all(t['type'] != 'forms' for t in info.get('content_types', [])))
st, _, out = rpc(access, 'tools/call', {'name': 'delete_everything', 'arguments': {}})
check('an unknown tool is an error the model can read', out.get('result', {}).get('isError') is True, out)
check('a notification gets 202', post_json('/mcp', {'jsonrpc': '2.0', 'method': 'notifications/initialized'}, {'Authorization': 'Bearer ' + access})[0] == 202)
st, _, body = post_json('/mcp', [{'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}], {'Authorization': 'Bearer ' + access})
check('a batch is refused', st == 200 and '-32600' in body, body)
st, _, _ = raw('POST', '/mcp', b'{}', {'Authorization': 'Bearer ' + access, 'Content-Type': 'text/plain'})
check('a body that is not JSON is refused', st == 415, st)
st, _, _ = rpc(access, 'ping', headers={'Origin': 'https://evil.example'})
check('a request from another origin is refused', st == 403, st)

# ---- refresh
def refresh(rt): return post_form('/_mcp/token', {'grant_type': 'refresh_token', 'client_id': cid, 'refresh_token': rt, 'resource': resource})
st, _, body = refresh(tokens['refresh_token'])
second = json.loads(body) if st == 200 else {}
check('a refresh token gives a new pair', st == 200 and second.get('refresh_token') not in (None, tokens['refresh_token']), body[:200])
st, _, body = refresh(tokens['refresh_token'])
check('the old one still works for a minute (two refreshes at once)', st == 200, body[:200])
c = sqlite3.connect('app/storage/db/app.sqlite')
c.execute("UPDATE mcp_tokens SET replaced_at = datetime('now', '-5 minutes') WHERE replaced_at IS NOT NULL"); c.commit(); c.close()
st, _, body = refresh(tokens['refresh_token'])
check('after that it is refused', st == 400 and 'invalid_grant' in body, body)
check('the new access token works', rpc(second['access_token'], 'ping')[0] == 200)

# ---- the admin screen
st, _, html = root.get('/admin/mcp')
row = re.search(r'data-mcp-connection="(\d+)".*?</li>', html, re.S)
check('the connection is listed with its account and scopes', row is not None and 'Test assistant' in row.group(0) and 'Read' in row.group(0), html[:200])
conn = row.group(1) if row else '0'
root.submit('/admin/mcp', lambda f: ('connection', conn) in [tuple(x) for x in f['fields']] and any(b[1] == 'pause' for b in f['buttons']), button=('mcp_action', 'pause'))
st, _, out = rpc(second['access_token'], 'tools/call', {'name': 'site_info', 'arguments': {}})
check('a paused connection is told so', out.get('result', {}).get('isError') is True and 'paused' in out['result']['content'][0]['text'], out)
root.submit('/admin/mcp', lambda f: ('connection', conn) in [tuple(x) for x in f['fields']] and any(b[1] == 'revoke' for b in f['buttons']), button=('mcp_action', 'revoke'))
check('a removed connection is refused at once', rpc(second['access_token'], 'ping')[0] == 401)

# ---- a token made by hand
root.submit('/admin/mcp', has_field('days'), {'name': 'Daily news', 'days': '30', 'scopes[]': ['content:read']})
st, _, html = root.get('/admin/mcp')
made = re.search(r'value="(gat_[A-Za-z0-9_-]+)"', html)
check('a token made by hand is shown once', made is not None, html[:300])
check('and not again', made is not None and made.group(1) not in root.get('/admin/mcp')[2])
check('it works', made is not None and rpc(made.group(1), 'tools/call', {'name': 'site_info', 'arguments': {}})[2].get('result', {}).get('isError') is False)

# ---- the log
st, _, html = root.get('/admin/activity-logs')
check('the activity log has the MCP events', st == 200 and all('>' + a + '</code>' in html for a in ['mcp.client_registered', 'mcp.connected', 'mcp.tool_called', 'mcp.connection_revoke', 'mcp.token_created']), re.findall(r'>(mcp\.[a-z_]+)</code>', html))

print('ALL PASSED' if not fails else f'{len(fails)} FAILED')
sys.exit(1 if fails else 0)
