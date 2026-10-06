<?php
/**
 * Program edit screen: lock the LMS-synced pricing fields and validate them.
 *
 * Same rules and the same client script as the course screen (see
 * courses-admin-fields.php) — only the post type and the field names differ, so
 * this registers the config rather than repeating the behaviour.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `program` post type these rules apply to.
 */
const PROGRAM_ADMIN_POST_TYPE = 'program';

/**
 * ACF fields rendered read-only on the program edit screen.
 *
 * @return string[] ACF field names.
 */
function program_admin_locked_fields() {
	/**
	 * Filter which ACF fields are locked on the program edit screen.
	 *
	 * @param string[] $fields ACF field names.
	 */
	return (array) apply_filters(
		'tutor_sso_program_locked_fields',
		array( 'is_paid', 'program_regular_price', 'program_sale_price' )
	);
}

/**
 * Whether the locked fields stay editable for the current user.
 *
 * @return bool
 */
function program_admin_fields_editable() {
	/**
	 * Filter whether the LMS-owned program fields are editable in the admin.
	 *
	 * @param bool $editable Default false.
	 */
	return (bool) apply_filters( 'tutor_sso_program_fields_editable', false );
}

/**
 * Enqueue the shared edit-screen script on the program add / edit screens.
 *
 * @param string $hook Current admin page hook.
 */
function program_admin_enqueue_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || PROGRAM_ADMIN_POST_TYPE !== $screen->post_type ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-course-admin-fields',
		TUTOR_SSO_URL . 'assets/css/course-admin-fields.css',
		array(),
		TUTOR_SSO_VERSION
	);

	wp_enqueue_script(
		'tutor-sso-course-admin-fields',
		TUTOR_SSO_URL . 'assets/js/course-admin-fields.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);

	$editable = program_admin_fields_editable();

	// The same global the course screen uses; only one of the two is ever loaded.
	wp_localize_script(
		'tutor-sso-course-admin-fields',
		'tutorSsoCourseAdmin',
		array(
			'lockedFields' => $editable ? array() : array_values( program_admin_locked_fields() ),
			'priceFields'  => array(
				'regular' => 'program_regular_price',
				'sale'    => 'program_sale_price',
			),
			'i18n'         => array(
				'lockedNote'    => __( 'Synced from the LMS — edit it there, not here.', 'tutor-sso' ),
				'invalidPrice'  => __( 'Enter a price as a number, e.g. 499 or 399.50.', 'tutor-sso' ),
				'negativePrice' => __( 'A price cannot be negative.', 'tutor-sso' ),
				'saleTooHigh'   => __( 'The sale price cannot be higher than the regular price.', 'tutor-sso' ),
				'fixErrors'     => __( 'Please correct the highlighted prices before updating.', 'tutor-sso' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\program_admin_enqueue_assets' );
