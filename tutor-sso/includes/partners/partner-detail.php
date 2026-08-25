<?php
/**
 * Partner detail: single-partner template loader + detail render / shortcode.
 * Usage:
 *   [rwaq_partner_detail]            render the current partner in the loop
 *   [rwaq_partner_detail id="123"]   render an explicit partner post by ID
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field holding the partner's numeric id on the LMS. Every value on the
 * page is fetched with it.
 */
const PARTNER_LMS_ID_FIELD = 'partner_id';

/**
 * How many cards each section shows before its "load more" button.
 *
 * @return int
 */
function partner_section_step() {
	$step = (int) apply_filters( 'tutor_sso_partner_section_step', 4 );

	return max( 1, $step );
}

/**
 * Use the plugin's single-partner.php for single partner posts.
 *
 * Runs on the `single_template` filter, which fires with the template the theme
 * hierarchy resolved. We only intervene for the partner post type, and we defer
 * to a theme-provided single-partner.php when one exists.
 *
 * @param string $template Path to the template the hierarchy resolved.
 * @return string
 */
function partner_single_template( $template ) {
	if ( ! is_singular( partners_archive_post_type() ) ) {
		return $template;
	}

	$theme_template = locate_template( array( 'single-partner.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/single-partner.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'single_template', __NAMESPACE__ . '\\partner_single_template' );

/**
 * Register the (lazily enqueued) partner detail assets.
 */
function partner_detail_register_assets() {
	wp_register_style(
		'tutor-sso-partner-detail',
		TUTOR_SSO_URL . 'assets/css/partner-detail.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// Section "load more" reveals + the description modal. Vanilla JS.
	wp_register_script(
		'tutor-sso-partner-detail',
		TUTOR_SSO_URL . 'assets/js/partner.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\partner_detail_register_assets' );

/**
 * Enqueue the detail assets. Called lazily by the shortcode.
 */
function partner_detail_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-partner-detail' );
	wp_enqueue_script( 'tutor-sso-partner-detail' );

	// The البرامج and دورات sections render the catalogs' own card components, so
	// both catalog stylesheets are needed for them to look like they do on the
	// listings. Registered by their modules on wp_enqueue_scripts, which has
	// already run by the time the shortcode renders.
	wp_enqueue_style( 'tutor-sso-courses' );
	wp_enqueue_style( 'tutor-sso-programs' );
}

/**
 * URL of a bundled partner-detail asset.
 *
 * @param string $file File name inside assets/images/partner/.
 * @return string
 */
function partner_asset( $file ) {
	return TUTOR_SSO_URL . 'assets/images/partner/' . $file;
}

/**
 * Read a custom field: ACF's get_field() first, then a raw post meta fallback.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @return mixed
 */
function partner_field( $post_id, $field ) {
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $field, $post_id );
		if ( ! empty( $value ) ) {
			return $value;
		}
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * Return an inline SVG icon used on the partner detail view, by name.
 *
 * Only the breadcrumb separator and the instructor card's meta glyphs are inline;
 * the larger artwork (decorative blobs, stat icons) is bundled under
 * assets/images/partner/ and referenced with partner_asset().
 *
 * The markup is static and trusted, so callers echo it without escaping.
 *
 * @param string $name One of: chevron, close.
 * @return string SVG markup, or '' for an unknown name.
 */
function partner_icon( $name ) {
	$icons = array(
		'chevron' => '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 3L4.5 6L7.5 9" stroke="#242424" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

		// Description modal close button — white on the brand header.
		'close'   => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Build the view model for one partner post.
 *
 * @param int $post_id Partner post ID.
 * @return array View model.
 */
function partner_detail_data( $post_id ) {
	$lms_id = (int) partner_field( $post_id, PARTNER_LMS_ID_FIELD );

	$remote = partner_fetch( $lms_id );

	// Fall back to the WordPress post for the two fields it can supply itself, so
	// a failed request still renders a recognisable page.
	$name = isset( $remote['name'] ) ? trim( (string) $remote['name'] ) : '';
	if ( '' === $name ) {
		$name = (string) get_the_title( $post_id );
	}

	$logo = isset( $remote['logo'] ) ? trim( (string) $remote['logo'] ) : '';
	if ( '' === $logo ) {
		$logo = (string) get_the_post_thumbnail_url( $post_id, 'medium' );
	}

	$data = array(
		'name'        => $name,
		'logo'        => $logo,
		'bio'         => isset( $remote['bio'] ) ? (string) $remote['bio'] : '',
		'bio_html'    => isset( $remote['bio_html'] ) ? (string) $remote['bio_html'] : '',
		'stats'       => isset( $remote['stats'] ) ? (array) $remote['stats'] : array(),
		'totals'      => isset( $remote['totals'] ) ? (array) $remote['totals'] : array(),
		'programs'    => isset( $remote['programs'] ) ? (array) $remote['programs'] : array(),
		'courses'     => isset( $remote['courses'] ) ? (array) $remote['courses'] : array(),
		'instructors' => isset( $remote['instructors'] ) ? (array) $remote['instructors'] : array(),
		'error'       => isset( $remote['error'] ) ? (string) $remote['error'] : '',
	);

	/**
	 * Filter the partner detail view model, after the API response has been
	 * mapped and the WordPress fallbacks applied.
	 *
	 * @param array $data    View model.
	 * @param int   $post_id Partner post ID.
	 * @param int   $lms_id  Organization id on the LMS.
	 */
	return (array) apply_filters( 'tutor_sso_partner_view_model', $data, $post_id, $lms_id );
}

/**
 * Render the breadcrumb: the partners archive link, then the current name.
 *
 * @param string $name Partner display name.
 * @return string HTML.
 */
function partner_render_breadcrumb( $name ) {
	$archive = get_post_type_archive_link( partners_archive_post_type() );

	ob_start();
	?>
	<div class="rwaq-pt__breadcrumb-bar">
		<nav class="rwaq-pt__breadcrumb" aria-label="<?php echo esc_attr__( 'مسار التنقل', 'tutor-sso' ); ?>">
			<?php if ( $archive ) : ?>
				<a class="rwaq-pt__crumb" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html__( 'الشركاء', 'tutor-sso' ); ?></a>
			<?php else : ?>
				<span class="rwaq-pt__crumb"><?php echo esc_html__( 'الشركاء', 'tutor-sso' ); ?></span>
			<?php endif; ?>

			<?php if ( '' !== $name ) : ?>
				<span class="rwaq-pt__crumb-sep" aria-hidden="true"><?php echo partner_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="rwaq-pt__crumb rwaq-pt__crumb--current" aria-current="page"><?php echo esc_html( $name ); ?></span>
			<?php endif; ?>
		</nav>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the hero band: decorative blobs, the partner summary beside its logo
 * tile, and the white stats bar.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function partner_render_hero( $data ) {
	$name  = isset( $data['name'] ) ? (string) $data['name'] : '';
	$logo  = isset( $data['logo'] ) ? (string) $data['logo'] : '';
	$bio   = isset( $data['bio'] ) ? (string) $data['bio'] : '';
	$stats = isset( $data['stats'] ) ? (array) $data['stats'] : array();

	ob_start();
	?>
	<section class="rwaq-pt__hero">
		<div class="rwaq-pt__blobs" aria-hidden="true">
			<?php foreach ( array( 1, 2, 3, 4 ) as $n ) : ?>
				<img class="rwaq-pt__blob rwaq-pt__blob--<?php echo esc_attr( $n ); ?>" src="<?php echo esc_url( TUTOR_SSO_URL . 'assets/images/instructor/blob-' . $n . '.svg' ); ?>" width="297" height="297" alt="" />
			<?php endforeach; ?>
		</div>

		<div class="rwaq-pt__hero-inner">
			<div class="rwaq-pt__profile">
				<?php if ( '' !== $logo ) : ?>
					<div class="rwaq-pt__logo">
						<img class="rwaq-pt__logo-image" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					</div>
				<?php endif; ?>

				<div class="rwaq-pt__summary">
					<?php if ( '' !== $name ) : ?>
						<h1 class="rwaq-pt__name"><?php echo esc_html( $name ); ?></h1>
					<?php endif; ?>

					<?php if ( '' !== $bio ) : ?>
						<div class="rwaq-pt__bio">
							<p class="rwaq-pt__bio-text"><?php echo esc_html( $bio ); ?></p>
							<?php // Revealed by partner.js only when the clamp is actually hiding text. ?>
							<button type="button" class="rwaq-pt__bio-toggle" aria-haspopup="dialog" aria-controls="rwaq-pt-bio-modal" hidden>
								<?php echo esc_html__( 'اقرأ المزيد', 'tutor-sso' ); ?>
							</button>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $stats ) ) : ?>
				<div class="rwaq-pt__stats">
					<?php foreach ( $stats as $index => $stat ) : ?>
						<?php
						$icon  = isset( $stat['icon'] ) ? (string) $stat['icon'] : '';
						$label = isset( $stat['label'] ) ? (string) $stat['label'] : '';

						if ( '' === $label ) {
							continue;
						}
						?>
						<?php if ( $index > 0 ) : ?>
							<span class="rwaq-pt__stats-divider" aria-hidden="true"></span>
						<?php endif; ?>
						<div class="rwaq-pt__stat">
							<?php if ( '' !== $icon ) : ?>
								<span class="rwaq-pt__stat-icon" aria-hidden="true">
									<img src="<?php echo esc_url( partner_asset( $icon ) ); ?>" width="24" height="24" alt="" />
								</span>
							<?php endif; ?>
							<span class="rwaq-pt__stat-label"><?php echo esc_html( $label ); ?></span>
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
 * Render one instructor card.
 *
 * A 296x350 tile: a 180px avatar, the name, the count row, then the partner's
 * logo at the foot.
 *
 * @param array $person Card row (see partners_map_instructor_card()).
 * @return string HTML.
 */
function partner_render_instructor_card( $person ) {
	if ( ! is_array( $person ) ) {
		return '';
	}

	$name   = isset( $person['name'] ) ? (string) $person['name'] : '';
	$image  = isset( $person['image'] ) ? (string) $person['image'] : '';
	$url    = isset( $person['url'] ) ? (string) $person['url'] : '';
	$logo   = isset( $person['logo'] ) ? (string) $person['logo'] : '';
	$counts = isset( $person['counts'] ) ? (array) $person['counts'] : array();

	// The design pairs مقالات with مساق on one row and puts طالب on its own. With
	// مقالات dropped — no article data exists anywhere in the API — the two
	// remaining counts share that first row instead of leaving it half empty.
	$rows = array(
		array(
			'icon'  => 'ic-courses.svg',
			/* translators: %s: number of courses. */
			'label' => sprintf( __( '%s مساق', 'tutor-sso' ), number_format_i18n( isset( $counts['courses'] ) ? (int) $counts['courses'] : 0 ) ),
		),
		array(
			'icon'  => 'ic-learners.svg',
			/* translators: %s: number of learners. */
			'label' => sprintf( __( '%s طالب', 'tutor-sso' ), number_format_i18n( isset( $counts['learners'] ) ? (int) $counts['learners'] : 0 ) ),
		),
	);

	$tag   = '' !== $url ? 'a' : 'div';
	$attrs = '' !== $url ? ' href="' . esc_url( $url ) . '"' : '';

	ob_start();
	?>
	<<?php echo $tag . $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="rwaq-pt-person">
		<span class="rwaq-pt-person__media">
			<?php
			// `image` is null for most instructors on the LMS today, so the shared
			// author-avatar fallback stands in — the same one the blog cards use,
			// and also swapped in via onerror when a URL is present but dead
			// (these are presigned S3 links that expire).
			$fallback = TUTOR_SSO_URL . 'assets/images/avatar-fallback-author.svg';
			$src      = '' !== $image ? $image : $fallback;
			?>
			<img
				class="rwaq-pt-person__image<?php echo '' !== $image ? '' : ' rwaq-pt-person__image--fallback'; ?>"
				src="<?php echo esc_url( $src ); ?>"
				alt="<?php echo esc_attr( $name ); ?>"
				loading="lazy"
				decoding="async"
				onerror="this.onerror=null;this.src='<?php echo esc_url( $fallback ); ?>';this.classList.add('rwaq-pt-person__image--fallback');"
			/>
		</span>

		<span class="rwaq-pt-person__body">
			<?php if ( '' !== $name ) : ?>
				<span class="rwaq-pt-person__name"><?php echo esc_html( $name ); ?></span>
			<?php endif; ?>

			<span class="rwaq-pt-person__meta">
				<span class="rwaq-pt-person__meta-row">
					<?php foreach ( $rows as $row ) : ?>
						<span class="rwaq-pt-person__stat">
							<img src="<?php echo esc_url( partner_asset( $row['icon'] ) ); ?>" width="16" height="16" alt="" aria-hidden="true" />
							<span><?php echo esc_html( $row['label'] ); ?></span>
						</span>
					<?php endforeach; ?>
				</span>
			</span>
		</span>

		<?php if ( '' !== $logo ) : ?>
			<span class="rwaq-pt-person__org">
				<img src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" decoding="async" />
			</span>
		<?php endif; ?>
	</<?php echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php
	return ob_get_clean();
}

/**
 * Render one card section: heading + count pill, the card grid, and the
 * "load N more" button.
 *
 * All rows are printed up front; the ones past the first step carry the
 * `is-hidden` class and partner.js reveals them a step at a time. That keeps the
 * whole section in the HTML — no request, and no content that only exists for
 * visitors with JS.
 *
 * @param string  $key      Section key, for the button's data attribute.
 * @param string  $heading  Section heading.
 * @param array[] $items    Rows.
 * @param string  $kind     'courses' | 'programs' | 'instructors'.
 * @param string  $empty    Empty-state message.
 * @param int     $total    Count for the pill — the API's own total. Defaults to
 *                          the number of rows, which is what it equals while the
 *                          payload embeds them all.
 * @return string HTML.
 */
function partner_render_section( $key, $heading, $items, $kind, $empty, $total = 0 ) {
	$items = array_values( array_filter( (array) $items, 'is_array' ) );
	$step  = partner_section_step();
	$count = count( $items );
	$total = (int) $total > 0 ? (int) $total : $count;

	ob_start();
	?>
	<section class="rwaq-pt__section" data-section="<?php echo esc_attr( $key ); ?>" data-step="<?php echo esc_attr( $step ); ?>">
		<div class="rwaq-pt__section-head">
			<h2 class="rwaq-pt__section-title"><?php echo esc_html( $heading ); ?></h2>
			<span class="rwaq-pt__count-pill"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
		</div>

		<?php if ( 0 === $count ) : ?>
			<p class="rwaq-pt__empty"><?php echo esc_html( $empty ); ?></p>
		<?php else : ?>
			<?php
			// The catalog card components need their catalog's root class for the
			// --rwaq-* custom properties they resolve colours from.
			$grid_class = 'rwaq-pt__grid';
			if ( 'courses' === $kind ) {
				$grid_class .= ' rwaq-courses';
			} elseif ( 'programs' === $kind ) {
				$grid_class .= ' rwaq-programs';
			}
			?>
			<div class="<?php echo esc_attr( $grid_class ); ?>">
				<?php foreach ( $items as $index => $item ) : ?>
					<div class="rwaq-pt__cell<?php echo $index >= $step ? ' is-hidden' : ''; ?>">
						<?php
						if ( 'programs' === $kind ) {
							echo programs_render_card( $item, 'program' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} elseif ( 'courses' === $kind ) {
							echo courses_render_card( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							echo partner_render_instructor_card( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $count > $step ) : ?>
				<div class="rwaq-pt__section-foot">
					<button type="button" class="rwaq-pt__more" data-more>
						<?php
						/* translators: %s: how many more items the next click reveals. */
						echo esc_html( sprintf( __( 'تحميل %s عناصر إضافية', 'tutor-sso' ), number_format_i18n( min( $step, $count - $step ) ) ) );
						?>
					</button>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Render the description modal, behind the hero's "read more".
 *
 * Same component as the instructor biography modal: a 700px panel with a
 * brand-purple header and the full text at 14/28.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function partner_render_bio_modal( $data ) {
	$name = isset( $data['name'] ) ? (string) $data['name'] : '';
	$html = isset( $data['bio_html'] ) ? (string) $data['bio_html'] : '';
	$text = isset( $data['bio'] ) ? (string) $data['bio'] : '';

	if ( '' === $html && '' === $text ) {
		return '';
	}

	ob_start();
	?>
	<div class="rwaq-pt__modal" id="rwaq-pt-bio-modal" hidden>
		<div class="rwaq-pt__modal-overlay" data-rwaq-pt-close></div>

		<div class="rwaq-pt__modal-panel" role="dialog" aria-modal="true" aria-labelledby="rwaq-pt-modal-title">
			<div class="rwaq-pt__modal-head">
				<h2 class="rwaq-pt__modal-title" id="rwaq-pt-modal-title">
					<?php
					/* translators: %s: partner name. */
					echo esc_html( '' !== $name ? sprintf( __( 'حول %s', 'tutor-sso' ), $name ) : __( 'نبذة', 'tutor-sso' ) );
					?>
				</h2>
				<button type="button" class="rwaq-pt__modal-close" data-rwaq-pt-close aria-label="<?php echo esc_attr__( 'إغلاق', 'tutor-sso' ); ?>">
					<?php echo partner_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>

			<div class="rwaq-pt__modal-body" tabindex="-1">
				<?php
				if ( '' !== $html ) {
					// Sanitised in partner_fetch() via wp_kses_post().
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
 * Render the whole partner detail view for a post.
 *
 * @param int $post_id Partner post ID.
 * @return string HTML.
 */
function partner_render_detail( $post_id ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return '';
	}

	partner_detail_enqueue_assets();

	$data   = partner_detail_data( $post_id );
	$error  = isset( $data['error'] ) ? (string) $data['error'] : '';
	$totals = isset( $data['totals'] ) ? (array) $data['totals'] : array();

	ob_start();
	?>
	<div class="rwaq-pt" dir="rtl">
		<?php
		echo partner_render_breadcrumb( isset( $data['name'] ) ? (string) $data['name'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo partner_render_hero( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<div class="rwaq-pt__content">
			<div class="rwaq-pt__content-inner">
				<?php if ( '' !== $error ) : ?>
					<p class="rwaq-pt__empty rwaq-pt__empty--error"><?php echo esc_html( $error ); ?></p>
				<?php else : ?>
					<?php
					echo partner_render_section( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'programs',
						__( 'البرامج', 'tutor-sso' ),
						$data['programs'],
						'programs',
						__( 'لا توجد برامج لهذا الشريك بعد.', 'tutor-sso' ),
						isset( $totals['programs'] ) ? (int) $totals['programs'] : 0
					);

					echo partner_render_section( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'courses',
						__( 'دورات', 'tutor-sso' ),
						$data['courses'],
						'courses',
						__( 'لا توجد دورات لهذا الشريك بعد.', 'tutor-sso' ),
						isset( $totals['courses'] ) ? (int) $totals['courses'] : 0
					);

					echo partner_render_section( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'instructors',
						__( 'المدرّبون', 'tutor-sso' ),
						$data['instructors'],
						'instructors',
						__( 'لا يوجد مدرّبون لهذا الشريك بعد.', 'tutor-sso' ),
						isset( $totals['instructors'] ) ? (int) $totals['instructors'] : 0
					);
					?>
				<?php endif; ?>
			</div>
		</div>

		<?php echo partner_render_bio_modal( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_partner_detail id="123"].
 *
 * With no id, renders the current post — which is how
 * templates/single-partner.php uses it.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function partner_detail_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'rwaq_partner_detail' );

	$post_id = (int) $atts['id'];

	if ( $post_id <= 0 ) {
		$post_id = get_the_ID();
	}

	return partner_render_detail( $post_id );
}
add_shortcode( 'rwaq_partner_detail', __NAMESPACE__ . '\\partner_detail_shortcode' );
