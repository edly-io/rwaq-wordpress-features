<?php
/**
 * AJAX handler for the courses catalog (infinite scroll + search + sort + org
 * filter). Registered for logged-in and logged-out visitors; verifies a nonce.
 * Returns rendered card HTML for the requested page plus a `has_more` flag.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a possibly-array request value into a clean list of text values.
 *
 * @param mixed $value Raw request value.
 * @return string[]
 */
function courses_ajax_sanitize_list( $value ) {
	$value = is_array( $value ) ? $value : array( $value );
	$out   = array();

	foreach ( $value as $item ) {
		$item = sanitize_text_field( (string) $item );
		if ( '' !== $item ) {
			$out[] = $item;
		}
	}

	return $out;
}

/**
 * AJAX: return a page of course cards, optionally searched / sorted / filtered.
 */
function ajax_load_courses() {
	if ( ! check_ajax_referer( 'tutor_sso_courses', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	$page     = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
	$per_page = isset( $_GET['per_page'] ) ? max( 1, (int) $_GET['per_page'] ) : 9;
	$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$ordering = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';
	$org      = courses_ajax_sanitize_list( isset( $_GET['org'] ) ? wp_unslash( $_GET['org'] ) : array() );

	$per_page = min( $per_page, 48 );

	$data = courses_fetch(
		$page,
		$per_page,
		array(
			'search'   => $search,
			'ordering' => $ordering,
			'org'      => $org,
		)
	);

	$courses   = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];

	$switched = switch_to_locale( get_locale() );
	$html     = courses_render_cards( $courses );
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
add_action( 'wp_ajax_tutor_sso_load_courses', __NAMESPACE__ . '\\ajax_load_courses' );
add_action( 'wp_ajax_nopriv_tutor_sso_load_courses', __NAMESPACE__ . '\\ajax_load_courses' );
