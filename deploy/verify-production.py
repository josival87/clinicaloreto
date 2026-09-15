"""Run on the deployment host; validates the empty initial installation."""
import http.cookiejar
import json
from datetime import date
from pathlib import Path
import urllib.error
import urllib.request

env = dict(line.split('=', 1) for line in Path('.env').read_text().splitlines()
           if line and not line.startswith('#') and '=' in line)
base = env['APP_URL'].rstrip('/')
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))


def request(path, body=None, csrf=None, expected=200):
    headers = {'Accept': 'application/json'}
    if body is not None:
        headers['Content-Type'] = 'application/json'
    if csrf:
        headers['X-CSRF-TOKEN'] = csrf
    req = urllib.request.Request(base + path, headers=headers,
                                 data=json.dumps(body).encode() if body is not None else None)
    try:
        response = client.open(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == expected, (path, response.status, expected)
    data = json.loads(response.read())
    print(path, response.status)
    return data


request('/api/clients', expected=401)
session = request('/api/session')
assert session['user'] is None
session = request('/api/login', {'cpf': env['ADMIN_CPF'], 'password': env['ADMIN_PASSWORD']}, session['csrf'])
assert session['user']['level'] == 'admin'
assert all(c.secure and c.path == '/clinicaloreto' for c in cookies)
for entity in ['clients', 'doctors', 'leaders', 'specialties']:
    data = request('/api/' + entity)
    rows = data['data'] if isinstance(data, dict) and 'data' in data else data
    assert rows == [], (entity, 'Expected an empty registry')
assert request('/api/appointments?date=' + date.today().isoformat()) == []
assert request('/api/slots?month=' + date.today().strftime('%Y-%m')) == []
users = request('/api/users')
rows = users['data'] if isinstance(users, dict) and 'data' in users else users
assert len(rows) == 1 and rows[0]['level'] == 'admin'
request('/api/dashboard')
request('/api/options')
request('/api/whatsapp')
request('/api/logout', {}, expected=419)
request('/api/logout', {}, session['csrf'])
request('/api/clients', expected=401)
print('PASS: HTTPS, admin login, scoped secure cookies, empty registries, CSRF and logout')
