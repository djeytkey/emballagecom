<?php
/**
 * Shop and theme related customizations.
 *
 * @package EmballageCom_Store
 */

defined('ABSPATH') || exit;

trait EmballageCom_Store_Shop_Trait {
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

		$shop_id  = (int) wc_get_page_id('shop');
		$front_id = (int) get_option('page_on_front');

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
	 * @param object               $args  Menu wp_nav_menu() args object.
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
}
