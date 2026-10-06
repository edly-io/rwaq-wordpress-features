<?php
/**
 * Load the cart stylesheet on the cart page.
 *
 * Styling only — WooCommerce's cart templates are not overridden, so an update
 * to them cannot break this. See assets/css/cart.css.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the cart styles, on the cart page only.
 */
function cart_styles_enqueue() {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-cart',
		TUTOR_SSO_URL . 'assets/css/cart.css',
		// Loads after WooCommerce's own sheet so these rules win on equal specificity.
		array( 'woocommerce-general', 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\cart_styles_enqueue', 20 );
