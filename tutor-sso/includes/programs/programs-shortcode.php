<?php
/**
 * Programs catalog: helpers, shortcode, search / sort / filters.
 *
 * Renders the published-programs catalog pulled from the LMS public API (see
 * programs-client.php): a filter sidebar (program type, organization,
 * featured) with per-option counts, a search box, a sort dropdown,
 * active-filter chips, and a grid of program cards. Page 1 is rendered
 * server-side (SEO + no-JS baseline); further pages load via AJAX on scroll,
 * and every search / sort / filter change re-queries the API via AJAX (see
 * programs-ajax.php and assets/js/programs.js).
 *
 * Usage:
 *   [rwaq_programs]                                   6 per page (default)
 *   [rwaq_programs per_page="9" columns="3"]
 *   [rwaq_programs detail_base="program"]             detail path segment
 *   [rwaq_programs title="البرامج"]                    header title (blank hides)
 *
 * Detail links are built from the program `slug` as {site}/{detail_base}/{slug}/
 * e.g. https://site.rwaq-dev.edly.io/program/ai-program/.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the (lazily enqueued) catalog assets.
 */
function programs_register_assets() {
	wp_register_style(
		'tutor-sso-programs',
		TUTOR_SSO_URL . 'assets/css/programs.css',
		array(),
		TUTOR_SSO_VERSION
	);

	// No RTL companion stylesheet: programs.css uses direction-agnostic layout
	// (grid / flexbox, logical spacing) and renders correctly in LTR and RTL.

	wp_register_script(
		'tutor-sso-programs',
		TUTOR_SSO_URL . 'assets/js/programs.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\programs_register_assets' );

/**
 * Enqueue + localize catalog assets. Called lazily by the shortcode so they
 * only load on pages that actually render the catalog.
 */
function programs_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-programs' );
	wp_enqueue_script( 'tutor-sso-programs' );

	wp_localize_script(
		'tutor-sso-programs',
		'tutorSsoPrograms',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_programs' ),
			'i18n'    => array(
				'error'        => __( 'حدث خطأ أثناء تحميل البرامج. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
				'noResults'    => __( 'لا توجد برامج مطابقة.', 'tutor-sso' ),
				/* translators: %s: number of programs found. */
				'countLabel'   => __( 'تم العثور على %s برنامجًا', 'tutor-sso' ),
				'removeFilter' => __( 'إزالة عامل التصفية', 'tutor-sso' ),
				'showLess'     => __( 'عرض أقل', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Sort options: option key => visible label. Keys map to ordering expressions in
 * programs_allowed_ordering().
 *
 * @return array<string,string>
 */
function programs_sort_options() {
	return array(
		'name_asc'  => __( 'أ–ي', 'tutor-sso' ),
		'name_desc' => __( 'ي–أ', 'tutor-sso' ),
		'newest'    => __( 'الأحدث أولًا', 'tutor-sso' ),
		'oldest'    => __( 'الأقدم أولًا', 'tutor-sso' ),
	);
}

/**
 * Default sort key (matches the design's initial "الأحدث أولًا").
 *
 * @return string
 */
function programs_default_sort() {
	return 'newest';
}

/**
 * Static program-type filter options: API slug => Arabic label.
 *
 * @return array<string,string>
 */
function programs_type_options() {
	return array(
		'MASTERS'        => __( 'ماجستير', 'tutor-sso' ),
		'MICROBACHELORS' => __( 'ميكروبكالوريوس', 'tutor-sso' ),
	);
}

/**
 * Arabic label for a program-type slug (falls back to the raw slug).
 *
 * @param string $slug Program type slug.
 * @return string
 */
function programs_type_label( $slug ) {
	$map = programs_type_options();
	return isset( $map[ $slug ] ) ? $map[ $slug ] : (string) $slug;
}

/**
 * Static featured filter options: filter value => { label, api_label }.
 *
 * The API `featured` filter takes true/false; the filters endpoint reports
 * counts keyed by the labels "Featured" / "Not Featured".
 *
 * @return array<string,array{label:string,api_label:string}>
 */
function programs_featured_options() {
	return array(
		'true'  => array(
			'label'     => __( 'مميز', 'tutor-sso' ),
			'api_label' => 'Featured',
		),
		'false' => array(
			'label'     => __( 'غير مميز', 'tutor-sso' ),
			'api_label' => 'Not Featured',
		),
	);
}

/**
 * Look up a `total_programs` count in a filters list by matching a field.
 *
 * @param array  $list  List of filter option objects.
 * @param string $key   Field to match on (e.g. 'slug', 'label').
 * @param string $value Value to match (case-insensitive).
 * @return int|null Count, or null when not found.
 */
function programs_lookup_count( $list, $key, $value ) {
	foreach ( (array) $list as $row ) {
		if ( is_array( $row ) && isset( $row[ $key ] ) && 0 === strcasecmp( (string) $row[ $key ], (string) $value ) ) {
			return isset( $row['total_programs'] ) ? (int) $row['total_programs'] : null;
		}
	}

	return null;
}

/**
 * Display label for an organization filter entry (prefers the Arabic name).
 *
 * @param array $org Organization object from the filters API.
 * @return string
 */
function programs_org_label( $org ) {
	if ( ! is_array( $org ) ) {
		return (string) $org;
	}

	foreach ( array( 'arabic_name', 'org_arabic_name', 'name', 'short_name' ) as $key ) {
		if ( ! empty( $org[ $key ] ) ) {
			return (string) $org[ $key ];
		}
	}

	return '';
}

/**
 * The API filter value for an organization entry (the `org` query param matches
 * on organization name).
 *
 * @param array $org Organization object from the filters API.
 * @return string
 */
function programs_org_value( $org ) {
	if ( ! is_array( $org ) ) {
		return (string) $org;
	}

	foreach ( array( 'name', 'short_name' ) as $key ) {
		if ( ! empty( $org[ $key ] ) ) {
			return (string) $org[ $key ];
		}
	}

	return '';
}

/**
 * Render a filter-option count badge (Arabic-Indic digits), or '' when unknown.
 *
 * @param int|null $count Count.
 * @return string
 */
function programs_count_badge( $count ) {
	if ( null === $count ) {
		return '';
	}

	return '<span class="rwaq-programs__filter-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
}

/**
 * Small inline icon set used on cards.
 *
 * @param string $name Icon name (calendar | courses).
 * @return string SVG markup.
 */
function programs_icon( $name ) {
	$icons = array(
		'calendar' => '<svg class="rwaq-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/></svg>',
		'courses'  => '<svg class="rwaq-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5.5A2 2 0 0 1 6 4h5v16H6a2 2 0 0 0-2 1.5zM20 5.5A2 2 0 0 0 18 4h-5v16h5a2 2 0 0 1 2 1.5z"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Build a detail-page URL for a program from its slug.
 *
 * @param array  $program     Raw program object from the API.
 * @param string $detail_base Path segment before the slug (default "program").
 * @return string URL, or '' when the program has no slug/key.
 */
function program_detail_url( $program, $detail_base = 'program' ) {
	$slug = isset( $program['slug'] ) ? trim( (string) $program['slug'] ) : '';

	if ( '' === $slug ) {
		// Fallback to the program key if a slug is not (yet) available.
		$slug = isset( $program['program_key'] ) ? trim( (string) $program['program_key'] ) : '';
	}

	if ( '' === $slug ) {
		return '';
	}

	$detail_base = trim( (string) $detail_base, '/' );
	$path        = '/' . ( '' !== $detail_base ? $detail_base . '/' : '' ) . rawurlencode( $slug ) . '/';

	return home_url( $path );
}

/**
 * Format a program's start date, localized (Arabic month names on RTL sites).
 *
 * @param array $program Raw program object.
 * @return string Localized date, or '' when no start date.
 */
function programs_start_date_text( $program ) {
	$start = isset( $program['start_date'] ) ? trim( (string) $program['start_date'] ) : '';

	if ( '' === $start ) {
		return '';
	}

	$ts = strtotime( $start );
	if ( ! $ts ) {
		return '';
	}

	$format = get_option( 'date_format' );

	return date_i18n( $format ? $format : 'j F Y', $ts );
}

/**
 * Render a single program card.
 *
 * Consumes org_logo / total_courses when present (added on the LMS side) and
 * degrades gracefully when they are absent.
 *
 * @param array  $program     Raw program object.
 * @param string $detail_base Detail path segment forwarded to program_detail_url().
 * @return string HTML.
 */
function programs_render_card( $program, $detail_base ) {
	$name     = isset( $program['name'] ) ? (string) $program['name'] : '';
	$image    = isset( $program['card_image'] ) ? (string) $program['card_image'] : '';
	$type     = isset( $program['program_type'] ) ? (string) $program['program_type'] : '';
	$featured = ! empty( $program['is_featured'] );
	$url        = program_detail_url( $program, $detail_base );
	$start_date = programs_start_date_text( $program );

	// Organization: prefer an Arabic name if the API provides one on the list.
	$org_name = '';
	foreach ( array( 'arabic_name', 'org_arabic_name', 'organization' ) as $key ) {
		if ( ! empty( $program[ $key ] ) ) {
			$org_name = (string) $program[ $key ];
			break;
		}
	}
	$org_logo = isset( $program['org_logo'] ) ? (string) $program['org_logo'] : '';

	// Number of courses (field may be absent until added on the LMS side).
	$total_courses = null;
	if ( isset( $program['total_courses'] ) && '' !== $program['total_courses'] ) {
		$total_courses = (int) $program['total_courses'];
	}

	ob_start();
	?>
	<article class="rwaq-program-card">
		<?php if ( $image ) : ?>
			<a class="rwaq-program-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
				<img class="rwaq-program-card__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
			</a>
		<?php endif; ?>

		<div class="rwaq-program-card__body">
			<?php if ( $org_name || $org_logo ) : ?>
				<div class="rwaq-program-card__org">
					<?php if ( $org_logo ) : ?>
						<img class="rwaq-program-card__org-logo" src="<?php echo esc_url( $org_logo ); ?>" alt="" loading="lazy" />
					<?php endif; ?>
					<?php if ( $org_name ) : ?>
						<span class="rwaq-program-card__org-name"><?php echo esc_html( $org_name ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h3 class="rwaq-program-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
			</h3>

			<ul class="rwaq-program-card__meta">
				<?php if ( '' !== $start_date ) : ?>
					<li class="rwaq-program-card__meta-item">
						<?php echo programs_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $start_date ); ?></span>
					</li>
				<?php endif; ?>
				<?php if ( null !== $total_courses ) : ?>
					<li class="rwaq-program-card__meta-item">
						<?php echo programs_icon( 'courses' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span>
							<?php
							/* translators: %s: number of courses in the program. */
							echo esc_html( sprintf( __( 'يشمل %s دورات', 'tutor-sso' ), number_format_i18n( $total_courses ) ) );
							?>
						</span>
					</li>
				<?php endif; ?>
			</ul>

			<div class="rwaq-program-card__badges">
				<?php if ( $type ) : ?>
					<span class="rwaq-program-card__type"><?php echo esc_html( programs_type_label( $type ) ); ?></span>
				<?php endif; ?>
				<?php if ( $featured ) : ?>
					<span class="rwaq-program-card__featured"><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Render a list of program cards.
 *
 * Shared by the shortcode's server-side render and the AJAX handler.
 *
 * @param array[] $programs    Program objects.
 * @param string  $detail_base Detail path segment.
 * @return string Concatenated card HTML.
 */
function programs_render_cards( $programs, $detail_base ) {
	$html = '';

	foreach ( (array) $programs as $program ) {
		$html .= programs_render_card( $program, $detail_base );
	}

	return $html;
}

/**
 * The Font Awesome "spinner" SVG used as the AJAX loader.
 *
 * @return string
 */
function programs_loader_svg() {
	return '<svg aria-hidden="true" class="e-font-icon-svg e-fas-spinner rwaq-programs__spinner" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M304 48c0 26.51-21.49 48-48 48s-48-21.49-48-48 21.49-48 48-48 48 21.49 48 48zm-48 368c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zm208-208c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zM96 256c0-26.51-21.49-48-48-48S0 229.49 0 256s21.49 48 48 48 48-21.49 48-48zm12.922 99.078c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.491-48-48-48zm294.156 0c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.49-48-48-48zM108.922 60.922c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.491-48-48-48z"></path></svg>';
}

/**
 * Render the filter sidebar (program type, organization, featured) with counts.
 *
 * @param array  $filters Filters payload from programs_fetch_filters() (may be WP_Error).
 * @param string $uid     Unique id prefix for grouping radio inputs.
 * @return string HTML.
 */
function programs_render_sidebar( $filters, $uid ) {
	$organizations = ( is_array( $filters ) && ! empty( $filters['organizations'] ) ) ? $filters['organizations'] : array();
	$type_counts   = ( is_array( $filters ) && ! empty( $filters['program_types'] ) ) ? $filters['program_types'] : array();
	$feat_counts   = ( is_array( $filters ) && ! empty( $filters['featured'] ) ) ? $filters['featured'] : array();
	$org_limit     = 4; // Show this many orgs before the "show more" toggle.

	ob_start();
	?>
	<aside class="rwaq-programs__sidebar">
		<div class="rwaq-programs__sidebar-head">
			<span class="rwaq-programs__sidebar-title"><?php echo esc_html__( 'التصفية', 'tutor-sso' ); ?></span>
			<button type="button" class="rwaq-programs__clear"><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
		</div>

		<div class="rwaq-programs__filter-group" data-filter="program_type">
			<h3 class="rwaq-programs__filter-title"><?php echo esc_html__( 'نوع البرنامج', 'tutor-sso' ); ?></h3>
			<div class="rwaq-programs__filter-options">
				<?php foreach ( programs_type_options() as $value => $label ) : ?>
					<label class="rwaq-programs__filter-option">
						<input type="checkbox" class="rwaq-programs__filter-input" value="<?php echo esc_attr( $value ); ?>" />
						<span class="rwaq-programs__filter-label"><?php echo esc_html( $label ); ?></span>
						<?php echo programs_count_badge( programs_lookup_count( $type_counts, 'slug', $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( ! empty( $organizations ) ) : ?>
			<div class="rwaq-programs__filter-group" data-filter="org">
				<h3 class="rwaq-programs__filter-title"><?php echo esc_html__( 'الجهة', 'tutor-sso' ); ?></h3>
				<div class="rwaq-programs__filter-options">
					<?php
					$index = 0;
					foreach ( $organizations as $org ) :
						$value = programs_org_value( $org );
						$label = programs_org_label( $org );
						if ( '' === $value || '' === $label ) {
							continue;
						}
						$overflow = $index >= $org_limit;
						?>
						<label class="rwaq-programs__filter-option<?php echo $overflow ? ' rwaq-programs__filter-option--overflow' : ''; ?>"<?php echo $overflow ? ' hidden' : ''; ?>>
							<input type="checkbox" class="rwaq-programs__filter-input" value="<?php echo esc_attr( $value ); ?>" />
							<span class="rwaq-programs__filter-label"><?php echo esc_html( $label ); ?></span>
							<?php echo programs_count_badge( isset( $org['total_programs'] ) ? (int) $org['total_programs'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</label>
						<?php
						$index++;
					endforeach;

					$hidden = count( $organizations ) - $org_limit;
					?>
				</div>
				<?php if ( $hidden > 0 ) : ?>
					<button type="button" class="rwaq-programs__show-more" aria-expanded="false" data-more-text="<?php echo esc_attr( sprintf( /* translators: %s: number of hidden organizations. */ __( 'عرض %s المزيد', 'tutor-sso' ), number_format_i18n( $hidden ) ) ); ?>">
						<?php
						/* translators: %s: number of hidden organizations. */
						echo esc_html( sprintf( __( 'عرض %s المزيد', 'tutor-sso' ), number_format_i18n( $hidden ) ) );
						?>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="rwaq-programs__filter-group" data-filter="featured">
			<h3 class="rwaq-programs__filter-title"><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></h3>
			<div class="rwaq-programs__filter-options">
				<?php foreach ( programs_featured_options() as $value => $opt ) : ?>
					<label class="rwaq-programs__filter-option">
						<input type="radio" class="rwaq-programs__filter-input" name="<?php echo esc_attr( $uid ); ?>-featured" value="<?php echo esc_attr( $value ); ?>" />
						<span class="rwaq-programs__filter-label"><?php echo esc_html( $opt['label'] ); ?></span>
						<?php echo programs_count_badge( programs_lookup_count( $feat_counts, 'label', $opt['api_label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
	</aside>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_programs per_page="6" columns="3" detail_base="program" title=""].
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function programs_catalog_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'per_page'    => 6,
			'columns'     => 3,
			'detail_base' => 'program',
			'title'       => __( 'البرامج', 'tutor-sso' ),
		),
		$atts,
		'rwaq_programs'
	);

	$per_page    = max( 1, (int) $atts['per_page'] );
	$columns     = max( 1, (int) $atts['columns'] );
	$detail_base = (string) $atts['detail_base'];
	$title       = (string) $atts['title'];
	$uid         = wp_unique_id( 'rwaq-programs-' );

	programs_enqueue_assets();

	// Server-render the first page with the default sort (SEO + no-JS baseline).
	$data    = programs_fetch_public( 1, $per_page, array( 'ordering' => programs_default_sort() ) );
	$filters = programs_fetch_filters();

	if ( is_wp_error( $data ) ) {
		return '<div class="rwaq-programs rwaq-programs--error">' . esc_html( $data->get_error_message() ) . '</div>';
	}

	$programs  = $data['results'];
	$total     = isset( $data['pagination']['count'] ) ? (int) $data['pagination']['count'] : count( $programs );
	$num_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
	$has_more  = $num_pages > 1;

	ob_start();
	?>
	<div
		class="rwaq-programs"
		data-per-page="<?php echo esc_attr( $per_page ); ?>"
		data-columns="<?php echo esc_attr( $columns ); ?>"
		data-detail-base="<?php echo esc_attr( $detail_base ); ?>"
		data-default-sort="<?php echo esc_attr( programs_default_sort() ); ?>"
		data-page="1"
		data-has-more="<?php echo $has_more ? 'true' : 'false'; ?>"
	>
		<div class="rwaq-programs__overlay" hidden>
			<?php echo programs_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php if ( '' !== $title ) : ?>
			<div class="rwaq-programs__header">
				<h2 class="rwaq-programs__title"><?php echo esc_html( $title ); ?></h2>
				<span class="rwaq-programs__total-badge">
					<span class="rwaq-programs__total-count" data-total-count><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<?php echo esc_html__( 'برنامجًا', 'tutor-sso' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="rwaq-programs__searchbar">
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-search"><?php echo esc_html__( 'ابحث في البرامج', 'tutor-sso' ); ?></label>
			<input
				type="search"
				id="<?php echo esc_attr( $uid ); ?>-search"
				class="rwaq-programs__search-input"
				placeholder="<?php echo esc_attr__( 'ابحث باسم دورة أو برنامج أو في وصف البرنامج…', 'tutor-sso' ); ?>"
				autocomplete="off"
			/>
		</div>

		<div class="rwaq-programs__layout">
			<?php echo programs_render_sidebar( $filters, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="rwaq-programs__main">
				<div class="rwaq-programs__controls">
					<div class="rwaq-programs__sort">
						<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-sort"><?php echo esc_html__( 'ترتيب البرامج', 'tutor-sso' ); ?></label>
						<span class="rwaq-programs__sort-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
						<select id="<?php echo esc_attr( $uid ); ?>-sort" class="rwaq-programs__sort-select">
							<?php foreach ( programs_sort_options() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, programs_default_sort() ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="rwaq-programs__result-count" data-result-count>
						<?php
						/* translators: %s: number of programs found. */
						echo esc_html( sprintf( __( 'تم العثور على %s برنامجًا', 'tutor-sso' ), number_format_i18n( $total ) ) );
						?>
					</div>
				</div>

				<div class="rwaq-programs__chips" aria-live="polite"></div>

				<div class="rwaq-programs__grid" style="--rwaq-programs-columns: <?php echo esc_attr( $columns ); ?>;" aria-live="polite" aria-busy="false">
					<?php echo programs_render_cards( $programs, $detail_base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="rwaq-programs__status" role="status"><?php echo empty( $programs ) ? esc_html__( 'لا توجد برامج مطابقة.', 'tutor-sso' ) : ''; ?></div>

				<div class="rwaq-programs__loader" hidden>
					<?php echo programs_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="rwaq-programs__sentinel" aria-hidden="true"></div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_programs', __NAMESPACE__ . '\\programs_catalog_shortcode' );
