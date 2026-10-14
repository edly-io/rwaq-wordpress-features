<?php
/**
 * Adjust the WooCommerce "Account details" form for an SSO site.
 *
 * Most of this form does not belong on it when identity lives in the LMS:
 *
 *  - The name fields (first, last, display) and the email address. These are
 *    the profile the LMS owns and re-sends on every login, so an edit made
 *    here would either split the two accounts apart (email) or silently be
 *    undone at the next sign-in (names). They are shown read-only, with one
 *    notice pointing at the LMS, and the login keeps them current.
 *  - The password fields. Sign-in goes through the LMS, so a WordPress-side
 *    password is not something the customer sets, or needs.
 *
 * Each is handled in two layers, because only one of them is a guarantee:
 *
 *  1. Server side (authoritative). WooCommerce reads $_POST straight into the
 *     user object, so there is no filter on either value — but it passes that
 *     object by reference to `woocommerce_save_account_details_errors`, which
 *     fires after both are read and before wp_update_user(). Restoring the
 *     email and dropping user_pass there means a hand-crafted POST, a devtools
 *     edit, or a stale cached page all save the account unchanged.
 *
 *  2. In the form (presentation). The locked inputs are marked readonly under
 *     a single notice, and the password fieldset is taken out. WooCommerce's
 *     template has no filter on any of them, so the markup is rewritten in a
 *     single output buffer rather than by overriding the template — if a
 *     future template changes beyond recognition the rewrite simply no-ops,
 *     and layer 1 still holds.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── 1. Server side: neither field can be changed, whatever was posted ────────

/**
 * The account fields the LMS owns, as named on the WordPress user object.
 *
 * @return string[]
 */
function my_account_locked_user_fields() {
	return array( 'first_name', 'last_name', 'display_name', 'user_email' );
}

/**
 * The same fields as named on the form, for the markup rewrite below.
 *
 * @return string[]
 */
function my_account_locked_input_names() {
	return array( 'account_first_name', 'account_last_name', 'account_display_name', 'account_email' );
}

/**
 * Restore the LMS-owned fields and drop any posted password before saving.
 *
 * @param \WP_Error $errors Validation errors (unused — none of these is one
 *                          the customer needs an error about; they are simply
 *                          not editable here).
 * @param \WP_User  $user   User about to be saved, passed by reference by
 *                          WooCommerce.
 * @return void
 */
function my_account_lock_identity_fields( $errors, &$user ) {
	if ( ! is_object( $user ) || empty( $user->ID ) ) {
		return;
	}

	$current = get_userdata( $user->ID );

	if ( $current ) {
		foreach ( my_account_locked_user_fields() as $field ) {
			if ( isset( $current->$field ) ) {
				$user->$field = $current->$field;
			}
		}
	}

	// WooCommerce sets user_pass only when a valid change was posted; unset
	// rather than blanked, so wp_update_user() leaves the stored hash alone
	// (an empty user_pass would be treated as a new password).
	unset( $user->user_pass );
}
add_action( 'woocommerce_save_account_details_errors', __NAMESPACE__ . '\\my_account_lock_identity_fields', 10, 2 );

// ── 2. In the form ──────────────────────────────────────────────────────────

/**
 * Whether the form buffer is currently open, so it is closed exactly once.
 *
 * @var bool
 */
$GLOBALS['tutor_sso_account_form_buffering'] = false;

/**
 * Start buffering the account form's fields.
 *
 * @return void
 */
function my_account_form_buffer_start() {
	if ( $GLOBALS['tutor_sso_account_form_buffering'] ) {
		return;
	}

	$GLOBALS['tutor_sso_account_form_buffering'] = true;
	ob_start();
}
add_action( 'woocommerce_edit_account_form_start', __NAMESPACE__ . '\\my_account_form_buffer_start' );

/**
 * Mark every LMS-owned input readonly.
 *
 * `readonly` rather than `disabled`: a disabled input posts nothing, which
 * would trip WooCommerce's own "required field" validation before the save
 * handler above ever runs.
 *
 * @param string $html Buffered form markup.
 * @return string
 */
function my_account_form_lock_inputs( $html ) {
	$names = array_map( 'preg_quote', my_account_locked_input_names() );

	return (string) preg_replace_callback(
		'#<input\b[^>]*\bname=(["\'])(?:' . implode( '|', $names ) . ')\1[^>]*>#i',
		function ( $matches ) {
			$tag = $matches[0];

			// Already locked by something else — leave it as it is.
			if ( preg_match( '#\breadonly\b#i', $tag ) ) {
				return $tag;
			}

			return preg_replace( '#^<input\b#i', '<input readonly aria-readonly="true"', $tag, 1 );
		},
		$html
	);
}

/**
 * Prepend the notice explaining where these details are edited.
 *
 * One notice for the whole group rather than a line under each field: four
 * repetitions of the same sentence is noise, and the reason is the same for
 * all of them.
 *
 * @param string $html Buffered form markup.
 * @return string
 */
function my_account_form_add_notice( $html ) {
	$notice = sprintf(
		'<p class="tutor-sso-account-locked-notice">%s</p>',
		esc_html__( 'يتم إدارة اسمك وبريدك الإلكتروني في منصة التعلّم، ويمكنك تعديلهما من هناك. سيتم تحديثهما هنا تلقائيًا عند تسجيل الدخول في المرة القادمة.', 'tutor-sso' )
	);

	return $notice . $html;
}

/**
 * Drop the password-change fieldset.
 *
 * Only a fieldset that actually holds the password inputs is removed, so a
 * fieldset added to this form by another plugin is left alone.
 *
 * @param string $html Buffered form markup.
 * @return string
 */
function my_account_form_remove_password_fields( $html ) {
	return (string) preg_replace_callback(
		'#<fieldset\b[^>]*>.*?</fieldset>#is',
		function ( $matches ) {
			return false !== stripos( $matches[0], 'name="password_' ) ? '' : $matches[0];
		},
		$html
	);
}

/**
 * Drop the "Save changes" button.
 *
 * Every field on this form is now read-only and the save handler restores them
 * all, so the button cannot change anything — it would only ever reload the
 * page and report success at saving nothing. Its nonce and the hidden `action`
 * field go with it, since neither means anything without a submit.
 *
 * The whole paragraph is matched rather than the button alone, so no empty
 * wrapper is left behind for the stylesheet to space out.
 *
 * @param string $html Buffered form markup.
 * @return string
 */
function my_account_form_remove_submit( $html ) {
	return (string) preg_replace_callback(
		'#<p\b[^>]*>(?:(?!</p>).)*?</p>#is',
		function ( $matches ) {
			return false !== stripos( $matches[0], 'name="save_account_details"' ) ? '' : $matches[0];
		},
		$html
	);
}

/**
 * Flush the buffer, applying every rewrite on the way out.
 *
 * Closes at the end of the form rather than at the field-area hook, because the
 * submit button is rendered between the two and has to be inside the buffer to
 * be removed.
 *
 * Two backstops follow it: a template that drops `..._form_end` would otherwise
 * leave the buffer open and swallow the rest of the page. Each is idempotent,
 * so whichever fires first wins and the others do nothing.
 *
 * @return void
 */
function my_account_form_buffer_end() {
	if ( ! $GLOBALS['tutor_sso_account_form_buffering'] ) {
		return;
	}

	$GLOBALS['tutor_sso_account_form_buffering'] = false;

	$html = ob_get_clean();

	if ( is_string( $html ) && '' !== $html ) {
		$html = my_account_form_lock_inputs( $html );
		$html = my_account_form_remove_password_fields( $html );
		$html = my_account_form_remove_submit( $html );
		$html = my_account_form_add_notice( $html );
	}

	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_edit_account_form_end', __NAMESPACE__ . '\\my_account_form_buffer_end' );
add_action( 'woocommerce_after_edit_account_form', __NAMESPACE__ . '\\my_account_form_buffer_end' );
add_action( 'shutdown', __NAMESPACE__ . '\\my_account_form_buffer_end', 0 );
