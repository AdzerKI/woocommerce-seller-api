<?php
/**
 * Plugin Name: OPOTOPO Seller API
 * Plugin URI: https://opotopo.com/knowledge/developers/klient-dlya-woocommerce
 * Description: Publishes the WooCommerce catalog on the OPOTOPO marketplace through the Seller API.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 9.6
 * Author: OPOTOPO
 * Author URI: https://opotopo.com
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: opotopo-seller-api
 * Domain Path: /languages
 */

use Opotopo\SellerApi\WooCommerce\Plugin;

defined('ABSPATH') || exit;

require_once __DIR__.'/vendor-prefixed/vendor/autoload.php';
require_once __DIR__.'/vendor/autoload.php';

(new Plugin(__FILE__))->register();
