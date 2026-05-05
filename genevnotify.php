<?php
/*
Plugin Name: GeneVNotify
Description: Sends WhatsApp notifications when WooCommerce order statuses change and on new user registration.
Version: 2.2
Author: EL GENEVSKY
*/

if (!defined('ABSPATH')) exit;

class GeneVNotify {
    private $option_name = 'genevnotify_settings';
    private $credentials_option = 'genevnotify_api_credentials';

   public function __construct() {
    add_action('admin_menu', [$this, 'add_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);
add_action('admin_menu', [$this, 'add_batch_tool_page']);

    // First generate OTP
    add_action('woocommerce_order_status_changed', [$this, 'generate_otp_on_status_change'], 10, 4);
    
    // Then send WhatsApp message
    add_action('woocommerce_order_status_changed', [$this, 'send_whatsapp_notification'], 20, 4);

    add_action('user_register', [$this, 'send_registration_message'], 10, 1);
}
public function add_batch_tool_page() {
    add_submenu_page(
        'tools.php',
        'Generate OTPs for Old Orders',
        'Generate OTPs for Orders',
        'manage_woocommerce',
        'generate-otp-orders',
        [$this, 'render_batch_tool_page']
    );
}

public function render_batch_tool_page() {
    if (isset($_POST['generate_otp_now'])) {
        $this->generate_otp_for_old_orders();
        echo '<div class="notice notice-success"><p>✅ OTPs generated and messages sent for all matching orders.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Generate OTPs & Send WhatsApp for Past Orders</h1>
        <form method="post">
            <p>This tool finds all <code>delivery_prepared</code> orders that are missing an OTP and sends them WhatsApp notifications.</p>
            <input type="submit" name="generate_otp_now" class="button button-primary" value="Run Now" />
        </form>
    </div>
    <?php
}

public function generate_otp_for_old_orders() {
    $args = [
        'status' => 'delivery_prepared',
        'limit' => -1,
        'return' => 'ids'
    ];

    $orders = wc_get_orders($args);
    $created = 0;
    $sent = 0;

    foreach ($orders as $order_id) {
        $otp = get_post_meta($order_id, 'order_confirm_otp', true);

        // Only act if OTP does not exist
        if (empty($otp)) {
            $order = wc_get_order($order_id);
            if (!$order) continue;

            // Generate and save OTP
            $new_otp = rand(1000, 9999);
            update_post_meta($order_id, 'order_confirm_otp', $new_otp);
            error_log("[GeneVNotify] Generated OTP $new_otp for old order $order_id");
            $created++;

            // Send WhatsApp message
            $this->send_whatsapp_notification($order_id, '', 'delivery_prepared', $order);
            $sent++;
        }
    }

    echo '<div class="notice notice-success"><p>✅ OTPs generated: ' . $created . ' | Messages sent: ' . $sent . '</p></div>';
}



public function generate_otp_on_status_change($order_id, $old_status, $new_status, $order) {
    if ($new_status !== 'delivery_prepared') return;

    if (!is_a($order, 'WC_Order')) {
        error_log("[GeneVNotify] Invalid order object in generate_otp_on_status_change for order $order_id");
        return;
    }

    $otp = rand(1000, 9999);
    update_post_meta($order_id, 'order_confirm_otp', $otp);
    error_log("[GeneVNotify] Generated and saved OTP $otp for order $order_id");
}


    public function add_settings_page() {
        add_options_page('GeneVNotify Settings', 'GeneVNotify', 'manage_options', 'genevnotify', [$this, 'settings_page_html']);
    }

    public function register_settings() {
        register_setting('genevnotify_settings_group', $this->option_name);
        register_setting('genevnotify_settings_group', $this->credentials_option);
    }

    public function settings_page_html() {
        $statuses = wc_get_order_statuses();
        $settings = get_option($this->option_name, []);
        $credentials = get_option($this->credentials_option, ['phone_number_id' => '', 'access_token' => '']);
        $register = isset($settings['user_register']) ? $settings['user_register'] : ['enabled' => 'no', 'template' => '', 'variables' => '', 'language' => 'ar'];
        ?>
        <div class="wrap">
            <h1>GeneVNotify Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('genevnotify_settings_group'); ?>

                <h2>New User Registration Notification</h2>
                <div style="border: 1px solid #ddd; padding: 16px; border-radius: 8px; background: #f1f1f1; margin-bottom: 20px; max-width: 600px;">
                    <label><strong>Enable Notification:</strong></label><br>
                    <select name="<?php echo $this->option_name; ?>[user_register][enabled]">
                        <option value="yes" <?php selected($register['enabled'], 'yes'); ?>>Yes</option>
                        <option value="no" <?php selected($register['enabled'], 'no'); ?>>No</option>
                    </select><br><br>

                    <label><strong>Template Name:</strong></label><br>
                    <input type="text" name="<?php echo $this->option_name; ?>[user_register][template]" value="<?php echo esc_attr($register['template']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

                    <label><strong>Variables (comma separated):</strong></label><br>
                    <input type="text" name="<?php echo $this->option_name; ?>[user_register][variables]" value="<?php echo esc_attr($register['variables']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

                    <label><strong>Language (e.g., ar, en):</strong></label><br>
                    <input type="text" name="<?php echo $this->option_name; ?>[user_register][language]" value="<?php echo esc_attr($register['language']); ?>" class="small-text" style="width: 100%; max-width: 100%;" />
                </div>

                <h2>API Credentials</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Phone Number ID</th>
                        <td><input type="text" name="<?php echo $this->credentials_option; ?>[phone_number_id]" value="<?php echo esc_attr($credentials['phone_number_id']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Access Token</th>
                        <td><input type="text" name="<?php echo $this->credentials_option; ?>[access_token]" value="<?php echo esc_attr($credentials['access_token']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /></td>
                    </tr>
                </table>

                <h2>Status-Based Message Templates</h2>
                <p>You can use variables: <code>{{code_otp}}, {{customer_name}}, {{order_id}}, {{shipping_method}}, {{tracking_number}}, {{wp-first-name}}, {{post-_wcpdf_invoice_number}}, {{post-_flotracknumber_link}}, {{post-smsa_awb_no_link}}</code></p>
                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                    <?php foreach ($statuses as $key => $label): 
                        $status_key = str_replace('wc-', '', $key);
                        $template = isset($settings[$status_key]['template']) ? $settings[$status_key]['template'] : '';
                        $variables = isset($settings[$status_key]['variables']) ? $settings[$status_key]['variables'] : '';
                        $language = isset($settings[$status_key]['language']) ? $settings[$status_key]['language'] : 'ar';
                        $enabled = isset($settings[$status_key]['enabled']) ? $settings[$status_key]['enabled'] : 'no';
                        ?>
                        <div style="flex: 1 1 calc(33.333% - 20px); min-width: 300px; border: 1px solid #ddd; padding: 16px; border-radius: 8px; background: #f9f9f9;">
                            <h3 style="margin-top: 0;">📦 <?php echo esc_html($label); ?></h3>
                            <label><strong>Enable Notification:</strong></label><br>
                            <select name="<?php echo $this->option_name; ?>[<?php echo $status_key; ?>][enabled]">
                                <option value="yes" <?php selected($enabled, 'yes'); ?>>Yes</option>
                                <option value="no" <?php selected($enabled, 'no'); ?>>No</option>
                            </select><br><br>

                            <label><strong>Template Name:</strong></label><br>
                            <input type="text" name="<?php echo $this->option_name; ?>[<?php echo $status_key; ?>][template]" value="<?php echo esc_attr($template); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

                            <label><strong>Variables (comma separated):</strong></label><br>
                            <input type="text" name="<?php echo $this->option_name; ?>[<?php echo $status_key; ?>][variables]" value="<?php echo esc_attr($variables); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

                            <label><strong>Language (e.g., ar, en):</strong></label><br>
                            <input type="text" name="<?php echo $this->option_name; ?>[<?php echo $status_key; ?>][language]" value="<?php echo esc_attr($language); ?>" class="small-text" style="width: 100%; max-width: 100%;" />
                        </div>
                    <?php endforeach; ?>
                </div>
                <br>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

     public function send_registration_message($user_id) {
        $settings = get_option($this->option_name, []);
        $credentials = get_option($this->credentials_option, []);
        $config = isset($settings['user_register']) ? $settings['user_register'] : null;

        if (!$config || $config['enabled'] !== 'yes') return;

        $user = get_userdata($user_id);
        $raw_phone = get_user_meta($user_id, 'billing_phone', true);
        if (!$raw_phone) return;

        $phone = $this->format_phone_number($raw_phone, $user_id);
        if (!$phone) return;

        $variables = array_map('trim', explode(',', $config['variables']));
        $replacements = [
            '{{customer_name}}' => $user->first_name ?: '-',
            '{{wp-first-name}}' => $user->first_name ?: '-',
        ];

        $values = [];
        foreach ($variables as $var) {
            $placeholder = '{{' . $var . '}}';
            $values[] = $replacements[$placeholder] ?? '-';
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $config['template'],
                'language' => ['code' => $config['language'] ?? 'ar'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => $v], $values)
                ]]
            ]
        ];

        $url = "https://graph.facebook.com/v17.0/{$credentials['phone_number_id']}/messages";
        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Content-Type' => 'application/json'
            ]
        ];

        wp_remote_post($url, $args);
    }
private function resolve_tracking_number( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) {
        return '-';
    }
    $order_id = $order->get_id();

    // Check these in order; return the first non-empty value
    $candidates = ['_flotracknumber', 'smsa_awb_no', 'tracking_dhl', 'redbox_tracking_number'];

    foreach ( $candidates as $meta_key ) {
        $val = get_post_meta( $order_id, $meta_key, true );
        if ( is_array( $val ) ) {
            $val = reset( $val );
        }
        $val = trim( (string) $val );
        if ( $val !== '' ) {
            return $val;
        }
    }
    return '-';
}

     public function send_whatsapp_notification($order_id, $old_status, $new_status, $order) {
        $settings = get_option($this->option_name, []);
        $credentials = get_option($this->credentials_option, []);
        $status_settings = isset($settings[$new_status]) ? $settings[$new_status] : null;

        if (!$status_settings || $status_settings['enabled'] !== 'yes') return;

        $template = $status_settings['template'] ?? '';
        $language = $status_settings['language'] ?? 'ar';
        $variables = isset($status_settings['variables']) ? explode(',', $status_settings['variables']) : [];

        if (!$template || empty($credentials['phone_number_id']) || empty($credentials['access_token'])) return;

        $phone = $this->get_customer_phone($order);
        if (!$phone) return;

        $user_id = $order->get_user_id();
        $clean_number = $this->format_phone_number($phone, $user_id);
        $clean_number = ltrim($clean_number, '+');
		$username= $order->get_billing_first_name();

        $replacements = [
            '{{customer_name}}' => $order->get_billing_first_name() ?: '-',
            '{{order_id}}' => $order->get_order_number() ?: '-',
            '{{shipping_method}}' => $order->get_shipping_method() ?: '-',
            '{{tracking_number}}' =>  $this->resolve_tracking_number($order),
            '{{wp-first-name}}' => get_user_meta($user_id, 'first_name', true) ?: '-',
            '{{post-_wcpdf_invoice_number}}' => get_post_meta($order->get_id(), '_wcpdf_invoice_number', true) ?: '-',
            '{{post-_flotracknumber_link}}' => get_post_meta($order->get_id(), '_flotracknumber_link', true) ?: '-',
            '{{post-smsa_awb_no_link}}' => get_post_meta($order->get_id(), 'smsa_awb_no_link', true) ?: '-',
            '{{code_otp}}' => get_post_meta($order->get_id(), 'order_confirm_otp', true) ?: '-',
        ];

        $values = [];
        foreach ($variables as $var) {
            $var = trim($var);
            $placeholder = '{{' . $var . '}}';
            $value = $replacements[$placeholder] ?? '-';
            error_log("GeneVNotify Debug → Variable: '$var' | Value: '$value'");
            $values[] = $value;
        }

        $parameters = array_map(fn($v) => ['type' => 'text', 'text' => $v], $values);
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $clean_number,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => [[
                    'type' => 'body',
                    'parameters' => $parameters
                ]]
            ]
        ];

        $url = "https://graph.facebook.com/v17.0/{$credentials['phone_number_id']}/messages";
        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token'],
                'Content-Type' => 'application/json'
            ]
        ];

        $response = wp_remote_post($url, $args);
        error_log("GeneVNotify sent message to $clean_number, response: " . print_r($response, true));

    // KARZOUN integration
$order_statuses = wc_get_order_statuses();
$status_key = 'wc-' . $new_status;
$label = isset($order_statuses[$status_key]) ? $order_statuses[$status_key] : ucfirst($new_status);

// Arabic-friendly variable labels
$variable_labels = [
    'code_otp' => 'رقم التاكيد',
    'customer_name' => 'اسم العميل',
    'order_id' => 'رقم الطلب',
    'shipping_method' => 'طريقة الشحن',
    'tracking_number' => 'رقم التتبع',
    'wp-first-name' => 'اسم العميل',
    'post-_wcpdf_invoice_number' => 'رقم الفاتورة',
    'post-_flotracknumber_link' => 'رابط التتبع',
    'post-smsa_awb_no_link' => 'رابط التتبع',
];

$variable_lines = [];
foreach ($variables as $var) {
    $var = trim($var);
    $placeholder = '{{' . $var . '}}';
    $value = $replacements[$placeholder] ?? '-';
    $label_ar = $variable_labels[$var] ?? $var;
    $variable_lines[] = "$label_ar: $value";
}

// Final message
$whatsapp_message = "تحول الطلب إلى: $label\nالتفاصيل:\n" . implode("\n", $variable_lines);


  

    // 2. Karzoun inbox log
    $karzoun_payload = [
        "event_type" => "message_create",
        "data" => [
            "from" => "private",
			"first_name" => $username,

            "to" => "{$clean_number}@c.us",
            "pushname" => "private",
            "type" => "chat",
            "body" => $whatsapp_message,
            "media" => "",
            "fromMe" => false,
            "quotedMsg" => new stdClass(),
            "mentionedIds" => []
        ]
    ];
    wp_remote_post("https://api.karzoun.app/incoming.php?id=453&access_token=YXhBseXdvrS5j7CFwbrKQdrs&instance_id=1327&u_token=xxxxx&u_instance=xxxx", [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($karzoun_payload),
        'timeout' => 20
    ]);
}


      private function get_customer_phone($order) {
        $user_id = $order->get_user_id();
        $phone = get_user_meta($user_id, 'billing_phone', true);
        if (empty($phone)) $phone = get_user_meta($user_id, 'shipping_phone', true);
        if (empty($phone)) $phone = $order->get_billing_phone();
        if (empty($phone)) return false;

        return $phone;
    }
  private function format_phone_number($phone_raw, $user_id) {
    // Always get the exact saved value from 'digits_phone'
    $digits_phone = get_user_meta($user_id, 'digits_phone', true);

    // If it's not empty, return it directly
    if (!empty($digits_phone)) {
        return $digits_phone;
    }

    // If empty, fallback to the passed $phone_raw (optional)
    return $phone_raw;
}


}

new GeneVNotify();