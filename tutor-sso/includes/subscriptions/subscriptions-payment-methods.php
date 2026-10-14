<?php
/**
 * Saved cards and subscriptions.
 *
 * The Payment methods page is back in My Account so a customer can replace a
 * card before it expires. Two rules keep that from breaking a subscription:
 *
 *   - Renewals charge the customer's default card (see
 *     subscription_renewal_token()), so "add a card, make it default" is all it
 *     takes to move a subscription onto it.
 *   - The last card that can pay for an active subscription cannot be deleted.
 *     Its Delete button is hidden, a note says why, and the delete link itself
 *     is refused — hiding the button alone would not stop a pasted link.
 *
 * "Can pay for" means saved under the same gateway as the subscription. A card
 * saved under the card gateway cannot renew a subscription taken out with
 * Apple Pay, so for that subscription it does not count. Such a subscription
 * keeps renewing through Apple Pay; if that ever fails it expires, and the
 * customer subscribes again with whichever card they like.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateways the customer has an active subscription on.
 *
 * @param int $user_id Customer.
 * @return string[]
 */
function subscription_user_active_gateways( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return array();
	}

	$table = subscriptions_table();

	$gateways = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT DISTINCT gateway_id FROM {$table} WHERE user_id = %d AND status = 'active'", $user_id )
	);

	return array_values( array_filter( array_map( 'strval', (array) $gateways ) ) );
}

/**
 * Whether deleting this card would leave an active subscription unable to pay.
 *
 * @param \WC_Payment_Token $token Saved card.
 * @return bool
 */
function subscription_token_is_protected( $token ) {
	if ( ! is_object( $token ) || ! method_exists( $token, 'get_gateway_id' ) || ! class_exists( '\WC_Payment_Tokens' ) ) {
		return false;
	}

	$gateway = (string) $token->get_gateway_id();

	if ( ! in_array( $gateway, subscription_user_active_gateways( (int) $token->get_user_id() ), true ) ) {
		return false;
	}

	foreach ( \WC_Payment_Tokens::get_customer_tokens( (int) $token->get_user_id(), $gateway ) as $other ) {
		if ( (int) $other->get_id() !== (int) $token->get_id() ) {
			return false;
		}
	}

	return true;
}

/**
 * The message shown wherever a delete is refused.
 *
 * @return string
 */
function subscription_last_card_message() {
	return __( 'لا يمكن حذف هذه البطاقة لأنها البطاقة الوحيدة التي يُجدَّد بها اشتراكك. أضف بطاقة أخرى أولاً، أو ألغِ الاشتراك.', 'tutor-sso' );
}

/**
 * Refuse to delete the last card an active subscription can use.
 *
 * Runs on `wp` at priority 10, ahead of WooCommerce's own handler at 20.
 * Anything this does not refuse — a card that is not protected, or a link
 * WooCommerce will reject itself (wrong owner, bad nonce) — is left for
 * WooCommerce to handle as usual.
 *
 * @return void
 */
function subscription_block_last_card_delete() {
	global $wp;

	if ( ! isset( $wp->query_vars['delete-payment-method'] ) || ! class_exists( '\WC_Payment_Tokens' ) ) {
		return;
	}

	$token = \WC_Payment_Tokens::get( absint( $wp->query_vars['delete-payment-method'] ) );

	if ( ! $token || get_current_user_id() !== (int) $token->get_user_id() || ! subscription_token_is_protected( $token ) ) {
		return;
	}

	wc_add_notice( subscription_last_card_message(), 'error' );
	wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
	exit;
}
add_action( 'wp', __NAMESPACE__ . '\\subscription_block_last_card_delete', 10 );

/**
 * Hide the Delete button on a protected card.
 *
 * @param array             $item  Row WooCommerce will render.
 * @param \WC_Payment_Token $token Saved card.
 * @return array
 */
function subscription_hide_protected_delete( $item, $token ) {
	if ( is_array( $item ) && isset( $item['actions']['delete'] ) && subscription_token_is_protected( $token ) ) {
		unset( $item['actions']['delete'] );
	}

	return $item;
}
add_filter( 'woocommerce_payment_methods_list_item', __NAMESPACE__ . '\\subscription_hide_protected_delete', 20, 2 );

/**
 * Explain the missing Delete button, above the list.
 *
 * Only when a card is actually protected, so the page says nothing in the
 * usual case.
 *
 * @param bool $has_methods Whether the customer has saved cards.
 * @return void
 */
function subscription_payment_methods_note( $has_methods ) {
	if ( ! $has_methods || ! is_user_logged_in() || ! class_exists( '\WC_Payment_Tokens' ) ) {
		return;
	}

	foreach ( \WC_Payment_Tokens::get_customer_tokens( get_current_user_id() ) as $token ) {
		if ( subscription_token_is_protected( $token ) ) {
			printf(
				'<p class="rwaq-payment-methods-note">%s</p>',
				esc_html__( 'بطاقتك الحالية مرتبطة باشتراكك الفعّال، ولا يمكن حذفها ما لم تُضف بطاقة أخرى. يُجدَّد اشتراكك بالبطاقة الافتراضية.', 'tutor-sso' )
			);

			return;
		}
	}
}
add_action( 'woocommerce_before_account_payment_methods', __NAMESPACE__ . '\\subscription_payment_methods_note' );

/**
 * Move subscriptions off a card that has just been deleted.
 *
 * Fires after the card is gone, so the lookup cannot pick it again. The
 * customer's default card wins, as it would at renewal; with no card left on
 * that gateway the subscription records none, which the admin list reports.
 *
 * @param int               $token_id Deleted card.
 * @param \WC_Payment_Token $token    The card as it was.
 * @return void
 */
function subscription_token_deleted( $token_id, $token ) {
	global $wpdb;

	$token_id = (int) $token_id;

	if ( ! $token_id || ! is_object( $token ) ) {
		return;
	}

	$table = subscriptions_table();

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare( "SELECT * FROM {$table} WHERE token_id = %d", $token_id ),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$replacement = subscription_renewal_token( array_merge( $row, array( 'token_id' => 0 ) ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array( 'token_id' => $replacement ),
			array( 'id' => (int) $row['id'] ),
			array( '%d' ),
			array( '%d' )
		);
	}
}
add_action( 'woocommerce_payment_token_deleted', __NAMESPACE__ . '\\subscription_token_deleted', 10, 2 );

/**
 * Move a customer's active subscriptions onto the card they made default.
 *
 * Renewal would pick it anyway; recording it now keeps the admin list honest
 * about which card will be charged.
 *
 * @param int               $token_id Card made default.
 * @param \WC_Payment_Token $token    The card.
 * @return void
 */
function subscription_token_set_default( $token_id, $token ) {
	global $wpdb;

	if ( ! is_object( $token ) || ! method_exists( $token, 'get_gateway_id' ) ) {
		return;
	}

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		subscriptions_table(),
		array( 'token_id' => (int) $token_id ),
		array(
			'user_id'    => (int) $token->get_user_id(),
			'gateway_id' => (string) $token->get_gateway_id(),
			'status'     => 'active',
		),
		array( '%d' ),
		array( '%d', '%s', '%s' )
	);
}
add_action( 'woocommerce_payment_token_set_default', __NAMESPACE__ . '\\subscription_token_set_default', 10, 2 );
