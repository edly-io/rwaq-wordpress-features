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
 * session cookies here. Both calls go through programs_request(), which caches
 * the decoded body in a transient keyed to a shared TTL boundary (see
 * sso_cache_key()) so the filter counts and the listing always come from the
 * same snapshot — the same arrangement the courses client uses.
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
 * org / program_type are sent comma-joined (org=A,B), which the API treats as OR
 * (verified: org=Rwaq,Arbisoft returns 4+3 programs); repeated params would honor
 * only the last value.
 *
 * Internal organizations (name/short name starting with "test" — see
 * sso_hidden_org_prefixes()) are hidden from both the filter sidebar and the
 * results. The API has no "exclude" filter, so `org` is sent as an allowlist of
 * the visible organizations, which keeps `count` and pagination consistent with
 * what is rendered.
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
 * Transient key for a programs API response, aligned to a shared TTL boundary so
 * the list and filters caches always expire together (see sso_cache_key()).
 *
 * @param string $prefix Key prefix identifying the resource.
 * @param string $url    Request URL.
 * @return string
 */
function programs_cache_key( $prefix, $url ) {
	return sso_cache_key( $prefix, $url, (int) apply_filters( 'tutor_sso_programs_cache_ttl', PROGRAMS_CACHE_TTL ) );
}

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
 * The empty (but well-formed) list response: a page past the end, or a catalog
 * with nothing visible.
 *
 * @return array{results:array[],pagination:array}
 */
function programs_empty_response() {
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

/**
 * Filters-API organizations minus the internal ones (see sso_is_hidden_org() in
 * sso-functions.php — the same rule the courses catalog applies).
 *
 * @return array[]|\WP_Error Visible organization objects, or the fetch error.
 */
function programs_visible_orgs() {
	$filters = programs_fetch_filters();

	if ( is_wp_error( $filters ) ) {
		return $filters;
	}

	$visible = array();

	foreach ( $filters['organizations'] as $org ) {
		if ( is_array( $org ) && ! sso_is_hidden_org( $org ) ) {
			$visible[] = $org;
		}
	}

	return $visible;
}

/**
 * Turn the requested `org` filter into an allowlist of visible organizations, so
 * internal ones are excluded from the results (the API has no "exclude" filter).
 *
 * Passing an allowlist rather than dropping rows afterwards keeps `count` and
 * pagination honest. When the organization list cannot be determined — the
 * endpoint errored, or answered without a usable `organizations` array — the args
 * are returned untouched, since a catalog that briefly shows an internal
 * organization beats one that renders empty because of an unrelated API change.
 *
 * @param array $args Fetch args (see programs_fetch_public()).
 * @return array|null Args to query with, or null when nothing is visible.
 */
function programs_restrict_org_args( $args ) {
	$filters = programs_fetch_filters();
	$known   = ( ! is_wp_error( $filters ) && ! empty( $filters['organizations'] ) );

	if ( ! $known ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[tutor-sso] program organizations unavailable; internal organizations are not being excluded'
			);
		}

		return $args;
	}

	$allowed = array();
	foreach ( programs_visible_orgs() as $org ) {
		$value = programs_org_value( $org );
		if ( '' !== $value ) {
			$allowed[] = $value;
		}
	}

	if ( empty( $allowed ) ) {
		return null; // The list was readable and every organization is hidden.
	}

	$requested = isset( $args['org'] ) ? array_filter( array_map( 'trim', array_map( 'strval', (array) $args['org'] ) ) ) : array();

	if ( empty( $requested ) ) {
		$args['org'] = $allowed;

		return $args;
	}

	// Keep only requested organizations that are visible (case-insensitive, since
	// the value may arrive from a hand-built request).
	$lookup = array();
	foreach ( $allowed as $value ) {
		$lookup[ strtolower( $value ) ] = $value;
	}

	$keep = array();
	foreach ( $requested as $value ) {
		$key = strtolower( $value );
		if ( isset( $lookup[ $key ] ) ) {
			$keep[] = $lookup[ $key ];
		}
	}

	if ( empty( $keep ) ) {
		return null; // Only hidden organizations were asked for.
	}

	$args['org'] = $keep;

	return $args;
}

/**
 * GET a URL on the LMS public API and decode the JSON body, caching the decoded
 * response under $cache_key (mirrors courses_request()).
 *
 * A 404 comes back as a WP_Error with the code `tutor_sso_not_found` so callers
 * can decide what it means: for the list endpoint DRF uses it for "page past the
 * last one" (degrade to an empty page), while on the filters endpoint it signals
 * a misconfigured URL and should surface as an error.
 *
 * Failures are never cached, so a broken API is retried on the next page load
 * instead of being pinned for the whole TTL.
 *
 * @param string $url       Absolute request URL.
 * @param string $cache_key Transient key for the decoded body.
 * @param string $label     Short label used in the debug log line.
 * @return array|\WP_Error Decoded body, or WP_Error on failure.
 */
function programs_request( $url, $cache_key, $label = 'request' ) {
	$cached = get_transient( $cache_key );

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

	if ( 404 === $status ) {
		return new \WP_Error( 'tutor_sso_not_found', __( 'Not found.', 'tutor-sso' ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] programs %s %s -> HTTP %d', $label, $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_programs_failed',
			__( 'Could not load programs from the LMS.', 'tutor-sso' )
		);
	}

	set_transient( $cache_key, $body, (int) apply_filters( 'tutor_sso_programs_cache_ttl', PROGRAMS_CACHE_TTL ) );

	return $body;
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

	// Internal organizations are excluded by turning `org` into an allowlist of
	// visible ones; null means nothing is visible, so skip the request.
	$args = programs_restrict_org_args( $args );
	if ( null === $args ) {
		return programs_empty_response();
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

	$body = programs_request( $url, programs_cache_key( 'tutor_sso_programs_', $url ), 'list' );

	if ( is_wp_error( $body ) ) {
		// DRF answers 404 ("Invalid page.") for a page past the last one; degrade
		// to an empty page so a stale ?program_page=99 does not error out.
		return 'tutor_sso_not_found' === $body->get_error_code() ? programs_empty_response() : $body;
	}

	return array(
		'results'    => ( isset( $body['results'] ) && is_array( $body['results'] ) ) ? $body['results'] : array(),
		'pagination' => ( isset( $body['pagination'] ) && is_array( $body['pagination'] ) ) ? $body['pagination'] : array(),
	);
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

	$url  = $base . PROGRAMS_FILTERS_ENDPOINT;
	$body = programs_request( $url, programs_cache_key( 'tutor_sso_program_filters_', $url ), 'filters' );

	if ( is_wp_error( $body ) ) {
		return new \WP_Error(
			'tutor_sso_filters_failed',
			__( 'Could not load catalog filters from the LMS.', 'tutor-sso' )
		);
	}

	return array(
		'organizations' => ( isset( $body['organizations'] ) && is_array( $body['organizations'] ) ) ? $body['organizations'] : array(),
		'program_types' => ( isset( $body['program_types'] ) && is_array( $body['program_types'] ) ) ? $body['program_types'] : array(),
		'featured'      => ( isset( $body['featured'] ) && is_array( $body['featured'] ) ) ? $body['featured'] : array(),
	);
}
