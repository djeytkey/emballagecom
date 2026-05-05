<?php
/**
 * Send new-order summary to merchant WhatsApp when the order becomes *processing*.
 *
 * Also runs on the thank-you page as a fallback (same anti-duplicate meta).
 *
 * Setup (choose one):
 * — CallMeBot: activate with their WhatsApp bot, then use either wp-config.php or an option:
 *   define( 'EMBALLAGECOM_STORE_CALLMEBOT_APIKEY', 'your_key' );
 *   Or: update_option( 'emballagecom_whatsapp_callmebot_apikey', 'your_key' );
 * — Or webhook: define( 'EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL', 'https://…' ); (POST JSON)
 *
 * Override phone / message via filters documented in code.
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
					'EmballageCom Store: WhatsApp notification not sent for order #%s (configure CallMeBot key or webhook; see trait doc).',
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

		/** CallMeBot (free WhatsApp forwarding). */
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
