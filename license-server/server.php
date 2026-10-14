<?php
/**
 * Product Image Finder — standalone license service, PHP 8.0+, OpenSSL.
 * Upload ONLY this file. Keep storage OUTSIDE public_html/document root.
 * Set environment variables, or edit the fallback values below on YOUR host.
 * Never put production secrets in Git or in the WordPress plugin.
 */
declare(strict_types=1);

$config = [
    'admin_token' => getenv('PIF_ADMIN_TOKEN') ?: '', // At least 32 random characters.
    'webhook_secret' => getenv('PIF_WEBHOOK_SECRET') ?: '', // Optional, different from admin token.
    'storage' => getenv('PIF_STORAGE_DIR') ?: '', // Absolute PRIVATE path; e.g. /home/account/pif-data.
    'product' => 'product-image-finder',
    'ttl' => 86400, // Signed entitlement lifetime: at most 24h before revocation takes effect.
];

function reply(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function fail(string $code, int $status = 400): void { reply(['ok' => false, 'error' => $code], $status); }
function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function domain_name(string $domain): string {
    $domain = strtolower(rtrim(trim($domain), '.'));
    if (strpos($domain, 'www.') === 0) { $domain = substr($domain, 4); }
    if (strlen($domain) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
        fail('invalid_domain');
    }
    return $domain;
}
function field(array $input, string $key, int $max = 255): string {
    if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key]) > $max) { fail('invalid_' . $key); }
    return trim($input[$key]);
}
function admin_auth(array $config): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!hash_equals('Bearer ' . $config['admin_token'], $header)) { fail('unauthorized', 401); }
}
function storage_path(array $config): string {
    $path = $config['storage'];
    if ($path === '' || $path[0] !== '/' || strpos($path, '://') !== false) { fail('private_storage_not_configured', 503); }
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) { throw new RuntimeException('storage'); }
    $real = realpath($path);
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) ?: __DIR__;
    // Forbid the document root and ANY folder inside it, independent of .htaccess support.
    if (!$real || $real === $root || strpos($real . '/', rtrim($root, '/') . '/') === 0 || $real === DIRECTORY_SEPARATOR) {
        fail('storage_must_be_outside_document_root', 503);
    }
    if (!is_writable($real)) { throw new RuntimeException('storage'); }
    return $real;
}
function atomic_write(string $path, string $data): void {
    $temp = tempnam(dirname($path), '.pif-');
    if ($temp === false) { throw new RuntimeException('write'); }
    try {
        chmod($temp, 0600);
        if (file_put_contents($temp, $data) !== strlen($data) || !rename($temp, $path)) { throw new RuntimeException('write'); }
    } finally { if (is_file($temp)) { unlink($temp); } }
}
function load_records(string $dir): array {
    $file = $dir . '/licenses.txt';
    if (!file_exists($file)) { return []; }
    $text = file_get_contents($file);
    if ($text === false) { throw new RuntimeException('read'); }
    $records = [];
    foreach (explode("\n", trim($text)) as $line) {
        if ($line === '') { continue; }
        $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($row) || !isset($row['key'], $row['order_id'], $row['status'])) { throw new RuntimeException('database'); }
        $records[] = $row;
    }
    return $records;
}
function save_records(string $dir, array $records): void {
    $lines = array_map(static function ($row) { return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }, $records);
    atomic_write($dir . '/licenses.txt', implode("\n", $lines) . ($lines ? "\n" : ''));
}
function with_lock(string $dir, callable $callback) {
    $lock = fopen($dir . '/database.lock', 'c');
    if (!$lock) { throw new RuntimeException('lock'); }
    chmod($dir . '/database.lock', 0600);
    if (!flock($lock, LOCK_EX)) { fclose($lock); throw new RuntimeException('lock'); }
    try { return $callback(); } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function public_key(string $dir, bool $initialize = false): string {
    $file = $dir . '/signing-private.pem';
    if (!is_file($file)) {
        if (!$initialize) { fail('server_not_initialized', 503); }
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key || !openssl_pkey_export($key, $pem)) { throw new RuntimeException('key_generation'); }
        atomic_write($file, $pem);
    }
    $key = openssl_pkey_get_private(file_get_contents($file));
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA) { throw new RuntimeException('private_key'); }
    return $details['key'];
}
function rate_limit(string $dir): void {
    // Fixed-window per-IP limiter, under the global database lock. Do not trust X-Forwarded-For.
    $file = $dir . '/rate-limits.json';
    $rates = is_file($file) ? json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : [];
    $now = time();
    foreach ($rates as $ip => $entry) { if ($entry['until'] <= $now) { unset($rates[$ip]); } }
    $id = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $entry = $rates[$id] ?? ['until' => $now + 60, 'count' => 0];
    if ($entry['count'] >= 60) { header('Retry-After: 60'); fail('rate_limited', 429); }
    if (count($rates) > 10000 && !isset($rates[$id])) { fail('busy', 429); }
    $entry['count']++;
    $rates[$id] = $entry;
    atomic_write($file, json_encode($rates, JSON_THROW_ON_ERROR));
}
function issue(array $input, array &$records): array {
    $order = field($input, 'order_id', 160);
    $platform = field($input, 'platform', 32);
    if ($order === '' || !preg_match('/^[a-z0-9_-]{2,32}$/D', $platform)) { fail('invalid_order'); }
    $expires = $input['expires_at'] ?? 0;
    if (!is_int($expires) || ($expires !== 0 && $expires <= time())) { fail('invalid_expiry'); }
    foreach ($records as $row) {
        if ($row['order_id'] === $order && $row['platform'] === $platform) {
            // A retry never creates a second key or silently changes the original entitlement.
            return ['ok' => true, 'license' => $row['key'], 'status' => $row['status'], 'existing' => true];
        }
    }
    $key = 'PIF-' . strtoupper(implode('-', str_split(bin2hex(random_bytes(16)), 8)));
    $records[] = ['key' => $key, 'order_id' => $order, 'platform' => $platform, 'created_at' => time(),
        'expires_at' => $expires, 'domain' => '', 'status' => 'active', 'last_seen' => 0];
    return ['ok' => true, 'license' => $key, 'status' => 'active', 'existing' => false];
}
function console_page(): void {
    $nonce = base64_encode(random_bytes(18));
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت لایسنس تصویرجو</title><style nonce="' . $nonce . '">body{font:16px/1.9 Tahoma,sans-serif;background:#f2f5fa;color:#17263d;max-width:850px;margin:40px auto;padding:20px}section{background:white;padding:28px;border-radius:16px;margin-bottom:16px}label{display:block;margin-top:12px}input,select,button{box-sizing:border-box;font:inherit;padding:10px;border:1px solid #b9c6d8;border-radius:8px}input{width:100%;direction:ltr}button{cursor:pointer;background:#174bd3;color:white;margin:8px 0 0 8px}pre{direction:ltr;text-align:left;white-space:pre-wrap;overflow-wrap:anywhere;background:#eaf0f8;padding:18px;border-radius:8px}small{color:#54647c}</style><h1>مدیریت لایسنس تصویرجو</h1><section><label>رمز مدیریت (Admin token)</label><input id="secret" type="password" autocomplete="off"><small>رمز فقط در حافظه همین صفحه می‌ماند. این صفحه را فقط با HTTPS باز کنید.</small><p><button data-action="init">راه‌اندازی / دریافت کلید عمومی</button><button data-action="list">فهرست ۱۰۰ لایسنس اخیر</button></p></section><section><h2>صدور لایسنس</h2><label>شناسه سفارش یکتا</label><input id="order"><label>فروشگاه</label><select id="platform"><option value="rtl">راست‌چین</option><option value="zhaket">ژاکت</option><option value="manual">دستی / آزمایشی</option></select><label>انقضا (اختیاری، تاریخ UTC)</label><input id="expiry" type="date"><button data-action="issue">صدور / بازیابی برای همان سفارش</button></section><section><h2>مدیریت یک لایسنس</h2><label>کد لایسنس</label><input id="license" autocomplete="off"><button data-action="revoke">لغو لایسنس</button><button data-action="reset">آزادسازی دامنه</button><small>آزادسازی برای انتقال قانونی به دامنه جدید است. توکن قبلی حداکثر ۲۴ ساعت اعتبار دارد.</small></section><section><h2>پاسخ سرور</h2><pre id="result" aria-live="polite">آماده</pre></section><script nonce="' . $nonce . '">
const $ = id => document.getElementById(id);
document.querySelectorAll("button[data-action]").forEach(button => button.addEventListener("click", async () => {
const action = button.dataset.action;
if ((action === "revoke" || action === "reset") && !confirm("این تغییر اعمال شود؟")) return;
let body = {};
if (action === "issue") body = {order_id: $("order").value, platform: $("platform").value, expires_at: $("expiry").value ? Math.floor(Date.parse($("expiry").value + "T23:59:59Z") / 1000) : 0};
if (action === "revoke" || action === "reset") body = {license: $("license").value};
button.disabled = true; $("result").textContent = "در حال ارسال…";
try {const url = new URL(location.href); url.search = "?action=" + action;
const response = await fetch(url, {method: "POST", headers: {"Content-Type": "application/json", Authorization: "Bearer " + $("secret").value}, body: JSON.stringify(body), credentials: "omit"});
const data = await response.json(); $("result").textContent = JSON.stringify(data, null, 2);
} catch(e) {$("result").textContent = "ارتباط برقرار نشد؛ HTTPS و تنظیمات هاست را بررسی کنید.";} finally {button.disabled = false;}
}));</script></html>';
    exit;
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
ini_set('display_errors', '0');
umask(0077);
try {
    $action = $_GET['action'] ?? 'console';
    if (!is_string($action)) { fail('invalid_action'); }
    // Behind a trusted TLS proxy, configure the web server to pass HTTPS=on.
    // Never trust arbitrary client-supplied X-Forwarded-Proto headers here.
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1' && (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 443) { fail('https_required', 426); }
    if ($action === 'console' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { console_page(); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); fail('post_required', 405); }
    if (strlen($config['admin_token']) < 32 || !extension_loaded('openssl')) { fail('server_not_configured', 503); }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) { fail('body_too_large', 413); }
    $input = json_decode($raw, true, 16);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) { fail('invalid_json'); }
    $admin_actions = ['init', 'issue', 'list', 'revoke', 'reset'];
    if (in_array($action, $admin_actions, true)) { admin_auth($config); }
    elseif ($action === 'webhook') {
        if (strlen($config['webhook_secret']) < 32) { fail('webhook_not_configured', 503); }
        $timestamp = $_SERVER['HTTP_X_PIF_TIMESTAMP'] ?? '';
        $signature = $_SERVER['HTTP_X_PIF_SIGNATURE'] ?? '';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 ||
            !hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $config['webhook_secret']), $signature)) { fail('invalid_signature', 401); }
        if (($input['event'] ?? '') !== 'purchase.completed' || ($input['status'] ?? '') !== 'paid' || ($input['product'] ?? '') !== $config['product']) { fail('unsupported_event'); }
    } elseif ($action !== 'activate' && $action !== 'verify') { fail('not_found', 404); }
    $dir = storage_path($config);
    $result = with_lock($dir, function () use ($action, $input, $dir, $config): array {
        if ($action === 'init') { return ['ok' => true, 'public_key' => public_key($dir, true), 'product' => $config['product']]; }
        $records = load_records($dir);
        if ($action === 'list') {
            $offset = max(0, (int) ($input['offset'] ?? 0));
            $rows = array_slice(array_reverse($records), $offset, 100);
            foreach ($rows as &$row) { $row['key'] = substr($row['key'], 0, 12) . '-…-' . substr($row['key'], -8); }
            unset($row);
            return ['ok' => true, 'total' => count($records), 'offset' => $offset, 'licenses' => $rows];
        }
        if ($action === 'issue' || $action === 'webhook') {
            public_key($dir, true);
            $result = issue($input, $records);
            save_records($dir, $records);
            return $result;
        }
        if ($action === 'activate' || $action === 'verify') { rate_limit($dir); }
        $key = strtoupper(field($input, 'license', 64));
        $index = null;
        foreach ($records as $i => $row) { if (hash_equals($row['key'], $key)) { $index = $i; break; } }
        if ($index === null) { fail('invalid_license', 403); }
        $row = $records[$index];
        if ($action === 'revoke' || $action === 'reset') {
            if ($action === 'revoke') { $records[$index]['status'] = 'revoked'; }
            else { $records[$index]['domain'] = ''; }
            save_records($dir, $records);
            return ['ok' => true];
        }
        if ($row['status'] !== 'active') { fail('revoked_license', 403); }
        if ($row['expires_at'] && $row['expires_at'] <= time()) { fail('expired_license', 403); }
        if (($input['product'] ?? '') !== $config['product']) { fail('invalid_product', 403); }
        $domain = domain_name(field($input, 'domain'));
        if ($row['domain'] !== '' && $row['domain'] !== $domain) { fail('domain_mismatch', 403); }
        if ($row['domain'] === '' && $action !== 'activate') { fail('activation_required', 403); }
        public_key($dir);
        $now = time();
        $claims = ['v' => 1, 'product' => $config['product'], 'domain' => $domain, 'key_hash' => hash('sha256', $key),
            'iat' => $now, 'exp' => min($now + $config['ttl'], $row['expires_at'] ?: PHP_INT_MAX)];
        $payload = b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $private = openssl_pkey_get_private(file_get_contents($dir . '/signing-private.pem'));
        if (!$private || !openssl_sign($payload, $signature, $private, OPENSSL_ALGO_SHA256)) { throw new RuntimeException('sign'); }
        $records[$index]['domain'] = $domain;
        $records[$index]['last_seen'] = $now;
        save_records($dir, $records);
        return ['ok' => true, 'token' => $payload . '.' . b64($signature)];
    });
    reply($result);
} catch (Throwable $error) {
    error_log('PIF license service failure: ' . get_class($error));
    fail('server_error', 500);
}
