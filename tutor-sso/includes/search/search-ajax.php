<?php
/**
 * AJAX handler for search infinite scroll. Returns one page of rendered cards
 * for a single result type, plus a has_more flag.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: return a page of search result cards for one type.
 */
function ajax_load_search() {
	if ( ! check_ajax_referer( 'tutor_sso_search', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	$query    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'courses';
	$page     = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
	$per_page = isset( $_GET['per_page'] ) ? max( 1, (int) $_GET['per_page'] ) : search_default_per_page();
	$ordering = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';

	if ( ! in_array( $type, array( 'courses', 'programs' ), true ) ) {
		$type = 'courses';
	}

	$per_page = min( $per_page, 48 );

	$data = search_fetch(
		$query,
		array(
			'ordering' => $ordering,
			'page'     => $page,
			'per_page' => $per_page,
		)
	);

	if ( ! empty( $data['error'] ) ) {
		wp_send_json_error( array( 'message' => $data['error'] ), 502 );
	}

	$rows  = 'programs' === $type ? $data['programs'] : $data['courses'];
	$total = 'programs' === $type ? (int) $data['programs_count'] : (int) $data['courses_count'];


	$switched = switch_to_locale( get_locale() );
	$html     = '';
	foreach ( $rows as $row ) {
		$card = 'programs' === $type ? programs_render_card( $row, 'program' ) : courses_render_card( $row );
		$html .= '<div class="rwaq-search__cell">' . $card . '</div>';
	}
	if ( $switched ) {
		restore_previous_locale();
	}

	wp_send_json_success(
		array(
			'html'     => $html,
			'page'     => $page,
			'has_more' => ( $page * $per_page ) < $total,
			'count'    => $total,
		)
	);
}
add_action( 'wp_ajax_tutor_sso_load_search', __NAMESPACE__ . '\\ajax_load_search' );
add_action( 'wp_ajax_nopriv_tutor_sso_load_search', __NAMESPACE__ . '\\ajax_load_search' );
