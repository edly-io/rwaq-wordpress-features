<?php
/**
 * Partners archive template.
 *
 * Loaded for the `partner` post-type archive by
 * includes/partners/partners-archive.php when the active theme does not provide
 * its own archive-partner.php. Renders the partners catalog
 * ([rwaq_partners]) inside the theme's header/footer — the theme still supplies
 * both; only the region between them is owned here.
 *
 * The wrapper keeps the theme's `site-main` class so the page inherits its
 * vertical rhythm, plus a plugin class that partners.css uses to lift the
 * theme's content-width cap — without it a narrow theme squeezes the design's
 * 1232px column. If the column still renders narrow, the theme is constraining
 * an ancestor of this element rather than this element itself.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main rwaq-partners-archive">
	<?php echo do_shortcode( '[rwaq_partners]' ); ?>
</main>
<?php
get_footer();
