<?php
/**
 * "Rwaq Search Results" page template.
 *
 * Loaded for pages using the Search template (see includes/search/search-page.php).
 * Renders the search results ([rwaq_search_results]) inside the theme's
 * header/footer — the theme still supplies both.
 *
 * The wrapper keeps the theme's `site-main` class so the page inherits its
 * vertical rhythm, plus a plugin class that search.css uses to lift the theme's
 * content-width cap — without it a narrow theme squeezes the design's 1232px
 * column.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main rwaq-search-page">
	<?php echo do_shortcode( '[rwaq_search_results]' ); ?>
</main>
<?php
get_footer();
