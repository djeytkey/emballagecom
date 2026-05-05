<?php
/**
 * Cart and quantity constraints.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Quantity_Trait {
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
