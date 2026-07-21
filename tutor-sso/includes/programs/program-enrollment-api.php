<?php
/**
 * Cookie / session based Open edX *program* enrollment client.
 *
 * Mirrors the course enrollment client (enrollment-api.php): it reuses the
 * logged-in user's own Open edX session cookies (forwarded verbatim) plus a
 * fresh CSRF token to call the RWAQ programs enrollment API on the learner's
 * behalf. The endpoint is a single REST resource whose HTTP verb selects the
 * operation:
 *
 *   GET    /rwaq/api/programs/my/enrollment/{program_key}/   → status
 *   POST   /rwaq/api/programs/my/enrollment/{program_key}/   → enroll
 *   DELETE /rwaq/api/programs/my/enrollment/{program_key}/   → unenroll
 *
 * The generic cookie / CSRF helpers (enroll_collect_cookies(),
 * enroll_serialize_cookies(), enroll_get_csrf_token(), enroll_has_edx_session(),
 * enroll_build_cookie_header(), enroll_lms_base_url()) are NOT course-specific
 * and are shared from enrollment-api.php, which is always loaded first.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Program enrollment endpoint path (appended to the LMS base URL, before the
 * program key and a trailing slash).
 */
const PROGRAM_ENROLLMENT_ENDPOINT = '/rwaq/api/programs/my/enrollment/';

/**
 * Build the enrollment API URL for a program key.
 *
 * Program keys are opaque edX-style keys (they contain "+" and ":") used
 * literally in the path — like programs_fetch_detail(), we do NOT url-encode
 * them. A trailing slash is required by the API.
 *
 * @param string $program_key Program key (program-v1:Org+Program+Run).
 * @return string
 */
function program_enrollment_url( $program_key ) {
	return enroll_lms_base_url() . PROGRAM_ENROLLMENT_ENDPOINT . trim( (string) $program_key ) . '/';
}

/**
 * Perform an enroll / unenroll action against the program enrollment API.
 *
 * enroll → POST, unenroll → DELETE, against the same resource URL. Reuses the
 * shared cookie-forwarding + CSRF flow so the LMS sees the same authenticated
 * session the user has in their browser (see enrollment-api.php).
 *
 * @param string $program_key Program key (program-v1:Org+Program+Run).
 * @param string $action      'enroll' or 'unenroll'.
 * @return array|\WP_Error { success: bool, status: int, body: string } or WP_Error.
 */
function program_change_enrollment( $program_key, $action ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	if ( ! enroll_has_edx_session() ) {
		return new \WP_Error( 'tutor_sso_no_session', __( 'No active LMS session was found. Please log in to the LMS and try again.', 'tutor-sso' ) );
	}

	$action = in_array( $action, array( 'enroll', 'unenroll' ), true ) ? $action : 'enroll';
	$method = ( 'enroll' === $action ) ? 'POST' : 'DELETE';

	$cookies      = enroll_collect_cookies();
	$cookie_token = isset( $cookies['csrftoken'] ) ? $cookies['csrftoken'] : '';
	$csrf         = enroll_get_csrf_token( enroll_serialize_cookies( $cookies ), $cookie_token );

	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}

	// Force the outgoing csrftoken cookie to match the header token so Django's
	// double-submit CSRF check always passes.
	$cookies['csrftoken'] = $csrf;
	$cookie_header        = enroll_serialize_cookies( $cookies );

	$response = wp_remote_request(
		program_enrollment_url( $program_key ),
		array(
			'method'    => $method,
			'timeout'   => 30,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'           => 'application/json, text/plain, */*',
				'Origin'           => $base,
				'Referer'          => $base . '/',
				'X-CSRFToken'      => $csrf,
				'X-Requested-With' => 'XMLHttpRequest',
				'Cookie'           => $cookie_header,
				'use-jwt-cookie'   => 'true',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );

	// Log the LMS response on failure to aid diagnosis (only when WP_DEBUG).
	if ( ( $status < 200 || $status >= 300 ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[tutor-sso] program %s for %s -> HTTP %d: %s',
				$action,
				$program_key,
				$status,
				wp_strip_all_tags( (string) $body )
			)
		);
	}

	return array(
		'success' => $status >= 200 && $status < 300,
		'status'  => $status,
		'body'    => $body,
	);
}

/**
 * Check whether the current user is actively enrolled in a program.
 *
 * GETs the program enrollment resource, which reports the requesting user's own
 * enrollment via the `is_active` flag. A 404 means "no enrollment record for
 * this user/program" and is treated as not-enrolled (not an error).
 *
 * @param string $program_key Program key.
 * @return bool|\WP_Error True/false, or WP_Error when the call genuinely fails.
 */
function program_is_enrolled( $program_key ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	if ( ! enroll_has_edx_session() ) {
		return false;
	}

	$response = wp_remote_get(
		program_enrollment_url( $program_key ),
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'         => 'application/json, text/plain, */*',
				'Referer'        => $base . '/',
				'Cookie'         => enroll_build_cookie_header(),
				'use-jwt-cookie' => 'true',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	// No enrollment record for this user/program → not enrolled.
	if ( 404 === $status ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		return new \WP_Error(
			'tutor_sso_status_failed',
			__( 'Could not determine enrollment status from the LMS.', 'tutor-sso' )
		);
	}

	// { "program_key": "…", "is_active": bool, "enrollment_date": …, "completion_date": … }
	return ! empty( $body['is_active'] );
}
