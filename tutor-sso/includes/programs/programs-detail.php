<?php

/**
 * Program detail: public detail API client + single-program shortcode.
 *
 * Companion to the catalog (see programs-client.php / programs-shortcode.php).
 * The catalog cards link to a single-program page (the `program` CPT); this
 * shortcode is placed on that page, reads the program key stored on the post
 * (ACF `program_key`, e.g. "program-v1:Rwaq+MASTERS+2026") and fetches that
 * program's full detail from the LMS public API:
 *
 *   GET /rwaq/api/programs/public/{program_key}
 *
 * Usage:
 *   [rwaq_program_detail]                                   key from current post
 *   [rwaq_program_detail program_id="program-v1:Rwaq+MASTERS+2026"]   explicit
 *   [rwaq_program_detail field="program_key"]               custom meta/ACF field
 *
 * The endpoint is public (no auth), reuses the catalog's base-URL setting and
 * transient cache, and — like the catalog — does not forward any edX cookies.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Fetch a single published program's detail from the LMS public API.
 *
 * Program keys are opaque edX-style keys (they contain "+" and ":") and are
 * used literally in the URL path, so — unlike query params — we do NOT
 * url-encode them. Reuses the catalog base URL, endpoint and cache TTL from
 * programs-client.php. Response is short-lived-cached in a transient.
 *
 * @param string $program_key Program key, e.g. "program-v1:Rwaq+MASTERS+2026".
 * @return array|\WP_Error Raw program detail object, or WP_Error on failure /
 *                         when the program is not found.
 */
function programs_fetch_detail($program_key)
{
	$base = programs_lms_base_url();

	if (empty($base)) {
		return new \WP_Error('tutor_sso_no_base', __('LMS Base URL is not configured.', 'tutor-sso'));
	}

	$program_key = trim((string) $program_key);

	if ('' === $program_key) {
		return new \WP_Error('tutor_sso_no_program_key', __('No program identifier was provided.', 'tutor-sso'));
	}

	$url = $base . PROGRAMS_PUBLIC_ENDPOINT . $program_key;

	$cache_key = 'tutor_sso_program_' . md5($url);
	$cached    = get_transient($cache_key);

	if (false !== $cached) {
		return $cached;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 20,
			'sslverify' => apply_filters('tutor_sso_ssl_verify', true),
			'headers'   => array(
				'Accept' => 'application/json',
			),
		)
	);

	if (is_wp_error($response)) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code($response);

	if (404 === $status) {
		return new \WP_Error(
			'tutor_sso_program_not_found',
			__('Program not found.', 'tutor-sso')
		);
	}

	$body = json_decode(wp_remote_retrieve_body($response), true);

	if ($status < 200 || $status >= 300 || ! is_array($body)) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf('[tutor-sso] program detail %s -> HTTP %d', $url, $status)
			);
		}

		return new \WP_Error(
			'tutor_sso_program_failed',
			__('Could not load the program from the LMS.', 'tutor-sso')
		);
	}

	set_transient($cache_key, $body, apply_filters('tutor_sso_programs_cache_ttl', PROGRAMS_CACHE_TTL));

	return $body;
}

/**
 * Resolve the program key to fetch: an explicit shortcode attribute wins,
 * otherwise the configured field is read from the current post (ACF first,
 * then post meta).
 *
 * @param array $atts Parsed shortcode attributes ({ program_id, field }).
 * @return string Program key, or '' when none can be determined.
 */
function program_detail_resolve_key($atts)
{
	$explicit = isset($atts['program_id']) ? trim((string) $atts['program_id']) : '';
	if ('' !== $explicit) {
		return $explicit;
	}

	$post_id = get_the_ID();
	if (! $post_id) {
		return '';
	}

	$field = ! empty($atts['field']) ? (string) $atts['field'] : 'program_key';

	if (function_exists('get_field')) {
		$value = get_field($field, $post_id);
		if (! empty($value)) {
			return trim((string) $value);
		}
	}

	return trim((string) get_post_meta($post_id, $field, true));
}

/**
 * Read the first non-empty scalar value among a list of candidate keys.
 *
 * @param array    $data Source object.
 * @param string[] $keys Candidate keys, in priority order.
 * @return string First non-empty value found, or ''.
 */
function program_detail_value($data, $keys)
{
	foreach ((array) $keys as $key) {
		if (isset($data[$key]) && ! is_array($data[$key]) && '' !== (string) $data[$key]) {
			return (string) $data[$key];
		}
	}

	return '';
}

/**
 * Absolutize an LMS asset path. Course image URLs come back as edX-relative
 * paths (e.g. "/asset-v1:Rwaq+013+2026+type@asset+block@medium.png"), so we
 * prepend the LMS base URL. Already-absolute URLs are returned unchanged.
 *
 * @param string $path Asset path or URL.
 * @return string Absolute URL, or '' when empty.
 */
function program_detail_asset_url($path)
{
	$path = trim((string) $path);

	if ('' === $path) {
		return '';
	}

	if (preg_match('#^https?://#i', $path)) {
		return $path;
	}

	return programs_lms_base_url() . '/' . ltrim($path, '/');
}

/**
 * Return an inline SVG icon used on the detail view, by name.
 *
 * The markup is static and trusted (no user input), so callers echo the result
 * directly without escaping.
 *
 * @param string $name One of: time, user, level, lang, certificate.
 * @return string SVG markup, or '' for an unknown name.
 */
function program_detail_icon($name)
{
	$icons = array(
		'time'        => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g clip-path="url(#rwaqpd-time)"><path d="M3.0709 12.8701C2.69399 11.9602 2.5 10.9849 2.5 10C2.5 9.01509 2.69399 8.03982 3.0709 7.12987C3.44781 6.21993 4.00026 5.39314 4.6967 4.6967C5.39314 4.00026 6.21993 3.44781 7.12987 3.0709C8.03982 2.69399 9.01509 2.5 10 2.5C10.9849 2.5 11.9602 2.69399 12.8701 3.0709C13.7801 3.44781 14.6069 4.00026 15.3033 4.6967C15.9997 5.39314 16.5522 6.21993 16.9291 7.12987C17.306 8.03982 17.5 9.01509 17.5 10C17.5 10.9849 17.306 11.9602 16.9291 12.8701C16.5522 13.7801 15.9997 14.6069 15.3033 15.3033C14.6069 15.9997 13.7801 16.5522 12.8701 16.9291C11.9602 17.306 10.9849 17.5 10 17.5C9.01509 17.5 8.03982 17.306 7.12987 16.9291C6.21993 16.5522 5.39314 15.9997 4.6967 15.3033C4.00026 14.6069 3.44781 13.7801 3.0709 12.8701Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 10L12.5 11.6667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 5.83334V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="rwaqpd-time"><rect width="20" height="20" fill="white"/></clipPath></defs></svg>',
		'user'        => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g clip-path="url(#rwaqpd-user)"><path d="M6.6665 5.83333C6.6665 6.71739 7.01769 7.56523 7.64281 8.19036C8.26794 8.81548 9.11578 9.16667 9.99984 9.16667C10.8839 9.16667 11.7317 8.81548 12.3569 8.19036C12.982 7.56523 13.3332 6.71739 13.3332 5.83333C13.3332 4.94928 12.982 4.10143 12.3569 3.47631C11.7317 2.85119 10.8839 2.5 9.99984 2.5C9.11578 2.5 8.26794 2.85119 7.64281 3.47631C7.01769 4.10143 6.6665 4.94928 6.6665 5.83333Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 17.5V15.8333C5 14.9493 5.35119 14.1014 5.97631 13.4763C6.60143 12.8512 7.44928 12.5 8.33333 12.5H11.6667C12.5507 12.5 13.3986 12.8512 14.0237 13.4763C14.6488 14.1014 15 14.9493 15 15.8333V17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="rwaqpd-user"><rect width="20" height="20" fill="white"/></clipPath></defs></svg>',
		'level'       => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g clip-path="url(#rwaqpd-level)"><path d="M2.5 10.8333C2.5 10.6123 2.5878 10.4004 2.74408 10.2441C2.90036 10.0878 3.11232 10 3.33333 10H6.66667C6.88768 10 7.09964 10.0878 7.25592 10.2441C7.4122 10.4004 7.5 10.6123 7.5 10.8333V15.8333C7.5 16.0543 7.4122 16.2663 7.25592 16.4226C7.09964 16.5789 6.88768 16.6667 6.66667 16.6667H3.33333C3.11232 16.6667 2.90036 16.5789 2.74408 16.4226C2.5878 16.2663 2.5 16.0543 2.5 15.8333V10.8333Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 7.50002C7.5 7.27901 7.5878 7.06705 7.74408 6.91076C7.90036 6.75448 8.11232 6.66669 8.33333 6.66669H11.6667C11.8877 6.66669 12.0996 6.75448 12.2559 6.91076C12.4122 7.06705 12.5 7.27901 12.5 7.50002V15.8334C12.5 16.0544 12.4122 16.2663 12.2559 16.4226C12.0996 16.5789 11.8877 16.6667 11.6667 16.6667H8.33333C8.11232 16.6667 7.90036 16.5789 7.74408 16.4226C7.5878 16.2663 7.5 16.0544 7.5 15.8334V7.50002Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 4.16665C12.5 3.94563 12.5878 3.73367 12.7441 3.57739C12.9004 3.42111 13.1123 3.33331 13.3333 3.33331H16.6667C16.8877 3.33331 17.0996 3.42111 17.2559 3.57739C17.4122 3.73367 17.5 3.94563 17.5 4.16665V15.8333C17.5 16.0543 17.4122 16.2663 17.2559 16.4226C17.0996 16.5788 16.8877 16.6666 16.6667 16.6666H13.3333C13.1123 16.6666 12.9004 16.5788 12.7441 16.4226C12.5878 16.2663 12.5 16.0543 12.5 15.8333V4.16665Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.3335 16.6667H15.0002" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="rwaqpd-level"><rect width="20" height="20" fill="white"/></clipPath></defs></svg>',
		'lang'        => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g clip-path="url(#rwaqpd-lang)"><path d="M7.50016 5.30914C7.50016 8.99081 5.63433 10.8333 3.3335 10.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.3335 5.30914H9.16683" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.1665 7.5C4.1665 9.28667 6.04317 10.7567 9.1665 10.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 16.6667L13.3333 9.16669L16.6667 16.6667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M15.9167 15H10.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.57812 2.5L6.23896 2.985" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="rwaqpd-lang"><rect width="20" height="20" fill="white"/></clipPath></defs></svg>',
		'certificate' => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><g clip-path="url(#rwaqpd-cert)"><path d="M10 12.5C10 13.163 10.2634 13.7989 10.7322 14.2678C11.2011 14.7366 11.837 15 12.5 15C13.163 15 13.7989 14.7366 14.2678 14.2678C14.7366 13.7989 15 13.163 15 12.5C15 11.837 14.7366 11.2011 14.2678 10.7322C13.7989 10.2634 13.163 10 12.5 10C11.837 10 11.2011 10.2634 10.7322 10.7322C10.2634 11.2011 10 11.837 10 12.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.8335 14.5833V18.3333L12.5002 17.0833L14.1668 18.3333V14.5833" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8.33333 15.8334H4.16667C3.72464 15.8334 3.30072 15.6578 2.98816 15.3452C2.67559 15.0326 2.5 14.6087 2.5 14.1667V5.83335C2.5 4.91669 3.25 4.16669 4.16667 4.16669H15.8333C16.2754 4.16669 16.6993 4.34228 17.0118 4.65484C17.3244 4.9674 17.5 5.39133 17.5 5.83335V14.1667C17.4997 14.459 17.4225 14.746 17.2763 14.9991C17.13 15.2521 16.9198 15.4622 16.6667 15.6084" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 7.5H15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 10H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 12.5H6.66667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g><defs><clipPath id="rwaqpd-cert"><rect width="20" height="20" fill="white"/></clipPath></defs></svg>',
	);

	return isset($icons[$name]) ? $icons[$name] : '';
}

/**
 * Build a course's detail URL. Defaults to the LMS course "about" page derived
 * from the course key; override via the `tutor_sso_program_course_url` filter.
 *
 * @param string $course_key Course key (e.g. "course-v1:Rwaq+013+2026").
 * @return string URL, or '' when no key / base URL.
 */
function program_detail_course_url($course_key)
{
	$course_key = trim((string) $course_key);
	$base       = programs_lms_base_url();

	$url = ('' !== $course_key && '' !== $base) ? $base . '/courses/' . $course_key . '/about' : '';

	/**
	 * Filter the per-course detail URL shown on the program detail page.
	 *
	 * @param string $url        Default URL (LMS about page), or ''.
	 * @param string $course_key The course key.
	 */
	return (string) apply_filters('tutor_sso_program_course_url', $url, $course_key);
}

/**
 * Convert a YouTube watch/share/short URL into a privacy-friendly embed URL.
 *
 * Accepts the common forms — watch?v=ID, youtu.be/ID, /embed/ID, /shorts/ID —
 * and returns an nocookie embed URL. Returns '' when no 11-char video id can be
 * extracted, so callers can treat "no video" and "unrecognized URL" alike.
 *
 * @param string $url Raw video URL from the API (intro_video_url).
 * @return string Embed URL, or '' when not a recognizable YouTube link.
 */
function program_detail_youtube_embed($url)
{
	$url = trim((string) $url);

	if ('' === $url) {
		return '';
	}

	$id = '';
	if (preg_match('#(?:youtu\.be/|youtube\.com/(?:embed/|shorts/|watch\?(?:.*&)?v=))([A-Za-z0-9_-]{11})#', $url, $m)) {
		$id = $m[1];
	}

	return '' !== $id ? 'https://www.youtube-nocookie.com/embed/' . $id : '';
}

/**
 * Render one course as a timeline row within the program detail.
 *
 * @param array $course One entry from the program's `courses` array.
 * @param int   $number 1-based position, shown in the node circle.
 * @return string HTML, or '' when the course has no display name.
 */
function program_detail_render_course($course, $number)
{
	if (! is_array($course)) {
		return '';
	}

	$title = program_detail_value($course, array('display_name', 'name', 'title'));

	if ('' === $title) {
		return '';
	}

	$sections = isset($course['num_sections']) && '' !== $course['num_sections'] ? (int) $course['num_sections'] : null;
	$url      = program_detail_course_url(program_detail_value($course, array('course_key')));

	ob_start();
?>
	<div class="rwaq-pd__tl-row">
		<div class="rwaq-pd__tl-node">
			<div class="rwaq-pd__tl-circle"><?php echo esc_html(number_format_i18n($number)); ?></div>
			<div class="rwaq-pd__tl-line"></div>
		</div>
		<div class="rwaq-pd__tl-card">
			<div>
				<div class="rwaq-pd__tl-title"><?php echo esc_html($title); ?></div>
				<?php if (null !== $sections) : ?>
					<div class="rwaq-pd__tl-sub">
						<?php
						/* translators: %s: number of lessons/sections in the course. */
						echo esc_html(sprintf(__('%s دروس', 'tutor-sso'), number_format_i18n($sections)));
						?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ('' !== $url) : ?>
				<a class="rwaq-pd__detail-btn" href="<?php echo esc_url($url); ?>"><?php echo esc_html__('عرض التفاصيل', 'tutor-sso'); ?></a>
			<?php endif; ?>
		</div>
	</div>
<?php
	return ob_get_clean();
}

/**
 * Build the learner "go to program" URL from the program's UUID.
 *
 * The LMS program dashboard is keyed by the program UUID (not the program key),
 * e.g. {base}/dashboard/programs/24cd8022-0a6f-4a55-9308-41027d9bf9b4. Returns
 * '' when the payload carries no UUID.
 *
 * @param array $program Program detail object.
 * @return string URL, or '' when no UUID / base URL.
 */
function program_detail_program_url($program)
{
	$uuid = program_detail_value($program, array('uuid'));
	$base = programs_lms_base_url();

	$url = ('' !== $uuid && '' !== $base) ? $base . '/dashboard/programs/' . rawurlencode($uuid) : '';

	/**
	 * Filter the learner "go to program" URL shown once enrolled.
	 *
	 * @param string $url     Default dashboard URL, or ''.
	 * @param array  $program Program detail object.
	 * @param string $uuid    Program UUID.
	 */
	return (string) apply_filters('tutor_sso_program_url', $url, $program, $uuid);
}

/**
 * Render the sidebar enrollment button for a program.
 *
 * Follows the course enroll button (render_enroll_button()): a logged-out
 * variant routes through the SSO login; once enrolled it shows a "go to
 * program" link alongside the unenroll button. State is toggled client-side via
 * assets/js/program-enroll.js (see program-enrollment-ajax.php). Reuses the
 * design's `.rwaq-pd__enroll` styling.
 *
 * @param string $program_key Program key (program-v1:Org+Program+Run).
 * @param string $program_url Learner program URL, shown once enrolled (optional).
 * @return string HTML, or '' when no key is available.
 */
function program_detail_enroll_button($program_key, $program_url = '')
{
	$program_key = trim((string) $program_key);

	if ('' === $program_key) {
		return '';
	}

	$enroll_label   = __('سجّل الآن', 'tutor-sso');
	$unenroll_label = __('إلغاء التسجيل', 'tutor-sso');
	$goto_label     = __('اذهب إلى البرنامج', 'tutor-sso');
	$program_url    = trim((string) $program_url);

	// Logged-out: route through the SSO login flow, returning to this page.
	if (! is_user_logged_in()) {
		$login_url = function_exists('tutor_sso_get_login_url')
			? tutor_sso_get_login_url()
			: wp_login_url(get_permalink() ? get_permalink() : home_url());

		return sprintf(
			'<div class="rwaq-pd__enroll-wrap"><a class="rwaq-pd__enroll rwaq-pd__enroll--login" href="%1$s">%2$s</a></div>',
			esc_url($login_url),
			esc_html($enroll_label)
		);
	}

	// Load enroll assets only when a real (logged-in) button is rendered.
	if (function_exists('tutor_sso_program_enroll_enqueue_assets')) {
		tutor_sso_program_enroll_enqueue_assets();
	}

	$status      = program_is_enrolled($program_key);
	$is_enrolled = (true === $status); // WP_Error → treat as not enrolled.

	ob_start();
?>
	<div
		class="rwaq-pd__enroll-wrap"
		data-program-id="<?php echo esc_attr($program_key); ?>"
		data-program-url="<?php echo esc_url($program_url); ?>"
		data-enroll-label="<?php echo esc_attr($enroll_label); ?>"
		data-unenroll-label="<?php echo esc_attr($unenroll_label); ?>"
		data-goto-label="<?php echo esc_attr($goto_label); ?>">
		<?php if ($is_enrolled) : ?>
			<?php if ('' !== $program_url) : ?>
				<a class="rwaq-pd__enroll rwaq-pd__enroll--goto" href="<?php echo esc_url($program_url); ?>"><?php echo esc_html($goto_label); ?></a>
			<?php endif; ?>
			<button type="button" class="rwaq-pd__enroll rwaq-pd__enroll--unenroll rwaq-program-unenroll"><?php echo esc_html($unenroll_label); ?></button>
		<?php else : ?>
			<button type="button" class="rwaq-pd__enroll rwaq-program-enroll"><?php echo esc_html($enroll_label); ?></button>
		<?php endif; ?>
		<div class="rwaq-pd__enroll-message" role="status" aria-live="polite"></div>
	</div>
<?php
	return ob_get_clean();
}

/**
 * Render the program detail view, following the program-page design.
 *
 * Every field is optional and omitted when the API does not provide it; design
 * elements the API has no data for (promo video, effort, language, certificate,
 * learner count) are left out.
 *
 * @param array  $program     Program detail object from programs_fetch_detail().
 * @param string $program_key Program key, used to wire the enroll button.
 * @return string HTML.
 */
function program_detail_render($program, $program_key = '')
{
	// Fall back to a key embedded in the detail payload when none was passed.
	if ('' === trim((string) $program_key)) {
		$program_key = program_detail_value($program, array('program_key'));
	}

	$name     = program_detail_value($program, array('name'));
	$image    = program_detail_value($program, array('card_image'));
	$type     = program_detail_value($program, array('program_type'));
	$featured = ! empty($program['is_featured']);

	$org_name = program_detail_value($program, array('organization_arabic_name', 'organization'));
	$org_logo = program_detail_value($program, array('organization_logo'));

	$lead     = program_detail_value($program, array('description'));
	$overview = program_detail_value($program, array('long_description'));

	$intro_video = program_detail_youtube_embed(program_detail_value($program, array('intro_video_url')));

	$total_courses = null;
	if (isset($program['total_courses']) && '' !== $program['total_courses']) {
		$total_courses = (int) $program['total_courses'];
	}

	$courses = (isset($program['courses']) && is_array($program['courses'])) ? $program['courses'] : array();

	ob_start();
?>
	<div class="rwaq-pd" dir="rtl">

		<div class="rwaq-pd__breadcrumb-bar">
			<nav class="rwaq-pd__breadcrumb">
				<a href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html__('الرئيسية', 'tutor-sso'); ?></a>
				<span class="rwaq-pd__sep">›</span>
				<a href="<?php echo esc_url(home_url('/programs')); ?>"><?php echo esc_html__('البرامج', 'tutor-sso'); ?></a>
				<?php if ($name) : ?>
					<span class="rwaq-pd__sep">›</span>
					<span class="rwaq-pd__current"><?php echo esc_html($name); ?></span>
				<?php endif; ?>
			</nav>
		</div>

		<div class="rwaq-pd__header-bar">
			<div class="rwaq-pd__header-inner">
				<h2 class="rwaq-pd__header-title"><?php echo esc_html($name); ?></h2>
				<div class="rwaq-pd__tabs">
					<a class="rwaq-pd__tab rwaq-pd__tab--active" href="#rwaq-pd-overview"><?php echo esc_html__('نظرة عامة', 'tutor-sso'); ?></a>
					<?php if (! empty($courses)) : ?>
						<a class="rwaq-pd__tab" href="#rwaq-pd-courses"><?php echo esc_html__('الدورات', 'tutor-sso'); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="rwaq-pd__layout">

			<main class="rwaq-pd__content" id="rwaq-pd-overview">

				<?php if ('' !== $intro_video) : ?>
					<div class="rwaq-pd__hero">
						<iframe
							class="rwaq-pd__hero-video"
							src="<?php echo esc_url($intro_video); ?>"
							title="<?php echo esc_attr($name); ?>"
							frameborder="0"
							allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
							allowfullscreen></iframe>
					</div>
				<?php endif; ?>

				<div class="rwaq-pd__badges">
					<?php if ($featured) : ?>
						<span class="rwaq-pd__badge-featured"><?php echo esc_html__('مميز', 'tutor-sso'); ?></span>
					<?php endif; ?>
					<?php if ($type) : ?>
						<span class="rwaq-pd__badge-level"><?php echo esc_html(programs_type_label($type)); ?></span>
					<?php endif; ?>
				</div>

				<?php if ($name) : ?>
					<h1 class="rwaq-pd__title"><?php echo esc_html($name); ?></h1>
				<?php endif; ?>

				<?php if ('' !== $lead) : ?>
					<p class="rwaq-pd__lead"><?php echo esc_html($lead); ?></p>
				<?php endif; ?>

				<?php if ($org_name || $org_logo) : ?>
					<div class="rwaq-pd__provider">
						<?php if ($org_logo) : ?>
							<div class="rwaq-pd__provider-logo">
								<img src="<?php echo esc_url($org_logo); ?>" alt="" loading="lazy" />
							</div>
						<?php endif; ?>
						<?php if ($org_name) : ?>
							<div class="rwaq-pd__provider-text">
								<div class="rwaq-pd__provider-small"><?php echo esc_html__('يقدمه', 'tutor-sso'); ?></div>
								<div class="rwaq-pd__provider-name"><?php echo esc_html($org_name); ?></div>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ('' !== $overview) : ?>
					<section class="rwaq-pd__section">
						<h2 class="rwaq-pd__section-title"><?php echo esc_html__('عن البرنامج', 'tutor-sso'); ?></h2>
						<div class="rwaq-pd__prose"><?php echo wp_kses_post(wpautop($overview)); ?></div>
					</section>
				<?php endif; ?>

				<?php if (! empty($courses)) : ?>
					<section class="rwaq-pd__section" id="rwaq-pd-courses">
						<h2 class="rwaq-pd__section-title">
							<?php
							if (null !== $total_courses) {
								/* translators: %s: number of courses in the program. */
								echo esc_html(sprintf(__('سلسلة مكوّنة من %s دورات', 'tutor-sso'), number_format_i18n($total_courses)));
							} else {
								echo esc_html__('الدورات', 'tutor-sso');
							}
							?>
						</h2>
						<div class="rwaq-pd__timeline">
							<?php
							$number = 1;
							foreach ($courses as $course) {
								echo program_detail_render_course($course, $number); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								$number++;
							}
							?>

							<?php // Certificate banner tail: cert icon node + partner name/logo + text. 
							?>
							<div class="rwaq-pd__tl-row">
								<div class="rwaq-pd__tl-node">
									<div class="rwaq-pd__tl-circle rwaq-pd__tl-circle--cert"><?php echo program_detail_icon('certificate'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
																								?></div>
								</div>
								<div class="rwaq-pd__cert-banner">

									<?php if ($org_logo || $org_name) : ?>
										<div class="rwaq-pd__cert-logo">
											<?php if ($org_logo) : ?>
												<img src="<?php echo esc_url($org_logo); ?>" alt="<?php echo esc_attr($org_name); ?>" loading="lazy" />
											<?php else : ?>
												<span class="rwaq-pd__cert-logo-text"><?php echo esc_html($org_name); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>
									<div class="rwaq-pd__cert-text">
										<div class="rwaq-pd__cert-title">
											<?php
											if ('' !== $org_name) {
												/* translators: 1: organization/partner name, 2: program name. */
												echo esc_html(sprintf(__('شهادة %1$s في %2$s', 'tutor-sso'), $org_name, $name));
											} else {
												/* translators: %s: program name. */
												echo esc_html(sprintf(__('شهادة إتمام %s', 'tutor-sso'), $name));
											}
											?>
										</div>
										<div class="rwaq-pd__cert-desc"><?php echo esc_html__('بعد إتمام البرنامج، أضف شهادتك إلى سيرتك الذاتية وملفك المهني لإبراز مهاراتك وخبراتك المهنية.', 'tutor-sso'); ?></div>
									</div>
								</div>
							</div>
						</div>
					</section>
				<?php endif; ?>

			</main>

			<aside class="rwaq-pd__sidebar">
				<div class="rwaq-pd__side-card">
					<div class="rwaq-pd__video-thumb">
						<?php if ($image) : ?>
							<img src="<?php echo esc_url($image); ?>" alt="" loading="lazy" />
						<?php endif; ?>
					</div>
					<div class="rwaq-pd__side-body">
						<?php
						// Cookie/session based program enroll button (see
						// program_detail_enroll_button()). Renders nothing when no
						// program key is resolvable.
						echo program_detail_enroll_button($program_key, program_detail_program_url($program)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>

						<ul class="rwaq-pd__info-list">
							<?php if (null !== $total_courses) : ?>
								<li class="rwaq-pd__info-row">
									<div class="rwaq-pd_inner-info-container">
										<span class="rwaq-pd__info-ico"><?php echo program_detail_icon('user'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
																		?></span>
										<span class="rwaq-pd__info-label"><?php echo esc_html__('الدورات', 'tutor-sso'); ?></span>
									</div>
									<span class="rwaq-pd__info-value">
										<?php
										/* translators: %s: number of courses. */
										echo esc_html(sprintf(__('%s دورات', 'tutor-sso'), number_format_i18n($total_courses)));
										?>
									</span>
								</li>
							<?php endif; ?>

							<?php // Certificate is a static placeholder — no API field yet. 
							?>
							<li class="rwaq-pd__info-row">
								<div class="rwaq-pd_inner-info-container">
									<span class="rwaq-pd__info-ico"><?php echo program_detail_icon('certificate'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
																	?></span>
									<span class="rwaq-pd__info-label"><?php echo esc_html__('الشهادة', 'tutor-sso'); ?></span>
								</div>

								<span class="rwaq-pd__info-value"><?php echo esc_html__('مشمولة', 'tutor-sso'); ?></span>
							</li>
						</ul>
					</div>
				</div>
			</aside>

		</div>
	</div>
<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_program_detail program_id="" field="program_key"].
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
/**
 * Register the program-detail front-end script. Enqueued lazily by the
 * shortcode so it only loads on pages that render the detail view.
 */
function program_detail_register_assets()
{
	wp_register_script(
		'tutor-sso-program-detail',
		TUTOR_SSO_URL . 'assets/js/program-detail.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\program_detail_register_assets');

function program_detail_shortcode($atts)
{
	$atts = shortcode_atts(
		array(
			'program_id' => '',
			'field'      => 'program_key',
		),
		$atts,
		'rwaq_program_detail'
	);

	// Reuse the catalog stylesheet (registered on wp_enqueue_scripts).
	wp_enqueue_style('tutor-sso-programs');

	// Sticky-tab scroll-spy for this view (no dependencies).
	wp_enqueue_script('tutor-sso-program-detail');

	$program_key = program_detail_resolve_key($atts);

	if ('' === $program_key) {
		return '<div class="rwaq-program-detail rwaq-program-detail--error">'
			. esc_html__('لم يتم تحديد البرنامج.', 'tutor-sso')
			. '</div>';
	}

	$program = programs_fetch_detail($program_key);

	if (is_wp_error($program)) {
		return '<div class="rwaq-program-detail rwaq-program-detail--error">'
			. esc_html($program->get_error_message())
			. '</div>';
	}

	return program_detail_render($program, $program_key);
}
add_shortcode('rwaq_program_detail', __NAMESPACE__ . '\\program_detail_shortcode');
