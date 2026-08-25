<?php
/**
 * Plugin Name:       Tutor LMS SSO
 * Plugin URI:        https://edly.io
 * Description:       Single Sign-On (SSO) between WordPress and Tutor LMS / Open edX via OAuth 2.0.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Edly Team
 * Author URI:        https://edly.io
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-sso
 * Domain Path:       /languages
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TUTOR_SSO_VERSION',  '1.0.0' );
define( 'TUTOR_SSO_FILE',     __FILE__ );
define( 'TUTOR_SSO_PATH',     plugin_dir_path( __FILE__ ) );
define( 'TUTOR_SSO_URL',      plugin_dir_url( __FILE__ ) );
define( 'TUTOR_SSO_BASENAME', plugin_basename( __FILE__ ) );

require_once TUTOR_SSO_PATH . 'admin/class-settings-page.php';
require_once TUTOR_SSO_PATH . 'includes/class-oauth-handler.php';
require_once TUTOR_SSO_PATH . 'includes/sso-functions.php';
require_once TUTOR_SSO_PATH . 'includes/media-helpers.php';
require_once TUTOR_SSO_PATH . 'includes/analytics-code.php';
require_once TUTOR_SSO_PATH . 'includes/enrollment-api.php';
require_once TUTOR_SSO_PATH . 'includes/enrollment-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/enrollment-shortcode.php';
require_once TUTOR_SSO_PATH . 'includes/email-confirm-shortcode.php';
require_once TUTOR_SSO_PATH . 'includes/elementor/elementor-widget-loader.php';
require_once TUTOR_SSO_PATH . 'includes/partner-logo-shortcode.php';
require_once TUTOR_SSO_PATH . 'includes/partner-name-shortcode.php';
require_once TUTOR_SSO_PATH . 'includes/programs/rest/routes.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-client.php';
require_once TUTOR_SSO_PATH . 'includes/courses/rest/routes.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-client.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-catalog.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-archive.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-catalog.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-archive.php';
require_once TUTOR_SSO_PATH . 'includes/programs/program-enrollment-api.php';
require_once TUTOR_SSO_PATH . 'includes/programs/program-enrollment-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-detail.php';
require_once TUTOR_SSO_PATH . 'includes/programs/program-single.php';
require_once TUTOR_SSO_PATH . 'includes/blogs/blogs-query.php';
require_once TUTOR_SSO_PATH . 'includes/blogs/blogs-catalog.php';
require_once TUTOR_SSO_PATH . 'includes/blogs/blogs-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/blogs/blogs-page-template.php';
require_once TUTOR_SSO_PATH . 'includes/blogs/blog-detail.php';
require_once TUTOR_SSO_PATH . 'includes/ambassadors/ambassadors-page-template.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partners-client.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partners-catalog.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partners-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partners-page-template.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partners-archive.php';
require_once TUTOR_SSO_PATH . 'includes/partners/partner-detail.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/instructors-client.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/instructor-detail.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/rest/routes.php';
require_once TUTOR_SSO_PATH . 'includes/blocks/quote-block.php';

// Boot the admin settings UI.
add_action( 'plugins_loaded', function () {
	new \TutorSSO\Admin\Settings_Page();
} );

/**
 * Load the plugin text domain so the bundled translations (e.g. Arabic in
 * /languages) apply. Hooked on `init` per current WordPress guidance.
 *
 * Translations also live in wp-content/languages/plugins/ if managed there;
 * that location takes precedence over the bundled files.
 */
function tutor_sso_load_textdomain() {
	load_plugin_textdomain(
		'tutor-sso',
		false,
		dirname( TUTOR_SSO_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'tutor_sso_load_textdomain' );

/**
 * Register and enqueue the IBM Plex Sans Arabic webfont globally.
 *
 * Loaded on every front-end page (not just where the programs catalog runs) so
 * it is available to the catalog and any other pages that use it. The programs
 * catalog stylesheet lists 'tutor-sso-programs-font' as a dependency, so it is
 * always loaded before it.
 */
function tutor_sso_register_font_assets() {
	wp_register_style(
		'tutor-sso-programs-font',
		'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap',
		array(),
		null
	);
	wp_enqueue_style( 'tutor-sso-programs-font' );
}
add_action( 'wp_enqueue_scripts', 'tutor_sso_register_font_assets' );

/**
 * Register front-end enrollment assets. They are only enqueued when a button is
 * actually rendered (see \TutorSSO\enroll_enqueue_assets()).
 */
function tutor_sso_register_enroll_assets() {
	wp_register_script(
		'tutor-sso-enroll',
		TUTOR_SSO_URL . 'assets/js/enroll.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);

	wp_register_style(
		'tutor-sso-enroll',
		TUTOR_SSO_URL . 'assets/css/enroll.css',
		array(),
		TUTOR_SSO_VERSION
	);

	// On RTL sites (e.g. Arabic) WordPress loads assets/css/enroll-rtl.css
	// in place of enroll.css automatically.
	wp_style_add_data( 'tutor-sso-enroll', 'rtl', 'replace' );
}
add_action( 'wp_enqueue_scripts', 'tutor_sso_register_enroll_assets' );

/**
 * Enqueue + localize the enrollment assets. Called lazily by the renderer so
 * the script never loads on pages without an enroll button.
 */
function tutor_sso_enroll_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-enroll' );
	wp_enqueue_script( 'tutor-sso-enroll' );

	wp_localize_script(
		'tutor-sso-enroll',
		'tutorSsoEnroll',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_enroll' ),
			'i18n'    => array(
				'enroll'           => __( 'Enroll', 'tutor-sso' ),
				'enrolling'        => __( 'Enrolling…', 'tutor-sso' ),
				'enrolled'         => __( 'Enrolled', 'tutor-sso' ),
				'unenroll'         => __( 'Unenroll', 'tutor-sso' ),
				'unenrolling'      => __( 'Unenrolling…', 'tutor-sso' ),
				'goToCourse'       => __( 'Go to Course', 'tutor-sso' ),
				'confirmUnenroll'  => __( 'Are you sure you want to unenroll from this course?', 'tutor-sso' ),
				'error'            => __( 'Something went wrong. Please try again.', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Public template helper — render the enroll button from PHP templates.
 *
 * @param string $course_id edX course id (course-v1:Org+Course+Run).
 * @param array  $args      Optional overrides forwarded to the renderer.
 * @return string Button HTML.
 */
function tutor_sso_render_enroll_button( $course_id, $args = array() ) {
	return \TutorSSO\render_enroll_button( $course_id, $args );
}

/**
 * Register front-end program-enrollment assets. Enqueued lazily, only when the
 * program detail view actually renders an enroll button for a logged-in user
 * (see \TutorSSO\program_detail_enroll_button()).
 */
function tutor_sso_register_program_enroll_assets() {
	wp_register_script(
		'tutor-sso-program-enroll',
		TUTOR_SSO_URL . 'assets/js/program-enroll.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'tutor_sso_register_program_enroll_assets' );

/**
 * Enqueue + localize the program-enrollment script. The button reuses the
 * catalog stylesheet (tutor-sso-programs), so only the script is enqueued here.
 */
function tutor_sso_program_enroll_enqueue_assets() {
	wp_enqueue_script( 'tutor-sso-program-enroll' );

	wp_localize_script(
		'tutor-sso-program-enroll',
		'tutorSsoProgramEnroll',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_program_enroll' ),
			'i18n'    => array(
				'enroll'          => __( 'سجّل الآن', 'tutor-sso' ),
				'enrolling'       => __( 'جارٍ التسجيل…', 'tutor-sso' ),
				'unenroll'        => __( 'إلغاء التسجيل', 'tutor-sso' ),
				'unenrolling'     => __( 'جارٍ إلغاء التسجيل…', 'tutor-sso' ),
				'goToProgram'     => __( 'اذهب إلى البرنامج', 'tutor-sso' ),
				'confirmUnenroll' => __( 'هل أنت متأكد من رغبتك في إلغاء التسجيل من هذا البرنامج؟', 'tutor-sso' ),
				'error'           => __( 'حدث خطأ ما. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Public API — use this in themes or other plugins.
 *
 * Generates a fresh SSO login URL that includes a CSRF-safe state token.
 * Because the state token is time-limited, avoid caching pages that print
 * this URL — generate it dynamically on each request.
 *
 * @return string Full OAuth 2.0 authorization URL.
 */
function tutor_sso_get_login_url() {
	return \TutorSSO\get_lms_login_url();
}
