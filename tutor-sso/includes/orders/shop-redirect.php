<?php
/**
 * Send the WooCommerce shop page to the courses catalog.
 *
 * Products here exist only to take payment for a course, program or plan —
 * nobody is meant to browse them as a shop. The catalog at /courses/ is the
 * real listing, so the shop page redirects there rather than showing a grid of
 * payment products.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the shop page should go.
 *
 * Resolved from the course post type's archive rather than a hardcoded
 * "/courses/", so it follows whatever rewrite slug the post type was registered
 * with — that registration lives outside this plugin.
 *
 * @return string URL, or '' when it cannot be resolved.
 */
function shop_redirect_target() {
	$url = '';

	if ( function_exists( __NAMESPACE__ . '\\courses_archive_post_type' ) ) {
		$archive = get_post_type_archive_link( courses_archive_post_type() );

		if ( is_string( $archive ) && '' !== $archive ) {
			$url = $archive;
		}
	}

	/**
	 * Filter where the shop page redirects to.
	 *
	 * @param string $url Resolved courses archive URL.
	 */
	return (string) apply_filters( 'tutor_sso_shop_redirect_url', $url );
}

/**
 * Compare two URLs by host and path, ignoring scheme, query and fragment.
 *
 * Used to make sure the shop is not being redirected to itself.
 *
 * @param string $a First URL.
 * @param string $b Second URL.
 * @return bool
 */
function shop_redirect_same_page( $a, $b ) {
	$normalize = static function ( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		return $host . $path;
	};

	$a = $normalize( $a );

	return '' !== $a && $a === $normalize( $b );
}

/**
 * Redirect the shop page.
 *
 * `is_shop()` covers the shop page and its pagination, and not product
 * categories or single products — those still work, since a course detail page
 * links to its product to start checkout.
 *
 * @return void
 */
function shop_redirect() {
	if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
		return;
	}

	$url = shop_redirect_target();

	// Nothing to send them to: better to leave the shop working than to
	// redirect to the home page and lose the request.
	if ( '' === $url ) {
		return;
	}

	// If the catalog somehow resolves to the shop page itself, redirecting
	// would loop until the browser gives up.
	$current = ( is_ssl() ? 'https://' : 'http://' )
		. ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' )
		. ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' );

	if ( shop_redirect_same_page( $url, $current ) ) {
		return;
	}

	/**
	 * Filter the redirect status.
	 *
	 * 302 by default, deliberately: a 301 is cached hard by browsers and
	 * search engines, so reinstating the shop page later would still send
	 * anyone who saw it to the catalog, for a long time and with nothing on
	 * the server able to undo it. Switch to 301 once the decision is settled.
	 *
	 * @param int $status HTTP status code.
	 */
	$status = (int) apply_filters( 'tutor_sso_shop_redirect_status', 302 );

	wp_safe_redirect( $url, $status );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\shop_redirect' );
