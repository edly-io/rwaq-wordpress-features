<?php
/**
 * Public Programs API client.
 *
 * Thin wrappers around the LMS public programs catalog endpoints:
 *
 *   GET /rwaq/api/programs/public/          — paginated, filterable program list
 *   GET /rwaq/api/programs/public/filters/  — available filter options
 *
 * The endpoints are public (no authentication / cookies required), so unlike the
 * enrollment client (see includes/enrollment-api.php) we do not forward any edX
 * session cookies here. Responses are short-lived-cached in a transient to keep
 * catalog pages fast and avoid hammering the LMS.
 *
 * List response shape (DRF PageNumberPagination):
 *   {
 *     "results": [ { program_key, organization, name, card_image, program_type,
 *                    is_featured, org_logo?, total_courses?, ... }, ... ],
 *     "pagination": { "next": url|null, "previous": url|null, "count": int, "num_pages": int }
 *   }
 *
 * Supported list filters (each single-value on the API today, AND-combined):
 *   org=<name>            organization name (see filters endpoint)
 *   program_type=<SLUG>   MASTERS | MICROBACHELORS
 *   featured=true|false
 *   search=<term>
 *   ordering=name|-name|created|-created
 *
 * We already send org / program_type as repeated params (org=A&org=B) so the UI
 * can offer multi-select; the API currently honors only the last value per key,
 * and will "just work" once it adds `__in`-style filtering.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public programs list endpoint path (appended to the LMS base URL).
 */
const PROGRAMS_PUBLIC_ENDPOINT = '/rwaq/api/programs/public/';

/**
 * Public catalog filters endpoint path (appended to the LMS base URL).
 */
const PROGRAMS_FILTERS_ENDPOINT = '/rwaq/api/programs/public/filters/';

/**
 * Default cache lifetime, in seconds, for a fetched catalog page / filter set.
 */
const PROGRAMS_CACHE_TTL = 300; // 5 minutes.

/**
 * Resolve the configured LMS base URL (reuses the SSO setting).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function programs_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Whitelisted `ordering` values accepted by the catalog. Maps a friendly key to
 * the DRF ordering expression sent to the API. Guards against arbitrary field
 * ordering coming from the query string / AJAX.
 *
 * @return array<string,string>
 */
function programs_allowed_ordering() {
	return array(
		'name_asc'  => 'name',    // أ–ي
		'name_desc' => '-name',   // ي–أ
		'newest'    => '-created', // الأحدث أولًا
		'oldest'    => 'created',  // الأقدم أولًا
	);
}

/**
 * Normalize a requested ordering key into a DRF ordering expression.
 *
 * @param string $ordering Friendly key (e.g. "name_asc") or raw expression.
 * @return string DRF ordering expression, or '' when unrecognized.
 */
function programs_normalize_ordering( $ordering ) {
	$ordering = trim( (string) $ordering );

	if ( '' === $ordering ) {
		return '';
	}

	$allowed = programs_allowed_ordering();

	if ( isset( $allowed[ $ordering ] ) ) {
		return $allowed[ $ordering ];
	}

	// Also accept a raw expression if it's one of the mapped targets.
	return in_array( $ordering, $allowed, true ) ? $ordering : '';
}

/**
 * Serialize a query args map into a query string. Array values are joined into a
 * single comma-separated param (org=A,B) — the format the catalog API expects
 * for multi-select filters. Each value is URL-encoded; the joining commas are
 * left literal.
 *
 * @param array<string,mixed> $args Query args (scalars or arrays of scalars).
 * @return string Query string without a leading "?".
 */
function programs_build_query( $args ) {
	$parts = array();

	foreach ( $args as $key => $value ) {
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				$item = trim( (string) $item );
				if ( '' !== $item ) {
					$items[] = rawurlencode( $item );
				}
			}
			if ( ! empty( $items ) ) {
				$parts[] = rawurlencode( $key ) . '=' . implode( ',', $items );
			}
			continue;
		}

		$value = trim( (string) $value );
		if ( '' !== $value ) {
			$parts[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
	}

	return implode( '&', $parts );
}

/**
 * Fetch one page of published programs from the LMS public catalog API.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Programs per page (sent as the `page_size` query arg).
 * @param array $args     Optional {
 *     @type string   $search       Free-text search term.
 *     @type string   $ordering     Ordering key (see programs_allowed_ordering()).
 *     @type string[] $org          Organization names to filter by.
 *     @type string[] $program_type Program type slugs (MASTERS | MICROBACHELORS).
 *     @type string   $featured     'true' | 'false' | '' (no filter).
 * }
 * @return array|\WP_Error {
 *     @type array[] $results    Raw program objects from the API.
 *     @type array   $pagination { next, previous, count, num_pages }.
 * } or WP_Error on failure.
 */
function programs_fetch_public( $page = 1, $per_page = 6, $args = array() ) {
	$base = programs_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $per_page );

	$query = array(
		'page'      => $page,
		'page_size' => $per_page,
	);

	if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
		$query['search'] = trim( (string) $args['search'] );
	}

	$ordering = isset( $args['ordering'] ) ? programs_normalize_ordering( $args['ordering'] ) : '';
	if ( '' !== $ordering ) {
		$query['ordering'] = $ordering;
	}

	if ( ! empty( $args['org'] ) ) {
		$query['org'] = (array) $args['org'];
	}

	if ( ! empty( $args['program_type'] ) ) {
		$query['program_type'] = (array) $args['program_type'];
	}

	$featured = isset( $args['featured'] ) ? trim( (string) $args['featured'] ) : '';
	if ( 'true' === $featured || 'false' === $featured ) {
		$query['featured'] = $featured;
	}

	$url = $base . PROGRAMS_PUBLIC_ENDPOINT . '?' . programs_build_query( $query );

	$cache_key = 'tutor_sso_programs_' . md5( $url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	// DRF returns 404 ("Invalid page.") for a page past the last one. Treat that
	// as an empty result set rather than a hard error so the UI can degrade
	// gracefully (e.g. a stale ?program_page=99 left in the URL).
	if ( 404 === $status ) {
		return array(
			'results'    => array(),
			'pagination' => array(
				'next'      => null,
				'previous'  => null,
				'count'     => 0,
				'num_pages' => 0,
			),
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] programs list %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_programs_failed',
			__( 'Could not load programs from the LMS.', 'tutor-sso' )
		);
	}

	$data = array(
		'results'    => ( isset( $body['results'] ) && is_array( $body['results'] ) ) ? $body['results'] : array(),
		'pagination' => ( isset( $body['pagination'] ) && is_array( $body['pagination'] ) ) ? $body['pagination'] : array(),
	);

	set_transient( $cache_key, $data, apply_filters( 'tutor_sso_programs_cache_ttl', PROGRAMS_CACHE_TTL ) );

	return $data;
}

/**
 * Fetch the available catalog filter options.
 *
 * @return array|\WP_Error {
 *     @type array[] $organizations [ { id, name, short_name, arabic_name?, total_programs? }, ... ].
 *     @type array[] $program_types [ { id, name, slug, total_programs? }, ... ].
 *     @type array[] $featured      [ { label, total_programs }, ... ].
 * } or WP_Error on failure.
 */
function programs_fetch_filters() {
	$base = programs_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$url       = $base . PROGRAMS_FILTERS_ENDPOINT;
	$cache_key = 'tutor_sso_program_filters_' . md5( $url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		return new \WP_Error(
			'tutor_sso_filters_failed',
			__( 'Could not load catalog filters from the LMS.', 'tutor-sso' )
		);
	}

	$data = array(
		'organizations' => ( isset( $body['organizations'] ) && is_array( $body['organizations'] ) ) ? $body['organizations'] : array(),
		'program_types' => ( isset( $body['program_types'] ) && is_array( $body['program_types'] ) ) ? $body['program_types'] : array(),
		'featured'      => ( isset( $body['featured'] ) && is_array( $body['featured'] ) ) ? $body['featured'] : array(),
	);

	set_transient( $cache_key, $data, apply_filters( 'tutor_sso_programs_cache_ttl', PROGRAMS_CACHE_TTL ) );

	return $data;
}
