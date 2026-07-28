<?php
/**
 * "Blog Listing" page template registration.
 *
 * Adds a selectable page template (Page Attributes → Template → "Blog Listing")
 * provided by the plugin — no theme edits needed. The template renders the page's
 * Featured Image as a full-width banner, then the page content inside a 1280px
 * <main> (where the [rwaq_blogs] shortcode is placed). See
 * templates/page-blog-listing.php.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-template slug stored in the `_wp_page_template` post meta.
 */
const BLOG_LISTING_TEMPLATE = 'tutor-sso-blog-listing';

/**
 * Add "Blog Listing" to the Page Attributes → Template dropdown.
 *
 * @param array $templates Existing page templates (slug => label).
 * @return array
 */
function blogs_register_page_template( $templates ) {
	$templates[ BLOG_LISTING_TEMPLATE ] = __( 'Blog Listing', 'tutor-sso' );
	return $templates;
}
add_filter( 'theme_page_templates', __NAMESPACE__ . '\\blogs_register_page_template' );

/**
 * Whether the current main request is a page using the Blog Listing template.
 *
 * @return bool
 */
function blogs_is_page_template() {
	return is_singular() && BLOG_LISTING_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

/**
 * Load the plugin's template file when the Blog Listing template is selected.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function blogs_load_page_template( $template ) {
	if ( blogs_is_page_template() ) {
		$file = TUTOR_SSO_PATH . 'templates/page-blog-listing.php';
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\blogs_load_page_template' );

/**
 * Ensure the listing stylesheet (which also styles the banner + main wrapper) is
 * loaded on the Blog Listing template, even if the page has no [rwaq_blogs]
 * shortcode to enqueue it.
 */
function blogs_page_template_assets() {
	if ( blogs_is_page_template() ) {
		wp_enqueue_style( 'tutor-sso-blog' );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\blogs_page_template_assets' );
