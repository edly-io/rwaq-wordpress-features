<?php
/**
 * Turn a paid subscription order into a subscription.
 *
 * Split across two hooks, because of the order WooCommerce runs them in:
 *
 *     set_status( 'processing' ) → save()
 *         → woocommerce_order_status_processing   → the orders API, priority 20
 *     → woocommerce_payment_complete              → the gateway saves its token
 *
 * The orders API therefore builds its payload *before* any token exists. So the
 * subscription and the order's own record of what it bought are written on the
 * status transition at priority 10 — ahead of the API — and the saved card is
 * attached afterwards, once the gateway has had its turn.
 *
 * Nothing here is gated on the feature switch: an order that has already been
 * paid for must be recorded whatever the setting says afterwards.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint the identifier the LMS keys a subscription on.
 *
 * Deliberately not derived from the row id. The LMS holds this value for the
 * life of the subscription and matches every renewal on it, so anything that
 * renumbers the table — a migration, a re-import, a restore — would silently
 * decouple our rows from theirs: renewals would stop extending and start
 * creating duplicates. A random component also means two installs pointed at
 * one LMS cannot mint the same identifier.
 *
 * 43 characters, inside the API's 64-character limit.
 *
 * @return string
 */
function subscription_mint_external_id() {
	$unique = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );

	return 'wp-sub-' . $unique;
}

/**
 * The subscription line in an order, if it has one.
 *
 * @param \WC_Order $order Order.
 * @return int Product ID, or 0.
 */
function subscription_order_product_id( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
		return 0;
	}

	foreach ( $order->get_items() as $item ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		if ( $product_id && subscription_product_is_subscription( $product_id ) ) {
			return $product_id;
		}
	}

	return 0;
}

/**
 * Record a paid subscription order.
 *
 * Idempotent on the order's own record: both status hooks fire for an order
 * that goes processing then completed, and WooCommerce replays them on a
 * status change later, so the guard is what stops a second subscription being
 * created for one payment.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function subscription_capture_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( (int) $order_id );

	if ( ! $order ) {
		return;
	}

	// Already recorded — either captured before, or a renewal whose period the
	// renewal engine wrote before taking the payment.
	if ( '' !== trim( (string) $order->get_meta( SUBSCRIPTION_ORDER_ID_META ) ) ) {
		return;
	}

	$product_id = subscription_order_product_id( $order );

	if ( ! $product_id ) {
		return;
	}

	$user_id = (int) $order->get_customer_id();

	if ( ! $user_id ) {
		// Nothing to renew against: a subscription belongs to an account, and
		// checkout requires one.
		return;
	}

	global $wpdb;

	$plan = subscription_period( $product_id );

	// The period starts when the money was taken, not when the row is written.
	$paid  = $order->get_date_paid();
	$start = $paid ? (int) $paid->getTimestamp() : (int) current_time( 'timestamp' );
	$end   = subscription_next_payment( $plan, $start );

	$external_id = subscription_mint_external_id();

	$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array(
			'external_id'     => $external_id,
			'user_id'         => $user_id,
			'product_id'      => $product_id,
			'parent_order_id' => (int) $order->get_id(),
			'status'          => 'active',
			'period'          => $plan,
			'amount'          => (float) $order->get_total(),
			'currency'        => (string) $order->get_currency(),
			'gateway_id'      => (string) $order->get_payment_method(),
			'token_id'        => 0,
			'next_payment_at' => gmdate( 'Y-m-d H:i:s', $end ),
			'paid_until'      => gmdate( 'Y-m-d H:i:s', $end ),
			'failure_count'   => 0,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%s', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
	);

	if ( ! $inserted ) {
		return;
	}

	subscription_order_record(
		$order,
		array(
			'subscription_id' => $external_id,
			'plan'            => $plan,
			'starts'          => $start,
			'ends'            => $end,
		)
	);

	/**
	 * Fires once a purchase has become a subscription.
	 *
	 * @param int       $subscription_id Row id.
	 * @param \WC_Order $order           The order that paid for it.
	 */
	do_action( 'tutor_sso_subscription_created', (int) $wpdb->insert_id, $order );
}
add_action( 'woocommerce_order_status_processing', __NAMESPACE__ . '\\subscription_capture_order', 10, 1 );
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ . '\\subscription_capture_order', 10, 1 );

/**
 * Find a card this customer can be charged with on this gateway.
 *
 * The order is the first place to look, but it cannot be the only one. A
 * gateway attaches the token to the order on its own schedule — Moyasar does it
 * from a `woocommerce_payment_complete` handler that first calls its API, and
 * any of fetching the payment, the payment not yet reading as paid, or a
 * redirect flow completing out of band leaves the order without one even though
 * the card was saved against the customer perfectly well.
 *
 * So fall back the same way the gateways themselves do at renewal time: the
 * customer's default card, then any card they have on this gateway. Keyed on
 * the gateway throughout, because a token belonging to another gateway cannot
 * be charged by this one.
 *
 * @param int       $user_id    Customer.
 * @param string    $gateway_id Gateway the subscription is paid by.
 * @param \WC_Order $order      Order to check first, if there is one.
 * @return int Token id, or 0.
 */
function subscription_find_token( $user_id, $gateway_id, $order = null ) {
	$user_id    = (int) $user_id;
	$gateway_id = trim( (string) $gateway_id );

	if ( $order && method_exists( $order, 'get_payment_tokens' ) ) {
		$tokens = $order->get_payment_tokens();

		if ( ! empty( $tokens ) ) {
			return (int) reset( $tokens );
		}
	}

	// Only the lookups below need the token store; a token already on the order
	// is just an id, and is returned above without it.
	if ( ! $user_id || '' === $gateway_id || ! class_exists( '\WC_Payment_Tokens' ) ) {
		return 0;
	}

	$default = \WC_Payment_Tokens::get_customer_default_token( $user_id );

	// Only if it belongs to this gateway — a customer may well have a default
	// saved against a different one.
	if ( $default && method_exists( $default, 'get_gateway_id' ) && $default->get_gateway_id() === $gateway_id ) {
		return (int) $default->get_id();
	}

	$tokens = \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );

	if ( ! empty( $tokens ) ) {
		$token = reset( $tokens );

		return method_exists( $token, 'get_id' ) ? (int) $token->get_id() : 0;
	}

	return 0;
}

/**
 * Attach the saved card to the subscription.
 *
 * Runs after the gateway's own `woocommerce_payment_complete` handler, which is
 * where the token is created — it does not exist yet when the order above is
 * captured.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function subscription_capture_token( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( (int) $order_id );

	if ( ! $order || ! method_exists( $order, 'get_payment_tokens' ) ) {
		return;
	}

	// Only an order that bought a subscription. This fires on every paid order,
	// so without the guard every course and program sale would run an UPDATE
	// that matches nothing. The meta is written by the capture above, which
	// runs earlier on the status transition.
	if ( '' === trim( (string) $order->get_meta( SUBSCRIPTION_ORDER_ID_META ) ) ) {
		return;
	}

	$token_id = subscription_find_token(
		(int) $order->get_customer_id(),
		(string) $order->get_payment_method(),
		$order
	);

	if ( ! $token_id ) {
		return;
	}

	global $wpdb;

	$table = subscriptions_table();

	// Only a subscription still waiting for a card, so a later order cannot
	// overwrite a token that renewals are already using.
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table,
		array( 'token_id' => $token_id ),
		array(
			'parent_order_id' => (int) $order->get_id(),
			'token_id'        => 0,
		),
		array( '%d' ),
		array( '%d', '%d' )
	);
}
add_action( 'woocommerce_payment_complete', __NAMESPACE__ . '\\subscription_capture_token', 20, 1 );
