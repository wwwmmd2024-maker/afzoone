<?php
if (!defined('ABSPATH')) { exit; }
final class PIF_Images {
    private const MAX_BYTES = 10485760;
    public static function init(): void {
        add_action('wp_ajax_pif_search', [__CLASS__, 'search']);
        add_action('wp_ajax_pif_import', [__CLASS__, 'import']);
    }
    private static function guard(): int {
        check_ajax_referer('pif_images', 'nonce');
        $post_id = absint($_POST['post_id'] ?? 0);
        if (!class_exists('WooCommerce') || !current_user_can('edit_products') || !current_user_can('upload_files') ||
            !$post_id || get_post_type($post_id) !== 'product' || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'اجازه ویرایش این محصول یا بارگذاری تصویر را ندارید.'], 403);
        }
        PIF_License::require_active();
        return $post_id;
    }
    private static function https_url($value): string {
        if (!is_string($value) || strlen($value) > 4096) { return ''; }
        $url = esc_url_raw($value, ['https']);
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || wp_parse_url($url, PHP_URL_USER) || wp_parse_url($url, PHP_URL_PASS)) { return ''; }
        return $url;
    }
    private static function api(string $url, array $headers = []) {
        $response = wp_safe_remote_get($url, ['headers' => $headers, 'timeout' => 20, 'redirection' => 0,
            'limit_response_size' => 1048576, 'user-agent' => 'ProductImageFinder/' . PIF_VERSION . ' (' . home_url('/') . ')']);
        if (is_wp_error($response)) { return new WP_Error('network', 'ارتباط با سرویس تصویر برقرار نشد. اتصال خروجی هاست را بررسی کنید.'); }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('provider', 'سرویس جست‌وجو پاسخ موفق نداد؛ سهمیه، کلید API یا دسترسی هاست را بررسی کنید.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : new WP_Error('response', 'پاسخ سرویس تصویر قابل خواندن نیست.');
    }
    private static function commons(string $query) {
        $url = add_query_arg(['action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => $query,
            'gsrnamespace' => 6, 'gsrlimit' => 20, 'prop' => 'imageinfo', 'iiprop' => 'url|extmetadata|mime|size',
            'iiurlwidth' => 360, 'iiurlheight' => 280], 'https://commons.wikimedia.org/w/api.php');
        $data = self::api($url);
        if (is_wp_error($data)) { return $data; }
        if (isset($data['error'])) { return new WP_Error('provider', 'سرویس Wikimedia نتوانست این جست‌وجو را انجام دهد.'); }
        $pages = array_values($data['query']['pages'] ?? []);
        usort($pages, static function ($a, $b) { return ($a['index'] ?? 999) <=> ($b['index'] ?? 999); });
        $items = [];
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? [];
            if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || ($info['size'] ?? 0) > self::MAX_BYTES) { continue; }
            if (($info['width'] ?? 0) * ($info['height'] ?? 0) > 24000000) { continue; }
            $meta = $info['extmetadata'] ?? [];
            $title = preg_replace('/^File:/', '', (string) ($page['title'] ?? ''));
            $items[] = [
                'title' => sanitize_text_field($title), 'url' => self::https_url($info['url'] ?? ''),
                'thumb' => self::https_url($info['thumburl'] ?? ''), 'source' => self::https_url($info['descriptionurl'] ?? ''),
                'license' => sanitize_text_field(wp_strip_all_tags($meta['LicenseShortName']['value'] ?? 'مجوز را در صفحه منبع بررسی کنید')),
                'license_url' => self::https_url($meta['LicenseUrl']['value'] ?? ''),
                'artist' => sanitize_text_field(wp_strip_all_tags($meta['Artist']['value'] ?? '')),
                'provider' => 'commons', 'width' => absint($info['width'] ?? 0), 'height' => absint($info['height'] ?? 0),
            ];
        }
        return $items;
    }
    private static function brave(string $query, string $key) {
        if ($key === '') { return new WP_Error('api_key', 'ابتدا کلید Brave Search API را در تنظیمات وارد کنید.'); }
        $url = add_query_arg(['q' => $query, 'count' => 20, 'safesearch' => 'strict'], 'https://api.search.brave.com/res/v1/images/search');
        $data = self::api($url, ['Accept' => 'application/json', 'X-Subscription-Token' => $key]);
        if (is_wp_error($data)) { return $data; }
        $items = [];
        foreach (($data['results'] ?? []) as $row) {
            $items[] = ['title' => sanitize_text_field($row['title'] ?? ''),
                'url' => self::https_url($row['properties']['url'] ?? ''),
                'thumb' => self::https_url($row['thumbnail']['src'] ?? ''), 'source' => self::https_url($row['url'] ?? ''),
                'license' => 'حق استفاده نامشخص؛ اجازه صاحب اثر لازم است', 'license_url' => '', 'artist' => '',
                'provider' => 'brave', 'width' => absint($row['properties']['width'] ?? 0), 'height' => absint($row['properties']['height'] ?? 0)];
        }
        return $items;
    }
    public static function search(): void {
        $post_id = self::guard();
        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        if (strlen($query) < 2 || strlen($query) > 300) { wp_send_json_error(['message' => 'نام محصول را بین ۲ تا ۳۰۰ بایت وارد کنید.'], 400); }
        $throttle = 'pif_search_' . get_current_user_id();
        if (get_transient($throttle)) { wp_send_json_error(['message' => 'چند ثانیه صبر کنید و دوباره جست‌وجو کنید.'], 429); }
        set_transient($throttle, 1, 3);
        $settings = get_option('pif_settings', []);
        $items = ($settings['provider'] ?? 'commons') === 'brave' ? self::brave($query, $settings['brave_key'] ?? '') : self::commons($query);
        if (is_wp_error($items)) { wp_send_json_error(['message' => $items->get_error_message()], 502); }
        $items = array_slice(array_values(array_filter($items, static function ($item) { return $item['url'] !== '' && $item['thumb'] !== '' && $item['source'] !== ''; })), 0, 12);
        $batch = wp_generate_password(32, false, false);
        // Browser supplies only the batch + index; arbitrary remote URLs are NEVER accepted for import.
        set_transient('pif_batch_' . $batch, ['user' => get_current_user_id(), 'post' => $post_id, 'items' => $items], 20 * MINUTE_IN_SECONDS);
        foreach ($items as $i => &$item) { $item['id'] = $i; unset($item['url']); }
        unset($item);
        wp_send_json_success(['batch' => $batch, 'items' => $items]);
    }
    private static function download(string $url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = wp_tempnam('pif-image');
        if (!$file) { return new WP_Error('temp', 'امکان ساخت فایل موقت وجود ندارد.'); }
        for ($hop = 0; $hop < 4; $hop++) {
            $url = self::https_url($url);
            if (!$url) { @unlink($file); return new WP_Error('url', 'نشانی تصویر امن نیست.'); }
            // WP safe HTTP blocks private/reserved networks. Each redirect is separately validated.
            $r = wp_safe_remote_get($url, ['timeout' => 25, 'redirection' => 0, 'stream' => true, 'filename' => $file,
                'limit_response_size' => self::MAX_BYTES + 1, 'headers' => ['Accept' => 'image/jpeg,image/png,image/webp']]);
            if (is_wp_error($r)) { @unlink($file); return new WP_Error('download', 'دریافت تصویر ممکن نشد؛ تصویر دیگری انتخاب کنید.'); }
            $code = wp_remote_retrieve_response_code($r);
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = wp_remote_retrieve_header($r, 'location');
                if (!is_string($location) || $location === '') { break; }
                $url = WP_Http::make_absolute_url($location, $url);
                continue;
            }
            clearstatcache(true, $file);
            if ($code !== 200 || !is_file($file) || filesize($file) < 32 || filesize($file) > self::MAX_BYTES) { break; }
            return $file;
        }
        @unlink($file);
        return new WP_Error('download', 'تصویر دریافت نشد یا حجم آن از ۱۰ مگابایت بیشتر است.');
    }
    private static function attach(array $item, int $post_id) {
        $file = self::download($item['url']);
        if (is_wp_error($file)) { return $file; }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        try {
            $mime = wp_get_image_mime($file);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $size = wp_getimagesize($file);
            if (!isset($extensions[$mime]) || !$size || $size[0] < 100 || $size[1] < 100 ||
                $size[0] > 12000 || $size[1] > 12000 || $size[0] * $size[1] > 24000000) {
                return new WP_Error('type', 'فقط تصویر واقعی JPG، PNG یا WebP با ابعاد مناسب (حداکثر ۲۴ مگاپیکسل) پذیرفته می‌شود.');
            }
            $title = sanitize_text_field(get_the_title($post_id));
            $name = sanitize_file_name($title ?: 'product');
            $id = media_handle_sideload(['name' => $name . '-' . substr(hash('sha256', $item['url']), 0, 8) . '.' . $extensions[$mime], 'tmp_name' => $file], $post_id, $title);
            if (is_wp_error($id)) { return new WP_Error('media', 'ثبت در کتابخانه رسانه ناموفق بود؛ فضای هاست و مجوز پوشه uploads را بررسی کنید.'); }
            update_post_meta($id, '_wp_attachment_image_alt', $title);
            update_post_meta($id, '_pif_source_url', $item['source']);
            update_post_meta($id, '_pif_original_url', $item['url']);
            update_post_meta($id, '_pif_license', $item['license']);
            update_post_meta($id, '_pif_artist', $item['artist']);
            $credit = trim($item['artist'] . ' — ' . $item['license']);
            $caption = esc_html($credit) . ' · <a href="' . esc_url($item['source']) . '" rel="nofollow noopener">منبع تصویر</a>';
            if ($item['license_url']) { $caption .= ' · <a href="' . esc_url($item['license_url']) . '" rel="nofollow noopener">مجوز</a>'; }
            wp_update_post(['ID' => $id, 'post_excerpt' => wp_slash($caption), 'post_content' => wp_slash($caption)]);
            return $id;
        } finally { if (is_file($file)) { @unlink($file); } }
    }
    public static function import(): void {
        $post_id = self::guard();
        if (($_POST['rights_confirmed'] ?? '') !== '1') { wp_send_json_error(['message' => 'ابتدا مجوز استفاده از تصویر را تأیید کنید.'], 400); }
        $mode = sanitize_key($_POST['mode'] ?? '');
        if (!in_array($mode, ['featured', 'gallery'], true)) { wp_send_json_error(['message' => 'نوع ثبت تصویر معتبر نیست.'], 400); }
        $batch = sanitize_text_field(wp_unslash($_POST['batch'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9]{32}$/D', $batch)) { wp_send_json_error(['message' => 'نتایج معتبر نیستند؛ دوباره جست‌وجو کنید.'], 400); }
        $data = get_transient('pif_batch_' . $batch);
        $index = filter_var($_POST['image_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_array($data) || $data['user'] !== get_current_user_id() || $data['post'] !== $post_id || $index === false || !isset($data['items'][$index])) {
            wp_send_json_error(['message' => 'نتایج منقضی شده‌اند؛ دوباره جست‌وجو کنید.'], 400);
        }
        // Atomic per-product mutex; avoids duplicate downloads and lost gallery writes from double clicks.
        $lock = 'pif_import_lock_' . $post_id;
        $existing = (int) get_option($lock, 0);
        if ($existing && $existing < time() - 300) { delete_option($lock); }
        if (!add_option($lock, time(), '', false)) { wp_send_json_error(['message' => 'در حال ثبت تصویر دیگری هستیم؛ چند لحظه بعد تلاش کنید.'], 409); }
        $result = null;
        try {
            $item = $data['items'][$index];
            $matches = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $post_id,
                'meta_key' => '_pif_original_url', 'meta_value' => $item['url'], 'fields' => 'ids', 'numberposts' => 1]);
            $id = $matches && is_file((string) get_attached_file($matches[0])) ? (int) $matches[0] : self::attach($item, $post_id);
            if (is_wp_error($id)) { $result = $id; }
            else {
                if ($mode === 'featured') {
                    set_post_thumbnail($post_id, $id);
                    if ((int) get_post_thumbnail_id($post_id) !== $id) { $result = new WP_Error('thumbnail', 'ثبت تصویر شاخص ناموفق بود؛ تصویر در کتابخانه رسانه باقی مانده است.'); }
                } else {
                    $gallery = array_filter(array_map('absint', explode(',', (string) get_post_meta($post_id, '_product_image_gallery', true))));
                    $gallery[] = $id;
                    update_post_meta($post_id, '_product_image_gallery', implode(',', array_unique($gallery)));
                }
                if (!$result) {
                    $result = ['attachment_id' => $id, 'thumbnail' => wp_get_attachment_image_url($id, 'thumbnail'),
                        'featured_html' => _wp_post_thumbnail_html(get_post_thumbnail_id($post_id), $post_id),
                        'gallery_ids' => get_post_meta($post_id, '_product_image_gallery', true),
                        'message' => $mode === 'featured' ? 'تصویر شاخص محصول ثبت شد.' : 'تصویر به گالری محصول اضافه شد.'];
                }
            }
        } finally { delete_option($lock); }
        if (is_wp_error($result)) { wp_send_json_error(['message' => $result->get_error_message()], 400); }
        wp_send_json_success($result);
    }
}
