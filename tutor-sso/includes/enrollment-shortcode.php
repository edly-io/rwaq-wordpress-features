<?php
/**
 * Enroll button renderer + shortcode.
 *
 * Usage:
 *   [tutor_enroll_button course_id="course-v1:Rwaq+116+2026"]
 *   <?php echo tutor_sso_render_enroll_button( 'course-v1:Rwaq+116+2026' ); ?>
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the edX course id to use.
 *
 * Precedence: an explicitly provided id wins; otherwise fall back to the ACF
 * field `openedx_course_id` on the current post (read via ACF's get_field(),
 * or raw post meta when ACF is not active). Returns '' when nothing is found —
 * the renderer treats an empty id as "render nothing".
 *
 * @param string $course_id Explicit course id (may be empty).
 * @return string
 */
function resolve_course_id( $course_id = '' ) {
	$course_id = trim( (string) $course_id );

	if ( '' !== $course_id ) {
		return $course_id;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	if ( function_exists( 'get_field' ) ) {
		$acf = get_field( 'openedx_course_id', $post_id );
		if ( ! empty( $acf ) ) {
			return trim( (string) $acf );
		}
	}

	$meta = get_post_meta( $post_id, 'openedx_course_id', true );

	return $meta ? trim( (string) $meta ) : '';
}

/**
 * Render the enroll button (with logged-out / enrolled / unenroll variants).
 *
 * @param string $course_id edX course id.
 * @param array  $args      Optional overrides (enrolled flag, labels).
 * @return string HTML.
 */
function render_enroll_button( $course_id, $args = array() ) {
	$course_id = trim( (string) $course_id );

	if ( empty( $course_id ) ) {
		return '';
	}

	// Load assets only when a button is actually rendered.
	\tutor_sso_enroll_enqueue_assets();

	$defaults = array(
		'enroll_label'     => __( 'Enroll', 'tutor-sso' ),
		'unenroll_label'   => __( 'Unenroll', 'tutor-sso' ),
		'goto_label'       => __( 'Go to Course', 'tutor-sso' ),
		'login_label'      => __( 'Log in to Enroll', 'tutor-sso' ),
		'enroll_message'   => '', // Shown on successful enroll; blank = server default.
		'unenroll_message' => '', // Shown on successful unenroll; blank = server default.
		'show_unenroll'    => true,
		'show_goto'        => true,
	);
	$args = wp_parse_args( $args, $defaults );

	$show_unenroll = ! empty( $args['show_unenroll'] );
	$show_goto     = ! empty( $args['show_goto'] );

	// Logged-out: route through the SSO login flow, returning to this page.
	if ( ! is_user_logged_in() ) {
		$login_url = function_exists( 'tutor_sso_get_login_url' )
			? tutor_sso_get_login_url()
			: wp_login_url( get_permalink() ? get_permalink() : home_url() );

		return sprintf(
			'<div class="tutor-sso-enroll-wrap"><a class="tutor-sso-enroll-btn tutor-sso-enroll-btn--login" href="%1$s">%2$s</a></div>',
			esc_url( $login_url ),
			esc_html( $args['login_label'] )
		);
	}

	// Allow callers to pass a precomputed flag to avoid a per-render API call.
	if ( isset( $args['enrolled'] ) ) {
		$is_enrolled = (bool) $args['enrolled'];
	} else {
		$status      = enroll_is_enrolled( $course_id );
		$is_enrolled = ( true === $status ); // WP_Error → treat as not enrolled.
	}

	$course_url = enroll_course_url( $course_id );

	ob_start();
	?>
	<div
		class="tutor-sso-enroll-wrap"
		data-course-id="<?php echo esc_attr( $course_id ); ?>"
		data-enroll-label="<?php echo esc_attr( $args['enroll_label'] ); ?>"
		data-unenroll-label="<?php echo esc_attr( $args['unenroll_label'] ); ?>"
		data-goto-label="<?php echo esc_attr( $args['goto_label'] ); ?>"
		data-enroll-message="<?php echo esc_attr( $args['enroll_message'] ); ?>"
		data-unenroll-message="<?php echo esc_attr( $args['unenroll_message'] ); ?>"
		data-show-unenroll="<?php echo $show_unenroll ? 'true' : 'false'; ?>"
		data-show-goto="<?php echo $show_goto ? 'true' : 'false'; ?>"
	>
		<?php if ( $is_enrolled ) : ?>
			<?php if ( $show_goto && $course_url ) : ?>
				<a class="tutor-sso-enroll-btn tutor-sso-enroll-btn--goto" href="<?php echo esc_url( $course_url ); ?>">
					<?php echo esc_html( $args['goto_label'] ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $show_unenroll ) : ?>
				<button type="button" class="tutor-sso-enroll-btn tutor-sso-enroll-btn--unenroll tutor-sso-unenroll">
					<?php echo esc_html( $args['unenroll_label'] ); ?>
				</button>
			<?php endif; ?>
		<?php else : ?>
			<button type="button" class="tutor-sso-enroll-btn tutor-sso-enroll-btn--enroll tutor-sso-enroll">
				<?php echo esc_html( $args['enroll_label'] ); ?>
			</button>
		<?php endif; ?>
		<div class="tutor-sso-enroll-message" role="status" aria-live="polite"></div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [tutor_enroll_button course_id="course-v1:Org+Course+Run"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function enroll_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'course_id'        => '',
			'enroll_label'     => '',
			'unenroll_label'   => '',
			'goto_label'       => '',
			'login_label'      => '',
			'enroll_message'   => '',
			'unenroll_message' => '',
		),
		$atts,
		'tutor_enroll_button'
	);

	// Only forward non-empty overrides so renderer defaults still apply.
	$args = array();
	foreach ( array( 'enroll_label', 'unenroll_label', 'goto_label', 'login_label', 'enroll_message', 'unenroll_message' ) as $key ) {
		if ( '' !== $atts[ $key ] ) {
			$args[ $key ] = $atts[ $key ];
		}
	}

	// Fall back to the ACF `openedx_course_id` field when no id is supplied.
	return render_enroll_button( resolve_course_id( $atts['course_id'] ), $args );
}
add_shortcode( 'tutor_enroll_button', __NAMESPACE__ . '\\enroll_shortcode' );
