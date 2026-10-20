<?php
/**
 * Turn off front-end password reset.
 *
 * Sign-in goes through the LMS, where the password that matters lives. A
 * WordPress password reset does nothing for the LMS — it only gives the user a
 * WordPress password, and with it a second way in at wp-login.php that skips
 * SSO. So:
 *
 *  - Reset emails are refused for everyone but administrators, whichever form
 *    asks: WordPress's own or WooCommerce's. This is the part that holds even
 *    for a form posted directly.
 *  - WooCommerce's My Account lost-password page sends the visitor to the home
 *    page, and the "Lost your password?" link on its login form points there.
 *
 * WordPress's own page, wp-login.php?action=lostpassword, stays: it is how an
 * administrator resets theirs. Anyone else who uses it is refused by the rule
 * above and no email is sent.
 *
 * Changing the password from My Account is handled in
 * orders/my-account-form.php.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the lost-password pages and links go.
 *
 * @return string
 */
function password_reset_redirect_url() {
	/**
	 * Filter where the disabled lost-password pages send visitors.
	 *
	 * @param string $url Home page URL.
	 */
	return (string) apply_filters( 'tutor_sso_password_reset_redirect_url', home_url( '/' ) );
}

/**
 * Allow a password reset for administrators only.
 *
 * WordPress's retrieve_password() — behind both its own form and
 * WooCommerce's — asks this before sending a reset link.
 *
 * @param bool $allow   Whether the reset is allowed so far.
 * @param int  $user_id User asking.
 * @return bool
 */
function password_reset_allowed( $allow, $user_id ) {
	return $allow && user_can( (int) $user_id, 'manage_options' );
}
add_filter( 'allow_password_reset', __NAMESPACE__ . '\\password_reset_allowed', 10, 2 );

/**
 * Send WooCommerce's My Account lost-password page to the home page.
 *
 * @return void
 */
function password_reset_close_wc_form() {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'lost-password' ) ) {
		return;
	}

	wp_safe_redirect( password_reset_redirect_url() );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\password_reset_close_wc_form' );

/**
 * Point the front end's "Lost your password?" link at the home page.
 *
 * WooCommerce's login form builds it with wp_lostpassword_url(), so without
 * this it would lead to a page that only redirects. wp-login.php keeps its own
 * link, to the page administrators reset from.
 *
 * @param string $url Lost-password URL.
 * @return string
 */
function password_reset_link_url( $url ) {
	if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
		return $url;
	}

	return password_reset_redirect_url();
}
add_filter( 'lostpassword_url', __NAMESPACE__ . '\\password_reset_link_url', 20 );
