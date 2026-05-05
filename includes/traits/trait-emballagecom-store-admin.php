<?php
/**
 * Admin/product metabox customizations.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Admin_Trait {
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
}
