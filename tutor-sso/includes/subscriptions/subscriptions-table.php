<?php
/**
 * The subscriptions table.
 *
 * A table rather than a custom post type: the only query that has to be fast is
 * "which subscriptions are due now", which is one indexed lookup here and a
 * meta_query against postmeta otherwise.
 *
 * Created from a stored schema version rather than an activation hook — the
 * plugin registers none, and one would not fire for an install that is already
 * active, so the table would silently never appear.
 *
 * `external_id` is the identifier the LMS keys a subscription on. It is stored
 * rather than derived from the row id so it survives anything that renumbers
 * the table — a migration, a re-import, a rebuild — and so two installs can
 * never mint the same value. The LMS holds it permanently, so a row whose
 * identifier changed would silently stop extending its subscription and start
 * creating duplicates instead.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bump when the schema below changes, so dbDelta runs again.
 */
const SUBSCRIPTIONS_DB_VERSION = '4';

/**
 * Option holding the installed schema version.
 */
const SUBSCRIPTIONS_DB_VERSION_OPTION = 'tutor_sso_subscriptions_db_version';

/**
 * Full table name, prefix included.
 *
 * @return string
 */
function subscriptions_table() {
	global $wpdb;

	return $wpdb->prefix . 'tutor_sso_subscriptions';
}

/**
 * Create or update the table when the schema version has moved.
 *
 * @return void
 */
function subscriptions_install_table() {
	// Deliberately not gated on the feature switch. An empty table costs
	// nothing and nothing queries it, whereas creating it only once the switch
	// is flipped would make its existence depend on an admin page load
	// happening afterwards — a fragile ordering dependency for something that
	// has to be there the moment a plan is sold.
	if ( (string) get_option( SUBSCRIPTIONS_DB_VERSION_OPTION ) === SUBSCRIPTIONS_DB_VERSION ) {
		return;
	}

	global $wpdb;

	$table   = subscriptions_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta is picky: two spaces after PRIMARY KEY, one field per line, and
	// KEY names it can match against SHOW INDEX.
	//
	// No comments inside the SQL. On an existing table dbDelta reads each line's
	// first word as a column name, so a "-- note" line becomes an attempt to
	// ADD COLUMN "--", which fails on every upgrade.
	//
	// external_id is nullable rather than NOT NULL DEFAULT '': MySQL allows any
	// number of NULLs in a unique index but only one '', so a row that somehow
	// arrived without an identifier would otherwise block every later one.
	//
	// lms_status is the status the LMS last confirmed for a status change, and
	// lms_sync_error why the last attempt failed; see subscriptions-lms.php.
	//
	// paid_until is the end of the last period actually paid for — what the
	// customer keeps after cancelling. Not next_payment_at, which is cleared on
	// cancel and holds the retry date while a payment is failing.
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		external_id varchar(64) DEFAULT NULL,
		user_id bigint(20) unsigned NOT NULL,
		product_id bigint(20) unsigned NOT NULL,
		parent_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		status varchar(20) NOT NULL DEFAULT 'active',
		period varchar(20) NOT NULL DEFAULT 'monthly',
		amount decimal(19,4) NOT NULL DEFAULT 0.0000,
		currency char(3) NOT NULL DEFAULT '',
		gateway_id varchar(100) NOT NULL DEFAULT '',
		token_id bigint(20) unsigned NOT NULL DEFAULT 0,
		next_payment_at datetime DEFAULT NULL,
		failure_count smallint(5) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		cancelled_at datetime DEFAULT NULL,
		paid_until datetime DEFAULT NULL,
		lms_status varchar(20) DEFAULT NULL,
		lms_sync_error varchar(255) DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY external_id (external_id),
		KEY user_id (user_id),
		KEY parent_order_id (parent_order_id),
		KEY due (status, next_payment_at)
	) {$collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( SUBSCRIPTIONS_DB_VERSION_OPTION, SUBSCRIPTIONS_DB_VERSION, false );
}
add_action( 'admin_init', __NAMESPACE__ . '\\subscriptions_install_table' );

/**
 * How many subscriptions are currently billing.
 *
 * Used to refuse switching the feature off while people are still being
 * charged. A missing or unreadable table counts as none: the renewal engine
 * could not be running either, so there is nothing to protect — and reporting
 * a phantom count would trap the administrator with a switch they can never
 * turn off.
 *
 * @return int
 */
function subscriptions_active_count() {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return 0;
	}

	$table = subscriptions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );

	return null === $count ? 0 : (int) $count;
}

/**
 * The plan a customer is actively subscribed to, if any.
 *
 * Any plan, not a particular one: every plan unlocks the same subscription
 * courses, so a second one would charge twice for the same access. Active only,
 * so someone who has cancelled or expired can subscribe again.
 *
 * @param int $user_id Customer.
 * @return int Plan product ID, or 0.
 */
function subscription_user_active_product( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;

	if ( ! $user_id || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return 0;
	}

	$table = subscriptions_table();

	$product_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT product_id FROM {$table} WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
			$user_id
		)
	);

	return (int) $product_id;
}

/**
 * Move a subscription's paid-until date forward.
 *
 * Only ever forward: a payment processed out of order, or replayed, must not
 * shorten what has already been paid for.
 *
 * @param int $subscription_id Row id.
 * @param int $until           End of the paid period, Unix timestamp.
 * @return void
 */
function subscription_extend_paid_until( $subscription_id, $until ) {
	global $wpdb;

	$until = (int) $until;

	if ( $until <= 0 ) {
		return;
	}

	$table = subscriptions_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET paid_until = %s WHERE id = %d AND ( paid_until IS NULL OR paid_until < %s )",
			gmdate( 'Y-m-d H:i:s', $until ),
			(int) $subscription_id,
			gmdate( 'Y-m-d H:i:s', $until )
		)
	);
}

/**
 * When a subscription's paid period ends, as a Unix timestamp.
 *
 * Read from the row. A row from before the column existed is worked out once
 * from its last paid order and written back, so this costs a query only the
 * first time.
 *
 * @param array $row Subscription row.
 * @return int 0 when it cannot be known.
 */
function subscription_paid_until( $row ) {
	if ( ! is_array( $row ) ) {
		return 0;
	}

	if ( ! empty( $row['paid_until'] ) ) {
		$stored = strtotime( $row['paid_until'] . ' UTC' );

		if ( $stored ) {
			return (int) $stored;
		}
	}

	if ( empty( $row['external_id'] ) || ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$orders = subscription_get_orders(
		array(
			'limit'  => -1,
			'status' => array( 'processing', 'completed' ),
		),
		array(
			array(
				'key'   => SUBSCRIPTION_ORDER_ID_META,
				'value' => (string) $row['external_id'],
			),
		)
	);

	$until = 0;

	foreach ( (array) $orders as $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			$until = max( $until, (int) $order->get_meta( SUBSCRIPTION_ORDER_ENDS_META ) );
		}
	}

	if ( $until > 0 && ! empty( $row['id'] ) ) {
		subscription_extend_paid_until( (int) $row['id'], $until );
	}

	return $until;
}
