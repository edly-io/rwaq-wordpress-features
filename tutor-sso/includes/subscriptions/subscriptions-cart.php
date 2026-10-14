<?php
/**
 * A subscription is bought on its own.
 *
 * Mixing a plan with one-off courses would mean a single order that is partly
 * recurring and partly not: the renewal would have to re-charge only some of
 * its lines, and the order sent to the LMS would describe both at once. The
 * cart is kept exclusive instead, which keeps every renewal order an exact
 * copy of its parent.
 *
 * The rule is "last action wins" rather than refusing the addition: whatever
 * the customer just asked for is what they get, and the cart is adjusted around
 * it. Refusing instead would leave them at a dead end with no way forward but
 * to find and empty the cart themselves.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a product is a subscription plan.
 *
 * @param int $product_id Product or variation ID.
 * @return bool
 */
function subscription_product_is_subscription( $product_id ) {
	$product_id = (int) $product_id;

	if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
		return false;
	}

	$product = wc_get_product( $product_id );

	return $product instanceof \WC_Product && SUBSCRIPTION_PRODUCT_TYPE === $product->get_type();
}

/**
 * Whether the cart currently holds a subscription.
 *
 * @return bool
 */
function subscription_cart_has_subscription() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 );

		if ( subscription_product_is_subscription( $id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Clear whatever cannot sit alongside the item just added.
 *
 * Runs on `woocommerce_add_to_cart`, which fires from WC_Cart::add_to_cart() —
 * so it covers the classic templates, the Store API used by block checkout, and
 * an add-to-cart URL alike, rather than only the form POST.
 *
 * @param string $cart_item_key Key of the item just added.
 * @param int    $product_id    Product added.
 * @param int    $quantity      Quantity added (unused).
 * @param int    $variation_id  Variation added, 0 when not a variation.
 * @return void
 */
function subscription_enforce_exclusive_cart( $cart_item_key, $product_id, $quantity = 0, $variation_id = 0 ) {
	// Checked first: with the feature off there are no plans to be exclusive
	// about, and this would otherwise walk the whole cart on every add.
	if ( ! subscriptions_enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$added_is_subscription = subscription_product_is_subscription( $variation_id ? $variation_id : $product_id );
	$removed_subscription  = false;
	$removed_other         = false;

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( $key === $cart_item_key ) {
			continue;
		}

		$id             = ! empty( $item['variation_id'] ) ? $item['variation_id'] : ( $item['product_id'] ?? 0 );
		$is_subscription = subscription_product_is_subscription( $id );

		// Remove when either side of the pairing is a subscription. Two
		// ordinary products are left alone; a second plan counts as "another
		// product" and replaces the first, so only one plan is ever in play.
		if ( ! $added_is_subscription && ! $is_subscription ) {
			continue;
		}

		WC()->cart->remove_cart_item( $key );

		if ( $is_subscription ) {
			$removed_subscription = true;
		} else {
			$removed_other = true;
		}
	}

	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}

	// Say what happened. A cart that silently empties itself reads as a bug.
	if ( $removed_subscription ) {
		wc_add_notice(
			__( 'تمت إزالة الاشتراك من السلة، لأنه لا يمكن شراؤه مع منتجات أخرى.', 'tutor-sso' ),
			'notice'
		);
	}

	if ( $removed_other ) {
		wc_add_notice(
			__( 'تمت إزالة العناصر الأخرى من السلة، لأنه لا يمكن شراء الاشتراك مع منتجات أخرى.', 'tutor-sso' ),
			'notice'
		);
	}
}
add_action( 'woocommerce_add_to_cart', __NAMESPACE__ . '\\subscription_enforce_exclusive_cart', 20, 4 );

/**
 * One of a plan per order.
 *
 * Quantity 2 of a monthly plan has no meaning — it would charge twice for one
 * subscription. WooCommerce enforces this itself once the product reports it,
 * including on the product page and in the cart's quantity field.
 *
 * @param bool        $sold_individually Current value.
 * @param \WC_Product $product           Product being asked about.
 * @return bool
 */
function subscription_sold_individually( $sold_individually, $product ) {
	if ( ! subscriptions_enabled() ) {
		return $sold_individually;
	}

	if ( $product instanceof \WC_Product && SUBSCRIPTION_PRODUCT_TYPE === $product->get_type() ) {
		return true;
	}

	return $sold_individually;
}
add_filter( 'woocommerce_is_sold_individually', __NAMESPACE__ . '\\subscription_sold_individually', 10, 2 );
