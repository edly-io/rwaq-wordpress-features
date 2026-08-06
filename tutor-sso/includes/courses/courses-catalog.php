<?php
/**
 * Courses catalog: assets, shortcode, search / sort / organization filter.
 *
 * The whole UI follows the programs catalog: a full-width search bar above a
 * two-column layout — a filter sidebar (organization checkboxes with counts, a
 * collapsible group, an in-group search box and a fixed-height scrolling option
 * list) beside the results (active-filter chips, result count + sort, grid). Page 1 is
 * server-rendered; further pages + every search / sort / filter change go via
 * AJAX (see courses-ajax.php and assets/js/courses.js). Data comes from the LMS
 * public courses API via courses-client.php.
 *
 * Usage:
 *   [rwaq_courses]
 *   [rwaq_courses per_page="9" columns="3"]
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the (lazily enqueued) courses catalog assets.
 */
function courses_register_assets() {
	wp_register_style(
		'tutor-sso-courses',
		TUTOR_SSO_URL . 'assets/css/courses.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	wp_register_script(
		'tutor-sso-courses',
		TUTOR_SSO_URL . 'assets/js/courses.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\courses_register_assets' );

/**
 * Enqueue + localize catalog assets. Called lazily by the shortcode.
 */
function courses_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-courses' );
	wp_enqueue_script( 'tutor-sso-courses' );

	wp_localize_script(
		'tutor-sso-courses',
		'tutorSsoCourses',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_courses' ),
			'icons'   => array(
				'removeChip' => courses_icon( 'close' ),
			),
			'i18n'    => array(
				'error'        => __( 'حدث خطأ أثناء تحميل الدورات. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
				'noResults'    => __( 'لا توجد دورات مطابقة.', 'tutor-sso' ),
				/* translators: %s: number of courses found. */
				'countLabel'   => __( 'تم العثور على %s دورة', 'tutor-sso' ),
				'removeFilter' => __( 'إزالة عامل التصفية', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Sort options: key => label (keys map to courses_allowed_ordering()).
 *
 * @return array<string,string>
 */
function courses_sort_options() {
	// Title only: the courses API accepts `ordering=title|-title` and silently
	// ignores date fields, so "newest / oldest first" would be dead options here.
	// courses_allowed_ordering() keeps the date mapping ready for when the API
	// gains it — add the two labels back at that point.
	return array(
		'title_asc'  => __( 'أ–ي', 'tutor-sso' ),
		'title_desc' => __( 'ي–أ', 'tutor-sso' ),
	);
}

/**
 * Default sort key.
 *
 * @return string
 */
function courses_default_sort() {
	// Matches the API's own default ordering (title ascending), so the toolbar
	// label always describes the order actually rendered.
	return 'title_asc';
}

/**
 * Site-wide default "courses per page", from the settings page.
 *
 * Used as the shortcode's per_page default so the page size is managed centrally.
 * Clamped to 1–48 (matching the AJAX handler's ceiling); falls back to 9.
 *
 * @return int
 */
function courses_default_per_page() {
	$value = (int) sso_option( 'courses_per_page', 8 );

	if ( $value < 1 ) {
		$value = 8;
	}

	return min( $value, 48 );
}

/**
 * Small inline icon set used across the catalog UI.
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function courses_icon( $name ) {
	$icons = array(
		'thumb'    => '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>',
		'search'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M9.16667 15.8333C12.8486 15.8333 15.8333 12.8486 15.8333 9.16667C15.8333 5.48477 12.8486 2.5 9.16667 2.5C5.48477 2.5 2.5 5.48477 2.5 9.16667C2.5 12.8486 5.48477 15.8333 9.16667 15.8333Z" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5001 17.5L13.9167 13.9167" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'caret'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
		// Header count badge (shared with the programs header).
		'bookmark' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.66675 13V3C2.66675 2.55797 2.84234 2.13405 3.1549 1.82149C3.46746 1.50893 3.89139 1.33333 4.33341 1.33333H13.3334V14.6667H4.33341C3.89139 14.6667 3.46746 14.4911 3.1549 14.1785C2.84234 13.866 2.66675 13.442 2.66675 13ZM2.66675 13C2.66675 12.558 2.84234 12.134 3.1549 11.8215C3.46746 11.5089 3.89139 11.3333 4.33341 11.3333H13.3334" stroke="#565199" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Sidebar head + collapsible group chevron (shared with the programs sidebar).
		'filter'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M14.6666 2H1.33325L6.66659 8.30667V12.6667L9.33325 14V8.30667L14.6666 2Z" stroke="#242424" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'chevron'  => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M10.5 8.75L7 5.25L3.5 8.75" stroke="#242424" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'check'    => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>',
		'close'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.08859 4.21569L4.14645 4.14645C4.32001 3.97288 4.58944 3.9536 4.78431 4.08859L4.85355 4.14645L10 9.293L15.1464 4.14645C15.32 3.97288 15.5894 3.9536 15.7843 4.08859L15.8536 4.14645C16.0271 4.32001 16.0464 4.58944 15.9114 4.78431L15.8536 4.85355L10.707 10L15.8536 15.1464C16.0271 15.32 16.0464 15.5894 15.9114 15.7843L15.8536 15.8536C15.68 16.0271 15.4106 16.0464 15.2157 15.9114L15.1464 15.8536L10 10.707L4.85355 15.8536C4.67999 16.0271 4.41056 16.0464 4.21569 15.9114L4.14645 15.8536C3.97288 15.68 3.9536 15.4106 4.08859 15.2157L4.14645 15.1464L9.293 10L4.14645 4.85355C3.97288 4.67999 3.9536 4.41056 4.08859 4.21569L4.14645 4.14645L4.08859 4.21569Z" fill="#616161"/></svg>',
		// Card meta.
		'calendar' => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="10" rx="2" stroke="#616161" stroke-width="1.2"/><path d="M5.5 2v3M10.5 2v3M2.5 7h11" stroke="#616161" stroke-width="1.2" stroke-linecap="round"/></svg>',
		'user'     => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5.5" r="2.5" stroke="#616161" stroke-width="1.2"/><path d="M3 13c0-2.2 2.2-3.5 5-3.5s5 1.3 5 3.5" stroke="#616161" stroke-width="1.2" stroke-linecap="round"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * The Font Awesome "spinner" SVG used as the AJAX loader.
 *
 * @return string
 */
function courses_loader_svg() {
	return '<svg aria-hidden="true" class="e-font-icon-svg e-fas-spinner rwaq-courses__spinner" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M304 48c0 26.51-21.49 48-48 48s-48-21.49-48-48 21.49-48 48-48 48 21.49 48 48zm-48 368c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zm208-208c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zM96 256c0-26.51-21.49-48-48-48S0 229.49 0 256s21.49 48 48 48 48-21.49 48-48zm12.922 99.078c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.491-48-48-48zm294.156 0c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.49-48-48-48zM108.922 60.922c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.491-48-48-48z"></path></svg>';
}

/**
 * Render a single course card.
 *
 * @param array $course Course row (see courses-client.php shape).
 * @return string HTML.
 */
function courses_render_card( $course ) {
	if ( ! is_array( $course ) ) {
		return '';
	}

	$title      = isset( $course['title'] ) ? (string) $course['title'] : '';
	$image      = isset( $course['image'] ) ? (string) $course['image'] : '';
	$url        = ! empty( $course['url'] ) ? (string) $course['url'] : '#';
	$org_name   = isset( $course['org_name'] ) ? (string) $course['org_name'] : '';
	$instructor = isset( $course['instructor'] ) ? (string) $course['instructor'] : '';
	$start_text = isset( $course['start_text'] ) ? (string) $course['start_text'] : '';

	ob_start();
	?>
	<article class="rwaq-course-card">
		<a class="rwaq-course-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?>
				<img class="rwaq-course-card__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
			<?php else : ?>
				<span class="rwaq-course-card__placeholder"><?php echo courses_icon( 'thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</a>

		<div class="rwaq-course-card__body">
			<h3 class="rwaq-course-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>

			<ul class="rwaq-course-card__meta">
				<?php if ( '' !== $instructor ) : ?>
					<li class="rwaq-course-card__meta-item">
						<?php echo courses_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $instructor ); ?></span>
					</li>
				<?php endif; ?>
				<?php if ( '' !== $start_text ) : ?>
					<li class="rwaq-course-card__meta-item">
						<?php echo courses_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $start_text ); ?></span>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( '' !== $org_name ) : ?>
				<div class="rwaq-course-card__badges">
					<span class="rwaq-course-card__org"><?php echo esc_html( $org_name ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Render a list of course cards. Shared by the shortcode and the AJAX handler.
 *
 * @param array[] $courses Course rows.
 * @return string
 */
function courses_render_cards( $courses ) {
	$html = '';

	foreach ( (array) $courses as $course ) {
		$html .= courses_render_card( $course );
	}

	return $html;
}

/**
 * Render the filter sidebar: the organization group (checkboxes with course
 * counts) inside a collapsible panel, plus a search box for long lists. The
 * option list scrolls past a fixed height (see .rwaq-courses__filter-options in
 * courses.css) so the sidebar keeps the same height however many organizations
 * the API returns. Behaviour (collapse, search, apply-on-change, chips) lives in
 * courses.js.
 *
 * @return string HTML, or '' when there is nothing to filter by.
 */
function courses_render_sidebar() {
	$organizations = courses_organizations();

	// Nothing to filter by (empty catalog / API unreachable): skip the sidebar.
	if ( empty( $organizations ) ) {
		return '';
	}

	ob_start();
	?>
	<aside class="rwaq-courses__sidebar">
		<div class="rwaq-courses__sidebar-head">
			<span class="rwaq-courses__sidebar-title">
				<?php echo courses_icon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo esc_html__( 'التصفية', 'tutor-sso' ); ?>
			</span>
			<button type="button" class="rwaq-courses__clear"><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
		</div>

		<div class="rwaq-courses__filter-group" data-filter="org">
			<h3 class="rwaq-courses__filter-title" role="button" tabindex="0" aria-expanded="true">
				<span><?php echo esc_html__( 'الجهة', 'tutor-sso' ); ?></span>
				<span class="rwaq-courses__filter-chevron" aria-hidden="true"><?php echo courses_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</h3>

			<div class="rwaq-courses__filter-search">
				<span class="rwaq-courses__filter-search-icon" aria-hidden="true"><?php echo courses_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<input type="search" class="rwaq-courses__filter-search-input" placeholder="<?php echo esc_attr__( 'ابحث عن جهة…', 'tutor-sso' ); ?>" autocomplete="off" aria-label="<?php echo esc_attr__( 'ابحث عن جهة', 'tutor-sso' ); ?>" />
			</div>

			<div class="rwaq-courses__filter-options" tabindex="0">
				<?php foreach ( $organizations as $org ) : ?>
					<?php
					$slug  = isset( $org['slug'] ) ? (string) $org['slug'] : '';
					$name  = isset( $org['name'] ) ? (string) $org['name'] : '';
					$count = isset( $org['count'] ) ? (int) $org['count'] : 0;

					if ( '' === $slug || '' === $name ) {
						continue;
					}
					?>
					<label class="rwaq-courses__filter-option" data-label="<?php echo esc_attr( $name ); ?>">
						<input type="checkbox" class="rwaq-courses__filter-input" value="<?php echo esc_attr( $slug ); ?>" />
						<span class="rwaq-courses__filter-box" aria-hidden="true"><?php echo courses_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="rwaq-courses__filter-label"><?php echo esc_html( $name ); ?></span>
						<?php if ( $count > 0 ) : ?>
							<span class="rwaq-courses__filter-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>

				<div class="rwaq-courses__filter-empty" hidden><?php echo esc_html__( 'لا توجد جهات مطابقة', 'tutor-sso' ); ?></div>
			</div>
		</div>
	</aside>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_courses per_page="9" columns="3"].
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function courses_catalog_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			// Default to the site-wide "Courses per page" setting; an explicit
			// per_page="…" attribute still overrides it per instance.
			'per_page' => courses_default_per_page(),
			'columns'  => 3,
			// Shown by default (like the programs catalog); title="" hides the
			// heading and its count badge.
			'title'    => __( 'الدورات', 'tutor-sso' ),
		),
		$atts,
		'rwaq_courses'
	);

	$per_page = max( 1, (int) $atts['per_page'] );
	$columns  = max( 1, (int) $atts['columns'] );
	$title    = (string) $atts['title'];
	$uid      = wp_unique_id( 'rwaq-courses-' );

	courses_enqueue_assets();

	$data = courses_fetch(
		1,
		$per_page,
		array( 'ordering' => courses_default_sort() )
	);

	$courses   = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];
	$has_more  = $num_pages > 1;
	$error     = isset( $data['error'] ) ? (string) $data['error'] : '';

	// An API failure shows the generic AJAX error copy (already translated) rather
	// than the raw WP_Error text, and never advertises more pages to load.
	if ( '' !== $error ) {
		$has_more = false;
	}

	$sort_options  = courses_sort_options();
	$default_sort  = courses_default_sort();
	$default_label = isset( $sort_options[ $default_sort ] ) ? $sort_options[ $default_sort ] : '';

	ob_start();
	?>
	<div
		class="rwaq-courses"
		data-per-page="<?php echo esc_attr( $per_page ); ?>"
		data-columns="<?php echo esc_attr( $columns ); ?>"
		data-default-sort="<?php echo esc_attr( $default_sort ); ?>"
		data-page="1"
		data-has-more="<?php echo $has_more ? 'true' : 'false'; ?>"
	>
		<div class="rwaq-courses__overlay" hidden>
			<?php echo courses_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php if ( '' !== $title ) : ?>
			<div class="rwaq-courses__header">
				<h2 class="rwaq-courses__title"><?php echo esc_html( $title ); ?></h2>
				<span class="rwaq-courses__total-badge">
					<?php echo courses_icon( 'bookmark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="rwaq-courses__total-count" data-total-count><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<?php echo esc_html__( 'دورات', 'tutor-sso' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="rwaq-courses__searchbar" role="search">
			<span class="rwaq-courses__search-icon" aria-hidden="true"><?php echo courses_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-search"><?php echo esc_html__( 'ابحث في الدورات', 'tutor-sso' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $uid ); ?>-search"
				class="rwaq-courses__search-input"
				placeholder="<?php echo esc_attr__( 'ابحث عن دورة…', 'tutor-sso' ); ?>"
				autocomplete="off"
			/>
		</div>

		<div class="rwaq-courses__layout">
			<?php echo courses_render_sidebar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="rwaq-courses__main">
				<div class="rwaq-courses__chips" aria-live="polite"></div>

				<div class="rwaq-courses__controls">
					<div class="rwaq-courses__result-count" data-result-count>
						<?php
						/* translators: %s: number of courses found. */
						echo esc_html( sprintf( __( 'تم العثور على %s دورة', 'tutor-sso' ), number_format_i18n( $total ) ) );
						?>
					</div>

					<div class="rwaq-courses__sort">
						<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-sort"><?php echo esc_html__( 'ترتيب الدورات', 'tutor-sso' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-sort" class="rwaq-courses__sort-select">
							<?php foreach ( $sort_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_sort ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="rwaq-courses__sort-trigger" aria-haspopup="listbox" aria-expanded="false">
							<span class="rwaq-courses__sort-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
							<span class="rwaq-courses__sort-value"><?php echo esc_html( $default_label ); ?></span>
							<span class="rwaq-courses__sort-chevron" aria-hidden="true"><?php echo courses_icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</button>
						<div class="rwaq-courses__sort-menu" role="listbox"></div>
					</div>
				</div>

				<div class="rwaq-courses__grid" style="--rwaq-courses-columns: <?php echo esc_attr( $columns ); ?>;" aria-live="polite" aria-busy="false">
					<?php echo courses_render_cards( $courses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="rwaq-courses__status" role="status">
					<?php
					if ( '' !== $error ) {
						echo esc_html__( 'حدث خطأ أثناء تحميل الدورات. يرجى المحاولة مرة أخرى.', 'tutor-sso' );
					} elseif ( empty( $courses ) ) {
						echo esc_html__( 'لا توجد دورات مطابقة.', 'tutor-sso' );
					}
					?>
				</div>

				<div class="rwaq-courses__loader" hidden>
					<?php echo courses_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="rwaq-courses__sentinel" aria-hidden="true"></div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_courses', __NAMESPACE__ . '\\courses_catalog_shortcode' );

/**
 * Document the [rwaq_courses] shortcode in the admin "Available Shortcodes"
 * reference (Settings → Tutor LMS SSO).
 *
 * @param array $shortcodes Existing shortcode definitions.
 * @return array
 */
function courses_register_admin_shortcode( $shortcodes ) {
	$shortcodes[] = array(
		'tag'         => 'rwaq_courses',
		'title'       => __( 'Courses Catalog', 'tutor-sso' ),
		'example'     => '[rwaq_courses per_page="9" columns="3"]',
		'description' => __( 'Courses catalog pulled from the LMS public courses API, with a filter sidebar (searchable, scrollable organization list with course counts), search, sorting, active-filter chips, and AJAX infinite scroll.', 'tutor-sso' ),
		'attributes'  => array(
			'per_page' => __( 'Courses per page / infinite-scroll batch. Defaults to the "Courses per page" setting (8 if unset).', 'tutor-sso' ),
			'columns'  => __( 'Grid column count. Default: 3 (the filter sidebar takes the rest of the row).', 'tutor-sso' ),
			'title'    => __( 'Heading shown above the catalog, next to the total-courses badge. Defaults to "الدورات"; pass title="" to hide both.', 'tutor-sso' ),
		),
	);

	return $shortcodes;
}
add_filter( 'tutor_sso_admin_shortcodes', __NAMESPACE__ . '\\courses_register_admin_shortcode' );
