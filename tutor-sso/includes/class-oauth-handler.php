<?php
/**
 * OAuth 2.0 handler for Tutor LMS SSO.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the token exchange with the LMS and decodes the resulting JWT.
 */
class OAuth_Handler {

	/**
	 * POST to the LMS token endpoint and return the decoded response.
	 *
	 * FIXES vs original:
	 * - Returns a decoded array (not a raw JSON string), avoiding a redundant
	 *   json_decode() call in the caller.
	 * - Uses wp_remote_retrieve_body() instead of array access on $response.
	 * - Escapes all output passed to wp_die().
	 * - Makes SSL verification filterable so dev environments with self-signed
	 *   certificates can override it without touching plugin code.
	 * - Removes the misleading 'charset' pseudo-header.
	 *
	 * @param string $token_endpoint Token URL.
	 * @param string $grant_type     OAuth grant type (e.g. 'authorization_code').
	 * @param string $client_id      OAuth client ID.
	 * @param string $client_secret  OAuth client secret.
	 * @param string $code           Authorization code from the LMS.
	 * @param string $redirect_url   Registered redirect URI.
	 * @return array Decoded token response (contains id_token / access_token).
	 */
	public function get_token( $token_endpoint, $grant_type, $client_id, $client_secret, $code, $redirect_url ) {

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'        => array(
					'grant_type'    => $grant_type,
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_url,
					'token_type'    => 'jwt',
				),
				'cookies'     => array(),
				/**
				 * Filter: tutor_sso_ssl_verify
				 *
				 * Defaults to true (verify SSL certificates). Set to false only in
				 * local/dev environments that use self-signed certificates.
				 *
				 * add_filter( 'tutor_sso_ssl_verify', '__return_false' );
				 */
				'sslverify'   => apply_filters( 'tutor_sso_ssl_verify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die(
				esc_html( $response->get_error_message() ) .
				' ' . esc_html__( 'Please contact the site administrator.', 'tutor-sso' )
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$content = json_decode( $body, true );

		if ( ! is_array( $content ) ) {
			wp_die(
				esc_html__( 'Invalid response from the LMS token endpoint. Please contact the site administrator.', 'tutor-sso' )
			);
		}

		if ( isset( $content['error_description'] ) ) {
			wp_die(
				esc_html( $content['error_description'] ) .
				' ' . esc_html__( 'Please contact the site administrator.', 'tutor-sso' )
			);
		}

		if ( isset( $content['error'] ) ) {
			wp_die(
				esc_html( $content['error'] ) .
				' ' . esc_html__( 'Please contact the site administrator.', 'tutor-sso' )
			);
		}

		return $content;
	}

	/**
	 * Exchange an authorization code and return token data that contains at
	 * least an id_token or an access_token.
	 *
	 * @param string $token_endpoint Token URL.
	 * @param string $grant_type     OAuth grant type.
	 * @param string $client_id      OAuth client ID.
	 * @param string $client_secret  OAuth client secret.
	 * @param string $code           Authorization code from the LMS.
	 * @param string $redirect_url   Registered redirect URI.
	 * @return array Token data array.
	 */
	public function get_id_token( $token_endpoint, $grant_type, $client_id, $client_secret, $code, $redirect_url ) {

		$content = $this->get_token(
			$token_endpoint,
			$grant_type,
			$client_id,
			$client_secret,
			$code,
			$redirect_url
		);

		if ( ! isset( $content['id_token'] ) && ! isset( $content['access_token'] ) ) {
			wp_die(
				esc_html__( 'Invalid response: neither id_token nor access_token was present. Contact your administrator.', 'tutor-sso' )
			);
		}

		return $content;
	}

	/**
	 * Decode the payload segment of a JWT id_token and return it as an array.
	 *
	 * FIX vs original: The original used plain base64_decode() on the JWT
	 * payload without converting the URL-safe Base64url alphabet (- _) to
	 * standard Base64 (+ /). This caused silent data corruption for tokens
	 * whose payload contained those characters.
	 *
	 * @param string $id_token JWT string in header.payload.signature format.
	 * @return array Decoded claims from the token payload.
	 */
	public function get_user_data_from_id_token( $id_token ) {

		$parts = explode( '.', $id_token );

		if ( ! isset( $parts[1] ) ) {
			wp_die(
				esc_html__( 'Malformed id_token (expected three dot-separated segments). Please contact the site administrator.', 'tutor-sso' )
			);
		}

		// Base64url → Base64: swap - and _ back to + and /.
		// PHP's base64_decode() handles missing padding automatically.
		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data    = json_decode( $payload, true );

		if ( ! is_array( $data ) ) {
			wp_die(
				esc_html__( 'Could not parse the id_token payload. Please contact the site administrator.', 'tutor-sso' )
			);
		}

		return $data;
	}
}
