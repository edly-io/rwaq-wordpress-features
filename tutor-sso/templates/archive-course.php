<?php
/**
 * Archive template for the "course" custom post type (URL: /courses/).
 *
 * The courses catalog is API-driven (see courses-catalog.php / the LMS public
 * API), so this archive does not run the WordPress loop — it simply hosts the
 * [rwaq_courses] shortcode inside a width-constrained wrapper. The shortcode
 * lazily enqueues its own CSS/JS (including the .rwaq-courses-archive styling).
 *
 * A theme can override this by providing its own archive-course.php (see
 * courses-archive.php).
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="rwaq-courses-archive">
	<?php
	// per_page is managed centrally via the "Courses per page" setting, which the
	// shortcode uses as its default — no attribute needed here.
	echo do_shortcode( '[rwaq_courses]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>

<?php
get_footer();
