// Real PHP/OpenSSL execution under PHP.wasm. No WordPress or remote-provider mock is claimed as E2E.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { PHP, FileLockManagerInMemory } from '../.tools/node_modules/@php-wasm/universal/index.js';
import { loadNodeRuntime } from '../.tools/node_modules/@php-wasm/node/index.js';
const php = new PHP(await loadNodeRuntime(process.env.PHP_VERSION || '8.3', {emscriptenOptions: {processId: 1}, fileLockManager: new FileLockManagerInMemory()}));
php.mkdir('/pif-public'); php.mkdir('/pif-private'); php.mkdir('/pif-plugin');
php.writeFile('/pif-public/server.php', fs.readFileSync('license-server/server.php'));
const secret = 'test-admin-' + crypto.randomBytes(24).toString('hex');
const webhook = 'test-hook-' + crypto.randomBytes(24).toString('hex');
php.mkdir('/root/install/ssl');
php.writeFile('/root/install/ssl/openssl.cnf', '[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n');
const env = { OPENSSL_CONF: '/root/install/ssl/openssl.cnf', PIF_ADMIN_TOKEN: secret, PIF_WEBHOOK_SECRET: webhook, PIF_STORAGE_DIR: '/pif-private' };
let passed = 0;
function ok(name, condition) { assert.ok(condition, name); console.log('PASS', name); passed++; }
async function call(action, body = {}, { admin = false, headers = {}, overrideEnv = {}, method = 'POST', raw } = {}) {
    const r = await php.run({scriptPath: '/pif-public/server.php', relativeUri: '/server.php?action=' + action,
        method, protocol: 'https', headers: {'Content-Type': 'application/json', ...(admin ? {Authorization: 'Bearer ' + secret} : {}), ...headers},
        body: new TextEncoder().encode(raw ?? JSON.stringify(body)), env: {...env, ...overrideEnv},
        $_SERVER: {HTTPS: 'on', DOCUMENT_ROOT: '/pif-public', REMOTE_ADDR: '198.51.100.5'}});
    assert.equal(r.errors, '', r.errors);
    try { return {status: r.httpStatusCode, ...JSON.parse(r.text)}; }
    catch (_) { throw new Error('Invalid response: ' + r.text); }
}
try {
    ok('admin authentication required', (await call('issue')).status === 401);
    ok('reject invalid JSON', (await call('activate', {}, {raw: '{'})).error === 'invalid_json');
    ok('reject oversized body', (await call('activate', {}, {raw: 'x'.repeat(17000)})).status === 413);
    ok('GET does not mutate records', (await call('issue', {}, {method: 'GET'})).status === 405);
    ok('private storage required', (await call('init', {}, {admin: true, overrideEnv: {PIF_STORAGE_DIR: '/pif-public/data'}})).error === 'storage_must_be_outside_document_root');
    const init = await call('init', {}, {admin: true});
    ok('initialize RSA public key', init.ok && init.public_key.includes('BEGIN PUBLIC KEY'));
    const order = {platform: 'manual', order_id: 'test-001', expires_at: 0};
    const issued = await call('issue', order, {admin: true});
    ok('issue random license', /^PIF-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/.test(issued.license));
    const again = await call('issue', order, {admin: true});
    ok('duplicate order is idempotent', again.existing && again.license === issued.license);
    const key = issued.license;
    const body = {license: key, domain: 'www.Example.com', product: 'product-image-finder'};
    ok('unknown key rejected', (await call('activate', {...body, license: 'nope'})).error === 'invalid_license');
    ok('product binding enforced', (await call('activate', {...body, product: 'other'})).error === 'invalid_product');
    ok('first verification requires activation', (await call('verify', body)).error === 'activation_required');
    const activated = await call('activate', body);
    ok('activate signs token', activated.ok && activated.token.includes('.'));
    const [payload, signature] = activated.token.split('.');
    ok('signature verifies independently in Node OpenSSL', crypto.verify('RSA-SHA256', Buffer.from(payload), init.public_key, Buffer.from(signature, 'base64url')));
    const claims = JSON.parse(Buffer.from(payload, 'base64url'));
    ok('canonical domain, key hash, and 24h TTL', claims.domain === 'example.com' && claims.key_hash === crypto.createHash('sha256').update(key).digest('hex') && claims.exp - claims.iat === 86400);
    ok('another domain is rejected', (await call('activate', {...body, domain: 'other.example.com'})).error === 'domain_mismatch');
    ok('valid refresh succeeds', (await call('verify', body)).ok);
    const listed = await call('list', {}, {admin: true});
    ok('listing masks license keys', listed.total === 1 && !JSON.stringify(listed).includes(key));
    ok('txt file stores license record', php.readFileAsText('/pif-private/licenses.txt').includes(key));

    // Execute the plugin verifier with only minimal WordPress environment stubs.
    const phpString = value => "'" + value.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
    php.writeFile('/pif-plugin/vendor-config.php', '<?php return ' + "['server_url'=>'https://licenses.example.com/server.php','product'=>'product-image-finder','public_key'=>" + phpString(init.public_key) + '];');
    php.writeFile('/pif-plugin/class-pif-license.php', fs.readFileSync('product-image-finder/includes/class-pif-license.php'));
    async function pluginAccepts(token, license = key, domain = 'example.com') {
        const r = await php.run({code: `<?php define('ABSPATH','/'); define('PIF_DIR','/pif-plugin/'); define('DAY_IN_SECONDS',86400);
            function wp_parse_url($s,$p){return parse_url($s,$p);} function home_url($s){return ${phpString('https://' + domain + '/')};}
            require '/pif-plugin/class-pif-license.php'; echo PIF_License::claims(${phpString(token)},${phpString(license)}) !== false ? 'yes' : 'no';`});
        assert.equal(r.errors, ''); return r.text === 'yes';
    }
    ok('plugin accepts valid signed entitlement', await pluginAccepts(activated.token));
    ok('plugin rejects modified signature', !await pluginAccepts(payload + '.AAAA'));
    ok('plugin rejects copied license key', !await pluginAccepts(activated.token, 'PIF-WRONG'));
    ok('plugin rejects copied site token', !await pluginAccepts(activated.token, key, 'another.example.com'));
    const privateKey = php.readFileAsText('/pif-private/signing-private.pem');
    function tokenFor(changes) {
        const p = Buffer.from(JSON.stringify({...claims, ...changes})).toString('base64url');
        return p + '.' + crypto.sign('RSA-SHA256', Buffer.from(p), privateKey).toString('base64url');
    }
    ok('plugin rejects expired token', !await pluginAccepts(tokenFor({iat: 1, exp: 10})));
    ok('plugin rejects excessive TTL', !await pluginAccepts(tokenFor({exp: claims.iat + 86401})));
    ok('plugin rejects future issued token', !await pluginAccepts(tokenFor({iat: claims.iat + 400, exp: claims.iat + 500})));
    ok('plugin rejects wrong signed product', !await pluginAccepts(tokenFor({product: 'another'})));
    ok('plugin rejects modified payload', !await pluginAccepts(Buffer.from(JSON.stringify({...claims, domain: 'another.example.com'})).toString('base64url') + '.' + signature));
    ok('admin can release domain', (await call('reset', {license: key}, {admin: true})).ok);
    ok('domain can move after release', (await call('activate', {...body, domain: 'new.example.com'})).ok);
    ok('admin can revoke', (await call('revoke', {license: key}, {admin: true})).ok);
    ok('revoked license cannot verify', (await call('verify', {...body, domain: 'new.example.com'})).error === 'revoked_license');
    ok('revoked order retry does not re-enable license', (await call('issue', order, {admin: true})).status === 'revoked');
    ok('unsigned webhook rejected', (await call('webhook', {})).status === 401);
    const event = {platform: 'adapter', order_id: 'sale-22', event: 'purchase.completed', status: 'paid', product: 'product-image-finder'};
    async function hook(input, ts = String(Math.floor(Date.now() / 1000))) {
        const raw = JSON.stringify(input);
        return call('webhook', input, {raw, headers: {'X-PIF-Timestamp': ts, 'X-PIF-Signature': crypto.createHmac('sha256', webhook).update(ts + '.' + raw).digest('hex')}});
    }
    ok('old webhook signatures rejected', (await hook(event, '1')).status === 401);
    ok('unpaid webhook rejected', (await hook({...event, status: 'pending'})).error === 'unsupported_event');
    const purchase = await hook(event);
    ok('signed paid webhook issues key', purchase.ok && !purchase.existing);
    ok('webhook retries return same key', (await hook(event)).license === purchase.license);
    ok('invalid expiry rejected', (await call('issue', {...order, order_id: 'bad', expires_at: 5}, {admin: true})).error === 'invalid_expiry');
    const rows = php.readFileAsText('/pif-private/licenses.txt').trim().split('\n').map(JSON.parse);
    rows[1].expires_at = 1;
    php.writeFile('/pif-private/licenses.txt', rows.map(JSON.stringify).join('\n') + '\n');
    ok('expired key cannot activate', (await call('activate', {...body, license: purchase.license})).error === 'expired_license');
    let last;
    for (let i = 0; i < 65; i++) last = await call('activate', {...body, license: 'wrong'});
    ok('public activation is rate limited', last.status === 429);
    console.log(`\n${passed} checks passed on PHP ${process.env.PHP_VERSION || '8.3'}.`);
} finally { php.exit(); }
