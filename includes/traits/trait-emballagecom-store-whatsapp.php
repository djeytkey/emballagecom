<?php
/**
 * Send new-order summary to merchant WhatsApp when the order becomes *processing* (server-side only).
 *
 * Also runs on the thank-you page (same anti-duplicate meta).
 *
 * WhatsApp Cloud API (Meta), plain text only (`type => text` — full Arabic order body).
 *
 * Credentials (first match wins): EmballageCom constants/options, then fallback to GeneVNotify option
 * `genevnotify_api_credentials` (`access_token`, `phone_number_id`) if EmballageCom values are empty.
 *
 * POST `https://graph.facebook.com/{version}/{phone-number-id}/messages` with Bearer token.
 * Optional: `EMBALLAGECOM_STORE_WA_CLOUD_API_VERSION` / option `emballagecom_whatsapp_cloud_api_version` (default v22.0).
 * If you only use GeneVNotify credentials, Graph `v17.0` often matches their setup — set the version option if needed.
 *
 * Also supported: custom webhook and CallMeBot (see `deliver_whatsapp_notification`).
 *
 * Recipient number: filter `emballagecom_store_whatsapp_owner_phone`, default Moroccan digits.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Whatsapp_Trait {

	private const WHATSAPP_NOTICE_META = '_emballagecom_whatsapp_thankyou_sent';

	/**
	 * Default merchant WhatsApp (E.164 without +).
	 */
	private const DEFAULT_WHATSAPP_OWNER_PHONE = '212622080730';

	public function maybe_send_whatsapp_order_notification($order_id, $order_object = null): void {
		if (! $order_id) {
			return;
		}

		/** @var WC_Order|false $order */
		$order = $order_object instanceof WC_Order ? $order_object : wc_get_order((int) $order_id);

		if (! $order instanceof WC_Order) {
			return;
		}

		if (! $order->has_status('processing')) {
			return;
		}

		if ($order->get_meta(self::WHATSAPP_NOTICE_META)) {
			return;
		}

		/** @var string $owner_phone International digits, no + */
		$owner_phone = apply_filters(
			'emballagecom_store_whatsapp_owner_phone',
			self::DEFAULT_WHATSAPP_OWNER_PHONE,
			$order
		);

		$owner_phone = preg_replace('/\D/', '', (string) $owner_phone);

		if ($owner_phone === '') {
			return;
		}

		$message = $this->build_whatsapp_arabic_order_message($order);

		/** @var mixed $sent_filter */
		$sent_filter = apply_filters(
			'emballagecom_store_whatsapp_send_message',
			'__emballagecom_whatsapp_default__',
			$message,
			$owner_phone,
			$order
		);

		if ('__emballagecom_whatsapp_default__' === $sent_filter) {
			$sent = $this->deliver_whatsapp_notification($message, $owner_phone, $order);
		} else {
			$sent = (bool) $sent_filter;
		}

		if ($sent) {
			$order->update_meta_data(self::WHATSAPP_NOTICE_META, time());
			$order->save();
		} elseif (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log(
				sprintf(
					'EmballageCom Store: WhatsApp notification failed for order #%s (configure Meta Cloud API, webhook, or CallMeBot).',
					(string) $order->get_id()
				)
			);
		}
	}

	/**
	 * Thank-you page fallback (order is usually *processing* by then).
	 *
	 * @param mixed $order_id
	 */
	public function maybe_send_whatsapp_on_thankyou($order_id): void {
		$this->maybe_send_whatsapp_order_notification($order_id, null);
	}

	/**
	 * Fires when order status changes (processing = new paid / ready orders in most setups).
	 *
	 * @param mixed      $order_id
	 * @param mixed      $old_status
	 * @param mixed      $new_status
	 * @param mixed      $order
	 */
	public function maybe_send_whatsapp_on_status_changed($order_id, $old_status = null, $new_status = null, $order = null): void {
		if ('processing' !== (string) $new_status) {
			return;
		}

		if (! $order instanceof WC_Order && $order_id) {
			$order = wc_get_order((int) $order_id);
		}

		if (! $order instanceof WC_Order) {
			return;
		}

		$this->maybe_send_whatsapp_order_notification((int) $order->get_id(), $order);
	}

	private function build_whatsapp_arabic_order_message(WC_Order $order): string {
		$name    = trim($order->get_formatted_billing_full_name()) ?: __('—', 'emballagecom-store');
		$phone   = trim((string) $order->get_billing_phone()) ?: __('—', 'emballagecom-store');
		$city    = trim((string) $order->get_billing_city()) ?: __('—', 'emballagecom-store');
		$address = trim((string) $order->get_billing_address_1()) ?: __('—', 'emballagecom-store');

		$lines = [];

		foreach ($order->get_items('line_item') as $item) {
			if (! $item instanceof WC_Order_Item_Product) {
				continue;
			}

			$product_name = $item->get_name();
			$qty          = $item->get_quantity();
			$price        = wp_strip_all_tags(
				wc_price((float) $item->get_total(), ['currency' => $order->get_currency()])
			);

			$lines[] = sprintf(
				/* translators: 1: product name, 2: quantity, 3: line total */
				'• %1$s × %2$s — %3$s',
				$product_name,
				$qty,
				$price
			);
		}

		$items_block = implode("\n", $lines);

		if ($items_block === '') {
			$items_block = __('—', 'emballagecom-store');
		}

		$subtotal = wp_strip_all_tags(wc_price((float) $order->get_subtotal(), ['currency' => $order->get_currency()]));
		$shipping = wp_strip_all_tags(wc_price((float) $order->get_shipping_total(), ['currency' => $order->get_currency()]));

		$fees_total = 0.0;

		foreach ($order->get_fees() as $fee) {
			$fees_total += (float) $fee->get_total();
		}

		$fees = wp_strip_all_tags(wc_price($fees_total, ['currency' => $order->get_currency()]));
		$total = wp_strip_all_tags(wc_price((float) $order->get_total(), ['currency' => $order->get_currency()]));

		$pay_title = wp_strip_all_tags((string) $order->get_payment_method_title());
		if ($pay_title === '') {
			$pay_title = __('—', 'emballagecom-store');
		}

		$edit_link = '';

		if (method_exists($order, 'get_edit_order_url')) {
			$edit_link = (string) $order->get_edit_order_url();
		}

		if ($edit_link === '' && function_exists('get_edit_post_link')) {
			$url = get_edit_post_link($order->get_id(), '');
			if (is_string($url) && $url !== '') {
				$edit_link = $url;
			}
		}

		if ($edit_link === '') {
			$edit_link = admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit');
		}

		$txt = sprintf(
			/* translators: placeholders are order-specific values */
			__(
				'🛒 *طلب جديد* رقم #%1$s

👤 الاسم: %2$s
📞 الهاتف: %3$s
📍 المدينة: %4$s
🏠 العنوان: %5$s

📦 المنتجات:
%6$s

💰 المجموع الفرعي: %7$s
🚚 الشحن: %8$s
➕ الرسوم/الخدمات: %9$s
✅ الإجمالي: %10$s
💳 طريقة الدفع: %11$s

🔗 لوحة التحكم: %12$s',
				'emballagecom-store'
			),
			$order->get_order_number(),
			$name,
			$phone,
			$city,
			$address,
			$items_block,
			$subtotal,
			$shipping,
			$fees,
			$total,
			$pay_title,
			$edit_link
		);

		return apply_filters('emballagecom_store_whatsapp_arabic_message_text', $txt, $order);
	}

	private function deliver_whatsapp_notification(string $message, string $owner_phone, WC_Order $order): bool {
		$webhook_url = '';

		if (defined('EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL') && is_string(EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL) && EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL !== '') {
			$webhook_url = (string) EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL;
		} else {
			$opt_hook = get_option('emballagecom_whatsapp_webhook_url', '');
			if (is_string($opt_hook) && $opt_hook !== '') {
				$webhook_url = $opt_hook;
			}
		}

		$webhook_url = apply_filters('emballagecom_store_whatsapp_webhook_url', $webhook_url, $order);

		/** Custom webhook POST (JSON body). */
		if ($webhook_url !== '') {
			$url = esc_url_raw($webhook_url);

			$payload = apply_filters(
				'emballagecom_store_whatsapp_webhook_payload',
				[
					'to_phone'       => $owner_phone,
					'message'        => $message,
					'order_id'       => $order->get_id(),
					'order_number'   => $order->get_order_number(),
				],
				$order
			);

			$response = wp_remote_post(
				$url,
				[
					'timeout' => 15,
					'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
					'body'    => wp_json_encode($payload),
				]
			);

			return ! is_wp_error($response)
				&& (int) wp_remote_retrieve_response_code($response) < 400
				&& $this->whatsapp_http_body_successful(wp_remote_retrieve_body($response));
		}

		if ($this->deliver_via_meta_whatsapp_cloud($message, $owner_phone, $order)) {
			return true;
		}

		$api_key = '';

		if (defined('EMBALLAGECOM_STORE_CALLMEBOT_APIKEY') && is_string(EMBALLAGECOM_STORE_CALLMEBOT_APIKEY) && EMBALLAGECOM_STORE_CALLMEBOT_APIKEY !== '') {
			$api_key = sanitize_text_field(EMBALLAGECOM_STORE_CALLMEBOT_APIKEY);
		} else {
			$opt_key = get_option('emballagecom_whatsapp_callmebot_apikey', '');
			if (is_string($opt_key) && $opt_key !== '') {
				$api_key = sanitize_text_field($opt_key);
			}
		}

		$api_key = apply_filters('emballagecom_store_callmebot_api_key', $api_key, $order);

		if ($api_key === '') {
			return false;
		}

		$phone_param = $owner_phone;
		if ($phone_param !== '' && strpos($phone_param, '+') !== 0) {
			$phone_param = '+' . $phone_param;
		}

		$url = sprintf(
			'https://api.callmebot.com/whatsapp.php?phone=%s&text=%s&apikey=%s',
			rawurlencode($phone_param),
			rawurlencode($message),
			rawurlencode($api_key)
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 25,
				'headers' => [
					'Accept'     => 'text/plain',
					'User-Agent' => 'EmballageCom-Store/WooCommerce',
				],
			]
		);

		if (is_wp_error($response)) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('EmballageCom Store: CallMeBot request error: ' . $response->get_error_message());
			}
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code < 200 || $code >= 400) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log(
					sprintf(
						'EmballageCom Store: CallMeBot HTTP %d body: %s',
						$code,
						substr($body, 0, 500)
					)
				);
			}
			return false;
		}

		return $this->whatsapp_http_body_successful($body);
	}

	/**
	 * Meta WhatsApp Cloud API — https://developers.facebook.com/docs/whatsapp/cloud-api
	 *
	 * @param string $to_digits Recipient E.164 without +.
	 */
	private function deliver_via_meta_whatsapp_cloud(string $message, string $to_digits, WC_Order $order): bool {
		$token = $this->get_whatsapp_cloud_token($order);
		$phone_number_id = $this->get_whatsapp_cloud_phone_number_id($order);

		if ($token === '' || $phone_number_id === '') {
			return false;
		}

		return $this->deliver_meta_cloud_text($message, $to_digits, $order, $token, $phone_number_id);
	}

	private function deliver_meta_cloud_text(string $message, string $to_digits, WC_Order $order, string $token, string $phone_number_id): bool {
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($message, 'UTF-8') > 4096) {
				$message = mb_substr($message, 0, 4090, 'UTF-8') . '…';
			}
		} elseif (strlen($message) > 4096) {
			$message = substr($message, 0, 4090) . '…';
		}

		$version = $this->get_whatsapp_cloud_api_version($order);
		$url = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode($version),
			rawurlencode($phone_number_id)
		);

		$payload = apply_filters(
			'emballagecom_store_wa_cloud_message_payload',
			[
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => $to_digits,
				'type'              => 'text',
				'text'              => [
					'preview_url' => false,
					'body'        => $message,
				],
			],
			$order
		);

		return $this->post_meta_cloud_messages($url, $token, $payload, $order);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function post_meta_cloud_messages(string $url, string $token, array $payload, WC_Order $order): bool {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 25,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				],
				'body'    => wp_json_encode($payload),
			]
		);

		return $this->meta_whatsapp_graph_response_ok($response, $order);
	}

	private function get_genevnotify_api_credentials(): array {
		$creds = get_option('genevnotify_api_credentials', []);

		return is_array($creds) ? $creds : [];
	}

	private function get_whatsapp_cloud_token(WC_Order $order): string {
		$token = '';

		if (defined('EMBALLAGECOM_STORE_WA_CLOUD_TOKEN') && is_string(EMBALLAGECOM_STORE_WA_CLOUD_TOKEN) && EMBALLAGECOM_STORE_WA_CLOUD_TOKEN !== '') {
			$token = EMBALLAGECOM_STORE_WA_CLOUD_TOKEN;
		} else {
			$opt = get_option('emballagecom_whatsapp_cloud_token', '');
			if (is_string($opt) && $opt !== '') {
				$token = $opt;
			}
		}

		if ('' === trim((string) $token)) {
			$gv = $this->get_genevnotify_api_credentials();
			if (! empty($gv['access_token']) && is_string($gv['access_token'])) {
				$token = $gv['access_token'];
			}
		}

		$token = apply_filters('emballagecom_store_wa_cloud_token', $token, $order);

		return is_string($token) ? trim($token) : '';
	}

	private function get_whatsapp_cloud_phone_number_id(WC_Order $order): string {
		$id = '';

		if (
			defined('EMBALLAGECOM_STORE_WA_CLOUD_PHONE_NUMBER_ID')
			&& is_string(EMBALLAGECOM_STORE_WA_CLOUD_PHONE_NUMBER_ID)
			&& EMBALLAGECOM_STORE_WA_CLOUD_PHONE_NUMBER_ID !== ''
		) {
			$id = EMBALLAGECOM_STORE_WA_CLOUD_PHONE_NUMBER_ID;
		} else {
			$opt = get_option('emballagecom_whatsapp_cloud_phone_number_id', '');
			if (is_string($opt) && $opt !== '') {
				$id = $opt;
			}
		}

		if ('' === trim((string) $id)) {
			$gv = $this->get_genevnotify_api_credentials();
			if (! empty($gv['phone_number_id']) && is_scalar($gv['phone_number_id'])) {
				$id = (string) $gv['phone_number_id'];
			}
		}

		$id = apply_filters('emballagecom_store_wa_cloud_phone_number_id', $id, $order);

		return is_string($id) ? preg_replace('/\D/', '', $id) : '';
	}

	private function get_whatsapp_cloud_api_version(WC_Order $order): string {
		$version = 'v22.0';

		if (
			defined('EMBALLAGECOM_STORE_WA_CLOUD_API_VERSION')
			&& is_string(EMBALLAGECOM_STORE_WA_CLOUD_API_VERSION)
			&& EMBALLAGECOM_STORE_WA_CLOUD_API_VERSION !== ''
		) {
			$version = EMBALLAGECOM_STORE_WA_CLOUD_API_VERSION;
		} else {
			$opt = get_option('emballagecom_whatsapp_cloud_api_version', '');
			if (is_string($opt) && $opt !== '') {
				$version = $opt;
			}
		}

		$version = apply_filters('emballagecom_store_wa_cloud_api_version', $version, $order);

		if (! is_string($version) || $version === '') {
			return 'v22.0';
		}

		return preg_match('/^v\d+(\.\d+)?$/', $version) ? $version : 'v22.0';
	}

	/**
	 * @param mixed $response
	 */
	private function meta_whatsapp_graph_response_ok($response, WC_Order $order): bool {
		if (is_wp_error($response)) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('EmballageCom Store: Meta WhatsApp Cloud request error: ' . $response->get_error_message());
			}

			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw = (string) wp_remote_retrieve_body($response);
		$data = json_decode($raw, true);

		if ($code < 200 || $code >= 300) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log(
					sprintf(
						'EmballageCom Store: Meta WhatsApp Cloud HTTP %d for order #%s — %s',
						$code,
						(string) $order->get_id(),
						substr($raw, 0, 800)
					)
				);
			}

			return false;
		}

		if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log(
					'EmballageCom Store: Meta WhatsApp Cloud API error for order #' . (string) $order->get_id() . ' — ' . wp_json_encode($data['error'])
				);
			}

			return false;
		}

		return true;
	}

	private function whatsapp_http_body_successful(string $body): bool {
		$body = trim($body);

		if ($body === '') {
			return true;
		}

		if (preg_match('/\bERROR\b/i', $body)) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('EmballageCom Store: WhatsApp provider returned error body: ' . substr($body, 0, 500));
			}
			return false;
		}

		return true;
	}
}
