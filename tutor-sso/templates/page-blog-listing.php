<?php
/**
 * "Blog Listing" page template.
 *
 * Registered programmatically by the plugin (see blogs-page-template.php) — it is
 * selectable under Page Attributes → Template → "Blog Listing", not via this
 * file's header. Renders a full-width hero banner (the page's Featured Image as
 * the background, with the heading/subtitle set directly below), then the page
 * content inside a width-constrained (1280px) <main> — place the [rwaq_blogs]
 * shortcode in the page content.
 *
 * A theme can override this by providing its own page template.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	$banner_bg = has_post_thumbnail() ? get_the_post_thumbnail_url( get_the_ID(), 'full' ) : '';

	// Banner text: ACF textarea fields (blog_banner_heading / blog_banner_subtitle)
	// when available, otherwise the built-in defaults.
	$heading  = function_exists( 'get_field' ) ? (string) get_field( 'blog_banner_heading' ) : '';
	$subtitle = function_exists( 'get_field' ) ? (string) get_field( 'blog_banner_subtitle' ) : '';

	if ( '' === trim( $heading ) ) {
		$heading = __( 'تعلّم من الأفضل وابقَ في طليعة التطوّر', 'tutor-sso' );
	}
	if ( '' === trim( $subtitle ) ) {
		$subtitle = __( 'رؤى عملية في التقنية، والتصميم، وتطوير المسار المهني — يكتبها خبراء طبّقوا هذه المعرفة فعليًا، وليس فقط من درسوها.', 'tutor-sso' );
	}
	?>

	<div class="rwaq-blog-listing">
		<section class="rwaq-blog-listing__banner"<?php echo $banner_bg ? ' style="background-image:url(' . esc_url( $banner_bg ) . ');"' : ''; ?>>
			<div class="rwaq-blog-listing__banner-inner">
				<h1 class="rwaq-blog-listing__banner-title"><?php echo nl2br( esc_html( $heading ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
				<p class="rwaq-blog-listing__banner-subtitle"><?php echo nl2br( esc_html( $subtitle ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>
		</section>

		<main class="rwaq-blog-listing__main">
			<?php the_content(); ?>
		</main>
	</div>

	<?php
endwhile;

get_footer();
