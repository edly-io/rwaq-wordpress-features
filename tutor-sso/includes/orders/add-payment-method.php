<?php
/**
 * The "Add payment method" page: only offer gateways that can actually add one.
 *
 * WooCommerce lists every gateway that declares `tokenization` here. Declaring
 * it says the gateway can *use* saved cards, not that it can save one from this
 * page — that takes an add_payment_method() of its own. A gateway without one
 * inherits WC_Payment_Gateway::add_payment_method(), which only returns
 * failure. Moyasar's Apple Pay is such a gateway: listed, selectable, and all it
 * shows is its description, because there is nothing it can do here.
 *
 * Decided by whether the gateway implements the method, not by name, so the
 * same holds for any other gateway.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a gateway implements adding a payment method itself.
 *
 * @param object $gateway Payment gateway.
 * @return bool
 */
function add_payment_method_gateway_can_add( $gateway ) {
	if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'add_payment_method' ) ) {
		return false;
	}

	try {
		$method = new \ReflectionMethod( $gateway, 'add_payment_method' );
	} catch ( \ReflectionException $e ) {
		return false;
	}

	// Inherited unchanged from WooCommerce's base class means "always fails".
	return 'WC_Payment_Gateway' !== $method->getDeclaringClass()->getName();
}

/**
 * Drop the gateways that cannot add a payment method, on that page only.
 *
 * @param array $gateways Available gateways, keyed by id.
 * @return array
 */
function add_payment_method_filter_gateways( $gateways ) {
	if ( ! is_array( $gateways ) || ! function_exists( 'is_add_payment_method_page' ) || ! is_add_payment_method_page() ) {
		return $gateways;
	}

	return array_filter( $gateways, __NAMESPACE__ . '\\add_payment_method_gateway_can_add' );
}
add_filter( 'woocommerce_available_payment_gateways', __NAMESPACE__ . '\\add_payment_method_filter_gateways', 20 );
