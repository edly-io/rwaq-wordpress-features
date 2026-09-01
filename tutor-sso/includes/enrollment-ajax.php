<?php
/**
 * AJAX handlers for cookie-based enroll / unenroll / status.
 *
 * All handlers require a logged-in WordPress user and a valid nonce. The actual
 * LMS calls reuse the user's edX session cookies (see enrollment-api.php).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared guard: verify nonce + login, then return the sanitized course id.
 *
 * Sends a JSON error and exits on failure.
 *
 * @return string Sanitized course id.
 */
function enroll_ajax_guard() {
	if ( ! check_ajax_referer( 'tutor_sso_enroll', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please log in to continue.', 'tutor-sso' ) ), 401 );
	}

	$course_id = isset( $_POST['course_id'] ) ? sanitize_text_field( wp_unslash( $_POST['course_id'] ) ) : '';

	if ( empty( $course_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing course identifier.', 'tutor-sso' ) ), 400 );
	}

	return $course_id;
}

/**
 * Normalize an LMS message for matching.
 *
 * The LMS localizes its error bodies, and the Arabic translations vary in
 * diacritics (تعذّرت vs تعذرت) and letter forms (أ/ا, ة/ه), so raw string
 * comparison is unreliable. Strip the marks and unify the variants so both the
 * haystack and the needles reduce to the same shape.
 *
 * @param string $text Raw message text.
 * @return string Normalized text.
 */
function enroll_normalize_message_text( $text ) {
	$text = wp_strip_all_tags( (string) $text );

	// Drop Arabic diacritics (tashkeel) and the tatweel elongation character.
	$stripped = preg_replace( '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text );
	if ( null !== $stripped ) {
		$text = $stripped;
	}

	// Unify letter forms that differ between translations of the same phrase.
	$text = str_replace(
		array( 'أ', 'إ', 'آ', 'ٱ', 'ى', 'ة' ),
		array( 'ا', 'ا', 'ا', 'ا', 'ي', 'ه' ),
		$text
	);

	$collapsed = preg_replace( '/\s+/u', ' ', $text );
	if ( null !== $collapsed ) {
		$text = $collapsed;
	}

	return strtolower( trim( $text ) );
}

/**
 * Whether a failed change_enrollment response means the course is full.
 *
 * @param int        $status  HTTP status returned by the LMS.
 * @param string     $body    Raw response body.
 * @param array|null $decoded Body decoded as JSON, when it was JSON.
 * @return bool
 */
function enroll_is_course_full_response( $status, $body, $decoded = null ) {
	if ( 400 !== (int) $status ) {
		return false;
	}

	$text = is_array( $decoded )
		? (string) ( isset( $decoded['message'] ) ? $decoded['message'] : ( isset( $decoded['detail'] ) ? $decoded['detail'] : '' ) )
		: $body;

	$text = enroll_normalize_message_text( $text );

	if ( '' === $text ) {
		// A bare 400 from change_enrollment is a refused enrollment; full course
		// is by far the most common cause.
		return true;
	}

	/**
	 * Filter the phrases that identify a "course is full" rejection.
	 *
	 * Matched against the normalized response body, so entries are normalized
	 * the same way before comparison.
	 *
	 * @param string[] $needles Phrases to look for.
	 */
	$needles = apply_filters(
		'tutor_sso_course_full_phrases',
		array(
			// English (default LMS locale).
			'could not enroll',
			'course is full',
			'enrollment is full',
			// Arabic (the LMS translates the body per the session's language).
			'تعذّرت عمليّة التسجيل',
			'الدورة ممتلئة',
		)
	);

	foreach ( $needles as $needle ) {
		$needle = enroll_normalize_message_text( $needle );

		if ( '' !== $needle && false !== strpos( $text, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Map an enroll/unenroll result into a JSON response.
 *
 * @param array|\WP_Error $result      Result from enroll_change_enrollment().
 * @param string          $course_id   Course id (for the go-to-course URL).
 * @param string          $success_msg Message on success.
 */
function enroll_send_change_result( $result, $course_id, $success_msg ) {
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
	}

	if ( empty( $result['success'] ) ) {
		$status = isset( $result['status'] ) ? (int) $result['status'] : 0;
		$body   = (string) $result['body'];

		// Try to surface a meaningful LMS message if the body is JSON.
		$decoded = json_decode( $body, true );
		$is_full = enroll_is_course_full_response( $status, $body, $decoded );

		if ( $is_full ) {
			// change_enrollment answers a full course with a bare 400 /
			// "Could not enroll"; translate that into something actionable.
			$message = __( 'تم الوصول إلى الحد الأقصى لعدد المسجلين في الدورة. الدورة مكتملة العدد.', 'tutor-sso' );
		} elseif ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
			$message = $decoded['message'];
		} elseif ( 403 === $status ) {
			// change_enrollment is session + CSRF protected; a 403 means the LMS
			// did not accept the forwarded session / CSRF token.
			$message = __( 'Your LMS session could not be verified. Please make sure you are logged in to the LMS, then reload this page and try again.', 'tutor-sso' );
		} else {
			$message = __( 'The request failed. Please try again later.', 'tutor-sso' );
		}

		$payload = array(
			'message' => $message,
			'status'  => $status,
			'full'    => $is_full,
		);

		// Surface the raw LMS reply to developers only, so an unrecognised
		// failure can be diagnosed from the browser's network tab.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$payload['lms_body'] = wp_strip_all_tags( $body );
		}

		wp_send_json_error( $payload, 502 );
	}

	wp_send_json_success(
		array(
			'message'    => $success_msg,
			'course_url' => enroll_course_url( $course_id ),
		)
	);
}

/**
 * AJAX: enroll the current user into a course.
 */
function ajax_enroll() {
	$course_id = enroll_ajax_guard();

	// Already-enrolled guard avoids a redundant change_enrollment call. We only
	// short-circuit on an explicit positive; WP_Error falls through to enroll.
	$enrolled = enroll_is_enrolled( $course_id );
	if ( true === $enrolled ) {
		wp_send_json_success(
			array(
				'message'    => __( 'You are already enrolled in this course.', 'tutor-sso' ),
				'already'    => true,
				'course_url' => enroll_course_url( $course_id ),
			)
		);
	}

	$result = enroll_change_enrollment( $course_id, 'enroll' );

	enroll_send_change_result(
		$result,
		$course_id,
		__( 'You have been enrolled successfully.', 'tutor-sso' )
	);
}
add_action( 'wp_ajax_tutor_sso_enroll', __NAMESPACE__ . '\\ajax_enroll' );

/**
 * AJAX: unenroll the current user from a course.
 */
function ajax_unenroll() {
	$course_id = enroll_ajax_guard();

	$result = enroll_change_enrollment( $course_id, 'unenroll' );

	enroll_send_change_result(
		$result,
		$course_id,
		__( 'You have been unenrolled from this course.', 'tutor-sso' )
	);
}
add_action( 'wp_ajax_tutor_sso_unenroll', __NAMESPACE__ . '\\ajax_unenroll' );

/**
 * AJAX: report the current user's enrollment status for a course.
 */
function ajax_enroll_status() {
	$course_id = enroll_ajax_guard();

	$enrolled = enroll_is_enrolled( $course_id );

	if ( is_wp_error( $enrolled ) ) {
		wp_send_json_error( array( 'message' => $enrolled->get_error_message() ), 502 );
	}

	wp_send_json_success(
		array(
			'enrolled'   => (bool) $enrolled,
			'course_url' => enroll_course_url( $course_id ),
		)
	);
}
add_action( 'wp_ajax_tutor_sso_enroll_status', __NAMESPACE__ . '\\ajax_enroll_status' );
