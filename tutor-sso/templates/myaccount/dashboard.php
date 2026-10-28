<?php
/**
 * My Account dashboard, in place of WooCommerce's myaccount/dashboard.php.
 *
 * Served by includes/orders/my-account-dashboard.php. Keeps WooCommerce's
 * dashboard hooks so anything attached to them still prints.
 *
 * @package tutor-sso
 *
 * @var \WP_User $current_user Passed in by WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\TutorSSO\my_account_dashboard_render( isset( $current_user ) ? $current_user : null );

/**
 * My Account dashboard.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_account_dashboard' );

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_before_my_account' );

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_after_my_account' );
