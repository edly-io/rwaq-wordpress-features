<?php
/**
 * Front-end analytics output for the settings configured under
 * Settings → Tutor LMS SSO → Analytics.
 *
 * Two independent tags, each skipped when its option is empty:
 *
 * - Google Tag Manager — container ID only; the snippet lives here, in code.
 * - Google Analytics 4 — measurement ID only; likewise.
 *
 * Both are safe for any administrator to configure because the stored value is
 * pattern-checked down to [A-Z0-9-] and can never escape the template it is
 * dropped into — the admin supplies an ID, never markup. Nothing here echoes
 * user-supplied HTML.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether analytics markup may be printed for the current request.
 *
 * Keeps tracking off admin screens, feeds and machine endpoints so it only
 * fires on real page views. `wp_head` does not run on wp-login.php, so the
 * login form is never instrumented either.
 *
 * @return bool
 */
function analytics_output_allowed() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}

	/**
	 * Master switch for every analytics tag.
	 *
	 * Return false to suppress all output — for a cookie-consent gate, to keep
	 * staging out of production reporting, or to exclude logged-in staff.
	 *
	 * @param bool $enabled Whether to print analytics.
	 */
	return (bool) apply_filters( 'tutor_sso_analytics_enabled', true );
}

/**
 * Read a tracking ID, re-validating it against its pattern.
 *
 * The settings sanitizer already enforces this, but the check is repeated at
 * output time so a value written around the Settings API — a database import,
 * a migration script, direct SQL — still cannot inject markup.
 *
 * @param string $option  Option name.
 * @param string $pattern Anchored regex the ID must match.
 * @return string Valid ID, or '' when unset or malformed.
 */
function get_tracking_id( $option, $pattern ) {
	$id = strtoupper( trim( (string) get_option( $option, '' ) ) );

	return preg_match( $pattern, $id ) ? $id : '';
}

/**
 * Google Tag Manager — the container loader, printed as early in <head> as
 * possible so tags fire before the rest of the page renders.
 */
function print_gtm_head() {
	if ( ! analytics_output_allowed() ) {
		return;
	}

	$id = get_tracking_id( 'tutor_sso_gtm_id', \TutorSSO\Admin\Settings_Page::GTM_ID_PATTERN );

	if ( '' === $id ) {
		return;
	}
	?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
<!-- End Google Tag Manager -->
<?php
}
add_action( 'wp_head', __NAMESPACE__ . '\print_gtm_head', 1 );

/**
 * Google Tag Manager — the <noscript> fallback, which Google requires
 * immediately after the opening <body> tag.
 *
 * Depends on the active theme calling wp_body_open(); themes older than
 * WordPress 5.2 may not, in which case only JavaScript-disabled visitors go
 * untracked.
 */
function print_gtm_body() {
	if ( ! analytics_output_allowed() ) {
		return;
	}

	$id = get_tracking_id( 'tutor_sso_gtm_id', \TutorSSO\Admin\Settings_Page::GTM_ID_PATTERN );

	if ( '' === $id ) {
		return;
	}
	?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $id ); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php
}
add_action( 'wp_body_open', __NAMESPACE__ . '\print_gtm_body', 1 );

/**
 * Google Analytics 4 — the standard gtag.js snippet.
 */
function print_ga4() {
	if ( ! analytics_output_allowed() ) {
		return;
	}

	$id = get_tracking_id( 'tutor_sso_ga_id', \TutorSSO\Admin\Settings_Page::GA_ID_PATTERN );

	if ( '' === $id ) {
		return;
	}
	?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?php echo esc_js( $id ); ?>');
</script>
<?php
}
add_action( 'wp_head', __NAMESPACE__ . '\print_ga4', 2 );
