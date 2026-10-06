<?php
/**
 * SSO hook callbacks and helper functions.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Internal option helper ────────────────────────────────────────────────────

/**
 * Retrieve a plugin option by its short key.
 * All plugin options are stored under the 'tutor_sso_' prefix.
 *
 * @param string $key     Option key without the 'tutor_sso_' prefix.
 * @param mixed  $default Default value when the option is empty.
 * @return mixed
 */
function sso_option( $key, $default = '' ) {
	return get_option( 'tutor_sso_' . $key, $default );
}

// ── Catalog pricing filter ─────────────────────────────────────────────────────

/**
 * Arabic label for a catalog "pricing" filter value.
 *
 * The filters API's own `label` is inconsistent across the two endpoints and
 * languages (courses: "مجّانًا" / "Paid" / "Program-only course"; programs:
 * "Free" / "Paid") — these are used instead so both catalogs read the same way,
 * falling back to the API's own label for any value this doesn't recognize.
 *
 * @param string $value    Filter value ('free' | 'paid' | 'program_only').
 * @param string $fallback Label to use when the value isn't recognized.
 * @return string
 */
function sso_pricing_label( $value, $fallback = '' ) {
	$labels = array(
		'free'         => __( 'مجاني', 'tutor-sso' ),
		'paid'         => __( 'مدفوع', 'tutor-sso' ),
		'program_only' => __( 'جزء من برنامج', 'tutor-sso' ),
	);

	$value = (string) $value;

	return isset( $labels[ $value ] ) ? $labels[ $value ] : (string) $fallback;
}

// ── Price parsing ─────────────────────────────────────────────────────────────

/**
 * Turn an LMS price string into a float for comparison and display.
 *
 * A bare `(float)` cast reads "1,299.00" as 1.0, because PHP stops at the
 * comma — the page would then show 1 while checkout charged 1299.
 * course_product_price() already resolves which separator is the decimal one,
 * so the parsing used for the WooCommerce product is reused for the display.
 *
 * It ends in wc_format_decimal(), so it is only reachable with WooCommerce
 * loaded; the raw cast stays the fallback, since the course detail panel
 * renders without WooCommerce (its wc_price() call has its own fallback).
 *
 * For the plain two-decimal strings the LMS sends today ("100.00") the two
 * paths agree exactly, so this changes nothing about current prices.
 *
 * @param mixed $value Price as the LMS sent it.
 * @return float Parsed amount, or 0.0 when there is nothing usable.
 */
function sso_price_to_float( $value ) {
	if ( function_exists( 'wc_format_decimal' ) && function_exists( __NAMESPACE__ . '\\course_product_price' ) ) {
		$parsed = course_product_price( $value );

		// '' means unparseable, zero or negative — all of which the callers
		// already treat as "no price", same as the cast's 0.0.
		return '' === $parsed ? 0.0 : (float) $parsed;
	}

	return (float) $value;
}

// ── Catalog card price badge ──────────────────────────────────────────────────

/**
 * Render a catalog card's price line: "مجاني" for free, otherwise the
 * WooCommerce-formatted price — the sale price struck through alongside the
 * regular price when there's a genuine discount (sale set and actually lower),
 * otherwise just the one price.
 *
 * Shared by the courses and programs catalogs (courses_render_card_price(),
 * programs_render_card_price()) — same rule, same markup shape, each call site
 * passing its own BEM base class so the two catalogs keep separate, independent
 * stylesheets.
 *
 * @param string $base_class e.g. 'rwaq-course-card__price'.
 * @param bool   $paid       Whether the LMS sells this item.
 * @param mixed  $regular    Regular price, numeric string or float.
 * @param mixed  $sale       Sale price, numeric string or float ('' when none).
 * @return string HTML, or '' when there's no usable price to show.
 */
function sso_render_price_html( $base_class, $paid, $regular, $sale ) {
	$base_class = (string) $base_class;

	if ( ! $paid ) {
		return '<p class="' . esc_attr( $base_class ) . ' ' . esc_attr( $base_class ) . '--free">' . esc_html__( 'مجاني', 'tutor-sso' ) . '</p>';
	}

	if ( ! function_exists( 'wc_price' ) ) {
		return '';
	}

	$regular = sso_price_to_float( $regular );
	$sale    = sso_price_to_float( $sale );

	// A discount only reads as one when the sale price is both set and
	// actually lower — otherwise it's just "the price", not "was/now".
	if ( $sale > 0 && $sale < $regular ) {
		return '<p class="' . esc_attr( $base_class ) . '">'
			. '<span class="' . esc_attr( $base_class ) . '-now">' . wc_price( $sale ) . '</span>'
			. '<del class="' . esc_attr( $base_class ) . '-was">' . wc_price( $regular ) . '</del>'
			. '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	$amount = $sale > 0 ? $sale : $regular;

	if ( $amount <= 0 ) {
		return '';
	}

	return '<p class="' . esc_attr( $base_class ) . '"><span class="' . esc_attr( $base_class ) . '-now">' . wc_price( $amount ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render a catalog card's discount-percentage badge (Figma 10400:11124's
 * corner tag): "خصم N%", shown over the card's thumbnail image.
 *
 * The LMS sends this as a float (e.g. "50.00"), so it's rounded here — the
 * design shows a whole number, not decimal points.
 *
 * @param string     $base_class e.g. 'rwaq-course-card__discount'.
 * @param float|string|null $discount Discount percentage from the API.
 * @return string HTML, or '' when there's no discount to show.
 */
function sso_render_discount_badge( $base_class, $discount ) {
	$discount = is_numeric( $discount ) ? (int) round( (float) $discount ) : 0;

	if ( $discount <= 0 ) {
		return '';
	}

	return '<span class="' . esc_attr( (string) $base_class ) . '">'
		. esc_html(
			sprintf(
				/* translators: %s: discount percentage, e.g. "50". */
				__( 'خصم %s%%', 'tutor-sso' ),
				number_format_i18n( $discount )
			)
		)
		. '</span>';
}

// ── Catalog cache helpers ─────────────────────────────────────────────────────

/**
 * Build a transient key that expires on a shared TTL boundary.
 *
 * A catalog page renders from two separately cached responses: the filters
 * response (organization counts) and the list response. On independent rolling
 * windows the two can be up to a full TTL apart in freshness, which surfaces as
 * "a new course is in the sidebar count but not in the grid". Folding a bucket
 * number — `floor( time() / ttl )`, identical for every caller inside the same
 * window — into both keys makes them refresh together, so the counts and the
 * listing always come from the same snapshot.
 *
 * @param string $prefix Key prefix identifying the resource.
 * @param string $url    Request URL the response belongs to.
 * @param int    $ttl    Cache lifetime in seconds.
 * @return string Transient key.
 */
function sso_cache_key( $prefix, $url, $ttl ) {
	$ttl = max( 1, (int) $ttl );

	return $prefix . md5( (string) $url ) . '_' . (int) floor( time() / $ttl );
}

// ── Internal (hidden) organizations ───────────────────────────────────────────

/**
 * Name prefixes marking an LMS organization as internal. Its content is kept out
 * of the public catalogs (courses + programs) and it never appears in a filter
 * list.
 *
 * Matched case-insensitively against the organization's short name and name, so
 * "test", "Test", "Testing Org" and "test-2" are all hidden, while "Contest Ltd"
 * is not. Filterable — return an empty array to show every organization.
 *
 * @return string[]
 */
function sso_hidden_org_prefixes() {
	return (array) apply_filters( 'tutor_sso_hidden_org_prefixes', array( 'test' ) );
}

/**
 * Whether an organization object from a catalog filters endpoint is internal.
 *
 * Both the courses and programs filters endpoints expose `short_name` / `name`,
 * so one check serves both.
 *
 * @param array $org Organization object from a filters endpoint.
 * @return bool
 */
function sso_is_hidden_org( $org ) {
	if ( ! is_array( $org ) ) {
		return false;
	}

	$prefixes = array_filter( array_map( 'strtolower', array_map( 'trim', array_map( 'strval', sso_hidden_org_prefixes() ) ) ) );

	if ( empty( $prefixes ) ) {
		return false;
	}

	foreach ( array( 'short_name', 'name' ) as $field ) {
		$value = isset( $org[ $field ] ) ? strtolower( trim( (string) $org[ $field ] ) ) : '';

		if ( '' === $value ) {
			continue;
		}

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * A service JWT for the LMS, from the credentials Open edX Commerce stores.
 *
 * Reuses its `openedx-client-id` / `openedx-client-secret` / `openedx-domain`
 * options and the same client_credentials grant it uses, so there is one set of
 * credentials to configure. Cached in our own transient rather than its
 * `openedx-jwt-token`, so nothing here can disturb its token lifecycle.
 *
 * Asks for a JWT, which both the orders API and the program bulk-enroll endpoint
 * accept as `Authorization: JWT`. The scheme has to match the token: a JWT sent
 * as Bearer is rejected, and so is the reverse.
 *
 * @return string|\WP_Error Token, or WP_Error when unavailable.
 */
function sso_lms_service_token() {
	$key = 'tutor_sso_service_token';

	$cached = get_transient( $key );

	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$client_id     = (string) get_option( 'openedx-client-id' );
	$client_secret = (string) get_option( 'openedx-client-secret' );
	$domain        = rtrim( (string) get_option( 'openedx-domain' ), '/' );

	if ( '' === $client_id || '' === $client_secret || '' === $domain ) {
		return new \WP_Error(
			'tutor_sso_no_credentials',
			__( 'Open edX API credentials are not configured.', 'tutor-sso' )
		);
	}

	$response = wp_remote_post(
		$domain . '/oauth2/access_token',
		array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'body'      => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'client_credentials',
				'token_type'    => 'jwt',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['access_token'] ) ) {
		return new \WP_Error(
			'tutor_sso_token_failed',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Could not obtain an LMS access token (HTTP %d).', 'tutor-sso' ), (int) wp_remote_retrieve_response_code( $response ) )
		);
	}

	// Expire a minute early so a token is never used on its last breath.
	$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 300;

	set_transient( $key, (string) $body['access_token'], $ttl );

	return (string) $body['access_token'];
}

/**
 * Path segment the instructor detail pages live under, i.e. /{base}/{slug}/.
 *
 * @return string
 */
function sso_instructor_base() {
	return (string) apply_filters( 'tutor_sso_instructor_detail_base', 'instructor' );
}

/**
 * Build an instructor detail URL from the LMS slug.
 *
 * @param string $slug Instructor slug from the API.
 * @return string URL, or '' when no slug is available.
 */
function sso_instructor_url( $slug ) {
	$slug = trim( (string) $slug );

	if ( '' === $slug ) {
		return '';
	}

	// Lowercase to match post_name; mb-aware because slugs are often Arabic.
	$slug = function_exists( 'mb_strtolower' ) ? mb_strtolower( $slug, 'UTF-8' ) : $slug;

	$base = trim( sso_instructor_base(), '/' );
	$path = '/' . ( '' !== $base ? $base . '/' : '' ) . rawurlencode( $slug ) . '/';

	return (string) apply_filters( 'tutor_sso_instructor_url', home_url( $path ), $slug );
}

/**
 * Every string a catalog's `org` filter might match for an organization.
 * short_name first, so it stays the canonical value.
 *
 * @param array $org Organization object from a filters endpoint.
 * @return string[] Unique, non-empty values.
 */
function sso_org_filter_aliases( $org ) {
	$aliases = array();

	if ( ! is_array( $org ) ) {
		return $aliases;
	}

	foreach ( array( 'short_name', 'name' ) as $key ) {
		$value = isset( $org[ $key ] ) ? trim( (string) $org[ $key ] ) : '';

		if ( '' !== $value && ! in_array( $value, $aliases, true ) ) {
			$aliases[] = $value;
		}
	}

	return $aliases;
}

/**
 * Group the `org` filter values of the visible organizations, one group each.
 * Aliases a hidden organization also answers to are dropped.
 *
 * @param array[] $visible Visible organization objects.
 * @param array[] $all     Every organization object from the filters endpoint.
 * @return array<int,string[]> Non-empty groups.
 */
function sso_org_alias_groups( $visible, $all ) {
	// Aliases a hidden organization answers to must not appear in any group.
	$blocked = array();

	foreach ( (array) $all as $org ) {
		if ( is_array( $org ) && sso_is_hidden_org( $org ) ) {
			foreach ( sso_org_filter_aliases( $org ) as $alias ) {
				$blocked[ strtolower( $alias ) ] = true;
			}
		}
	}

	$groups = array();

	foreach ( (array) $visible as $org ) {
		$group = array();

		foreach ( sso_org_filter_aliases( $org ) as $alias ) {
			if ( ! isset( $blocked[ strtolower( $alias ) ] ) ) {
				$group[] = $alias;
			}
		}

		if ( ! empty( $group ) ) {
			$groups[] = $group;
		}
	}

	return $groups;
}

/**
 * Inverse of sso_is_hidden_org(), for use as an array_filter() callback.
 *
 * @param array $org Organization object from a filters endpoint.
 * @return bool
 */
function sso_is_not_hidden_org( $org ) {
	return ! sso_is_hidden_org( $org );
}

// ── 1. OAuth 2.0 callback handler ────────────────────────────────────────────

/**
 * Handle the authorization-code callback from the LMS.
 *
 * Runs on every 'init', but returns immediately when the 'code' query
 * parameter is absent so normal page loads are unaffected.
 *
 * FIXES vs original:
 * - Removed unused `use Edly\Utils` import.
 * - Sanitize and unslash $_GET values before use.
 * - Added CSRF protection via a state parameter / transient (OAuth best
 *   practice; the original had no state check at all).
 * - Added null-check for required user fields (preferred_username, email)
 *   before touching the database.
 * - Handle WP_Error returned by create_or_update_user().
 * - Post-login redirect uses wp_safe_redirect() with the configured host
 *   whitelisted, replacing a plain wp_safe_redirect() that could silently
 *   fall back to the home URL for external destinations.
 */
function validate_sso_login() {

	// ── LMS-side OAuth error (no code, but error param present) ─────────────
	if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$error             = sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$error_description = isset( $_GET['error_description'] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: '';
		error_log( sprintf( '[tutor-sso] LMS OAuth error: %s — %s', $error, $error_description ) );
		wp_safe_redirect( get_site_url() );
		exit;
	}

	if ( empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	// ── CSRF: verify the state parameter ─────────────────────────────────────
	// The state was generated by get_lms_authorize_url() and stored as a transient.
	$state = isset( $_GET['state'] ) // phpcs:ignore WordPress.Security.NonceVerification
		? sanitize_text_field( wp_unslash( $_GET['state'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
		: '';

	if ( empty( $state ) || ! get_transient( 'tutor_sso_state_' . $state ) ) {
		error_log( '[tutor-sso] State validation failed — state param missing or transient not found.' );
		wp_safe_redirect( get_site_url() );
		exit;
	}

	// One-time use: delete immediately after the check.
	delete_transient( 'tutor_sso_state_' . $state );

	// ── Exchange the code for tokens ──────────────────────────────────────────
	$code    = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$handler = new OAuth_Handler();

	$token_data = $handler->get_id_token(
		sso_option( 'access_token_url' ),
		'authorization_code',
		sso_option( 'client_id' ),
		sso_option( 'client_secret' ),
		$code,
		sso_option( 'redirect_url' )
	);

	$id_token = $token_data['id_token'] ?? $token_data['access_token'] ?? '';

	if ( empty( $id_token ) ) {
		wp_die( esc_html__( 'Invalid token received. Please contact the site administrator.', 'tutor-sso' ) );
	}

	// ── Decode the JWT payload and validate required fields ───────────────────
	$user_data = $handler->get_user_data_from_id_token( $id_token );

	if ( empty( $user_data['preferred_username'] ) || empty( $user_data['email'] ) ) {
		wp_die(
			esc_html__( 'Required fields (preferred_username, email) are missing from the token payload. Please contact the site administrator.', 'tutor-sso' )
		);
	}

	// ── Create / update the WordPress user ───────────────────────────────────
	$blog_id  = get_current_blog_id();
	$username = $user_data['preferred_username'];
	$email    = sanitize_email( $user_data['email'] );

	$user_id = create_or_update_user( $username, $email, 'subscriber', $blog_id, sso_profile_from_claims( $user_data ) );

	if ( is_wp_error( $user_id ) ) {
		wp_die(
			esc_html__( 'Could not create or retrieve your WordPress account. Please contact the site administrator.', 'tutor-sso' )
		);
	}

	$user = get_user_by( 'id', $user_id );

	link_user_to_blog( $user_id, $blog_id );
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id );
	do_action( 'wp_login', $user->user_login, $user );

	// ── Redirect after login ──────────────────────────────────────────────────
	$redirect_url = sso_option( 'signin_redirect_url' ) ?: get_site_url( $blog_id );

	// wp_safe_redirect() only permits same-host URLs by default.
	// Allow whatever host the admin has configured.
	add_filter(
		'allowed_redirect_hosts',
		function ( $hosts ) use ( $redirect_url ) {
			$host = wp_parse_url( $redirect_url, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
			return $hosts;
		}
	);

	// Clear the cached email-verification state so the first page after a fresh
	// login re-checks the API (handles account switches and newly verified emails).
	setcookie( 'edxemailverified', '', time() - DAY_IN_SECONDS, '/', '', is_ssl(), false );

	wp_safe_redirect( $redirect_url );
	exit;
}
add_action( 'init', __NAMESPACE__ . '\\validate_sso_login' );

// ── 2. Build the LMS authorization URL ───────────────────────────────────────

/**
 * Query arg that triggers the login gateway.
 */
const LOGIN_TRIGGER = 'tutor_sso_login';

/**
 * Build the OAuth 2.0 authorization URL that starts the SSO login flow.
 * Generates a fresh state token, stored as a 10-minute transient.
 *
 * @return string Full authorization URL.
 */
function get_lms_authorize_url() {

	// Generate a random, URL-safe state token for CSRF protection.
	$state = wp_generate_password( 32, false );
	set_transient( 'tutor_sso_state_' . $state, true, 10 * MINUTE_IN_SECONDS );

	return add_query_arg(
		array(
			'client_id'     => sso_option( 'client_id' ),
			'redirect_uri'  => sso_option( 'redirect_url' ),
			'response_type' => 'code',
			'state'         => $state,
		),
		sso_option( 'authorize_endpoint' )
	);
}

/**
 * URL every login button points at: the gateway, not the LMS directly.
 *
 * @return string
 */
function get_lms_login_url() {
	return add_query_arg( LOGIN_TRIGGER, '1', home_url( '/' ) );
}

/**
 * Cookie-name fragments treated as edX's. Matched case-insensitively.
 *
 * @return string[]
 */
function sso_edx_cookie_fragments() {
	return (array) apply_filters(
		'tutor_sso_edx_cookie_fragments',
		array( 'edx', 'sessionid', 'csrftoken', 'openedx' )
	);
}

/**
 * Domain shared by the WordPress and LMS hosts, as a cookie domain.
 * '' when they share no parent, so nothing wider than the host is touched.
 *
 * @return string e.g. '.rwaq.org'.
 */
function sso_shared_cookie_domain() {
	$wp  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$lms = (string) wp_parse_url( sso_option( 'lms_base_url' ), PHP_URL_HOST );

	if ( '' === $wp || '' === $lms ) {
		return '';
	}

	$a = array_reverse( explode( '.', $wp ) );
	$b = array_reverse( explode( '.', $lms ) );

	$common = array();
	foreach ( $a as $i => $label ) {
		if ( ! isset( $b[ $i ] ) || strtolower( $b[ $i ] ) !== strtolower( $label ) ) {
			break;
		}
		$common[] = $label;
	}

	// Two labels minimum, so a shared TLD alone never becomes a cookie domain.
	return count( $common ) >= 2 ? '.' . implode( '.', array_reverse( $common ) ) : '';
}

/**
 * Expire every edX cookie the browser sent us, so a stale LMS session cannot
 * short-circuit the next authorize request. WordPress's own cookies are skipped.
 */
function sso_clear_edx_cookies() {
	$fragments = array_filter( array_map( 'strtolower', array_map( 'strval', sso_edx_cookie_fragments() ) ) );

	if ( empty( $fragments ) || empty( $_COOKIE ) ) {
		return;
	}

	// Host-only first, then the shared parent — a cookie set on either is only
	// cleared by expiring it with the same domain.
	$domains = array( '' );
	$shared  = sso_shared_cookie_domain();
	if ( '' !== $shared ) {
		$domains[] = $shared;
	}

	$expired = time() - YEAR_IN_SECONDS;
	$secure  = is_ssl();

	foreach ( array_keys( (array) $_COOKIE ) as $name ) {
		$name  = (string) $name;
		$lower = strtolower( $name );

		// Never touch WordPress's own session cookies.
		if ( 0 === strpos( $lower, 'wordpress' ) || 0 === strpos( $lower, 'wp-' ) || 0 === strpos( $lower, 'wp_' ) ) {
			continue;
		}

		$match = false;
		foreach ( $fragments as $fragment ) {
			if ( false !== strpos( $lower, $fragment ) ) {
				$match = true;
				break;
			}
		}

		if ( ! $match ) {
			continue;
		}

		// Browsers match on name+domain+path, so one call per domain suffices.
		foreach ( $domains as $domain ) {
			setcookie( $name, '', $expired, '/', $domain, $secure, true );
		}

		unset( $_COOKIE[ $name ] );
	}
}

/**
 * Handle ?tutor_sso_login=1: clear edX cookies, then start the OAuth flow.
 */
function sso_login_gateway() {
	if ( ! isset( $_GET[ LOGIN_TRIGGER ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	if ( is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	sso_clear_edx_cookies();

	$url = get_lms_authorize_url();

	// Off the WordPress host, so wp_redirect() rather than wp_safe_redirect().
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
add_action( 'init', __NAMESPACE__ . '\\sso_login_gateway' );

// ── 3. Redirect to the LMS logout page on WordPress logout ───────────────────

/**
 * When a user logs out of WordPress, also send them to the LMS logout
 * endpoint so both sessions are terminated together.
 *
 * FIX vs original: The original used wp_redirect() (no host validation).
 * Now uses wp_safe_redirect() with the LMS host added to the whitelist.
 */
function redirect_to_lms_logout() {

	$lms_base = rtrim( sso_option( 'lms_base_url' ), '/' );

	if ( empty( $lms_base ) ) {
		return;
	}

	add_filter(
		'allowed_redirect_hosts',
		function ( $hosts ) use ( $lms_base ) {
			$host = wp_parse_url( $lms_base, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
			return $hosts;
		}
	);

	wp_safe_redirect( $lms_base . '/logout' );
	exit;
}
add_action( 'wp_logout', __NAMESPACE__ . '\\redirect_to_lms_logout' );

// ── 4. Hide the admin bar for non-admin roles ─────────────────────────────────

/**
 * Return true only when the current user has an admin-level role, so the
 * admin bar is hidden from regular subscribers.
 *
 * @return bool
 */
function show_admin_bar_for_admins_only() {
	$user          = wp_get_current_user();
	$allowed_roles = array( 'administrator', 'edly_admin' );
	return (bool) array_intersect( $allowed_roles, (array) $user->roles );
}
add_filter( 'show_admin_bar', __NAMESPACE__ . '\\show_admin_bar_for_admins_only' );

// ── 5. Block wp-admin for non-admin users ─────────────────────────────────────

/**
 * Redirect non-admin users away from the WordPress dashboard.
 * AJAX requests are always allowed through.
 */
function block_dashboard_for_non_admins() {

	$user          = wp_get_current_user();
	$allowed_roles = array( 'administrator', 'edly_admin' );
	$is_ajax       = defined( 'DOING_AJAX' ) && DOING_AJAX;

	if (
		! empty( $user->roles )
		&& ! array_intersect( $allowed_roles, $user->roles )
		&& is_admin()
		&& ! $is_ajax
	) {
		wp_safe_redirect( home_url() );
		exit;
	}
}
add_action( 'init', __NAMESPACE__ . '\\block_dashboard_for_non_admins' );

// ── 6. Auto-logout when the LMS session cookie disappears ────────────────────

/**
 * Destroy the WordPress session when the user's Open edX session cookies are
 * absent, keeping the two sessions in sync without requiring an explicit
 * WordPress logout.
 *
 * Relies on the standard Open edX / Tutor cookie names:
 *   - edxloggedin   (boolean flag cookie)
 *   - edx-user-info (JSON user-info cookie)
 *
 * If your Tutor deployment uses a custom PLATFORM_NAME, the cookie names may
 * differ (they follow the pattern {platform_name}loggedin). Adjust as needed.
 *
 * NOTE: super-admins are never auto-logged-out so maintenance work is not
 * interrupted by a missing LMS session.
 */
function revoke_session_on_lms_logout() {

	if ( ! wp_validate_auth_cookie( '', 'logged_in' ) ) {
		return;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	$has_edx_logged_in = ! empty( $_COOKIE['edxloggedin'] );
	$has_edx_user_info = isset( $_COOKIE['edx-user-info'] );

	if ( ! $has_edx_logged_in || ! $has_edx_user_info ) {
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
	}
}
add_action( 'init', __NAMESPACE__ . '\\revoke_session_on_lms_logout', 25 );

// ── 7. User creation / update ─────────────────────────────────────────────────

/**
 * Create a new WordPress user or synchronise an existing one, then make sure
 * they belong to the current blog (multisite only).
 *
 * FIXES vs original:
 * - Uses wp_update_user( array() ) instead of mutating $user directly, which
 *   was not being persisted to the database correctly.
 * - Guards add_user_to_blog() behind is_multisite() — the function is a no-op
 *   on single-site installs but calling it adds unnecessary overhead.
 * - Returns a proper WP_Error on failure instead of a bare `new \WP_Error()`.
 *
 * @param string $username User login name.
 * @param string $email    User e-mail address (already sanitized).
 * @param string $role     WordPress role to assign on first creation.
 * @param int    $blog_id  Target blog/site ID.
 * @param array  $profile  Optional name fields from the id_token, as returned
 *                         by sso_profile_from_claims(). Empty leaves whatever
 *                         name the account already has.
 * @return int|\WP_Error   User ID on success, WP_Error on failure.
 */
function create_or_update_user( $username, $email, $role, $blog_id, $profile = array() ) {

	$user = get_user_by( 'login', $username );

	if ( ! $user ) {
		// wpmu_create_user() lives in wp-includes/ms-functions.php, which
		// wp-settings.php loads only for multisite — calling it on a single
		// site is a fatal, so the first SSO login by a new user would take the
		// whole sign-in down. wp_create_user() is the single-site equivalent;
		// the branch matches the is_multisite() guard just below.
		$user_id = is_multisite()
			? wpmu_create_user( $username, wp_generate_password(), $email )
			: wp_create_user( $username, wp_generate_password(), $email );

		// The two report failure differently: wpmu_create_user() returns false,
		// wp_create_user() a WP_Error — which is truthy, so a bare `! $user_id`
		// would let a failed creation through as if it were an ID.
		if ( ! $user_id || is_wp_error( $user_id ) ) {
			return new \WP_Error(
				'tutor_sso_user_creation_failed',
				__( 'Failed to create the WordPress user account.', 'tutor-sso' )
			);
		}

		if ( is_multisite() ) {
			add_user_to_blog( $blog_id, $user_id, $role );
		}

	} else {
		$user_id = $user->ID;

		// Keep the e-mail address in sync with the LMS.
		if ( $email !== $user->user_email ) {
			wp_update_user(
				array(
					'ID'         => $user_id,
					'user_email' => $email,
				)
			);
		}
	}

	// The LMS owns the name: the account page renders these fields read-only
	// and the save handler restores them (see my-account-form.php), so
	// re-applying on every login is the only way a rename in the LMS reaches
	// the site — and there is no WordPress-side edit for it to overwrite.
	sso_sync_user_profile( $user_id, $profile );

	return $user_id;
}

/**
 * Read the OpenID Connect name claims out of an id_token payload.
 *
 * `name` is the one Open edX actually fills (its account has a single "Full
 * name" field); given_name / family_name are honoured first when a provider
 * does send them, since a split the provider made is better than one guessed
 * here.
 *
 * @param array $claims Decoded id_token payload.
 * @return array{first:string,last:string,full:string}|array Empty when the
 *               token carries no usable name — the account's own name is then
 *               left alone rather than overwritten with a guess.
 */
function sso_profile_from_claims( $claims ) {
	if ( ! is_array( $claims ) ) {
		return array();
	}

	// is_scalar before the cast: a token whose `name` is a JSON object or
	// array decodes to one here, and casting that is a fatal (object) or
	// yields the literal string "Array" (array) — either during login, which
	// is the worst place to find out. A non-string claim is treated as absent.
	$full  = ( isset( $claims['name'] ) && is_scalar( $claims['name'] ) ) ? trim( (string) $claims['name'] ) : '';
	$first = ( isset( $claims['given_name'] ) && is_scalar( $claims['given_name'] ) ) ? trim( (string) $claims['given_name'] ) : '';
	$last  = ( isset( $claims['family_name'] ) && is_scalar( $claims['family_name'] ) ) ? trim( (string) $claims['family_name'] ) : '';

	if ( '' === $full ) {
		$full = trim( $first . ' ' . $last );
	}

	if ( '' === $full ) {
		return array();
	}

	if ( '' === $first && '' === $last ) {
		// Split the single `name` claim on its last space. Byte functions are
		// safe here even for an Arabic name: a space is ASCII 0x20, which
		// cannot occur inside a UTF-8 multi-byte sequence, so the cut always
		// lands on a character boundary.
		$pos = strrpos( $full, ' ' );

		if ( false !== $pos ) {
			$first = substr( $full, 0, $pos );
			$last  = substr( $full, $pos + 1 );
		} else {
			$first = $full;
		}
	}

	return array(
		'first' => $first,
		'last'  => $last,
		'full'  => $full,
	);
}

/**
 * Apply the id_token's name to the WordPress account.
 *
 * Runs on every login, not only at creation: first name, last name and display
 * name are not editable on this site, so the LMS is their only source and a
 * rename there has to be able to land. Nothing a person could have set here is
 * at risk of being overwritten, which is what made a per-login write wrong
 * while those fields were still editable.
 *
 * @param int   $user_id User ID.
 * @param array $profile As returned by sso_profile_from_claims(); an empty one
 *                       (no name in the token) leaves the account alone.
 * @return void
 */
function sso_sync_user_profile( $user_id, $profile ) {
	if ( empty( $profile['full'] ) ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return;
	}

	$first = isset( $profile['first'] ) ? $profile['first'] : '';
	$last  = isset( $profile['last'] ) ? $profile['last'] : '';

	$fields = array( 'ID' => $user_id );

	if ( $first !== $user->first_name ) {
		$fields['first_name'] = $first;
	}

	if ( $last !== $user->last_name ) {
		$fields['last_name'] = $last;
	}

	if ( $profile['full'] !== $user->display_name ) {
		$fields['display_name'] = $profile['full'];
	}

	// Nothing but the ID means the account already matches — no write.
	if ( count( $fields ) > 1 ) {
		wp_update_user( $fields );
	}
}

// ── 8. Blog membership helper ─────────────────────────────────────────────────

/**
 * Add a user to a blog if they are not already a member (multisite only).
 *
 * @param int    $user_id User ID.
 * @param int    $blog_id Blog/site ID.
 * @param string $role    Role to assign when adding for the first time.
 */
function link_user_to_blog( $user_id, $blog_id, $role = 'subscriber' ) {
	if ( is_multisite() && ! is_user_member_of_blog( $user_id, $blog_id ) ) {
		add_user_to_blog( $blog_id, $user_id, $role );
	}
}

// ── 9. Suppress the heartbeat auth-check modal ───────────────────────────────

// Prevent the "You have been logged out" popup on the front end.
// SSO users should be handled by revoke_session_on_lms_logout() above.
add_filter( 'wp_auth_check_load', '__return_false' );

// ── 10. Login / logout button shortcode ──────────────────────────────────────

/**
 * Render a login button for logged-out visitors and the account dropdown for
 * logged-in users from a single shortcode.
 *
 * Attributes:
 *   label         - Text for the login button    (default "Log in with LMS").
 *   logout_label  - Text for the logout item.
 *   account_label - Text for the settings item.
 *   account_url   - Override for the settings destination (defaults to the
 *                   LMS's own account settings page).
 *
 * Backward compatible: [tutor_sso_login label="…"] still works exactly as
 * before for logged-out visitors. Logged-in users used to get a bare logout
 * link; they now get the account dropdown (name, email, account settings,
 * log out) from the design — `logout_label` still names the logout item, so
 * an existing override keeps working.
 */
add_shortcode( 'tutor_sso_login', function ( $atts ) {

    $atts = shortcode_atts(
        [
            'label'         => __( 'Log in with LMS', 'tutor-sso' ),
            'logout_label'  => __( 'تسجيل الخروج', 'tutor-sso' ),
            'account_label' => __( 'إعدادات الحساب', 'tutor-sso' ),
            'account_url'   => '',
        ],
        $atts,
        'tutor_sso_login'
    );

    if ( is_user_logged_in() ) {
        return \TutorSSO\render_account_menu(
            [
                'logout_label'  => $atts['logout_label'],
                'account_label' => $atts['account_label'],
                'account_url'   => $atts['account_url'],
            ]
        );
    }

    $url = \TutorSSO\get_lms_login_url();

    return sprintf(
        '<a href="%s" class="tutor-sso-login-btn">%s</a>',
        esc_url( $url ),
        esc_html( $atts['label'] )
    );
} );

// ── 11. Start-learning button shortcode ───────────────────────────────────────

/**
 * Render a "Start learning" button that links to the LMS dashboard.
 *
 * Attributes:
 *   label - Button text. Default "Start learning".
 *   url   - Destination URL. Defaults to the configured course dashboard URL
 *           (tutor_sso_course_dashboard_url), then the LMS base URL, with
 *           /learner-dashboard/ appended.
 *
 * Only rendered for logged-in users.
 *
 * Example: [tutor_sso_start_learning label="ابدأ التعلم" url="https://lms.example.com/dashboard"]
 */
add_shortcode( 'tutor_sso_start_learning', function ( $atts ) {

    if ( ! is_user_logged_in() ) {
        return '';
    }

    $atts = shortcode_atts(
        [
            'label' => __( 'Start learning', 'tutor-sso' ),
            'url'   => '',
        ],
        $atts,
        'tutor_sso_start_learning'
    );

    $url = $atts['url'];

    if ( empty( $url ) ) {
        $base = sso_option( 'course_dashboard_url' ) ?: sso_option( 'lms_base_url' );
        $base = rtrim( (string) $base, '/' );
        $url  = $base ? $base . '/learner-dashboard/' : '';
    }

    if ( empty( $url ) ) {
        return '';
    }

    return sprintf(
        '<a href="%s" class="tutor-sso-start-learning-btn">%s</a>',
        esc_url( $url ),
        esc_html( $atts['label'] )
    );
} );