<?php
/**
 * Courses catalog: assets, shortcode, search / sort / organization filter.
 *
 * Card / search / sort UI follow the programs catalog; the organization filter
 * follows the blogs category dropdown (multi-select + Apply/Clear + chips) with
 * an added in-dropdown search box to filter a long organization list. Page 1 is
 * server-rendered; further pages + every search / sort / filter change go via
 * AJAX (see courses-ajax.php and assets/js/courses.js). Data comes from
 * courses-client.php (placeholder until the real APIs are wired).
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
				'error'         => __( 'حدث خطأ أثناء تحميل الدورات. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
				'noResults'     => __( 'لا توجد دورات مطابقة.', 'tutor-sso' ),
				/* translators: %s: number of courses found. */
				'countLabel'    => __( 'تم العثور على %s دورة', 'tutor-sso' ),
				'removeFilter'  => __( 'إزالة عامل التصفية', 'tutor-sso' ),
				'noOrgsMatch'   => __( 'لا توجد جهات مطابقة', 'tutor-sso' ),
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
	return array(
		'newest'     => __( 'الأحدث أولًا', 'tutor-sso' ),
		'oldest'     => __( 'الأقدم أولًا', 'tutor-sso' ),
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
	return 'newest';
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
		'thumb'   => '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>',
		'search'  => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M9.16667 15.8333C12.8486 15.8333 15.8333 12.8486 15.8333 9.16667C15.8333 5.48477 12.8486 2.5 9.16667 2.5C5.48477 2.5 2.5 5.48477 2.5 9.16667C2.5 12.8486 5.48477 15.8333 9.16667 15.8333Z" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5001 17.5L13.9167 13.9167" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'caret'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
		'check'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>',
		'close'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.08859 4.21569L4.14645 4.14645C4.32001 3.97288 4.58944 3.9536 4.78431 4.08859L4.85355 4.14645L10 9.293L15.1464 4.14645C15.32 3.97288 15.5894 3.9536 15.7843 4.08859L15.8536 4.14645C16.0271 4.32001 16.0464 4.58944 15.9114 4.78431L15.8536 4.85355L10.707 10L15.8536 15.1464C16.0271 15.32 16.0464 15.5894 15.9114 15.7843L15.8536 15.8536C15.68 16.0271 15.4106 16.0464 15.2157 15.9114L15.1464 15.8536L10 10.707L4.85355 15.8536C4.67999 16.0271 4.41056 16.0464 4.21569 15.9114L4.14645 15.8536C3.97288 15.68 3.9536 15.4106 4.08859 15.2157L4.14645 15.1464L9.293 10L4.14645 4.85355C3.97288 4.67999 3.9536 4.41056 4.08859 4.21569L4.14645 4.14645L4.08859 4.21569Z" fill="#616161"/></svg>',
		// Card meta.
		'clock'   => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.5" stroke="#616161" stroke-width="1.2"/><path d="M8 4.5V8l2.5 1.5" stroke="#616161" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'lessons' => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3.5C6.8 2.7 5.2 2.5 3.5 2.7c-.5 0-.8.4-.8.9v7.2c0 .5.4.8.9.8 1.6-.2 3.1 0 4.4.9 1.3-.9 2.8-1.1 4.4-.9.5 0 .9-.3.9-.8V3.6c0-.5-.3-.9-.8-.9C10.8 2.5 9.2 2.7 8 3.5Zm0 0v9" stroke="#616161" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
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

	$title    = isset( $course['title'] ) ? (string) $course['title'] : '';
	$image    = isset( $course['image'] ) ? (string) $course['image'] : '';
	$url      = ! empty( $course['url'] ) ? (string) $course['url'] : '#';
	$org_name = isset( $course['org_name'] ) ? (string) $course['org_name'] : '';
	$org_logo = isset( $course['org_logo'] ) ? (string) $course['org_logo'] : '';
	$duration = isset( $course['duration'] ) ? (string) $course['duration'] : '';
	$lessons  = isset( $course['lessons_count'] ) && '' !== $course['lessons_count'] ? (int) $course['lessons_count'] : null;
	$level    = isset( $course['level'] ) ? (string) $course['level'] : '';
	$featured = ! empty( $course['is_featured'] );

	ob_start();
	?>
	<article class="rwaq-course-card">
		<a class="rwaq-course-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?>
				<img class="rwaq-course-card__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
			<?php else : ?>
				<span class="rwaq-course-card__placeholder"><?php echo courses_icon( 'thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
			<?php if ( $featured ) : ?>
				<span class="rwaq-course-card__featured"><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></span>
			<?php endif; ?>
		</a>

		<div class="rwaq-course-card__body">
			<?php if ( $org_name || $org_logo ) : ?>
				<div class="rwaq-course-card__org">
					<?php if ( $org_logo ) : ?>
						<span class="rwaq-course-card__logo"><img src="<?php echo esc_url( $org_logo ); ?>" alt="" loading="lazy" /></span>
					<?php endif; ?>
					<?php if ( $org_name ) : ?>
						<span class="rwaq-course-card__org-name"><?php echo esc_html( $org_name ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h3 class="rwaq-course-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>

			<ul class="rwaq-course-card__meta">
				<?php if ( '' !== $duration ) : ?>
					<li class="rwaq-course-card__meta-item">
						<?php echo courses_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $duration ); ?></span>
					</li>
				<?php endif; ?>
				<?php if ( null !== $lessons ) : ?>
					<li class="rwaq-course-card__meta-item">
						<?php echo courses_icon( 'lessons' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span>
							<?php
							/* translators: %s: number of lessons in the course. */
							echo esc_html( sprintf( __( 'يشمل %s درسًا', 'tutor-sso' ), number_format_i18n( $lessons ) ) );
							?>
						</span>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( '' !== $level ) : ?>
				<div class="rwaq-course-card__badges">
					<span class="rwaq-course-card__level"><?php echo esc_html( $level ); ?></span>
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
 * Render the organization filter dropdown: an in-menu search box, an "All"
 * reset row, then one checkbox per organization, plus Apply / Clear. Behaviour
 * (open/close, staged selection, search, apply, chips) lives in courses.js.
 *
 * @return string HTML.
 */
function courses_render_filter() {
	$organizations = courses_organizations();
	$all_label     = __( 'جميع الجهات', 'tutor-sso' );

	ob_start();
	?>
	<div class="rwaq-courses__filter" data-filter="org">
		<button type="button" class="rwaq-courses__filter-trigger" aria-haspopup="listbox" aria-expanded="false">
			<span class="rwaq-courses__filter-caption"><?php echo esc_html__( 'الجهة', 'tutor-sso' ); ?></span>
			<span class="rwaq-courses__filter-value" data-all-label="<?php echo esc_attr( $all_label ); ?>"><?php echo esc_html( $all_label ); ?></span>
			<span class="rwaq-courses__filter-chevron" aria-hidden="true"><?php echo courses_icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</button>

		<div class="rwaq-courses__filter-menu" role="listbox" aria-multiselectable="true">
			<div class="rwaq-courses__filter-search">
				<span class="rwaq-courses__filter-search-icon" aria-hidden="true"><?php echo courses_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<input type="search" class="rwaq-courses__filter-search-input" placeholder="<?php echo esc_attr__( 'ابحث عن جهة…', 'tutor-sso' ); ?>" autocomplete="off" aria-label="<?php echo esc_attr__( 'ابحث عن جهة', 'tutor-sso' ); ?>" />
			</div>

			<div class="rwaq-courses__filter-list">
				<label class="rwaq-courses__filter-option rwaq-courses__filter-option--all">
					<input type="checkbox" class="rwaq-courses__filter-input" data-role="all" value="__all__" checked />
					<span class="rwaq-courses__filter-box" aria-hidden="true"><?php echo courses_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="rwaq-courses__filter-label"><?php echo esc_html( $all_label ); ?></span>
				</label>

				<?php foreach ( $organizations as $org ) : ?>
					<?php
					$slug = isset( $org['slug'] ) ? (string) $org['slug'] : '';
					$name = isset( $org['name'] ) ? (string) $org['name'] : '';
					if ( '' === $slug || '' === $name ) {
						continue;
					}
					?>
					<label class="rwaq-courses__filter-option" data-label="<?php echo esc_attr( $name ); ?>">
						<input type="checkbox" class="rwaq-courses__filter-input" data-role="org" value="<?php echo esc_attr( $slug ); ?>" />
						<span class="rwaq-courses__filter-box" aria-hidden="true"><?php echo courses_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="rwaq-courses__filter-label"><?php echo esc_html( $name ); ?></span>
					</label>
				<?php endforeach; ?>

				<div class="rwaq-courses__filter-empty" hidden><?php echo esc_html__( 'لا توجد جهات مطابقة', 'tutor-sso' ); ?></div>
			</div>

			<div class="rwaq-courses__filter-footer">
				<button type="button" class="rwaq-courses__filter-apply"><?php echo esc_html__( 'تطبيق', 'tutor-sso' ); ?></button>
				<button type="button" class="rwaq-courses__filter-clear"><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
			</div>
		</div>
	</div>
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
			'columns'  => 4,
			'title'    => '',
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
					<span class="rwaq-courses__total-count" data-total-count><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<?php echo esc_html__( 'دورة', 'tutor-sso' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="rwaq-courses__toolbar">
			<div class="rwaq-courses__toolbar-group">
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

				<?php echo courses_render_filter(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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

		<div class="rwaq-courses__results">
			<div class="rwaq-courses__result-count" data-result-count>
				<?php
				/* translators: %s: number of courses found. */
				echo esc_html( sprintf( __( 'تم العثور على %s دورة', 'tutor-sso' ), number_format_i18n( $total ) ) );
				?>
			</div>
			<div class="rwaq-courses__chips" aria-live="polite"></div>
			<button type="button" class="rwaq-courses__clear-all" hidden><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
		</div>

		<div class="rwaq-courses__grid" style="--rwaq-courses-columns: <?php echo esc_attr( $columns ); ?>;" aria-live="polite" aria-busy="false">
			<?php echo courses_render_cards( $courses ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<div class="rwaq-courses__status" role="status"><?php echo empty( $courses ) ? esc_html__( 'لا توجد دورات مطابقة.', 'tutor-sso' ) : ''; ?></div>

		<div class="rwaq-courses__loader" hidden>
			<?php echo courses_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<div class="rwaq-courses__sentinel" aria-hidden="true"></div>
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
		'description' => __( 'Courses catalog with an organization filter dropdown (searchable), search, sorting, active-filter chips, and AJAX infinite scroll. (Data source pending API integration.)', 'tutor-sso' ),
		'attributes'  => array(
			'per_page' => __( 'Courses per page / infinite-scroll batch. Defaults to the "Courses per page" setting (8 if unset).', 'tutor-sso' ),
			'columns'  => __( 'Grid column count. Default: 4.', 'tutor-sso' ),
			'title'    => __( 'Optional heading shown above the catalog. Leave blank to hide it.', 'tutor-sso' ),
		),
	);

	return $shortcodes;
}
add_filter( 'tutor_sso_admin_shortcodes', __NAMESPACE__ . '\\courses_register_admin_shortcode' );
