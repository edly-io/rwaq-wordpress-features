<?php
/**
 * Partners archive template loader.
 *
 * Serves templates/archive-partner.php for the `partner` post-type archive, so
 * the partners catalog is the archive view with no page setup — mirroring
 * courses-archive.php and programs-archive.php.
 *
 * A theme can still override by providing its own archive-partner.php.
 *
 * Note that the catalog itself does not read the `partner` posts: the cards come
 * from the LMS organizations API (see partners-client.php). The post type
 * supplies the archive URL and the per-partner detail pages the cards link to,
 * which are matched by slug — see partner_detail_url().
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type whose archive shows the partners catalog.
 *
 * @return string
 */
function partners_archive_post_type() {
	/**
	 * Filter the post type whose archive the plugin's partners template serves.
	 *
	 * @param string $post_type Default 'partner'.
	 */
	return (string) apply_filters( 'tutor_sso_partner_post_type', 'partner' );
}

/**
 * Use the plugin's archive-partner.php for the `partner` archive.
 *
 * Runs on `template_include`, which fires after the specific `archive_template`
 * filter, so this also wins over a theme's generic archive.php.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function partners_archive_template( $template ) {
	if ( ! is_post_type_archive( partners_archive_post_type() ) ) {
		return $template;
	}

	// Respect a theme-provided archive-partner.php if one exists.
	$theme_template = locate_template( array( 'archive-partner.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/archive-partner.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\partners_archive_template' );
