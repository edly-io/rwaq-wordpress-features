<?php
/**
 * AJAX handler for the programs catalog (infinite scroll + search + sort).
 *
 * The programs endpoint is public, so this handler is registered for both
 * logged-in (wp_ajax_) and logged-out (wp_ajax_nopriv_) visitors. It still
 * verifies a nonce to keep requests same-origin. It returns the rendered card
 * HTML for the requested page plus a `has_more` flag the JS uses to decide
 * whether to keep loading.
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
 * @param mixed $value Raw request value (array or scalar).
 * @return string[]
 */
function programs_ajax_sanitize_list( $value ) {
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
 * AJAX: return a page of program cards, optionally filtered / sorted.
 */
function ajax_load_programs() {
	if ( ! check_ajax_referer( 'tutor_sso_programs', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	$page        = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
	$per_page    = isset( $_GET['per_page'] ) ? max( 1, (int) $_GET['per_page'] ) : 6;
	$search      = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	$ordering    = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';
	$detail_base = isset( $_GET['detail_base'] ) ? sanitize_text_field( wp_unslash( $_GET['detail_base'] ) ) : 'program';
	$featured    = isset( $_GET['featured'] ) ? sanitize_text_field( wp_unslash( $_GET['featured'] ) ) : '';

	// Multi-value filters arrive as org[]=… / program_type[]=… arrays.
	$org          = programs_ajax_sanitize_list( isset( $_GET['org'] ) ? wp_unslash( $_GET['org'] ) : array() );
	$program_type = programs_ajax_sanitize_list( isset( $_GET['program_type'] ) ? wp_unslash( $_GET['program_type'] ) : array() );

	// Clamp per_page to a sane ceiling so a crafted request can't ask the LMS
	// for an unbounded page size.
	$per_page = min( $per_page, 48 );

	$data = programs_fetch_public(
		$page,
		$per_page,
		array(
			'search'       => $search,
			'ordering'     => $ordering,
			'org'          => $org,
			'program_type' => $program_type,
			'featured'     => $featured,
		)
	);

	if ( is_wp_error( $data ) ) {
		wp_send_json_error( array( 'message' => $data->get_error_message() ), 502 );
	}

	$programs  = $data['results'];
	$total     = isset( $data['pagination']['count'] ) ? (int) $data['pagination']['count'] : count( $programs );
	$num_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

	// admin-ajax runs in the admin context, so date_i18n() / translations would
	// otherwise use the admin user's locale (e.g. English) instead of the site
	// locale used for the initial front-end render. Switch to the site locale so
	// dates (Arabic month names) and labels match the server-rendered cards.
	$switched = switch_to_locale( get_locale() );
	$html     = programs_render_cards( $programs, $detail_base );
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
add_action( 'wp_ajax_tutor_sso_load_programs', __NAMESPACE__ . '\\ajax_load_programs' );
add_action( 'wp_ajax_nopriv_tutor_sso_load_programs', __NAMESPACE__ . '\\ajax_load_programs' );
