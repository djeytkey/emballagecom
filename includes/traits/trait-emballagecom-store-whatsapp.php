<?php
/**
 * Send new-order summary to merchant WhatsApp (thank-you page).
 *
 * Setup (choose one):
 * — CallMeBot: chat once with their bot and put the key in wp-config.php:
 *   define( 'EMBALLAGECOM_STORE_CALLMEBOT_APIKEY', 'your_key' );
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

	public function maybe_send_whatsapp_on_thankyou($order_id): void {
		if (! $order_id) {
			return;
		}

		/** @var WC_Order|false $order */
		$order = wc_get_order((int) $order_id);

		if (! $order instanceof WC_Order) {
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
		}
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
		/** Custom webhook POST (JSON body). */
		if (defined('EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL') && EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL) {
			$url = (string) EMBALLAGECOM_STORE_WHATSAPP_WEBHOOK_URL;

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

			return ! is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400;
		}

		/** CallMeBot (free WhatsApp forwarding). */
		if (
			! defined('EMBALLAGECOM_STORE_CALLMEBOT_APIKEY')
			|| ! is_string(EMBALLAGECOM_STORE_CALLMEBOT_APIKEY)
			|| EMBALLAGECOM_STORE_CALLMEBOT_APIKEY === ''
		) {
			return false;
		}

		$api_key = sanitize_text_field(EMBALLAGECOM_STORE_CALLMEBOT_APIKEY);
		$url = add_query_arg(
			[
				'phone'   => $owner_phone,
				'text'    => $message,
				'apikey'  => $api_key,
			],
			'https://api.callmebot.com/whatsapp.php'
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => ['Accept' => 'text/plain'],
			]
		);

		if (is_wp_error($response)) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		return $code >= 200 && $code < 400;
	}
}
