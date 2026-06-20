<?php
/**
 * [partner_logo] — outputs the course-partner term's ACF image
 * (field: partner_logo) for the current post. Built for use inside
 * an Elementor Loop Grid item.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the current post's course-partner logo.
 *
 * @return string <img> markup, or '' if unavailable.
 */
function partner_logo_shortcode() {
	// ACF not active → bail safely instead of fatal-erroring.
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

	$term  = $terms[0]; // first assigned course-partner term
	$image = \get_field( 'partner_logo', $term );
	if ( ! $image ) {
		return '';
	}

	$class = 'partner-logo';

	if ( \is_array( $image ) && ! empty( $image['ID'] ) ) {
		return \wp_get_attachment_image( $image['ID'], 'medium', false, array( 'class' => $class ) );
	} elseif ( \is_numeric( $image ) ) {
		return \wp_get_attachment_image( $image, 'medium', false, array( 'class' => $class ) );
	} elseif ( \is_string( $image ) ) {
		return '<img class="' . \esc_attr( $class ) . '" src="' . \esc_url( $image ) . '" alt="">';
	}

	return '';
}
add_shortcode( 'partner_logo', __NAMESPACE__ . '\\partner_logo_shortcode' );