<?php
/** Vendor build settings. PUBLIC information only. Never insert server secrets here.
 * Generate a configured ZIP with tools/build.py before distributing to customers.
 */
if (!defined('ABSPATH')) { exit; }
return [
    'server_url' => '',
    'public_key' => '',
    'product' => 'product-image-finder',
];
