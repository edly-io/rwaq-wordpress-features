<?php
/**
 * Post every paid WooCommerce order to the LMS orders API.
 *
 *   POST {lms}/rwaq/api/orders/
 *
 * One record per successful order, covering both course and program items.
 * Saving an order enrolls nobody — enrollment runs separately (Open edX Commerce
 * for courses, programs-order-enrollment.php for programs).
 *
 * The endpoint is idempotent: 201 when saved, 200 when this wordpress_order_id
 * was already stored, so a retry is safe. Authenticated with the same service
 * credentials Open edX Commerce stores (see sso_lms_service_token()).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order meta recording the result, so a paid order is posted once.
 */
const ORDERS_API_SENT_META = '_tutor_sso_order_synced';

/**
 * Whether orders are posted to the LMS at all.
 *
 * @return bool
 */
function orders_api_enabled() {
	/**
	 * Filter whether paid orders are posted to the LMS orders API.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'tutor_sso_orders_api_enabled', true );
}

/**
 * Base URL for the orders API.
 *
 * @return string Without a trailing slash, or ''.
 */
function orders_api_base() {
	$base = rtrim( (string) get_option( 'openedx-domain' ), '/' );

	if ( '' === $base ) {
		$base = enroll_lms_base_url();
	}

	/**
	 * Filter the host the orders API is called on.
	 *
	 * @param string $base Base URL, no trailing slash.
	 */
	return (string) apply_filters( 'tutor_sso_orders_api_base', $base );
}

/**
 * Money as the API wants it: a string with two decimals, never negative.
 *
 * @param float|string $amount Amount.
 * @return string
 */
function orders_api_money( $amount ) {
	$amount = (float) $amount;

	return number_format( max( 0, $amount ), 2, '.', '' );
}

/**
 * What an order item is selling: a course, a program, or neither.
 *
 * Courses are identified by Open edX Commerce's own product flags, programs by
 * ours, so each plugin keeps owning its side.
 *
 * @param int $product_id Product ID.
 * @return array{course_id?:string,program_key?:string} Empty when it is neither.
 */
function orders_api_item_identifier( $product_id ) {
	$product_id = (int) $product_id;

	$course_id = trim( (string) get_post_meta( $product_id, 'is_openedx_course', true ) ) === 'yes'
		? trim( (string) get_post_meta( $product_id, '_course_id', true ) )
		: '';

	if ( '' !== $course_id ) {
		return array( 'course_id' => $course_id );
	}

	if ( function_exists( __NAMESPACE__ . '\\program_product_is_program' ) && program_product_is_program( $product_id ) ) {
		$program_key = trim( (string) get_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META, true ) );

		if ( '' !== $program_key ) {
			return array( 'program_key' => $program_key );
		}
	}

	return array();
}

/**
 * Map an order's coupons onto the API's rows.
 *
 * WooCommerce's discount types map as: fixed_product -> a product coupon naming
 * its item, fixed_cart -> a cart amount, percent -> a cart percentage. A product
 * coupon that touched several items is sent as a cart amount instead, because
 * WooCommerce does not report how it split across them and the API wants one row
 * per item.
 *
 * @param \WC_Order $order Order.
 * @param array     $items Items already mapped, to resolve product coupons against.
 * @return array[]
 */
function orders_api_coupons( $order, $items ) {
	$rows = array();

	// Coupon lines are order items of type 'coupon'; there is no get_coupon_lines().
	foreach ( $order->get_items( 'coupon' ) as $line ) {
		$code     = trim( (string) $line->get_code() );
		$discount = orders_api_money( $line->get_discount() );

		if ( '' === $code ) {
			continue;
		}

		// WooCommerce stores a snapshot of the coupon on the order line at
		// checkout (WC_Checkout::create_order_coupon_lines() -> meta
		// `coupon_info`, a JSON array of [ id, code, type-or-null, amount ]
		// where null means fixed_cart). Reading that reports what the customer
		// actually got; looking the coupon up live reports what it says today,
		// so a coupon later edited — or deleted, which yields amount 0 next to
		// a real non-zero discount — would rewrite the history of past orders.
		$info = json_decode( (string) $line->get_meta( 'coupon_info' ), true );

		if ( is_array( $info ) && array_key_exists( 3, $info ) ) {
			$type  = isset( $info[2] ) && null !== $info[2] ? (string) $info[2] : 'fixed_cart';
			$value = (string) $info[3];
		} else {
			// No snapshot: an order made outside checkout (admin, importer) or
			// before WooCommerce recorded this. Fall back to the live coupon.
			// The guard names the class actually being constructed — it used to
			// test wc_get_coupon_id_by_code(), a function this never calls.
			$coupon = class_exists( '\WC_Coupon' ) ? new \WC_Coupon( $code ) : null;
			$type   = $coupon ? $coupon->get_discount_type() : 'fixed_cart';
			$value  = $coupon ? (string) $coupon->get_amount() : $discount;
		}

		$row = array(
			'code'            => $code,
			'scope'           => 'cart',
			'discount_type'   => ( 'percent' === $type ) ? 'percentage' : 'amount',
			'value'           => ( 'percent' === $type ) ? $value : orders_api_money( $value ),
			'discount_amount' => $discount,
		);

		// A per-product coupon can name its item only when exactly one item is
		// in the order to name.
		if ( 'fixed_product' === $type && 1 === count( $items ) ) {
			$row['scope']         = 'product';
			$row['discount_type'] = 'amount';

			foreach ( array( 'course_id', 'program_key' ) as $field ) {
				if ( isset( $items[0][ $field ] ) ) {
					$row[ $field ] = $items[0][ $field ];
				}
			}
		}

		$rows[] = $row;
	}

	return $rows;
}

/**
 * Build the payload for an order, or explain why it has nothing to send.
 *
 * @param \WC_Order $order Order.
 * @return array|\WP_Error
 */
function orders_api_payload( $order ) {
	$items = array();

	foreach ( $order->get_items() as $item ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		if ( ! $product_id ) {
			continue;
		}

		$identifier = orders_api_item_identifier( $product_id );

		if ( empty( $identifier ) ) {
			continue;
		}

		// Subtotal is before discounts, total after — their difference is this
		// line's share of every coupon, product and cart alike.
		$subtotal = (float) $order->get_line_subtotal( $item, false, false );
		$total    = (float) $order->get_line_total( $item, false, false );

		$items[] = array_merge(
			$identifier,
			array(
				'actual_price'    => orders_api_money( $subtotal ),
				'discount_amount' => orders_api_money( $subtotal - $total ),
				'price_paid'      => orders_api_money( $total ),
			)
		);
	}

	if ( empty( $items ) ) {
		return new \WP_Error( 'tutor_sso_no_items', __( 'This order sells no course or program.', 'tutor-sso' ) );
	}

	$email = trim( (string) $order->get_billing_email() );

	if ( '' === $email ) {
		return new \WP_Error( 'tutor_sso_no_email', __( 'This order has no billing email.', 'tutor-sso' ) );
	}

	// The order totals are the items' own sums, so they agree with the per-item
	// figures the API checks them against.
	$actual = 0.0;
	$paid   = 0.0;

	foreach ( $items as $row ) {
		$actual += (float) $row['actual_price'];
		$paid   += (float) $row['price_paid'];
	}

	$created = $order->get_date_created();

	$payload = array(
		'wordpress_order_id' => (string) $order->get_id(),
		'order_by'           => $email,
		'order_date'         => $created ? $created->date( 'c' ) : gmdate( 'c' ),
		'status'             => 'completed',
		'currency'           => (string) $order->get_currency(),
		'actual_price'       => orders_api_money( $actual ),
		'price_paid'         => orders_api_money( $paid ),
		'items'              => $items,
		'coupons'            => orders_api_coupons( $order, $items ),
	);

	$paid_via = trim( (string) $order->get_payment_method() );

	if ( '' !== $paid_via ) {
		$payload['paid_via'] = $paid_via;
	}

	/**
	 * Filter the order payload before it is posted.
	 *
	 * @param array     $payload Payload.
	 * @param \WC_Order $order   Order.
	 */
	return (array) apply_filters( 'tutor_sso_order_payload', $payload, $order );
}

/**
 * Post one order to the LMS.
 *
 * @param int $order_id WooCommerce order ID.
 * @return true|\WP_Error
 */
function orders_api_send( $order_id ) {
	if ( ! orders_api_enabled() || ! function_exists( 'wc_get_order' ) ) {
		return new \WP_Error( 'tutor_sso_orders_disabled', __( 'Order syncing is disabled.', 'tutor-sso' ) );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return new \WP_Error( 'tutor_sso_no_order', __( 'Order not found.', 'tutor-sso' ) );
	}

	$payload = orders_api_payload( $order );

	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$base = orders_api_base();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$token = sso_lms_service_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$response = wp_remote_post(
		$base . '/rwaq/api/orders/',
		array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'JWT ' . $token,
			),
			'body'      => wp_json_encode( $payload ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) );

	// 201 saved, 200 already stored — both mean the LMS has this order.
	if ( 200 === $status || 201 === $status ) {
		return true;
	}

	// A stale cached token is the one failure worth clearing automatically.
	if ( 401 === $status ) {
		delete_transient( 'tutor_sso_service_token' );
	}

	return new \WP_Error(
		'tutor_sso_order_failed',
		sprintf(
			/* translators: 1: HTTP status code, 2: response body. */
			__( 'The LMS rejected the order (HTTP %1$d): %2$s', 'tutor-sso' ),
			$status,
			$body
		)
	);
}

/**
 * Post the order once it is paid, and record the outcome on the order.
 *
 * Runs on both paid statuses; the stored flag and the API's own idempotency mean
 * the second one is a no-op.
 *
 * @param int $order_id WooCommerce order ID.
 */
function orders_api_handle_order( $order_id ) {
	$order_id = (int) $order_id;

	if ( 'sent' === get_post_meta( $order_id, ORDERS_API_SENT_META, true ) ) {
		return;
	}

	// This runs while the buyer is completing checkout. Whatever goes wrong here
	// must not take the page down with it — the order is already paid.
	try {
		$result = orders_api_send( $order_id );
	} catch ( \Throwable $e ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[tutor-sso] order %d sync crashed: %s', $order_id, $e->getMessage() )
		);



		update_post_meta( $order_id, ORDERS_API_SENT_META, 'error' );

		return;
	}


	// Nothing to send is not a failure worth recording or retrying.
	if ( is_wp_error( $result ) && 'tutor_sso_no_items' === $result->get_error_code() ) {
		return;
	}

	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( is_wp_error( $result ) ) {
		update_post_meta( $order_id, ORDERS_API_SENT_META, 'error' );

		if ( $order ) {
			$order->add_order_note( sprintf( 'LMS order sync failed: %s', $result->get_error_message() ) );
		}

		return;
	}

	update_post_meta( $order_id, ORDERS_API_SENT_META, 'sent' );

	if ( $order ) {
		$order->add_order_note( __( 'Order sent to the LMS orders API.', 'tutor-sso' ) );
	}
}
add_action( 'woocommerce_order_status_processing', __NAMESPACE__ . '\\orders_api_handle_order', 20, 1 );
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ . '\\orders_api_handle_order', 20, 1 );
