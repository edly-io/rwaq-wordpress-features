<?php
/**
 * Load the checkout stylesheet on the checkout page.
 *
 * Styling only — WooCommerce's and Moyasar's own templates are not overridden,
 * so an update to either cannot break this. See assets/css/checkout.css.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the checkout styles, on the checkout page only.
 */
function checkout_styles_enqueue() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-checkout',
		TUTOR_SSO_URL . 'assets/css/checkout.css',
		// Loads after WooCommerce's own sheet so these rules win on equal specificity.
		array( 'woocommerce-general', 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\checkout_styles_enqueue', 20 );
