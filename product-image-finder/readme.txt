=== تصویرجو — تصویر محصولات ووکامرس ===
Contributors: afzoone
Tags: woocommerce, product, image, search
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

جست‌وجوی تصویر، انتخاب تصویر شاخص و افزودن به گالری محصول در ویرایشگر کلاسیک ووکامرس.

== Description ==
این بسته برای توزیع مستقیم فروشنده آماده شده و برای استفاده به ووکامرس و لایسنس سرور فروشنده نیاز دارد.
فروشنده باید پیش از توزیع، vendor-config.php را با HTTPS endpoint و کلید عمومی RSA سرور خود پیکربندی کند.
نسخه template نصب می‌شود ولی تا پیکربندی فروشنده و تأیید لایسنس، جست‌وجو و ثبت تصویر قفل است.
وردپرس تک‌سایته، PHP 8.0+ با OpenSSL، کتابخانه GD یا Imagick و ارتباط خروجی HTTPS لازم است.
حداقل ووکامرس هدف: 8.2. ویرایشگر جدید/آزمایشی محصولات و نصب شبکه‌ای پشتیبانی نمی‌شوند.

== Installation ==
1. فروشنده ZIP پیکربندی‌شده را تولید می‌کند.
2. از افزونه‌ها > افزودن > بارگذاری افزونه، ZIP را نصب و فعال کنید.
3. در منوی تصویرجو، کد لایسنس خود را وارد کنید.
4. در ویرایش محصول، از کادر تصویرجو استفاده کنید.
5. پیش از ثبت تصویر، منبع، مدل دقیق و حقوق استفاده را بررسی کنید.

== External services / Privacy ==
License server: activation and periodic verification send the license key, canonical site hostname and product identifier to the vendor's configured HTTPS endpoint. The server also sees the connecting IP. No customer orders, product content or WordPress passwords are sent to this server. A signed entitlement is cached for at most 24 hours, and refreshed approximately every six hours via WP-Cron. Vendor contact and privacy policy must be supplied by the actual distributor.

Wikimedia Commons: default search service. Queries are sent to https://commons.wikimedia.org/w/api.php only when the user starts a search. Wikimedia receives the query, server IP, and a User-Agent containing the site URL. Preview loading exposes the browser IP to the image host. Import downloads the selected image from its host. Results may not contain the exact product and each license must be checked.
Terms: https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use
Privacy: https://foundation.wikimedia.org/wiki/Policy:Privacy_policy
API: https://www.mediawiki.org/wiki/API:Main_page

Brave Search: optional image provider, requiring the site's own API subscription/key. Sends the search query, API key, server IP and User-Agent/site URL to https://api.search.brave.com/res/v1/images/search. Preview images and selected originals are loaded from third-party hosts. Search results do not grant reuse rights. Keys are stored in WordPress options and are not sent to the vendor license server.
Terms: https://brave.com/search/api/terms-of-service/
Privacy: https://brave.com/search/api/privacy-policy/
Docs: https://api-dashboard.search.brave.com/app/documentation/image-search

== Frequently Asked Questions ==
= آیا کاملاً ضدکرک است؟ =
خیر. کد PHP روی هاست مشتری قابل تغییر است. پاسخ امضاشده و اتصال به دامنه، جعل ساده پاسخ را محدود می‌کند ولی اجرای نسخه دستکاری‌شده را غیرممکن نمی‌کند.

= آیا به ژاکت و راست‌چین متصل است؟ =
اتصال رسمی آماده ادعا نمی‌شود. سرور یک API و وب‌هوک سفارشی امن دارد؛ اتصال واقعی و تحویل کد به مشتری نیازمند امکانات و تأیید فروشگاه است. صدور دستی ممکن است.

= آیا اعتبار صاحب عکس در فروشگاه دیده می‌شود؟ =
منبع و نام صاحب اثر در کپشن، توضیح و متادیتای رسانه ذخیره می‌شوند. بسیاری از قالب‌های ووکامرس کپشن را نشان نمی‌دهند؛ مسئولیت نمایش اعتبار و رعایت شرایط مجوز با مدیر سایت است.

= پس از حذف افزونه چه می‌شود؟ =
تنظیمات و اطلاعات لایسنس محلی پاک می‌شوند. تصاویر و اطلاعات منبع باقی می‌مانند. دامنه روی سرور فروشنده خودکار آزاد نمی‌شود.

== Changelog ==
= 1.0.0 =
Initial version: Commons/Brave search, featured/gallery import, Persian admin UI, signed domain-bound licensing, standalone license service and vendor packaging tools.
