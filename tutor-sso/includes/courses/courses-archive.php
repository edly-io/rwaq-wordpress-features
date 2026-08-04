<?php
/**
 * Serve the bundled archive template for the "course" custom post type.
 *
 * The `course` CPT (archive at /courses/) is registered elsewhere; this file
 * only supplies the archive *view*. On the course archive we load the plugin's
 * templates/archive-course.php, which hosts the [rwaq_courses] catalog.
 *
 * A theme may override the view by shipping its own archive-course.php — that
 * takes precedence (via locate_template), so the plugin template is only the
 * fallback.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The custom post type whose archive renders the courses catalog.
 *
 * Filterable so a differently-named CPT can reuse the same archive view.
 *
 * @return string
 */
function courses_archive_post_type() {
	return (string) apply_filters( 'tutor_sso_courses_archive_post_type', 'course' );
}

/**
 * Point the course CPT archive at the plugin's template, unless the active theme
 * provides its own archive-course.php.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function courses_archive_template( $template ) {
	if ( ! is_post_type_archive( courses_archive_post_type() ) ) {
		return $template;
	}

	// Let a theme-provided archive-course.php win if one exists.
	$theme_template = locate_template( array( 'archive-course.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/archive-course.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\courses_archive_template' );
