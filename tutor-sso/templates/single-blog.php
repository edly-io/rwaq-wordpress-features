<?php
/**
 * Single "blog" (post) template.
 *
 * Loaded automatically for single blog posts by blog-detail.php (via the
 * `single_template` filter) when the active theme does not provide its own
 * single-blog.php. Renders the blog detail view ([rwaq_blog_detail]) inside the
 * theme's header/footer — the shortcode renders the current post's own title,
 * featured image, meta and content.
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
	<main id="primary" class="site-main rwaq-blog-single">
		<?php echo do_shortcode( '[rwaq_blog_detail]' ); ?>
	</main>
	<?php
endwhile;

get_footer();
