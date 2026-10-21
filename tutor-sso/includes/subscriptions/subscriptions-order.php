<?php
/**
 * What a subscription order records about the payment it took.
 *
 * The plan and the period are stored on the order itself, not read back from
 * the subscription row. The row moves with every renewal — its dates are
 * always the *current* period — so building the payload from it would make a
 * re-send of an old order describe today's period instead of the one that
 * payment actually bought. The order is the immutable record of its own
 * payment, the same reasoning WooCommerce applies when it snapshots a coupon
 * onto the order line at checkout.
 *
 * Written when the order is created (first payment) or renewed; read here by
 * the orders API.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order meta: the subscription this payment belongs to.
 *
 * Stable across renewals — the LMS keys the subscription on it, so every
 * renewal order carries the same value as the first.
 */
const SUBSCRIPTION_ORDER_ID_META = '_tutor_sso_subscription_id';

/**
 * Order meta: 'monthly' or 'yearly', as sold.
 */
const SUBSCRIPTION_ORDER_PLAN_META = '_tutor_sso_subscription_plan';

/**
 * Order meta: start of the period this payment bought, as a Unix timestamp.
 */
const SUBSCRIPTION_ORDER_STARTS_META = '_tutor_sso_subscription_starts';

/**
 * Order meta: end of the period this payment bought, as a Unix timestamp.
 */
const SUBSCRIPTION_ORDER_ENDS_META = '_tutor_sso_subscription_ends';

/**
 * Format a timestamp as ISO 8601 with a UTC offset.
 *
 * The LMS refuses a datetime without an offset with a 400, so "2026-11-10T10:00:00"
 * is not acceptable and neither is anything gmdate() produces without one.
 * Rendered in the site's timezone, which is the one the billing dates were
 * worked out in.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function subscription_order_iso8601( $timestamp ) {
	$timestamp = (int) $timestamp;

	if ( $timestamp <= 0 ) {
		return '';
	}

	$date = new \DateTimeImmutable( '@' . $timestamp );

	return $date->setTimezone( function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) )
		->format( 'c' );
}

/**
 * Record what a payment bought, so the payload can be built from it later.
 *
 * @param int|\WC_Order $order    Order or its ID.
 * @param array         $details {
 *     @type string $subscription_id Stable subscription identifier.
 *     @type string $plan            'monthly' or 'yearly'.
 *     @type int    $starts          Period start, Unix timestamp.
 *     @type int    $ends            Period end, Unix timestamp.
 * }
 * @return bool Whether anything was written.
 */
function subscription_order_record( $order, $details ) {
	$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order ) : null );

	if ( ! $order || ! is_array( $details ) ) {
		return false;
	}

	$id    = isset( $details['subscription_id'] ) ? trim( (string) $details['subscription_id'] ) : '';
	$plan  = isset( $details['plan'] ) ? (string) $details['plan'] : '';
	$start = isset( $details['starts'] ) ? (int) $details['starts'] : 0;
	$end   = isset( $details['ends'] ) ? (int) $details['ends'] : 0;

	if ( '' === $id || ! array_key_exists( $plan, subscription_periods() ) || $start <= 0 || $end <= $start ) {
		return false;
	}

	$order->update_meta_data( SUBSCRIPTION_ORDER_ID_META, $id );
	$order->update_meta_data( SUBSCRIPTION_ORDER_PLAN_META, $plan );
	$order->update_meta_data( SUBSCRIPTION_ORDER_STARTS_META, $start );
	$order->update_meta_data( SUBSCRIPTION_ORDER_ENDS_META, $end );
	$order->save();

	return true;
}

/**
 * The `subscription` block for an order's item, ready for the API.
 *
 * Every field the LMS marks required is checked here rather than left to fail
 * as a 400 after the request has gone out: a missing plan or a period that
 * ends before it starts means the order was recorded wrongly, and sending it
 * would only turn a local bug into a remote one.
 *
 * @param \WC_Order $order Order.
 * @return array Empty when this order bought no subscription.
 */
function subscription_order_payload( $order ) {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
		return array();
	}

	$id    = trim( (string) $order->get_meta( SUBSCRIPTION_ORDER_ID_META ) );
	$plan  = (string) $order->get_meta( SUBSCRIPTION_ORDER_PLAN_META );
	$start = (int) $order->get_meta( SUBSCRIPTION_ORDER_STARTS_META );
	$end   = (int) $order->get_meta( SUBSCRIPTION_ORDER_ENDS_META );

	if ( '' === $id || ! array_key_exists( $plan, subscription_periods() ) ) {
		return array();
	}

	// "ends_at must be after starts_at" — refused by the LMS, so caught here.
	if ( $start <= 0 || $end <= $start ) {
		return array();
	}

	return array(
		'wordpress_subscription_id' => $id,
		'plan'                      => $plan,
		'starts_at'                 => subscription_order_iso8601( $start ),
		'ends_at'                   => subscription_order_iso8601( $end ),
	);
}

/**
 * Query var carrying meta conditions through to the legacy order store.
 */
const SUBSCRIPTION_ORDERS_META_VAR = 'tutor_sso_meta_query';

/**
 * Whether orders live in WooCommerce's own tables (HPOS) rather than in posts.
 *
 * @return bool
 */
function subscription_orders_use_hpos() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * wc_get_orders() filtered by order meta, on either storage engine.
 *
 * `meta_query` cannot simply be passed to wc_get_orders(). The HPOS store
 * honours it; the legacy post store skips it without a word
 * (WC_Data_Store_WP::get_wp_query_args() `continue`s past the key), so the
 * query quietly matches every order instead. Anything deciding "does an order
 * like this exist" then always says yes.
 *
 * On HPOS it is passed as meta_query. On the legacy store it rides in a custom
 * query var, which subscription_orders_cpt_meta_query() hands to WP_Query —
 * the extension point WooCommerce documents for exactly this.
 *
 * @param array $args       wc_get_orders() arguments, without meta_query.
 * @param array $meta_query Meta conditions, in WP_Meta_Query form.
 * @return mixed What wc_get_orders() returns for these arguments.
 */
function subscription_get_orders( array $args, array $meta_query ) {
	if ( subscription_orders_use_hpos() ) {
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	} else {
		$args[ SUBSCRIPTION_ORDERS_META_VAR ] = $meta_query;
	}

	return wc_get_orders( $args );
}

/**
 * Apply those meta conditions on the legacy order store.
 *
 * @param array $wp_query_args WP_Query arguments WooCommerce built.
 * @param array $query_vars    The wc_get_orders() arguments.
 * @return array
 */
function subscription_orders_cpt_meta_query( $wp_query_args, $query_vars ) {
	if ( empty( $query_vars[ SUBSCRIPTION_ORDERS_META_VAR ] ) || ! is_array( $query_vars[ SUBSCRIPTION_ORDERS_META_VAR ] ) ) {
		return $wp_query_args;
	}

	// Our var is not a WP_Query argument; WooCommerce passed it through as-is.
	unset( $wp_query_args[ SUBSCRIPTION_ORDERS_META_VAR ] );

	if ( ! isset( $wp_query_args['meta_query'] ) || ! is_array( $wp_query_args['meta_query'] ) ) {
		$wp_query_args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	// Nested, so its own AND/OR relation stands and cannot loosen the
	// conditions WooCommerce built for customer, status and the rest.
	$wp_query_args['meta_query'][] = $query_vars[ SUBSCRIPTION_ORDERS_META_VAR ];

	return $wp_query_args;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', __NAMESPACE__ . '\\subscription_orders_cpt_meta_query', 10, 2 );
