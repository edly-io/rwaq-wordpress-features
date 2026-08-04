<?php
/**
 * Public Courses API client + catalog data layer.
 *
 * Thin wrapper around the LMS public course catalog endpoints:
 *
 *   GET /api/v1/courses/          — paginated, searchable, org-filterable list
 *   GET /api/v1/courses/filters/  — available filter options (organizations)
 *
 * The endpoint is public (no authentication / cookies required), so like the
 * programs client (see includes/programs/programs-client.php) we do not forward
 * any edX session cookies. Responses are short-lived-cached in a transient to
 * keep catalog pages fast and avoid hammering the LMS.
 *
 * List response shape (DRF PageNumberPagination):
 *   {
 *     "count": int, "next": url|null, "previous": url|null,
 *     "results": [ { course_key, title, start, end, course_image, description,
 *                    org, org_logo, org_arabic_name, status, pricing, language,
 *                    effort, instructor, instructor_image, overview, ... }, ... ]
 *   }
 *
 * Supported list query args (verified against the stage API):
 *   page=<n> & page_size=<n>   pagination
 *   search=<term>              free-text search
 *   org=<A>,<B>                organization short names, comma-joined = OR
 *                              (names/short names only — ids are not matched)
 *   ordering=title|-title      alphabetical sort
 *
 * The API ignores date orderings (`start` / `-start`) today; they are still sent
 * for the "newest / oldest" sort options so those start working as soon as the
 * API supports them. Until then those two options fall back to the API's
 * default order.
 *
 * courses_fetch() maps the raw API rows onto the flat card shape the catalog and
 * AJAX handler consume — only what the card actually renders:
 *   {
 *     id, key, title, image, url,
 *     org_slug, org_name, instructor, start_text
 *   }
 *
 * Organization shape (filter options, from the filters endpoint):
 *   { slug, name, logo, count }
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public courses list endpoint path (appended to the LMS base URL).
 */
const COURSES_PUBLIC_ENDPOINT = '/api/v1/courses/';

/**
 * Default cache lifetime, in seconds, for a fetched catalog page / org list.
 */
const COURSES_CACHE_TTL = 300; // 5 minutes.

/**
 * Catalog filters endpoint path (appended to the LMS base URL).
 */
const COURSES_FILTERS_ENDPOINT = '/api/v1/courses/filters/';

/**
 * Post type the LMS courses are synced into (see rest/class-courses-rest-controller.php).
 */
const COURSES_LOCAL_POST_TYPE = 'course';

/**
 * Meta key holding the edX course id on a local `course` post.
 */
const COURSES_LOCAL_KEY_META = 'openedx_course_id';

/**
 * Resolve the configured LMS base URL (reuses the SSO setting).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function courses_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Whitelisted `ordering` values. Maps a friendly sort key to the DRF ordering
 * expression sent to the API, so arbitrary field ordering cannot be injected
 * from the query string / AJAX.
 *
 * Only `title` / `-title` are honored by the API today; the date expressions are
 * forward-compatible (see the file header).
 *
 * @return array<string,string>
 */
function courses_allowed_ordering() {
	return array(
		'newest'     => '-start', // الأحدث أولًا
		'oldest'     => 'start',  // الأقدم أولًا
		'title_asc'  => 'title',  // أ–ي
		'title_desc' => '-title', // ي–أ
	);
}

/**
 * Normalize a requested ordering key into a DRF ordering expression.
 *
 * @param string $ordering Friendly key (e.g. "title_asc") or raw expression.
 * @return string DRF ordering expression, or '' when unrecognized.
 */
function courses_normalize_ordering( $ordering ) {
	$ordering = trim( (string) $ordering );

	if ( '' === $ordering ) {
		return '';
	}

	$allowed = courses_allowed_ordering();

	if ( isset( $allowed[ $ordering ] ) ) {
		return $allowed[ $ordering ];
	}

	// Also accept a raw expression if it's one of the mapped targets.
	return in_array( $ordering, $allowed, true ) ? $ordering : '';
}

/**
 * Serialize a query args map into a query string. Array values are joined into a
 * single comma-separated param (org=A,B) — the format the catalog API expects
 * for multi-select filters (repeated keys only honor the last value). Each value
 * is URL-encoded; the joining commas are left literal.
 *
 * @param array<string,mixed> $args Query args (scalars or arrays of scalars).
 * @return string Query string without a leading "?".
 */
function courses_build_query( $args ) {
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
 * GET a URL on the LMS public API and decode the JSON body.
 *
 * @param string $url       Absolute request URL.
 * @param string $cache_key Transient key for the decoded body.
 * @return array|\WP_Error Decoded body, or WP_Error on failure.
 */
function courses_request( $url, $cache_key ) {
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
	// as an empty result set rather than a hard error so the UI degrades
	// gracefully (e.g. a stale page number left in a request).
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
				sprintf( '[tutor-sso] courses list %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_courses_failed',
			__( 'Could not load courses from the LMS.', 'tutor-sso' )
		);
	}

	set_transient( $cache_key, $body, apply_filters( 'tutor_sso_courses_cache_ttl', COURSES_CACHE_TTL ) );

	return $body;
}

/**
 * Fetch one page of courses from the LMS public catalog API (raw rows).
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Courses per page (sent as the `page_size` query arg).
 * @param array $args     Optional {
 *     @type string   $search   Free-text search term.
 *     @type string   $ordering Ordering key (see courses_allowed_ordering()).
 *     @type string[] $org      Organization codes to filter by (OR).
 * }
 * @return array|\WP_Error { count, next, previous, results } or WP_Error.
 */
function courses_fetch_public( $page = 1, $per_page = 8, $args = array() ) {
	$base = courses_lms_base_url();

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

	$ordering = isset( $args['ordering'] ) ? courses_normalize_ordering( $args['ordering'] ) : '';
	if ( '' !== $ordering ) {
		$query['ordering'] = $ordering;
	}

	if ( ! empty( $args['org'] ) ) {
		$query['org'] = (array) $args['org'];
	}

	$url = $base . COURSES_PUBLIC_ENDPOINT . '?' . courses_build_query( $query );

	return courses_request( $url, 'tutor_sso_courses_' . md5( $url ) );
}

/**
 * Fetch a page of courses, mapped onto the card shape used by the catalog.
 *
 * `tutor_sso_courses_results` stays available as a short-circuit seam: return an
 * array from it to bypass the API call entirely. `tutor_sso_courses_source`
 * receives the raw API rows (pre-normalization) for the current page.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Courses per page.
 * @param array $args     { search, ordering, org (string[]) }.
 * @return array{results:array[],total:int,num_pages:int,error:string}
 */
function courses_fetch( $page = 1, $per_page = 8, $args = array() ) {
	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $per_page );

	$empty = array(
		'results'   => array(),
		'total'     => 0,
		'num_pages' => 0,
		'error'     => '',
	);

	// Real-API seam: return an array from this filter to bypass the request.
	$external = apply_filters( 'tutor_sso_courses_results', null, $page, $per_page, $args );
	if ( is_array( $external ) ) {
		return wp_parse_args( $external, $empty );
	}

	$response = courses_fetch_public( $page, $per_page, $args );

	if ( is_wp_error( $response ) ) {
		$empty['error'] = $response->get_error_message();
		return $empty;
	}

	$rows  = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
	$total = isset( $response['count'] ) ? (int) $response['count'] : count( $rows );

	$rows = apply_filters( 'tutor_sso_courses_source', $rows, $args );

	$keys = array_map(
		function ( $row ) {
			return is_array( $row ) && isset( $row['course_key'] ) ? (string) $row['course_key'] : '';
		},
		$rows
	);

	$urls    = courses_local_post_urls( $keys );
	$results = array();

	foreach ( $rows as $row ) {
		if ( is_array( $row ) ) {
			$results[] = courses_normalize_course( $row, $urls );
		}
	}

	return array(
		'results'   => $results,
		'total'     => $total,
		'num_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		'error'     => '',
	);
}

/**
 * Map a raw API course row onto the flat shape the card renderer consumes.
 *
 * @param array               $row  Raw course object from the API.
 * @param array<string,string> $urls course_key => local permalink map.
 * @return array
 */
function courses_normalize_course( $row, $urls = array() ) {
	$key      = isset( $row['course_key'] ) ? trim( (string) $row['course_key'] ) : '';
	$org_code = isset( $row['org'] ) ? trim( (string) $row['org'] ) : '';
	$org_name = isset( $row['org_arabic_name'] ) ? trim( (string) $row['org_arabic_name'] ) : '';

	$course = array(
		'id'         => $key,
		'key'        => $key,
		'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
		'image'      => isset( $row['course_image'] ) ? (string) $row['course_image'] : '',
		'url'        => course_catalog_url( $key, $urls ),
		'org_slug'   => $org_code,
		'org_name'   => '' !== $org_name ? $org_name : $org_code,
		'instructor' => isset( $row['instructor'] ) ? trim( (string) $row['instructor'] ) : '',
		'start_text' => courses_start_date_text( isset( $row['start'] ) ? $row['start'] : '' ),
	);

	return apply_filters( 'tutor_sso_courses_card_data', $course, $row );
}

/**
 * Resolve the link for a course card.
 *
 * Prefers the local `course` post synced from the LMS (see the courses REST
 * controller) and falls back to the LMS about page.
 *
 * @param string              $key  edX course key.
 * @param array<string,string> $urls course_key => local permalink map.
 * @return string URL, or '' when the course has no key.
 */
function course_catalog_url( $key, $urls = array() ) {
	$key = trim( (string) $key );

	if ( '' === $key ) {
		return '';
	}

	if ( isset( $urls[ $key ] ) && '' !== $urls[ $key ] ) {
		$url = $urls[ $key ];
	} else {
		$base = courses_lms_base_url();
		$url  = '' !== $base ? $base . '/courses/' . rawurlencode( $key ) . '/about' : '';
	}

	return (string) apply_filters( 'tutor_sso_course_url', $url, $key );
}

/**
 * Look up local `course` posts for a batch of edX course keys, in one query.
 *
 * @param array $keys edX course keys.
 * @return array<string,string> course_key => permalink (only for keys found).
 */
function courses_local_post_urls( $keys ) {
	$keys = array_values(
		array_unique(
			array_filter(
				array_map(
					function ( $key ) {
						return trim( (string) $key );
					},
					(array) $keys
				)
			)
		)
	);

	if ( empty( $keys ) || ! post_type_exists( COURSES_LOCAL_POST_TYPE ) ) {
		return array();
	}

	$ids = get_posts(
		array(
			'post_type'              => COURSES_LOCAL_POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => count( $keys ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => COURSES_LOCAL_KEY_META,
					'value'   => $keys,
					'compare' => 'IN',
				),
			),
		)
	);

	if ( empty( $ids ) ) {
		return array();
	}

	// 'fields' => 'ids' skips the meta cache, so prime it in one query.
	update_meta_cache( 'post', $ids );

	$urls = array();

	foreach ( $ids as $id ) {
		$key = (string) get_post_meta( $id, COURSES_LOCAL_KEY_META, true );

		if ( '' !== $key && ! isset( $urls[ $key ] ) ) {
			$urls[ $key ] = (string) get_permalink( $id );
		}
	}

	return $urls;
}

/**
 * Format a course start date, localized (Arabic month names on RTL sites).
 *
 * @param string $start ISO 8601 date from the API.
 * @return string Localized date, or '' when absent / unparseable.
 */
function courses_start_date_text( $start ) {
	$start = trim( (string) $start );

	if ( '' === $start ) {
		return '';
	}

	$ts = strtotime( $start );

	if ( ! $ts ) {
		return '';
	}

	$format = get_option( 'date_format' );

	return date_i18n( $format ? $format : 'j F Y', $ts );
}

/**
 * Fetch the available catalog filter options.
 *
 * @return array|\WP_Error {
 *     @type array[] $organizations [ { id, name, short_name, organization_arabic_name,
 *                                     organization_logo, total_courses }, ... ].
 * } or WP_Error on failure.
 */
function courses_fetch_filters() {
	$base = courses_lms_base_url();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$url  = $base . COURSES_FILTERS_ENDPOINT;
	$body = courses_request( $url, 'tutor_sso_course_filters_' . md5( $url ) );

	if ( is_wp_error( $body ) ) {
		return $body;
	}

	return array(
		'organizations' => ( isset( $body['organizations'] ) && is_array( $body['organizations'] ) ) ? $body['organizations'] : array(),
	);
}

/**
 * Organizations for the filter dropdown, from the catalog filters API.
 *
 * The dropdown value is the organization's short name — the string the list
 * endpoint's `org` filter matches (it does not accept ids). Labels prefer the
 * Arabic name. API order is preserved (alphabetical by name).
 *
 * Filterable so alternative data can be injected without editing this file.
 *
 * @return array<int,array{slug:string,name:string,logo:string,count:int}>
 */
function courses_organizations() {
	$filters = courses_fetch_filters();
	$orgs    = array();

	if ( ! is_wp_error( $filters ) ) {
		foreach ( $filters['organizations'] as $org ) {
			if ( ! is_array( $org ) ) {
				continue;
			}

			$name  = isset( $org['name'] ) ? trim( (string) $org['name'] ) : '';
			$short = isset( $org['short_name'] ) ? trim( (string) $org['short_name'] ) : '';
			$slug  = '' !== $short ? $short : $name;

			if ( '' === $slug ) {
				continue;
			}

			$arabic = isset( $org['organization_arabic_name'] ) ? trim( (string) $org['organization_arabic_name'] ) : '';

			$orgs[] = array(
				'slug'  => $slug,
				'name'  => '' !== $arabic ? $arabic : ( '' !== $name ? $name : $slug ),
				'logo'  => isset( $org['organization_logo'] ) ? (string) $org['organization_logo'] : '',
				'count' => isset( $org['total_courses'] ) ? (int) $org['total_courses'] : 0,
			);
		}
	}

	return apply_filters( 'tutor_sso_courses_organizations', $orgs );
}
