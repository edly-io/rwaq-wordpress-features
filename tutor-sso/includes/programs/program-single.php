<?php
/**
 * Single-program template loader.
 *
 * Attaches templates/single-program.php as the template for single
 * `program` posts, so the program detail view ([rwaq_program_detail]) is shown
 * by default on every program page — no page editing or theme work required.
 *
 * A theme can still override by providing its own single-program.php.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Program post type whose single view uses the plugin template.
 *
 * @return string
 */
function program_single_post_type() {
	/**
	 * Filter the post type served by the plugin's single-program template.
	 *
	 * @param string $post_type Default 'program'.
	 */
	return (string) apply_filters( 'tutor_sso_program_post_type', 'program' );
}

/**
 * Use the plugin's single-program.php for single `program` posts.
 *
 * Runs on the `single_template` filter, which fires with the template the
 * theme hierarchy resolved. We only intervene for the program post type, and
 * we defer to a theme-provided single-program.php when one exists.
 *
 * @param string $template Path to the template the hierarchy resolved.
 * @return string
 */
function program_single_template( $template ) {
	if ( ! is_singular( program_single_post_type() ) ) {
		return $template;
	}

	// Respect a theme-provided single-program.php if one exists.
	$theme_template = locate_template( array( 'single-program.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/single-program.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'single_template', __NAMESPACE__ . '\\program_single_template' );
