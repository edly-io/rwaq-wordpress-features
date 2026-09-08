<?php
/**
 * Branded "page not found" (404) view.
 *
 * The view is also available as [rwaq_404] so it can be dropped into an
 * Elementor page or a normal page if the site prefers routing 404s that way.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the 404 view's assets.
 *
 * not-found.css depends on the IBM Plex Sans Arabic webfont
 * ('tutor-sso-programs-font', registered and enqueued globally in
 * tutor-sso.php) plus Otomanopee One, which the design uses for the "404"
 * numeral only. That second family is registered here rather than globally
 * because this is the only view that needs it, and it is a dependency of the
 * stylesheet so it always loads first.
 *
 * The stylesheet uses logical properties, so no RTL companion file is needed.
 */
function not_found_register_assets() {
	wp_register_style(
		'tutor-sso-not-found-font',
		'https://fonts.googleapis.com/css2?family=Otomanopee+One&display=swap',
		array(),
		null
	);

	wp_register_style(
		'tutor-sso-not-found',
		TUTOR_SSO_URL . 'assets/css/not-found.css',
		array( 'tutor-sso-programs-font', 'tutor-sso-not-found-font' ),
		TUTOR_SSO_VERSION
	);

	// On a 404 the view is certain to render, and this hook still runs inside
	// wp_head — so enqueue now to get the stylesheet into the head. Left to the
	// shortcode's lazy enqueue it would be printed by print_late_styles() in the
	// footer, flashing the page unstyled first. Every other request registers
	// only, so neither file is requested anywhere else.
	if ( not_found_should_render() ) {
		not_found_enqueue_assets();
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\not_found_register_assets' );

/**
 * Enqueue the 404 assets. Called lazily by the shortcode.
 */
function not_found_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-not-found' );
}

/**
 * URL of a bundled 404 asset.
 *
 * @param string $file File name inside assets/images/not-found/.
 * @return string
 */
function not_found_asset( $file ) {
	return TUTOR_SSO_URL . 'assets/images/not-found/' . $file;
}

/**
 * Where the "browse available courses" action points.
 *
 * Prefers the course CPT archive (/courses/), which is the same post type the
 * courses archive view is registered for, and falls back to /courses/ when the
 * CPT is not registered (e.g. the catalog lives on a plain page instead).
 *
 * @return string
 */
function not_found_courses_url() {
	$url = '';

	if ( function_exists( __NAMESPACE__ . '\\courses_archive_post_type' ) ) {
		$archive = get_post_type_archive_link( courses_archive_post_type() );
		$url     = $archive ? $archive : '';
	}

	if ( '' === $url ) {
		$url = home_url( '/courses/' );
	}

	/**
	 * Filter the 404 page's "browse courses" destination.
	 *
	 * @param string $url Resolved courses URL.
	 */
	return (string) apply_filters( 'tutor_sso_404_courses_url', $url );
}

/**
 * Render the 404 view.
 *
 * @return string HTML.
 */
function not_found_render() {
	not_found_enqueue_assets();

	$home_url    = home_url( '/' );
	$courses_url = not_found_courses_url();

	ob_start();
	?>
	<section class="rwaq-404">
		<div class="rwaq-404__inner">
			<img
				class="rwaq-404__blob"
				src="<?php echo esc_url( not_found_asset( 'blob.svg' ) ); ?>"
				width="551"
				height="551"
				alt=""
				aria-hidden="true"
			/>

			<div class="rwaq-404__stack">
				<div class="rwaq-404__mark">
					<img
						class="rwaq-404__brackets"
						src="<?php echo esc_url( not_found_asset( 'corner-brackets.svg' ) ); ?>"
						width="220"
						height="150"
						alt=""
						aria-hidden="true"
					/>
					<span class="rwaq-404__code">404</span>
				</div>

				<div class="rwaq-404__text">
					<h1 class="rwaq-404__title"><?php echo esc_html__( 'الصفحة غير موجودة', 'tutor-sso' ); ?></h1>
					<p class="rwaq-404__desc"><?php echo esc_html__( 'الرابط الذي حاولت الوصول إليه غير متاح، ربما تم نقله أو حذفه.', 'tutor-sso' ); ?></p>
				</div>

				<div class="rwaq-404__actions">
					<a class="rwaq-404__btn rwaq-404__btn--primary" href="<?php echo esc_url( $home_url ); ?>">
						<span class="rwaq-404__btn-label"><?php echo esc_html__( 'العودة إلى الرئيسية', 'tutor-sso' ); ?></span>
					</a>
					<a class="rwaq-404__btn rwaq-404__btn--subtle" href="<?php echo esc_url( $courses_url ); ?>">
						<span class="rwaq-404__btn-label"><?php echo esc_html__( 'تصفح الدورات المتاحة', 'tutor-sso' ); ?></span>
					</a>
				</div>
			</div>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_404].
 *
 * How templates/404.php renders the view, and usable on any page.
 *
 * @return string HTML.
 */
function not_found_shortcode() {
	return not_found_render();
}
add_shortcode( 'rwaq_404', __NAMESPACE__ . '\\not_found_shortcode' );

/**
 * Whether the plugin's 404 view should serve the current request.
 *
 * @return bool
 */
function not_found_should_render() {
	if ( is_admin() || is_feed() || is_embed() ) {
		return false;
	}

	// Only the main query's 404 — a secondary WP_Query finding nothing must not
	// swap the whole template out.
	if ( ! is_404() || ! is_main_query() ) {
		return false;
	}

	/**
	 * Filter whether the bundled 404 template replaces the theme's.
	 *
	 * Return false to hand 404s back to the active theme.
	 *
	 * @param bool $enabled Whether to use the plugin's 404 template.
	 */
	return (bool) apply_filters( 'tutor_sso_enable_404_template', true );
}

/**
 * Serve the bundled 404 template for any not-found request.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function not_found_template( $template ) {
	if ( ! not_found_should_render() ) {
		return $template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/404.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
// Late priority: the theme's 404.php is already resolved by the time
// template_include runs, and a late hook also wins over themes or page builders
// that filter template_include themselves at the default priority.
add_filter( 'template_include', __NAMESPACE__ . '\\not_found_template', 99 );
