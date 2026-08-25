<?php
/**
 * AJAX handler for the partners catalog (infinite scroll + search + sort).
 * Registered for logged-in and logged-out visitors; verifies a
 * nonce. Returns rendered card HTML for the requested page plus a `has_more`
 * flag — the same contract as the courses catalog.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: return a page of partner cards, optionally searched and sorted.
 */
function ajax_load_partners() {
	if ( ! check_ajax_referer( 'tutor_sso_partners', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	$page     = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
	$per_page = isset( $_GET['per_page'] ) ? max( 1, (int) $_GET['per_page'] ) : partners_default_per_page();
	$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$ordering = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';

	$per_page = min( $per_page, 96 );

	$data = partners_fetch(
		$page,
		$per_page,
		array(
			'search'   => $search,
			'ordering' => $ordering,
		)
	);

	if ( ! empty( $data['error'] ) ) {
		wp_send_json_error(
			array( 'message' => __( 'حدث خطأ أثناء تحميل الشركاء. يرجى المحاولة مرة أخرى.', 'tutor-sso' ) ),
			502
		);
	}

	$partners  = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];

	$switched = switch_to_locale( get_locale() );
	$html     = partners_render_cards( $partners );
	$count    = partners_count_text( $total );
	if ( $switched ) {
		restore_previous_locale();
	}

	wp_send_json_success(
		array(
			'html'       => $html,
			'page'       => $page,
			'has_more'   => $page < $num_pages,
			'count'      => $total,
			'countText'  => $count,
			'num_pages'  => $num_pages,
		)
	);
}
add_action( 'wp_ajax_tutor_sso_load_partners', __NAMESPACE__ . '\\ajax_load_partners' );
add_action( 'wp_ajax_nopriv_tutor_sso_load_partners', __NAMESPACE__ . '\\ajax_load_partners' );
