<?php
/**
 * Reject names that are not names on the address forms.
 *
 * WooCommerce only checks that a name field is filled in, so "12345" or "@@##"
 * goes through checkout and onto the order, the invoice and the customer's
 * saved address. A name here must contain at least one letter and nothing but
 * letters (any script, so Arabic and Latin alike, with Arabic diacritics),
 * spaces, hyphens and apostrophes — "عبد الله", "O'Brien" and "Al-Saud" pass;
 * digits or symbols anywhere do not.
 *
 * Checked server side, where it cannot be skipped, on the two forms a customer
 * types a name into: checkout, and My Account's edit-address page. The account
 * details form is not one of them — its names come from the LMS and are locked
 * (see my-account-form.php).
 *
 * The checkout form is `novalidate`, so a browser-side pattern would never
 * run; the error comes back from the server next to the field instead, the
 * way WooCommerce reports a missing required field.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a value is an acceptable name.
 *
 * An empty value passes: whether the field is required is WooCommerce's check.
 *
 * @param string $value Submitted value.
 * @return bool
 */
function name_field_is_valid( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return true;
	}

	// Letters and combining marks (Arabic harakat), spaces, hyphens and both
	// straight and typographic apostrophes — and at least one letter.
	return 1 === preg_match( "/^[\p{L}\p{M}\s'\x{2019}\-]+$/u", $value )
		&& 1 === preg_match( '/\p{L}/u', $value );
}

/**
 * Error message for an invalid name field, by field suffix.
 *
 * @param string $suffix `first_name` or `last_name`.
 * @return string
 */
function name_field_error_message( $suffix ) {
	return 'last_name' === $suffix
		? __( 'يرجى إدخال اسم عائلة صحيح باستخدام الحروف فقط.', 'tutor-sso' )
		: __( 'يرجى إدخال اسم أول صحيح باستخدام الحروف فقط.', 'tutor-sso' );
}

/**
 * The name fields in a set of submitted values, as field key => suffix.
 *
 * Covers billing and, should shipping ever be switched on, shipping.
 *
 * @param array $values Submitted values keyed by field.
 * @return array<string,string>
 */
function name_fields_in( $values ) {
	$fields = array();

	foreach ( array( 'billing', 'shipping' ) as $type ) {
		foreach ( array( 'first_name', 'last_name' ) as $suffix ) {
			$key = $type . '_' . $suffix;

			if ( array_key_exists( $key, $values ) ) {
				$fields[ $key ] = $suffix;
			}
		}
	}

	return $fields;
}

/**
 * Checkout: add an error for each invalid name.
 *
 * The `id` lets WooCommerce mark the field itself, as with its own errors.
 *
 * @param array     $data   Posted checkout data, already sanitized.
 * @param \WP_Error $errors Checkout errors.
 * @return void
 */
function name_validate_checkout( $data, $errors ) {
	if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
		return;
	}

	foreach ( name_fields_in( $data ) as $key => $suffix ) {
		if ( ! name_field_is_valid( $data[ $key ] ) ) {
			$errors->add( $key . '_invalid', name_field_error_message( $suffix ), array( 'id' => $key ) );
		}
	}
}
add_action( 'woocommerce_after_checkout_validation', __NAMESPACE__ . '\\name_validate_checkout', 10, 2 );

/**
 * My Account edit address: add an error notice for each invalid name.
 *
 * WooCommerce counts error notices straight after this action and does not
 * save the address if there are any.
 *
 * @param int    $user_id      Customer.
 * @param string $address_type `billing` or `shipping`.
 * @return void
 */
function name_validate_saved_address( $user_id, $address_type ) {
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}

	$values = array();

	foreach ( array( 'first_name', 'last_name' ) as $suffix ) {
		$key = $address_type . '_' . $suffix;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce before this action.
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$values[ $key ] = wc_clean( wp_unslash( $_POST[ $key ] ) );
		}
	}

	foreach ( name_fields_in( $values ) as $key => $suffix ) {
		if ( ! name_field_is_valid( $values[ $key ] ) ) {
			wc_add_notice( name_field_error_message( $suffix ), 'error', array( 'id' => $key ) );
		}
	}
}
add_action( 'woocommerce_after_save_address_validation', __NAMESPACE__ . '\\name_validate_saved_address', 10, 2 );
