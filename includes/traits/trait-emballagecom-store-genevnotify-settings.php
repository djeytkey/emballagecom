<?php
/**
 * Admin: EmballageCom menu + GeneVNotify (WhatsApp templates & API) settings.
 *
 * Persists options compatible with the reference file `genevnotify.php`: `genevnotify_settings`, `genevnotify_api_credentials`.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Genevnotify_Settings_Trait {

	private $genevnotify_option_name = 'genevnotify_settings';

	private $genevnotify_credentials_option = 'genevnotify_api_credentials';

	private $genevnotify_settings_group = 'genevnotify_settings_group';

	public function register_emballagecom_admin_menu(): void {
		add_menu_page(
			__('EmballageCom', 'emballagecom-store'),
			__('EmballageCom', 'emballagecom-store'),
			'manage_options',
			'emballagecom-settings',
			[$this, 'render_genevnotify_settings_page'],
			'dashicons-store',
			59
		);
	}

	public function register_genevnotify_settings(): void {
		register_setting($this->genevnotify_settings_group, $this->genevnotify_option_name);
		register_setting($this->genevnotify_settings_group, $this->genevnotify_credentials_option);
	}

	public function render_genevnotify_settings_page(): void {
		if (! function_exists('wc_get_order_statuses')) {
			echo '<div class="wrap"><p>' . esc_html__('WooCommerce est requis pour cette page.', 'emballagecom-store') . '</p></div>';

			return;
		}

		$statuses    = wc_get_order_statuses();
		$settings    = get_option($this->genevnotify_option_name, []);
		$credentials = get_option($this->genevnotify_credentials_option, ['phone_number_id' => '', 'access_token' => '']);
		$register    = isset($settings['user_register']) ? $settings['user_register'] : ['enabled' => 'no', 'template' => '', 'variables' => '', 'language' => 'ar'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e('GeneVNotify — réglages WhatsApp', 'emballagecom-store'); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields($this->genevnotify_settings_group); ?>

				<h2><?php esc_html_e('Notification nouvelle inscription', 'emballagecom-store'); ?></h2>
				<div style="border: 1px solid #ddd; padding: 16px; border-radius: 8px; background: #f1f1f1; margin-bottom: 20px; max-width: 600px;">
					<label><strong><?php esc_html_e('Activer la notification', 'emballagecom-store'); ?></strong></label><br>
					<select name="<?php echo esc_attr($this->genevnotify_option_name); ?>[user_register][enabled]">
						<option value="yes" <?php selected($register['enabled'], 'yes'); ?>><?php esc_html_e('Oui', 'emballagecom-store'); ?></option>
						<option value="no" <?php selected($register['enabled'], 'no'); ?>><?php esc_html_e('Non', 'emballagecom-store'); ?></option>
					</select><br><br>

					<label><strong><?php esc_html_e('Nom du modèle (template)', 'emballagecom-store'); ?></strong></label><br>
					<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name); ?>[user_register][template]" value="<?php echo esc_attr($register['template']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

					<label><strong><?php esc_html_e('Variables (séparées par des virgules)', 'emballagecom-store'); ?></strong></label><br>
					<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name); ?>[user_register][variables]" value="<?php echo esc_attr($register['variables']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

					<label><strong><?php esc_html_e('Langue (ex. ar, en)', 'emballagecom-store'); ?></strong></label><br>
					<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name); ?>[user_register][language]" value="<?php echo esc_attr($register['language']); ?>" class="small-text" style="width: 100%; max-width: 100%;" />
				</div>

				<h2><?php esc_html_e('Identifiants API', 'emballagecom-store'); ?></h2>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e('Phone Number ID', 'emballagecom-store'); ?></th>
						<td><input type="text" name="<?php echo esc_attr($this->genevnotify_credentials_option); ?>[phone_number_id]" value="<?php echo esc_attr($credentials['phone_number_id']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /></td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e('Access Token', 'emballagecom-store'); ?></th>
						<td><input type="text" name="<?php echo esc_attr($this->genevnotify_credentials_option); ?>[access_token]" value="<?php echo esc_attr($credentials['access_token']); ?>" class="small-text" style="width: 100%; max-width: 100%;" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e('Modèles par statut de commande', 'emballagecom-store'); ?></h2>
				<p><?php esc_html_e('Variables possibles :', 'emballagecom-store'); ?>
					<code>{{code_otp}}, {{customer_name}}, {{order_id}}, {{shipping_method}}, {{tracking_number}}, {{wp-first-name}}, {{post-_wcpdf_invoice_number}}, {{post-_flotracknumber_link}}, {{post-smsa_awb_no_link}}</code></p>
				<div style="display: flex; flex-wrap: wrap; gap: 20px;">
					<?php foreach ($statuses as $key => $label) : ?>
						<?php
						$status_key = str_replace('wc-', '', $key);
						$template   = isset($settings[ $status_key ]['template']) ? $settings[ $status_key ]['template'] : '';
						$variables  = isset($settings[ $status_key ]['variables']) ? $settings[ $status_key ]['variables'] : '';
						$language   = isset($settings[ $status_key ]['language']) ? $settings[ $status_key ]['language'] : 'ar';
						$enabled    = isset($settings[ $status_key ]['enabled']) ? $settings[ $status_key ]['enabled'] : 'no';
						?>
						<div style="flex: 1 1 calc(33.333% - 20px); min-width: 300px; border: 1px solid #ddd; padding: 16px; border-radius: 8px; background: #f9f9f9;">
							<h3 style="margin-top: 0;"><?php echo esc_html($label); ?></h3>
							<label><strong><?php esc_html_e('Activer la notification', 'emballagecom-store'); ?></strong></label><br>
							<select name="<?php echo esc_attr($this->genevnotify_option_name . '[' . $status_key . '][enabled]'); ?>">
								<option value="yes" <?php selected($enabled, 'yes'); ?>><?php esc_html_e('Oui', 'emballagecom-store'); ?></option>
								<option value="no" <?php selected($enabled, 'no'); ?>><?php esc_html_e('Non', 'emballagecom-store'); ?></option>
							</select><br><br>

							<label><strong><?php esc_html_e('Nom du modèle (template)', 'emballagecom-store'); ?></strong></label><br>
							<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name . '[' . $status_key . '][template]'); ?>" value="<?php echo esc_attr($template); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

							<label><strong><?php esc_html_e('Variables (séparées par des virgules)', 'emballagecom-store'); ?></strong></label><br>
							<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name . '[' . $status_key . '][variables]'); ?>" value="<?php echo esc_attr($variables); ?>" class="small-text" style="width: 100%; max-width: 100%;" /><br><br>

							<label><strong><?php esc_html_e('Langue (ex. ar, en)', 'emballagecom-store'); ?></strong></label><br>
							<input type="text" name="<?php echo esc_attr($this->genevnotify_option_name . '[' . $status_key . '][language]'); ?>" value="<?php echo esc_attr($language); ?>" class="small-text" style="width: 100%; max-width: 100%;" />
						</div>
					<?php endforeach; ?>
				</div>
				<br>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
