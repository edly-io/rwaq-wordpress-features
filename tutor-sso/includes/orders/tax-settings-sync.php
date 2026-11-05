<?php
/**
 * Push the WooCommerce tax settings to the LMS whenever they change.
 *
 *   POST {lms}/rwaq/api/pricing/tax-settings/
 *
 *   {
 *     "tax_enabled": true,
 *     "tax_label": "VAT",
 *     "tax_rate": "15.00",
 *     "prices_include_tax": false
 *   }
 *
 * The rate sent is the standard rate for the store's own country — the rate
 * checkout and renewals charge a customer billed there, which with VAT only is
 * everyone. Label and rate use the same format as `totals` in the order payload
 * (see orders-api.php).
 *
 * Sent when any of these change: "Enable tax rates" (General tab), "Prices
 * entered with tax" (Tax tab), the store's country, or a tax rate. Several can
 * change in one save, so the changes are collected and posted once, at the end
 * of the request.
 *
 * The endpoint is idempotent, so the last body the LMS accepted is remembered,
 * together with the LMS it went to, and an unchanged one is not sent again;
 * pointing the plugin at another LMS sends it there. When a post fails, the next admin
 * page load retries it (at most once an hour), so the LMS catches up without
 * anyone saving the settings twice. That same check sends the first copy after
 * this is deployed.
 *
 * Authenticated with the same service token as the orders API.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the hash of the last body the LMS accepted.
 */
const TAX_SYNC_SENT_OPTION = 'tutor_sso_tax_settings_synced';

/**
 * Option holding the last failure, shown on the WooCommerce settings page.
 */
const TAX_SYNC_ERROR_OPTION = 'tutor_sso_tax_settings_sync_error';

/**
 * Transient that spaces out retries after a failure.
 */
const TAX_SYNC_RETRY_TRANSIENT = 'tutor_sso_tax_settings_retry';

/**
 * Whether tax settings are posted to the LMS at all.
 *
 * @return bool
 */
function tax_sync_enabled() {
	/**
	 * Filter whether the tax settings are posted to the LMS.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'tutor_sso_tax_sync_enabled', true );
}

/**
 * The tax settings as the LMS stores them.
 *
 * `tax_enabled` is true only when a rate actually applies, so the LMS never
 * sees "enabled" without a rate. The label is always set, as the API requires
 * it: the rate's own name, or WooCommerce's default ("VAT" / "Tax") when there
 * is no rate.
 *
 * @return array|null Null when WooCommerce is not loaded.
 */
function tax_sync_payload() {
	if ( ! function_exists( 'wc_tax_enabled' ) || ! class_exists( '\WC_Tax' ) || ! function_exists( 'WC' ) ) {
		return null;
	}

	$label = '';
	$rate  = null;

	if ( wc_tax_enabled() ) {
		// Standard class ('') at the store's base address. Only VAT is charged,
		// so there is one match; were there more, the first is sent.
		foreach ( \WC_Tax::get_base_tax_rates( '' ) as $rate_id => $row ) {
			// The label an order's tax line gets, with WooCommerce's default for
			// an unnamed rate.
			$label = (string) \WC_Tax::get_rate_label( $rate_id );
			$rate  = number_format( (float) $row['rate'], 2, '.', '' );
			break;
		}
	}

	if ( '' === $label ) {
		$label = (string) WC()->countries->tax_or_vat();
	}

	$payload = array(
		'tax_enabled'        => null !== $rate,
		'tax_label'          => mb_substr( $label, 0, 50 ),
		'tax_rate'           => $rate,
		'prices_include_tax' => (bool) wc_prices_include_tax(),
	);

	/**
	 * Filter the tax settings posted to the LMS.
	 *
	 * @param array $payload Payload.
	 */
	return (array) apply_filters( 'tutor_sso_tax_settings_payload', $payload );
}

/**
 * Fingerprint of a body sent to the current LMS, to tell whether it has it.
 *
 * @param string $body JSON body.
 * @return string
 */
function tax_sync_hash( $body ) {
	return md5( orders_api_base() . '|' . $body );
}

/**
 * Post the current tax settings to the LMS, unless it already has them.
 *
 * @return true|\WP_Error
 */
function tax_sync_send() {
	if ( ! tax_sync_enabled() ) {
		return new \WP_Error( 'tutor_sso_tax_sync_disabled', __( 'Tax settings syncing is disabled.', 'tutor-sso' ) );
	}

	$payload = tax_sync_payload();

	if ( null === $payload ) {
		return new \WP_Error( 'tutor_sso_no_woocommerce', __( 'WooCommerce is not active.', 'tutor-sso' ) );
	}

	$body = wp_json_encode( $payload );
	$hash = tax_sync_hash( $body );

	if ( get_option( TAX_SYNC_SENT_OPTION ) === $hash ) {
		return true;
	}

	$base = orders_api_base();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$token = sso_lms_service_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$response = wp_remote_post(
		$base . '/rwaq/api/pricing/tax-settings/',
		array(
			'timeout'   => 10,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'JWT ' . $token,
			),
			'body'      => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	// 200 saved; 201 too, in case the LMS reports a first save as created.
	if ( 200 === $status || 201 === $status ) {
		update_option( TAX_SYNC_SENT_OPTION, $hash, false );
		return true;
	}

	// A stale cached token is the one failure worth clearing automatically.
	if ( 401 === $status ) {
		delete_transient( 'tutor_sso_service_token' );
	}

	return new \WP_Error(
		'tutor_sso_tax_sync_failed',
		sprintf(
			/* translators: 1: HTTP status code, 2: response body. */
			__( 'The LMS rejected the tax settings (HTTP %1$d): %2$s', 'tutor-sso' ),
			$status,
			wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) )
		)
	);
}

/**
 * Send, and record the outcome so a failure is visible and retried.
 */
function tax_sync_run() {
	try {
		$result = tax_sync_send();
	} catch ( \Throwable $e ) {
		$result = new \WP_Error( 'tutor_sso_tax_sync_crashed', $e->getMessage() );
	}

	if ( ! is_wp_error( $result ) ) {
		delete_option( TAX_SYNC_ERROR_OPTION );
		delete_transient( TAX_SYNC_RETRY_TRANSIENT );
		return;
	}

	// Switched off on purpose, or nothing to read: not a failure.
	if ( in_array( $result->get_error_code(), array( 'tutor_sso_tax_sync_disabled', 'tutor_sso_no_woocommerce' ), true ) ) {
		return;
	}

	error_log( sprintf( '[tutor-sso] tax settings sync failed: %s', $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	update_option( TAX_SYNC_ERROR_OPTION, $result->get_error_message(), false );
	set_transient( TAX_SYNC_RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
}

/**
 * Note a change to the tax settings; the post goes out once, at shutdown.
 */
function tax_sync_schedule() {
	static $scheduled = false;

	if ( $scheduled ) {
		return;
	}

	$scheduled = true;
	add_action( 'shutdown', __NAMESPACE__ . '\\tax_sync_run' );
}

foreach ( array( 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_default_country' ) as $tax_sync_option ) {
	add_action( 'add_option_' . $tax_sync_option, __NAMESPACE__ . '\\tax_sync_schedule' );
	add_action( 'update_option_' . $tax_sync_option, __NAMESPACE__ . '\\tax_sync_schedule' );
}
unset( $tax_sync_option );

add_action( 'woocommerce_tax_rate_added', __NAMESPACE__ . '\\tax_sync_schedule' );
add_action( 'woocommerce_tax_rate_updated', __NAMESPACE__ . '\\tax_sync_schedule' );
add_action( 'woocommerce_tax_rate_deleted', __NAMESPACE__ . '\\tax_sync_schedule' );

/**
 * Catch the LMS up when it is missing the current settings: the first copy
 * after deploying, or a post that failed. Spaced out after a failure, so an
 * LMS that is down does not slow every admin page.
 */
function tax_sync_catch_up() {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_woocommerce' ) || get_transient( TAX_SYNC_RETRY_TRANSIENT ) ) {
		return;
	}

	$payload = tax_sync_payload();

	if ( null === $payload || get_option( TAX_SYNC_SENT_OPTION ) === tax_sync_hash( wp_json_encode( $payload ) ) ) {
		return;
	}

	tax_sync_schedule();
}
add_action( 'admin_init', __NAMESPACE__ . '\\tax_sync_catch_up' );

/**
 * Show the last failure on the WooCommerce settings page.
 */
function tax_sync_admin_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$error = (string) get_option( TAX_SYNC_ERROR_OPTION );

	if ( '' === $error ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: error message. */
				__( 'The tax settings could not be sent to the LMS. It will be retried automatically. %s', 'tutor-sso' ),
				$error
			)
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\tax_sync_admin_notice' );
