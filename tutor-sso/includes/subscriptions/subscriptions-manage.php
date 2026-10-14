<?php
/**
 * Cancelling and resuming a subscription.
 *
 * One implementation for every place that does it — the admin list and the
 * customer's account page — so the rules cannot drift apart.
 *
 * The two are deliberately asymmetric about the LMS:
 *
 *   - Cancel changes WordPress first and tells the LMS afterwards. Stopping the
 *     charges is what matters, and it must happen even when the LMS is down; a
 *     failed call is recorded on the row and can be retried.
 *   - Resume asks the LMS first and changes nothing if it refuses. Resuming
 *     restarts charging, and charging for a subscription the LMS will not honour
 *     — one it has revoked after a refund, say — would bill for nothing.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cancel a subscription: stop charging, keep access to the end of the period.
 *
 * @param int    $subscription_id Row id.
 * @param string $reason          Sent to the LMS.
 * @return array{cancelled:bool,lms:true|\WP_Error}
 */
function subscription_cancel( $subscription_id, $reason ) {
	global $wpdb;

	$subscription_id = (int) $subscription_id;

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array(
			'status'          => 'cancelled',
			'next_payment_at' => null,
			'cancelled_at'    => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => $subscription_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	/**
	 * Fires when a subscription is cancelled.
	 *
	 * @param int $subscription_id Row id.
	 */
	do_action( 'tutor_sso_subscription_cancelled', $subscription_id );

	return array(
		'cancelled' => true,
		'lms'       => subscription_lms_sync( $subscription_id, 'cancel', $reason ),
	);
}

/**
 * Whether a cancelled subscription can still be resumed.
 *
 * Only inside the period already paid for. After that there is nothing left
 * to resume — the customer buys a new subscription.
 *
 * @param array $row Subscription row.
 * @return bool
 */
function subscription_can_resume( $row ) {
	return is_array( $row )
		&& 'cancelled' === ( $row['status'] ?? '' )
		&& subscription_paid_until( $row ) > time();
}

/**
 * Resume a cancelled subscription.
 *
 * Billing picks up where it would have: the next charge is at the end of the
 * period already paid for, so resuming early or late costs the same.
 *
 * @param int    $subscription_id Row id.
 * @param string $reason          Sent to the LMS.
 * @return true|\WP_Error
 */
function subscription_reactivate( $subscription_id, $reason ) {
	$subscription_id = (int) $subscription_id;
	$row             = subscription_get( $subscription_id );

	if ( ! subscription_can_resume( $row ) ) {
		return new \WP_Error( 'tutor_sso_subscription_not_resumable', __( 'لم يعد بالإمكان استئناف هذا الاشتراك.', 'tutor-sso' ) );
	}

	$lms = subscription_lms_patch( (string) $row['external_id'], 'reactivate', $reason );

	// A site that does not sync orders to the LMS has nothing there to ask.
	if ( is_wp_error( $lms ) && 'tutor_sso_orders_disabled' !== $lms->get_error_code() ) {
		return $lms;
	}

	global $wpdb;

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array(
			'status'          => 'active',
			'next_payment_at' => gmdate( 'Y-m-d H:i:s', subscription_paid_until( $row ) ),
			'cancelled_at'    => null,
			'lms_status'      => is_wp_error( $lms ) ? null : 'active',
			'lms_sync_error'  => null,
		),
		array( 'id' => $subscription_id ),
		array( '%s', '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	/**
	 * Fires when a cancelled subscription is resumed.
	 *
	 * @param int $subscription_id Row id.
	 */
	do_action( 'tutor_sso_subscription_reactivated', $subscription_id );

	return true;
}

/**
 * The subscription a customer's account page should show.
 *
 * Active first. Otherwise a cancelled one still inside its paid period. A
 * subscription whose period is over is not "current" — see
 * subscription_user_has_history().
 *
 * @param int $user_id Customer.
 * @return array|null Row, or null.
 */
function subscription_user_current( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return null;
	}

	$table = subscriptions_table();

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND status IN ( 'active', 'cancelled' ) ORDER BY ( status = 'active' ) DESC, id DESC LIMIT 5",
			$user_id
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		if ( 'active' === $row['status'] || subscription_paid_until( $row ) > time() ) {
			return $row;
		}
	}

	return null;
}

/**
 * The customer's most recent subscription of any status, if they ever had one.
 *
 * @param int $user_id Customer.
 * @return array|null
 */
function subscription_user_latest( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return null;
	}

	$table = subscriptions_table();

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}
