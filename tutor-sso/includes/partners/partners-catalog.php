<?php
/**
 * Partners catalog: assets, shortcode, search + sort.
 *
 * Implements the Figma "Landing page → Partners catalogue" body (node
 * 9656-89828) between the theme's header and footer: a hero band carrying the
 * heading and a live count, a toolbar (sort pill + search box), then a
 * six-per-row grid of partner logo cards. Page 1 is server-rendered;
 * every search / sort change and each further page goes via AJAX (see
 * partners-ajax.php and assets/js/partners.js). Data comes from the LMS public
 * organizations API via partners-client.php.
 *
 * Follows the courses catalog throughout — same request/cache layer, same
 * whitelisted ordering, same AJAX contract — so the two behave alike.
 *
 * Usage:
 *   [rwaq_partners]
 *   [rwaq_partners per_page="24" columns="6"]
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the (lazily enqueued) partners catalog assets.
 */
function partners_register_assets() {
	wp_register_style(
		'tutor-sso-partners',
		TUTOR_SSO_URL . 'assets/css/partners.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// partners.css uses logical properties throughout and needs no RTL
	// companion stylesheet.
	wp_register_script(
		'tutor-sso-partners',
		TUTOR_SSO_URL . 'assets/js/partners.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\partners_register_assets' );

/**
 * Enqueue + localize catalog assets. Called lazily by the shortcode.
 */
function partners_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-partners' );
	wp_enqueue_script( 'tutor-sso-partners' );

	wp_localize_script(
		'tutor-sso-partners',
		'tutorSsoPartners',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_partners' ),
			'i18n'    => array(
				'error'     => __( 'حدث خطأ أثناء تحميل الشركاء. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
				'noResults' => __( 'لا توجد شركاء مطابقون.', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Sort options: key => label (keys map to partners_allowed_ordering()).
 *
 * Name only, matching the design's toolbar. `created` is deliberately not
 * offered: whether this endpoint's OrderingFilter accepts it is unverified, and
 * a sort option that silently does nothing is worse than none.
 *
 * @return array<string,string>
 */
function partners_sort_options() {
	return array(
		'name_asc'  => __( 'أ–ي', 'tutor-sso' ),
		'name_desc' => __( 'ي–أ', 'tutor-sso' ),
	);
}

/**
 * Default sort key.
 *
 * @return string
 */
function partners_default_sort() {
	return 'name_asc';
}

/**
 * Site-wide default "partners per page", from the settings page.
 *
 * Used as the shortcode's per_page default so the page size is managed centrally
 * (Settings → Tutor LMS SSO → Partners Catalog), matching how the courses and
 * programs catalogs read theirs. 24 is the fallback: four rows of six, exactly
 * the design's grid.
 *
 * Clamped to 1–96, the same ceiling the AJAX handler enforces, so a value typed
 * into the settings screen cannot ask the API for an unbounded page.
 *
 * @return int
 */
function partners_default_per_page() {
	$value = (int) sso_option( 'partners_per_page', 24 );

	if ( $value < 1 ) {
		$value = 24;
	}

	/**
	 * Filter the partners-per-page default, after the stored setting is read.
	 *
	 * @param int $value Partners per page.
	 */
	$value = (int) apply_filters( 'tutor_sso_partners_per_page', $value );

	return min( max( 1, $value ), 96 );
}

/**
 * Small inline icon set used across the catalog UI.
 *
 * The markup is static and trusted (no user input), so callers echo the result
 * directly without escaping.
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function partners_icon( $name ) {
	$icons = array(
		// Toolbar pills — the design's 14px lucide chevron-down.
		'chevron' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#242424" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',

		// Search box — the design's 19px glyph inside a 24px box.
		'search'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 0 4.55 13.46l4.24 4.24a1 1 0 0 0 1.42-1.42l-4.24-4.24A7.5 7.5 0 0 0 10.5 3Zm0 2a5.5 5.5 0 1 1 0 11 5.5 5.5 0 0 1 0-11Z" fill="#707070"/></svg>',

		// Empty-logo placeholder, matching the other catalogs' card placeholder.
		'thumb'   => '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * The Font Awesome "spinner" SVG used as the AJAX loader. Shared shape with the
 * courses / programs catalogs.
 *
 * @return string
 */
function partners_loader_svg() {
	return '<svg aria-hidden="true" class="rwaq-partners__spinner" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M304 48c0 26.51-21.49 48-48 48s-48-21.49-48-48 21.49-48 48-48 48 21.49 48 48zm-48 368c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zm208-208c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zM96 256c0-26.51-21.49-48-48-48S0 229.49 0 256s21.49 48 48 48 48-21.49 48-48zm12.922 99.078c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.491-48-48-48zm294.156 0c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.49-48-48-48zM108.922 60.922c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.491-48-48-48z"></path></svg>';
}

/**
 * Render a single partner card.
 *
 * A 192x202 tile: a 102px logo well above the partner's Arabic name and its
 * short name.
 * The whole tile is a link when a URL is available (see partner_detail_url(),
 * which returns '' unless filtered), otherwise a plain <div> — so the markup
 * never advertises a destination it does not have.
 *
 * @param array $partner Partner row (see partners_normalize_partner()).
 * @return string HTML.
 */
function partners_render_card( $partner ) {
	if ( ! is_array( $partner ) ) {
		return '';
	}

	$name     = isset( $partner['name'] ) ? (string) $partner['name'] : '';
	$logo     = isset( $partner['logo'] ) ? (string) $partner['logo'] : '';
	$subtitle = isset( $partner['subtitle'] ) ? (string) $partner['subtitle'] : '';
	$url      = isset( $partner['url'] ) ? (string) $partner['url'] : '';

	$tag   = '' !== $url ? 'a' : 'div';
	$attrs = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';

	ob_start();
	?>
	<<?php echo $tag . $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="rwaq-partner-card">
		<span class="rwaq-partner-card__logo">
			<?php if ( '' !== $logo ) : ?>
				<img class="rwaq-partner-card__image" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" decoding="async" />
			<?php else : ?>
				<span class="rwaq-partner-card__placeholder"><?php echo partners_icon( 'thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</span>

		<span class="rwaq-partner-card__text">
			<?php if ( '' !== $name ) : ?>
				<span class="rwaq-partner-card__name"><?php echo esc_html( $name ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $subtitle ) : ?>
				<span class="rwaq-partner-card__subtitle"><?php echo esc_html( $subtitle ); ?></span>
			<?php endif; ?>
		</span>
	</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php
	return ob_get_clean();
}

/**
 * Render a list of partner cards. Shared by the shortcode and the AJAX handler.
 *
 * @param array[] $partners Partner rows.
 * @return string
 */
function partners_render_cards( $partners ) {
	$html = '';

	foreach ( (array) $partners as $partner ) {
		$html .= partners_render_card( $partner );
	}

	return $html;
}

/**
 * The hero's count line.
 *
 * The design reads "441 شريكًا في 37 دولة", but the endpoint exposes no country,
 * so only the partner count is shown rather than inventing a country total.
 *
 * @param int $total Total partners reported by the API.
 * @return string
 */
function partners_count_text( $total ) {
	/* translators: %s: number of partners. */
	return sprintf( __( '%s شريكًا', 'tutor-sso' ), number_format_i18n( $total ) );
}

/**
 * Render the toolbar: the sort pill and the search box.
 *
 * The design also shows a country filter; it is not built, because the endpoint
 * carries no country to filter on.
 *
 * Source order is deliberate. Under `dir="rtl"` the first child sits at the
 * right edge, so the search group comes first and the sort pill last — which
 * puts the sort on the left and the search on the right, as the design shows.
 *
 * @param string $uid Unique id prefix for label/control wiring.
 * @return string HTML.
 */
function partners_render_toolbar( $uid ) {
	$sort_options  = partners_sort_options();
	$default_sort  = partners_default_sort();
	$default_label = isset( $sort_options[ $default_sort ] ) ? $sort_options[ $default_sort ] : '';

	ob_start();
	?>
	<div class="rwaq-partners__toolbar">
		<div class="rwaq-partners__toolbar-end">
			<div class="rwaq-partners__searchbar" role="search">
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-search"><?php echo esc_html__( 'البحث عن الشركاء', 'tutor-sso' ); ?></label>
				<input
					type="search"
					id="<?php echo esc_attr( $uid ); ?>-search"
					class="rwaq-partners__search-input"
					placeholder="<?php echo esc_attr__( 'البحث عن الشركاء', 'tutor-sso' ); ?>"
					autocomplete="off"
				/>
				<span class="rwaq-partners__search-icon" aria-hidden="true"><?php echo partners_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
		</div>

		<div class="rwaq-partners__sort">
			<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-sort"><?php echo esc_html__( 'ترتيب الشركاء', 'tutor-sso' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-sort" class="rwaq-partners__sort-select">
				<?php foreach ( $sort_options as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_sort ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="rwaq-partners__pill" aria-haspopup="listbox" aria-expanded="false">
				<span class="rwaq-partners__pill-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
				<span class="rwaq-partners__pill-value"><?php echo esc_html( $default_label ); ?></span>
				<span class="rwaq-partners__pill-chevron" aria-hidden="true"><?php echo partners_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>
			<div class="rwaq-partners__menu" role="listbox"></div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_partners per_page="24" columns="6"].
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function partners_catalog_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'per_page' => partners_default_per_page(),
			'columns'  => 6,
			'title'    => __( 'تعرّف على شركائنا', 'tutor-sso' ),
		),
		$atts,
		'rwaq_partners'
	);

	$per_page = max( 1, (int) $atts['per_page'] );
	$columns  = max( 1, (int) $atts['columns'] );
	$title    = (string) $atts['title'];
	$uid      = wp_unique_id( 'rwaq-partners-' );

	partners_enqueue_assets();

	$data = partners_fetch(
		1,
		$per_page,
		array( 'ordering' => partners_default_sort() )
	);

	$partners  = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];
	$error     = isset( $data['error'] ) ? (string) $data['error'] : '';
	$has_more  = '' === $error && $num_pages > 1;

	ob_start();
	?>
	<div
		class="rwaq-partners"
		dir="rtl"
		data-per-page="<?php echo esc_attr( $per_page ); ?>"
		data-columns="<?php echo esc_attr( $columns ); ?>"
		data-default-sort="<?php echo esc_attr( partners_default_sort() ); ?>"
		data-page="1"
		data-has-more="<?php echo $has_more ? 'true' : 'false'; ?>"
	>
		<section class="rwaq-partners__hero">
			<div class="rwaq-partners__blobs" aria-hidden="true">
				<img class="rwaq-partners__blob rwaq-partners__blob--1" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/instructor/blob-1.svg' ); ?>" width="297" height="297" alt="" />
				<img class="rwaq-partners__blob rwaq-partners__blob--2" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/instructor/blob-2.svg' ); ?>" width="297" height="297" alt="" />
				<img class="rwaq-partners__blob rwaq-partners__blob--3" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/instructor/blob-3.svg' ); ?>" width="297" height="297" alt="" />
				<img class="rwaq-partners__blob rwaq-partners__blob--4" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/instructor/blob-4.svg' ); ?>" width="297" height="297" alt="" />
			</div>

			<div class="rwaq-partners__hero-inner">
				<?php if ( '' !== $title ) : ?>
					<h1 class="rwaq-partners__title"><?php echo esc_html( $title ); ?></h1>
				<?php endif; ?>
				<p class="rwaq-partners__count" data-result-count><?php echo esc_html( partners_count_text( $total ) ); ?></p>
			</div>
		</section>

		<section class="rwaq-partners__content">
			<div class="rwaq-partners__content-inner">
				<div class="rwaq-partners__overlay" hidden>
					<?php echo partners_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<?php echo partners_render_toolbar( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="rwaq-partners__grid" style="--rwaq-partners-columns: <?php echo esc_attr( $columns ); ?>;" aria-live="polite" aria-busy="false">
					<?php echo partners_render_cards( $partners ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="rwaq-partners__status" role="status">
					<?php
					if ( '' !== $error ) {
						echo esc_html__( 'حدث خطأ أثناء تحميل الشركاء. يرجى المحاولة مرة أخرى.', 'tutor-sso' );
					} elseif ( empty( $partners ) ) {
						echo esc_html__( 'لا توجد شركاء مطابقون.', 'tutor-sso' );
					}
					?>
				</div>

				<div class="rwaq-partners__loader" hidden>
					<?php echo partners_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<?php // Infinite-scroll tripwire. Must stay in the layout (never hidden)
				      // so it can leave and re-enter the viewport as pages append. ?>
				<div class="rwaq-partners__sentinel" aria-hidden="true"></div>
			</div>
		</section>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_partners', __NAMESPACE__ . '\\partners_catalog_shortcode' );
