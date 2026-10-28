<?php
/**
 * Say so when an individual-use coupon takes other coupons off the cart.
 *
 * A coupon marked "Individual use only" cannot be combined with others, so
 * WooCommerce removes every coupon already applied before adding it
 * (WC_Cart::apply_coupon()). It does that silently: the customer sees only
 * "Coupon code applied successfully" and a discount they had is gone.
 *
 * WooCommerce's behaviour is kept — the new coupon still wins. This only adds
 * a notice, beside the success message, naming each coupon that was removed
 * and the coupon that removed it. It works wherever a coupon is applied, cart
 * or checkout, since both go through apply_coupon().
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the notice, from the hook WooCommerce asks just before removing.
 *
 * `woocommerce_apply_individual_use_coupon` returns the codes to keep; every
 * applied code not in it is removed straight after. Run late so the list is
 * final, and returned unchanged — this decides nothing.
 *
 * @param string[]   $keep    Codes to keep (WooCommerce's default: none).
 * @param \WC_Coupon $coupon  The individual-use coupon being applied.
 * @param string[]   $applied Codes currently applied.
 * @return string[]
 */
function coupon_individual_use_notice( $keep, $coupon, $applied ) {
	if ( ! function_exists( 'wc_add_notice' ) || ! $coupon instanceof \WC_Coupon ) {
		return $keep;
	}

	// Only for a customer applying a coupon on the site — not an admin adding
	// one to an order, or code applying one in the background.
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $keep;
	}

	$removed = array_values( array_diff( (array) $applied, (array) $keep ) );

	if ( ! $removed ) {
		return $keep;
	}

	$message = 1 === count( $removed )
		/* translators: 1: removed coupon code, 2: the individual-use coupon code. */
		? __( 'تمت إزالة القسيمة %1$s لأن القسيمة %2$s لا يمكن استخدامها مع قسائم أخرى.', 'tutor-sso' )
		/* translators: 1: removed coupon codes, comma separated, 2: the individual-use coupon code. */
		: __( 'تمت إزالة القسائم %1$s لأن القسيمة %2$s لا يمكن استخدامها مع قسائم أخرى.', 'tutor-sso' );

	wc_add_notice(
		sprintf(
			$message,
			esc_html( implode( '، ', $removed ) ),
			esc_html( $coupon->get_code() )
		),
		'notice'
	);

	return $keep;
}
add_filter( 'woocommerce_apply_individual_use_coupon', __NAMESPACE__ . '\\coupon_individual_use_notice', 999, 3 );
