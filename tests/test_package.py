"""Verify installable layout, template safety and configured builds without production secrets."""
import json
from pathlib import Path
import subprocess
import tempfile
from zipfile import ZipFile

root = Path(__file__).resolve().parents[1]
zip_path = root / 'packages/product-image-finder-1.0.0-template.zip'
with ZipFile(zip_path) as z:
    names = z.namelist()
    assert 'product-image-finder/product-image-finder.php' in names
    assert 'product-image-finder/LICENSE.txt' in names
    assert all(name.startswith('product-image-finder/') for name in names)
    assert not any('server.php' in name or '.pem' in name or 'licenses.txt' in name for name in names)
    assert "'server_url' => ''" in z.read('product-image-finder/vendor-config.php').decode()
    assert z.testzip() is None

with tempfile.TemporaryDirectory() as directory:
    tmp = Path(directory)
    # Node generates a throwaway key pair; only its public half is written and packaged.
    result = subprocess.check_output(['node', '-e', "const c=require('crypto');const k=c.generateKeyPairSync('rsa',{modulusLength:2048});console.log(JSON.stringify(k.publicKey.export({type:'spki',format:'pem'})))"], text=True)
    public = json.loads(result)
    key_file = tmp / 'public.pem'
    key_file.write_text(public)
    target = tmp / 'configured.zip'
    subprocess.run(['python3', str(root / 'tools/build.py'), '--server-url', 'https://licenses.example.com/server.php', '--public-key', str(key_file), '--output', str(target)], check=True)
    with ZipFile(target) as z:
        config = z.read('product-image-finder/vendor-config.php').decode()
        assert 'https://licenses.example.com/server.php' in config
        assert public.strip() in config
        assert 'PRIVATE KEY' not in config
        assert set(z.namelist()) == set(names)
    invalid = subprocess.run(['python3', str(root / 'tools/build.py'), '--server-url', 'http://unsafe.example.com', '--public-key', str(key_file), '--output', str(target)], capture_output=True)
    assert invalid.returncode != 0
print('PASS package layout, no private server files, configured public-key build, and rejection of HTTP URL')
