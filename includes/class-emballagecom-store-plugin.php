<?php
/**
 * Main plugin class.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/traits/trait-emballagecom-store-shop.php';
require_once __DIR__ . '/traits/trait-emballagecom-store-checkout.php';
require_once __DIR__ . '/traits/trait-emballagecom-store-admin.php';
require_once __DIR__ . '/traits/trait-emballagecom-store-quantity.php';
require_once __DIR__ . '/traits/trait-emballagecom-store-whatsapp.php';

if (! class_exists('EmballageCom_Store_Plugin')) {
	final class EmballageCom_Store_Plugin {
		use EmballageCom_Store_Shop_Trait;
		use EmballageCom_Store_Checkout_Trait;
		use EmballageCom_Store_Admin_Trait;
		use EmballageCom_Store_Quantity_Trait;
		use EmballageCom_Store_Whatsapp_Trait;

		private const META_KEY = '_emballagecom_min_quantity';
		private const OZON_CITIES_ENDPOINT = 'https://api.ozonexpress.ma/cities';
		private const OZON_CITIES_TRANSIENT = 'emballagecom_ozon_cities';
		private const OZON_CITIES_TTL = 3600;
		private const DELIVERY_FEE_LABEL = 'رسوم التوصيل';

		private static ?self $instance = null;

		public static function instance(): self {
			if (null === self::$instance) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Uses the active-plugin list (not the WooCommerce class) so activation works even if this
		 * plugin runs before WooCommerce during the same request.
		 */
		public static function is_woocommerce_active(): bool {
			if (! function_exists('is_plugin_active')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$slug = 'woocommerce/woocommerce.php';

			if (is_plugin_active($slug)) {
				return true;
			}

			return is_multisite() && is_plugin_active_for_network($slug);
		}

		/**
		 * Register hooks after all plugins are loaded (load order no longer matters).
		 */
		public static function bootstrap(): void {
			if (! class_exists('WooCommerce')) {
				add_action('admin_notices', [self::instance(), 'woocommerce_missing_notice']);
				return;
			}

			$p = self::instance();

			add_action('add_meta_boxes', [$p, 'register_product_metabox']);
			add_action('save_post_product', [$p, 'save_product_minimum_quantity'], 10, 2);

			add_filter('woocommerce_quantity_input_args', [$p, 'enforce_quantity_input_minimum'], 10, 2);
			add_filter('woocommerce_quantity_input_min', [$p, 'filter_quantity_input_min'], 10, 2);
			add_filter('woocommerce_add_to_cart_validation', [$p, 'validate_add_to_cart_minimum'], 10, 6);
			add_filter('woocommerce_update_cart_validation', [$p, 'validate_cart_update_minimum'], 10, 4);
			add_action('woocommerce_check_cart_items', [$p, 'validate_cart_before_checkout']);

			add_action('template_redirect', [$p, 'redirect_shop_to_home'], 0);
			add_filter('woocommerce_get_shop_page_permalink', [$p, 'filter_shop_page_permalink_home'], 999);
			add_filter('post_type_archive_link', [$p, 'filter_product_archive_link_home'], 999, 2);
			add_filter('wp_nav_menu_objects', [$p, 'filter_nav_menu_shop_links'], 999, 2);
			add_action('wp_enqueue_scripts', [$p, 'enqueue_thegem_hide_cart_checkout_title'], 20);

			add_filter('woocommerce_checkout_fields', [$p, 'customize_checkout_city_field']);
			add_filter('woocommerce_checkout_posted_data', [$p, 'force_checkout_country_to_morocco']);
			add_action('wp_enqueue_scripts', [$p, 'enqueue_checkout_city_select2'], 30);
			add_action('woocommerce_cart_calculate_fees', [$p, 'add_delivery_fee_from_city'], 20);
			add_action('woocommerce_order_status_changed', [$p, 'maybe_send_whatsapp_on_status_changed'], 20, 4);
			add_action('woocommerce_thankyou', [$p, 'maybe_send_whatsapp_on_thankyou'], 20, 1);
		}

		public function woocommerce_missing_notice(): void {
			if (! current_user_can('activate_plugins')) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__('يجب تثبيت WooCommerce وتفعيله لتعمل إضافة متجر EmballageCom.', 'emballagecom-store')
			);
		}
	}
}
