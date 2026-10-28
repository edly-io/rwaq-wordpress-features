<?php
/**
 * Force the card to be saved when a subscription is being bought.
 *
 * A subscription that cannot be renewed is not a subscription, so the card has
 * to be kept — and that cannot be left to a checkbox the buyer may not tick.
 *
 * Gateways decide whether to keep a card from WooCommerce core's
 * `wc-{gateway_id}-new-payment-method` field. Moyasar reads it in its checkout
 * script and in process_payment, ORing it with its own guess at whether the
 * order is a subscription. Relying on that guess is what left subscriptions
 * with no card on file: when it does not fire, an unticked box means the
 * gateway never asks for a token, and the renewal has nothing to charge.
 *
 * So the field is set here instead, on both sides:
 *
 *   - in the POST, which is what the gateway actually reads, and which a buyer
 *     cannot get around; and
 *   - on the checkbox itself, which is then hidden. Gateways that build their
 *     request in JavaScript read the checkbox rather than the POST, so it has to
 *     be ticked; and a ticked box that is visible is an invitation to untick it,
 *     for a choice that is not on offer. A line of text says what is happening
 *     instead, which is the honest version of a control that cannot be used.
 *
 * Keyed on the core field name rather than anything Moyasar-specific, so a
 * different tokenising gateway is handled the same way.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core "save this card" field for a gateway.
 *
 * @param string $gateway_id Gateway id.
 * @return string
 */
function subscription_save_card_field( $gateway_id ) {
	return 'wc-' . $gateway_id . '-new-payment-method';
}

/**
 * Gateways that can keep a card.
 *
 * @return string[] Gateway ids.
 */
function subscription_tokenising_gateways() {
	if ( ! function_exists( 'WC' ) ) {
		return array();
	}

	$gateways = WC()->payment_gateways();

	if ( ! $gateways || ! method_exists( $gateways, 'payment_gateways' ) ) {
		return array();
	}

	$ids = array();

	foreach ( $gateways->payment_gateways() as $id => $gateway ) {
		if ( method_exists( $gateway, 'supports' ) && $gateway->supports( 'tokenization' ) ) {
			$ids[] = (string) $id;
		}
	}

	return $ids;
}

/**
 * Set the save-card field before the gateway reads it.
 *
 * Runs on `woocommerce_checkout_process`, which fires before the order is
 * created and before process_payment — so by the time any gateway looks at
 * $_POST, the field says what it needs to say.
 *
 * Set for every tokenising gateway rather than only the chosen one: it costs
 * nothing, and a gateway reading its own field is never confused by another's.
 *
 * @return void
 */
function subscription_force_save_card() {
	if ( ! subscription_cart_has_subscription() ) {
		return;
	}

	foreach ( subscription_tokenising_gateways() as $gateway_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST[ subscription_save_card_field( $gateway_id ) ] = 'true';
	}

	// Moyasar's own field, read instead of the core one on one of its paths.
	// Harmless anywhere else: a gateway that does not know this name ignores it.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST['moyasar_save_card'] = 'true';
}
add_action( 'woocommerce_checkout_process', __NAMESPACE__ . '\\subscription_force_save_card', 1 );

/**
 * Tick the checkbox, hide it, and say what is happening instead.
 *
 * Hidden rather than disabled: a disabled checkbox is not submitted at all, so
 * for a gateway that posts the field natively, disabling it would mean exactly
 * the thing this file exists to prevent. Hidden, it still reads as checked both
 * to the gateway's own script and to the browser.
 *
 * @return void
 */
function subscription_save_card_notice() {
	if ( ! subscription_cart_has_subscription() ) {
		return;
	}

	$fields = array();

	foreach ( subscription_tokenising_gateways() as $gateway_id ) {
		$fields[] = subscription_save_card_field( $gateway_id );
	}

	if ( empty( $fields ) ) {
		return;
	}

	// Hide it in CSS as well as in script, so it is never briefly visible while
	// the page is still loading. :has() reaches the wrapper, which is what
	// carries the border and padding; the plain selectors are the fallback where
	// it is not supported, and leave an empty wrapper rather than a usable
	// control.
	$css = array();

	foreach ( $fields as $field ) {
		// A CSS identifier, not HTML: escaping it as HTML would turn the quotes
		// in an attribute selector into entities and break the rule. Gateway ids
		// are plain enough to use directly, so anything outside that is dropped
		// rather than encoded, and the selector needs no quoting.
		$field = preg_replace( '/[^A-Za-z0-9_-]/', '', $field );

		if ( '' === $field ) {
			continue;
		}

		$css[] = sprintf( 'p:has(> #%1$s), .form-row:has(> #%1$s)', $field );
		$css[] = sprintf( '#%1$s, label[for=%1$s]', $field );
	}

	if ( ! empty( $css ) ) {
		printf(
			'<style>%s{display:none !important}</style>',
			implode( ',', $css ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reduced to [A-Za-z0-9_-] above.
		);
	}

	?>
	<script>
	( function ( $ ) {
		'use strict';

		var fields = <?php echo wp_json_encode( $fields ); ?>;
		var notice = <?php echo wp_json_encode( __( 'سيتم حفظ بطاقتك لتجديد الاشتراك تلقائياً.', 'tutor-sso' ) ); ?>;

		function hold() {
			$.each( fields, function ( i, name ) {
				var $box = $( '#' + name );

				if ( ! $box.length ) {
					return;
				}

				// Checked, never disabled: a disabled field is not submitted.
				$box.prop( 'checked', true );

				var $row = $box.closest( 'p, .form-row' );

				if ( ! $row.length ) {
					$row = $box.closest( 'label' );
				}

				if ( ! $row.length ) {
					$row = $box;
				}

				$row.hide();

				// After the hidden row, not inside it, or it would be hidden too.
				if ( ! $row.next( '.tutor-sso-save-card-note' ).length ) {
					$row.after( '<p class="tutor-sso-save-card-note" style="opacity:.75;font-size:.9em;margin:.5em 0">' + notice + '</p>' );
				}
			} );
		}

		// Re-run after every checkout refresh: WooCommerce replaces the payment
		// box wholesale when the totals or the chosen gateway change, which
		// would otherwise put a visible, unticked checkbox back.
		$( document.body ).on( 'updated_checkout payment_method_selected', hold );
		$( hold );

		// Belt and braces, for a gateway script that resets its own field.
		$( document.body ).on( 'change', fields.map( function ( n ) { return '#' + n; } ).join( ',' ), function () {
			$( this ).prop( 'checked', true );
		} );
	}( jQuery ) );
	</script>
	<?php
}
add_action( 'woocommerce_after_checkout_form', __NAMESPACE__ . '\\subscription_save_card_notice', 20 );
