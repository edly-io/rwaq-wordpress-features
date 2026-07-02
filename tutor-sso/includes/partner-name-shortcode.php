<?php
/**
 * [partner_name] — outputs the course-partner term's ACF text field
 * (field: partner_name) for the current post. Built for use inside
 * an Elementor Loop Grid item.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the current post's course-partner name.
 *
 * @return string Escaped partner name, or '' if unavailable.
 */
function partner_name_shortcode() {
	if ( ! \function_exists( 'get_field' ) ) {
		return '';
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	$terms = \get_the_terms( $post_id, 'course-partner' );
	if ( ! $terms || \is_wp_error( $terms ) ) {
		return '';
	}

	$term = $terms[0]; // first assigned course-partner term
	$name = \get_field( 'partner_name', $term );
	if ( ! $name || ! \is_string( $name ) ) {
		return '';
	}

	return '<span class="partner-name">' . \esc_html( $name ) . '</span>';
}
add_shortcode( 'partner_name', __NAMESPACE__ . '\\partner_name_shortcode' );