<?php
/**
 * Trim the WooCommerce My Account navigation.
 *
 * Downloads is not part of this site's account area: courses and programs are
 * not downloadable files.
 *
 * Addresses stays: it holds the billing address checkout fills in by default.
 * Payment methods stays too: it is where a subscriber replaces a card before it
 * expires. See includes/subscriptions/subscriptions-payment-methods.php for
 * what keeps the last card from being deleted.
 *
 * Done through `woocommerce_account_menu_items` rather than by hiding the
 * links in CSS, so the items are genuinely gone — WooCommerce also reads this
 * list when deciding what to render, and a CSS-hidden link is still a link.
 *
 * The downloads endpoint itself is left reachable; only its menu entry goes.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account menu endpoints this site does not use.
 *
 * @return string[] Endpoint keys, as used by wc_get_account_menu_items().
 */
function my_account_hidden_endpoints() {
	/**
	 * Filter the account menu endpoints removed from the navigation.
	 *
	 * @param string[] $endpoints Endpoint keys.
	 */
	return (array) apply_filters(
		'tutor_sso_my_account_hidden_endpoints',
		array( 'downloads' )
	);
}

/**
 * Remove those endpoints from the account navigation.
 *
 * @param array $items Menu items, keyed by endpoint.
 * @return array
 */
function my_account_filter_menu_items( $items ) {
	if ( ! is_array( $items ) ) {
		return $items;
	}

	foreach ( my_account_hidden_endpoints() as $endpoint ) {
		unset( $items[ $endpoint ] );
	}

	return $items;
}
add_filter( 'woocommerce_account_menu_items', __NAMESPACE__ . '\\my_account_filter_menu_items' );
