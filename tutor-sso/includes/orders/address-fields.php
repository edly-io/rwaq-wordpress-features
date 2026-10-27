<?php
/**
 * Length limits on the address forms' free-text fields.
 *
 * WooCommerce puts no ceiling on any of them, so a pasted value can run past
 * the width of its box and push the form's layout out with it, and an
 * arbitrarily long name or address is stored on the order as sent.
 *
 * Two layers:
 *  - A maxlength on each input, through the field definitions, so it reaches
 *    every form WooCommerce builds from them — the checkout and My Account's
 *    edit address alike. Typing simply stops at the limit.
 *  - The same limits checked on the server when the checkout is placed or an
 *    address is saved, because a maxlength is only the browser's promise: a
 *    hand-made request ignores it.
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
 * every format in use, the longest being around 10. 50 for a name, city or
 * area and 100 for a company or street address are well past real values
 * while keeping a pasted paragraph out.
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
			'first_name' => 50,
			'last_name'  => 50,
			'company'    => 100,
			'address_1'  => 100,
			'city'       => 50,
			'state'      => 50,
			'postcode'   => 12,
			'phone'      => 20,
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
 * The limit for a form field, by the same suffix match as the maxlength.
 *
 * @param string $key Field key, e.g. `billing_first_name`.
 * @return int Maximum characters, or 0 when the field has none.
 */
function address_field_limit( $key ) {
	$key = (string) $key;

	foreach ( address_field_maxlengths() as $suffix => $max ) {
		$suffix = (string) $suffix;

		if ( $key === $suffix || substr( $key, - ( strlen( $suffix ) + 1 ) ) === '_' . $suffix ) {
			return (int) $max;
		}
	}

	return 0;
}

/**
 * The fields among $values that are over their limit.
 *
 * Counted in characters, not bytes, so an Arabic name gets the same allowance
 * as a Latin one.
 *
 * @param array $values Field key => submitted value.
 * @param array $fields Field key => definition, for the labels.
 * @return string[] One error message per field over its limit.
 */
function address_length_errors( $values, $fields ) {
	$errors = array();

	foreach ( (array) $values as $key => $value ) {
		$max = address_field_limit( $key );

		if ( $max <= 0 || ! is_scalar( $value ) ) {
			continue;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );

		if ( $length <= $max ) {
			continue;
		}

		$label = isset( $fields[ $key ]['label'] ) && '' !== trim( wp_strip_all_tags( (string) $fields[ $key ]['label'] ) )
			? trim( wp_strip_all_tags( (string) $fields[ $key ]['label'] ) )
			: (string) $key;

		$errors[ $key ] = sprintf(
			/* translators: 1: field label, 2: maximum number of characters. */
			__( '%1$s: الحد الأقصى %2$s حرف.', 'tutor-sso' ),
			$label,
			number_format_i18n( $max )
		);
	}

	return $errors;
}

/**
 * Refuse a checkout with a field over its limit.
 *
 * @param array     $data   Posted checkout data.
 * @param \WP_Error $errors Checkout errors.
 * @return void
 */
function address_validate_checkout_lengths( $data, $errors ) {
	if ( ! $errors instanceof \WP_Error || ! function_exists( 'WC' ) || ! WC()->checkout() ) {
		return;
	}

	$fields = array();

	foreach ( (array) WC()->checkout()->get_checkout_fields() as $group ) {
		$fields += (array) $group;
	}

	foreach ( address_length_errors( $data, $fields ) as $key => $message ) {
		$errors->add( $key . '_maxlength', $message );
	}
}
add_action( 'woocommerce_after_checkout_validation', __NAMESPACE__ . '\\address_validate_checkout_lengths', 10, 2 );

/**
 * Refuse to save a My Account address with a field over its limit.
 *
 * An error notice here stops WooCommerce saving the address.
 *
 * @param int    $user_id      Customer.
 * @param string $address_type 'billing' or 'shipping'.
 * @param array  $address      The address's field definitions.
 * @return void
 */
function address_validate_saved_lengths( $user_id, $address_type, $address ) {
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}

	$values = array();

	foreach ( array_keys( (array) $address ) as $key ) {
		// WooCommerce has already checked the nonce before this runs.
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$values[ $key ] = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	foreach ( address_length_errors( $values, (array) $address ) as $message ) {
		wc_add_notice( $message, 'error' );
	}
}
add_action( 'woocommerce_after_save_address_validation', __NAMESPACE__ . '\\address_validate_saved_lengths', 10, 3 );

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
