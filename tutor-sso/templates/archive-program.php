<?php
/**
 * Archive template for the "program" custom post type (URL: /programs/).
 *
 * The programs catalog is API-driven (see programs-catalog.php / the LMS public
 * API), so this archive does not run the WordPress loop — it simply hosts the
 * [rwaq_programs] shortcode inside a width-constrained wrapper. The shortcode
 * lazily enqueues its own CSS/JS (including the .rwaq-programs-archive styling).
 *
 * A theme can override this by providing its own archive-program.php (see
 * programs-archive.php).
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="rwaq-programs-archive">
	<?php
	// per_page is managed centrally via the "Programs per page" setting, which the
	// shortcode uses as its default — no attribute needed here.
	echo do_shortcode( '[rwaq_programs]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>

<?php
get_footer();
