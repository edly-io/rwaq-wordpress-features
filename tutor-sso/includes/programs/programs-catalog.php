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
	// The IBM Plex Sans Arabic webfont ('tutor-sso-programs-font') is registered
	// and enqueued globally in tutor-sso.php. It is listed as a dependency here so
	// it is guaranteed to load before the catalog stylesheet that uses it.
	wp_register_style(
		'tutor-sso-programs',
		TUTOR_SSO_URL . 'assets/css/programs.css',
		array( 'tutor-sso-programs-font' ),
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
			'icons'   => array(
				// SVG for the active-filter chip remove button (chips are rendered
				// client-side, so the markup is passed through to programs.js).
				'removeChip' => programs_icon( 'close' ),
			),
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
 * Site-wide default "programs per page", from the settings page.
 *
 * Used as the shortcode's per_page default (and thus by the /programs/ archive)
 * so the page size is managed centrally. Clamped to 1–48 (matching the AJAX
 * handler's ceiling); falls back to 6.
 *
 * @return int
 */
function programs_default_per_page() {
	$value = (int) sso_option( 'programs_per_page', 6 );

	if ( $value < 1 ) {
		$value = 6;
	}

	return min( $value, 48 );
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

	foreach ( array( 'organization_arabic_name', 'name', 'short_name' ) as $key ) {
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

	// The `org` query param matches the organization's actual name, so never use
	// the Arabic display name here (that is only for programs_org_label()).
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
 * Small inline icon set used across the catalog UI.
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function programs_icon( $name ) {
	$icons = array(
		// Card meta.
		'calendar' => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5.33325 1.33331V3.99998" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.6667 1.33331V3.99998" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.6667 2.66669H3.33333C2.59695 2.66669 2 3.26364 2 4.00002V13.3334C2 14.0697 2.59695 14.6667 3.33333 14.6667H12.6667C13.403 14.6667 14 14.0697 14 13.3334V4.00002C14 3.26364 13.403 2.66669 12.6667 2.66669Z" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 6.66669H14" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'courses'  => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 11.75V4.25C4 3.91848 4.1317 3.60054 4.36612 3.36612C4.60054 3.1317 4.91848 3 5.25 3H11.5C11.6326 3 11.7598 3.05268 11.8536 3.14645C11.9473 3.24021 12 3.36739 12 3.5V12.5C12 12.6326 11.9473 12.7598 11.8536 12.8536C11.7598 12.9473 11.6326 13 11.5 13H5.25C4.91848 13 4.60054 12.8683 4.36612 12.6339C4.1317 12.3995 4 12.0815 4 11.75ZM4 11.75C4 11.4185 4.1317 11.1005 4.36612 10.8661C4.60054 10.6317 4.91848 10.5 5.25 10.5H12" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Card thumbnail placeholder (when no card image).
		'thumb'    => '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>',
		// Header total badge.
		'bookmark' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.66675 13V3C2.66675 2.55797 2.84234 2.13405 3.1549 1.82149C3.46746 1.50893 3.89139 1.33333 4.33341 1.33333H13.3334V14.6667H4.33341C3.89139 14.6667 3.46746 14.4911 3.1549 14.1785C2.84234 13.866 2.66675 13.442 2.66675 13ZM2.66675 13C2.66675 12.558 2.84234 12.134 3.1549 11.8215C3.46746 11.5089 3.89139 11.3333 4.33341 11.3333H13.3334" stroke="#565199" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Search bar.
		'search'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M9.16667 15.8333C12.8486 15.8333 15.8333 12.8486 15.8333 9.16667C15.8333 5.48477 12.8486 2.5 9.16667 2.5C5.48477 2.5 2.5 5.48477 2.5 9.16667C2.5 12.8486 5.48477 15.8333 9.16667 15.8333Z" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5001 17.5L13.9167 13.9167" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Sidebar.
		'filter'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M14.6666 2H1.33325L6.66659 8.30667V12.6667L9.33325 14V8.30667L14.6666 2Z" stroke="#242424" stroke-width="1.33333" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Collapsible group chevron (points up when expanded).
		'chevron'  => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M10.5 8.75L7 5.25L3.5 8.75" stroke="#242424" stroke-width="1.16667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		// Sort dropdown chevron (points down).
		'caret'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
		// Custom checkbox check mark.
		'check'    => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>',
		// Active-filter chip remove.
		'close'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.08859 4.21569L4.14645 4.14645C4.32001 3.97288 4.58944 3.9536 4.78431 4.08859L4.85355 4.14645L10 9.293L15.1464 4.14645C15.32 3.97288 15.5894 3.9536 15.7843 4.08859L15.8536 4.14645C16.0271 4.32001 16.0464 4.58944 15.9114 4.78431L15.8536 4.85355L10.707 10L15.8536 15.1464C16.0271 15.32 16.0464 15.5894 15.9114 15.7843L15.8536 15.8536C15.68 16.0271 15.4106 16.0464 15.2157 15.9114L15.1464 15.8536L10 10.707L4.85355 15.8536C4.67999 16.0271 4.41056 16.0464 4.21569 15.9114L4.14645 15.8536C3.97288 15.68 3.9536 15.4106 4.08859 15.2157L4.14645 15.1464L9.293 10L4.14645 4.85355C3.97288 4.67999 3.9536 4.41056 4.08859 4.21569L4.14645 4.14645L4.08859 4.21569Z" fill="#616161"/></svg>',
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

	// Normalize the slug to lowercase so detail URLs are consistent
	// (e.g. "Test-Program-Cert-1" -> "test-program-cert-1").
	$slug = strtolower( $slug );

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
 * Consumes organization_logo / total_courses when present (added on the LMS
 * side) and degrades gracefully when they are absent.
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
	$org_logo = isset( $program['organization_logo'] ) ? (string) $program['organization_logo'] : '';

	// Number of courses (field may be absent until added on the LMS side).
	$total_courses = null;
	if ( isset( $program['total_courses'] ) && '' !== $program['total_courses'] ) {
		$total_courses = (int) $program['total_courses'];
	}

	ob_start();
	?>
	<article class="rwaq-program-card">
		<a class="rwaq-program-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?>
				<img class="rwaq-program-card__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
			<?php else : ?>
				<span class="rwaq-program-card__placeholder"><?php echo programs_icon( 'thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</a>

		<div class="rwaq-program-card__body">
			<?php if ( $org_name || $org_logo ) : ?>
				<div class="rwaq-program-card__org">
					<?php if ( $org_logo ) : ?>
						<span class="rwaq-program-card__logo"><img class="rwaq-program-card__org-logo" src="<?php echo esc_url( $org_logo ); ?>" alt="" loading="lazy" /></span>
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
				<?php if ( $featured ) : ?>
					<span class="rwaq-program-card__featured"><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></span>
				<?php endif; ?>
				<?php if ( $type ) : ?>
					<span class="rwaq-program-card__type"><?php echo esc_html( programs_type_label( $type ) ); ?></span>
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
	// Internal organizations never appear as a filter option (see
	// sso_is_hidden_org()); their programs are excluded from the results too.
	$organizations = array_values( array_filter( $organizations, __NAMESPACE__ . '\\sso_is_not_hidden_org' ) );
	$type_counts   = ( is_array( $filters ) && ! empty( $filters['program_types'] ) ) ? $filters['program_types'] : array();
	$feat_counts   = ( is_array( $filters ) && ! empty( $filters['featured'] ) ) ? $filters['featured'] : array();
	$org_limit     = 4; // Show this many orgs before the "show more" toggle.

	ob_start();
	?>
	<aside class="rwaq-programs__sidebar">
		<div class="rwaq-programs__sidebar-head">
			<span class="rwaq-programs__sidebar-title">
				<?php echo programs_icon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo esc_html__( 'التصفية', 'tutor-sso' ); ?>
			</span>
			<button type="button" class="rwaq-programs__clear"><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
		</div>

		<div class="rwaq-programs__filter-group" data-filter="program_type">
			<h3 class="rwaq-programs__filter-title" role="button" tabindex="0" aria-expanded="true">
				<span><?php echo esc_html__( 'نوع البرنامج', 'tutor-sso' ); ?></span>
				<span class="rwaq-programs__filter-chevron" aria-hidden="true"><?php echo programs_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</h3>
			<div class="rwaq-programs__filter-options">
				<?php foreach ( programs_type_options() as $value => $label ) : ?>
					<label class="rwaq-programs__filter-option">
						<input type="checkbox" class="rwaq-programs__filter-input" value="<?php echo esc_attr( $value ); ?>" />
						<span class="rwaq-programs__filter-box" aria-hidden="true"><?php echo programs_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="rwaq-programs__filter-label"><?php echo esc_html( $label ); ?></span>
						<?php echo programs_count_badge( programs_lookup_count( $type_counts, 'slug', $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( ! empty( $organizations ) ) : ?>
			<div class="rwaq-programs__filter-group" data-filter="org">
				<h3 class="rwaq-programs__filter-title" role="button" tabindex="0" aria-expanded="true">
					<span><?php echo esc_html__( 'الجهة', 'tutor-sso' ); ?></span>
					<span class="rwaq-programs__filter-chevron" aria-hidden="true"><?php echo programs_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</h3>
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
							<span class="rwaq-programs__filter-box" aria-hidden="true"><?php echo programs_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
			<h3 class="rwaq-programs__filter-title" role="button" tabindex="0" aria-expanded="true">
				<span><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></span>
				<span class="rwaq-programs__filter-chevron" aria-hidden="true"><?php echo programs_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</h3>
			<div class="rwaq-programs__filter-options">
				<?php foreach ( programs_featured_options() as $value => $opt ) : ?>
					<label class="rwaq-programs__filter-option">
						<input type="radio" class="rwaq-programs__filter-input" name="<?php echo esc_attr( $uid ); ?>-featured" value="<?php echo esc_attr( $value ); ?>" />
						<span class="rwaq-programs__filter-box rwaq-programs__filter-box--radio" aria-hidden="true"></span>
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
			// Default to the site-wide "Programs per page" setting; an explicit
			// per_page="…" attribute still overrides it per instance.
			'per_page'    => programs_default_per_page(),
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
					<?php echo programs_icon( 'bookmark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="rwaq-programs__total-count" data-total-count><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<?php echo esc_html__( 'برنامجًا', 'tutor-sso' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="rwaq-programs__searchbar" role="search">
			<span class="rwaq-programs__search-icon" aria-hidden="true"><?php echo programs_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
				<div class="rwaq-programs__chips" aria-live="polite"></div>

				<div class="rwaq-programs__controls">
					<div class="rwaq-programs__result-count" data-result-count>
						<?php
						/* translators: %s: number of programs found. */
						echo esc_html( sprintf( __( 'تم العثور على %s برنامجًا', 'tutor-sso' ), number_format_i18n( $total ) ) );
						?>
					</div>

					<?php
					$sort_options  = programs_sort_options();
					$default_sort  = programs_default_sort();
					$default_label = isset( $sort_options[ $default_sort ] ) ? $sort_options[ $default_sort ] : '';
					?>
					<div class="rwaq-programs__sort">
						<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-sort"><?php echo esc_html__( 'ترتيب البرامج', 'tutor-sso' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-sort" class="rwaq-programs__sort-select">
							<?php foreach ( $sort_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_sort ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="rwaq-programs__sort-trigger" aria-haspopup="listbox" aria-expanded="false">
							<span class="rwaq-programs__sort-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
							<span class="rwaq-programs__sort-value"><?php echo esc_html( $default_label ); ?></span>
							<span class="rwaq-programs__sort-chevron" aria-hidden="true"><?php echo programs_icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						</button>
						<div class="rwaq-programs__sort-menu" role="listbox"></div>
					</div>
				</div>

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
