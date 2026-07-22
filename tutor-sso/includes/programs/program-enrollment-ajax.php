<?php
/**
 * AJAX handlers for cookie-based program enroll / unenroll / status.
 *
 * Mirrors the course enrollment handlers (enrollment-ajax.php): every handler
 * requires a logged-in WordPress user and a valid nonce, then delegates to the
 * session-based program client (program-enrollment-api.php).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared guard: verify nonce + login, then return the sanitized program key.
 *
 * Sends a JSON error and exits on failure.
 *
 * @return string Sanitized program key.
 */
function program_enroll_ajax_guard() {
	if ( ! check_ajax_referer( 'tutor_sso_program_enroll', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid security token. Please refresh and try again.', 'tutor-sso' ) ), 403 );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please log in to continue.', 'tutor-sso' ) ), 401 );
	}

	$program_id = isset( $_POST['program_id'] ) ? sanitize_text_field( wp_unslash( $_POST['program_id'] ) ) : '';

	if ( empty( $program_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing program identifier.', 'tutor-sso' ) ), 400 );
	}

	return $program_id;
}

/**
 * Map an enroll/unenroll result into a JSON response.
 *
 * @param array|\WP_Error $result      Result from program_change_enrollment().
 * @param string          $success_msg Message on success.
 */
function program_send_change_result( $result, $success_msg ) {
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
	}

	if ( empty( $result['success'] ) ) {
		$status = isset( $result['status'] ) ? (int) $result['status'] : 0;

		// Surface a meaningful LMS message when the body is JSON. The programs API
		// uses `detail`; fall back to `message` for parity with other endpoints.
		$decoded = json_decode( (string) $result['body'], true );

		if ( is_array( $decoded ) && ! empty( $decoded['detail'] ) ) {
			$message = $decoded['detail'];
		} elseif ( is_array( $decoded ) && ! empty( $decoded['message'] ) ) {
			$message = $decoded['message'];
		} elseif ( 403 === $status ) {
			// The endpoint is session + CSRF protected; a 403 means the LMS did
			// not accept the forwarded session / CSRF token.
			$message = __( 'Your LMS session could not be verified. Please make sure you are logged in to the LMS, then reload this page and try again.', 'tutor-sso' );
		} else {
			$message = __( 'The request failed. Please try again later.', 'tutor-sso' );
		}

		wp_send_json_error(
			array(
				'message' => $message,
				'status'  => $status,
			),
			502
		);
	}

	wp_send_json_success( array( 'message' => $success_msg ) );
}

/**
 * AJAX: enroll the current user into a program.
 */
function ajax_program_enroll() {
	$program_id = program_enroll_ajax_guard();

	// Already-enrolled guard avoids a redundant enroll call. We only short-circuit
	// on an explicit positive; WP_Error falls through to the enroll attempt.
	$enrolled = program_is_enrolled( $program_id );
	if ( true === $enrolled ) {
		wp_send_json_success(
			array(
				'message' => __( 'تم تسجيلك بنجاح.', 'tutor-sso' ),
				'already' => true,
			)
		);
	}

	$result = program_change_enrollment( $program_id, 'enroll' );

	program_send_change_result(
		$result,
		__( 'تم تسجيلك بنجاح.', 'tutor-sso' )
	);
}
add_action( 'wp_ajax_tutor_sso_program_enroll', __NAMESPACE__ . '\\ajax_program_enroll' );

/**
 * AJAX: unenroll the current user from a program.
 */
function ajax_program_unenroll() {
	$program_id = program_enroll_ajax_guard();

	$result = program_change_enrollment( $program_id, 'unenroll' );

	program_send_change_result(
		$result,
		__( 'تم إلغاء تسجيلك من هذه الدورة.', 'tutor-sso' )
	);
}
add_action( 'wp_ajax_tutor_sso_program_unenroll', __NAMESPACE__ . '\\ajax_program_unenroll' );

/**
 * AJAX: report the current user's enrollment status for a program.
 */
function ajax_program_enroll_status() {
	$program_id = program_enroll_ajax_guard();

	$enrolled = program_is_enrolled( $program_id );

	if ( is_wp_error( $enrolled ) ) {
		wp_send_json_error( array( 'message' => $enrolled->get_error_message() ), 502 );
	}

	wp_send_json_success( array( 'enrolled' => (bool) $enrolled ) );
}
add_action( 'wp_ajax_tutor_sso_program_enroll_status', __NAMESPACE__ . '\\ajax_program_enroll_status' );
