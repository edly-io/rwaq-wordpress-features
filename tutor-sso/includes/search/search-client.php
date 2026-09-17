<?php
/**
 * Public Search API client.
 *
 * Thin wrapper around the LMS unified search endpoint:
 *
 *   GET /rwaq/api/programs/public/search/?q=…
 *
 * search_fetch() maps the raw rows onto the shapes the existing catalog card
 * renderers already consume, so the result grids are the catalogs' own cards.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public search endpoint path (appended to the LMS base URL).
 */
const SEARCH_PUBLIC_ENDPOINT = '/rwaq/api/programs/public/search/';

/**
 * Default cache lifetime, in seconds, for a search response.
 */
const SEARCH_CACHE_TTL = 300; // 5 minutes.

/**
 * Resolve the configured LMS base URL (reuses the SSO setting).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function search_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Whitelisted `ordering` values: friendly sort key => API ordering expression.
 *
 * @return array<string,string>
 */
function search_allowed_ordering() {
	return array(
		'newest' => '-created',
		'oldest' => 'created',
	);
}

/**
 * Sort options for the toolbar: key => label.
 *
 * @return array<string,string>
 */
function search_sort_options() {
	return array(
		'newest' => __( 'الأحدث أولًا', 'tutor-sso' ),
		'oldest' => __( 'الأقدم أولًا', 'tutor-sso' ),
	);
}

/**
 * Default sort key — newest first, which is also the endpoint's own default.
 *
 * @return string
 */
function search_default_sort() {
	return 'newest';
}

/**
 * Normalize a requested ordering key into an API ordering expression.
 *
 * @param string $ordering Friendly key, or a raw expression.
 * @return string Expression, or '' when unrecognized.
 */
function search_normalize_ordering( $ordering ) {
	$ordering = trim( (string) $ordering );

	if ( '' === $ordering ) {
		return '';
	}

	$allowed = search_allowed_ordering();

	if ( isset( $allowed[ $ordering ] ) ) {
		return $allowed[ $ordering ];
	}

	return in_array( $ordering, $allowed, true ) ? $ordering : '';
}

/**
 * How many results per type the page shows.
 *
 *
 * @return int
 */
function search_default_per_page() {
	$value = (int) apply_filters( 'tutor_sso_search_per_page', 12 );

	return min( max( 1, $value ), 48 );
}

/**
 * Transient key for a search response, aligned to a shared TTL boundary.
 *
 * @param string $url Request URL.
 * @return string
 */
function search_cache_key( $url ) {
	return sso_cache_key(
		'tutor_sso_search_',
		$url,
		(int) apply_filters( 'tutor_sso_search_cache_ttl', SEARCH_CACHE_TTL )
	);
}

/**
 * GET the search endpoint and decode the JSON body.
 *
 * @param string $url       Absolute request URL.
 * @param string $cache_key Transient key for the decoded body.
 * @return array|\WP_Error Decoded body, or WP_Error on failure.
 */
function search_request( $url, $cache_key ) {
	$cached = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] search %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_search_failed',
			__( 'تعذّر تنفيذ البحث. يرجى المحاولة مرة أخرى.', 'tutor-sso' )
		);
	}

	set_transient(
		$cache_key,
		$body,
		(int) apply_filters( 'tutor_sso_search_cache_ttl', SEARCH_CACHE_TTL )
	);

	return $body;
}

/**
 * Run a search and map the results onto the card shapes.
 *
 * `tutor_sso_search_results` is a short-circuit seam — return an array from it
 * to bypass the request entirely.
 *
 * @param string $query    Search term.
 * @param array  $args     { ordering, page, per_page }.
 * @return array{
 *     query:string, courses:array[], programs:array[], courses_count:int,
 *     programs_count:int, total:int, error:string
 * }
 */
function search_fetch( $query, $args = array() ) {
	$query = trim( (string) $query );

	$empty = array(
		'query'          => $query,
		'courses'        => array(),
		'programs'       => array(),
		'courses_count'  => 0,
		'programs_count' => 0,
		'total'          => 0,
		'error'          => '',
	);

	$external = apply_filters( 'tutor_sso_search_results', null, $query, $args );
	if ( is_array( $external ) ) {
		return wp_parse_args( $external, $empty );
	}

	if ( '' === $query ) {
		return $empty;
	}

	$base = search_lms_base_url();

	if ( '' === $base ) {
		$empty['error'] = __( 'LMS Base URL is not configured.', 'tutor-sso' );
		return $empty;
	}

	$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : search_default_per_page();
	$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

	$params = array(
		'q'         => $query,
		'page'      => $page,
		'page_size' => max( 1, $per_page ),
	);

	$ordering = isset( $args['ordering'] ) ? search_normalize_ordering( $args['ordering'] ) : '';
	if ( '' !== $ordering ) {
		$params['ordering'] = $ordering;
	}

	// courses_build_query() handles the encoding these catalog endpoints expect.
	$url  = $base . SEARCH_PUBLIC_ENDPOINT . '?' . courses_build_query( $params );
	$body = search_request( $url, search_cache_key( $url ) );

	if ( is_wp_error( $body ) ) {
		$empty['error'] = $body->get_error_message();
		return $empty;
	}

	$courses  = isset( $body['courses'] ) && is_array( $body['courses'] ) ? $body['courses'] : array();
	$programs = isset( $body['programs'] ) && is_array( $body['programs'] ) ? $body['programs'] : array();

	$courses_count  = isset( $body['courses_count'] ) ? (int) $body['courses_count'] : count( $courses );
	$programs_count = isset( $body['programs_count'] ) ? (int) $body['programs_count'] : count( $programs );

	$data = array(
		'query'          => $query,
		'courses'        => array_map( __NAMESPACE__ . '\\courses_normalize_course', $courses ),
		'programs'       => array_values( $programs ),
		'courses_count'  => $courses_count,
		'programs_count' => $programs_count,
		'total'          => $courses_count + $programs_count,
		'error'          => '',
	);

	/**
	 * Filter the mapped search results.
	 *
	 * @param array  $data  Mapped results.
	 * @param array  $body  Raw API response.
	 * @param string $query Search term.
	 */
	return (array) apply_filters( 'tutor_sso_search_data', $data, $body, $query );
}
