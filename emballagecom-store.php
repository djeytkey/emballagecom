<?php
/**
 * Plugin Name:       EmballageCom Store
 * Description:       Store-specific tweaks for EmballageCom (minimum order quantities and more).
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
		}

		public function woocommerce_missing_notice(): void {
			if (! current_user_can('activate_plugins')) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__('EmballageCom Store requires WooCommerce to be installed and active.', 'emballagecom-store')
			);
		}

		public function register_product_metabox(): void {
			add_meta_box(
				'emballagecom_min_quantity',
				__('EmballageCom – Minimum quantity', 'emballagecom-store'),
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
				<label for="emballagecom_min_quantity"><strong><?php esc_html_e('Minimum order quantity', 'emballagecom-store'); ?></strong></label>
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
				<?php esc_html_e('Leave empty to use WooCommerce default (usually 1). Applies to variable products for all variants. Customers cannot buy fewer.', 'emballagecom-store'); ?>
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
					__('The minimum quantity for %1$s is %2$d.', 'emballagecom-store'),
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
					__('The minimum quantity for %1$s is %2$d.', 'emballagecom-store'),
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
						__('Adjust your cart: the minimum quantity for %1$s is %2$d.', 'emballagecom-store'),
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
				esc_html__('EmballageCom Store requires WooCommerce to be installed and active.', 'emballagecom-store'),
				esc_html__('Plugin dependency', 'emballagecom-store'),
				['back_link' => true]
			);
		}
	}
);

add_action('plugins_loaded', [EmballageCom_Store_Plugin::class, 'bootstrap']);
