<?php
/**
 * Public course detail API client.
 *
 * GET /api/v1/courses/{course_key}/ on the LMS. course_detail_fetch() maps the
 * raw object onto the flat view model course-detail.php renders — only the
 * fields the page shows.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default cache lifetime, in seconds, for a fetched course detail.
 */
const COURSE_DETAIL_CACHE_TTL = 300; // 5 minutes.

/**
 * Transient key for a fetched course, aligned to a shared TTL boundary.
 *
 * @param string $url Request URL.
 * @return string
 */
function course_detail_cache_key( $url ) {
	return sso_cache_key(
		'tutor_sso_course_detail_',
		$url,
		(int) apply_filters( 'tutor_sso_course_detail_cache_ttl', COURSE_DETAIL_CACHE_TTL )
	);
}

/**
 * Fetch one course's raw detail object by edX course key.
 *
 * @param string $course_key e.g. course-v1:Org+Course+Run.
 * @return array|\WP_Error Raw API object, or WP_Error on failure.
 */
function course_detail_fetch_public( $course_key ) {
	$course_key = trim( (string) $course_key );

	if ( '' === $course_key ) {
		return new \WP_Error(
			'tutor_sso_course_no_key',
			__( 'لم يتم ضبط معرّف المساق في منصة التعلّم.', 'tutor-sso' )
		);
	}

	$base = courses_lms_base_url();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$url    = $base . COURSES_PUBLIC_ENDPOINT . rawurlencode( $course_key ) . '/';
	$key    = course_detail_cache_key( $url );
	$cached = get_transient( $key );

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

	// 404 = a stale or mistyped course key: a content problem, not an outage.
	if ( 404 === $status ) {
		return new \WP_Error(
			'tutor_sso_course_not_found',
			__( 'لم يتم العثور على هذا المساق في منصة التعلّم.', 'tutor-sso' )
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] course detail %s -> HTTP %d', $url, $status )
			);
		}

		return new \WP_Error(
			'tutor_sso_course_failed',
			__( 'تعذّر تحميل بيانات المساق. يرجى المحاولة مرة أخرى.', 'tutor-sso' )
		);
	}

	set_transient( $key, $body, (int) apply_filters( 'tutor_sso_course_detail_cache_ttl', COURSE_DETAIL_CACHE_TTL ) );

	return $body;
}

/**
 * Turn a YouTube watch/share/embed URL into an embeddable /embed/ URL.
 *
 * @param string $link Any YouTube URL.
 * @return string Embed URL, or '' when no video id can be read.
 */
function course_youtube_embed_url( $link ) {
	$link = trim( (string) $link );

	if ( '' === $link ) {
		return '';
	}

	// youtu.be/ID, /embed/ID, /shorts/ID, /live/ID, or ?v=ID.
	if ( preg_match( '#(?:youtu\.be/|/embed/|/shorts/|/live/|[?&]v=)([A-Za-z0-9_-]{11})#', $link, $m ) ) {
		return 'https://www.youtube.com/embed/' . $m[1];
	}

	return '';
}

/**
 * Point root-relative src/href in the overview HTML at the LMS.
 *
 * The API's overview embeds Studio assets as "/asset-v1:…", which would resolve
 * against this site and 404.
 *
 * @param string $html Overview HTML.
 * @return string
 */
function course_absolutize_overview( $html ) {
	$html = (string) $html;
	$base = courses_lms_base_url();

	if ( '' === $html || '' === $base ) {
		return $html;
	}

	return (string) preg_replace(
		'#\b(src|href)="/(?!/)#',
		'$1="' . $base . '/',
		$html
	);
}

/**
 * Map the raw API object onto the course detail view model.
 *
 * @param string $course_key edX course key.
 * @return array {
 *     @type string   $title, $description, $overview (HTML), $image
 *     @type string   $org_name, $org_logo, $video
 *     @type int      $enrolled
 *     @type bool     $certificate
 *     @type string[] $categories
 *     @type array[]  $instructors [ { id, name, image, url, counts{courses,learners,programs} } ]
 *     @type array[]  $related     Rows shaped for courses_render_card()
 *     @type string   $error       Message to show instead of the page body.
 * }
 */
function course_detail_data_remote( $course_key ) {
	$empty = array(
		'title'       => '',
		'description' => '',
		'overview'    => '',
		'image'       => '',
		'org_name'    => '',
		'org_logo'    => '',
		'video'       => '',
		'enrolled'    => 0,
		'certificate' => false,
		'categories'  => array(),
		'instructors' => array(),
		'related'     => array(),
		'error'       => '',
	);

	$row = course_detail_fetch_public( $course_key );

	if ( is_wp_error( $row ) ) {
		$empty['error'] = $row->get_error_message();

		return $empty;
	}

	$org_name = isset( $row['org_arabic_name'] ) ? trim( (string) $row['org_arabic_name'] ) : '';
	if ( '' === $org_name ) {
		$org_name = isset( $row['org'] ) ? trim( (string) $row['org'] ) : '';
	}

	$categories = array();
	foreach ( (array) ( isset( $row['categories'] ) ? $row['categories'] : array() ) as $category ) {
		if ( ! is_array( $category ) ) {
			continue;
		}

		$name = isset( $category['arabic_name'] ) ? trim( (string) $category['arabic_name'] ) : '';
		if ( '' === $name ) {
			$name = isset( $category['name'] ) ? trim( (string) $category['name'] ) : '';
		}

		if ( '' !== $name ) {
			$categories[] = $name;
		}
	}

	$instructors = array();
	foreach ( (array) ( isset( $row['instructors'] ) ? $row['instructors'] : array() ) as $person ) {
		if ( ! is_array( $person ) ) {
			continue;
		}

		$name = isset( $person['name'] ) ? trim( (string) $person['name'] ) : '';
		if ( '' === $name ) {
			continue;
		}

		$id = isset( $person['id'] ) ? (int) $person['id'] : 0;

		$instructors[] = array(
			'id'     => $id,
			'name'   => $name,
			'image'  => isset( $person['image'] ) ? trim( (string) $person['image'] ) : '',
			'url'    => course_instructor_url( isset( $person['slug'] ) ? $person['slug'] : '' ),
			'counts' => array(
				'courses'  => isset( $person['course_count'] ) ? (int) $person['course_count'] : 0,
				'learners' => isset( $person['student_count'] ) ? (int) $person['student_count'] : 0,
				'programs' => isset( $person['program_count'] ) ? (int) $person['program_count'] : 0,
			),
		);
	}

	$related = array();
	foreach ( (array) ( isset( $row['related_courses'] ) ? $row['related_courses'] : array() ) as $course ) {
		if ( is_array( $course ) ) {
			$related[] = courses_normalize_course( $course );
		}
	}

	$data = array(
		'title'       => isset( $row['title'] ) ? (string) $row['title'] : '',
		'description' => isset( $row['description'] ) ? trim( wp_strip_all_tags( (string) $row['description'] ) ) : '',
		'overview'    => course_absolutize_overview( isset( $row['overview'] ) ? $row['overview'] : '' ),
		'image'       => isset( $row['course_image'] ) ? trim( (string) $row['course_image'] ) : '',
		'org_name'    => $org_name,
		'org_logo'    => isset( $row['org_logo'] ) ? trim( (string) $row['org_logo'] ) : '',
		'video'       => course_youtube_embed_url( isset( $row['youtube_intro_video_link'] ) ? $row['youtube_intro_video_link'] : '' ),
		'enrolled'    => isset( $row['enrollment_count'] ) ? (int) $row['enrollment_count'] : 0,
		'certificate' => ! empty( $row['certificate_enabled'] ),
		'categories'  => $categories,
		'instructors' => $instructors,
		'related'     => $related,
		'error'       => '',
	);

	return $data;
}

/**
 * Path segment the instructor detail pages live under, i.e. /{base}/{slug}/.
 *
 * @return string
 */
function course_instructor_base() {
	return (string) apply_filters( 'tutor_sso_instructor_detail_base', 'instructor' );
}

/**
 * Link for an instructor: the local instructor post, built from the API slug as
 * {site}/instructor/{slug}/ (mirrors course_detail_url()).
 *
 * @param string $slug Instructor slug from the API.
 * @return string URL, or '' when the row carries no slug.
 */
function course_instructor_url( $slug ) {
	$slug = trim( (string) $slug );

	if ( '' === $slug ) {
		return '';
	}

	// Lowercase so the URL matches the WordPress post_name, which WP lowercases
	// on save. Multibyte-aware: slugs are Arabic, and byte-wise strtolower()
	// corrupts their UTF-8.
	$slug = function_exists( 'mb_strtolower' ) ? mb_strtolower( $slug, 'UTF-8' ) : $slug;

	$base = trim( course_instructor_base(), '/' );
	$path = '/' . ( '' !== $base ? $base . '/' : '' ) . rawurlencode( $slug ) . '/';

	return (string) apply_filters( 'tutor_sso_course_instructor_url', home_url( $path ), $slug );
}
