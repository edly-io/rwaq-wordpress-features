<?php
/**
 * Make the saved-card choice at checkout behave.
 *
 * For a logged-in customer with a saved card, the card gateway prints
 * WooCommerce's saved-cards list — each card, then "use a new payment method" —
 * above its card form. WooCommerce ships a script for that list,
 * tokenization-form.js, which hides the card form while a saved card is
 * selected and drops the lone "new payment method" option when there are no
 * saved cards. A gateway turns it on by calling tokenization_script(); Moyasar
 * never does.
 *
 * Without it, the customer's default card is pre-selected while the empty card
 * form stays on screen. Moyasar charges whichever option is selected, so a
 * customer who types a different card into that form and pays is charged on
 * the saved card instead — with nothing to say so. Loading the script makes
 * what is charged match what is on screen.
 *
 * Done through the gateway's own tokenization_script(), not by enqueueing the
 * file by name, so WooCommerce's script handle, its version and its settings
 * stay exactly what WooCommerce would use.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load WooCommerce's saved-cards script on checkout.
 *
 * Only for a logged-in customer — saved cards belong to an account, and the
 * list is never printed otherwise. Also on the pay-for-order page, which shows
 * the same payment box; not on order received, which shows none.
 *
 * @return void
 */
function checkout_saved_cards_script() {
	if ( ! is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return;
	}

	// One call is enough: the script handles every saved-cards list on the
	// page. Any gateway that saves cards can load it.
	foreach ( (array) WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
		if ( is_object( $gateway ) && method_exists( $gateway, 'supports' ) && $gateway->supports( 'tokenization' )
			&& method_exists( $gateway, 'tokenization_script' ) ) {
			$gateway->tokenization_script();
			return;
		}
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\checkout_saved_cards_script', 20 );
