<?php
/**
 * Cookie / session based Open edX enrollment client.
 *
 * Unlike the original Tutor Course Enroll plugin — which used a server-side
 * client_credentials JWT + the /api/enrollment/v1/enrollment API — this client
 * reuses the *logged-in user's own* Open edX session. WordPress and the LMS
 * share a cookie domain (the SSO plugin already relies on this: see
 * revoke_session_on_lms_logout()), so the edX session cookies are present in
 * $_COOKIE on the WordPress server and can be forwarded straight to the LMS.
 *
 * This mirrors exactly what the browser does (see the Postman collection):
 *   1. GET  /csrf/api/v1/token                                   → CSRF token
 *   2. POST /change_enrollment  (course_id, enrollment_action)   → (un)enroll
 *   3. GET  /api/course_home/course_metadata/{course_id}         → status
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * edX cookies we forward to the LMS. Anything matching one of these names (or
 * the edx-/edxsso prefixes) in $_COOKIE is relayed so the LMS sees the same
 * authenticated session the user has in their browser.
 *
 * @return string[]
 */
function enroll_forwarded_cookie_names() {
	$names = array(
		'sessionid',
		'csrftoken',
		'edxloggedin',
		'edx-user-info',
		'edx-jwt-cookie-header-payload',
		'edx-jwt-cookie-signature',
		'openedx-language-preference',
	);

	/**
	 * Filter the list of cookie names forwarded to the LMS.
	 *
	 * @param string[] $names Default cookie names.
	 */
	return apply_filters( 'tutor_sso_enroll_forwarded_cookies', $names );
}

/**
 * Whether a cookie name should be forwarded to the LMS.
 *
 * @param string $name Cookie name.
 * @return bool
 */
function enroll_is_forwardable_cookie( $name ) {
	return in_array( $name, enroll_forwarded_cookie_names(), true )
		|| 0 === strpos( $name, 'edx-' )
		|| 0 === strpos( $name, 'edx_' );
}

/**
 * Collect the edX cookies to forward, keyed by name, preserving the EXACT raw
 * bytes the browser sent.
 *
 * We parse $_SERVER['HTTP_COOKIE'] rather than $_COOKIE because PHP URL-decodes
 * $_COOKIE values — and cookies such as `edx-user-info` contain spaces, quotes
 * and escaped commas that, once decoded and re-emitted, corrupt the outgoing
 * Cookie header and cause the LMS to mis-parse `sessionid` / `csrftoken`
 * (yielding a 403 on change_enrollment). The raw header is what Postman / the
 * browser send verbatim.
 *
 * @return array<string,string> name => raw value.
 */
function enroll_collect_cookies() {
	$raw = isset( $_SERVER['HTTP_COOKIE'] ) ? wp_unslash( $_SERVER['HTTP_COOKIE'] ) : '';

	if ( '' === $raw ) {
		return array();
	}

	$cookies = array();

	foreach ( explode( ';', $raw ) as $chunk ) {
		$chunk = trim( $chunk );

		if ( '' === $chunk || false === strpos( $chunk, '=' ) ) {
			continue;
		}

		list( $name, $value ) = explode( '=', $chunk, 2 );
		$name = trim( $name );

		if ( '' !== $name && enroll_is_forwardable_cookie( $name ) ) {
			// Keep the value verbatim (already in transport/encoded form).
			$cookies[ $name ] = $value;
		}
	}

	return $cookies;
}

/**
 * Serialize a name => value cookie map into a Cookie header string.
 *
 * @param array<string,string> $cookies Cookie map.
 * @return string
 */
function enroll_serialize_cookies( $cookies ) {
	$pairs = array();

	foreach ( $cookies as $name => $value ) {
		$pairs[] = $name . '=' . $value;
	}

	return implode( '; ', $pairs );
}

/**
 * Build a Cookie header string from the forwardable edX cookies.
 *
 * @return string Cookie header value, or '' when no edX cookies are present.
 */
function enroll_build_cookie_header() {
	return enroll_serialize_cookies( enroll_collect_cookies() );
}

/**
 * Whether the current request carries an authenticated edX session.
 *
 * @return bool
 */
function enroll_has_edx_session() {
	return ! empty( $_COOKIE['edxloggedin'] ) && ! empty( $_COOKIE['sessionid'] );
}

/**
 * Resolve the configured LMS base URL (reuses the SSO setting).
 *
 * @return string Base URL without a trailing slash, or '' when unset.
 */
function enroll_lms_base_url() {
	return rtrim( (string) sso_option( 'lms_base_url' ), '/' );
}

/**
 * Get a CSRF token for the change_enrollment call.
 *
 * Asks the LMS CSRF endpoint first (forwarding the user's cookies) so the token
 * is fresh and valid for the current session, then falls back to the csrftoken
 * cookie already on the request. The caller forces the outgoing csrftoken
 * cookie to equal this value so Django's double-submit check always matches.
 *
 * @param string $cookie_header Pre-built Cookie header to forward.
 * @param string $cookie_token  csrftoken cookie value already on the request.
 * @return string|\WP_Error CSRF token or WP_Error.
 */
function enroll_get_csrf_token( $cookie_header, $cookie_token = '' ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$response = wp_remote_get(
		$base . '/csrf/api/v1/token',
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'         => 'application/json',
				'Referer'        => $base . '/',
				'Cookie'         => $cookie_header,
				'use-jwt-cookie' => 'true',
			),
		)
	);

	if ( ! is_wp_error( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['csrfToken'] ) ) {
			return $body['csrfToken'];
		}
	}

	// Fall back to the token already in the request cookies.
	if ( '' !== $cookie_token ) {
		return $cookie_token;
	}

	return new \WP_Error( 'tutor_sso_no_csrf', __( 'Could not obtain a CSRF token from the LMS.', 'tutor-sso' ) );
}

/**
 * Perform an enroll / unenroll action against the LMS change_enrollment view.
 *
 * @param string $course_id edX course id (course-v1:Org+Course+Run).
 * @param string $action    'enroll' or 'unenroll'.
 * @return array|\WP_Error  { success: bool, status: int, body: string } or WP_Error.
 */
function enroll_change_enrollment( $course_id, $action ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	if ( ! enroll_has_edx_session() ) {
		return new \WP_Error( 'tutor_sso_no_session', __( 'No active LMS session was found. Please log in to the LMS and try again.', 'tutor-sso' ) );
	}

	$action = in_array( $action, array( 'enroll', 'unenroll' ), true ) ? $action : 'enroll';

	$cookies       = enroll_collect_cookies();
	$cookie_token  = isset( $cookies['csrftoken'] ) ? $cookies['csrftoken'] : '';
	$csrf          = enroll_get_csrf_token( enroll_serialize_cookies( $cookies ), $cookie_token );

	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}

	// Force the outgoing csrftoken cookie to match the header token so Django's
	// double-submit CSRF check always passes.
	$cookies['csrftoken'] = $csrf;
	$cookie_header        = enroll_serialize_cookies( $cookies );

	$response = wp_remote_post(
		$base . '/change_enrollment',
		array(
			'timeout'   => 30,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'           => 'application/json, text/plain, */*',
				'Content-Type'     => 'application/x-www-form-urlencoded; charset=UTF-8',
				'Origin'           => $base,
				'Referer'          => $base . '/course/' . $course_id . '/home',
				'X-CSRFToken'      => $csrf,
				'X-Requested-With' => 'XMLHttpRequest',
				'Cookie'           => $cookie_header,
			),
			'body'      => array(
				'course_id'         => $course_id,
				'enrollment_action' => $action,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );

	// Log the LMS response on failure to aid diagnosis (only when WP_DEBUG_LOG).
	if ( ( $status < 200 || $status >= 300 ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'[tutor-sso] change_enrollment (%s) for %s -> HTTP %d: %s',
				$action,
				$course_id,
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
 * Check whether the current user is actively enrolled in a course.
 *
 * Uses the course_home metadata endpoint, which reports the requesting user's
 * own enrollment under `enrollment.is_active`.
 *
 * @param string $course_id edX course id.
 * @return bool|\WP_Error True/false, or WP_Error when the call fails.
 */
function enroll_is_enrolled( $course_id ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	if ( ! enroll_has_edx_session() ) {
		return false;
	}

	$cookie_header = enroll_build_cookie_header();

	$url = $base . '/api/course_home/course_metadata/' . rawurlencode( $course_id );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'         => 'application/json, text/plain, */*',
				'Referer'        => $base . '/',
				'Cookie'         => $cookie_header,
				'use-jwt-cookie' => 'true',
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
			'tutor_sso_status_failed',
			__( 'Could not determine enrollment status from the LMS.', 'tutor-sso' )
		);
	}

	// course_metadata returns { "enrollment": { "is_active": bool, "mode": ... }, ... }.
	if ( isset( $body['enrollment'] ) && is_array( $body['enrollment'] ) ) {
		return ! empty( $body['enrollment']['is_active'] );
	}

	// Some deployments expose a flat is_enrolled flag instead.
	if ( isset( $body['is_enrolled'] ) ) {
		return ! empty( $body['is_enrolled'] );
	}

	return false;
}

/**
 * Build a "go to course" URL for a course id.
 *
 * @param string $course_id edX course id.
 * @return string
 */
function enroll_course_url( $course_id ) {
	$dashboard = (string) sso_option( 'course_dashboard_url' );
	$base      = $dashboard ? rtrim( $dashboard, '/' ) : enroll_lms_base_url();

	if ( empty( $base ) ) {
		return '';
	}

	$url = $base . '/learning/course/' . rawurlencode( $course_id ) . '/home';

	/**
	 * Filter the resolved course URL.
	 *
	 * @param string $url       Default course URL.
	 * @param string $course_id edX course id.
	 */
	return apply_filters( 'tutor_sso_course_url', $url, $course_id );
}
