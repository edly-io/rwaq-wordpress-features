<?php
/**
 * Renewing a subscription.
 *
 * Scheduled in two levels. An hourly scan finds what is due and enqueues one
 * action per subscription, rather than renewing them all inside a single job —
 * one card that times out then cannot hold up everybody else's renewal, and
 * Action Scheduler retries each one on its own.
 *
 * A renewal is an ordinary WooCommerce order: it is created unpaid, carries the
 * saved card, and is handed to the gateway through the same
 * `woocommerce_scheduled_subscription_payment_{gateway}` action that
 * WooCommerce Subscriptions defines. Gateways that support tokenised renewals
 * already implement it, which is what keeps this from being Moyasar-specific.
 *
 * Because the order is ordinary, the orders API picks it up by itself when it
 * reaches processing — no separate call, and the LMS sees a renewal exactly as
 * it sees a first payment.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook that scans for due subscriptions.
 */
const SUBSCRIPTION_SCAN_HOOK = 'tutor_sso_subscription_scan';

/**
 * Hook that renews one subscription.
 */
const SUBSCRIPTION_RENEW_HOOK = 'tutor_sso_subscription_renew';

/**
 * Action Scheduler group, so these are identifiable in the admin.
 */
const SUBSCRIPTION_AS_GROUP = 'tutor-sso-subscriptions';

/**
 * Order meta: the renewal has already moved the subscription on.
 */
const SUBSCRIPTION_RENEWAL_APPLIED_META = '_tutor_sso_renewal_applied';

/**
 * How often to look for subscriptions that are due.
 *
 * Hourly rather than daily: a subscription falling due at 09:00 should not wait
 * until the next nightly run, and the scan costs one indexed query when nothing
 * is due.
 *
 * @return int Seconds.
 */
function subscription_scan_interval() {
	return HOUR_IN_SECONDS;
}

/**
 * Option holding the interval the scan is currently scheduled at.
 *
 * A recurring action's interval is fixed when it is created, so changing it
 * has to replace the schedule rather than adjust it — and that needs to know
 * what it was scheduled at before. A site that ran the old every-minute
 * testing schedule is moved back to hourly this way.
 */
const SUBSCRIPTION_SCAN_INTERVAL_OPTION = 'tutor_sso_subscription_scan_interval';

/**
 * Keep the scan scheduled, at the right interval.
 *
 * @return void
 */
function subscription_schedule_scan() {
	if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	$interval = subscription_scan_interval();
	$stored   = (int) get_option( SUBSCRIPTION_SCAN_INTERVAL_OPTION, 0 );
	$exists   = as_has_scheduled_action( SUBSCRIPTION_SCAN_HOOK, array(), SUBSCRIPTION_AS_GROUP );

	if ( $exists && $stored === $interval ) {
		return;
	}

	if ( $exists && function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( SUBSCRIPTION_SCAN_HOOK, array(), SUBSCRIPTION_AS_GROUP );
	}

	as_schedule_recurring_action( time() + $interval, $interval, SUBSCRIPTION_SCAN_HOOK, array(), SUBSCRIPTION_AS_GROUP );
	update_option( SUBSCRIPTION_SCAN_INTERVAL_OPTION, $interval, false );
}
add_action( 'init', __NAMESPACE__ . '\\subscription_schedule_scan' );

/**
 * How long to wait after each failed attempt, in days.
 *
 * Running out of entries ends the subscription.
 *
 * @return int[]
 */
function subscription_retry_schedule() {
	/**
	 * Filter the retry schedule.
	 *
	 * @param int[] $days Days to wait after the 1st, 2nd, 3rd … failure.
	 */
	return (array) apply_filters( 'tutor_sso_subscription_retry_days', array( 1, 3, 5 ) );
}

/**
 * Subscriptions whose next payment is due.
 *
 * Batched: a backlog after downtime should not try to take on every
 * subscription in one request. The next run takes the rest.
 *
 * @param int $limit Maximum to return. 0 uses the filtered default.
 * @return int[]
 */
function subscription_due_ids( $limit = 0 ) {
	global $wpdb;

	$limit = $limit > 0 ? (int) $limit : (int) apply_filters( 'tutor_sso_subscription_scan_batch', 50 );
	$table = subscriptions_table();

	$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'active' AND next_payment_at IS NOT NULL AND next_payment_at <= %s ORDER BY next_payment_at ASC LIMIT %d",
			gmdate( 'Y-m-d H:i:s' ),
			$limit
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Enqueue a renewal for everything that is due.
 *
 * @return void
 */
function subscription_scan_due() {
	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}

	foreach ( subscription_due_ids() as $id ) {

		// Already queued by an earlier scan that has not run yet.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( SUBSCRIPTION_RENEW_HOOK, array( $id ), SUBSCRIPTION_AS_GROUP ) ) {
			continue;
		}

		as_schedule_single_action( time(), SUBSCRIPTION_RENEW_HOOK, array( $id ), SUBSCRIPTION_AS_GROUP );
	}
}
add_action( SUBSCRIPTION_SCAN_HOOK, __NAMESPACE__ . '\\subscription_scan_due' );

/**
 * One subscription's row.
 *
 * @param int $subscription_id Row id.
 * @return array|null
 */
function subscription_get( $subscription_id ) {
	global $wpdb;

	$table = subscriptions_table();

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $subscription_id ),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * The renewal order already created for this subscription and period, if any.
 *
 * The guard against charging twice: a scan that overlaps its predecessor, or an
 * action retried after a timeout, must not produce a second order for a period
 * already billed.
 *
 * @param string $external_id Subscription identifier.
 * @param int    $period_start Period start, Unix timestamp.
 * @return int Order ID, or 0.
 */
function subscription_renewal_find( $external_id, $period_start ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return false;
	}

	// Through subscription_get_orders(), not a bare meta_query: on the legacy
	// order store that would be dropped, every order would match, and no
	// renewal would ever be attempted.
	$found = subscription_get_orders(
		array(
			'limit'  => 1,
			'return' => 'ids',
			// A failed attempt does not count: the period's retries are new
			// orders for the same period, and must not find it in their way.
			'status' => array_values( array_diff( array( 'pending', 'on-hold', 'processing', 'completed' ), subscription_renewal_failed_statuses() ) ),
		),
		array(
			'relation' => 'AND',
			array(
				'key'   => SUBSCRIPTION_ORDER_ID_META,
				'value' => $external_id,
			),
			array(
				'key'   => SUBSCRIPTION_ORDER_STARTS_META,
				'value' => (int) $period_start,
			),
		)
	);

	return ! empty( $found ) ? (int) reset( $found ) : 0;
}

/**
 * Whether a renewal order already exists for this subscription and period.
 *
 * @param string $external_id  Subscription identifier.
 * @param int    $period_start Period start, Unix timestamp.
 * @return bool
 */
function subscription_renewal_exists( $external_id, $period_start ) {
	return subscription_renewal_find( $external_id, $period_start ) > 0;
}

/**
 * Order attribution source type for renewal orders.
 *
 * WooCommerce's "Origin" column comes from order attribution, which it records
 * from fields the browser posts at checkout. A renewal is created by the
 * scheduler or an admin button with no browser involved, so it carries none and
 * the column reads "Unknown" — or "Web admin" when renewed from the button,
 * which is wrong in a different way. This names it for what it is.
 */
const SUBSCRIPTION_RENEWAL_SOURCE_TYPE = 'subscription_renewal';

/**
 * The meta key WooCommerce reads the attribution source type from.
 *
 * Built the way WooCommerce builds it, prefix filter included, so a site that
 * changes the prefix is still written to the key it actually reads.
 *
 * @return string
 */
function subscription_attribution_source_key() {
	$prefix = trim( (string) apply_filters( 'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_' ), '_' );

	return '_' . $prefix . '_source_type';
}

/**
 * Label renewal orders in the Origin column.
 *
 * WooCommerce falls back to "Unknown" for a source type it does not know, and
 * then only uses the label if it contains a placeholder for the source. "%.0s"
 * is that placeholder printing nothing, so the label stands on its own. Kept out
 * of the translatable string so a translation cannot drop it.
 *
 * @param string $label       Label WooCommerce chose.
 * @param string $source_type Attribution source type.
 * @return string
 */
function subscription_renewal_origin_label( $label, $source_type = '' ) {
	if ( SUBSCRIPTION_RENEWAL_SOURCE_TYPE !== $source_type ) {
		return $label;
	}

	return str_replace( '%', '%%', __( 'Subscription renewal', 'tutor-sso' ) ) . '%.0s';
}
add_filter( 'wc_order_attribution_origin_label', __NAMESPACE__ . '\\subscription_renewal_origin_label', 10, 2 );

/**
 * The gateway that will take a renewal, with its hooks registered.
 *
 * Asking WooCommerce for its gateways is also what *constructs* them, and a
 * tokenising gateway registers
 * `woocommerce_scheduled_subscription_payment_{id}` in its constructor — Moyasar
 * does it for both the card and Apple Pay gateways.
 *
 * Nothing constructs them in an Action Scheduler job or an admin-post request:
 * neither one goes near checkout, so WC()->payment_gateways() is never reached.
 * Without this the renewal action has no listener at all, do_action() returns
 * quietly, and the renewal order is left sitting on "Pending payment" with
 * nothing to say why. Resolving the gateway here is what makes the hook live.
 *
 * @param string $gateway_id Gateway id stored on the subscription.
 * @return \WC_Payment_Gateway|null
 */
function subscription_gateway( $gateway_id ) {
	$gateway_id = trim( (string) $gateway_id );

	if ( '' === $gateway_id || ! function_exists( 'WC' ) ) {
		return null;
	}

	$gateways = WC()->payment_gateways();

	if ( ! $gateways || ! method_exists( $gateways, 'payment_gateways' ) ) {
		return null;
	}

	$available = $gateways->payment_gateways();

	return isset( $available[ $gateway_id ] ) ? $available[ $gateway_id ] : null;
}

/**
 * The card a renewal charges.
 *
 * In order:
 *
 *   1. the customer's default card, if it belongs to the subscription's
 *      gateway — so "add a card and make it default" on the Payment methods
 *      page is how a customer replaces an expiring one;
 *   2. the card recorded on the subscription, if it still exists;
 *   3. any other card they have on that gateway — a row can reach renewal with
 *      none recorded, when the gateway attached the token to the first order
 *      too late for capture to see it.
 *
 * Only cards on the subscription's own gateway: another gateway's token cannot
 * be charged by this one.
 *
 * @param array $row Subscription row.
 * @return int Token id, or 0 when there is no card to charge.
 */
function subscription_renewal_token( $row ) {
	if ( ! class_exists( '\WC_Payment_Tokens' ) ) {
		return (int) ( $row['token_id'] ?? 0 );
	}

	$user_id = (int) ( $row['user_id'] ?? 0 );
	$gateway = trim( (string) ( $row['gateway_id'] ?? '' ) );

	if ( ! $user_id || '' === $gateway ) {
		return 0;
	}

	$default = \WC_Payment_Tokens::get_customer_default_token( $user_id );

	if ( $default && $default->get_gateway_id() === $gateway ) {
		return (int) $default->get_id();
	}

	$recorded = (int) ( $row['token_id'] ?? 0 );

	if ( $recorded ) {
		$token = \WC_Payment_Tokens::get( $recorded );

		if ( $token && (int) $token->get_user_id() === $user_id && $token->get_gateway_id() === $gateway ) {
			return $recorded;
		}
	}

	return subscription_find_token( $user_id, $gateway );
}

/**
 * The day of the month a subscription bills on.
 *
 * The day its first period started, read from the order that bought it — every
 * period end after that is aimed back at this day (see
 * subscription_next_payment()), so a month shorter than it does not move the
 * billing day for good. Nothing new is stored: the first order already records
 * its period start.
 *
 * @param array $row Subscription row.
 * @return int 1–31, or 0 when the first order cannot be read — the period then
 *             counts from its own start, as it did before this existed.
 */
function subscription_anchor_day( $row ) {
	$order_id = is_array( $row ) && isset( $row['parent_order_id'] ) ? (int) $row['parent_order_id'] : 0;
	$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	$start    = $order ? (int) $order->get_meta( SUBSCRIPTION_ORDER_STARTS_META ) : 0;

	return $start > 0 ? (int) date( 'j', $start ) : 0;
}

/**
 * How long a renewal may hold its lock before it is treated as abandoned.
 *
 * A charge is one request to the gateway, so anything still holding the lock
 * after this has died mid-way (a fatal error, a killed process) and would
 * otherwise block the subscription for good.
 */
const SUBSCRIPTION_RENEW_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * How long a renewal order may stay pending before it counts as failed.
 *
 * Moyasar charges a saved card in the same request and settles by webhook
 * within minutes, so an order still pending after a day never went through —
 * a timeout or a fatal error mid-charge. Left alone it would block the
 * subscription for good, since a pending order counts as the period's renewal.
 */
const SUBSCRIPTION_RENEWAL_STALE_AFTER = DAY_IN_SECONDS;

/**
 * Take the renewal lock for one subscription.
 *
 * The scheduled renewal and the admin "renew now" button can run at the same
 * moment; both would find no order for the period and both would charge. The
 * lock is a row in the options table, inserted atomically — INSERT IGNORE
 * reports whether this request created it — rather than add_option(), whose
 * check-then-insert leaves exactly that window open.
 *
 * @param int $subscription_id Row id.
 * @return bool Whether this request now holds the lock.
 */
function subscription_renew_lock( $subscription_id ) {
	global $wpdb;

	$name = 'tutor_sso_renew_lock_' . (int) $subscription_id;
	$now  = time();

	// A lock left by a renewal that died is released once it is old enough.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d",
			$name,
			$now - SUBSCRIPTION_RENEW_LOCK_TTL
		)
	);

	return 1 === (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
			$name,
			(string) $now
		)
	);
}

/**
 * Release the renewal lock.
 *
 * @param int $subscription_id Row id.
 * @return void
 */
function subscription_renew_unlock( $subscription_id ) {
	global $wpdb;

	$wpdb->delete( $wpdb->options, array( 'option_name' => 'tutor_sso_renew_lock_' . (int) $subscription_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Renew one subscription, unless a renewal of it is already running.
 *
 * @param int $subscription_id Row id.
 * @return void
 */
function subscription_renew( $subscription_id ) {
	$subscription_id = (int) $subscription_id;

	if ( ! subscription_renew_lock( $subscription_id ) ) {
		return;
	}

	try {
		subscription_renew_locked( $subscription_id );
	} finally {
		subscription_renew_unlock( $subscription_id );
	}
}

/**
 * Renew one subscription. Only called with the renewal lock held.
 *
 * @param int $subscription_id Row id.
 * @return void
 */
function subscription_renew_locked( $subscription_id ) {
	$row = subscription_get( $subscription_id );

	if ( ! $row || 'active' !== $row['status'] ) {
		return;
	}

	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	$product = wc_get_product( (int) $row['product_id'] );

	if ( ! $product ) {
		subscription_renewal_failed( $subscription_id, 'the plan product no longer exists' );
		return;
	}

	// Resolved before anything is created, both because it is what registers the
	// renewal hook and so a gateway that cannot charge fails here rather than
	// leaving an unpayable order behind.
	$gateway_id   = (string) $row['gateway_id'];
	$gateway      = subscription_gateway( $gateway_id );
	$renewal_hook = 'woocommerce_scheduled_subscription_payment_' . $gateway_id;

	if ( ! $gateway ) {
		subscription_renewal_failed(
			$subscription_id,
			sprintf( 'the "%s" payment gateway is not available', $gateway_id )
		);
		return;
	}

	// The gateway is here but does not do tokenised renewals. Worth separating
	// from the case above: it is a gateway that cannot ever renew, not one that
	// is temporarily missing.
	if ( ! has_action( $renewal_hook ) ) {
		subscription_renewal_failed(
			$subscription_id,
			sprintf( 'the "%s" gateway does not support automatic renewals', $gateway_id )
		);
		return;
	}

	// The new period starts where the paid one ends — not when the charge is
	// made, which is a day early (subscription_charge_at()), or later still
	// after a failed attempt. So the subscription's dates never move: charged
	// early, late or on a retry, each period runs end to end.
	$start = subscription_paid_until( $row );

	if ( $start <= 0 ) {
		$start = strtotime( (string) $row['next_payment_at'] . ' UTC' );
		$start = $start ? $start : time();
	}
	$end   = subscription_next_payment( (string) $row['period'], $start, subscription_anchor_day( $row ) );

	$existing = subscription_renewal_find( (string) $row['external_id'], $start );

	if ( $existing ) {
		subscription_renewal_expire_stale( $existing );
		return;
	}

	$amount = (float) $row['amount'];

	$order = wc_create_order(
		array(
			'customer_id' => (int) $row['user_id'],
			'status'      => 'pending',
		)
	);

	if ( is_wp_error( $order ) || ! $order ) {
		subscription_renewal_failed( $subscription_id, 'the renewal order could not be created' );
		return;
	}

	// The price agreed at sign-up, not today's — a plan whose price changes
	// should not silently re-price everyone already on it.
	$order->add_product( $product, 1, array( 'subtotal' => $amount, 'total' => $amount ) );
	$order->set_currency( (string) $row['currency'] );

	// The object, not the id: given a string WooCommerce sets the method but
	// leaves the title empty, and the orders list shows "Unknown" for it.
	$order->set_payment_method( $gateway );
	$order->calculate_totals( false );

	// Origin column. update, not add: renewed from the admin button,
	// WooCommerce has already stamped "admin" on this order while creating it.
	$order->update_meta_data( subscription_attribution_source_key(), SUBSCRIPTION_RENEWAL_SOURCE_TYPE );

	// What this payment buys, recorded before it is taken so the orders API has
	// it whichever way the payment completes.
	subscription_order_record(
		$order,
		array(
			'subscription_id' => (string) $row['external_id'],
			'plan'            => (string) $row['period'],
			'starts'          => $start,
			'ends'            => $end,
		)
	);

	// The gateway looks here first for a card to charge. Which card is decided
	// by subscription_renewal_token(); whatever it settles on is written back,
	// so the admin list shows the card that is actually being charged.
	$token_id = subscription_renewal_token( $row );

	if ( $token_id !== (int) $row['token_id'] ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			subscriptions_table(),
			array( 'token_id' => $token_id ),
			array( 'id' => (int) $subscription_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	if ( $token_id && class_exists( '\WC_Payment_Tokens' ) ) {
		$token = \WC_Payment_Tokens::get( $token_id );

		if ( $token ) {
			$order->add_payment_token( $token );
		}
	}

	$order->save();

	/**
	 * Hand the charge to the gateway.
	 *
	 * The convention WooCommerce Subscriptions established and tokenising
	 * gateways implement, so this stays gateway-agnostic. The gateway marks the
	 * order paid or failed; the period moves on the order's status change, not
	 * on this call returning, because a payment can also complete by webhook.
	 */
	do_action( $renewal_hook, $amount, $order );

	// A gateway marks the order paid or failed. Still pending means it did
	// neither — it may be waiting on a webhook, which is legitimate, so the
	// subscription is left alone; but say so on the order, because this is
	// otherwise indistinguishable from a renewal that never ran.
	$order = wc_get_order( $order->get_id() );

	if ( $order && $order->has_status( 'pending' ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: payment gateway id. */
				__( 'The %s gateway did not complete this renewal. If it settles by webhook this is expected; otherwise the renewal did not go through.', 'tutor-sso' ),
				$gateway_id
			)
		);
	}
}
add_action( SUBSCRIPTION_RENEW_HOOK, __NAMESPACE__ . '\\subscription_renew' );

/**
 * Move the subscription on once a renewal is paid.
 *
 * Driven by the order's status rather than by the renewal call returning: the
 * gateway may complete the payment synchronously or by webhook, and only the
 * status change catches both.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function subscription_renewal_paid( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( (int) $order_id );

	if ( ! $order ) {
		return;
	}

	$external_id = trim( (string) $order->get_meta( SUBSCRIPTION_ORDER_ID_META ) );
	$ends        = (int) $order->get_meta( SUBSCRIPTION_ORDER_ENDS_META );

	if ( '' === $external_id || $ends <= 0 ) {
		return;
	}

	// Once only: processing and completed both fire, and WooCommerce replays
	// them on any later status change.
	if ( 'yes' === $order->get_meta( SUBSCRIPTION_RENEWAL_APPLIED_META ) ) {
		return;
	}

	global $wpdb;

	$table = subscriptions_table();

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT id, status FROM {$table} WHERE external_id = %s", $external_id ),
		ARRAY_A
	);

	// Not recorded yet: a first payment reaches here before checkout capture
	// has written the row, and capture sets the dates itself.
	if ( ! $row ) {
		return;
	}

	$order->update_meta_data( SUBSCRIPTION_RENEWAL_APPLIED_META, 'yes' );

	// A cancelled or expired subscription is not revived by a payment arriving
	// late — an admin marking an old order paid, say. Said on the order, so the
	// payment is not mistaken for one that extended anything.
	if ( 'active' !== $row['status'] ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: subscription status. */
				__( 'This payment did not extend the subscription, which is %s.', 'tutor-sso' ),
				$row['status']
			)
		);
		$order->save();
		return;
	}

	$order->save();

	// Forward only: an older renewal completing late must not pull the next
	// payment back to a date already billed for, or clear the failure count
	// of a newer renewal that is still being retried.
	$charge_at = gmdate( 'Y-m-d H:i:s', subscription_charge_at( $ends ) );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"UPDATE {$table} SET next_payment_at = %s, failure_count = 0 WHERE id = %d AND ( next_payment_at IS NULL OR next_payment_at < %s )",
			$charge_at,
			(int) $row['id'],
			$charge_at
		)
	);

	subscription_extend_paid_until( (int) $row['id'], $ends );
}
add_action( 'woocommerce_order_status_processing', __NAMESPACE__ . '\\subscription_renewal_paid', 10, 1 );
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ . '\\subscription_renewal_paid', 10, 1 );

/**
 * Record a failed renewal and decide what happens next.
 *
 * `next_payment_at` must move even on failure. Left where it is, the row stays
 * due and every hourly scan would present the same card again — a retry storm
 * against the gateway rather than a retry schedule.
 *
 * @param int    $subscription_id Row id.
 * @param string $reason          For the order note, where there is an order.
 * @return void
 */
function subscription_renewal_failed( $subscription_id, $reason = '' ) {
	$row = subscription_get( $subscription_id );

	if ( ! $row || 'active' !== $row['status'] ) {
		return;
	}

	global $wpdb;

	$attempts = (int) $row['failure_count'];
	$schedule = subscription_retry_schedule();

	// Out of retries: stop charging and let access lapse at the date already
	// paid for.
	if ( ! isset( $schedule[ $attempts ] ) ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			subscriptions_table(),
			array(
				'status'          => 'expired',
				'failure_count'   => $attempts + 1,
				'next_payment_at' => null,
			),
			array( 'id' => (int) $subscription_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		/**
		 * Fires when a subscription has run out of retries.
		 *
		 * @param int    $subscription_id Row id.
		 * @param string $reason          Why the last attempt failed.
		 */
		do_action( 'tutor_sso_subscription_expired', (int) $subscription_id, (string) $reason );

		return;
	}


	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array(
			'failure_count'   => $attempts + 1,
			'next_payment_at' => gmdate( 'Y-m-d H:i:s', time() + ( (int) $schedule[ $attempts ] * DAY_IN_SECONDS ) ),
		),
		array( 'id' => (int) $subscription_id ),
		array( '%d', '%s' ),
		array( '%d' )
	);

	/**
	 * Fires after a failed renewal that will be retried.
	 *
	 * @param int    $subscription_id Row id.
	 * @param int    $attempts        Failures so far, this one included.
	 * @param string $reason          Why it failed.
	 */
	do_action( 'tutor_sso_subscription_payment_failed', (int) $subscription_id, $attempts + 1, (string) $reason );
}

/**
 * Order meta: this renewal's failure has been counted.
 */
const SUBSCRIPTION_RENEWAL_FAILURE_COUNTED_META = '_tutor_sso_renewal_failure_counted';

/**
 * The order statuses that mean a renewal payment failed.
 *
 * "failed", plus whatever status Moyasar is set to give a failed payment
 * (Moyasar → Advanced → Failed Status, "failed" unless changed). Paid and
 * in-progress statuses are never taken as failure, whatever that setting says.
 *
 * @return string[]
 */
function subscription_renewal_failed_statuses() {
	$statuses = array( 'failed' );

	$settings = get_option( 'woocommerce_moyasar_settings', array() );
	$moyasar  = is_array( $settings ) && ! empty( $settings['order_status_failed'] )
		? preg_replace( '/^wc-/', '', (string) $settings['order_status_failed'] )
		: '';

	if ( '' !== $moyasar && ! in_array( $moyasar, array( 'pending', 'processing', 'completed' ), true ) ) {
		$statuses[] = $moyasar;
	}

	/**
	 * Filter the order statuses taken as a failed renewal payment.
	 *
	 * @param string[] $statuses Status slugs, without the "wc-" prefix.
	 */
	return array_unique( (array) apply_filters( 'tutor_sso_subscription_renewal_failed_statuses', $statuses ) );
}

/**
 * The subscription row a renewal order belongs to.
 *
 * Renewal orders only: the order that first bought the plan carries the same
 * identifier, but its failure means nothing was ever subscribed, and it must
 * not count against the subscription it later became.
 *
 * @param \WC_Order $order Order.
 * @return array|null Row, or null when the order is not a renewal.
 */
function subscription_renewal_order_row( $order ) {
	$external_id = trim( (string) $order->get_meta( SUBSCRIPTION_ORDER_ID_META ) );

	if ( '' === $external_id ) {
		return null;
	}

	global $wpdb;

	$table = subscriptions_table();

	$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s", $external_id ),
		ARRAY_A
	);

	if ( ! is_array( $row ) || (int) $row['parent_order_id'] === (int) $order->get_id() ) {
		return null;
	}

	return $row;
}

/**
 * Count a renewal whose order moved to a failed status.
 *
 * Once per order: an order can pass through more than one failed status (failed,
 * then cancelled by hand), and each would otherwise use up a retry.
 *
 * @param int       $order_id Order ID.
 * @param string    $from     Previous status.
 * @param string    $to       New status.
 * @param \WC_Order $order    Order.
 * @return void
 */
function subscription_renewal_order_failed( $order_id, $from, $to, $order = null ) {
	if ( ! in_array( $to, subscription_renewal_failed_statuses(), true ) ) {
		return;
	}

	if ( ! $order instanceof \WC_Order ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
	}

	if ( ! $order || 'yes' === $order->get_meta( SUBSCRIPTION_RENEWAL_FAILURE_COUNTED_META ) ) {
		return;
	}

	$row = subscription_renewal_order_row( $order );

	if ( ! $row ) {
		return;
	}

	$order->update_meta_data( SUBSCRIPTION_RENEWAL_FAILURE_COUNTED_META, 'yes' );
	$order->save();

	subscription_renewal_failed( (int) $row['id'], 'the gateway declined the renewal payment' );
}
add_action( 'woocommerce_order_status_changed', __NAMESPACE__ . '\\subscription_renewal_order_failed', 10, 4 );

/**
 * Fail a renewal order that has been pending too long.
 *
 * Called when the renewal for a period is due again and finds this order in
 * the way. Moving it to "failed" runs the usual failure handling above: the
 * attempt is counted, the next retry is scheduled, and WooCommerce emails the
 * customer that the payment did not go through.
 *
 * @param int $order_id Renewal order ID.
 * @return void
 */
function subscription_renewal_expire_stale( $order_id ) {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

	if ( ! $order || ! $order->has_status( 'pending' ) ) {
		return;
	}

	$created = $order->get_date_created();

	if ( ! $created || $created->getTimestamp() > time() - SUBSCRIPTION_RENEWAL_STALE_AFTER ) {
		return;
	}

	$order->update_status(
		'failed',
		__( 'The renewal payment did not complete within a day, so it is treated as failed and will be retried.', 'tutor-sso' )
	);
}

/**
 * Whether a customer may pay a renewal order themselves.
 *
 * Renewals are charged automatically, but a customer told their payment failed
 * may pay the order from My Account with another card. That is only allowed
 * when it would actually extend an active subscription. Paying an old renewal
 * whose period a later payment already covered, or one belonging to a
 * subscription that has been cancelled or has expired, would charge them for
 * nothing — an expired subscription is replaced by buying a new plan.
 *
 * @param string[]  $statuses Statuses WooCommerce allows payment from.
 * @param \WC_Order $order    Order.
 * @return string[]
 */
function subscription_renewal_payable_statuses( $statuses, $order = null ) {
	if ( ! $order instanceof \WC_Order ) {
		return $statuses;
	}

	$row = subscription_renewal_order_row( $order );

	if ( ! $row ) {
		return $statuses;
	}

	$ends = (int) $order->get_meta( SUBSCRIPTION_ORDER_ENDS_META );

	if ( 'active' !== $row['status'] || $ends <= subscription_paid_until( $row ) ) {
		return array();
	}

	return $statuses;
}
add_filter( 'woocommerce_valid_order_statuses_for_payment', __NAMESPACE__ . '\\subscription_renewal_payable_statuses', 10, 2 );
