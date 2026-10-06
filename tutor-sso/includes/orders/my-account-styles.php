<?php
/**
 * Load the My Account stylesheet on the WooCommerce account pages.
 *
 * Styling only — WooCommerce's own myaccount templates are not overridden, so
 * an update to them cannot break this. See assets/css/my-account.css.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the My Account styles, on those pages only.
 */
function my_account_styles_enqueue() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-my-account',
		TUTOR_SSO_URL . 'assets/css/my-account.css',
		// After woocommerce-general so the float layout it sets on the
		// navigation / content columns is already in when this replaces it.
		array( 'woocommerce-general', 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\my_account_styles_enqueue', 21 );
