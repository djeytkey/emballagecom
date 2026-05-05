<?php
/**
 * Plugin Name:       متجر EmballageCom
 * Description:       إعدادات مخصّصة لمتجر EmballageCom (الحد الأدنى للكمية وغيرها).
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

if (! class_exists('EmballageCom_Store_Plugin')) {

	final class EmballageCom_Store_Plugin {

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

			// Shop archive / shop page URL → home (redirect + rewired links).
			add_action('template_redirect', [$p, 'redirect_shop_to_home'], 0);
			add_filter('woocommerce_get_shop_page_permalink', [$p, 'filter_shop_page_permalink_home'], 999);
			add_filter('post_type_archive_link', [$p, 'filter_product_archive_link_home'], 999, 2);
			add_filter('wp_nav_menu_objects', [$p, 'filter_nav_menu_shop_links'], 999, 2);

			// TheGem: hide cart/checkout centered page title strip.
			add_action('wp_enqueue_scripts', [$p, 'enqueue_thegem_hide_cart_checkout_title'], 20);
			add_filter('woocommerce_checkout_fields', [$p, 'customize_checkout_city_field']);
			add_filter('woocommerce_checkout_posted_data', [$p, 'force_checkout_country_to_morocco']);
			add_action('wp_enqueue_scripts', [$p, 'enqueue_checkout_city_select2'], 30);
			add_action('woocommerce_cart_calculate_fees', [$p, 'add_delivery_fee_from_city'], 20);
		}

		public function redirect_shop_to_home(): void {
			if (is_feed() || is_admin()) {
				return;
			}

			if (! function_exists('is_shop') || ! function_exists('wc_get_page_id')) {
				return;
			}

			if (! is_shop()) {
				return;
			}

			$shop_id    = (int) wc_get_page_id('shop');
			$front_id   = (int) get_option('page_on_front');

			if ($shop_id > 0 && $front_id > 0 && $shop_id === $front_id) {
				return;
			}

			wp_safe_redirect(home_url('/'), 301);
			exit;
		}

		public function filter_shop_page_permalink_home($permalink): string {
			return home_url('/');
		}

		public function filter_product_archive_link_home(string $link, string $post_type): string {
			if ('product' === $post_type) {
				return home_url('/');
			}

			return $link;
		}

		/**
		 * Rewrite menu entries that point at the WooCommerce shop page so href is the homepage.
		 *
		 * @param array<int, \WP_Post> $items Sorted menu objects.
		 * @param object                 $args  Menu wp_nav_menu() args object.
		 * @return array<int, \WP_Post>
		 */
		public function filter_nav_menu_shop_links(array $items, $args): array {
			$shop_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;

			if ($shop_id <= 0) {
				return $items;
			}

			$home = home_url('/');

			foreach ($items as $item) {
				if (empty($item->url)) {
					continue;
				}

				if (isset($item->object, $item->object_id) && 'page' === $item->object && (int) $item->object_id === $shop_id) {
					$item->url = $home;

					continue;
				}

				if (function_exists('url_to_postid') && (int) url_to_postid((string) $item->url) === $shop_id) {
					$item->url = $home;
				}
			}

			return $items;
		}

		public function enqueue_thegem_hide_cart_checkout_title(): void {
			if (! function_exists('is_cart') || ! function_exists('is_checkout')) {
				return;
			}

			if (! is_cart() && ! is_checkout()) {
				return;
			}

			wp_register_style('emballagecom-store-thegem', false, [], '');
			wp_enqueue_style('emballagecom-store-thegem');
			wp_add_inline_style(
				'emballagecom-store-thegem',
				'.page-title-block.page-title-alignment-center.page-title-style-1.woocommerce-cart-checkout{display:none!important;}'
			);
		}

		/**
		 * @return array<string,array{name:string,delivered_price:float}>
		 */
		public function get_ozon_cities(): array {
			$cached = get_transient(self::OZON_CITIES_TRANSIENT);

			if (is_array($cached)) {
				return $cached;
			}

			$response = wp_remote_get(
				self::OZON_CITIES_ENDPOINT,
				[
					'timeout' => 15,
					'headers' => [
						'Accept' => 'application/json',
					],
				]
			);

			if (is_wp_error($response)) {
				return [];
			}

			$body = wp_remote_retrieve_body($response);

			if (! is_string($body) || $body === '') {
				return [];
			}

			$data = json_decode($body, true);

			if (! is_array($data) || ! isset($data['CITIES']) || ! is_array($data['CITIES'])) {
				return [];
			}

			$cities = [];

			foreach ($data['CITIES'] as $row) {
				if (! is_array($row) || ! isset($row['NAME']) || ! isset($row['DELIVERED-PRICE'])) {
					continue;
				}

				$name = trim((string) $row['NAME']);

				if ($name === '') {
					continue;
				}

				$normalized_key = $this->normalize_city_key($name);

				$cities[$normalized_key] = [
					'name'            => $name,
					'delivered_price' => (float) $row['DELIVERED-PRICE'],
				];
			}

			if (! empty($cities)) {
				set_transient(self::OZON_CITIES_TRANSIENT, $cities, self::OZON_CITIES_TTL);
			}

			return $cities;
		}

		private function normalize_city_key(string $city): string {
			$city = trim($city);

			if ($city === '') {
				return '';
			}

			if (function_exists('mb_strtolower')) {
				return mb_strtolower($city, 'UTF-8');
			}

			return strtolower($city);
		}

		public function customize_checkout_city_field(array $fields): array {
			// Morocco-only checkout: remove unused address fields.
			unset($fields['billing']['billing_country'], $fields['billing']['billing_address_2'], $fields['billing']['billing_state'], $fields['billing']['billing_postcode'], $fields['billing']['billing_email']);
			unset($fields['shipping']['shipping_country'], $fields['shipping']['shipping_address_2'], $fields['shipping']['shipping_state'], $fields['shipping']['shipping_postcode']);

			if (! isset($fields['billing']['billing_city']) || ! is_array($fields['billing']['billing_city'])) {
				return $fields;
			}

			$cities = $this->get_ozon_cities();

			if (empty($cities)) {
				return $fields;
			}

			$options = ['' => __('Choisissez votre ville', 'emballagecom-store')];

			foreach ($cities as $city) {
				$options[$city['name']] = $city['name'];
			}

			$fields['billing']['billing_city']['type']        = 'select';
			$fields['billing']['billing_city']['options']     = $options;
			$fields['billing']['billing_city']['required']    = true;
			$fields['billing']['billing_city']['input_class'] = ['wc-enhanced-select'];
			$fields['billing']['billing_city']['priority']    = 70;

			return $fields;
		}

		public function force_checkout_country_to_morocco(array $data): array {
			$data['billing_country']  = 'MA';
			$data['shipping_country'] = 'MA';

			return $data;
		}

		public function enqueue_checkout_city_select2(): void {
			if (! function_exists('is_checkout') || ! is_checkout()) {
				return;
			}

			wp_enqueue_script('selectWoo');
			wp_enqueue_style('select2');
			$script = <<<'JS'
jQuery(function($){
	function initCity(){
		var $city = $('#billing_city');
		if (!$city.length) {
			return;
		}
		if ($city.data('select2')) {
			$city.select2('destroy');
		}
		$city.selectWoo({ width: '100%' });
	}

	initCity();
	$(document.body).on('updated_checkout', initCity);
	$(document.body).on('change', '#billing_city', function(){
		$(document.body).trigger('update_checkout');
	});
});
JS;

			wp_add_inline_script(
				'selectWoo',
				$script
			);
		}

		private function get_selected_checkout_city(): string {
			$city = '';
			$post_data = WC()->session ? (string) WC()->session->get('post_data') : '';

			if ($post_data !== '') {
				parse_str($post_data, $parsed);

				if (is_array($parsed) && isset($parsed['billing_city'])) {
					$city = (string) $parsed['billing_city'];
				}
			}

			if ($city === '' && isset($_POST['billing_city'])) {
				$city = (string) wp_unslash($_POST['billing_city']);
			}

			if ($city === '' && WC()->customer instanceof WC_Customer) {
				$city = (string) WC()->customer->get_billing_city();
			}

			return trim($city);
		}

		public function add_delivery_fee_from_city(WC_Cart $cart): void {
			if (is_admin() && ! wp_doing_ajax()) {
				return;
			}

			if (! function_exists('is_checkout') || (! is_checkout() && ! wp_doing_ajax())) {
				return;
			}

			$city = $this->get_selected_checkout_city();

			if ($city === '') {
				return;
			}

			$cities = $this->get_ozon_cities();

			if (empty($cities)) {
				return;
			}

			$key = $this->normalize_city_key($city);

			if (! isset($cities[$key])) {
				return;
			}

			$fee = (float) $cities[$key]['delivered_price'];

			if ($fee <= 0) {
				return;
			}

			$cart->add_fee(self::DELIVERY_FEE_LABEL, $fee, false);
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

		public function register_product_metabox(): void {
			add_meta_box(
				'emballagecom_min_quantity',
				__('EmballageCom — الحد الأدنى للكمية', 'emballagecom-store'),
				[$this, 'render_minimum_quantity_metabox'],
				'product',
				'side',
				'default'
			);
		}

		public function render_minimum_quantity_metabox(WP_Post $post): void {
			wp_nonce_field('emballagecom_save_min_qty', 'emballagecom_min_qty_nonce');

			$raw     = get_post_meta($post->ID, self::META_KEY, true);
			$min_qty = $raw !== '' && $raw !== false ? absint($raw) : '';

			wp_enqueue_script('jquery');
			?>
			<p>
				<label for="emballagecom_min_quantity"><strong><?php esc_html_e('الحد الأدنى لكمية الطلب', 'emballagecom-store'); ?></strong></label>
			</p>
			<p>
				<input
					type="number"
					id="emballagecom_min_quantity"
					name="emballagecom_min_quantity"
					class="small-text"
					min="1"
					step="1"
					value="<?php echo esc_attr((string) $min_qty); ?>"
					style="width:100%;max-width:6em;"
				/>
			</p>
			<p class="description">
				<?php esc_html_e('اتركه فارغًا لاستخدام إعداد WooCommerce الافتراضي (غالبًا 1). ينطبق على المنتجات ذات المتغيّرات لجميع الأصناف. لا يمكن للعملاء طلب كمية أقل.', 'emballagecom-store'); ?>
			</p>
			<?php
		}

		public function save_product_minimum_quantity(int $post_id, WP_Post $post): void {
			if ('product' !== $post->post_type) {
				return;
			}

			if (
				! isset($_POST['emballagecom_min_qty_nonce'])
				|| ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['emballagecom_min_qty_nonce'])), 'emballagecom_save_min_qty')
			) {
				return;
			}

			if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
				return;
			}

			if (! current_user_can('edit_post', $post_id)) {
				return;
			}

			if (! isset($_POST['emballagecom_min_quantity'])) {
				delete_post_meta($post_id, self::META_KEY);
				return;
			}

			$value = sanitize_text_field(wp_unslash((string) $_POST['emballagecom_min_quantity']));

			if ($value === '') {
				delete_post_meta($post_id, self::META_KEY);
				return;
			}

			$n = absint($value);

			if ($n < 1) {
				delete_post_meta($post_id, self::META_KEY);
				return;
			}

			update_post_meta($post_id, self::META_KEY, $n);
		}

		public function get_minimum_for_product(?WC_Product $product): ?int {
			if (! $product instanceof WC_Product) {
				return null;
			}

			$raw = get_post_meta($product->get_id(), self::META_KEY, true);

			if (($raw === '' || $raw === false) && $product->is_type('variation')) {
				$parent_id = $product->get_parent_id();

				if ($parent_id > 0) {
					$raw = get_post_meta($parent_id, self::META_KEY, true);
				}
			}

			if ($raw === '' || $raw === false) {
				return null;
			}

			$n = absint($raw);

			return $n > 0 ? $n : null;
		}

		public function enforce_quantity_input_minimum(array $args, $product): array {
			if (! $product instanceof WC_Product) {
				return $args;
			}

			$min = $this->get_minimum_for_product($product);

			if (null !== $min) {
				if (! isset($args['min_value']) || (int) $args['min_value'] < $min) {
					$args['min_value'] = $min;
				}
				if (isset($args['input_value'])) {
					$current = (int) wc_stock_amount(wp_unslash($args['input_value']));
					if ($current < $min) {
						$args['input_value'] = $min;
					}
				}
			}

			return $args;
		}

		public function filter_quantity_input_min(int $min, $product): int {
			if (! $product instanceof WC_Product) {
				return $min;
			}

			$m = $this->get_minimum_for_product($product);

			return null !== $m ? max($min, $m) : $min;
		}

		/**
		 * @param mixed $passed
		 * @param mixed $product_id
		 * @param mixed $quantity
		 * @param mixed $variation_id
		 */
		public function validate_add_to_cart_minimum($passed, $product_id, $quantity, $variation_id = null, $_variation = null, $_cart_item_data = null): bool {
			if (! $passed) {
				return false;
			}

			$pid = absint((string) $variation_id ?: 0) ?: absint((string) $product_id);

			if ($pid < 1) {
				return true;
			}

			$product = wc_get_product($pid);

			$need = $this->get_minimum_for_product($product);

			if (null === $need) {
				return true;
			}

			$qty = (int) wc_stock_amount($quantity);

			if ($qty >= $need) {
				return true;
			}

			$parent = wc_get_product(absint((string) $product_id));
			$name   = $product ? $product->get_name() : ($parent ? $parent->get_name() : '');

			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: minimum quantity */
					__('الحد الأدنى للكمية لـ «%1$s» هو %2$d.', 'emballagecom-store'),
					wp_strip_all_tags($name),
					$need
				),
				'error'
			);

			return false;
		}

		/**
		 * @param mixed $passed
		 * @param mixed $quantity
		 */
		public function validate_cart_update_minimum($passed, string $cart_item_key, array $values, $quantity): bool {
			if (! $passed) {
				return false;
			}

			$qty = (int) wc_stock_amount($quantity);

			if ($qty < 1) {
				return true;
			}

			$product = isset($values['data']) ? $values['data'] : null;

			if (! $product instanceof WC_Product) {
				return true;
			}

			$need = $this->get_minimum_for_product($product);

			if (null === $need || $qty >= $need) {
				return true;
			}

			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: minimum quantity */
					__('الحد الأدنى للكمية لـ «%1$s» هو %2$d.', 'emballagecom-store'),
					wp_strip_all_tags($product->get_name()),
					$need
				),
				'error'
			);

			return false;
		}

		public function validate_cart_before_checkout(): void {
			if (! function_exists('WC') || ! WC()->cart) {
				return;
			}

			foreach (WC()->cart->get_cart() as $cart_item) {
				$product = isset($cart_item['data']) ? $cart_item['data'] : null;

				if (! $product instanceof WC_Product) {
					continue;
				}

				$need = $this->get_minimum_for_product($product);

				if (null === $need) {
					continue;
				}

				$qty = isset($cart_item['quantity']) ? (int) $cart_item['quantity'] : 0;

				if ($qty >= $need) {
					continue;
				}

				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: minimum quantity */
						__('يُرجى تعديل السلة: الحد الأدنى للكمية لمنتج «%1$s» هو %2$d.', 'emballagecom-store'),
						wp_strip_all_tags($product->get_name()),
						$need
					),
					'error'
				);
			}
		}
	}
}

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
