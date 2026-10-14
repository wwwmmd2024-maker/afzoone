<?php
if (!defined('ABSPATH')) { exit; }
final class PIF_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'setup']);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('add_meta_boxes_product', [__CLASS__, 'metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_pif_license_action', [__CLASS__, 'license_action']);
    }
    public static function url(): string { return admin_url('admin.php?page=pif-settings'); }
    public static function menu(): void {
        add_menu_page('تصویرجو', 'تصویرجو', 'manage_options', 'pif-settings', [__CLASS__, 'page'], 'dashicons-format-image', 58);
    }
    public static function setup(): void {
        register_setting('pif_settings_group', 'pif_settings', ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_settings'], 'default' => ['provider' => 'commons']]);
        if (get_option('pif_activation_redirect') && current_user_can('manage_options') && !wp_doing_ajax() && !is_network_admin()) {
            delete_option('pif_activation_redirect');
            if (empty($_GET['activate-multi'])) { wp_safe_redirect(self::url()); exit; }
        }
    }
    public static function sanitize_settings($input): array {
        if (!is_array($input)) { $input = []; }
        $old = get_option('pif_settings', []);
        $key = trim(sanitize_text_field($input['brave_key'] ?? ''));
        if (strlen($key) > 256) { $key = ''; }
        if (!$key) { $key = $old['brave_key'] ?? ''; }
        if (!empty($input['clear_key'])) { $key = ''; }
        return ['provider' => ($input['provider'] ?? '') === 'brave' ? 'brave' : 'commons', 'brave_key' => $key];
    }
    public static function notices(): void {
        if (!current_user_can('manage_options')) { return; }
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page_pif-settings') { return; }
        if (!class_exists('WooCommerce')) { echo '<div class="notice notice-warning"><p>تصویرجو برای جست‌وجوی تصویر محصولات به ووکامرس فعال نیاز دارد.</p></div>'; }
        elseif (!PIF_License::active()) {
            echo '<div class="notice notice-warning"><p>تصویرجو: برای فعال شدن جست‌وجو و ثبت تصویر، <a href="' . esc_url(self::url()) . '">لایسنس را وارد و تأیید کنید</a>.</p></div>';
        }
    }
    public static function license_action(): void {
        if (!current_user_can('manage_options')) { wp_die('دسترسی ندارید.', '', ['response' => 403]); }
        check_admin_referer('pif_license_action');
        $action = sanitize_key($_POST['license_action'] ?? '');
        if ($action === 'forget') {
            delete_option('pif_license');
            $result = true;
        } elseif ($action === 'refresh') { $result = PIF_License::refresh(true); }
        elseif ($action === 'activate') { $result = PIF_License::activate(sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''))); }
        else { wp_die('درخواست معتبر نیست.', '', ['response' => 400]); }
        $notice = is_wp_error($result) ? ['error' => true, 'message' => $result->get_error_message()] :
            ['error' => !$result, 'message' => $action === 'forget' ? 'لایسنس از این وردپرس حذف شد. آزادسازی دامنه باید توسط فروشنده انجام شود.' : ($result ? 'اعتبارسنجی لایسنس موفق بود.' : 'ابتدا کد لایسنس را وارد کنید.')];
        set_transient('pif_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(self::url()); exit;
    }
    public static function assets(): void {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'toplevel_page_pif-settings' && $screen->post_type !== 'product')) { return; }
        wp_enqueue_style('pif-admin', PIF_URL . 'assets/admin.css', [], PIF_VERSION);
        if ($screen->post_type === 'product' && $screen->base === 'post') {
            wp_enqueue_script('pif-admin', PIF_URL . 'assets/admin.js', ['jquery', 'wp-data'], PIF_VERSION, true);
            wp_localize_script('pif-admin', 'pifAdmin', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('pif_images')]);
        }
    }
    public static function metabox(): void {
        if (class_exists('WooCommerce') && current_user_can('upload_files')) {
            add_meta_box('pif-product-images', 'تصویرجو · جست‌وجوی تصویر محصول', [__CLASS__, 'box'], 'product', 'normal', 'high');
        }
    }
    public static function box(WP_Post $post): void {
        if (!PIF_License::active()) {
            echo '<div class="pif-locked"><span class="dashicons dashicons-lock"></span><h3>ابتدا لایسنس تصویرجو را فعال کنید</h3><p>جست‌وجو و ثبت تصویر فقط پس از تأیید سرور در دسترس است.</p>';
            if (current_user_can('manage_options')) { echo '<a class="button button-primary" href="' . esc_url(self::url()) . '">ورود به صفحه لایسنس</a>'; }
            else { echo '<p>از مدیر سایت بخواهید لایسنس را فعال کند.</p>'; }
            echo '</div>'; return;
        }
        $settings = get_option('pif_settings', []);
        $provider = ($settings['provider'] ?? 'commons') === 'brave' ? 'Brave · جست‌وجوی وب' : 'Wikimedia Commons';
        ?>
        <div class="pif-search-app" data-post-id="<?php echo esc_attr($post->ID); ?>" dir="rtl">
            <div class="pif-box-heading"><div><strong>نام محصول را بنویسید؛ تصویرش را انتخاب کنید.</strong><p>مدل دقیق و نام انگلیسی محصول معمولاً نتیجه بهتری دارند.</p></div><span class="pif-pill"><?php echo esc_html($provider); ?></span></div>
            <div class="pif-search-row"><label class="screen-reader-text" for="pif-query">نام محصول یا مدل</label><input id="pif-query" type="search" maxlength="150" placeholder="مثلاً Canon EOS R50" value="<?php echo esc_attr($post->post_status === 'auto-draft' ? '' : $post->post_title); ?>"><button type="button" class="button button-primary" id="pif-search">جست‌وجوی تصویر</button><button type="button" class="button" id="pif-use-title">از نام محصول</button></div>
            <p class="pif-fine">جست‌وجو، عبارت محصول را به سرویس انتخاب‌شده می‌فرستد. نمایش پیش‌نمایش‌ها نیز به میزبان تصویر متصل می‌شود.</p>
            <label class="pif-consent"><input type="checkbox" id="pif-rights"> مجوز استفاده از تصویر انتخابی را بررسی کرده‌ام و مسئول رعایت حقوق صاحب اثر و درج اعتبار لازم هستم.</label>
            <p class="pif-fine">جست‌وجو به معنی اجازه استفاده تجاری نیست. اطلاعات منبع در رسانه ذخیره می‌شود؛ نمایش اعتبار در قالب فروشگاه را خودتان بررسی کنید.</p>
            <div id="pif-status" class="pif-status" role="status" aria-live="polite">برای شروع، نام محصول را جست‌وجو کنید.</div>
            <div id="pif-results" class="pif-results"></div>
        </div>
        <?php
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) { return; }
        $active = PIF_License::active();
        $license = get_option('pif_license', []);
        $settings = get_option('pif_settings', []);
        $notice = get_transient('pif_notice_' . get_current_user_id());
        delete_transient('pif_notice_' . get_current_user_id());
        $config = PIF_License::config();
        ?>
        <div class="wrap pif-wrap" dir="rtl">
            <header class="pif-hero"><div><span class="pif-eyebrow">ابزار تصویر محصولات ووکامرس</span><h1>تصویر مناسب، در همان پیشخوان.</h1><p>جست‌وجو، انتخاب و ثبت تصویر محصول؛ بدون رفت‌وآمد بین تب‌ها.</p></div><span class="pif-brand dashicons dashicons-format-image"></span></header>
            <?php settings_errors(); ?>
            <?php if ($notice): ?><div class="notice <?php echo $notice['error'] ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div><?php endif; ?>
            <?php if (!class_exists('WooCommerce')): ?><div class="notice notice-warning"><p>برای استفاده از بخش محصول، ووکامرس را نصب و فعال کنید.</p></div><?php endif; ?>
            <div class="pif-layout"><section class="pif-panel">
                <div class="pif-section-title"><h2>۱. فعال‌سازی لایسنس</h2><span class="pif-badge <?php echo $active ? 'is-active' : ''; ?>"><?php echo $active ? 'فعال' : 'غیرفعال'; ?></span></div>
                <p>کدی را که از فروشنده دریافت کرده‌اید وارد کنید. هر لایسنس به یک دامنه متصل می‌شود.</p>
                <?php if (!PIF_License::configured()): ?><div class="pif-warning"><?php echo esc_html(PIF_License::message('not_configured')); ?></div><?php endif; ?>
                <dl class="pif-details"><dt>دامنه این سایت</dt><dd dir="ltr"><?php echo esc_html(PIF_License::domain()); ?></dd><dt>سرور فروشنده</dt><dd dir="ltr"><?php echo esc_html(wp_parse_url($config['server_url'], PHP_URL_HOST) ?: 'پیکربندی نشده'); ?></dd>
                    <?php if (!empty($license['key'])): ?><dt>لایسنس ذخیره‌شده</dt><dd dir="ltr"><?php echo esc_html(substr($license['key'], 0, 12) . '-…-' . substr($license['key'], -8)); ?></dd><?php endif; ?>
                </dl>
                <?php if (!empty($license['error'])): ?><p class="pif-warning"><?php echo esc_html(PIF_License::message($license['error'])); ?></p><?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pif_license_action"><input type="hidden" name="license_action" value="activate"><?php wp_nonce_field('pif_license_action'); ?>
                    <label for="pif-license-key">کد لایسنس</label><input id="pif-license-key" name="license_key" class="pif-key" type="text" dir="ltr" maxlength="64" placeholder="PIF-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" required autocomplete="off" spellcheck="false">
                    <p class="pif-fine">با فعال‌سازی، کد لایسنس و دامنه سایت برای سرور فروشنده ارسال می‌شوند. اعتبار توکن هر ۶ ساعت بررسی می‌شود و حداکثر ۲۴ ساعت است.</p>
                    <button class="button button-primary" <?php disabled(!PIF_License::configured()); ?>>تأیید و فعال‌سازی</button>
                </form>
                <?php if (!empty($license['key'])): ?><div class="pif-license-tools">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="pif_license_action"><?php wp_nonce_field('pif_license_action'); ?><button name="license_action" value="refresh" class="button">بررسی مجدد</button></form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="pif_license_action"><?php wp_nonce_field('pif_license_action'); ?><button name="license_action" value="forget" class="button">حذف لایسنس از این سایت</button></form>
                </div><?php endif; ?>
            </section><section class="pif-panel">
                <h2>۲. منبع جست‌وجو</h2><p>برای شروع به کلید API نیازی ندارید. برای پوشش گسترده‌تر وب، Brave را انتخاب کنید.</p>
                <form method="post" action="options.php"><?php settings_fields('pif_settings_group'); ?>
                    <label for="pif-provider">سرویس تصویر</label><select id="pif-provider" name="pif_settings[provider]"><option value="commons" <?php selected($settings['provider'] ?? 'commons', 'commons'); ?>>Wikimedia Commons — بدون کلید API</option><option value="brave" <?php selected($settings['provider'] ?? '', 'brave'); ?>>Brave Image Search — نیازمند API</option></select>
                    <p class="pif-fine">Commons برای همه محصولات عکس ندارد. Brave ممکن است هزینه و محدودیت سهمیه داشته باشد و حق استفاده تجاری از تصاویر را تضمین نمی‌کند.</p>
                    <label for="pif-brave-key">کلید Brave Search API</label><input id="pif-brave-key" name="pif_settings[brave_key]" type="password" autocomplete="new-password" dir="ltr" value="" placeholder="<?php echo empty($settings['brave_key']) ? 'کلید API' : 'کلید ذخیره شده؛ خالی بگذارید تا حفظ شود'; ?>">
                    <label class="pif-check"><input type="checkbox" name="pif_settings[clear_key]" value="1"> حذف کلید ذخیره‌شده</label>
                    <?php submit_button('ذخیره تنظیمات'); ?>
                </form>
            </section></div>
            <section class="pif-panel pif-guide"><h2>۳. اولین تصویر محصول را ثبت کنید</h2><ol><li>یک محصول جدید بسازید یا محصول موجود را ویرایش کنید.</li><li>در کادر «تصویرجو» نام و مدل محصول را جست‌وجو کنید.</li><li>منبع و مجوز تصویر را بررسی کنید، سپس «تصویر شاخص» یا «افزودن به گالری» را بزنید.</li></ol><p>تغییر تصویر بلافاصله ثبت می‌شود. برای باقی تغییرات محصول، دکمه به‌روزرسانی ووکامرس را بزنید. ویرایشگر کلاسیک محصول پشتیبانی می‌شود.</p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">رفتن به محصولات</a></section>
            <p class="pif-footer">تصویرجو ۱.۰ · اطلاعات منبع در کتابخانه رسانه نگه‌داری می‌شود؛ تصاویر موجود با غیرفعال شدن لایسنس حذف نمی‌شوند.</p>
        </div>
        <?php
    }
}
