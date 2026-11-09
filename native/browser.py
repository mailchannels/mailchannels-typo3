"""Real-browser form submission against the disposable site (stdlib WebDriver client).

Runs inside the pinned Chromium image, sharing the HTTP container's network namespace, so the
browser reaches only 127.0.0.1:8080. CAPTURE names the synthetic API capture file."""
import base64, json, os, subprocess, time, urllib.request

CAPTURE = os.environ['CAPTURE']
SITE = 'http://127.0.0.1:8080/'
DRIVER = 'http://127.0.0.1:9515'
ELEMENT = 'element-6066-11e4-a52e-4f735466cecf'
FILES = {'first.txt': b'Browser upload one\n', 'second.txt': b'Browser upload two \xc3\xa9\n'}
count = 0


def check(ok, label):
    global count
    if not ok:
        raise SystemExit('FAIL ' + label)
    count += 1
    print('PASS ' + label, flush=True)


def call(method, path, body=None):
    data = None if body is None else json.dumps(body).encode()
    req = urllib.request.Request(DRIVER + path, data=data, method=method,
                                 headers={'Content-Type': 'application/json'})
    with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req, timeout=30) as resp:
        return json.load(resp)['value']


def payloads():
    if not os.path.exists(CAPTURE):
        return []
    with open(CAPTURE, encoding='utf-8') as f:
        return [p for line in f if line.strip() for p in json.loads(line)['payloads']]


driver = subprocess.Popen(['chromedriver', '--port=9515', '--allowed-ips=127.0.0.1'],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
session = None
try:
    for _ in range(100):
        try:
            if call('GET', '/status')['ready']:
                break
        except OSError:
            pass
        time.sleep(0.1)
    session = call('POST', '/session', {'capabilities': {'alwaysMatch': {
        'browserName': 'chrome', 'goog:chromeOptions': {'binary': '/usr/bin/chromium', 'args': [
            '--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--no-proxy-server']}}}})
    sid = '/session/' + session['sessionId']
    print('Browser ' + session['capabilities']['browserVersion'], flush=True)

    def find(css):
        found = call('POST', sid + '/element', {'using': 'css selector', 'value': css})
        if not isinstance(found, dict) or ELEMENT not in found:
            raise SystemExit('FAIL no element ' + css + ': ' + json.dumps(found)[:300]
                             + '\n' + call('GET', sid + '/source')[:4000])
        return found[ELEMENT]

    def source():
        return call('GET', sid + '/source')

    def submit(marker_or_alert):
        call('POST', sid + '/element/' + find('button[type="submit"]') + '/click', {})
        for _ in range(100):
            html = source()
            if marker_or_alert(html):
                return html
            time.sleep(0.1)
        raise SystemExit('FAIL page did not finish loading')

    for name, data in FILES.items():
        with open('/tmp/' + name, 'wb') as f:
            f.write(data)
    before = len(payloads())
    call('POST', sid + '/url', {'url': SITE})
    html = source()
    check('frontendbrowserfixture-' in html and 'FRONTEND-BROWSER-COMPLETE' not in html
          and call('GET', sid + '/element/' + find('input[type="file"]') + '/attribute/multiple') is not None
          and len(payloads()) == before,
          'browser GET renders the stock multiple-upload form without mail')

    visitor, email = find('input[name$="[visitor]"]'), find('input[name$="[email]"]')
    call('POST', sid + '/element/' + visitor + '/value', {'text': 'Zoë 東京 ✓'})
    call('POST', sid + '/element/' + email + '/value', {'text': 'not-an-address'})
    call('POST', sid + '/element/' + find('input[type="file"]') + '/value',
         {'text': '\n'.join('/tmp/' + n for n in FILES)})
    html = submit(lambda h: '-email-errors' in h or 'FRONTEND-BROWSER' in h)
    check('-email-errors' in html and 'FRONTEND-BROWSER-COMPLETE' not in html and len(payloads()) == before,
          'browser submission with an invalid email shows the native alert and sends nothing')
    check(all(n in html for n in FILES), 'both uploaded files are kept and previewed after the error')

    email = find('input[name$="[email]"]')
    call('POST', sid + '/element/' + email + '/clear', {})
    call('POST', sid + '/element/' + email + '/value', {'text': 'zoe@example.com'})
    html = submit(lambda h: 'FRONTEND-BROWSER' in h or '-email-errors' in h)
    for _ in range(20):
        if len(payloads()) > before:
            break
        time.sleep(0.1)
    sent = payloads()[before:]
    check(html.count('FRONTEND-BROWSER-COMPLETE') == 1 and 'FRONTEND-BROWSER-DELIVERY-FAILED' not in html
          and len(sent) == 1, 'corrected browser submission completes and sends exactly once')
    p = sent[0]
    check(p['subject'] == 'Contact Zoë 東京 ✓' and p['reply_to'] == {'email': 'zoe@example.com', 'name': 'Zoë 東京 ✓'},
          'browser mail carries the submitted Unicode name and reply-to')
    check([a['filename'] for a in p['attachments']] == list(FILES)
          and [base64.b64decode(a['content']) for a in p['attachments']] == list(FILES.values()),
          'browser mail attaches both uploads with exact names and bytes')
    plain, html_body = (c['value'] for c in p['content'])
    check([c['type'] for c in p['content']] == ['text/plain', 'text/html']
          and all(n in plain and n in html_body for n in FILES) and 'Zoë 東京 ✓' in plain and 'Zoë 東京 ✓' in html_body,
          'stock core with the extension templates renders both filenames in plain and HTML')
    print(f'TYPO3_BROWSER_COMPLETE {count} checks; no provider calls', flush=True)
finally:
    if session is not None:
        try:
            call('DELETE', '/session/' + session['sessionId'])
        except OSError:
            pass
    driver.terminate()
    driver.wait(10)
