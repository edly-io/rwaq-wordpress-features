<?php
/**
 * The header's Elementor Menu Cart, when the cart is empty.
 *
 * Elementor Pro prints its own "No products in the cart." in the elementor-pro
 * text domain, which has no Arabic translation installed, so it shows in
 * English. It is swapped for Arabic here.
 *
 * The empty dropdown is also sized for a full cart — a 330px wide, 200px tall
 * panel around one line of text — so assets/css/menu-cart.css shrinks it when
 * that message is all it holds, and brings a full cart's text and buttons in
 * line with the rest of the site.
 *
 * Its other labels ("Subtotal", "View cart", "Checkout") are WooCommerce's.
 * They came out in English too, so the cart refresh is switched to the site
 * language, and Arabic is supplied for them if WooCommerce still has none.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render Elementor's cart refresh in the site language.
 *
 * Elementor redraws the menu cart through admin-ajax.php, and admin-ajax runs
 * in the admin context, where WordPress picks the logged-in user's own locale.
 * A user whose profile is set to English then gets "Subtotal", "View cart" and
 * "Checkout" in English, though the page around the cart is Arabic. Switching
 * to the site locale first matches the page — the same fix as programs-ajax.php
 * and search-ajax.php. Elementor's handler ends with wp_send_json(), so nothing
 * runs after it to switch back.
 *
 * @return void
 */
function menu_cart_fragments_site_locale() {
	switch_to_locale( get_locale() );
}
add_action( 'wp_ajax_elementor_menu_cart_fragments', __NAMESPACE__ . '\\menu_cart_fragments_site_locale', 1 );

/**
 * Arabic for the menu cart's WooCommerce labels, when WooCommerce has none.
 *
 * Elementor's mini cart prints "Subtotal", "View cart" and "Checkout" in the
 * woocommerce text domain. They still came out in English after the locale
 * switch above, which means no Arabic translation was found for them at all —
 * an incomplete or missing language pack on the server, or one that a locale
 * switch does not reload. This only steps in when the string came back
 * untranslated, so a working WooCommerce translation always wins.
 *
 * Front end and AJAX only, and only on an Arabic site, so an English-speaking
 * admin's WooCommerce screens are left alone.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 * @return string
 */
function menu_cart_woocommerce_labels( $translation, $text, $domain ) {
	if ( 'woocommerce' !== $domain || $translation !== $text ) {
		return $translation;
	}

	static $labels = null;

	if ( null === $labels ) {
		$labels = array(
			'Subtotal'  => 'المجموع الفرعي',
			'View cart' => 'سلة التسوق',
			'Checkout'  => 'إتمام الطلب',
		);
	}

	if ( ! isset( $labels[ $text ] ) ) {
		return $translation;
	}

	if ( ( is_admin() && ! wp_doing_ajax() ) || 0 !== strpos( get_locale(), 'ar' ) ) {
		return $translation;
	}

	return $labels[ $text ];
}
add_filter( 'gettext', __NAMESPACE__ . '\\menu_cart_woocommerce_labels', 10, 3 );

/**
 * Translate Elementor Pro's empty cart message.
 *
 * Also runs on WooCommerce's cart-fragment AJAX refresh, which re-renders the
 * mini cart after an item is removed.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 * @return string
 */
function menu_cart_empty_message( $translation, $text, $domain ) {
	if ( 'elementor-pro' !== $domain || 'No products in the cart.' !== $text ) {
		return $translation;
	}

	return __( 'سلة التسوق فارغة.', 'tutor-sso' );
}
add_filter( 'gettext', __NAMESPACE__ . '\\menu_cart_empty_message', 10, 3 );

/**
 * Enqueue the menu cart styles.
 *
 * On every front-end page, since the cart sits in the site header.
 */
function menu_cart_styles_enqueue() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-menu-cart',
		TUTOR_SSO_URL . 'assets/css/menu-cart.css',
		array(),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\menu_cart_styles_enqueue', 20 );
