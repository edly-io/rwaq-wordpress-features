<?php
/**
 * Single "partner" template.
 *
 * Loaded automatically for single `partner` posts by
 * includes/partners/partner-detail.php (via the `single_template` filter) when
 * the active theme does not provide its own single-partner.php. Renders the
 * partner detail view ([rwaq_partner_detail]) inside the theme's header/footer.
 *
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
	<main id="primary" class="site-main rwaq-partner-single">
		<?php echo do_shortcode( '[rwaq_partner_detail]' ); ?>
	</main>
	<?php
endwhile;

get_footer();
