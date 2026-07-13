<?php
/**
 * Bootstrap for the Courses REST API.
 *
 * Loads the controller and registers its routes on `rest_api_init`.
 * JWT authentication (and the JWT_AUTH_SECRET_KEY fallback) is handled by the
 * programs bootstrap, so it is not duplicated here.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Courses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-courses-rest-controller.php';

/**
 * Register the courses REST routes.
 */
function register_courses_routes() {
	$controller = new Courses_REST_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_courses_routes' );
