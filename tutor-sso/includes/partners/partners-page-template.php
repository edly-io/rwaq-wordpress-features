<?php
/**
 * "Rwaq Partners" page template registration.
 *
 * Adds a selectable page template (Page Attributes → Template → "Rwaq
 * Partners") provided by the plugin — no theme edits needed. The template
 * renders the partners catalog ([rwaq_partners]) inside the theme's
 * header/footer. Mirrors the blogs listing page template, which is the
 * precedent here for a listing page that is not a post-type archive.
 *
 * The shortcode also works standalone, so the catalog can be dropped into an
 * Elementor page instead of using this template.
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
const PARTNERS_TEMPLATE = 'tutor-sso-rwaq-partners';

/**
 * Add "Rwaq Partners" to the Page Attributes → Template dropdown.
 *
 * @param array $templates Existing page templates (slug => label).
 * @return array
 */
function partners_register_page_template( $templates ) {
	$templates[ PARTNERS_TEMPLATE ] = __( 'Rwaq Partners', 'tutor-sso' );
	return $templates;
}
add_filter( 'theme_page_templates', __NAMESPACE__ . '\\partners_register_page_template' );

/**
 * Whether the current main request is a page using the Partners template.
 *
 * @return bool
 */
function partners_is_page_template() {
	return is_singular() && PARTNERS_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

/**
 * Load the plugin's template file when the Partners template is selected.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function partners_load_page_template( $template ) {
	if ( partners_is_page_template() ) {
		$file = TUTOR_SSO_PATH . 'templates/page-rwaq-partners.php';
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\partners_load_page_template' );
