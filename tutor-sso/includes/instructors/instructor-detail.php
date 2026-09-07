<?php
/**
 * Instructor detail: single-instructor template loader + detail render / shortcode.
 *
 * Companion to the blog and program patterns (see blog-detail.php /
 * program-single.php). 
 *
 * Only the region between the site header and footer is owned here — the theme
 * still supplies both.
 *
 * Two responsibilities live in this file:
 *
 *   1. A `single_template` filter that serves templates/single-instructor.php
 *      for single `instructor` posts, so the styled detail view is used by
 *      default with no page editing or theme work (a theme may still override
 *      with its own single-instructor.php).
 *   2. The [rwaq_instructor_detail] shortcode (used by that template) which
 *      renders the detail view for the current post.
 *
 * DATA SOURCE
 *
 * Everything on this page comes from the LMS public instructor API, fetched by
 * the numeric id in the post's `instructor_lms_id` custom field (ACF first, post
 * meta fallback) — the only field this page needs. See instructors-client.php
 * for the endpoint and the field-by-field mapping.
 *
 * instructor_detail_data() assembles the view model the renderers below consume:
 * Usage:
 *   [rwaq_instructor_detail]            render the current instructor in the loop
 *   [rwaq_instructor_detail id="123"]   render an explicit instructor by ID
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field holding the instructor's numeric id on the LMS. Every value on
 * the page is fetched with it — see instructors-client.php.
 */
const INSTRUCTOR_LMS_ID_FIELD = 'instructor_lms_id';

/**
 * Post type whose single view uses the plugin's instructor template.
 *
 * @return string
 */
function instructor_single_post_type() {
	/**
	 * Filter the post type served by the plugin's single-instructor template.
	 *
	 * @param string $post_type Default 'instructor'.
	 */
	return (string) apply_filters( 'tutor_sso_instructor_post_type', 'instructor' );
}

/**
 * Use the plugin's single-instructor.php for single instructor posts.
 *
 * Runs on the `single_template` filter, which fires with the template the theme
 * hierarchy resolved. We only intervene for the instructor post type, and we
 * defer to a theme-provided single-instructor.php when one exists.
 *
 * @param string $template Path to the template the hierarchy resolved.
 * @return string
 */
function instructor_single_template( $template ) {
	if ( ! is_singular( instructor_single_post_type() ) ) {
		return $template;
	}

	// Respect a theme-provided single-instructor.php if one exists.
	$theme_template = locate_template( array( 'single-instructor.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/single-instructor.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'single_template', __NAMESPACE__ . '\\instructor_single_template' );

/**
 * Register the (lazily enqueued) instructor detail assets.
 *
 * Depends on the IBM Plex Sans Arabic webfont ('tutor-sso-programs-font',
 * registered and enqueued globally in tutor-sso.php) so it always loads first.
 * instructor.css uses logical properties throughout and needs no RTL companion
 * stylesheet.
 */
function instructor_detail_register_assets() {
	wp_register_style(
		'tutor-sso-instructor',
		TUTOR_SSO_URL . 'assets/css/instructor.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// Tab switching + the biography modal. Vanilla JS, no dependencies.
	wp_register_script(
		'tutor-sso-instructor',
		TUTOR_SSO_URL . 'assets/js/instructor.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\instructor_detail_register_assets' );

/**
 * Enqueue the detail assets. Called lazily by the shortcode.
 */
function instructor_detail_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-instructor' );
	wp_enqueue_script( 'tutor-sso-instructor' );

	// The tab panels render the catalogs' own card components, so both catalog
	// stylesheets are needed for them to look like they do on the listings.
	// Registered by their modules on wp_enqueue_scripts, which has already run by
	// the time the shortcode renders.
	wp_enqueue_style( 'tutor-sso-courses' );
	wp_enqueue_style( 'tutor-sso-programs' );
}

/**
 * URL of a bundled instructor asset.
 *
 * @param string $file File name inside assets/images/instructor/.
 * @return string
 */
function instructor_asset( $file ) {
	return TUTOR_SSO_URL . 'assets/images/instructor/' . $file;
}

/**
 * Return an inline SVG icon used on the instructor detail view, by name.
 *
 * Only the breadcrumb separator is drawn here — the card components bring their
 * own icons. The larger artwork (decorative blobs, stat icons) is bundled under
 * assets/images/instructor/ and referenced with instructor_asset() instead.
 *
 * The markup is static and trusted (no user input), so callers echo the result
 * directly without escaping.
 *
 * @param string $name One of: chevron, close.
 * @return string SVG markup, or '' for an unknown name.
 */
function instructor_icon( $name ) {
	$icons = array(
		// Breadcrumb separator — the design's 12px chevron, mirrored to point
		// toward the start of the line as it does in the mockup.
		'chevron'  => '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 3L4.5 6L7.5 9" stroke="#242424" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

		// Biography modal close button. The design strokes it white on the dark
		// header; currentColor lets the button carry a hover state.
		'close'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',

	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Read a custom field: ACF's get_field() first (so return-format handling and
 * field aliases work), then a raw post meta fallback.
 *
 * Mirrors ambassadors_field() — kept local so the two modules stay independent.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @return mixed Field value (string, array or attachment ID), or ''.
 */
function instructor_field( $post_id, $field ) {
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $field, $post_id );
		if ( ! empty( $value ) ) {
			return $value;
		}
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * Build the view model for one instructor.
 *
 * Everything comes from the LMS instructor API, keyed by the post's
 * `instructor_lms_id` custom field — see instructors-client.php for the endpoint
 * and the field-by-field mapping. Nothing on this page is static content.
 *
 * Two things are still sourced locally, both deliberately:
 *
 *   - The name and portrait fall back to the WordPress post's own title and
 *     featured image when the API cannot be reached, so a failed request still
 *     renders a recognisable page instead of an empty hero.
 *   - `error` carries a human-readable message when the fetch failed; the tab
 *     panels show it in place of the card grids.
 *
 * @param int $post_id Instructor post ID.
 * @return array View model.
 */
function instructor_detail_data( $post_id ) {
	$lms_id = (int) instructor_field( $post_id, INSTRUCTOR_LMS_ID_FIELD );

	$remote = instructors_fetch( $lms_id );

	// Fall back to the WordPress post for the two fields it can supply itself,
	// so an API failure still shows who the page is about.
	$name = isset( $remote['name'] ) ? trim( (string) $remote['name'] ) : '';
	if ( '' === $name ) {
		$name = (string) get_the_title( $post_id );
	}

	$avatar = isset( $remote['avatar'] ) ? trim( (string) $remote['avatar'] ) : '';
	if ( '' === $avatar ) {
		$avatar = (string) get_the_post_thumbnail_url( $post_id, 'medium_large' );
	}

	$data = array(
		'name'     => $name,
		'avatar'   => $avatar,
		'bio'      => isset( $remote['bio'] ) ? (string) $remote['bio'] : '',
		'bio_html' => isset( $remote['bio_html'] ) ? (string) $remote['bio_html'] : '',
		'partners' => isset( $remote['partners'] ) ? (array) $remote['partners'] : array(),
		'stats'    => isset( $remote['stats'] ) ? (array) $remote['stats'] : array(),
		'courses'  => isset( $remote['courses'] ) ? (array) $remote['courses'] : array(),
		'programs' => isset( $remote['programs'] ) ? (array) $remote['programs'] : array(),
		'error'    => isset( $remote['error'] ) ? (string) $remote['error'] : '',
	);

	/**
	 * Filter the instructor detail view model, after the API response has been
	 * mapped and the WordPress fallbacks applied.
	 *
	 * @param array $data    View model.
	 * @param int   $post_id Instructor post ID.
	 * @param int   $lms_id  Instructor id on the LMS.
	 */
	return (array) apply_filters( 'tutor_sso_instructor_detail_data', $data, $post_id, $lms_id );
}

/**
 * Render the breadcrumb bar: the instructor archive link, then the current name.
 *
 * @param string $name Instructor display name.
 * @return string HTML.
 */
function instructor_render_breadcrumb( $name ) {
	$archive = get_post_type_archive_link( instructor_single_post_type() );

	ob_start();
	?>
	<div class="rwaq-ins__breadcrumb-bar">
		<nav class="rwaq-ins__breadcrumb" aria-label="<?php echo esc_attr__( 'مسار التنقل', 'tutor-sso' ); ?>">
			<?php if ( $archive ) : ?>
				<a class="rwaq-ins__crumb" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html__( 'مدرّب', 'tutor-sso' ); ?></a>
			<?php else : ?>
				<span class="rwaq-ins__crumb"><?php echo esc_html__( 'مدرّب', 'tutor-sso' ); ?></span>
			<?php endif; ?>

			<?php if ( '' !== $name ) : ?>
				<span class="rwaq-ins__crumb-sep" aria-hidden="true"><?php echo instructor_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="rwaq-ins__crumb rwaq-ins__crumb--current" aria-current="page"><?php echo esc_html( $name ); ?></span>
			<?php endif; ?>
		</nav>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the hero band: the decorative blobs, the instructor summary beside the
 * tilted avatar card, and the white stats bar.
 *
 * @param array $data View model (see instructor_detail_data()).
 * @return string HTML.
 */
function instructor_render_hero( $data ) {
	$name     = isset( $data['name'] ) ? (string) $data['name'] : '';
	$avatar   = isset( $data['avatar'] ) ? (string) $data['avatar'] : '';
	$bio      = isset( $data['bio'] ) ? (string) $data['bio'] : '';
	$partners = isset( $data['partners'] ) ? (array) $data['partners'] : array();
	$stats    = isset( $data['stats'] ) ? (array) $data['stats'] : array();

	ob_start();
	?>
	<section class="rwaq-ins__hero">
		<div class="rwaq-ins__blobs" aria-hidden="true">
			<img class="rwaq-ins__blob rwaq-ins__blob--1" src="<?php echo esc_url( instructor_asset( 'blob-1.svg' ) ); ?>" width="297" height="297" alt="" />
			<img class="rwaq-ins__blob rwaq-ins__blob--2" src="<?php echo esc_url( instructor_asset( 'blob-2.svg' ) ); ?>" width="297" height="297" alt="" />
			<img class="rwaq-ins__blob rwaq-ins__blob--3" src="<?php echo esc_url( instructor_asset( 'blob-3.svg' ) ); ?>" width="297" height="297" alt="" />
			<img class="rwaq-ins__blob rwaq-ins__blob--4" src="<?php echo esc_url( instructor_asset( 'blob-4.svg' ) ); ?>" width="297" height="297" alt="" />
		</div>

		<div class="rwaq-ins__hero-inner">
			<div class="rwaq-ins__profile">
				<?php
				$avatar_fallback = TUTOR_SSO_URL . 'assets/images/avatar-fallback-author.svg';
				$avatar_src      = '' !== $avatar ? $avatar : $avatar_fallback;
				$avatar_classes  = 'rwaq-ins__avatar-image' . ( '' !== $avatar ? '' : ' rwaq-ins__avatar-image--fallback' );
				?>
				<div class="rwaq-ins__avatar">
					<span class="rwaq-ins__avatar-card" aria-hidden="true"></span>
					<img
						class="<?php echo esc_attr( $avatar_classes ); ?>"
						src="<?php echo esc_url( $avatar_src ); ?>"
						width="238"
						height="238"
						alt="<?php echo esc_attr( $name ); ?>"
						onerror="this.onerror=null;this.src='<?php echo esc_url( $avatar_fallback ); ?>';this.classList.add('rwaq-ins__avatar-image--fallback');"
					/>
				</div>

				<div class="rwaq-ins__summary">
					<?php if ( '' !== $name ) : ?>
						<h1 class="rwaq-ins__name"><?php echo esc_html( $name ); ?></h1>
					<?php endif; ?>

					<?php if ( ! empty( $partners ) ) : ?>
						<div class="rwaq-ins__partners">
							<span class="rwaq-ins__partners-label"><?php echo esc_html__( 'مرتبط بـ:', 'tutor-sso' ); ?></span>
							<?php foreach ( $partners as $index => $partner ) : ?>
								<?php
								$logo  = isset( $partner['logo'] ) ? (string) $partner['logo'] : '';
								$label = isset( $partner['name'] ) ? (string) $partner['name'] : '';

								if ( '' === $logo ) {
									continue;
								}
								?>
								<?php if ( $index > 0 ) : ?>
									<img class="rwaq-ins__partners-dot" src="<?php echo esc_url( instructor_asset( 'dot.svg' ) ); ?>" width="6" height="6" alt="" aria-hidden="true" />
								<?php endif; ?>
								<img class="rwaq-ins__partner-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $label ); ?>" loading="lazy" decoding="async" />
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( '' !== $bio ) : ?>
						<div class="rwaq-ins__bio">
							<p class="rwaq-ins__bio-text"><?php echo esc_html( $bio ); ?></p>
							<?php // Revealed by instructor.js only when the clamp is actually hiding text. ?>
							<button type="button" class="rwaq-ins__bio-toggle" aria-haspopup="dialog" aria-controls="rwaq-ins-bio-modal" hidden>
								<?php echo esc_html__( 'اقرأ المزيد', 'tutor-sso' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $stats ) ) : ?>
				<div class="rwaq-ins__stats">
					<?php foreach ( $stats as $index => $stat ) : ?>
						<?php
						$icon  = isset( $stat['icon'] ) ? (string) $stat['icon'] : '';
						$label = isset( $stat['label'] ) ? (string) $stat['label'] : '';

						if ( '' === $label ) {
							continue;
						}
						?>
						<?php if ( $index > 0 ) : ?>
							<span class="rwaq-ins__stats-divider" aria-hidden="true"></span>
						<?php endif; ?>
						<div class="rwaq-ins__stat">
							<?php if ( '' !== $icon ) : ?>
								<span class="rwaq-ins__stat-icon" aria-hidden="true">
									<img src="<?php echo esc_url( instructor_asset( $icon ) ); ?>" width="24" height="24" alt="" />
								</span>
							<?php endif; ?>
							<span class="rwaq-ins__stat-label"><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Render the biography modal: the full text, behind the hero's "read more".
 *
 * Matches node 9664:99416: a 700px panel, brand-purple header with the title
 * "حول {name}" and the close button, then the full text at 14/28.
 *
 * Always emitted (hidden) when there is a biography, so the button has something
 * to open without a round trip. instructor.js reveals the button only when the
 * hero's two-line clamp is actually hiding text, so a short biography ships an
 * unused hidden dialog and no visible affordance.
 *
 * `bio_html` keeps the API's paragraphs (sanitised with wp_kses_post upstream);
 * the plain-text `bio` is the fallback when the HTML form is empty.
 *
 * @param array $data View model (see instructor_detail_data()).
 * @return string HTML.
 */
function instructor_render_bio_modal( $data ) {
	$name = isset( $data['name'] ) ? (string) $data['name'] : '';
	$html = isset( $data['bio_html'] ) ? (string) $data['bio_html'] : '';
	$text = isset( $data['bio'] ) ? (string) $data['bio'] : '';

	if ( '' === $html && '' === $text ) {
		return '';
	}

	ob_start();
	?>
	<div class="rwaq-ins__modal" id="rwaq-ins-bio-modal" hidden>
		<div class="rwaq-ins__modal-overlay" data-rwaq-ins-close></div>

		<div class="rwaq-ins__modal-panel" role="dialog" aria-modal="true" aria-labelledby="rwaq-ins-modal-title">
			<div class="rwaq-ins__modal-head">
				<h2 class="rwaq-ins__modal-title" id="rwaq-ins-modal-title">
					<?php
					/* translators: %s: instructor name. */
					echo esc_html( '' !== $name ? sprintf( __( 'حول %s', 'tutor-sso' ), $name ) : __( 'نبذة', 'tutor-sso' ) );
					?>
				</h2>
				<button type="button" class="rwaq-ins__modal-close" data-rwaq-ins-close aria-label="<?php echo esc_attr__( 'إغلاق', 'tutor-sso' ); ?>">
					<?php echo instructor_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>

			<div class="rwaq-ins__modal-body" tabindex="-1">
				<?php
				if ( '' !== $html ) {
					// Sanitised in instructors_fetch() via wp_kses_post().
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<p>' . esc_html( $text ) . '</p>';
				}
				?>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Number of cards per row in the tab panels.
 *
 * Four fills the design's 1232px column exactly. The listing stylesheets read it
 * from --rwaq-{courses,programs}-columns and collapse to fewer columns at their
 * own breakpoints.
 *
 * @return int
 */
function instructor_grid_columns() {
	$columns = (int) apply_filters( 'tutor_sso_instructor_grid_columns', 4 );

	return max( 1, min( 6, $columns ) );
}

/**
 * Render a grid of cards, or the empty-state line when there are none.
 *
 * The cards are the catalogs' own components, so each grid is wrapped in that
 * catalog's root class as well as its grid class. The root is what carries the
 * --rwaq-* custom properties the cards resolve their colours from (see the
 * `.rwaq-courses` / `.rwaq-programs` blocks in courses.css / programs.css) —
 * without it the cards would render unstyled.
 *
 * @param array[] $items   Rows: card shapes for 'courses', raw API objects for 'programs'.
 * @param string  $empty   Empty-state message.
 * @param string  $kind    'courses' or 'programs'.
 * @return string HTML.
 */
function instructor_render_grid( $items, $empty, $kind ) {
	$items = array_filter( (array) $items, 'is_array' );

	if ( empty( $items ) ) {
		return '<p class="rwaq-ins__empty">' . esc_html( $empty ) . '</p>';
	}

	$columns = instructor_grid_columns();

	$html = sprintf(
		'<div class="rwaq-%1$s rwaq-ins__cards"><div class="rwaq-%1$s__grid" style="--rwaq-%1$s-columns: %2$d;">',
		esc_attr( $kind ),
		$columns
	);

	foreach ( $items as $item ) {
		// 'program' is the programs module's own default detail base (see
		// program_detail_url()); the catalog only overrides it via a shortcode
		// attribute, which this view has no equivalent of.
		$html .= 'programs' === $kind
			? programs_render_card( $item, 'program' )
			: courses_render_card( $item );
	}

	return $html . '</div></div>';
}

/**
 * Render the tabbed body: the courses / programs tab strip and its two panels.
 *
 * The counts in the tab badges come from the row counts themselves, so they can
 * never disagree with the grid below.
 *
 * @param array $data View model (see instructor_detail_data()).
 * @return string HTML.
 */
function instructor_render_tabs( $data ) {
	$courses  = isset( $data['courses'] ) ? array_filter( (array) $data['courses'], 'is_array' ) : array();
	$programs = isset( $data['programs'] ) ? array_filter( (array) $data['programs'], 'is_array' ) : array();
	$error    = isset( $data['error'] ) ? (string) $data['error'] : '';

	// The mockup's second tab was "مقالات", but the instructor endpoint carries
	// no articles — it returns programs, so that is what the tab shows.
	$tabs = array(
		'courses'  => array(
			'label' => __( 'دورات', 'tutor-sso' ),
			'items' => $courses,
			'empty' => __( 'لا توجد دورات لهذا المدرّب بعد.', 'tutor-sso' ),
		),
		'programs' => array(
			'label' => __( 'برامج', 'tutor-sso' ),
			'items' => $programs,
			'empty' => __( 'لا توجد برامج لهذا المدرّب بعد.', 'tutor-sso' ),
		),
	);

	ob_start();
	?>
	<section class="rwaq-ins__content">
		<div class="rwaq-ins__content-inner">
			<div class="rwaq-ins__tabs" role="tablist">
				<?php $first = true; ?>
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<button
						type="button"
						class="rwaq-ins__tab<?php echo $first ? ' is-active' : ''; ?>"
						role="tab"
						id="rwaq-ins-tab-<?php echo esc_attr( $key ); ?>"
						aria-controls="rwaq-ins-panel-<?php echo esc_attr( $key ); ?>"
						aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
						tabindex="<?php echo $first ? '0' : '-1'; ?>"
					>
						<span class="rwaq-ins__tab-label"><?php echo esc_html( $tab['label'] ); ?></span>
						<span class="rwaq-ins__tab-count"><?php echo esc_html( number_format_i18n( count( $tab['items'] ) ) ); ?></span><?php // Row count, so the badge can never disagree with the grid below it. ?>
					</button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</div>

			<?php $first = true; ?>
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<div
					class="rwaq-ins__panel"
					role="tabpanel"
					id="rwaq-ins-panel-<?php echo esc_attr( $key ); ?>"
					aria-labelledby="rwaq-ins-tab-<?php echo esc_attr( $key ); ?>"
					<?php echo $first ? '' : 'hidden'; ?>
				>
					<?php if ( '' !== $error ) : ?>
						<p class="rwaq-ins__empty rwaq-ins__empty--error"><?php echo esc_html( $error ); ?></p>
					<?php else : ?>
						<?php echo instructor_render_grid( $tab['items'], $tab['empty'], $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Render the whole instructor detail view for a post.
 *
 * @param int $post_id Instructor post ID.
 * @return string HTML.
 */
function instructor_render_detail( $post_id ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return '';
	}

	instructor_detail_enqueue_assets();

	$data = instructor_detail_data( $post_id );

	ob_start();
	?>
	<div class="rwaq-ins" dir="rtl">
		<?php
		echo instructor_render_breadcrumb( isset( $data['name'] ) ? (string) $data['name'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo instructor_render_hero( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo instructor_render_tabs( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo instructor_render_bio_modal( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_instructor_detail id="123"].
 *
 * With no id, renders the current post — which is how
 * templates/single-instructor.php uses it.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function instructor_detail_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id' => 0,
		),
		$atts,
		'rwaq_instructor_detail'
	);

	$post_id = (int) $atts['id'];

	if ( $post_id <= 0 ) {
		$post_id = get_the_ID();
	}

	return instructor_render_detail( $post_id );
}
add_shortcode( 'rwaq_instructor_detail', __NAMESPACE__ . '\\instructor_detail_shortcode' );
