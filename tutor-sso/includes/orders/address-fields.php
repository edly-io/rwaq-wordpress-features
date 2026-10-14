<?php
/**
 * Length limits on the address forms' free-text fields.
 *
 * Phone and postcode have no natural ceiling in WooCommerce, so a pasted value
 * can run past the width of its box and push the form's layout out with it.
 * A maxlength stops that at the source rather than leaving the stylesheet to
 * cope with arbitrarily long input.
 *
 * Applied through the field definitions so it reaches every form WooCommerce
 * builds from them — My Account's add/edit address and the checkout alike.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field suffix => maximum characters.
 *
 * Matched on the suffix because the same field is keyed `billing_phone` on one
 * form and `shipping_postcode` on another.
 *
 * 20 for a phone covers the longest international format with separators
 * (E.164 is 15 digits, plus "+", spaces or dashes). 12 for a postcode covers
 * every format in use, the longest being around 10.
 *
 * @return array<string,int>
 */
function address_field_maxlengths() {
	/**
	 * Filter the address field length limits.
	 *
	 * @param array<string,int> $limits Field suffix => maximum characters.
	 */
	return (array) apply_filters(
		'tutor_sso_address_field_maxlengths',
		array(
			'phone'    => 20,
			'postcode' => 12,
		)
	);
}

/**
 * Add a maxlength attribute to the fields above.
 *
 * An existing maxlength is left alone: whoever set it meant something by it.
 *
 * @param array $fields WooCommerce address field definitions.
 * @return array
 */
function address_apply_maxlengths( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$limits = address_field_maxlengths();

	foreach ( $fields as $key => $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}

		foreach ( $limits as $suffix => $max ) {
			$suffix = (string) $suffix;

			// `postcode` on the bare definitions, `billing_postcode` once the
			// form has prefixed them.
			if ( $key !== $suffix && substr( $key, - ( strlen( $suffix ) + 1 ) ) !== '_' . $suffix ) {
				continue;
			}

			$attributes = ( isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) )
				? $field['custom_attributes']
				: array();

			if ( ! isset( $attributes['maxlength'] ) ) {
				$attributes['maxlength'] = (int) $max;
			}

			$fields[ $key ]['custom_attributes'] = $attributes;

			break;
		}
	}

	return $fields;
}
add_filter( 'woocommerce_billing_fields', __NAMESPACE__ . '\\address_apply_maxlengths' );
add_filter( 'woocommerce_shipping_fields', __NAMESPACE__ . '\\address_apply_maxlengths' );
add_filter( 'woocommerce_default_address_fields', __NAMESPACE__ . '\\address_apply_maxlengths' );

/**
 * Show only the billing address in the account area.
 *
 * Nothing sold here is shipped — every synced product is created virtual (see
 * courses-product-sync.php / programs-product-sync.php) — so a shipping
 * address is a form the customer can only fill in for nothing.
 *
 * WooCommerce drops it by itself once shipping is disabled in its settings;
 * this keeps the account page right regardless of that setting, and leaves the
 * setting free to stay on for anything else that depends on it.
 *
 * @param array $addresses Address type => heading.
 * @return array
 */
function address_billing_only( $addresses ) {
	if ( ! is_array( $addresses ) ) {
		return $addresses;
	}

	unset( $addresses['shipping'] );

	return $addresses;
}
add_filter( 'woocommerce_my_account_get_addresses', __NAMESPACE__ . '\\address_billing_only' );
