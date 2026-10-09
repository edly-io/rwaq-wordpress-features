<?php
/**
 * Stop a course or program being bought twice.
 *
 * The course and program detail pages already show "go to course" rather than a
 * buy button once someone owns the item, but that is presentation only. A
 * direct ?add-to-cart= link, the WooCommerce product page itself, or an item
 * left sitting in the cart from before the purchase all reach checkout without
 * passing that view — so the rule is enforced here as well, where it cannot be
 * walked around.
 *
 * Scoped to courses and programs: a product with no LMS identifier is not
 * something this plugin sells access to, and a subscription plan is explicitly
 * re-buyable — someone who cancels has to be able to subscribe again.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current customer already owns this product.
 *
 * Decided from WooCommerce's own order history rather than the LMS: it is the
 * record of what was bought *here*, needs no HTTP call on a request the
 * customer is waiting on, and cannot leave the cart broken when the LMS is
 * unreachable. The detail pages still consult the LMS, so an entitlement that
 * came from somewhere else is handled there.
 *
 * @param int $product_id Product being added or checked out.
 * @return bool
 */
function purchase_already_owned( $product_id ) {
	$product_id = (int) $product_id;

	if ( ! $product_id || ! is_user_logged_in() || ! function_exists( 'wc_customer_bought_product' ) ) {
		return false;
	}

	// Only courses and programs. orders_api_item_identifier() returns a
	// course_id / program_key, and an empty array for anything else.
	if ( ! function_exists( __NAMESPACE__ . '\\orders_api_item_identifier' )
		|| ! orders_api_item_identifier( $product_id ) ) {
		return false;
	}

	$user = wp_get_current_user();

	$owned = wc_customer_bought_product( (string) $user->user_email, (int) $user->ID, $product_id );

	/**
	 * Filter whether the customer already owns this product.
	 *
	 * @param bool $owned      Whether a past paid order contains it.
	 * @param int  $product_id Product ID.
	 */
	return (bool) apply_filters( 'tutor_sso_purchase_already_owned', $owned, $product_id );
}

/**
 * The message shown when someone tries to buy something twice.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function purchase_duplicate_notice( $product_id ) {
	$title = get_the_title( (int) $product_id );

	if ( '' === trim( (string) $title ) ) {
		return __( 'لقد اشتريت هذا من قبل، ويمكنك الوصول إليه من حسابك.', 'tutor-sso' );
	}

	return sprintf(
		/* translators: %s: course or program name. */
		__( 'لقد اشتريت "%s" من قبل، ويمكنك الوصول إليه من حسابك.', 'tutor-sso' ),
		$title
	);
}

/**
 * Refuse to add something already owned to the cart.
 *
 * @param bool $passed     Whether validation has passed so far.
 * @param int  $product_id Product being added.
 * @return bool
 */
function purchase_block_add_to_cart( $passed, $product_id ) {
	if ( ! $passed || ! purchase_already_owned( $product_id ) ) {
		return $passed;
	}

	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice( purchase_duplicate_notice( $product_id ), 'error' );
	}

	return false;
}
add_filter( 'woocommerce_add_to_cart_validation', __NAMESPACE__ . '\\purchase_block_add_to_cart', 10, 2 );

/**
 * Catch anything already in the cart that has since been bought.
 *
 * Runs on the cart and checkout pages. The usual way in is a second tab, or a
 * cart left from before the purchase — the item passed validation when it was
 * added, so the check above never saw it.
 *
 * The item is removed rather than only flagged: an error the customer cannot
 * clear without finding and deleting the line themselves is a dead end.
 *
 * @return void
 */
function purchase_remove_owned_from_cart() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( ! purchase_already_owned( $product_id ) ) {
			continue;
		}

		WC()->cart->remove_cart_item( $key );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( purchase_duplicate_notice( $product_id ), 'notice' );
		}
	}
}
add_action( 'woocommerce_check_cart_items', __NAMESPACE__ . '\\purchase_remove_owned_from_cart' );
