<?php
/**
 * Load the My Account stylesheet on the WooCommerce account pages, and the
 * script that collapses the navigation on small screens.
 *
 * Styling only — WooCommerce's own myaccount templates are not overridden, so
 * an update to them cannot break this. See assets/css/my-account.css. The
 * dashboard is the exception: my-account-dashboard.php replaces it.
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

	// The navigation only shows to a logged-in customer; logged out, the
	// account page redirects away.
	if ( ! is_user_logged_in() ) {
		return;
	}

	// Collapses the navigation behind a toggle on small screens. See the
	// "Navigation toggle" section of my-account.css.
	wp_enqueue_script(
		'tutor-sso-my-account-menu',
		TUTOR_SSO_URL . 'assets/js/my-account-menu.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);

	wp_localize_script(
		'tutor-sso-my-account-menu',
		'tutorSsoAccountMenu',
		array(
			'i18n' => array(
				'title' => __( 'قائمة الحساب', 'tutor-sso' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\my_account_styles_enqueue', 21 );
