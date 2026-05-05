<?php
/**
 * Plugin Name:       EmballageCom Store
 * Description:       EmballageCom Store Customizations
 * Version:           1.0.0
 * Author:            EmballageCom
 * Text Domain:       emballagecom-store
 * Domain Path:       /languages
 * Requires Plugins: woocommerce
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

define('EMBALLAGECOM_STORE_FILE', __FILE__);
require_once __DIR__ . '/includes/class-emballagecom-store-plugin.php';

register_activation_hook(
	EMBALLAGECOM_STORE_FILE,
	static function (): void {
		if (! EmballageCom_Store_Plugin::is_woocommerce_active()) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			deactivate_plugins(plugin_basename(EMBALLAGECOM_STORE_FILE));
			wp_die(
				esc_html__('يجب تثبيت WooCommerce وتفعيله لتعمل إضافة متجر EmballageCom.', 'emballagecom-store'),
				esc_html__('تبعية الإضافة', 'emballagecom-store'),
				['back_link' => true]
			);
		}
	}
);

add_action('plugins_loaded', [EmballageCom_Store_Plugin::class, 'bootstrap']);
