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
	 * Google Tag Manager container ID format.
	 *
	 * Shared with the front-end printer, which re-validates before output.
	 */
	const GTM_ID_PATTERN = '/^GTM-[A-Z0-9]{4,15}$/';

	/** GA4 measurement ID format. */
	const GA_ID_PATTERN = '/^G-[A-Z0-9]{4,15}$/';

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
			'tutor_sso_jwt_auth_secret_key' => 'sanitize_text_field',
			'tutor_sso_programs_per_page'   => 'absint',
			'tutor_sso_blogs_per_page'      => 'absint',
			'tutor_sso_courses_per_page'    => 'absint',
			'tutor_sso_gtm_id'              => array( $this, 'sanitize_gtm_id' ),
			'tutor_sso_ga_id'               => array( $this, 'sanitize_ga_id' ),
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

		// ── Section 5: Programs API ──────────────────────────────────────────
		add_settings_section(
			'tutor_sso_section_programs_api',
			__( 'Programs API', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'JWT signing secret used to authenticate the programs REST endpoint (POST /wp-json/rwaq/v1/programs). Requires the "JWT Authentication for WP REST API" plugin.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		$jwt_in_config = defined( 'JWT_AUTH_SECRET_KEY' ) && ! get_option( 'tutor_sso_jwt_auth_secret_key' );

		add_settings_field(
			'tutor_sso_jwt_auth_secret_key',
			__( 'JWT Secret Key', 'tutor-sso' ),
			array( $this, 'render_password_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_programs_api',
			array(
				'option_name' => 'tutor_sso_jwt_auth_secret_key',
				'description' => $jwt_in_config
					? __( 'JWT_AUTH_SECRET_KEY is already defined in wp-config.php, which always takes precedence, this field is ignored.', 'tutor-sso' )
					: __( 'A long random string (e.g. from the WordPress salt generator). Used to sign JWTs. Defining JWT_AUTH_SECRET_KEY in wp-config.php,  overrides this field.', 'tutor-sso' ),
			)
		);

		// ── Section 6: Programs Catalog ──────────────────────────────────────
		add_settings_section(
			'tutor_sso_section_programs_catalog',
			__( 'Programs Catalog', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Display settings for the [rwaq_programs] catalog and the /programs/ archive page.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tutor_sso_programs_per_page',
			__( 'Programs per page', 'tutor-sso' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_programs_catalog',
			array(
				'option_name' => 'tutor_sso_programs_per_page',
				'default'     => 6,
				'min'         => 1,
				'max'         => 48,
				'description' => __( 'How many programs to load per page / infinite-scroll batch. Default: 6, maximum: 48. A per_page="…" attribute on the shortcode overrides this.', 'tutor-sso' ),
			)
		);

		// ── Section 7: Blogs Listing ─────────────────────────────────────────
		add_settings_section(
			'tutor_sso_section_blogs_listing',
			__( 'Blogs Listing', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Display settings for the [rwaq_blogs] posts listing.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tutor_sso_blogs_per_page',
			__( 'Blogs per page', 'tutor-sso' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_blogs_listing',
			array(
				'option_name' => 'tutor_sso_blogs_per_page',
				'default'     => 8,
				'min'         => 1,
				'max'         => 48,
				'description' => __( 'How many posts to load per page / infinite-scroll batch. Default: 8, maximum: 48. A per_page="…" attribute on the shortcode overrides this.', 'tutor-sso' ),
			)
		);

		// ── Section 8: Courses Catalog ───────────────────────────────────────
		add_settings_section(
			'tutor_sso_section_courses_catalog',
			__( 'Courses Catalog', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Display settings for the [rwaq_courses] catalog.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tutor_sso_courses_per_page',
			__( 'Courses per page', 'tutor-sso' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_courses_catalog',
			array(
				'option_name' => 'tutor_sso_courses_per_page',
				'default'     => 8,
				'min'         => 1,
				'max'         => 48,
				'description' => __( 'How many courses to load per page / infinite-scroll batch. Default: 8, maximum: 48. A per_page="…" attribute on the shortcode overrides this.', 'tutor-sso' ),
			)
		);

		// ── Section 9: Analytics ─────────────────────────────────────────────
		add_settings_section(
			'tutor_sso_section_analytics',
			__( 'Analytics', 'tutor-sso' ),
			function () {
				echo '<p>' . esc_html__(
					'Tracking code printed on every front-end page. Enter just the IDs below — the plugin builds the official Google snippets for you. Leave a field empty to disable that tag.',
					'tutor-sso'
				) . '</p>';
				echo '<p>' . esc_html__(
					'If you already deploy Google Analytics through Tag Manager, fill in the GTM ID only — filling both loads GA4 twice and double-counts every pageview.',
					'tutor-sso'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'tutor_sso_gtm_id',
			__( 'Add GTM ID', 'tutor-sso' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_analytics',
			array(
				'option_name' => 'tutor_sso_gtm_id',
				'placeholder' => 'GTM-XXXXXXX',
				'description' => __( 'The Google Tag Manager container used to track website analytics. Example value "GTM-M69F9BL".', 'tutor-sso' ),
			)
		);

		add_settings_field(
			'tutor_sso_ga_id',
			__( 'Add Google Analytics ID', 'tutor-sso' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'tutor_sso_section_analytics',
			array(
				'option_name' => 'tutor_sso_ga_id',
				'placeholder' => 'G-XXXXXXXXXX',
				'description' => __( 'The Google Analytics 4 measurement ID used to track user activity. Example value "G-7LZG2QW3X9".', 'tutor-sso' ),
			)
		);

	}

	/**
	 * Sanitize a Google Tag Manager container ID.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_gtm_id( $value ) {
		return $this->sanitize_tracking_id(
			$value,
			'tutor_sso_gtm_id',
			self::GTM_ID_PATTERN,
			'GTM-M69F9BL'
		);
	}

	/**
	 * Sanitize a GA4 measurement ID.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_ga_id( $value ) {
		return $this->sanitize_tracking_id(
			$value,
			'tutor_sso_ga_id',
			self::GA_ID_PATTERN,
			'G-7LZG2QW3X9'
		);
	}

	/**
	 * Validate a tracking ID against a strict pattern.
	 *
	 * This is what makes the ID fields safe for any administrator to edit while
	 * the raw snippet field is not: the stored value can only ever be uppercase
	 * letters, digits and a hyphen, so it cannot close the surrounding attribute
	 * or <script> tag when interpolated into the snippet template.
	 *
	 * An invalid ID keeps the previously stored value rather than clearing it —
	 * a typo should not silently switch tracking off — and reports the problem
	 * through the Settings API notice that options-general.php renders.
	 *
	 * @param string $value   Submitted value.
	 * @param string $option  Option name, used to recover the stored value.
	 * @param string $pattern Anchored regex the ID must match.
	 * @param string $example Example ID shown in the error notice.
	 * @return string
	 */
	private function sanitize_tracking_id( $value, $option, $pattern, $example ) {
		// Uppercase so a pasted lowercase ID is corrected rather than rejected.
		$value = strtoupper( trim( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( $pattern, $value ) ) {
			add_settings_error(
				$option,
				$option . '_invalid',
				sprintf(
					/* translators: 1: submitted value, 2: example ID such as G-7LZG2QW3X9 */
					__( '"%1$s" is not a valid tracking ID, so the previous value was kept. Expected something like "%2$s".', 'tutor-sso' ),
					esc_html( $value ),
					$example
				),
				'error'
			);
			return (string) get_option( $option, '' );
		}

		return $value;
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
	 *     @type string $placeholder  Optional placeholder text.
	 *     @type string $description  Optional help text.
	 * }
	 */
	public function render_text_field( $args ) {
		$value = get_option( $args['option_name'], '' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( $args['option_name'] ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
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
	 * Render a number <input>.
	 *
	 * @param array $args {
	 *     @type string $option_name  Option name.
	 *     @type int    $default      Default value when unset.
	 *     @type int    $min          Optional minimum.
	 *     @type int    $max          Optional maximum.
	 *     @type string $description  Optional help text.
	 * }
	 */
	public function render_number_field( $args ) {
		$default = isset( $args['default'] ) ? (int) $args['default'] : 0;
		$value   = get_option( $args['option_name'], $default );
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text"%3$s%4$s step="1" />',
			esc_attr( $args['option_name'] ),
			esc_attr( $value ),
			isset( $args['min'] ) ? ' min="' . esc_attr( $args['min'] ) . '"' : '',
			isset( $args['max'] ) ? ' max="' . esc_attr( $args['max'] ) . '"' : ''
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

			<?php $this->render_shortcodes_reference(); ?>
		</div>
		<?php
	}

	// ── Shortcode reference ─────────────────────────────────────────────────────

	/**
	 * The shortcodes shipped by this plugin, for the admin reference.
	 *
	 * Extensible via the `tutor_sso_admin_shortcodes` filter so new shortcodes
	 * can self-document without editing this class.
	 *
	 * @return array<int,array> Each: { tag, title, example, description, attributes[] }.
	 */
	private function get_shortcode_reference() {
		$shortcodes = array(
			array(
				'tag'         => 'rwaq_programs',
				'title'       => __( 'Programs Catalog', 'tutor-sso' ),
				'example'     => '[rwaq_programs per_page="6" columns="3" detail_base="program" title="البرامج"]',
				'description' => __( 'Published-programs catalog with a filter sidebar (program type, organization, featured), search, sorting, active-filter chips, and AJAX infinite scroll.', 'tutor-sso' ),
				'attributes'  => array(
					'per_page'    => __( 'Programs per page. Defaults to the "Programs per page" setting above (6 if unset).', 'tutor-sso' ),
					'columns'     => __( 'Grid column count. Default: 3.', 'tutor-sso' ),
					'detail_base' => __( 'Path segment before the program slug; detail links become {site}/{detail_base}/{slug}/. Default: "program".', 'tutor-sso' ),
					'title'       => __( 'Header title shown above the catalog. Leave blank to hide the header. Default: "البرامج".', 'tutor-sso' ),
				),
			),
			array(
				'tag'         => 'tutor_enroll_button',
				'title'       => __( 'Enroll Button', 'tutor-sso' ),
				'example'     => '[tutor_enroll_button course_id="course-v1:Org+Course+Run"]',
				'description' => __( 'Enroll / unenroll button for a course. Falls back to the ACF field "openedx_course_id" on the current post when no course_id is given.', 'tutor-sso' ),
				'attributes'  => array(
					'course_id'      => __( 'edX course id. Optional if the post has an "openedx_course_id" field.', 'tutor-sso' ),
					'enroll_label'   => __( 'Override the "Enroll" button text.', 'tutor-sso' ),
					'unenroll_label' => __( 'Override the "Unenroll" button text.', 'tutor-sso' ),
					'goto_label'     => __( 'Override the "Go to Course" button text.', 'tutor-sso' ),
					'login_label'    => __( 'Override the logged-out "Log in to Enroll" text.', 'tutor-sso' ),
				),
			),
			array(
				'tag'         => 'tutor_sso_login',
				'title'       => __( 'Login / Logout Button', 'tutor-sso' ),
				'example'     => '[tutor_sso_login label="Log in with LMS"]',
				'description' => __( 'SSO login link for logged-out visitors; a logout link for logged-in users.', 'tutor-sso' ),
				'attributes'  => array(
					'label'        => __( 'Logged-out button text. Default: "Log in with LMS".', 'tutor-sso' ),
					'logout_label' => __( 'Logged-in button text. Default: "Log out".', 'tutor-sso' ),
				),
			),
			array(
				'tag'         => 'tutor_sso_start_learning',
				'title'       => __( 'Start Learning Button', 'tutor-sso' ),
				'example'     => '[tutor_sso_start_learning label="Start learning"]',
				'description' => __( 'Button linking to the LMS dashboard. Only rendered for logged-in users.', 'tutor-sso' ),
				'attributes'  => array(
					'label' => __( 'Button text. Default: "Start learning".', 'tutor-sso' ),
					'url'   => __( 'Destination URL. Defaults to the configured Course Dashboard / LMS URL.', 'tutor-sso' ),
				),
			),
			array(
				'tag'         => 'tutor_sso_email_confirm',
				'title'       => __( 'Email Confirmation Modal', 'tutor-sso' ),
				'example'     => '[tutor_sso_email_confirm title="Check your inbox"]Message body[/tutor_sso_email_confirm]',
				'description' => __( 'Hidden modal toggled by JS. Inner content (between the tags) takes precedence over the content attribute.', 'tutor-sso' ),
				'attributes'  => array(
					'title'        => __( 'Modal heading.', 'tutor-sso' ),
					'content'      => __( 'Modal body (alternative to inner content).', 'tutor-sso' ),
					'button_label' => __( 'Close button text. Default: "Close".', 'tutor-sso' ),
				),
			),
			array(
				'tag'         => 'partner_name',
				'title'       => __( 'Partner Name', 'tutor-sso' ),
				'example'     => '[partner_name]',
				'description' => __( 'Outputs the current post\'s course-partner name (ACF field "partner_name"). Requires ACF and a "course-partner" term.', 'tutor-sso' ),
				'attributes'  => array(),
			),
			array(
				'tag'         => 'partner_logo',
				'title'       => __( 'Partner Logo', 'tutor-sso' ),
				'example'     => '[partner_logo]',
				'description' => __( 'Outputs the current post\'s course-partner logo (ACF field "partner_logo"). Requires ACF and a "course-partner" term.', 'tutor-sso' ),
				'attributes'  => array(),
			),
		);

		/**
		 * Filter the shortcodes listed in the admin reference.
		 *
		 * @param array $shortcodes List of shortcode definitions.
		 */
		return apply_filters( 'tutor_sso_admin_shortcodes', $shortcodes );
	}

	/**
	 * Render the read-only "Available Shortcodes" reference with copy buttons.
	 */
	private function render_shortcodes_reference() {
		$shortcodes = $this->get_shortcode_reference();

		if ( empty( $shortcodes ) ) {
			return;
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Available Shortcodes', 'tutor-sso' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Copy a shortcode and paste it into any page, post, or widget.', 'tutor-sso' ); ?></p>

		<div class="tutor-sso-shortcodes">
			<?php foreach ( $shortcodes as $sc ) : ?>
				<?php
				$tag     = isset( $sc['tag'] ) ? (string) $sc['tag'] : '';
				$title   = isset( $sc['title'] ) ? (string) $sc['title'] : $tag;
				$example = isset( $sc['example'] ) ? (string) $sc['example'] : '[' . $tag . ']';
				$desc    = isset( $sc['description'] ) ? (string) $sc['description'] : '';
				$attrs   = ( isset( $sc['attributes'] ) && is_array( $sc['attributes'] ) ) ? $sc['attributes'] : array();
				?>
				<div class="tutor-sso-shortcode card">
					<h3 class="tutor-sso-shortcode__title"><?php echo esc_html( $title ); ?></h3>

					<?php if ( $desc ) : ?>
						<p class="tutor-sso-shortcode__desc"><?php echo esc_html( $desc ); ?></p>
					<?php endif; ?>

					<div class="tutor-sso-shortcode__code">
						<code><?php echo esc_html( $example ); ?></code>
						<button
							type="button"
							class="button button-secondary tutor-sso-copy"
							data-clipboard="<?php echo esc_attr( $example ); ?>"
						>
							<?php esc_html_e( 'Copy', 'tutor-sso' ); ?>
						</button>
					</div>

					<?php if ( ! empty( $attrs ) ) : ?>
						<table class="tutor-sso-shortcode__attrs widefat striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Attribute', 'tutor-sso' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Description', 'tutor-sso' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $attrs as $name => $attr_desc ) : ?>
									<tr>
										<td><code><?php echo esc_html( $name ); ?></code></td>
										<td><?php echo esc_html( $attr_desc ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<style>
			.tutor-sso-shortcodes { display: grid; gap: 16px; max-width: 900px; margin-top: 12px; }
			.tutor-sso-shortcode.card { padding: 16px 20px; max-width: none; }
			.tutor-sso-shortcode__title { margin: 0 0 4px; }
			.tutor-sso-shortcode__desc { margin: 0 0 12px; color: #50575e; }
			.tutor-sso-shortcode__code { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
			.tutor-sso-shortcode__code code { flex: 1 1 auto; padding: 8px 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; word-break: break-all; }
			.tutor-sso-shortcode__attrs { margin-top: 4px; }
			.tutor-sso-shortcode__attrs td:first-child { width: 160px; white-space: nowrap; }
			.tutor-sso-copy.is-copied { color: #007017; }
		</style>

		<script>
			( function () {
				document.querySelectorAll( '.tutor-sso-copy' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var text = btn.getAttribute( 'data-clipboard' ) || '';
						var done = function () {
							var original = btn.textContent;
							btn.textContent = '<?php echo esc_js( __( 'Copied!', 'tutor-sso' ) ); ?>';
							btn.classList.add( 'is-copied' );
							window.setTimeout( function () {
								btn.textContent = original;
								btn.classList.remove( 'is-copied' );
							}, 1500 );
						};

						if ( navigator.clipboard && navigator.clipboard.writeText ) {
							navigator.clipboard.writeText( text ).then( done ).catch( fallback );
						} else {
							fallback();
						}

						function fallback() {
							var ta = document.createElement( 'textarea' );
							ta.value = text;
							ta.setAttribute( 'readonly', '' );
							ta.style.position = 'absolute';
							ta.style.left = '-9999px';
							document.body.appendChild( ta );
							ta.select();
							try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
							document.body.removeChild( ta );
						}
					} );
				} );
			} )();
		</script>
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
