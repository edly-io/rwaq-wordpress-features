<?php
/**
 * Single "course" template.
 *
 * Loaded automatically for single `course` posts by
 * includes/courses/course-detail.php (via the `single_template` filter) when the
 * active theme does not provide its own single-course.php.
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
	<main id="primary" class="site-main rwaq-course-single">
		<?php echo do_shortcode( '[rwaq_course_detail]' ); ?>
	</main>
	<?php
endwhile;

get_footer();
