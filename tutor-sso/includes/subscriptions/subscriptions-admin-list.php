<?php
/**
 * The subscriptions list screen.
 *
 * Settings → Subscriptions. Shows who is subscribed to what, when they are due
 * and whether anything has been failing, with a per-row renewal.
 *
 * The per-row button charges that subscription straight away whether or not it
 * is due — subscription_renew() only requires the subscription to be active.
 * That is what makes a renewal testable without waiting a month or editing the
 * database: buy a plan, then renew it from here. The period still starts where
 * the last one ended, so renewing early extends rather than overlaps, and the
 * duplicate-order guard still refuses to bill one period twice.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This screen's slug.
 */
const SUBSCRIPTION_LIST_SLUG = 'tutor-sso-subscriptions';

/**
 * admin-post action for a single renewal.
 */
const SUBSCRIPTION_RENEW_ONE_ACTION = 'tutor_sso_renew_one';

/**
 * admin-post action for cancelling one subscription.
 */
const SUBSCRIPTION_CANCEL_ACTION = 'tutor_sso_cancel_subscription';

/**
 * admin-post action for deleting one subscription.
 */
const SUBSCRIPTION_DELETE_ACTION = 'tutor_sso_delete_subscription';

/**
 * admin-post action for re-sending a cancellation to the LMS.
 */
const SUBSCRIPTION_LMS_RETRY_ACTION = 'tutor_sso_lms_retry';

/**
 * Reason sent to the LMS for a cancellation made here.
 */
function subscription_admin_cancel_reason() {
	return __( 'Cancelled by an administrator in WordPress', 'tutor-sso' );
}

/**
 * Rows per page.
 */
const SUBSCRIPTION_LIST_PER_PAGE = 20;

/**
 * Add the screen, beside the settings page.
 *
 * @return void
 */
function subscription_list_menu() {
	add_options_page(
		__( 'Subscriptions', 'tutor-sso' ),
		__( 'Subscriptions', 'tutor-sso' ),
		'manage_options',
		SUBSCRIPTION_LIST_SLUG,
		__NAMESPACE__ . '\\subscription_list_render'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\subscription_list_menu' );

/**
 * One page of subscriptions, newest first.
 *
 * @param int $page Page number, from 1.
 * @return array{rows:array,total:int}
 */
function subscription_list_query( $page = 1 ) {
	global $wpdb;

	$table  = subscriptions_table();
	$page   = max( 1, (int) $page );
	$offset = ( $page - 1 ) * SUBSCRIPTION_LIST_PER_PAGE;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", SUBSCRIPTION_LIST_PER_PAGE, $offset ),
		ARRAY_A
	);

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $total,
	);
}

/**
 * A stored UTC datetime, shown in the site's timezone.
 *
 * @param string|null $value Datetime from the table.
 * @return string
 */
function subscription_list_date( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return '—';
	}

	$timestamp = strtotime( $value . ' UTC' );

	if ( ! $timestamp ) {
		return '—';
	}

	return wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
}

/**
 * What a renewal would charge, and whether it could.
 *
 * Both of these decide whether a renewal can happen at all, and neither was
 * visible anywhere — which made a subscription that silently refused to renew
 * impossible to tell apart from one that had simply not come due. A missing
 * gateway or card is the first thing to look at, so it is shown as a warning
 * rather than left blank.
 *
 * @param array $row Subscription row.
 * @return string Escaped HTML.
 */
function subscription_list_payment( $row ) {
	$gateway_id = trim( (string) $row['gateway_id'] );
	$token_id   = (int) $row['token_id'];

	if ( '' === $gateway_id ) {
		return '<span style="color:#b32d2e">' . esc_html__( 'No gateway recorded — this cannot renew.', 'tutor-sso' ) . '</span>';
	}

	$gateway = function_exists( __NAMESPACE__ . '\\subscription_gateway' )
		? subscription_gateway( $gateway_id )
		: null;

	$name = $gateway && method_exists( $gateway, 'get_title' ) ? $gateway->get_title() : $gateway_id;
	$out  = esc_html( $name );

	if ( ! $gateway ) {
		$out .= '<br /><span style="color:#b32d2e">' . esc_html__( 'gateway not available', 'tutor-sso' ) . '</span>';

		return $out;
	}

	if ( ! $token_id && function_exists( __NAMESPACE__ . '\\subscription_find_token' ) ) {
		// Nothing recorded on the row is not the same as nothing to charge: the
		// card may be on the customer's account, where the renewal will find it.
		// Say which of the two it is, since only one of them is a problem.
		$token_id = subscription_find_token( (int) $row['user_id'], $gateway_id );

		if ( $token_id ) {
			$out .= '<br /><small>' . esc_html__( 'using the card on the customer\'s account', 'tutor-sso' ) . '</small>';
		}
	}

	if ( ! $token_id || ! class_exists( '\\WC_Payment_Tokens' ) ) {
		return $out . '<br /><span style="color:#b32d2e">' . esc_html__( 'no card to charge — this cannot renew', 'tutor-sso' ) . '</span>';
	}

	$token = \WC_Payment_Tokens::get( $token_id );

	if ( ! $token ) {
		return $out . '<br /><span style="color:#b32d2e">' . esc_html__( 'saved card no longer exists', 'tutor-sso' ) . '</span>';
	}

	return $out . '<br /><small>' . esc_html( $token->get_display_name() ) . '</small>';
}

/**
 * What the LMS knows of this subscription.
 *
 * An active subscription reaches the LMS through the order that bought it, so
 * it reads as active there once that order has been sent — or once a resume
 * has been confirmed. Cancellations are sent from here, and their outcome is
 * recorded on the row. An expired one needs no call: the LMS works expiry out
 * from the end date itself.
 *
 * @param array $row Subscription row.
 * @return string Escaped HTML.
 */
function subscription_list_lms( $row ) {
	if ( 'active' === $row['status'] ) {
		return subscription_list_lms_active( $row );
	}

	if ( 'cancelled' !== $row['status'] ) {
		return '—';
	}

	$confirmed = isset( $row['lms_status'] ) ? (string) $row['lms_status'] : '';

	if ( 'cancelled' === $confirmed ) {
		return '<span style="color:#00a32a">' . esc_html__( 'Cancelled', 'tutor-sso' ) . '</span>';
	}

	if ( 'revoked' === $confirmed ) {
		return esc_html__( 'Revoked on the LMS', 'tutor-sso' );
	}

	$error = isset( $row['lms_sync_error'] ) ? trim( (string) $row['lms_sync_error'] ) : '';

	// Never sent covers subscriptions cancelled before this was added.
	$out = '<span style="color:#b32d2e">'
		. esc_html( '' === $error ? __( 'Not sent to the LMS', 'tutor-sso' ) : $error )
		. '</span><br />';

	$url = wp_nonce_url(
		add_query_arg(
			array(
				'action'          => SUBSCRIPTION_LMS_RETRY_ACTION,
				'subscription_id' => (int) $row['id'],
			),
			admin_url( 'admin-post.php' )
		),
		SUBSCRIPTION_LMS_RETRY_ACTION . '_' . (int) $row['id']
	);

	return $out . sprintf(
		'<a href="%1$s" class="button button-small">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'Send to LMS', 'tutor-sso' )
	);
}

/**
 * The LMS column for an active subscription.
 *
 * Read from the order sync's own record on the order that bought the plan
 * (orders_api_handle_order()), so this shows what was actually sent rather
 * than assuming it. A failed send links to that order, whose notes say why.
 *
 * @param array $row Subscription row.
 * @return string Escaped HTML.
 */
function subscription_list_lms_active( $row ) {
	$active = '<span style="color:#00a32a">' . esc_html__( 'Active', 'tutor-sso' ) . '</span>';

	// Resumed: the LMS confirmed it directly.
	if ( isset( $row['lms_status'] ) && 'active' === (string) $row['lms_status'] ) {
		return $active;
	}

	$order_id = isset( $row['parent_order_id'] ) ? (int) $row['parent_order_id'] : 0;
	$sent     = $order_id && defined( __NAMESPACE__ . '\\ORDERS_API_SENT_META' )
		? (string) get_post_meta( $order_id, ORDERS_API_SENT_META, true )
		: '';

	if ( 'sent' === $sent ) {
		return $active;
	}

	if ( 'error' === $sent ) {
		$out = '<span style="color:#b32d2e">' . esc_html__( 'Not sent to the LMS', 'tutor-sso' ) . '</span>';
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( $order ) {
			$out .= '<br /><a href="' . esc_url( $order->get_edit_order_url() ) . '">'
				. esc_html__( 'See the order notes', 'tutor-sso' ) . '</a>';
		}

		return $out;
	}

	// Never attempted — the orders sync is switched off, or the order predates it.
	return '—';
}

/**
 * Render the screen.
 *
 * @return void
 */
function subscription_list_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page   = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1;
	$result = subscription_list_query( $page );
	$pages  = (int) ceil( $result['total'] / SUBSCRIPTION_LIST_PER_PAGE );

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Subscriptions', 'tutor-sso' ) . '</h1>';

	if ( empty( $result['rows'] ) ) {
		echo '<p>' . esc_html__( 'No subscriptions yet. One is created when a plan is bought.', 'tutor-sso' ) . '</p></div>';
		return;
	}

	echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
	foreach ( array(
		__( 'Subscription', 'tutor-sso' ),
		__( 'Customer', 'tutor-sso' ),
		__( 'Plan', 'tutor-sso' ),
		__( 'Status', 'tutor-sso' ),
		__( 'Amount', 'tutor-sso' ),
		__( 'Payment', 'tutor-sso' ),
		__( 'Next payment', 'tutor-sso' ),
		__( 'Failures', 'tutor-sso' ),
		__( 'LMS', 'tutor-sso' ),
		__( 'Actions', 'tutor-sso' ),
	) as $heading ) {
		echo '<th>' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $result['rows'] as $row ) {
		$user    = get_userdata( (int) $row['user_id'] );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row['product_id'] ) : null;

		echo '<tr>';

		// The identifier the LMS keys on, with the plan product under it.
		echo '<td><code>' . esc_html( (string) $row['external_id'] ) . '</code><br /><small>'
			. esc_html( $product ? $product->get_name() : sprintf( '#%d', (int) $row['product_id'] ) )
			. '</small></td>';

		echo '<td>' . esc_html( $user ? $user->user_email : sprintf( '#%d', (int) $row['user_id'] ) ) . '</td>';
		echo '<td>' . esc_html( (string) $row['period'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row['amount'] . ' ' . $row['currency'] ) . '</td>';
		echo '<td>' . wp_kses_post( subscription_list_payment( $row ) ) . '</td>';
		echo '<td>' . esc_html( subscription_list_date( $row['next_payment_at'] ) ) . '</td>';
		echo '<td>' . esc_html( (string) (int) $row['failure_count'] ) . '</td>';
		echo '<td>' . wp_kses_post( subscription_list_lms( $row ) ) . '</td>';

		echo '<td>';

		// Only an active subscription has anything to charge.
		if ( 'active' === $row['status'] ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'          => SUBSCRIPTION_RENEW_ONE_ACTION,
						'subscription_id' => (int) $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				SUBSCRIPTION_RENEW_ONE_ACTION . '_' . (int) $row['id']
			);

			printf(
				'<a href="%1$s" class="button button-small">%2$s</a> ',
				esc_url( $url ),
				esc_html__( 'Renew now', 'tutor-sso' )
			);

			$cancel_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'          => SUBSCRIPTION_CANCEL_ACTION,
						'subscription_id' => (int) $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				SUBSCRIPTION_CANCEL_ACTION . '_' . (int) $row['id']
			);

			printf(
				'<a href="%1$s" class="button button-small" onclick="return confirm(%2$s)">%3$s</a>',
				esc_url( $cancel_url ),
				esc_attr( wp_json_encode( __( 'Stop renewing this subscription? Access already paid for runs to its end date.', 'tutor-sso' ) ) ),
				esc_html__( 'Cancel', 'tutor-sso' )
			);
		} else {
			// Only once it can no longer be charged; see subscription_delete_one().
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'          => SUBSCRIPTION_DELETE_ACTION,
						'subscription_id' => (int) $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				SUBSCRIPTION_DELETE_ACTION . '_' . (int) $row['id']
			);

			printf(
				'<a href="%1$s" class="button button-small button-link-delete" onclick="return confirm(%2$s)">%3$s</a>',
				esc_url( $delete_url ),
				esc_attr( wp_json_encode( __( 'Permanently delete this subscription record? This cannot be undone. Its orders are kept.', 'tutor-sso' ) ) ),
				esc_html__( 'Delete', 'tutor-sso' )
			);
		}

		echo '</td></tr>';
	}

	echo '</tbody></table>';

	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => max( 1, $page ),
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}

	echo '</div>';
}

/**
 * Charge one subscription now.
 *
 * @return void
 */
function subscription_renew_one() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'tutor-sso' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$subscription_id = isset( $_GET['subscription_id'] ) ? (int) $_GET['subscription_id'] : 0;

	// Nonce is per subscription, so a link for one cannot charge another.
	check_admin_referer( SUBSCRIPTION_RENEW_ONE_ACTION . '_' . $subscription_id );

	if ( $subscription_id ) {
		subscription_renew( $subscription_id );
	}

	wp_safe_redirect(
		add_query_arg(
			'tutor_sso_renewed',
			$subscription_id,
			admin_url( 'options-general.php?page=' . SUBSCRIPTION_LIST_SLUG )
		)
	);
	exit;
}
add_action( 'admin_post_' . SUBSCRIPTION_RENEW_ONE_ACTION, __NAMESPACE__ . '\\subscription_renew_one' );

/**
 * Stop renewing one subscription.
 *
 * Deliberately not a delete. The row is the record of what was sold and what
 * the LMS was told, and renewals already taken stay true after cancelling — so
 * the subscription is marked cancelled and simply stops coming due. Access
 * already paid for runs to the end date it was paid to, which is why
 * next_payment_at is cleared rather than the status alone being changed: a row
 * left with a due date would be picked up by the next scan.
 *
 * Cancelling a renewal *order* does not do this. An order is one payment; the
 * subscription is the standing arrangement, and WooCommerce has no idea the two
 * are related.
 *
 * @return void
 */
function subscription_cancel_one() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'tutor-sso' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$subscription_id = isset( $_GET['subscription_id'] ) ? (int) $_GET['subscription_id'] : 0;

	check_admin_referer( SUBSCRIPTION_CANCEL_ACTION . '_' . $subscription_id );

	if ( $subscription_id ) {
		// Billing stops whether or not the LMS answers; a failure is recorded
		// on the row. See subscriptions-manage.php.
		$result = subscription_cancel( $subscription_id, subscription_admin_cancel_reason() );
		$lms    = $result['lms'];
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'tutor_sso_cancelled' => $subscription_id,
				'tutor_sso_lms'       => isset( $lms ) && true === $lms ? 'ok' : 'failed',
			),
			admin_url( 'options-general.php?page=' . SUBSCRIPTION_LIST_SLUG )
		)
	);
	exit;
}

/**
 * Re-send a cancellation the LMS did not confirm.
 *
 * Only for a cancelled subscription. Safe to repeat: the LMS answers a repeated
 * cancel with the current state.
 *
 * @return void
 */
function subscription_lms_retry_one() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'tutor-sso' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$subscription_id = isset( $_GET['subscription_id'] ) ? (int) $_GET['subscription_id'] : 0;

	check_admin_referer( SUBSCRIPTION_LMS_RETRY_ACTION . '_' . $subscription_id );

	$row    = $subscription_id ? subscription_get( $subscription_id ) : null;
	$result = 'failed';

	if ( $row && 'cancelled' === $row['status'] ) {
		$result = true === subscription_lms_sync( $subscription_id, 'cancel', subscription_admin_cancel_reason() ) ? 'ok' : 'failed';
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'tutor_sso_lms_retried' => $subscription_id,
				'tutor_sso_lms'         => $result,
			),
			admin_url( 'options-general.php?page=' . SUBSCRIPTION_LIST_SLUG )
		)
	);
	exit;
}
add_action( 'admin_post_' . SUBSCRIPTION_LMS_RETRY_ACTION, __NAMESPACE__ . '\\subscription_lms_retry_one' );
add_action( 'admin_post_' . SUBSCRIPTION_CANCEL_ACTION, __NAMESPACE__ . '\\subscription_cancel_one' );

/**
 * Permanently delete one subscription row.
 *
 * Meant for clearing out test data. Refused for an active subscription — cancel
 * it first — because deleting a live one would silently stop its renewals while
 * the customer still believes they are subscribed. Checked here, not just by
 * hiding the button, so a crafted link cannot get round it.
 *
 * Only the row goes. The orders that paid for it stay, as the financial record;
 * their renewal hooks look the subscription up by its identifier and simply
 * find nothing. The LMS is not told: it keeps whatever the orders API sent it.
 *
 * @return void
 */
function subscription_delete_one() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'tutor-sso' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$subscription_id = isset( $_GET['subscription_id'] ) ? (int) $_GET['subscription_id'] : 0;

	check_admin_referer( SUBSCRIPTION_DELETE_ACTION . '_' . $subscription_id );

	$row    = $subscription_id ? subscription_get( $subscription_id ) : null;
	$result = 'missing';

	if ( $row && 'active' === $row['status'] ) {
		$result = 'active';
	} elseif ( $row ) {
		global $wpdb;

		$wpdb->delete( subscriptions_table(), array( 'id' => $subscription_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// A renewal queued before it was cancelled would find no row and do
		// nothing, but there is no reason to leave it sitting in the queue.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( SUBSCRIPTION_RENEW_HOOK, array( $subscription_id ), SUBSCRIPTION_AS_GROUP );
		}

		$result = 'deleted';
	}

	wp_safe_redirect(
		add_query_arg(
			'tutor_sso_deleted',
			$result,
			admin_url( 'options-general.php?page=' . SUBSCRIPTION_LIST_SLUG )
		)
	);
	exit;
}
add_action( 'admin_post_' . SUBSCRIPTION_DELETE_ACTION, __NAMESPACE__ . '\\subscription_delete_one' );

/**
 * Report a deletion.
 *
 * @return void
 */
function subscription_deleted_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result = isset( $_GET['tutor_sso_deleted'] ) ? sanitize_key( wp_unslash( $_GET['tutor_sso_deleted'] ) ) : '';

	if ( 'deleted' === $result ) {
		$class   = 'notice-success';
		$message = __( 'Subscription deleted.', 'tutor-sso' );
	} elseif ( 'active' === $result ) {
		$class   = 'notice-error';
		$message = __( 'An active subscription cannot be deleted. Cancel it first.', 'tutor-sso' );
	} elseif ( 'missing' === $result ) {
		$class   = 'notice-warning';
		$message = __( 'That subscription no longer exists.', 'tutor-sso' );
	} else {
		return;
	}

	printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
}
add_action( 'admin_notices', __NAMESPACE__ . '\\subscription_deleted_notice' );

/**
 * Report a single renewal.
 *
 * Says what was attempted, not what succeeded — the gateway may finish by
 * webhook after this request ends.
 *
 * @return void
 */
function subscription_renew_one_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( empty( $_GET['tutor_sso_renewed'] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
		esc_html__( 'Renewal attempted. Check the orders list for the result, and this page for the new payment date.', 'tutor-sso' )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\subscription_renew_one_notice' );

/**
 * Report a cancellation.
 *
 * @return void
 */
function subscription_cancelled_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cancelled = isset( $_GET['tutor_sso_cancelled'] ) ? (int) $_GET['tutor_sso_cancelled'] : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$retried = isset( $_GET['tutor_sso_lms_retried'] ) ? (int) $_GET['tutor_sso_lms_retried'] : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$lms_ok = isset( $_GET['tutor_sso_lms'] ) && 'ok' === $_GET['tutor_sso_lms'];

	if ( ! $cancelled && ! $retried ) {
		return;
	}

	if ( $lms_ok ) {
		$class   = 'notice-success';
		$message = $cancelled
			? __( 'Subscription cancelled here and on the LMS. It will not be charged again; access already paid for runs to its end date.', 'tutor-sso' )
			: __( 'The LMS has been updated.', 'tutor-sso' );
	} else {
		$class   = 'notice-warning';
		$message = $cancelled
			? __( 'Subscription cancelled here, so it will not be charged again — but the LMS was not updated. The reason is in the LMS column; use "Send to LMS" to try again.', 'tutor-sso' )
			: __( 'The LMS still was not updated. The reason is in the LMS column.', 'tutor-sso' );
	}

	printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
}
add_action( 'admin_notices', __NAMESPACE__ . '\\subscription_cancelled_notice' );
