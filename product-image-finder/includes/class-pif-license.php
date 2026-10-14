<?php
if (!defined('ABSPATH')) { exit; }
final class PIF_License {
    public static function config(): array { return require PIF_DIR . 'vendor-config.php'; }
    public static function configured(): bool {
        $c = self::config();
        return extension_loaded('openssl') && !empty($c['public_key']) &&
            wp_parse_url($c['server_url'], PHP_URL_SCHEME) === 'https' &&
            (bool) wp_parse_url($c['server_url'], PHP_URL_HOST);
    }
    public static function domain(): string {
        $host = strtolower(rtrim((string) wp_parse_url(home_url('/'), PHP_URL_HOST), '.'));
        if (function_exists('idn_to_ascii')) { $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host; }
        return preg_replace('/^www\./', '', $host);
    }
    private static function decode(string $value) {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) { return false; }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
    /** Only a signed, unexpired, site-bound entitlement unlocks server-side operations. */
    public static function claims(string $token, string $key) {
        if (!self::configured() || strlen($token) > 8192) { return false; }
        $parts = explode('.', $token);
        if (count($parts) !== 2) { return false; }
        $payload = self::decode($parts[0]);
        $signature = self::decode($parts[1]);
        if ($payload === false || $signature === false) { return false; }
        $config = self::config();
        $public = openssl_pkey_get_public($config['public_key']);
        if (!$public || openssl_verify($parts[0], $signature, $public, OPENSSL_ALGO_SHA256) !== 1) { return false; }
        $c = json_decode($payload, true, 16);
        if (!is_array($c) || ($c['v'] ?? null) !== 1 || ($c['product'] ?? '') !== $config['product'] ||
            ($c['domain'] ?? '') !== self::domain() || !is_string($c['key_hash'] ?? null) ||
            !hash_equals(hash('sha256', $key), $c['key_hash']) || !is_int($c['exp'] ?? null) ||
            !is_int($c['iat'] ?? null) || $c['iat'] > time() + 300 || $c['exp'] <= time() ||
            $c['exp'] <= $c['iat'] || $c['exp'] - $c['iat'] > DAY_IN_SECONDS) { return false; }
        return $c;
    }
    public static function active(): bool {
        $data = get_option('pif_license', []);
        return is_array($data) && !empty($data['key']) && !empty($data['token']) &&
            self::claims((string) $data['token'], (string) $data['key']) !== false;
    }
    public static function message(string $code): string {
        $messages = [
            'not_configured' => 'نسخه فروشنده هنوز پیکربندی نشده است؛ نشانی سرور HTTPS و کلید عمومی باید در بسته افزونه قرار گیرند. OpenSSL نیز باید فعال باشد.',
            'invalid_license' => 'کد لایسنس صحیح نیست.', 'revoked_license' => 'این لایسنس لغو شده است.',
            'expired_license' => 'مهلت لایسنس تمام شده است.', 'domain_mismatch' => 'این لایسنس روی دامنه دیگری فعال است. برای انتقال با فروشنده تماس بگیرید.',
            'activation_required' => 'دامنه لایسنس آزاد شده است؛ دوباره دکمه فعال‌سازی را بزنید.',
            'invalid_domain' => 'دامنه معتبر نیست؛ سایت باید دامنه عمومی داشته باشد.',
            'rate_limited' => 'درخواست‌های زیادی ارسال شده است؛ یک دقیقه دیگر تلاش کنید.',
            'invalid_response' => 'امضای پاسخ سرور معتبر نیست. کلید عمومی یا ساعت هاست را بررسی کنید.',
            'network_error' => 'سرور لایسنس در دسترس نیست؛ دوباره تلاش کنید. توکن قبلی فقط تا پایان اعتبار خود کار می‌کند.',
            'server_error' => 'سرور لایسنس آماده نیست؛ با فروشنده تماس بگیرید.',
            'invalid_format' => 'فرمت کد لایسنس درست نیست.',
        ];
        return $messages[$code] ?? $messages['server_error'];
    }
    private static function request(string $action, string $key) {
        if (!self::configured()) { return new WP_Error('not_configured', self::message('not_configured')); }
        $config = self::config();
        $response = wp_safe_remote_post(add_query_arg('action', $action, $config['server_url']), [
            'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 16384,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode(['license' => $key, 'domain' => self::domain(), 'product' => $config['product']]),
        ]);
        if (is_wp_error($response)) { return new WP_Error('network_error', self::message('network_error')); }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200 || !is_array($body) || ($body['ok'] ?? false) !== true) {
            $code = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'server_error';
            $allowed = ['invalid_license', 'revoked_license', 'expired_license', 'domain_mismatch', 'activation_required', 'invalid_domain', 'rate_limited'];
            if (!in_array($code, $allowed, true)) { $code = 'server_error'; }
            return new WP_Error($code, self::message($code));
        }
        if (!is_string($body['token'] ?? null) || !self::claims($body['token'], $key)) {
            return new WP_Error('invalid_response', self::message('invalid_response'));
        }
        return $body['token'];
    }
    public static function activate(string $key) {
        $key = strtoupper(trim($key));
        if (!preg_match('/^PIF-(?:[A-F0-9]{8}-){3}[A-F0-9]{8}$/D', $key)) { return new WP_Error('invalid_format', self::message('invalid_format')); }
        $token = self::request('activate', $key);
        if (is_wp_error($token)) { return $token; }
        update_option('pif_license', ['key' => $key, 'token' => $token, 'checked_at' => time(), 'error' => ''], false);
        return true;
    }
    public static function refresh(bool $force = false) {
        $data = get_option('pif_license', []);
        if (!is_array($data) || empty($data['key'])) { return false; }
        if (!$force && time() - (int) ($data['checked_at'] ?? 0) < 6 * HOUR_IN_SECONDS) { return self::active(); }
        if (get_transient('pif_license_refresh_lock')) { return self::active(); }
        set_transient('pif_license_refresh_lock', 1, 30);
        $token = self::request('verify', $data['key']);
        $data['checked_at'] = time();
        if (is_wp_error($token)) {
            $data['error'] = $token->get_error_code();
            if (in_array($data['error'], ['invalid_license', 'revoked_license', 'expired_license', 'domain_mismatch', 'activation_required'], true)) { $data['token'] = ''; }
        } else { $data['token'] = $token; $data['error'] = ''; }
        update_option('pif_license', $data, false);
        delete_transient('pif_license_refresh_lock');
        return is_wp_error($token) ? $token : true;
    }
    public static function require_active(): void {
        if (!self::active()) { self::refresh(); }
        if (!self::active()) { wp_send_json_error(['message' => 'برای استفاده از تصویرجو، ابتدا لایسنس معتبر را فعال کنید.'], 403); }
    }
}
