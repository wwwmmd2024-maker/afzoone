#!/usr/bin/env python3
"""Reference bridge: call ONLY after your marketplace adapter independently verifies payment.
Not an official Zhaket/RTL connector. Never run this code on the customer's browser.
"""
import argparse
import hashlib
import hmac
import json
import os
import time
import urllib.error
import urllib.request
from urllib.parse import urlsplit, urlencode

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Do not forward signed purchase data to another host.

p = argparse.ArgumentParser(description=__doc__)
p.add_argument('--url', required=True, help='HTTPS server.php URL')
p.add_argument('--platform', required=True, help='e.g. rtl or zhaket, after building a real verified adapter')
p.add_argument('--order-id', required=True, help='Unique marketplace order ID')
p.add_argument('--expires-at', type=int, default=0, help='Unix UTC timestamp, or 0 for lifetime')
a = p.parse_args()
u = urlsplit(a.url)
if u.scheme != 'https' or not u.hostname or u.username or u.password or u.query or u.fragment:
    p.error('Use a clean HTTPS URL')
secret = os.environ.get('PIF_WEBHOOK_SECRET', '')
if len(secret) < 32:
    p.error('Set PIF_WEBHOOK_SECRET securely in the environment (at least 32 characters)')
body = json.dumps({'event': 'purchase.completed', 'status': 'paid', 'product': 'product-image-finder',
                   'platform': a.platform, 'order_id': a.order_id, 'expires_at': a.expires_at}, separators=(',', ':')).encode()
ts = str(int(time.time()))
sig = hmac.new(secret.encode(), ts.encode() + b'.' + body, hashlib.sha256).hexdigest()
req = urllib.request.Request(a.url + '?' + urlencode({'action': 'webhook'}), data=body, method='POST',
    headers={'Content-Type': 'application/json', 'X-PIF-Timestamp': ts, 'X-PIF-Signature': sig})
try:
    with urllib.request.build_opener(NoRedirect).open(req, timeout=30) as r:
        print(r.read(16384).decode())
except urllib.error.HTTPError as e:
    raise SystemExit(f'Server returned HTTP {e.code}: {e.read(16384).decode()}')
except urllib.error.URLError:
    raise SystemExit('Connection failed. Retry using the same platform + order ID; do not create a new order ID.')
