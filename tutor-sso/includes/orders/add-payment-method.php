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

/**
 * Point a page-less "payment-methods" link at My Account.
 *
 * wc_get_endpoint_url() builds on the current page when given none, and an
 * AJAX request has no page, so the link comes out as /payment-methods/.
 * Moyasar builds its "card saved" redirect that way after the 3DS check, and
 * its own fallback only catches a URL containing admin-ajax.php, so the
 * customer landed on a page that does not exist.
 *
 * Only when there is neither a given page nor a current one: every link built
 * on a page passes through untouched.
 *
 * @param string      $url       Endpoint URL as built.
 * @param string      $endpoint  Endpoint slug, as configured in WooCommerce.
 * @param string      $value     Endpoint value.
 * @param string|bool $permalink Page the URL was built on; false for none.
 * @return string
 */
function add_payment_method_redirect_url( $url, $endpoint, $value, $permalink ) {
	if ( ! empty( $permalink ) || ! function_exists( 'WC' ) || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return $url;
	}

	// The slug, not the key: WooCommerce maps one to the other before this
	// filter runs, and the slug can be renamed in its settings.
	$query_vars = WC()->query->get_query_vars();
	$slug       = ! empty( $query_vars['payment-methods'] ) ? $query_vars['payment-methods'] : 'payment-methods';

	if ( $slug !== $endpoint ) {
		return $url;
	}

	// Built on the My Account page, so this filter lets it through.
	return wc_get_account_endpoint_url( 'payment-methods' );
}
add_filter( 'woocommerce_get_endpoint_url', __NAMESPACE__ . '\\add_payment_method_redirect_url', 10, 4 );
