<?php
/**
 * Public Instructors API client + detail data layer.
 *
 * Thin wrapper around the LMS public instructor endpoint:
 *
 *   GET /rwaq/api/instructors/public/{id}/
 *
 *
 * Detail response shape (verified against the stage API):
 *   {
 *     "id": int, "name": str, "slug": str,
 *     "created": iso8601,               instructor's join date
 *     "total_enrolled_learners": int,
 *     "image": url,                     presigned S3 URL (expires in ~7 days)
 *     "detail": html,                   biography, wrapped in <p> tags
 *     "featured": bool, "featured_video": str,
 *     "organizations": [ { id, name, short_name, organization_arabic_name,
 *                          organization_logo }, ... ],
 *     "total_courses": int,
 *     "total_programs": int,
 *     "courses":  [ { course_key, slug, title, start, end, course_image, org,
 *                     org_logo, org_arabic_name, status, pricing, language,
 *                     effort, instructor, instructor_image, instructors[],
 *                     overview, categories }, ... ],
 *     "programs": [ { program_key, uuid, name, slug, description, card_image,
 *                     program_type, organization, organization_logo,
 *                     organization_arabic_name, start_date, end_date, status,
 *                     is_featured, total_courses }, ... ]
 *   }
 *
 * instructors_fetch() maps that onto the flat view model the detail renderers
 * consume — only what the page actually shows. See instructor-detail.php.
 *
 * The stats bar is fully API-driven: total_courses, total_programs,
 * total_enrolled_learners, and the joining year from `created` — see
 * instructors_joined_text(). Nothing on this page is static content.
 *
 * Cards are the catalogs' own components, not copies: course rows go through
 * courses_normalize_course() into courses_render_card(), and program rows are
 * handed to programs_render_card() as-is. So an instructor's cards match the
 * courses and programs listings exactly — same markup, styling, hover and links
 * — and stay matched when those listings change.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public instructor detail endpoint path (appended to the LMS base URL).
 * The instructor's numeric LMS id and a trailing slash complete it.
 */
const INSTRUCTORS_PUBLIC_ENDPOINT = '/rwaq/api/instructors/public/';

/**
 * Default cache lifetime, in seconds, for a fetched instructor detail.
 */
const INSTRUCTORS_CACHE_TTL = 300; // 5 minutes.

/**
 * Resolve the configured LMS base URL (reuses the SSO setting, as the courses
 * and programs clients do — all three live on the same host).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function instructors_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Transient key for a fetched instructor, aligned to a shared TTL boundary
 * (see sso_cache_key()).
 *
 * @param string $url Request URL.
 * @return string
 */
function instructors_cache_key( $url ) {
	return sso_cache_key(
		'tutor_sso_instructor_',
		$url,
		(int) apply_filters( 'tutor_sso_instructors_cache_ttl', INSTRUCTORS_CACHE_TTL )
	);
}

/**
 * GET a URL on the LMS public API and decode the JSON body.
 *
 * @param string $url       Absolute request URL.
 * @param string $cache_key Transient key for the decoded body.
 * @return array|\WP_Error Decoded body, or WP_Error on failure.
 */
function instructors_request( $url, $cache_key ) {
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

	// The endpoint 404s with {"detail": "No RwaqInstructor matches the given
	// query."} for an id that does not exist. That is a content problem (a stale
	// or mistyped instructor_lms_id), not an outage, so it gets its own code and
	// its own message on the page.
	if ( 404 === $status ) {
		return new \WP_Error(
			'tutor_sso_instructor_not_found',
			__( 'لم يتم العثور على هذا المدرّب في منصة التعلّم.', 'tutor-sso' )
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] instructor detail %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_instructor_failed',
			__( 'تعذّر تحميل بيانات المدرّب. يرجى المحاولة مرة أخرى.', 'tutor-sso' )
		);
	}

	set_transient(
		$cache_key,
		$body,
		(int) apply_filters( 'tutor_sso_instructors_cache_ttl', INSTRUCTORS_CACHE_TTL )
	);

	return $body;
}

/**
 * Fetch one instructor's raw detail object by LMS id.
 *
 * @param int $lms_id Instructor id on the LMS.
 * @return array|\WP_Error Raw API object, or WP_Error on failure.
 */
function instructors_fetch_public( $lms_id ) {
	$lms_id = (int) $lms_id;

	if ( $lms_id <= 0 ) {
		return new \WP_Error(
			'tutor_sso_instructor_no_id',
			__( 'لم يتم ضبط معرّف المدرّب في منصة التعلّم.', 'tutor-sso' )
		);
	}

	$base = instructors_lms_base_url();

	if ( '' === $base ) {
		return new \WP_Error(
			'tutor_sso_no_base',
			__( 'LMS Base URL is not configured.', 'tutor-sso' )
		);
	}

	$url = $base . INSTRUCTORS_PUBLIC_ENDPOINT . $lms_id . '/';

	return instructors_request( $url, instructors_cache_key( $url ) );
}

/**
 * Map the instructor's `organizations` onto the hero's affiliated-logo row.
 *
 * Internal organizations (see sso_hidden_org_prefixes()) are dropped, matching
 * the courses and programs catalogs. Unlike the mockup's three hand-placed
 * logos, these are arbitrary uploads of unknown aspect ratio, so no dimensions
 * are emitted — instructor.css constrains them by height and lets each keep its
 * own width (see .rwaq-ins__partner-logo--api).
 *
 * @param array $rows Raw `organizations` array.
 * @return array[] { name, logo } rows.
 */
function instructors_map_partners( $rows ) {
	$partners = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) || sso_is_hidden_org( $row ) ) {
			continue;
		}

		$logo = isset( $row['organization_logo'] ) ? trim( (string) $row['organization_logo'] ) : '';

		if ( '' === $logo ) {
			continue;
		}

		// Prefer the Arabic name on this RTL page, then the full name, then the
		// short code — whichever is first non-empty becomes the logo's alt text.
		$name = '';
		foreach ( array( 'organization_arabic_name', 'name', 'short_name' ) as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				$name = trim( (string) $row[ $field ] );
				break;
			}
		}

		$partners[] = array(
			'name' => $name,
			'logo' => $logo,
		);
	}

	return $partners;
}

/**
 * Shorten a course's instructor list to its first name.
 *
 * The API returns every instructor in one comma-joined string — e.g.
 * "Muhammad Faraz Maqsood, Test Instructor, Test Instructor 1" — which wraps
 * onto a second line in the card's meta row and pushes the card taller than its
 * neighbours. Only the first name is kept, with an ellipsis standing in for the
 * rest.
 *
 * The `instructors` array is preferred over splitting the string, since it is
 * the authoritative list and a name could itself contain a comma. The string is
 * the fallback, split on both the Latin and the Arabic comma.
 *
 * @param array $row Raw course object.
 * @return string One name, suffixed with an ellipsis when others are hidden.
 */
function instructors_course_lead_instructor( $row ) {
	$names = array();

	if ( ! empty( $row['instructors'] ) && is_array( $row['instructors'] ) ) {
		foreach ( $row['instructors'] as $person ) {
			$name = '';
			if ( is_array( $person ) && isset( $person['name'] ) ) {
				$name = trim( (string) $person['name'] );
			} elseif ( is_string( $person ) ) {
				$name = trim( $person );
			}

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
	}

	if ( empty( $names ) ) {
		$raw = isset( $row['instructor'] ) ? trim( (string) $row['instructor'] ) : '';

		if ( '' === $raw ) {
			return '';
		}

		$names = preg_split( '/\s*[,\x{060C}]\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$names = is_array( $names ) ? array_map( 'trim', $names ) : array();
		$names = array_values( array_filter( $names, 'strlen' ) );
	}

	if ( empty( $names ) ) {
		return '';
	}

	if ( count( $names ) === 1 ) {
		return $names[0];
	}

	/* translators: %s: the first instructor's name; the ellipsis stands for the others. */
	return sprintf( __( '%s…', 'tutor-sso' ), $names[0] );
}

/**
 * Map one raw course row onto the shape the courses listing card consumes.
 *
 * Delegates to courses_normalize_course(), the same mapper the courses catalog
 * uses, so an instructor's course cards are built from identical data — title,
 * image, url, org_name, instructor, start_text — then trims the instructor list
 * to one name. The trim is applied here rather than in the shared card so the
 * courses listing keeps showing its full list.
 *
 * @param array $row Raw course object.
 * @return array Card row for courses_render_card().
 */
function instructors_map_course_card( $row ) {
	$card = courses_normalize_course( $row );

	$card['instructor'] = instructors_course_lead_instructor( $row );

	return $card;
}

/**
 * Drop rows belonging to an internal organization.
 *
 * The course rows expose the org as `org` / `org_arabic_name` and the program
 * rows as `organization`, neither of which is the { short_name, name } shape
 * sso_is_hidden_org() reads — so the value is re-wrapped for the check.
 *
 * @param array[] $rows   Raw rows.
 * @param string  $field  Field holding the organization's short name.
 * @return array[] Visible rows, re-indexed.
 */
function instructors_filter_hidden_orgs( $rows, $field ) {
	$visible = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$org = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';

		if ( sso_is_hidden_org( array( 'short_name' => $org ) ) ) {
			continue;
		}

		$visible[] = $row;
	}

	return $visible;
}

/**
 * The stats bar's fourth cell — the year the instructor joined.
 *
 * Built from the API's `created` timestamp (ISO 8601, e.g.
 * "2026-08-04T06:17:10.011406Z"). The design labels this cell "انضم في عام
 * {year}", so only the year is shown.
 *
 * Returns '' when `created` is absent or unparseable, which drops the cell
 * rather than printing a wrong or empty year — better a three-cell stats bar
 * than a confident lie about when someone joined.
 *
 * The year is formatted with date_i18n() rather than number_format_i18n(), which
 * would group it as "2,026".
 *
 * @param array $body Raw API object.
 * @return string Label, or '' to drop the cell entirely.
 */
function instructors_joined_text( $body ) {
	$created = isset( $body['created'] ) ? trim( (string) $body['created'] ) : '';
	$text    = '';

	if ( '' !== $created ) {
		$timestamp = strtotime( $created );

		if ( false !== $timestamp ) {
			/* translators: %s: four-digit year the instructor joined. */
			$text = sprintf( __( 'انضم في عام %s', 'tutor-sso' ), date_i18n( 'Y', $timestamp ) );
		}
	}

	/**
	 * Filter the "joined in" stat label.
	 *
	 * @param string $text Label, or '' when the API carried no usable date.
	 * @param array  $body Raw instructor object from the API.
	 */
	return (string) apply_filters( 'tutor_sso_instructor_joined_text', $text, $body );
}

/**
 * Fetch an instructor and map it onto the detail view model.
 *
 * `tutor_sso_instructor_source` stays available as a short-circuit seam: return
 * an array from it to bypass the API call entirely (useful for tests and for
 * previewing a layout without a reachable LMS).
 *
 * @param int $lms_id Instructor id on the LMS.
 * @return array{
 *     name:string, avatar:string, bio:string, bio_html:string, partners:array[],
 *     stats:array[], courses:array[], programs:array[], error:string
 * }
 */
function instructors_fetch( $lms_id ) {
	$empty = array(
		'name'     => '',
		'avatar'   => '',
		'bio'      => '',
		'bio_html' => '',
		'partners' => array(),
		'stats'    => array(),
		'courses'  => array(),
		'programs' => array(),
		'error'    => '',
	);

	// Real-API seam: return an array from this filter to bypass the request.
	$external = apply_filters( 'tutor_sso_instructor_source', null, $lms_id );
	if ( is_array( $external ) ) {
		return wp_parse_args( $external, $empty );
	}

	$body = instructors_fetch_public( $lms_id );

	if ( is_wp_error( $body ) ) {
		$empty['error'] = $body->get_error_message();
		return $empty;
	}

	$courses  = instructors_filter_hidden_orgs( isset( $body['courses'] ) ? $body['courses'] : array(), 'org' );
	$programs = instructors_filter_hidden_orgs( isset( $body['programs'] ) ? $body['programs'] : array(), 'organization' );

	// `detail` is HTML (the LMS wraps the biography in <p>). Two forms are kept:
	//
	//   bio      Plain text for the hero, whose two-line clamp uses
	//            -webkit-line-clamp — that only works on a single text block, so
	//            the markup has to go.
	//   bio_html Sanitised HTML for the modal, so a multi-paragraph biography
	//            renders as paragraphs there, as the design shows.
	$detail   = isset( $body['detail'] ) ? (string) $body['detail'] : '';
	$bio      = trim( wp_strip_all_tags( $detail ) );
	$bio_html = trim( wp_kses_post( $detail ) );

	// Counts come from the API's own totals rather than count($rows): the row
	// arrays can be filtered (hidden organizations) or, later, paginated.
	$total_courses  = isset( $body['total_courses'] ) ? (int) $body['total_courses'] : count( $courses );
	$total_programs = isset( $body['total_programs'] ) ? (int) $body['total_programs'] : count( $programs );
	$total_learners = isset( $body['total_enrolled_learners'] ) ? (int) $body['total_enrolled_learners'] : 0;

	// Stats in the design's RTL order. The icon in each position is the one the
	// mockup put there; position 2 was "مقالات" and now labels برامج, which the
	// API actually provides.
	$stats = array(
		array(
			'icon'  => 'stat-courses.svg',
			/* translators: %s: number of courses. */
			'label' => sprintf( __( '%s مساق', 'tutor-sso' ), number_format_i18n( $total_courses ) ),
		),
		array(
			'icon'  => 'stat-articles.svg',
			/* translators: %s: number of programs. */
			'label' => sprintf( __( '%s برنامج', 'tutor-sso' ), number_format_i18n( $total_programs ) ),
		),
		array(
			'icon'  => 'stat-students.svg',
			/* translators: %s: number of enrolled learners. */
			'label' => sprintf( __( '%s طالب', 'tutor-sso' ), number_format_i18n( $total_learners ) ),
		),
	);

	$joined = instructors_joined_text( $body );
	if ( '' !== $joined ) {
		$stats[] = array(
			'icon'  => 'stat-joined.svg',
			'label' => $joined,
		);
	}

	$data = array(
		'name'     => isset( $body['name'] ) ? trim( (string) $body['name'] ) : '',
		'avatar'   => isset( $body['image'] ) ? trim( (string) $body['image'] ) : '',
		'bio'      => $bio,
		'bio_html' => $bio_html,
		'partners' => instructors_map_partners( isset( $body['organizations'] ) ? $body['organizations'] : array() ),
		'stats'    => $stats,
		'courses'  => array_map( __NAMESPACE__ . '\\instructors_map_course_card', $courses ),
		// programs_render_card() reads the raw API object, so these pass through
		// untouched.
		'programs' => array_values( $programs ),
		'error'    => '',
	);

	/**
	 * Filter the mapped instructor view model.
	 *
	 * @param array $data   Mapped view model.
	 * @param array $body   Raw instructor object from the API.
	 * @param int   $lms_id Instructor id on the LMS.
	 */
	return (array) apply_filters( 'tutor_sso_instructor_card_data', $data, $body, (int) $lms_id );
}
