<?php
/**
 * Checkout customizations.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Checkout_Trait {
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
		unset($fields['billing']['billing_country'], $fields['billing']['billing_address_2'], $fields['billing']['billing_state'], $fields['billing']['billing_postcode'], $fields['billing']['billing_email'], $fields['billing']['billing_company']);
		unset($fields['shipping']['shipping_country'], $fields['shipping']['shipping_address_2'], $fields['shipping']['shipping_state'], $fields['shipping']['shipping_postcode'], $fields['shipping']['shipping_company']);
		unset($fields['billing']['billing_last_name']);

		if (isset($fields['billing']['billing_first_name']) && is_array($fields['billing']['billing_first_name'])) {
			$fields['billing']['billing_first_name']['label']    = __('الاسم الكامل', 'emballagecom-store');
			$fields['billing']['billing_first_name']['class']    = ['form-row-last'];
			$fields['billing']['billing_first_name']['priority'] = 60;
		}

		if (isset($fields['billing']['billing_phone']) && is_array($fields['billing']['billing_phone'])) {
			$fields['billing']['billing_phone']['class']    = ['form-row-first'];
			$fields['billing']['billing_phone']['priority'] = 61;
		}

		if (isset($fields['billing']['billing_address_1']) && is_array($fields['billing']['billing_address_1'])) {
			$fields['billing']['billing_address_1']['class']    = ['form-row-last'];
			$fields['billing']['billing_address_1']['priority'] = 71;
		}

		if (! isset($fields['billing']['billing_city']) || ! is_array($fields['billing']['billing_city'])) {
			return $fields;
		}

		$cities = $this->get_ozon_cities();
		if (! empty($cities)) {
			$options = ['' => __('اختر مدينتك', 'emballagecom-store')];

			foreach ($cities as $city) {
				$options[$city['name']] = $city['name'];
			}

			$fields['billing']['billing_city']['type']        = 'select';
			$fields['billing']['billing_city']['options']     = $options;
			$fields['billing']['billing_city']['required']    = true;
			$fields['billing']['billing_city']['input_class'] = ['wc-enhanced-select'];
		}
		$fields['billing']['billing_city']['class']    = ['form-row-first'];
		$fields['billing']['billing_city']['priority'] = 70;

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
		wp_register_style('emballagecom-store-checkout', false, [], '');
		wp_enqueue_style('emballagecom-store-checkout');
		wp_add_inline_style(
			'emballagecom-store-checkout',
			'@media (max-width: 767px){.woocommerce-checkout .col2-set .col-1,.woocommerce-checkout .col2-set .col-2{float:none!important;width:100%!important;}.woocommerce-checkout form .form-row,.woocommerce-checkout form .form-row-first,.woocommerce-checkout form .form-row-last,.woocommerce-page.woocommerce-checkout form .form-row,.woocommerce-page.woocommerce-checkout form .form-row-first,.woocommerce-page.woocommerce-checkout form .form-row-last{float:none!important;display:block!important;clear:both!important;width:100%!important;max-width:100%!important;margin-left:0!important;margin-right:0!important;}}'
		);
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
		$city      = '';
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
}
