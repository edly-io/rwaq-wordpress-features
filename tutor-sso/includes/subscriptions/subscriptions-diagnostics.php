<?php
/**
 * Why a subscription ended up with no card, recorded on the order.
 *
 * Whether a renewal can happen depends on a chain that runs across three
 * plugins: the cart has to read as a subscription, the gateway has to be asked
 * for a token, the gateway has to come back with one, and it has to be saved
 * against the customer. When the card is missing at the end of that, nothing
 * says which link broke — the gateway logs some of its own decisions and
 * silently skips others.
 *
 * So the state every link depends on is written to the order as a note, once,
 * after everyone involved has had their turn. An order note rather than the
 * error log: it is readable by whoever is looking at the order, it cannot fill a
 * log file, and it is attached to the thing it describes.
 *
 * Only in testing mode. This is for working out why a card went missing, not
 * something to leave running on a live shop.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describe the token state of a paid subscription order.
 *
 * Runs at priority 30 on `woocommerce_payment_complete`: the gateway saves its
 * token at 10 and the subscription captures it at 20, so by here the answer is
 * final rather than half-written.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function subscription_diagnose_token( $order_id ) {
	if ( ! subscriptions_test_mode() || ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( (int) $order_id );

	if ( ! $order ) {
		return;
	}

	// Only orders that bought a plan.
	if ( ! subscription_order_product_id( $order ) ) {
		return;
	}

	$gateway_id = (string) $order->get_payment_method();
	$user_id    = (int) $order->get_customer_id();

	// What the product actually reports, which is what every gateway's own
	// subscription detection keys on.
	$types = array();

	foreach ( $order->get_items() as $item ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;

		if ( $product && method_exists( $product, 'get_type' ) ) {
			$types[] = $product->get_type();
		}
	}

	$order_tokens = method_exists( $order, 'get_payment_tokens' ) ? $order->get_payment_tokens() : array();

	$customer_tokens = array();

	if ( $user_id && class_exists( '\WC_Payment_Tokens' ) ) {
		$customer_tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
	}

	$lines = array(
		'gateway: ' . ( '' === $gateway_id ? '(none)' : $gateway_id ),
		'customer: ' . ( $user_id ? '#' . $user_id : '(guest)' ),
		'product types: ' . ( empty( $types ) ? '(none)' : implode( ', ', $types ) ),
		'transaction id: ' . ( $order->get_transaction_id() ? 'set' : 'MISSING' ),
		'tokens on this order: ' . count( $order_tokens ),
		'cards saved for this customer on this gateway: ' . count( $customer_tokens ),
	);

	// The gateway's own record of what it was asked to do and what it did. Read
	// by name because there is no standard for either; absent for a gateway that
	// does not use them, which is itself worth seeing.
	foreach ( array( '_moyasar_save_card', '_moyasar_token_saved' ) as $key ) {
		$value = $order->get_meta( $key );

		$lines[] = $key . ': ' . ( '' === $value ? '(not set)' : $value );
	}

	$order->add_order_note( "Subscription card diagnostics\n" . implode( "\n", $lines ) );
}
add_action( 'woocommerce_payment_complete', __NAMESPACE__ . '\\subscription_diagnose_token', 30, 1 );
