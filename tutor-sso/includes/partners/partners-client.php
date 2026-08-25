<?php
/**
 * Public Organizations (Partners) API client + catalog data layer.
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
 * Normalise a search term for Arabic typing variance.
 *
 * The endpoint's `search` is a plain case-insensitive substring match over the
 * organization's Latin and Arabic names (verified against stage: searching
 * "رواق" returns an organization whose Latin name has no Arabic in it at all).
 * A substring match takes the query literally, so a term decorated with
 * tashkeel or tatweel — "رِواق", "روااق" as typed with a kashida — fails against
 * plainly-stored text even though a reader would call them the same word.
 *
 * Removed here, therefore:
 *   - tashkeel / harakat and the Quranic annotation marks
 *   - tatweel (kashida), which is pure typographic stretching
 * Mapped here:
 *   - Persian/Urdu letterforms that look identical to Arabic ones on screen
 *     (ک→ك, ی→ي, ہ→ه), a very common keyboard-layout mismatch
 *   - Arabic-Indic digits onto ASCII, so "١٢" finds "12"
 *
 * NOT touched: alef hamza forms (أ إ آ ا) and taa marbuta vs haa (ة/ه). Folding
 * those would be wrong here rather than merely unhelpful — the *stored* value
 * is equally likely to carry the hamza, so rewriting the query would break
 * searches that work today. Matching across those needs the same normalisation
 * applied to the column, which is an LMS-side change.
 *
 * @param string $term Raw search term.
 * @return string Normalised term (may be identical to the input).
 */
function partners_normalize_search( $term ) {
	$term = trim( (string) $term );

	if ( '' === $term ) {
		return '';
	}

	// Tashkeel (U+064B–U+0652), superscript alef, the extended combining marks,
	// and the Quranic annotation range — all non-spacing decoration.
	$stripped = preg_replace( '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $term );
	if ( null !== $stripped ) {
		$term = $stripped;
	}

	$term = strtr(
		$term,
		array(
			"\u{0640}" => '',        // Tatweel (kashida).
			"\u{06A9}" => "\u{0643}", // Keheh    → kaf.
			"\u{06AA}" => "\u{0643}", // Swash kaf → kaf.
			"\u{06CC}" => "\u{064A}", // Farsi yeh → yeh.
			"\u{06D2}" => "\u{064A}", // Yeh barree → yeh.
			"\u{06C0}" => "\u{0629}", // Heh with hamza → taa marbuta.
			"\u{06C1}" => "\u{0647}", // Heh goal → heh.
			"\u{06BE}" => "\u{0647}", // Heh doachashmee → heh.
			// Arabic-Indic and Eastern Arabic-Indic digits → ASCII.
			"\u{0660}" => '0',
			"\u{0661}" => '1',
			"\u{0662}" => '2',
			"\u{0663}" => '3',
			"\u{0664}" => '4',
			"\u{0665}" => '5',
			"\u{0666}" => '6',
			"\u{0667}" => '7',
			"\u{0668}" => '8',
			"\u{0669}" => '9',
			"\u{06F0}" => '0',
			"\u{06F1}" => '1',
			"\u{06F2}" => '2',
			"\u{06F3}" => '3',
			"\u{06F4}" => '4',
			"\u{06F5}" => '5',
			"\u{06F6}" => '6',
			"\u{06F7}" => '7',
			"\u{06F8}" => '8',
			"\u{06F9}" => '9',
		)
	);

	// Collapse the runs of whitespace the removals can leave behind.
	$collapsed = preg_replace( '/\s+/u', ' ', $term );
	if ( null !== $collapsed ) {
		$term = $collapsed;
	}

	/**
	 * Filter the normalised partner search term.
	 *
	 * @param string $term Normalised term.
	 */
	return trim( (string) apply_filters( 'tutor_sso_partners_search_term', $term ) );
}

/**
 * Request one page of organizations for an exact search term.
 *
 * @param string $base     LMS base URL, already trimmed.
 * @param int    $page     1-based page number.
 * @param int    $per_page Rows per page.
 * @param string $search   Search term, used verbatim.
 * @param string $ordering Normalised ordering param, or ''.
 * @return array|\WP_Error Raw decoded body, or WP_Error on failure.
 */
function partners_request_page( $base, $page, $per_page, $search, $ordering ) {
	$query = array(
		'page'      => max( 1, (int) $page ),
		'page_size' => max( 1, (int) $per_page ),
	);

	if ( '' !== $search ) {
		$query['search'] = $search;
	}

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
 * Fetch one raw page of organizations.
 *
 * The term is sent exactly as typed first. Only if that finds nothing, and
 * normalising it actually changes it, is the normalised form tried — so this
 * can only ever add results, never lose a match the literal term would have
 * found. The cost is one extra request on a miss, and both responses are cached
 * by URL like any other page.
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

	$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
	$ordering = isset( $args['ordering'] ) ? partners_normalize_ordering( $args['ordering'] ) : '';

	$body = partners_request_page( $base, $page, $per_page, $search, $ordering );

	if ( '' === $search || is_wp_error( $body ) ) {
		return $body;
	}

	$normalised = partners_normalize_search( $search );

	if ( $normalised === $search || '' === $normalised ) {
		return $body;
	}

	$meta  = partners_pagination( $body );
	$found = isset( $meta['count'] ) ? (int) $meta['count'] : 0;

	if ( $found > 0 ) {
		return $body;
	}

	$retry = partners_request_page( $base, $page, $per_page, $normalised, $ordering );

	// A failed retry must not turn a legitimately empty result into an error.
	return is_wp_error( $retry ) ? $body : $retry;
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

/**
 * Public organization detail endpoint path. The organization's numeric LMS id
 * and a trailing slash complete it.
 */
const PARTNER_DETAIL_ENDPOINT = '/rwaq/api/organizations/public/';

/**
 * Fetch one organization's raw detail object by LMS id.
 *
 * @param int $lms_id Organization id on the LMS.
 * @return array|\WP_Error Raw API object, or WP_Error on failure.
 */
function partner_fetch_detail( $lms_id ) {
	$lms_id = (int) $lms_id;

	if ( $lms_id <= 0 ) {
		return new \WP_Error(
			'tutor_sso_partner_no_id',
			__( 'لم يتم ضبط معرّف الشريك في منصة التعلّم.', 'tutor-sso' )
		);
	}

	$base = partners_lms_base_url();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$url = $base . PARTNER_DETAIL_ENDPOINT . $lms_id . '/';

	$body = partners_request( $url, partners_cache_key( $url ) );

	// partners_request() maps a 404 onto an empty *list* shape, which is right for
	// the catalog but meaningless here — a detail 404 means the id is wrong.
	if ( ! is_wp_error( $body ) && ! isset( $body['id'] ) ) {
		return new \WP_Error(
			'tutor_sso_partner_not_found',
			__( 'لم يتم العثور على هذا الشريك في منصة التعلّم.', 'tutor-sso' )
		);
	}

	return $body;
}

/**
 * Map an instructor row from the organization detail payload onto the card shape.
 *
 * @param array $row     Raw instructor row.
 * @param array $partner Raw organization payload, for the card's org logo.
 * @return array Card row: { id, name, image, url, counts[], logo }.
 */
function partners_map_instructor_card( $row, $partner ) {
	$id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
	$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';

	// Keyed so a filter can replace individual values without knowing the render
	// order. Missing on an older API build → 0, never invented.
	$counts = array(
		'courses'  => isset( $row['total_courses'] ) ? (int) $row['total_courses'] : 0,
		'learners' => isset( $row['total_enrolled_learners'] ) ? (int) $row['total_enrolled_learners'] : 0,
	);

	/**
	 * Filter an instructor card's counts.
	 *
	 * @param array $counts { courses, learners }.
	 * @param array $row    Raw instructor row from the organization payload.
	 * @param array $partner Raw organization payload.
	 */
	$counts = (array) apply_filters( 'tutor_sso_partner_instructor_counts', $counts, $row, $partner );

	return array(
		'id'     => $id,
		'name'   => $name,
		'image'  => isset( $row['image'] ) ? trim( (string) $row['image'] ) : '',
		'url'    => $id > 0 ? (string) apply_filters( 'tutor_sso_partner_instructor_url', '', $row ) : '',
		'counts' => $counts,
		// The design puts the partner's own logo at the foot of each card.
		'logo'   => isset( $partner['logo'] ) ? trim( (string) $partner['logo'] ) : '',
	);
}

/**
 * Fetch an organization and map it onto the detail view model.
 *
 * `tutor_sso_partner_detail_source` is a short-circuit seam: return an array
 * from it to bypass the request entirely.
 *
 * @param int $lms_id Organization id on the LMS.
 * @return array{
 *     name:string, logo:string, bio:string, bio_html:string, stats:array[],
 *     totals:array{courses:int,programs:int,instructors:int},
 *     programs:array[], courses:array[], instructors:array[], error:string
 * }
 */
function partner_fetch( $lms_id ) {
	$empty = array(
		'name'        => '',
		'logo'        => '',
		'bio'         => '',
		'bio_html'    => '',
		'stats'       => array(),
		'totals'      => array(
			'courses'     => 0,
			'programs'    => 0,
			'instructors' => 0,
		),
		'programs'    => array(),
		'courses'     => array(),
		'instructors' => array(),
		'error'       => '',
	);

	$external = apply_filters( 'tutor_sso_partner_detail_source', null, $lms_id );
	if ( is_array( $external ) ) {
		return wp_parse_args( $external, $empty );
	}

	$body = partner_fetch_detail( $lms_id );

	if ( is_wp_error( $body ) ) {
		$empty['error'] = $body->get_error_message();
		return $empty;
	}

	// The Arabic name is what the design shows; Latin name then short code are the
	// fallbacks, so the hero is never nameless.
	$name = '';
	foreach ( array( 'arabic_name', 'name', 'short_name' ) as $key ) {
		if ( ! empty( $body[ $key ] ) ) {
			$name = trim( (string) $body[ $key ] );
			break;
		}
	}

	// `description` is HTML-capable, so two forms are kept for the same reason as
	// the instructor biography: the hero clamps plain text, the modal renders
	// paragraphs. It is empty for every organization on stage today.
	$detail   = isset( $body['description'] ) ? (string) $body['description'] : '';
	$bio      = trim( wp_strip_all_tags( $detail ) );
	$bio_html = trim( wp_kses_post( $detail ) );

	$courses  = isset( $body['courses'] ) && is_array( $body['courses'] ) ? $body['courses'] : array();
	$programs = isset( $body['programs'] ) && is_array( $body['programs'] ) ? $body['programs'] : array();
	$people   = isset( $body['instructors'] ) && is_array( $body['instructors'] ) ? $body['instructors'] : array();

	// The API's own totals when present, otherwise what the embedded arrays hold —
	// the two agree today, but the totals stay right if the rows are ever paginated.
	$total_courses     = isset( $body['total_courses'] ) ? (int) $body['total_courses'] : count( $courses );
	$total_programs    = isset( $body['total_programs'] ) ? (int) $body['total_programs'] : count( $programs );
	$total_instructors = isset( $body['total_instructors'] ) ? (int) $body['total_instructors'] : count( $people );

	// Stats in the design's RTL order. The design's third cell is the partner's
	// country, which this endpoint does not carry, so it is omitted rather than
	// invented — leaving a clean three-cell bar.
	$stats = array(
		array(
			'icon'  => 'stat-courses.svg',
			/* translators: %s: number of courses. */
			'label' => sprintf( __( '%s دورة', 'tutor-sso' ), number_format_i18n( $total_courses ) ),
		),
		array(
			'icon'  => 'stat-programs.svg',
			/* translators: %s: number of programs. */
			'label' => sprintf( __( '%s برنامجًا', 'tutor-sso' ), number_format_i18n( $total_programs ) ),
		),
	);

	$joined = partner_joined_text( $body );
	if ( '' !== $joined ) {
		$stats[] = array(
			'icon'  => 'stat-joined.svg',
			'label' => $joined,
		);
	}

	$data = array(
		'name'        => $name,
		'logo'        => isset( $body['logo'] ) ? trim( (string) $body['logo'] ) : '',
		'bio'         => $bio,
		'bio_html'    => $bio_html,
		'stats'       => $stats,
		'totals'      => array(
			'courses'     => $total_courses,
			'programs'    => $total_programs,
			'instructors' => $total_instructors,
		),
		// Both card rows go straight into the catalogs' own renderers: course rows
		// through courses_normalize_course(), program rows untouched.
		'courses'     => array_map( __NAMESPACE__ . '\\courses_normalize_course', $courses ),
		'programs'    => array_values( $programs ),
		'instructors' => array_map(
			function ( $row ) use ( $body ) {
				return partners_map_instructor_card( $row, $body );
			},
			$people
		),
		'error'       => '',
	);

	/**
	 * Filter the mapped partner detail view model.
	 *
	 * @param array $data   Mapped view model.
	 * @param array $body   Raw organization object from the API.
	 * @param int   $lms_id Organization id on the LMS.
	 */
	return (array) apply_filters( 'tutor_sso_partner_detail_data', $data, $body, (int) $lms_id );
}

/**
 * The stats bar's "joined in" cell, from the organization's `created` timestamp.
 *
 * Returns '' when absent or unparseable, which drops the cell rather than
 * printing a wrong year. Mirrors instructors_joined_text().
 *
 * @param array $body Raw API object.
 * @return string
 */
function partner_joined_text( $body ) {
	$created = isset( $body['created'] ) ? trim( (string) $body['created'] ) : '';
	$text    = '';

	if ( '' !== $created ) {
		$timestamp = strtotime( $created );

		if ( false !== $timestamp ) {
			/* translators: %s: four-digit year the partner joined. */
			$text = sprintf( __( 'انضم في عام %s', 'tutor-sso' ), date_i18n( 'Y', $timestamp ) );
		}
	}

	/**
	 * Filter the partner's "joined in" stat label.
	 *
	 * @param string $text Label, or '' when the API carried no usable date.
	 * @param array  $body Raw organization object.
	 */
	return (string) apply_filters( 'tutor_sso_partner_joined_text', $text, $body );
}
