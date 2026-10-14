<?php
/**
 * Load the order-received stylesheet on the "Thank you" page.
 *
 * Styling only — WooCommerce's own templates are not overridden, so an update
 * to them cannot break this. See assets/css/order-received.css.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the order-received styles, on that endpoint only.
 */
function order_received_styles_enqueue() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-order-received',
		TUTOR_SSO_URL . 'assets/css/order-received.css',
		// Loads after checkout.css so its 960px column wins over checkout's
		// 1232px on the properties both set.
		array( 'woocommerce-general', 'tutor-sso-programs-font', 'tutor-sso-checkout' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\order_received_styles_enqueue', 21 );
