<?php
/**
 * Lock the checkout email to the customer's account email.
 *
 * The order's billing email is what tells the LMS who bought: it goes out as
 * the order's `order_by`, and program enrollment uses it too. WooCommerce fills
 * the field from the email saved at the customer's last checkout — empty on a
 * first purchase, stale after an email change on the LMS — and lets them type
 * any address, which would send the purchase to someone else's LMS account or
 * to none.
 *
 * The account email is the LMS email (it is refreshed from the LMS on every
 * login), so for a logged-in customer the field is filled with it and shown
 * read-only, as the account details page already does. Nothing downstream
 * changes: the order still carries its billing email, now always the right one.
 * Guests keep an ordinary, editable field.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The logged-in customer's account email, or '' for a guest.
 *
 * @return string
 */
function checkout_email_account_email() {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$user = wp_get_current_user();

	return $user instanceof \WP_User ? trim( (string) $user->user_email ) : '';
}

/**
 * Fill the checkout email with the account email.
 *
 * Runs ahead of WooCommerce's own lookup of the saved billing email.
 *
 * @param mixed  $value Value supplied by an earlier filter, or null.
 * @param string $input Field name.
 * @return mixed
 */
function checkout_email_value( $value, $input ) {
	if ( 'billing_email' !== $input ) {
		return $value;
	}

	$email = checkout_email_account_email();

	return '' !== $email ? $email : $value;
}
add_filter( 'woocommerce_checkout_get_value', __NAMESPACE__ . '\\checkout_email_value', 10, 2 );

/**
 * Show the checkout email as read-only.
 *
 * Checkout fields only — the billing address form in My Account is left as is.
 *
 * @param array $fields Checkout fields, grouped by section.
 * @return array
 */
function checkout_email_readonly( $fields ) {
	if ( '' === checkout_email_account_email() || ! isset( $fields['billing']['billing_email'] ) ) {
		return $fields;
	}

	$attributes = isset( $fields['billing']['billing_email']['custom_attributes'] )
		? (array) $fields['billing']['billing_email']['custom_attributes']
		: array();

	$attributes['readonly']      = 'readonly';
	$attributes['aria-readonly'] = 'true';

	$fields['billing']['billing_email']['custom_attributes'] = $attributes;

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', __NAMESPACE__ . '\\checkout_email_readonly', 20 );
