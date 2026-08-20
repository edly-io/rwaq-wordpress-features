<?php
/**
 * Single "instructor" template.
 *
 * Loaded automatically for single `instructor` posts by instructor-detail.php
 * (via the `single_template` filter) when the active theme does not provide its
 * own single-instructor.php. Renders the instructor detail view
 * ([rwaq_instructor_detail]) inside the theme's header/footer — the shortcode
 * builds the view from the current instructor's custom fields.
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
	<main id="primary" class="site-main rwaq-instructor-single">
		<?php echo do_shortcode( '[rwaq_instructor_detail]' ); ?>
	</main>
	<?php
endwhile;

get_footer();
