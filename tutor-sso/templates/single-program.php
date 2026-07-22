<?php
/**
 * Single "program" template.
 *
 * Loaded automatically for single `program` posts by program-single.php (via
 * the `single_template` filter) when the active theme does not provide its own
 * single-program.php. Renders the program detail view ([rwaq_program_detail])
 * inside the theme's header/footer — the shortcode reads the current program's
 * `program_key` field and fetches its detail from the LMS.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<main id="primary" class="site-main rwaq-program-single">
		<?php echo do_shortcode( '[rwaq_program_detail]' ); ?>
	</main>
	<?php
endwhile;

get_footer();
