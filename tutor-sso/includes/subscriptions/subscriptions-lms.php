<?php
/**
 * Tell the LMS when a subscription's status changes.
 *
 * `PATCH /rwaq/api/subscriptions/<wordpress_subscription_id>/` with an action of
 * cancel, reactivate or revoke. Same host and service-account token as the
 * orders API, which is how the LMS learned about the subscription in the first
 * place.
 *
 * WordPress is the source of truth for billing, so its own status is changed
 * first and never waits on this call: a cancellation has to stop the charges
 * even when the LMS is unreachable. What the LMS last confirmed is recorded on
 * the row instead, so a call that failed is visible and can be retried rather
 * than silently leaving the two systems disagreeing.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Actions the LMS accepts.
 *
 * @return string[]
 */
function subscription_lms_actions() {
	return array( 'cancel', 'reactivate', 'revoke' );
}

/**
 * Send a status change for one subscription.
 *
 * @param string $external_id The wordpress_subscription_id the LMS knows it by.
 * @param string $action      cancel, reactivate or revoke.
 * @param string $reason      Optional; the API keeps up to 1000 characters.
 * @return array|\WP_Error The subscription as the LMS now has it.
 */
function subscription_lms_patch( $external_id, $action, $reason = '' ) {
	$external_id = trim( (string) $external_id );
	$action      = (string) $action;

	if ( '' === $external_id ) {
		return new \WP_Error( 'tutor_sso_subscription_no_id', __( 'The subscription has no identifier to send.', 'tutor-sso' ) );
	}

	if ( ! in_array( $action, subscription_lms_actions(), true ) ) {
		return new \WP_Error( 'tutor_sso_subscription_bad_action', __( 'Unknown subscription action.', 'tutor-sso' ) );
	}

	// Same switch as the orders API: a site that does not post orders to the
	// LMS has nothing there to update either.
	if ( ! orders_api_enabled() ) {
		return new \WP_Error( 'tutor_sso_orders_disabled', __( 'Order syncing is disabled.', 'tutor-sso' ) );
	}

	$base = orders_api_base();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$token = sso_lms_service_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$body = array( 'action' => $action );

	$reason = trim( (string) $reason );

	if ( '' !== $reason ) {
		$body['reason'] = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 1000 ) : substr( $reason, 0, 1000 );
	}

	$response = wp_remote_request(
		$base . '/rwaq/api/subscriptions/' . rawurlencode( $external_id ) . '/',
		array(
			'method'    => 'PATCH',
			'timeout'   => 15,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'JWT ' . $token,
			),
			'body'      => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$raw    = (string) wp_remote_retrieve_body( $response );

	// 200 also for repeating an action already applied, so a retry is safe.
	if ( 200 === $status ) {
		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : array();
	}

	// A stale cached token is the one failure worth clearing automatically.
	if ( 401 === $status ) {
		delete_transient( 'tutor_sso_service_token' );
	}

	// Revoked on the LMS side (refund or chargeback). Kept distinct: it is a
	// final state, not something a retry will change.
	if ( 409 === $status ) {
		return new \WP_Error( 'tutor_sso_subscription_revoked', __( 'The LMS has this subscription as revoked. Only a new payment restores it.', 'tutor-sso' ) );
	}

	if ( 404 === $status ) {
		return new \WP_Error( 'tutor_sso_subscription_unknown', __( 'The LMS does not know this subscription. Check that its order reached the LMS.', 'tutor-sso' ) );
	}

	return new \WP_Error(
		'tutor_sso_subscription_failed',
		sprintf(
			/* translators: 1: HTTP status code, 2: response body. */
			__( 'The LMS rejected the status change (HTTP %1$d): %2$s', 'tutor-sso' ),
			$status,
			wp_strip_all_tags( $raw )
		)
	);
}

/**
 * Send a status change for a subscription row, and record the outcome on it.
 *
 * @param int    $subscription_id Row id.
 * @param string $action          cancel, reactivate or revoke.
 * @param string $reason          Optional reason.
 * @return true|\WP_Error
 */
function subscription_lms_sync( $subscription_id, $action, $reason = '' ) {
	$row = subscription_get( $subscription_id );

	if ( ! $row ) {
		return new \WP_Error( 'tutor_sso_no_subscription', __( 'Subscription not found.', 'tutor-sso' ) );
	}

	try {
		$result = subscription_lms_patch( (string) $row['external_id'], $action, $reason );
	} catch ( \Throwable $e ) {
		// An admin is waiting on this request; a failure here must still let the
		// local change stand and be reported, not take the page down.
		$result = new \WP_Error( 'tutor_sso_subscription_crashed', $e->getMessage() );
	}

	global $wpdb;

	if ( is_wp_error( $result ) && 'tutor_sso_subscription_revoked' !== $result->get_error_code() ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			subscriptions_table(),
			array( 'lms_sync_error' => substr( $result->get_error_message(), 0, 255 ) ),
			array( 'id' => (int) $subscription_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result;
	}

	// Revoked counts as settled: the LMS has a final answer, and repeating the
	// call would only get the same 409.
	$confirmed = is_wp_error( $result )
		? 'revoked'
		: ( isset( $result['status'] ) && is_string( $result['status'] ) ? sanitize_key( $result['status'] ) : $action );

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array(
			'lms_status'     => $confirmed,
			'lms_sync_error' => null,
		),
		array( 'id' => (int) $subscription_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	return true;
}
