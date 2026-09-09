<?php
/**
 * Search bar shortcode + search results page.
 *
 * Two shortcodes and a page template:
 *
 *   [rwaq_search_bar]      the 304px search input from the design's navigation.
 *                          Place it in the header; it is a plain GET form, so it
 *                          works with no JavaScript at all.
 *   [rwaq_search_results]  the results page (Figma node 9574-75548): title row,
 *                          type toggle + sort, a card grid, and the closing CTA
 *                          band.
 *
 * A "Rwaq Search Results" page template is also registered, so a page can be
 * switched to it instead of pasting the shortcode.
 *
 * WHERE THE BAR SUBMITS
 *
 * The bar finds its target by itself: it submits to the page using the "Rwaq
 * Search Results" page template, whatever that page's slug is. The
 * Settings → Tutor LMS SSO → Search → "Search results page" field overrides
 * that when set — needed only when the results live on a page built with
 * [rwaq_search_results] rather than the template. {site}/search/ is the last
 * resort. See search_page_url().
 *
 * The query travels as `?q=`, matching the API's own parameter name.
 *
 * DATA
 *
 * One request to the LMS search endpoint returns courses, programs and both
 * totals (see search-client.php), so:
 *   - the type toggle switches between two already-rendered grids client-side,
 *     with no second request;
 *   - the sort pill navigates, because ordering has to be re-queried.
 *
 * Each type panel pages independently via infinite scroll (see search-ajax.php).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-template slug stored in the `_wp_page_template` post meta.
 */
const SEARCH_TEMPLATE = 'tutor-sso-rwaq-search';

/**
 * Query var carrying the search term. Matches the API's own `q`.
 */
const SEARCH_QUERY_VAR = 'q';

/**
 * Query var carrying the active result type.
 */
const SEARCH_TYPE_VAR = 'type';

/**
 * Add "Rwaq Search Results" to the Page Attributes → Template dropdown.
 *
 * @param array $templates Existing page templates (slug => label).
 * @return array
 */
function search_register_page_template( $templates ) {
	$templates[ SEARCH_TEMPLATE ] = __( 'Rwaq Search Results', 'tutor-sso' );
	return $templates;
}
add_filter( 'theme_page_templates', __NAMESPACE__ . '\\search_register_page_template' );

/**
 * Whether the current main request is a page using the Search template.
 *
 * @return bool
 */
function search_is_page_template() {
	return is_singular() && SEARCH_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

/**
 * Load the plugin's template file when the Search template is selected.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function search_load_page_template( $template ) {
	if ( search_is_page_template() ) {
		$file = TUTOR_SSO_PATH . 'templates/page-rwaq-search.php';
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\search_load_page_template' );

/**
 * Register the (lazily enqueued) search assets.
 */
function search_register_assets() {
	wp_register_style(
		'tutor-sso-search',
		TUTOR_SSO_URL . 'assets/css/search.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// Type toggle + sort menu. Vanilla JS; the page works without it.
	wp_register_script(
		'tutor-sso-search',
		TUTOR_SSO_URL . 'assets/js/search.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\search_register_assets' );

/**
 * Find the page using the "Rwaq Search Results" template, if there is one.
 *
 * This is what lets the search bar work whatever the page's slug is, without
 * anyone filling in a setting. The lookup is one meta query, cached for a day —
 * the answer only changes when a page's template is reassigned.
 *
 * Only pages on the "Rwaq Search Results" template are considered, and when
 * several are, the lowest ID wins. Note this resolves a URL for the bar's form
 * `action`; nothing is ever redirected.
 *
 * @return string Permalink, or '' when no page uses the template.
 */
function search_find_template_page() {
	$cached = get_transient( 'tutor_sso_search_page_id' );

	if ( false !== $cached ) {
		// 0 is cached too, so a site with no such page does not re-query on
		// every render.
		return $cached ? (string) get_permalink( (int) $cached ) : '';
	}

	$ids = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			// Oldest first, deliberately. get_posts() would otherwise default to
			// date DESC, so publishing a second page on this template — a staging
			// copy, say — would silently move every search bar on the site to it.
			// Ordering by ID keeps the first page created the winner for good.
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_wp_page_template',
					'value' => SEARCH_TEMPLATE,
				),
			),
		)
	);

	$id = ! empty( $ids ) ? (int) $ids[0] : 0;

	set_transient( 'tutor_sso_search_page_id', $id, DAY_IN_SECONDS );

	return $id ? (string) get_permalink( $id ) : '';
}

/**
 * Drop the cached search-page lookup when a page is saved or deleted.
 *
 * Assigning the template to a different page has to take effect immediately, or
 * the search bar would keep pointing at the old one for up to a day.
 *
 * @param int $post_id Post ID.
 */
function search_flush_page_cache( $post_id ) {
	if ( 'page' === get_post_type( $post_id ) ) {
		delete_transient( 'tutor_sso_search_page_id' );
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\search_flush_page_cache' );
add_action( 'deleted_post', __NAMESPACE__ . '\\search_flush_page_cache' );

/**
 * The URL the search bar submits to.
 *
 * Resolution order, most explicit first:
 *
 *   1. The "Search results page" setting, when filled in.
 *   2. The page using the "Rwaq Search Results" template — so any slug works
 *      with no configuration at all.
 *   3. {site}/search/, as a last resort.
 *
 * @return string
 */
function search_page_url() {
	$url = trim( (string) sso_option( 'search_page_url' ) );

	if ( '' === $url ) {
		$url = search_find_template_page();
	}

	if ( '' === $url ) {
		$url = home_url( '/search/' );
	}

	/**
	 * Filter the search results page URL.
	 *
	 * @param string $url Resolved URL.
	 */
	return (string) apply_filters( 'tutor_sso_search_page_url', $url );
}

/**
 * Small inline icon set. Static, trusted markup — echoed without escaping.
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function search_icon( $name ) {
	$icons = array(
		// The design's 19px glyph inside a 24px box, as on the partners toolbar.
		'search'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 0 4.55 13.46l4.24 4.24a1 1 0 0 0 1.42-1.42l-4.24-4.24A7.5 7.5 0 0 0 10.5 3Zm0 2a5.5 5.5 0 1 1 0 11 5.5 5.5 0 0 1 0-11Z" fill="#707070"/></svg>',
		'chevron' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#242424" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Shortcode: [rwaq_search_bar].
 *
 * The navigation search input. A plain GET form pointed at the results page, so
 * submitting works with JavaScript disabled and the browser handles Enter.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function search_bar_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'placeholder' => __( 'ماذا تريد أن تتعلم؟', 'tutor-sso' ),
			'action'      => '',
		),
		$atts,
		'rwaq_search_bar'
	);

	wp_enqueue_style( 'tutor-sso-search' );

	$action = '' !== $atts['action'] ? $atts['action'] : search_page_url();
	$uid    = wp_unique_id( 'rwaq-search-' );

	// Pre-fill with the current term, so the bar still shows what was searched
	// after landing on the results page.
	$current = isset( $_GET[ SEARCH_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ SEARCH_QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	ob_start();
	?>
	<form class="rwaq-searchbar" role="search" method="get" action="<?php echo esc_url( $action ); ?>" dir="rtl">
		<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $atts['placeholder'] ); ?></label>
		<input
			type="search"
			id="<?php echo esc_attr( $uid ); ?>"
			class="rwaq-searchbar__input"
			name="<?php echo esc_attr( SEARCH_QUERY_VAR ); ?>"
			value="<?php echo esc_attr( $current ); ?>"
			placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
			autocomplete="off"
		/>
		<button type="submit" class="rwaq-searchbar__submit" aria-label="<?php echo esc_attr__( 'بحث', 'tutor-sso' ); ?>">
			<?php echo search_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_search_bar', __NAMESPACE__ . '\\search_bar_shortcode' );

/**
 * Build a results-page URL with one query arg replaced.
 *
 * Used by the sort menu and the no-JS type links, so each keeps the current
 * query and only changes its own parameter.
 *
 * @param array $args Query args to set.
 * @return string
 */
function search_current_url( $args = array() ) {
	$current = array(
		SEARCH_QUERY_VAR => isset( $_GET[ SEARCH_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ SEARCH_QUERY_VAR ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		SEARCH_TYPE_VAR  => isset( $_GET[ SEARCH_TYPE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ SEARCH_TYPE_VAR ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
		'ordering'       => isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
	);

	$merged = array_filter( array_merge( $current, $args ), 'strlen' );

	return add_query_arg( $merged, search_page_url() );
}

/**
 * Render the title row: the heading with the query, the "browse also" links and
 * the total.
 *
 * @param array $data Search results (see search_fetch()).
 * @return string HTML.
 */
function search_render_title( $data ) {
	$query = isset( $data['query'] ) ? (string) $data['query'] : '';
	$total = isset( $data['total'] ) ? (int) $data['total'] : 0;

	ob_start();
	?>
	<div class="rwaq-search__titlerow">
		<h1 class="rwaq-search__title">
			<?php echo esc_html__( 'نتائج البحث عن', 'tutor-sso' ); ?><span class="rwaq-search__query">(<?php echo esc_html( $query ); ?>)</span>
		</h1>

		<div class="rwaq-search__subrow">
			<p class="rwaq-search__total">
				<?php
				/* translators: %s: number of results. */
				echo esc_html( sprintf( __( '%s نتيجة', 'tutor-sso' ), number_format_i18n( $total ) ) );
				?>
			</p>

			<p class="rwaq-search__also">
				<span class="rwaq-search__also-label"><?php echo esc_html__( 'تصفح أيضًا:', 'tutor-sso' ); ?></span>
				<a class="rwaq-search__also-link" href="<?php echo esc_url( search_archive_url( 'courses' ) ); ?>"><?php echo esc_html__( 'جميع الدورات', 'tutor-sso' ); ?></a>
				<span class="rwaq-search__also-dot" aria-hidden="true"></span>
				<a class="rwaq-search__also-link" href="<?php echo esc_url( search_archive_url( 'programs' ) ); ?>"><?php echo esc_html__( 'جميع البرامج', 'tutor-sso' ); ?></a>
			</p>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The full-catalog URL for a result type, used by the "browse also" links and
 * the CTA buttons.
 *
 * Prefers the post type's own archive link so it follows whatever permalink base
 * the site uses, and falls back to a conventional path.
 *
 * @param string $type 'courses' | 'programs'.
 * @return string
 */
function search_archive_url( $type ) {
	if ( 'programs' === $type ) {
		$post_type = function_exists( __NAMESPACE__ . '\\program_single_post_type' ) ? program_single_post_type() : 'program';
		$fallback  = home_url( '/programs/' );
	} else {
		$post_type = 'course';
		$fallback  = home_url( '/courses/' );
	}

	$url = get_post_type_archive_link( $post_type );

	/**
	 * Filter a full-catalog URL used on the search page.
	 *
	 * @param string $url  Archive link, or the conventional fallback.
	 * @param string $type 'courses' | 'programs'.
	 */
	return (string) apply_filters( 'tutor_sso_search_archive_url', $url ? $url : $fallback, $type );
}

/**
 * Render the toolbar: the type toggle and the sort pill.
 *
 * Source order puts the toggle first so that, under RTL, it sits at the right
 * edge and the sort pill at the left — as the design shows.
 *
 * The toggles are anchors carrying `?type=`, so they work without JavaScript;
 * search.js upgrades them into an instant client-side switch, since both grids
 * are already in the page.
 *
 * @param array  $data   Search results.
 * @param string $active Active type.
 * @param string $sort   Active sort key.
 * @return string HTML.
 */
function search_render_toolbar( $data, $active, $sort ) {
	$counts = array(
		'courses'  => isset( $data['courses_count'] ) ? (int) $data['courses_count'] : 0,
		'programs' => isset( $data['programs_count'] ) ? (int) $data['programs_count'] : 0,
	);

	$labels = array(
		'courses'  => __( 'الدورات', 'tutor-sso' ),
		'programs' => __( 'البرامج', 'tutor-sso' ),
	);

	$options = search_sort_options();
	$label   = isset( $options[ $sort ] ) ? $options[ $sort ] : reset( $options );

	ob_start();
	?>
	<div class="rwaq-search__toolbar">
		<div class="rwaq-search__types" role="tablist">
			<?php foreach ( $labels as $type => $text ) : ?>
				<a
					class="rwaq-search__type<?php echo $type === $active ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( search_current_url( array( SEARCH_TYPE_VAR => $type ) ) ); ?>"
					data-type="<?php echo esc_attr( $type ); ?>"
					role="tab"
					aria-selected="<?php echo $type === $active ? 'true' : 'false'; ?>"
					aria-controls="rwaq-search-panel-<?php echo esc_attr( $type ); ?>"
				>
					<?php
					printf(
						/* translators: 1: type label, 2: result count. */
						esc_html__( '%1$s - %2$s', 'tutor-sso' ),
						esc_html( $text ),
						esc_html( number_format_i18n( $counts[ $type ] ) )
					);
					?>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="rwaq-search__sort">
			<button type="button" class="rwaq-search__pill" aria-haspopup="listbox" aria-expanded="false">
				<span class="rwaq-search__pill-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
				<span class="rwaq-search__pill-value"><?php echo esc_html( $label ); ?></span>
				<span class="rwaq-search__pill-chevron" aria-hidden="true"><?php echo search_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>

			<div class="rwaq-search__menu" role="listbox">
				<?php foreach ( $options as $key => $text ) : ?>
					<a
						class="rwaq-search__option<?php echo $key === $sort ? ' is-selected' : ''; ?>"
						href="<?php echo esc_url( search_current_url( array( 'ordering' => $key ) ) ); ?>"
						role="option"
						aria-selected="<?php echo $key === $sort ? 'true' : 'false'; ?>"
					><?php echo esc_html( $text ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render one result grid, with its own paging state for infinite scroll.
 *
 * @param array[] $items    Rows for page 1.
 * @param string  $type     'courses' | 'programs'.
 * @param bool    $active   Whether this panel is the visible one.
 * @param string  $empty    Empty-state message.
 * @param int     $total    Total matches for this type.
 * @param int     $per_page Page size.
 * @return string HTML.
 */
function search_render_grid( $items, $type, $active, $empty, $total = 0, $per_page = 12 ) {
	$items = array_values( array_filter( (array) $items, 'is_array' ) );

	// Card components read --rwaq-* from their catalog's root class.
	$root = 'programs' === $type ? 'rwaq-programs' : 'rwaq-courses';

	$has_more = count( $items ) > 0 && $per_page < $total;

	ob_start();
	?>
	<div
		class="rwaq-search__panel"
		id="rwaq-search-panel-<?php echo esc_attr( $type ); ?>"
		data-panel="<?php echo esc_attr( $type ); ?>"
		data-page="1"
		data-has-more="<?php echo $has_more ? 'true' : 'false'; ?>"
		role="tabpanel"
		<?php echo $active ? '' : 'hidden'; ?>
	>
		<?php if ( empty( $items ) ) : ?>
			<p class="rwaq-search__empty"><?php echo esc_html( $empty ); ?></p>
		<?php else : ?>
			<div class="rwaq-search__grid <?php echo esc_attr( $root ); ?>">
				<?php foreach ( $items as $item ) : ?>
					<div class="rwaq-search__cell">
						<?php
						if ( 'programs' === $type ) {
							echo programs_render_card( $item, 'program' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo courses_render_card( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="rwaq-search__loader" hidden>
				<span class="rwaq-search__spinner" aria-hidden="true"></span>
			</div>

			<?php // Tripwire for the observer. Must stay in the layout to be seen. ?>
			<div class="rwaq-search__sentinel" aria-hidden="true"></div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the closing CTA band.
 *
 * @return string HTML.
 */
function search_render_cta() {
	ob_start();
	?>
	<section class="rwaq-search__cta">
		<div class="rwaq-search__cta-text">
			<h2 class="rwaq-search__cta-title"><?php echo esc_html__( 'لم تجد ما يناسبك؟', 'tutor-sso' ); ?></h2>
			<p class="rwaq-search__cta-copy"><?php echo esc_html__( 'تصفح مكتبة رواق الكاملة وواصل رحلتك التعليمية مع مئات الدورات والبرامج في مختلف المجالات.', 'tutor-sso' ); ?></p>
		</div>

		<div class="rwaq-search__cta-actions">
			<a class="rwaq-search__btn rwaq-search__btn--primary" href="<?php echo esc_url( search_archive_url( 'courses' ) ); ?>"><?php echo esc_html__( 'عرض جميع الدورات', 'tutor-sso' ); ?></a>
			<a class="rwaq-search__btn rwaq-search__btn--subtle" href="<?php echo esc_url( search_archive_url( 'programs' ) ); ?>"><?php echo esc_html__( 'عرض جميع البرامج', 'tutor-sso' ); ?></a>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_search_results].
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function search_results_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'per_page' => search_default_per_page(),
		),
		$atts,
		'rwaq_search_results'
	);

	wp_enqueue_style( 'tutor-sso-search' );
	wp_enqueue_script( 'tutor-sso-search' );

	// The result grids are the catalogs' own card components.
	wp_enqueue_style( 'tutor-sso-courses' );
	wp_enqueue_style( 'tutor-sso-programs' );

	wp_localize_script(
		'tutor-sso-search',
		'tutorSsoSearch',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_search' ),
			'i18n'    => array( 'error' => __( 'تعذّر تحميل المزيد من النتائج.', 'tutor-sso' ) ),
		)
	);

	// phpcs:disable WordPress.Security.NonceVerification -- read-only search.
	$query = isset( $_GET[ SEARCH_QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ SEARCH_QUERY_VAR ] ) ) : '';
	$type  = isset( $_GET[ SEARCH_TYPE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ SEARCH_TYPE_VAR ] ) ) : '';
	$sort  = isset( $_GET['ordering'] ) ? sanitize_key( wp_unslash( $_GET['ordering'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification

	if ( ! in_array( $type, array( 'courses', 'programs' ), true ) ) {
		$type = 'courses';
	}

	$options = search_sort_options();
	if ( ! isset( $options[ $sort ] ) ) {
		$sort = search_default_sort();
	}

	$data = search_fetch(
		$query,
		array(
			'ordering' => $sort,
			'per_page' => (int) $atts['per_page'],
		)
	);

	$error = isset( $data['error'] ) ? (string) $data['error'] : '';

	ob_start();
	?>
	<div
		class="rwaq-search"
		dir="rtl"
		data-active-type="<?php echo esc_attr( $type ); ?>"
		data-query="<?php echo esc_attr( $query ); ?>"
		data-ordering="<?php echo esc_attr( $sort ); ?>"
		data-per-page="<?php echo esc_attr( (int) $atts['per_page'] ); ?>"
	>
		<div class="rwaq-search__inner">
			<?php echo search_render_title( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( '' !== $error ) : ?>
				<p class="rwaq-search__empty rwaq-search__empty--error"><?php echo esc_html( $error ); ?></p>
			<?php elseif ( '' === $query ) : ?>
				<p class="rwaq-search__empty"><?php echo esc_html__( 'اكتب كلمة للبحث في الدورات والبرامج.', 'tutor-sso' ); ?></p>
			<?php else : ?>
				<?php
				echo search_render_toolbar( $data, $type, $sort ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				echo search_render_grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$data['courses'],
					'courses',
					'courses' === $type,
					__( 'لا توجد دورات مطابقة.', 'tutor-sso' ),
					(int) $data['courses_count'],
					(int) $atts['per_page']
				);

				echo search_render_grid( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$data['programs'],
					'programs',
					'programs' === $type,
					__( 'لا توجد برامج مطابقة.', 'tutor-sso' ),
					(int) $data['programs_count'],
					(int) $atts['per_page']
				);
				?>
			<?php endif; ?>

			<?php echo search_render_cta(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_search_results', __NAMESPACE__ . '\\search_results_shortcode' );
