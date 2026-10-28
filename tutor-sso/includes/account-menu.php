<?php
/**
 * Logged-in account menu — the header dropdown that replaced the bare logout
 * link (Figma 10529:104856).
 *
 * The panel shows who is signed in (display name + email) above two actions:
 * account settings on the LMS, and log out. It is rendered by the existing
 * [tutor_sso_login] shortcode's logged-in branch (see sso-functions.php §10),
 * so every place that already printed a logout button gets the dropdown with
 * no template change.
 *
 * Markup/CSS/JS are self-contained here and in account-menu.{css,js}: the
 * trigger is a real <button> with aria-expanded/aria-controls, and the panel
 * is a sibling that the script toggles. Nothing depends on the theme's own
 * header markup, since the shortcode can be placed anywhere.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destination of the "account settings" item: the WooCommerce My Account page.
 *
 * Read from WooCommerce's own page setting rather than a hardcoded /my-account/
 * path, so it follows whatever page the store has assigned and whatever
 * permalink that page uses.
 *
 * @return string URL, or '' when WooCommerce is inactive or has no My Account
 *                page assigned — in which case the item is skipped entirely
 *                rather than linking somewhere wrong.
 */
function account_settings_url() {
	$url = '';

	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$permalink = wc_get_page_permalink( 'myaccount' );

		if ( is_string( $permalink ) && '' !== $permalink ) {
			$url = $permalink;
		}
	}

	/**
	 * Filter the account-settings destination.
	 *
	 * @param string $url Resolved URL ('' when WooCommerce has none).
	 */
	return (string) apply_filters( 'tutor_sso_account_settings_url', $url );
}

/**
 * Inline SVG for one menu item's icon.
 *
 * Inlined rather than referenced as a file so the icon inherits `currentColor`
 * and needs no extra request; both are 20x20 to match the design.
 *
 * @param string $name 'settings' or 'logout'.
 * @return string SVG markup, or '' for an unknown name.
 */
function account_menu_icon( $name ) {
	$icons = array(
		// Gear.
		'settings' => '<circle cx="10" cy="10" r="2.9"/><path d="M16.1 12.3a1.3 1.3 0 0 0 .3 1.4l.1.1a1.5 1.5 0 1 1-2.2 2.2l-.1-.1a1.3 1.3 0 0 0-1.4-.3 1.3 1.3 0 0 0-.8 1.2v.2a1.5 1.5 0 1 1-3 0v-.1a1.3 1.3 0 0 0-.9-1.2 1.3 1.3 0 0 0-1.4.3l-.1.1a1.5 1.5 0 1 1-2.2-2.2l.1-.1a1.3 1.3 0 0 0 .3-1.4 1.3 1.3 0 0 0-1.2-.8h-.2a1.5 1.5 0 1 1 0-3h.1a1.3 1.3 0 0 0 1.2-.9 1.3 1.3 0 0 0-.3-1.4l-.1-.1a1.5 1.5 0 1 1 2.2-2.2l.1.1a1.3 1.3 0 0 0 1.4.3h.1a1.3 1.3 0 0 0 .8-1.2v-.2a1.5 1.5 0 1 1 3 0v.1a1.3 1.3 0 0 0 .8 1.2 1.3 1.3 0 0 0 1.4-.3l.1-.1a1.5 1.5 0 1 1 2.2 2.2l-.1.1a1.3 1.3 0 0 0-.3 1.4v.1a1.3 1.3 0 0 0 1.2.8h.2a1.5 1.5 0 1 1 0 3h-.1a1.3 1.3 0 0 0-1.2.8z"/>',
		// Door with an out-arrow.
		'logout'   => '<path d="M7.5 17.5h-2a1.7 1.7 0 0 1-1.7-1.7V4.2a1.7 1.7 0 0 1 1.7-1.7h2"/><path d="M13.3 14.2 17.5 10l-4.2-4.2"/><path d="M17.5 10h-10"/>',
	);

	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}

	return '<svg class="tutor-sso-account__icon" viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[ $name ] . '</svg>';
}

/**
 * The name to show for an account — a person's name, not their login.
 *
 * WordPress defaults display_name to the username, and an SSO account created
 * before the login flow started syncing names (see sso_sync_user_profile())
 * still carries that default — which is why the menu read "kk_123" rather than
 * a name. Where the account has real name fields, they win over that default;
 * the login itself now keeps them filled, so this is the fallback for accounts
 * that have not logged in since.
 *
 * @param \WP_User $user User.
 * @return string Display name, falling back to the login when there is nothing
 *                better.
 */
function account_menu_display_name( $user ) {
	$display = trim( (string) $user->display_name );
	$login   = trim( (string) $user->user_login );

	if ( '' !== $display && $display !== $login ) {
		return $display;
	}

	$composed = trim( trim( (string) $user->first_name ) . ' ' . trim( (string) $user->last_name ) );

	if ( '' !== $composed ) {
		return $composed;
	}

	return '' !== $display ? $display : $login;
}

/**
 * How many characters of the display name the trigger button shows.
 */
const ACCOUNT_MENU_NAME_LENGTH = 4;

/**
 * Render the account dropdown for the current user.
 *
 * @param array $args {
 *     @type string $account_label Label of the settings item.
 *     @type string $logout_label  Label of the logout item.
 *     @type string $account_url   Override for the My Account destination.
 * }
 * @return string HTML, or '' when nobody is logged in.
 */
function render_account_menu( $args = array() ) {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$user = wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return '';
	}

	$args = wp_parse_args(
		$args,
		array(
			'account_label' => __( 'إعدادات الحساب', 'tutor-sso' ),
			'logout_label'  => __( 'تسجيل الخروج', 'tutor-sso' ),
			'account_url'   => '',
		)
	);

	tutor_sso_account_menu_enqueue_assets();

	$name  = account_menu_display_name( $user );
	$email = trim( (string) $user->user_email );

	$account_url = '' !== $args['account_url'] ? $args['account_url'] : account_settings_url();

	// Each instance needs its own id so aria-controls stays unambiguous when
	// the shortcode is used more than once (e.g. desktop + mobile headers).
	static $instance = 0;
	++$instance;
	$panel_id = 'tutor-sso-account-panel-' . $instance;

	ob_start();
	?>
	<div class="tutor-sso-account" data-tutor-sso-account>
		<button
			type="button"
			class="tutor-sso-account__trigger"
			aria-expanded="false"
			aria-haspopup="true"
			aria-controls="<?php echo esc_attr( $panel_id ); ?>"
			aria-label="<?php echo esc_attr( '' !== $name ? sprintf( /* translators: %s: the user's name. */ __( 'قائمة الحساب: %s', 'tutor-sso' ), $name ) : __( 'قائمة الحساب', 'tutor-sso' ) ); ?>"
		>
			<?php // Icon only, as designed (Figma 10699:13511): the name is the button's label for screen readers, and the panel below shows it in full. ?>
			<img class="tutor-sso-account__user-icon" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/account/user.svg' ); ?>" alt="" aria-hidden="true">
			<img class="tutor-sso-account__chevron" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/account/chevron.svg' ); ?>" alt="" aria-hidden="true">
		</button>

		<div class="tutor-sso-account__panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<div class="tutor-sso-account__identity">
				<span class="tutor-sso-account__name"><?php echo esc_html( $name ); ?></span>
				<?php if ( '' !== $email ) : ?>
					<span class="tutor-sso-account__email"><?php echo esc_html( $email ); ?></span>
				<?php endif; ?>
			</div>

			<hr class="tutor-sso-account__divider">

			<?php if ( '' !== $account_url ) : ?>
				<a class="tutor-sso-account__item" href="<?php echo esc_url( $account_url ); ?>">
					<?php echo account_menu_icon( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="tutor-sso-account__label"><?php echo esc_html( $args['account_label'] ); ?></span>
				</a>
			<?php endif; ?>

			<a class="tutor-sso-account__item" href="<?php echo esc_url( wp_logout_url() ); ?>">
				<?php echo account_menu_icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="tutor-sso-account__label"><?php echo esc_html( $args['logout_label'] ); ?></span>
			</a>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
