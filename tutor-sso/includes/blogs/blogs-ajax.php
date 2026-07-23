<?php
/**
 * AJAX handler for the blogs listing (infinite scroll + search + sort + filters).
 *
 * Public listing, so registered for both logged-in (wp_ajax_) and logged-out
 * (wp_ajax_nopriv_) visitors; still verifies a nonce to keep requests
 * same-origin. Returns the rendered card HTML for the requested page plus a
 * `has_more` flag the JS uses to decide whether to keep loading.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize the incoming `tax` map ({ taxonomy => slug[] }) from the request.
 *
 * @param mixed $raw Raw request value.
 * @return array<string,string[]>
 */
function blogs_ajax_sanitize_tax( $raw ) {
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();

	foreach ( $raw as $taxonomy => $slugs ) {
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy ) {
			continue;
		}

		$clean = array();
		foreach ( (array) $slugs as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' !== $slug ) {
				$clean[] = $slug;
			}
		}

		if ( ! empty( $clean ) ) {
			$out[ $taxonomy ] = $clean;
		}
	}

	return $out;
}

/**
 * AJAX: return a page of post cards, optionally filtered / sorted.
 */
function ajax_load_blogs() {
	if ( ! check_ajax_referer( 'tutor_sso_blogs', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	$page      = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
	$per_page  = isset( $_GET['per_page'] ) ? max( 1, (int) $_GET['per_page'] ) : 9;
	$search    = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$ordering  = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';
	$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
	$badge_tax = isset( $_GET['badge_tax'] ) ? sanitize_key( wp_unslash( $_GET['badge_tax'] ) ) : '';
	$featured  = ! empty( $_GET['featured'] );

	// Taxonomy filters arrive as tax[<taxonomy>][]=<slug>.
	$tax = blogs_ajax_sanitize_tax( isset( $_GET['tax'] ) ? wp_unslash( $_GET['tax'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// Clamp per_page to a sane ceiling so a crafted request can't ask for an
	// unbounded page size.
	$per_page = min( $per_page, 48 );

	$data = blogs_fetch(
		$page,
		$per_page,
		array(
			'search'    => $search,
			'ordering'  => $ordering,
			'post_type' => $post_type,
			'tax'       => $tax,
			'featured'  => $featured,
		)
	);

	$posts     = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];

	// admin-ajax runs in the admin context, so date_i18n() / translations would
	// otherwise use the admin user's locale instead of the site locale used for
	// the initial front-end render. Switch to the site locale so dates (Arabic
	// month names) and labels match the server-rendered cards.
	$switched = switch_to_locale( get_locale() );
	$html     = blogs_render_cards(
		$posts,
		array( 'badge_tax' => $badge_tax )
	);
	if ( $switched ) {
		restore_previous_locale();
	}

	wp_send_json_success(
		array(
			'html'      => $html,
			'page'      => $page,
			'has_more'  => $page < $num_pages,
			'count'     => $total,
			'num_pages' => $num_pages,
		)
	);
}
add_action( 'wp_ajax_tutor_sso_load_blogs', __NAMESPACE__ . '\\ajax_load_blogs' );
add_action( 'wp_ajax_nopriv_tutor_sso_load_blogs', __NAMESPACE__ . '\\ajax_load_blogs' );
