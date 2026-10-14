<?php
/**
 * Course edit screen: lock the LMS-synced ACF fields and validate the prices.
 *
 * The pricing fields are owned by the LMS — it pushes them through the course
 * sync endpoint (see rest/class-courses-rest-controller.php), and every sync
 * overwrites whatever is in WordPress. Editing them in the admin therefore looks
 * like it works and silently reverts on the next sync, so they render read-only
 * with a note saying where the value comes from.
 *
 * Price validation runs on top of that, for sites that unlock the fields via
 * `tutor_sso_course_fields_editable`: a price must be a non-negative number and
 * a sale price may not exceed the regular one, or the save is blocked.
 *
 * Client behaviour lives in assets/js/course-admin-fields.js.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `course` post type these rules apply to.
 */
const COURSE_ADMIN_POST_TYPE = 'course';

/**
 * ACF fields rendered read-only on the course edit screen.
 *
 * Defaults to the pricing / program flags the LMS owns. Other synced fields are left
 * editable on purpose — some sites hand-curate them between syncs — so add them
 * here via the filter if that is not true for you. Every key of
 * Courses_REST_Controller::acf_field_map() is a candidate.
 *
 * @return string[] ACF field names.
 */
function course_admin_locked_fields() {
	/**
	 * Filter which ACF fields are locked on the course edit screen.
	 *
	 * @param string[] $fields ACF field names.
	 */
	return (array) apply_filters(
		'tutor_sso_course_locked_fields',
		array( 'is_paid', 'part_of_program', 'course_regular_price', 'course_sale_price' )
	);
}

/**
 * Price fields validated as numbers, in { regular, sale } roles so the client
 * can compare the two.
 *
 * @return array<string,string> role => ACF field name.
 */
function course_admin_price_fields() {
	/**
	 * Filter the course price fields and their roles.
	 *
	 * @param array<string,string> $fields role => ACF field name.
	 */
	return (array) apply_filters(
		'tutor_sso_course_price_fields',
		array(
			'regular' => 'course_regular_price',
			'sale'    => 'course_sale_price',
		)
	);
}

/**
 * Whether the locked fields should stay editable for the current user.
 *
 * Off by default: the LMS overwrites them on the next sync whoever edits them.
 * Filter to true (optionally gated on a capability) to hand them back, and the
 * price validation below becomes the guard rail.
 *
 * @return bool
 */
function course_admin_fields_editable() {
	/**
	 * Filter whether the LMS-owned course fields are editable in the admin.
	 *
	 * @param bool $editable Default false.
	 */
	return (bool) apply_filters( 'tutor_sso_course_fields_editable', false );
}

/**
 * Enqueue the edit-screen script on the course add / edit screens only.
 *
 * @param string $hook Current admin page hook.
 */
function course_admin_enqueue_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen || COURSE_ADMIN_POST_TYPE !== $screen->post_type ) {
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

	$editable = course_admin_fields_editable();

	wp_localize_script(
		'tutor-sso-course-admin-fields',
		'tutorSsoCourseAdmin',
		array(
			// Nothing is locked when the fields have been handed back.
			'lockedFields' => $editable ? array() : array_values( course_admin_locked_fields() ),
			'priceFields'  => course_admin_price_fields(),
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
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\course_admin_enqueue_assets' );
