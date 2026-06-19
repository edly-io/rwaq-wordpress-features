<?php
/**
 * Admin settings page for Tutor LMS SSO.
 *
 * Replaces the original hardcoded constants (EDLY_SSO_CLIENT_ID, etc.) with
 * values stored via the WordPress Settings API, configurable from
 * Settings → Tutor LMS SSO.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the SSO settings page under Settings in wp-admin.
 */
class Settings_Page {

	/** WordPress option group name. */
	const OPTION_GROUP = 'tutor_sso_settings';

	/** Admin page slug. */
	const PAGE_SLUG = 'tutor-sso-settings';

	/**
	 * Wire up all hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter(
			'plugin_action_links_' . TUTOR_SSO_BASENAME,
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Register the settings page under Settings.
	 */
	public function add_menu_page() {
		add_options_page(
			__( 'Tutor LMS SSO Settings', 'tutor-sso' ),
			__( 'Tutor LMS SSO', 'tutor-sso' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register every option with its sanitizer via the Settings API.
	 */
	public function register_settings() {

		// Map option name → sanitizer callback.
		$options = array(
			'tutor_sso_lms_base_url'       => 'esc_url_raw',
			'tutor_sso_access_token_url'    => 'esc_url_raw',
			'tutor_sso_authorize_endpoint'  => 'esc_url_raw',
			'tutor_sso_redirect_url'        => 'esc_url_raw',
			'tutor_sso_signin_redirect_url' => 'esc_url_raw',
			'tutor_sso_client_id'           => 'sanitize_text_field',
			'tutor_sso_client_secret'       => 'sanitize_text_field',
			'tutor_sso_course_dashboard_url' => 'esc_url_raw',
		);

		foreach ( $options as $name => $cb ) {
			register_setting( self::OPTION_GROUP, $name, array( 'sanitize_callback' => $cb ) );
		}

		// ── Section 1: LMS Endpoints ─────────────────────────────────────────

		add_settings_section(
			'tutor_sso_section_endpoints',
			__( 'LMS Endpoints', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__( 'Open edX / Tutor LMS OAuth 2.0 endpoints.', 'tutor-sso' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_url_field(
			'tutor_sso_lms_base_url',
			__( 'LMS Base URL', 'tutor-sso' ),
			'tutor_sso_section_endpoints',
			'https://lms.example.com',
			__( 'Root URL of your Tutor / Open edX instance (no trailing slash). Used to build the logout redirect.', 'tutor-sso' )
		);

		$this->add_url_field(
			'tutor_sso_access_token_url',
			__( 'Access Token URL', 'tutor-sso' ),
			'tutor_sso_section_endpoints',
			'https://lms.example.com/oauth2/access_token',
			__( 'OAuth 2.0 token endpoint — where WordPress exchanges the authorization code for tokens.', 'tutor-sso' )
		);

		$this->add_url_field(
			'tutor_sso_authorize_endpoint',
			__( 'Authorize Endpoint', 'tutor-sso' ),
			'tutor_sso_section_endpoints',
			'https://lms.example.com/oauth2/authorize',
			__( 'OAuth 2.0 authorization endpoint — where WordPress sends users to log in.', 'tutor-sso' )
		);

		// ── Section 2: OAuth Credentials ─────────────────────────────────────

		add_settings_section(
			'tutor_sso_section_credentials',
			__( 'OAuth Credentials', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Client credentials from your Open edX OAuth 2.0 application (LMS admin → Django admin → DOT Applications).',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tutor_sso_client_id',
			__( 'Client ID', 'tutor-sso' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_credentials',
			array(
				'option_name' => 'tutor_sso_client_id',
				'description' => __( 'OAuth 2.0 client ID from your LMS.', 'tutor-sso' ),
			)
		);

		add_settings_field(
			'tutor_sso_client_secret',
			__( 'Client Secret', 'tutor-sso' ),
			array( $this, 'render_password_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_credentials',
			array(
				'option_name' => 'tutor_sso_client_secret',
				'description' => __( 'OAuth 2.0 client secret. Stored in the WordPress database — restrict database access accordingly.', 'tutor-sso' ),
			)
		);

		// ── Section 3: Redirect URLs ──────────────────────────────────────────

		add_settings_section(
			'tutor_sso_section_redirects',
			__( 'Redirect URLs', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__( 'Where to send users during and after the login flow.', 'tutor-sso' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_url_field(
			'tutor_sso_redirect_url',
			__( 'OAuth Callback URL', 'tutor-sso' ),
			'tutor_sso_section_redirects',
			get_site_url() . '/',
			sprintf(
				/* translators: %s: site home URL wrapped in <code> */
				__( 'The WordPress URL the LMS redirects back to with the authorization code. Must match the redirect URI registered in your LMS OAuth application. Typically %s.', 'tutor-sso' ),
				'<code>' . esc_url( get_site_url() ) . '/</code>'
			)
		);

		$this->add_url_field(
			'tutor_sso_signin_redirect_url',
			__( 'Post Sign-In Redirect URL', 'tutor-sso' ),
			'tutor_sso_section_redirects',
			get_site_url(),
			__( 'Where to send users after a successful SSO login. Leave empty to use the site home URL.', 'tutor-sso' )
		);

		// ── Section 4: Course Enrollment ─────────────────────────────────────

		add_settings_section(
			'tutor_sso_section_enrollment',
			__( 'Course Enrollment', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Settings for the [tutor_enroll_button] shortcode. Enrollment reuses the logged-in user\'s LMS session cookies and the LMS Base URL configured above — no extra API keys are required.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->add_url_field(
			'tutor_sso_course_dashboard_url',
			__( 'Course Dashboard URL (optional)', 'tutor-sso' ),
			'tutor_sso_section_enrollment',
			get_site_url(),
			__( 'Base URL the "Go to Course" link points at. Leave empty to fall back to the LMS Base URL.', 'tutor-sso' )
		);
	}

	/**
	 * Helper: register a URL input field in one call.
	 *
	 * @param string $id          Option name / field ID.
	 * @param string $label       Field label.
	 * @param string $section     Section to attach to.
	 * @param string $placeholder Placeholder text.
	 * @param string $description Help text (may contain basic HTML).
	 */
	private function add_url_field( $id, $label, $section, $placeholder, $description ) {
		add_settings_field(
			$id,
			$label,
			array( $this, 'render_url_field' ),
			self::PAGE_SLUG,
			$section,
			array(
				'option_name' => $id,
				'placeholder' => $placeholder,
				'description' => $description,
			)
		);
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	/**
	 * Render a plain text <input>.
	 *
	 * @param array $args {
	 *     @type string $option_name  Option name.
	 *     @type string $description  Optional help text.
	 * }
	 */
	public function render_text_field( $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( $args['option_name'] ),
			esc_attr( $value )
		);
		$this->maybe_description( $args );
	}

	/**
	 * Render a URL <input>.
	 *
	 * @param array $args {
	 *     @type string $option_name  Option name.
	 *     @type string $placeholder  Placeholder URL.
	 *     @type string $description  Optional help text.
	 * }
	 */
	public function render_url_field( $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<input type="url" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( $args['option_name'] ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);
		$this->maybe_description( $args );
	}

	/**
	 * Render a password <input>.
	 *
	 * @param array $args {
	 *     @type string $option_name  Option name.
	 *     @type string $description  Optional help text.
	 * }
	 */
	public function render_password_field( $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $args['option_name'] ),
			esc_attr( $value )
		);
		$this->maybe_description( $args );
	}

	/**
	 * Output a <p class="description"> if $args['description'] is set.
	 *
	 * @param array $args Field arguments.
	 */
	private function maybe_description( $args ) {
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses_post( $args['description'] ) );
		}
	}

	// ── Page render ───────────────────────────────────────────────────────────

	/**
	 * Output the settings page HTML.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prepend a "Settings" link on the Plugins list screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
				__( 'Settings', 'tutor-sso' )
			)
		);
		return $links;
	}
}
