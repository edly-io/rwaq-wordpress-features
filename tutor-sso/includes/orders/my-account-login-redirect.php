<?php
/**
 * Send a logged-out visitor on My Account to the home page.
 *
 * Sign-in goes through the LMS, so WooCommerce's own login form on the account
 * page is never the way in. It shows up when the session lapses while the page
 * is open — the WordPress cookie expires, or revoke_session_on_lms_logout()
 * ends it because the LMS cookies are gone — and the visitor refreshes. Instead
 * of a form that signs them in with a WordPress password that skips SSO, they
 * land on the home page, where the login button starts the LMS flow.
 *
 * Every account endpoint is covered, not just the dashboard: orders, payment
 * methods and the rest show the same form to a logged-out visitor. The
 * lost-password endpoint already goes to the home page (password-reset.php).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a logged-out visitor on My Account is sent.
 *
 * @return string
 */
function my_account_logged_out_redirect_url() {
	/**
	 * Filter where a logged-out visitor on My Account is sent.
	 *
	 * @param string $url Home page URL.
	 */
	return (string) apply_filters( 'tutor_sso_my_account_logged_out_redirect_url', home_url( '/' ) );
}

/**
 * Redirect a logged-out visitor away from My Account.
 *
 * @return void
 */
function my_account_redirect_logged_out() {
	if ( is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	wp_safe_redirect( my_account_logged_out_redirect_url() );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\my_account_redirect_logged_out' );
