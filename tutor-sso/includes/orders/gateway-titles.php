<?php
/**
 * Arabic names for payment gateways whose title is still English.
 *
 * Moyasar's card gateway is titled "Card Payment", which shows as-is next to
 * its radio button at checkout and on My Account's add-payment-method page.
 * WooCommerce reads a gateway's title through `woocommerce_gateway_title`
 * wherever it shows it, so this swaps it there.
 *
 * Matched on the English title rather than the gateway ID, so a title an admin
 * has already changed in the gateway's settings is left alone. Front end and
 * AJAX only (checkout refreshes its payment box over AJAX); the gateway's
 * settings screen keeps the stored title, so it is clear what is saved.
 *
 * New orders store the title shown at checkout, so their order details and
 * emails get the Arabic name too. Orders placed before this keep "Card Payment".
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * English gateway titles and their Arabic replacements.
 *
 * @return string[]
 */
function gateway_title_translations() {
	/**
	 * Filter the gateway titles replaced with Arabic.
	 *
	 * @param string[] $titles Arabic titles keyed by the English title.
	 */
	return (array) apply_filters(
		'tutor_sso_gateway_title_translations',
		array(
			'Card Payment' => __( 'الدفع بالبطاقة', 'tutor-sso' ),
		)
	);
}

/**
 * Swap an English gateway title for its Arabic one.
 *
 * @param string $title      Gateway title.
 * @param string $gateway_id Gateway ID.
 * @return string
 */
function gateway_title_arabic( $title, $gateway_id = '' ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $title;
	}

	$titles = gateway_title_translations();
	$key    = trim( wp_strip_all_tags( (string) $title ) );

	return isset( $titles[ $key ] ) ? $titles[ $key ] : $title;
}
add_filter( 'woocommerce_gateway_title', __NAMESPACE__ . '\\gateway_title_arabic', 10, 2 );
