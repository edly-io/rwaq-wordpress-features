<?php
/**
 * Bootstrap for the Partners (organizations) REST API.
 *
 * Loads the controller and registers its routes on `rest_api_init`.
 * JWT authentication (and the JWT_AUTH_SECRET_KEY fallback) is handled by the
 * programs bootstrap, so it is not duplicated here.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Partners;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-partners-rest-controller.php';

/**
 * Register the partners REST routes.
 */
function register_partners_routes() {
	$controller = new Partners_REST_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_partners_routes' );
