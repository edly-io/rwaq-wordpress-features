<?php
/**
 * Email-confirmation notice modal shortcode + AJAX proxy.
 *
 * On every page that renders this shortcode, JS checks for an active Open edX
 * session. If found, it calls the WP AJAX proxy (which forwards edX cookies
 * server-side) to GET /api/learner_home/init, unless the visitor already has
 * the `edxemailverified` cookie set (meaning the check passed before).
 *
 *   emailConfirmation.isNeeded === true  → email not yet confirmed
 *                                          → show the modal notice every page load.
 *                                          → no cookie set (always re-checks).
 *   emailConfirmation.isNeeded === false → email confirmed
 *                                          → set cookie `edxemailverified=true`.
 *                                          → skip API on all future page loads.
 *                                          → no modal shown.
 *
 * The `edxemailverified` cookie is removed when:
 *   - The edX session disappears (JS clears it on each page load).
 *   - The user completes a fresh SSO login (PHP clears it before the redirect).
 *
 * Shortcode: [tutor_sso_email_confirm content="…" title="…" button_label="…"]
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Asset registration ────────────────────────────────────────────────────────

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_register_script(
			'tutor-sso-email-confirm',
			TUTOR_SSO_URL . 'assets/js/email-confirm.js',
			array( 'jquery' ),
			TUTOR_SSO_VERSION,
			true
		);

		wp_register_style(
			'tutor-sso-email-confirm',
			TUTOR_SSO_URL . 'assets/css/email-confirm.css',
			array(),
			TUTOR_SSO_VERSION
		);
	}
);

// ── AJAX proxy ────────────────────────────────────────────────────────────────

/**
 * Proxy GET /api/learner_home/init to the LMS, forwarding the visitor's edX
 * session cookies. Returns JSON: { "isNeeded": bool }.
 *
 * Registered for both logged-in and non-logged-in WordPress users because a
 * visitor may carry a valid edX session before their WordPress session is set.
 */
function email_confirm_ajax() {
	check_ajax_referer( 'tutor_sso_email_confirm', 'nonce' );

	$base = rtrim( (string) sso_option( 'lms_base_url' ), '/' );

	if ( empty( $base ) ) {
		wp_send_json_error(
			array( 'message' => __( 'LMS Base URL is not configured.', 'tutor-sso' ) ),
			500
		);
	}

	$cookie_header = enroll_build_cookie_header();

	$response = wp_remote_get(
		$base . '/api/learner_home/init',
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

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Unexpected response from LMS.', 'tutor-sso' ) ),
			502
		);
	}

	$is_needed = isset( $body['emailConfirmation']['isNeeded'] )
		? (bool) $body['emailConfirmation']['isNeeded']
		: false;

	wp_send_json_success( array( 'isNeeded' => $is_needed ) );
}
add_action( 'wp_ajax_tutor_sso_email_confirm',        __NAMESPACE__ . '\\email_confirm_ajax' );
add_action( 'wp_ajax_nopriv_tutor_sso_email_confirm', __NAMESPACE__ . '\\email_confirm_ajax' );

// ── Shortcode ─────────────────────────────────────────────────────────────────

/**
 * Render a hidden modal that JS shows when email confirmation is still needed.
 * The modal contains only the provided content and a close (×) button.
 *
 * Attributes:
 *   content      - Notice text / HTML. For rich HTML, use inner content instead.
 *   title        - Optional heading shown above the content.
 *   button_label - Screen-reader label for the close button (default: "Close").
 *
 * Plain-text usage:
 *   [tutor_sso_email_confirm content="Please confirm your email to continue."]
 *
 * Rich-HTML usage:
 *   [tutor_sso_email_confirm title="Confirm your email"]
 *     <p>Check your inbox and click the confirmation link.</p>
 *   [/tutor_sso_email_confirm]
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Inner shortcode content (takes precedence over content= attribute).
 * @return string Modal HTML (hidden by default; JS toggles visibility).
 */
add_shortcode(
	'tutor_sso_email_confirm',
	function ( $atts, $content = '' ) {

		$atts = shortcode_atts(
			array(
				'content'       => '',
				'title'         => '',
				'button_label'  => __( 'Close', 'tutor-sso' ),
				'confirm_label' => '',
			),
			$atts,
			'tutor_sso_email_confirm'
		);

		// Inner content (between tags) wins so rich HTML needs no attribute-encoding.
		$body = $content ? do_shortcode( $content ) : $atts['content'];

		// Lazy enqueue: assets only load on pages that render this shortcode.
		wp_enqueue_style( 'tutor-sso-email-confirm' );
		wp_enqueue_script( 'tutor-sso-email-confirm' );

		wp_localize_script(
			'tutor-sso-email-confirm',
			'tutorSsoEmailConfirm',
			array(
				'ajaxurl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'tutor_sso_email_confirm' ),
				'cookieName' => 'edxemailverified',
				'cookieTtl'  => 30 * DAY_IN_SECONDS, // cache "email verified" for 30 days (seconds)
				'i18n'       => array(
					'close' => esc_html( $atts['button_label'] ),
				),
			)
		);

		ob_start();
		?>
		<div id="tutor-sso-ecm-overlay"
			class="tutor-sso-ecm-overlay"
			aria-hidden="true"
			role="dialog"
			aria-modal="true"
			<?php if ( ! empty( $atts['title'] ) ) : ?>aria-labelledby="tutor-sso-ecm-title"<?php endif; ?>
		>
			<div class="tutor-sso-ecm-modal">
				<button type="button"
					class="tutor-sso-ecm-close"
					aria-label="<?php echo esc_attr( $atts['button_label'] ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
					</svg>
				</button>
				<?php if ( ! empty( $atts['title'] ) ) : ?>
					<div class="tutor-sso-ecm-title-wrap">
						<h2 id="tutor-sso-ecm-title" class="tutor-sso-ecm-title">
							<?php echo esc_html( $atts['title'] ); ?>
						</h2>
					</div>
				<?php endif; ?>
				<div class="tutor-sso-ecm-body">
					<?php echo wp_kses_post( $body ); ?>
				</div>
				<?php if ( ! empty( $atts['confirm_label'] ) ) : ?>
					<button type="button" class="tutor-sso-ecm-confirm">
						<?php echo esc_html( $atts['confirm_label'] ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
);
