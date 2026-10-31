<?php

use Opotopo\SellerApi\WooCommerce\Plugin;
use Opotopo\SellerApi\WooCommerce\Schema;
use Opotopo\SellerApi\WooCommerce\Settings;

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__.'/vendor-prefixed/vendor/autoload.php';
require_once __DIR__.'/vendor/autoload.php';

Schema::drop();
foreach (Settings::OPTIONS as $option) {
    delete_option($option);
}
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], Plugin::GROUP);
}
