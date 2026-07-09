<?php
/**
 * Bootstrap for the Programs REST API.
 *
 * Loads the controller and registers its routes on `rest_api_init`. Also shows
 * an admin notice when the JWT authentication plugin (which this endpoint
 * relies on for bearer-token auth) does not appear to be active.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Programs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-programs-rest-controller.php';

/**
 * Define JWT_AUTH_SECRET_KEY from the plugin setting when it has not already
 * been set in wp-config.php.
 *
 * wp-config.php always wins: a constant defined there is not stored in the
 * database and is therefore more secure, so we only fall back to the saved
 * option when the constant is absent. This runs at plugin-load time, well
 * before the JWT plugin reads the key on `rest_api_init`.
 */
function maybe_define_jwt_secret() {
	if ( defined( 'JWT_AUTH_SECRET_KEY' ) ) {
		return;
	}

	$secret = get_option( 'tutor_sso_jwt_auth_secret_key', '' );

	if ( is_string( $secret ) && '' !== $secret ) {
		define( 'JWT_AUTH_SECRET_KEY', $secret );
	}
}
maybe_define_jwt_secret();

/**
 * Register the programs REST routes.
 */
function register_programs_routes() {
	$controller = new Programs_REST_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_programs_routes' );

/**
 * Heuristically detect the "JWT Authentication for WP REST API" plugin.
 *
 * The two common distributions (tmeister/usefulteam) register their token
 * route under the `jwt-auth/v1` namespace, so its presence is a reliable
 * signal that JWT auth is available.
 *
 * @return bool
 */
function jwt_plugin_active() {
	$namespaces = rest_get_server()->get_namespaces();
	return in_array( 'jwt-auth/v1', $namespaces, true );
}

/**
 * Warn admins if the JWT auth plugin / secret key is missing, since the
 * programs endpoint cannot be authenticated without it.
 */
function maybe_show_jwt_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only nag on the Plugins screen and the SSO settings page.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_tutor-sso-settings' ), true ) ) {
		return;
	}

	$missing_secret = ! defined( 'JWT_AUTH_SECRET_KEY' );

	if ( ! $missing_secret ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_html__( 'Tutor LMS SSO — Programs API:', 'tutor-sso' ),
		esc_html__( 'The programs REST endpoint needs a JWT signing secret. Define JWT_AUTH_SECRET_KEY in wp-config.php, or set it under Settings → Tutor LMS SSO. Until then, requests cannot be authenticated.', 'tutor-sso' ),
		esc_url( admin_url( 'options-general.php?page=tutor-sso-settings' ) ),
		esc_html__( 'Open settings', 'tutor-sso' )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\maybe_show_jwt_notice' );
