<?php
/**
 * "Page not found" (404) template.
 *
 * Loaded automatically for every 404 request by includes/not-found/not-found.php
 * (via the `template_include` filter). Renders the branded not-found view
 * ([rwaq_404]) inside the theme's header/footer.
 *
 * Unlike the plugin's other templates this one takes precedence over the
 * theme's 404.php — see not-found.php for why, and for the
 * `tutor_sso_enable_404_template` opt-out.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main rwaq-404-main">
	<?php echo do_shortcode( '[rwaq_404]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>

<?php
get_footer();
