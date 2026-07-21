<?php
/**
 * Serve the bundled archive template for the "program" custom post type.
 *
 * The `program` CPT (archive at /programs/) is registered elsewhere; this file
 * only supplies the archive *view*. On the program archive we load the plugin's
 * templates/archive-program.php, which hosts the [rwaq_programs] catalog.
 *
 * A theme may override the view by shipping its own archive-program.php — that
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
 * The custom post type whose archive renders the programs catalog.
 *
 * Filterable so a differently-named CPT can reuse the same archive view.
 *
 * @return string
 */
function programs_archive_post_type() {
	return (string) apply_filters( 'tutor_sso_programs_archive_post_type', 'program' );
}

/**
 * Point the program CPT archive at the plugin's template, unless the active theme
 * provides its own archive-program.php.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function programs_archive_template( $template ) {
	if ( ! is_post_type_archive( programs_archive_post_type() ) ) {
		return $template;
	}

	// Let a theme-provided archive-program.php win if one exists.
	$theme_template = locate_template( array( 'archive-program.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/archive-program.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\programs_archive_template' );
