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
require_once TUTOR_SSO_PATH . 'includes/account-menu.php';
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
require_once TUTOR_SSO_PATH . 'includes/courses/course-detail-client.php';
require_once TUTOR_SSO_PATH . 'includes/courses/course-detail.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-admin-fields.php';
require_once TUTOR_SSO_PATH . 'includes/courses/courses-product-sync.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-catalog.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-archive.php';
require_once TUTOR_SSO_PATH . 'includes/programs/program-enrollment-api.php';
require_once TUTOR_SSO_PATH . 'includes/programs/program-enrollment-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-detail.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-product-sync.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-admin-fields.php';
require_once TUTOR_SSO_PATH . 'includes/programs/programs-order-enrollment.php';
require_once TUTOR_SSO_PATH . 'includes/orders/orders-api.php';
require_once TUTOR_SSO_PATH . 'includes/orders/order-course-links.php';
require_once TUTOR_SSO_PATH . 'includes/orders/cart-styles.php';
require_once TUTOR_SSO_PATH . 'includes/orders/checkout-styles.php';
require_once TUTOR_SSO_PATH . 'includes/orders/checkout-payment-heading.php';
require_once TUTOR_SSO_PATH . 'includes/orders/order-received-styles.php';
require_once TUTOR_SSO_PATH . 'includes/orders/my-account-styles.php';
require_once TUTOR_SSO_PATH . 'includes/orders/my-account-menu.php';
require_once TUTOR_SSO_PATH . 'includes/orders/my-account-form.php';
require_once TUTOR_SSO_PATH . 'includes/orders/shop-redirect.php';
require_once TUTOR_SSO_PATH . 'includes/orders/duplicate-purchase.php';
require_once TUTOR_SSO_PATH . 'includes/orders/address-fields.php';
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
require_once TUTOR_SSO_PATH . 'includes/partners/rest/routes.php';
require_once TUTOR_SSO_PATH . 'includes/search/search-client.php';
require_once TUTOR_SSO_PATH . 'includes/search/search-page.php';
require_once TUTOR_SSO_PATH . 'includes/search/search-ajax.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/instructors-client.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/instructor-detail.php';
require_once TUTOR_SSO_PATH . 'includes/instructors/rest/routes.php';
require_once TUTOR_SSO_PATH . 'includes/blocks/quote-block.php';
require_once TUTOR_SSO_PATH . 'includes/not-found/not-found.php';

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
 * Version every plugin asset by its own file's modification time.
 *
 * TUTOR_SSO_VERSION alone is a constant that a release has to remember to
 * bump, and when it isn't, a deploy ships changed CSS and JS under a URL that
 * browsers and CDNs already hold — the file is new, the cache entry is not.
 * Appending each file's mtime makes the URL change exactly when that file's
 * contents do, whatever the deploy method and with nothing to remember.
 *
 * Applied as a filter on the final URL rather than as a version argument at
 * each wp_register_*() call: that covers every asset the plugin has now and
 * every one added later, instead of leaving the next one to be registered
 * wrongly in the same way.
 *
 * Per file, not per plugin: an edit to one stylesheet leaves the other assets
 * on their existing URLs, still cached.
 *
 * @param string $src    Asset URL, as WordPress assembled it.
 * @param string $handle Registered handle.
 * @return string URL, re-versioned when it is one of this plugin's own files.
 */
function tutor_sso_asset_version_src( $src, $handle ) {
	if ( 0 !== strpos( (string) $handle, 'tutor-sso-' ) ) {
		return $src;
	}

	// Compared without the scheme: a site serving over HTTPS while the plugin
	// URL was built as HTTP (or behind a proxy that rewrites one to the other)
	// would otherwise fail this test and silently keep the stale version.
	$base  = preg_replace( '#^https?:#', '', TUTOR_SSO_URL );
	$clean = preg_replace( '#^https?:#', '', explode( '?', (string) $src, 2 )[0] );

	// Not a local file — the webfont handle points at Google Fonts.
	if ( '' === $base || 0 !== strpos( $clean, $base ) ) {
		return $src;
	}

	$path  = TUTOR_SSO_PATH . substr( $clean, strlen( $base ) );
	$mtime = is_readable( $path ) ? filemtime( $path ) : false;

	// Unreadable, or a stat that failed: leave the URL exactly as it was
	// rather than replacing a working version with an empty one.
	if ( ! $mtime ) {
		return $src;
	}

	return add_query_arg( 'ver', TUTOR_SSO_VERSION . '.' . $mtime, remove_query_arg( 'ver', $src ) );
}
add_filter( 'style_loader_src', 'tutor_sso_asset_version_src', 10, 2 );
add_filter( 'script_loader_src', 'tutor_sso_asset_version_src', 10, 2 );

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
 * Register the logged-in account dropdown's assets. Enqueued lazily by
 * \TutorSSO\render_account_menu(), so neither file loads on a page that never
 * prints the menu (every logged-out page, for one).
 */
function tutor_sso_register_account_menu_assets() {
	wp_register_style(
		'tutor-sso-account-menu',
		TUTOR_SSO_URL . 'assets/css/account-menu.css',
		// The menu states the design's own type rather than inheriting the
		// theme's, so the webfont has to be in before it.
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// No RTL swap: account-menu.css sets its own `direction: rtl` and uses
	// logical properties throughout, so there is nothing a mirrored copy
	// would need to override (see the enroll-assets note below for why a
	// second file is avoided).
	wp_register_script(
		'tutor-sso-account-menu',
		TUTOR_SSO_URL . 'assets/js/account-menu.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'tutor_sso_register_account_menu_assets' );

/**
 * Enqueue the account dropdown's assets. Called by the renderer.
 */
function tutor_sso_account_menu_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-account-menu' );
	wp_enqueue_script( 'tutor-sso-account-menu' );
}

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
