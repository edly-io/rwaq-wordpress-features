<?php
/**
 * Enrollment codes: join a course or program by typing a code (EDLYCSRWAQ-366).
 *
 * An "Enrollment code" course or program is free, but a learner needs a code
 * from the organization to join it. Open edX does all the checking — whether
 * the code exists, covers this item, is switched on, has uses left — so nothing
 * here validates, counts or stores codes. WordPress shows the code form, sends
 * what the learner typed, and shows the answer:
 *
 *   POST {lms}/rwaq/api/enrollment-codes/redeem/
 *        { "code": "SAFE24", "course_id": "course-v1:..." }    a course
 *        { "code": "SAFE24", "program_key": "program-v1:..." } a program
 *
 *   200 { "detail": "You are enrolled.", "already_enrolled": false, "scope": "organization" }
 *   4xx { "detail": "...", "field": "code" }    `detail` is already in the learner's language
 *
 * A successful code enrolls the learner, and from then on the status calls
 * report `enrollment_code_access: true` for every item the code covers — so a
 * later join (after unenrolling, or another item of the same organization) is
 * an ordinary enroll with no code.
 *
 * Authenticated like the other learner calls: the learner's own Open edX
 * session cookies and a fresh CSRF token (see enrollment-api.php).
 *
 * The form itself is assets/js/enrollment-code.js; the buttons that open it are
 * rendered by render_enroll_button() and program_detail_enroll_button().
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redeem endpoint path, appended to the LMS base URL.
 */
const ENROLLMENT_CODE_REDEEM_ENDPOINT = '/rwaq/api/enrollment-codes/redeem/';

/**
 * Longest code accepted from the form. Codes are short; this only stops an
 * absurd body being forwarded.
 */
const ENROLLMENT_CODE_MAX_LENGTH = 100;

/**
 * Send a code to Open edX for the current learner.
 *
 * @param string $kind 'course' or 'program'.
 * @param string $id   Course id (course-v1:…) or program key (program-v1:…).
 * @param string $code What the learner typed.
 * @return array|\WP_Error { success: bool, status: int, body: array|null } or WP_Error.
 */
function enrollment_code_redeem( $kind, $id, $code ) {
	$base = enroll_lms_base_url();

	if ( empty( $base ) ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	if ( ! enroll_has_edx_session() ) {
		return new \WP_Error( 'tutor_sso_no_session', __( 'لم يتم العثور على جلسة دخول فعّالة في منصة التعلّم. يرجى تسجيل الدخول والمحاولة مرة أخرى.', 'tutor-sso' ) );
	}

	$cookies      = enroll_collect_cookies();
	$cookie_token = isset( $cookies['csrftoken'] ) ? $cookies['csrftoken'] : '';
	$csrf         = enroll_get_csrf_token( enroll_serialize_cookies( $cookies ), $cookie_token );

	if ( is_wp_error( $csrf ) ) {
		return $csrf;
	}

	// Force the outgoing csrftoken cookie to match the header token so Django's
	// double-submit CSRF check always passes.
	$cookies['csrftoken'] = $csrf;

	$payload = array( 'code' => $code );

	if ( 'program' === $kind ) {
		$payload['program_key'] = $id;
	} else {
		$payload['course_id'] = $id;
	}

	$response = wp_remote_post(
		$base . ENROLLMENT_CODE_REDEEM_ENDPOINT,
		array(
			'timeout'   => 30,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'           => 'application/json',
				'Content-Type'     => 'application/json',
				'Origin'           => $base,
				'Referer'          => $base . '/',
				'X-CSRFToken'      => $csrf,
				'X-Requested-With' => 'XMLHttpRequest',
				'Cookie'           => enroll_serialize_cookies( $cookies ),
				'use-jwt-cookie'   => 'true',
			),
			'body'      => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );
	$body   = json_decode( $raw, true );

	// The code itself is left out of the log: it is a shared secret.
	if ( ( $status < 200 || $status >= 300 ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[tutor-sso] enrollment code for %s %s -> HTTP %d: %s', $kind, $id, $status, wp_strip_all_tags( $raw ) )
		);
	}

	return array(
		'success' => $status >= 200 && $status < 300,
		'status'  => $status,
		'body'    => is_array( $body ) ? $body : null,
	);
}

/**
 * AJAX: redeem a code for a course or program, enrolling the learner.
 *
 * POST: nonce, kind (course|program), id, code.
 */
function ajax_redeem_enrollment_code() {
	if ( ! check_ajax_referer( 'tutor_sso_enrollment_code', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'انتهت صلاحية الصفحة. يرجى تحديثها والمحاولة مرة أخرى.', 'tutor-sso' ) ), 403 );
	}

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'يرجى تسجيل الدخول للمتابعة.', 'tutor-sso' ) ), 401 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$kind = isset( $_POST['kind'] ) && 'program' === $_POST['kind'] ? 'program' : 'course';
	$id   = isset( $_POST['id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
	$code = isset( $_POST['code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( '' === $id ) {
		wp_send_json_error( array( 'message' => __( 'Missing course identifier.', 'tutor-sso' ) ), 400 );
	}

	if ( '' === $code ) {
		wp_send_json_error( array( 'message' => __( 'يرجى إدخال رمز التسجيل.', 'tutor-sso' ) ), 400 );
	}

	if ( mb_strlen( $code ) > ENROLLMENT_CODE_MAX_LENGTH ) {
		wp_send_json_error( array( 'message' => __( 'لم يتم العثور على هذا الرمز.', 'tutor-sso' ) ), 400 );
	}

	$result = enrollment_code_redeem( $kind, $id, $code );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
	}

	$body   = is_array( $result['body'] ) ? $result['body'] : array();
	$detail = isset( $body['detail'] ) && is_string( $body['detail'] ) ? trim( $body['detail'] ) : '';
	$status = (int) $result['status'];

	if ( empty( $result['success'] ) ) {
		// Open edX's own words, already in the learner's language: wrong code,
		// expired, used up, closed, full, too many tries.
		if ( '' !== $detail ) {
			$message = $detail;
		} elseif ( 403 === $status ) {
			// Session + CSRF protected: a 403 means the LMS did not accept them.
			$message = __( 'تعذّر التحقق من جلسة الدخول في منصة التعلّم. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 'tutor-sso' );
		} else {
			$message = __( 'حدث خطأ ما. يرجى المحاولة مرة أخرى.', 'tutor-sso' );
		}

		wp_send_json_error(
			array(
				'message' => $message,
				'status'  => $status,
			),
			( $status >= 400 && $status < 500 ) ? $status : 502
		);
	}

	wp_send_json_success(
		array(
			'message'    => '' !== $detail ? $detail : __( 'تم تسجيلك بنجاح.', 'tutor-sso' ),
			'already'    => ! empty( $body['already_enrolled'] ),
			// Where "go to course" points once enrolled. A program's link is
			// already on its button wrapper.
			'course_url' => 'course' === $kind ? enroll_course_url( $id ) : '',
		)
	);
}
add_action( 'wp_ajax_tutor_sso_redeem_enrollment_code', __NAMESPACE__ . '\\ajax_redeem_enrollment_code' );

/**
 * Register the code form's script and style; enqueued by the buttons that need it.
 *
 * @return void
 */
function enrollment_code_register_assets() {
	wp_register_script(
		'tutor-sso-enrollment-code',
		TUTOR_SSO_URL . 'assets/js/enrollment-code.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);

	wp_register_style(
		'tutor-sso-enrollment-code',
		TUTOR_SSO_URL . 'assets/css/enrollment-code.css',
		array( 'tutor-sso-confirm-dialog' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enrollment_code_register_assets' );

/**
 * Enqueue and localize the code form. Called when a button that opens it renders.
 *
 * @return void
 */
function enrollment_code_enqueue_assets() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	wp_enqueue_style( 'tutor-sso-enrollment-code' );
	wp_enqueue_script( 'tutor-sso-enrollment-code' );

	wp_localize_script(
		'tutor-sso-enrollment-code',
		'tutorSsoEnrollmentCode',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_enrollment_code' ),
			'i18n'    => array(
				'title'           => __( 'رمز التسجيل', 'tutor-sso' ),
				'messageCourse'   => __( 'التسجيل في هذا المساق يتطلّب رمز تسجيل.', 'tutor-sso' ),
				'messageProgram'  => __( 'التسجيل في هذا البرنامج يتطلّب رمز تسجيل.', 'tutor-sso' ),
				'placeholder'     => __( 'أدخل رمز التسجيل', 'tutor-sso' ),
				'submit'          => __( 'تأكيد الرمز', 'tutor-sso' ),
				'submitting'      => __( 'جارٍ التحقق…', 'tutor-sso' ),
				'cancel'          => __( 'إلغاء', 'tutor-sso' ),
				'empty'           => __( 'يرجى إدخال رمز التسجيل.', 'tutor-sso' ),
				'error'           => __( 'حدث خطأ ما. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
			),
		)
	);
}

/**
 * The button that opens the code form, in place of a plain enroll button.
 *
 * @param string $kind    'course' or 'program'.
 * @param string $id      Course id or program key.
 * @param string $classes The button's classes, matching the caller's own buttons.
 * @param string $label   Button text.
 * @return string HTML.
 */
function enrollment_code_button( $kind, $id, $classes, $label ) {
	enrollment_code_enqueue_assets();

	return sprintf(
		'<button type="button" class="%1$s rwaq-enrollment-code-open" data-code-kind="%2$s" data-code-id="%3$s" aria-haspopup="dialog">%4$s<span>%5$s</span></button>',
		esc_attr( $classes ),
		esc_attr( 'program' === $kind ? 'program' : 'course' ),
		esc_attr( $id ),
		sso_lock_icon(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		esc_html( $label )
	);
}
