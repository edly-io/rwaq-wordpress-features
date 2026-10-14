<?php
/**
 * Trim the WooCommerce My Account navigation.
 *
 * Downloads, Addresses and Payment methods are not part of this site's account
 * area: courses and programs are not downloadable files, and payment details
 * are held by the gateway rather than stored against the customer here.
 *
 * Done through `woocommerce_account_menu_items` rather than by hiding the
 * links in CSS, so the items are genuinely gone — WooCommerce also reads this
 * list when deciding what to render, and a CSS-hidden link is still a link.
 *
 * The endpoints themselves are deliberately left reachable: WooCommerce links
 * to the address endpoint from the order/checkout flow, and breaking those
 * links would be a wider change than removing three menu entries.
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
		array( 'downloads', 'edit-address', 'payment-methods' )
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
