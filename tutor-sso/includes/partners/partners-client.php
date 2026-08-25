<?php
/**
 * Public Organizations (Partners) API client + catalog data layer.
 *
 * Thin wrapper around the LMS public organizations endpoint:
 *
 *   GET /rwaq/api/organizations/public/
 *
 * The endpoint is public (no authentication / cookies required), so like the
 * courses and programs clients we do not forward any edX session cookies.
 * Responses are short-lived-cached in a transient to keep the catalog fast and
 * avoid hammering the LMS.
 *
 * List response shape (verified against the stage API). Note that, unlike the
 * courses list, the pagination block is NESTED rather than top-level, and there
 * is no `active` field:
 *   {
 *     "results": [ { id, name, short_name, arabic_name, logo, created }, ... ],
 *     "pagination": { next, previous, count, num_pages }
 *   }
 *
 * partners_pagination() reads either shape, so a response that moves the keys
 * back to the top level keeps working.
 *
 * partners_fetch() maps those rows onto the flat card shape the catalog and the
 * AJAX handler consume — only what the card actually renders:
 *   { id, name, subtitle, logo, url }
 *
 * The card shows `arabic_name` as its title and `short_name` underneath. Only one
 * organization on stage has `arabic_name` set, so the title falls back to `name`
 * then `short_name` — a card is never nameless.
 *
 * `url` points at the local partner detail page ({site}/partner/{slug}/), built
 * from the API slug — see partner_detail_url().
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public organizations list endpoint path (appended to the LMS base URL).
 */
const PARTNERS_PUBLIC_ENDPOINT = '/rwaq/api/organizations/public/';

/**
 * Default cache lifetime, in seconds, for a fetched catalog page.
 */
const PARTNERS_CACHE_TTL = 300; // 5 minutes.

/**
 * Resolve the configured LMS base URL (reuses the SSO setting, as the courses
 * and programs clients do — all of these live on the same host).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function partners_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Transient key for a partners API response, aligned to a shared TTL boundary
 * (see sso_cache_key()).
 *
 * @param string $url Request URL.
 * @return string
 */
function partners_cache_key( $url ) {
	return sso_cache_key(
		'tutor_sso_partners_',
		$url,
		(int) apply_filters( 'tutor_sso_partners_cache_ttl', PARTNERS_CACHE_TTL )
	);
}

/**
 * Whitelisted `ordering` values: friendly sort key => DRF ordering expression.
 *
 * `name` is the only field this endpoint's OrderingFilter honours — verified:
 * `ordering=arabic_name` returns the default order unchanged, because DRF drops
 * unknown fields silently. So the sort is on the Latin name even though the
 * cards prefer the Arabic one, which is the closest correct behaviour available
 * until `arabic_name` is added to the endpoint's `ordering_fields`.
 *
 * Filterable so the field can be swapped without touching the catalog.
 *
 * @return array<string,string>
 */
function partners_allowed_ordering() {
	$field = (string) apply_filters( 'tutor_sso_partners_ordering_field', 'name' );

	return array(
		'name_asc'  => $field,        // أ–ي
		'name_desc' => '-' . $field,  // ي–أ
	);
}

/**
 * Normalize a requested ordering key into a DRF ordering expression.
 *
 * @param string $ordering Friendly key (e.g. "name_asc") or raw expression.
 * @return string DRF ordering expression, or '' when unrecognized.
 */
function partners_normalize_ordering( $ordering ) {
	$ordering = trim( (string) $ordering );

	if ( '' === $ordering ) {
		return '';
	}

	$allowed = partners_allowed_ordering();

	if ( isset( $allowed[ $ordering ] ) ) {
		return $allowed[ $ordering ];
	}

	// Also accept a raw expression if it is one of the mapped targets, so an
	// arbitrary field cannot be injected from the query string / AJAX.
	return in_array( $ordering, $allowed, true ) ? $ordering : '';
}

/**
 * GET a URL on the LMS public API and decode the JSON body.
 *
 * @param string $url       Absolute request URL.
 * @param string $cache_key Transient key for the decoded body.
 * @return array|\WP_Error Decoded body, or WP_Error on failure.
 */
function partners_request( $url, $cache_key ) {
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

	// DRF returns 404 ("Invalid page.") for a page past the last one. Treat that
	// as an empty result set rather than a hard error, so a stale page number in
	// a request degrades quietly.
	if ( 404 === $status ) {
		return array(
			'count'    => 0,
			'next'     => null,
			'previous' => null,
			'results'  => array(),
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] partners list %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_partners_failed',
			__( 'Could not load partners from the LMS.', 'tutor-sso' )
		);
	}

	set_transient(
		$cache_key,
		$body,
		(int) apply_filters( 'tutor_sso_partners_cache_ttl', PARTNERS_CACHE_TTL )
	);

	return $body;
}

/**
 * Fetch one raw page of organizations.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Rows per page.
 * @param array $args     { search, ordering }.
 * @return array|\WP_Error Raw decoded body, or WP_Error on failure.
 */
function partners_fetch_public( $page = 1, $per_page = 24, $args = array() ) {
	$base = partners_lms_base_url();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$query = array(
		'page'      => max( 1, (int) $page ),
		'page_size' => max( 1, (int) $per_page ),
	);

	if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
		$query['search'] = trim( (string) $args['search'] );
	}

	$ordering = isset( $args['ordering'] ) ? partners_normalize_ordering( $args['ordering'] ) : '';
	if ( '' !== $ordering ) {
		$query['ordering'] = $ordering;
	}

	// courses_build_query() joins array values into one comma-separated param,
	// which is the format these catalog endpoints expect for multi-select
	// filters. Reused rather than duplicated.
	$url = $base . PARTNERS_PUBLIC_ENDPOINT . '?' . courses_build_query( $query );

	return partners_request( $url, partners_cache_key( $url ) );
}

/**
 * Path segment the partner detail pages live under, i.e. /{base}/{slug}/.
 *
 * Filterable so a site using a different permalink base for the `partner` post
 * type can point the catalog at it. Mirrors courses_detail_base().
 *
 * @return string
 */
function partners_detail_base() {
	return (string) apply_filters( 'tutor_sso_partner_detail_base', 'partner' );
}

/**
 * Resolve the link for a partner card: the local `partner` post detail page,
 * built from the API `slug` as {site}/partner/{slug}/.
 *
 * The slug is lowercased to match the WordPress post_name, which WP lowercases
 * on save. A row with no slug yields '' and the card renders as a plain,
 * non-clickable tile rather than linking somewhere broken.
 *
 * @param array $row Raw organization row.
 * @return string URL, or ''.
 */
function partner_detail_url( $row ) {
	$slug = isset( $row['slug'] ) ? trim( (string) $row['slug'] ) : '';
	$url  = '';

	if ( '' !== $slug ) {
		$base = trim( partners_detail_base(), '/' );
		$path = '/' . ( '' !== $base ? $base . '/' : '' ) . rawurlencode( strtolower( $slug ) ) . '/';
		$url  = home_url( $path );
	}

	return (string) apply_filters( 'tutor_sso_partner_url', $url, $row );
}

/**
 * Map a raw organization row onto the flat shape the card renderer consumes.
 *
 * @param array $row Raw organization row.
 * @return array
 */
function partners_normalize_partner( $row ) {
	// The Arabic name is what the design shows; the Latin name is the fallback,
	// then the short code, so a card is never nameless.
	$name = '';
	foreach ( array( 'arabic_name', 'name', 'short_name' ) as $key ) {
		if ( ! empty( $row[ $key ] ) ) {
			$name = trim( (string) $row[ $key ] );
			break;
		}
	}

	$short = isset( $row['short_name'] ) ? trim( (string) $row['short_name'] ) : '';

	$partner = array(
		'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
		'name'     => $name,
		// The card's second line. Suppressed when it would only repeat the title,
		// which happens whenever `arabic_name` is unset and the title fell back to
		// the short name.
		'subtitle' => $short !== $name ? $short : '',
		'logo'     => isset( $row['logo'] ) ? trim( (string) $row['logo'] ) : '',
		'url'      => partner_detail_url( $row ),
	);

	return apply_filters( 'tutor_sso_partners_card_data', $partner, $row );
}

/**
 * Whether a raw row should be shown.
 *
 * Drops explicitly inactive organizations and internal ones (see
 * sso_hidden_org_prefixes(), which the courses and programs catalogs use — the
 * rows already carry the { short_name, name } shape it reads).
 *
 * `active` is only honoured when present — the stage endpoint does not return
 * it, so today this comes down to the hidden-organization check alone.
 *
 * @param array $row Raw organization row.
 * @return bool
 */
function partners_row_is_visible( $row ) {
	if ( ! is_array( $row ) ) {
		return false;
	}

	if ( array_key_exists( 'active', $row ) && ! filter_var( $row['active'], FILTER_VALIDATE_BOOLEAN ) ) {
		return false;
	}

	return ! sso_is_hidden_org( $row );
}

/**
 * Read the pagination block from a list response.
 *
 * This endpoint nests it under `pagination`; the courses list puts the same keys
 * at the top level. Both are accepted so the client does not break if the shape
 * is normalised later.
 *
 * @param array $response Decoded body.
 * @return array{count?:int,num_pages?:int}
 */
function partners_pagination( $response ) {
	if ( isset( $response['pagination'] ) && is_array( $response['pagination'] ) ) {
		return $response['pagination'];
	}

	return $response;
}

/**
 * Fetch a page of partners, mapped onto the card shape used by the catalog.
 *
 * `tutor_sso_partners_results` stays available as a short-circuit seam: return
 * an array from it to bypass the API call entirely.
 *
 * Note on `total`. Internal organizations are filtered out locally, because the
 * API has no "exclude" parameter, so the API's `count` overstates what a visitor
 * can actually see — on stage it reports 18 while 15 are visible.
 *
 * When the whole result set fits on one page the visible rows *are* the whole
 * catalog, so the filtered count is exact and is what gets reported. Across
 * multiple pages that is unknowable without fetching them all, so the API's
 * count is used instead: overstating keeps pagination working, whereas reporting
 * a per-page figure as the total would be plainly wrong.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Partners per page.
 * @param array $args     { search, ordering }.
 * @return array{results:array[],total:int,num_pages:int,error:string}
 */
function partners_fetch( $page = 1, $per_page = 24, $args = array() ) {
	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $per_page );

	$empty = array(
		'results'   => array(),
		'total'     => 0,
		'num_pages' => 0,
		'error'     => '',
	);

	// Real-API seam: return an array from this filter to bypass the request.
	$external = apply_filters( 'tutor_sso_partners_results', null, $page, $per_page, $args );
	if ( is_array( $external ) ) {
		return wp_parse_args( $external, $empty );
	}

	$response = partners_fetch_public( $page, $per_page, $args );

	if ( is_wp_error( $response ) ) {
		$empty['error'] = $response->get_error_message();
		return $empty;
	}

	$rows = ( isset( $response['results'] ) && is_array( $response['results'] ) ) ? $response['results'] : array();
	$meta = partners_pagination( $response );

	$total     = isset( $meta['count'] ) ? (int) $meta['count'] : count( $rows );
	$num_pages = isset( $meta['num_pages'] ) ? (int) $meta['num_pages'] : 0;

	// The API reports num_pages itself; only fall back to computing it.
	if ( $num_pages < 1 ) {
		$num_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
	}

	$results = array();
	foreach ( $rows as $row ) {
		if ( ! partners_row_is_visible( $row ) ) {
			continue;
		}

		$results[] = partners_normalize_partner( $row );
	}

	// Single page: the visible rows are the entire catalog, so the count the hero
	// shows can be the exact one rather than the API's unfiltered figure.
	if ( $num_pages <= 1 ) {
		$total = count( $results );
	}

	return array(
		'results'   => $results,
		'total'     => $total,
		'num_pages' => $num_pages,
		'error'     => '',
	);
}
