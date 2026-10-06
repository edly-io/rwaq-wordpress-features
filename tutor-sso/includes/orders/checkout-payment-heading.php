<?php
/**
 * Add a "طريقة الدفع" heading above the checkout payment methods.
 *
 * The design shows one; the current checkout template doesn't render any
 * heading there at all, so this adds the one missing element rather than
 * faking it with CSS. Purely additive — the payment list itself is untouched.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Echo the heading, right before WooCommerce's #payment div.
 */
function checkout_payment_heading() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	echo '<h3 class="tutor-sso-checkout-payment-heading">' . esc_html__( 'طريقة الدفع', 'tutor-sso' ) . '</h3>';
}
add_action( 'woocommerce_review_order_before_payment', __NAMESPACE__ . '\\checkout_payment_heading' );
