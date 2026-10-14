#!/usr/bin/env python3
"""Build a clean WordPress ZIP, optionally configured for the vendor's license host."""
import argparse
import base64
import hashlib
import re
from pathlib import Path
from urllib.parse import urlsplit
from zipfile import ZipFile, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parents[1]

def php_quote(value):
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--server-url', help='HTTPS URL of the standalone server.php')
    p.add_argument('--public-key', type=Path, help='Public PEM from the server init action; NEVER a private key')
    p.add_argument('--output', type=Path, default=ROOT / 'packages/product-image-finder-1.0.0-template.zip')
    args = p.parse_args()
    if bool(args.server_url) != bool(args.public_key):
        p.error('--server-url and --public-key must be provided together')
    config = None
    if args.server_url:
        u = urlsplit(args.server_url)
        if u.scheme != 'https' or not u.hostname or u.username or u.password or u.query or u.fragment or u.port not in (None, 443):
            p.error('Use a public HTTPS URL without credentials, query string, fragment or nonstandard port')
        key = args.public_key.read_text().strip()
        if not re.fullmatch(r'-----BEGIN PUBLIC KEY-----\s+[A-Za-z0-9+/=\s]+-----END PUBLIC KEY-----', key):
            p.error('Expected a PEM PUBLIC KEY, not a private key or a certificate')
        try:
            der = base64.b64decode(''.join(key.splitlines()[1:-1]), validate=True)
        except ValueError:
            p.error('Invalid PEM encoding')
        if len(der) < 256 or b'\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01' not in der:
            p.error('Expected an RSA public key of at least 2048 bits')
        config = "<?php\nif (!defined('ABSPATH')) { exit; }\nreturn [\n    'server_url' => " + php_quote(args.server_url) + ",\n    'public_key' => " + php_quote(key + '\n') + ",\n    'product' => 'product-image-finder',\n];\n"
    source = ROOT / 'product-image-finder'
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with ZipFile(args.output, 'w', ZIP_DEFLATED, compresslevel=9) as z:
        for file in sorted(source.rglob('*')):
            if not file.is_file() or file.suffix not in ('.php', '.js', '.css', '.txt', '.md'):
                continue
            data = config.encode() if config is not None and file.name == 'vendor-config.php' else file.read_bytes()
            # Deterministic metadata makes rebuilds reproducible.
            from zipfile import ZipInfo
            entry = ZipInfo(str(file.relative_to(ROOT)), date_time=(2026, 10, 14, 0, 0, 0))
            entry.compress_type = ZIP_DEFLATED
            entry.external_attr = 0o644 << 16
            z.writestr(entry, data)
    digest = hashlib.sha256(args.output.read_bytes()).hexdigest()
    args.output.with_suffix('.zip.sha256').write_text(f'{digest}  {args.output.name}\n')
    print(f'{"Configured" if config else "UNCONFIGURED TEMPLATE"}: {args.output}')
    print(f'SHA256: {digest}')
    if config is None:
        print('Installable template only. License activation stays locked until vendor configuration is built.')

if __name__ == '__main__':
    main()
