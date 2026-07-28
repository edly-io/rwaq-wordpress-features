<?php
/**
 * Blogs listing: assets, shortcode, search / sort / filters.
 *
 * WordPress-posts counterpart of the programs catalog (see programs-catalog.php).
 * Renders a listing of published posts: a filter sidebar (one collapsible group
 * per configured taxonomy, with per-term counts), a search box, a sort dropdown,
 * active-filter chips and a grid of post cards. Page 1 is rendered server-side
 * (SEO + no-JS baseline); further pages load via AJAX on scroll, and every
 * search / sort / filter change re-queries via AJAX (see blogs-ajax.php and
 * assets/js/blogs.js).
 *
 * Usage:
 *   [rwaq_blogs]                                        9 per page (default)
 *   [rwaq_blogs per_page="12" columns="3"]
 *   [rwaq_blogs post_type="post" taxonomy="category,post_tag"]
 *   [rwaq_blogs title="المدونة"]                         header title (blank hides)
 *
 * The design is intentionally a clean baseline; brand styling can layer on top of
 * the BEM-ish classes (.rwaq-blogs, .rwaq-blog-card, …).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the (lazily enqueued) blogs listing assets.
 */
function blogs_register_assets() {
	// The combined blog stylesheet (listing + detail) is registered once as
	// 'tutor-sso-blog' in blog-detail.php; the listing reuses that same handle
	// (see blogs_enqueue_assets()) so the file is never registered twice.
	wp_register_script(
		'tutor-sso-blogs',
		TUTOR_SSO_URL . 'assets/js/blogs.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\blogs_register_assets' );

/**
 * Enqueue + localize listing assets. Called lazily by the shortcode so they only
 * load on pages that actually render the listing.
 */
function blogs_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-blog' );
	wp_enqueue_script( 'tutor-sso-blogs' );

	wp_localize_script(
		'tutor-sso-blogs',
		'tutorSsoBlogs',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tutor_sso_blogs' ),
			'icons'   => array(
				// SVG for the active-filter chip remove button (chips are rendered
				// client-side, so the markup is passed through to blogs.js).
				'removeChip' => blogs_icon( 'close' ),
			),
			'i18n'    => array(
				'error'        => __( 'حدث خطأ أثناء تحميل المقالات. يرجى المحاولة مرة أخرى.', 'tutor-sso' ),
				'noResults'    => __( 'لا توجد مقالات مطابقة.', 'tutor-sso' ),
				/* translators: %s: number of posts found. */
				'countLabel'   => __( 'تم العثور على %s مقالة', 'tutor-sso' ),
				'removeFilter' => __( 'إزالة عامل التصفية', 'tutor-sso' ),
			),
		)
	);
}

/**
 * Sort options: option key => visible label. Keys map to ordering expressions in
 * blogs_allowed_ordering().
 *
 * @return array<string,string>
 */
function blogs_sort_options() {
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
function blogs_default_sort() {
	return 'newest';
}

/**
 * Site-wide default "posts per page", from the settings page.
 *
 * Used as the shortcode's per_page default so the page size is managed centrally.
 * Clamped to 1–48 (matching the AJAX handler's ceiling); falls back to 8.
 *
 * @return int
 */
function blogs_default_per_page() {
	$value = (int) sso_option( 'blogs_per_page', 8 );

	if ( $value < 1 ) {
		$value = 8;
	}

	return min( $value, 48 );
}

/**
 * Small inline icon set used across the listing UI.
 *
 * @param string $name Icon name.
 * @return string SVG markup.
 */
function blogs_icon( $name ) {
	$icons = array(
		'calendar' => '<svg class="rwaq-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5.33325 1.33331V3.99998" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M10.6667 1.33331V3.99998" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.6667 2.66669H3.33333C2.59695 2.66669 2 3.26364 2 4.00002V13.3334C2 14.0697 2.59695 14.6667 3.33333 14.6667H12.6667C13.403 14.6667 14 14.0697 14 13.3334V4.00002C14 3.26364 13.403 2.66669 12.6667 2.66669Z" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 6.66669H14" stroke="#616161" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'thumb'    => '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4.5-4.5L6 21"/></svg>',
		'search'   => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M9.16667 15.8333C12.8486 15.8333 15.8333 12.8486 15.8333 9.16667C15.8333 5.48477 12.8486 2.5 9.16667 2.5C5.48477 2.5 2.5 5.48477 2.5 9.16667C2.5 12.8486 5.48477 15.8333 9.16667 15.8333Z" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5001 17.5L13.9167 13.9167" stroke="#616161" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		'caret'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
		'check'    => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4 10-10"/></svg>',
		'close'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4.08859 4.21569L4.14645 4.14645C4.32001 3.97288 4.58944 3.9536 4.78431 4.08859L4.85355 4.14645L10 9.293L15.1464 4.14645C15.32 3.97288 15.5894 3.9536 15.7843 4.08859L15.8536 4.14645C16.0271 4.32001 16.0464 4.58944 15.9114 4.78431L15.8536 4.85355L10.707 10L15.8536 15.1464C16.0271 15.32 16.0464 15.5894 15.9114 15.7843L15.8536 15.8536C15.68 16.0271 15.4106 16.0464 15.2157 15.9114L15.1464 15.8536L10 10.707L4.85355 15.8536C4.67999 16.0271 4.41056 16.0464 4.21569 15.9114L4.14645 15.8536C3.97288 15.68 3.9536 15.4106 4.08859 15.2157L4.14645 15.1464L9.293 10L4.14645 4.85355C3.97288 4.67999 3.9536 4.41056 4.08859 4.21569L4.14645 4.14645L4.08859 4.21569Z" fill="#616161"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * The Font Awesome "spinner" SVG used as the AJAX loader.
 *
 * @return string
 */
function blogs_loader_svg() {
	return '<svg aria-hidden="true" class="e-font-icon-svg e-fas-spinner rwaq-blogs__spinner" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M304 48c0 26.51-21.49 48-48 48s-48-21.49-48-48 21.49-48 48-48 48 21.49 48 48zm-48 368c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zm208-208c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.49-48-48-48zM96 256c0-26.51-21.49-48-48-48S0 229.49 0 256s21.49 48 48 48 48-21.49 48-48zm12.922 99.078c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.491-48-48-48zm294.156 0c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48c0-26.509-21.49-48-48-48zM108.922 60.922c-26.51 0-48 21.49-48 48s21.49 48 48 48 48-21.49 48-48-21.491-48-48-48z"></path></svg>';
}

/**
 * Render a single post card.
 *
 * @param int|\WP_Post $post Post ID or object.
 * @param array        $args {
 *     @type string $badge_tax Taxonomy whose terms show as badges on the card.
 *     @type bool   $excerpt   Whether to render the excerpt.
 * }
 * @return string HTML.
 */
/**
 * Whether a post is flagged featured (ACF true/false field `is_featured`).
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function blogs_is_featured( $post_id ) {
	if ( function_exists( 'get_field' ) ) {
		return (bool) get_field( 'is_featured', $post_id );
	}

	return '1' === (string) get_post_meta( $post_id, 'is_featured', true );
}

/**
 * A post author's display name.
 *
 * Prefers the per-post ACF `author_name` override; otherwise the author's
 * first + last name (whichever exist). Returns '' when nothing is set (the card
 * then shows just the avatar, no name).
 *
 * @param \WP_Post $post Post object.
 * @return string
 */
function blogs_author_name( $post ) {
	if ( function_exists( 'get_field' ) ) {
		$custom = trim( (string) get_field( 'author_name', $post->ID ) );
		if ( '' !== $custom ) {
			return $custom;
		}
	}

	$author_id = (int) $post->post_author;
	$first     = (string) get_the_author_meta( 'first_name', $author_id );
	$last      = (string) get_the_author_meta( 'last_name', $author_id );

	return trim( $first . ' ' . $last );
}

/**
 * The bundled default author avatar (used when there's no ACF image / Gravatar).
 *
 * @return string
 */
function blogs_author_avatar_fallback() {
	return TUTOR_SSO_URL . 'assets/images/avatar-fallback-author.svg';
}

/**
 * Resolve an ACF/meta image field value to a URL, whatever return format the
 * field uses: an image array, an attachment ID, or a plain URL string. Returns
 * '' when the value is empty or can't be resolved.
 *
 * @param mixed  $value Field value (array, numeric ID, or URL string).
 * @param string $size  Image size to request for attachment IDs.
 * @return string
 */
function blogs_image_field_url( $value, $size = 'thumbnail' ) {
	if ( empty( $value ) ) {
		return '';
	}

	if ( is_array( $value ) ) {
		if ( ! empty( $value['url'] ) ) {
			return (string) $value['url'];
		}
		$value = isset( $value['ID'] ) ? $value['ID'] : ( isset( $value['id'] ) ? $value['id'] : 0 );
	}

	if ( is_numeric( $value ) ) {
		$url = wp_get_attachment_image_url( (int) $value, $size );
		return $url ? $url : '';
	}

	return (string) $value;
}

/**
 * A post author's avatar URL.
 *
 * Prefers the per-post ACF `author_image` override — resolved via
 * blogs_image_field_url() so it works whatever return format the field uses
 * (array / ID / URL); otherwise the author's Gravatar requested with d=404, so
 * authors with no Gravatar return a 404 and the card <img> swaps to the bundled
 * SVG fallback via onerror. (Gravatar can't serve an SVG as its own `default`,
 * which renders as a broken image.) get_avatar_url is false when avatars are
 * disabled site-wide.
 *
 * @param \WP_Post $post Post object.
 * @return string
 */
function blogs_author_avatar( $post ) {
	if ( function_exists( 'get_field' ) ) {
		$custom = blogs_image_field_url( get_field( 'author_image', $post->ID ) );
		if ( '' !== $custom ) {
			return $custom;
		}
	}

	$url = get_avatar_url(
		(int) $post->post_author,
		array(
			'size'    => 96,
			'default' => '404',
		)
	);

	return $url ? $url : blogs_author_avatar_fallback();
}

/**
 * Render a single post card.
 *
 * @param int|\WP_Post $post Post ID or object.
 * @param array        $args {
 *     @type string $badge_tax Taxonomy whose terms show as badges on the card.
 * }
 * @return string HTML.
 */
function blogs_render_card( $post, $args = array() ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return '';
	}

	$badge_tax = isset( $args['badge_tax'] ) ? (string) $args['badge_tax'] : 'category';

	$id       = $post->ID;
	$url      = get_permalink( $id );
	$title    = get_the_title( $id );
	$image    = get_the_post_thumbnail_url( $id, 'large' );
	$date     = get_the_date( '', $id );
	$featured = blogs_is_featured( $id );

	$author_name     = blogs_author_name( $post );
	$author_avatar   = blogs_author_avatar( $post );
	$author_fallback = blogs_author_avatar_fallback();

	$badges = array();
	if ( $badge_tax && taxonomy_exists( $badge_tax ) ) {
		$terms = get_the_terms( $id, $badge_tax );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$badges = $terms;
		}
	}

	ob_start();
	?>
	<article class="rwaq-blog-card">
		<a class="rwaq-blog-card__media" href="<?php echo esc_url( $url ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?>
				<img class="rwaq-blog-card__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
			<?php else : ?>
				<span class="rwaq-blog-card__placeholder"><?php echo blogs_icon( 'thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</a>

		<div class="rwaq-blog-card__body">
			<?php if ( $featured || ! empty( $badges ) ) : ?>
				<div class="rwaq-blog-card__terms">
					<?php if ( $featured ) : ?>
						<span class="rwaq-blog-card__term rwaq-blog-card__term--featured"><?php echo esc_html__( 'المقال المميز', 'tutor-sso' ); ?></span>
					<?php endif; ?>
					<?php foreach ( $badges as $term ) : ?>
						<span class="rwaq-blog-card__term"><?php echo esc_html( $term->name ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<h3 class="rwaq-blog-card__title">
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>

			<div class="rwaq-blog-card__footer">
				<span class="rwaq-blog-card__author">
					<img class="rwaq-blog-card__author-avatar" src="<?php echo esc_url( $author_avatar ); ?>" alt="" loading="lazy" width="32" height="32" onerror="this.onerror=null;this.src='<?php echo esc_url( $author_fallback ); ?>';" />
					<?php if ( '' !== $author_name ) : ?>
						<span class="rwaq-blog-card__author-name"><?php echo esc_html( $author_name ); ?></span>
					<?php endif; ?>
				</span>

				<?php if ( '' !== $date ) : ?>
					<span class="rwaq-blog-card__date">
						<?php echo blogs_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $date ); ?></span>
					</span>
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Render a list of post cards. Shared by the shortcode and the AJAX handler.
 *
 * @param \WP_Post[] $posts Posts.
 * @param array      $args  Forwarded to blogs_render_card().
 * @return string Concatenated card HTML.
 */
function blogs_render_cards( $posts, $args = array() ) {
	$html = '';

	foreach ( (array) $posts as $post ) {
		$html .= blogs_render_card( $post, $args );
	}

	return $html;
}

/**
 * Parse a comma-separated taxonomy attribute into a clean list of existing
 * taxonomy slugs.
 *
 * @param string $value Raw attribute (e.g. "category,post_tag").
 * @return string[]
 */
function blogs_parse_taxonomies( $value ) {
	$out = array();

	foreach ( explode( ',', (string) $value ) as $tax ) {
		$tax = sanitize_key( trim( $tax ) );
		if ( '' !== $tax && taxonomy_exists( $tax ) && ! in_array( $tax, $out, true ) ) {
			$out[] = $tax;
		}
	}

	return $out;
}

/**
 * Render the category filter dropdown (multi-select, deferred "Apply").
 *
 * The list is: an "All" reset option, a "Featured" (مميز) pseudo-option that maps
 * to the ACF is_featured flag rather than a real term, then the taxonomy terms.
 * Selection/label/apply/clear behavior is wired up in blogs.js.
 *
 * @param string $taxonomy Taxonomy slug backing the term options ('' for none).
 * @return string HTML.
 */
function blogs_render_filter( $taxonomy ) {
	$terms     = $taxonomy ? blogs_terms( $taxonomy ) : array();
	$all_label = __( 'جميع الفئات', 'tutor-sso' );

	ob_start();
	?>
	<div class="rwaq-blogs__filter" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
		<button type="button" class="rwaq-blogs__filter-trigger" aria-haspopup="listbox" aria-expanded="false">
			<span class="rwaq-blogs__filter-caption"><?php echo esc_html__( 'الفئة', 'tutor-sso' ); ?></span>
			<span class="rwaq-blogs__filter-value" data-all-label="<?php echo esc_attr( $all_label ); ?>"><?php echo esc_html( $all_label ); ?></span>
			<span class="rwaq-blogs__filter-chevron" aria-hidden="true"><?php echo blogs_icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</button>

		<div class="rwaq-blogs__filter-menu" role="listbox" aria-multiselectable="true">
			<div class="rwaq-blogs__filter-list">
				<label class="rwaq-blogs__filter-option rwaq-blogs__filter-option--all">
					<input type="checkbox" class="rwaq-blogs__filter-input" data-role="all" value="__all__" checked />
					<span class="rwaq-blogs__filter-box" aria-hidden="true"><?php echo blogs_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="rwaq-blogs__filter-label"><?php echo esc_html( $all_label ); ?></span>
				</label>

				<label class="rwaq-blogs__filter-option">
					<input type="checkbox" class="rwaq-blogs__filter-input" data-role="featured" value="featured" />
					<span class="rwaq-blogs__filter-box" aria-hidden="true"><?php echo blogs_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="rwaq-blogs__filter-label"><?php echo esc_html__( 'مميز', 'tutor-sso' ); ?></span>
				</label>

				<?php foreach ( $terms as $term ) : ?>
					<label class="rwaq-blogs__filter-option">
						<input type="checkbox" class="rwaq-blogs__filter-input" data-role="term" value="<?php echo esc_attr( $term->slug ); ?>" />
						<span class="rwaq-blogs__filter-box" aria-hidden="true"><?php echo blogs_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="rwaq-blogs__filter-label"><?php echo esc_html( $term->name ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="rwaq-blogs__filter-footer">
				<button type="button" class="rwaq-blogs__filter-apply"><?php echo esc_html__( 'تطبيق', 'tutor-sso' ); ?></button>
				<button type="button" class="rwaq-blogs__filter-clear"><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_blogs per_page="9" columns="3" post_type="post" taxonomy="category" title=""].
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function blogs_listing_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			// Default to the site-wide "Blogs per page" setting; an explicit
			// per_page="…" attribute still overrides it per instance.
			'per_page'  => blogs_default_per_page(),
			'columns'   => 4,
			'post_type' => 'post',
			'taxonomy'  => 'category',
			'title'     => '',
		),
		$atts,
		'rwaq_blogs'
	);

	$per_page   = max( 1, (int) $atts['per_page'] );
	$columns    = max( 1, (int) $atts['columns'] );
	$post_type  = post_type_exists( $atts['post_type'] ) ? (string) $atts['post_type'] : 'post';
	$taxonomies = blogs_parse_taxonomies( $atts['taxonomy'] );
	$badge_tax  = ! empty( $taxonomies ) ? $taxonomies[0] : '';
	$title      = (string) $atts['title'];
	$uid        = wp_unique_id( 'rwaq-blogs-' );

	blogs_enqueue_assets();

	// Server-render the first page with the default sort (SEO + no-JS baseline).
	$data = blogs_fetch(
		1,
		$per_page,
		array(
			'ordering'  => blogs_default_sort(),
			'post_type' => $post_type,
		)
	);

	$posts     = $data['results'];
	$total     = $data['total'];
	$num_pages = $data['num_pages'];
	$has_more  = $num_pages > 1;

	$card_args = array( 'badge_tax' => $badge_tax );

	$sort_options  = blogs_sort_options();
	$default_sort  = blogs_default_sort();
	$default_label = isset( $sort_options[ $default_sort ] ) ? $sort_options[ $default_sort ] : '';

	ob_start();
	?>
	<div
		class="rwaq-blogs"
		data-per-page="<?php echo esc_attr( $per_page ); ?>"
		data-columns="<?php echo esc_attr( $columns ); ?>"
		data-post-type="<?php echo esc_attr( $post_type ); ?>"
		data-badge-tax="<?php echo esc_attr( $badge_tax ); ?>"
		data-taxonomy="<?php echo esc_attr( $badge_tax ); ?>"
		data-default-sort="<?php echo esc_attr( $default_sort ); ?>"
		data-page="1"
		data-has-more="<?php echo $has_more ? 'true' : 'false'; ?>"
	>
		<div class="rwaq-blogs__overlay" hidden>
			<?php echo blogs_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php if ( '' !== $title ) : ?>
			<div class="rwaq-blogs__header">
				<h2 class="rwaq-blogs__title"><?php echo esc_html( $title ); ?></h2>
				<span class="rwaq-blogs__total-badge">
					<span class="rwaq-blogs__total-count" data-total-count><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<?php echo esc_html__( 'مقالة', 'tutor-sso' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<div class="rwaq-blogs__toolbar">
			<div class="rwaq-blogs__toolbar-group">
				<div class="rwaq-blogs__searchbar" role="search">
					<span class="rwaq-blogs__search-icon" aria-hidden="true"><?php echo blogs_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-search"><?php echo esc_html__( 'ابحث في المقالات', 'tutor-sso' ); ?></label>
					<input
						type="search"
						id="<?php echo esc_attr( $uid ); ?>-search"
						class="rwaq-blogs__search-input"
						placeholder="<?php echo esc_attr__( 'ابحث في المدونات…', 'tutor-sso' ); ?>"
						autocomplete="off"
					/>
				</div>

				<?php echo blogs_render_filter( $badge_tax ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="rwaq-blogs__sort">
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-sort"><?php echo esc_html__( 'ترتيب المقالات', 'tutor-sso' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-sort" class="rwaq-blogs__sort-select">
					<?php foreach ( $sort_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $default_sort ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="rwaq-blogs__sort-trigger" aria-haspopup="listbox" aria-expanded="false">
					<span class="rwaq-blogs__sort-caption"><?php echo esc_html__( 'الترتيب حسب', 'tutor-sso' ); ?></span>
					<span class="rwaq-blogs__sort-value"><?php echo esc_html( $default_label ); ?></span>
					<span class="rwaq-blogs__sort-chevron" aria-hidden="true"><?php echo blogs_icon( 'caret' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</button>
				<div class="rwaq-blogs__sort-menu" role="listbox"></div>
			</div>
		</div>

		<div class="rwaq-blogs__results">
			<div class="rwaq-blogs__result-count" data-result-count>
				<?php
				/* translators: %s: number of posts found. */
				echo esc_html( sprintf( __( 'تم العثور على %s مقالة', 'tutor-sso' ), number_format_i18n( $total ) ) );
				?>
			</div>
			<div class="rwaq-blogs__chips" aria-live="polite"></div>
			<button type="button" class="rwaq-blogs__clear-all" hidden><?php echo esc_html__( 'مسح الكل', 'tutor-sso' ); ?></button>
		</div>

		<div class="rwaq-blogs__grid" style="--rwaq-blogs-columns: <?php echo esc_attr( $columns ); ?>;" aria-live="polite" aria-busy="false">
			<?php echo blogs_render_cards( $posts, $card_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<div class="rwaq-blogs__status" role="status"><?php echo empty( $posts ) ? esc_html__( 'لا توجد مقالات مطابقة.', 'tutor-sso' ) : ''; ?></div>

		<div class="rwaq-blogs__loader" hidden>
			<?php echo blogs_loader_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<div class="rwaq-blogs__sentinel" aria-hidden="true"></div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'rwaq_blogs', __NAMESPACE__ . '\\blogs_listing_shortcode' );

/**
 * Document the [rwaq_blogs] shortcode in the admin "Available Shortcodes"
 * reference (Settings → Tutor LMS SSO).
 *
 * @param array $shortcodes Existing shortcode definitions.
 * @return array
 */
function blogs_register_admin_shortcode( $shortcodes ) {
	$shortcodes[] = array(
		'tag'         => 'rwaq_blogs',
		'title'       => __( 'Blogs Listing', 'tutor-sso' ),
		'example'     => '[rwaq_blogs per_page="8" columns="4" post_type="post" taxonomy="category"]',
		'description' => __( 'Listing of WordPress posts with a category filter dropdown (plus a Featured option), search, sorting, active-filter chips, and AJAX infinite scroll.', 'tutor-sso' ),
		'attributes'  => array(
			'per_page'  => __( 'Posts per page / infinite-scroll batch. Defaults to the "Blogs per page" setting (8 if unset).', 'tutor-sso' ),
			'columns'   => __( 'Grid column count. Default: 4.', 'tutor-sso' ),
			'post_type' => __( 'Post type to list. Default: "post".', 'tutor-sso' ),
			'taxonomy'  => __( 'Taxonomy for the category filter dropdown and card badges (e.g. "category"). Default: "category".', 'tutor-sso' ),
			'title'     => __( 'Optional heading shown above the listing. Leave blank to hide it. Default: empty.', 'tutor-sso' ),
		),
	);

	return $shortcodes;
}
add_filter( 'tutor_sso_admin_shortcodes', __NAMESPACE__ . '\\blogs_register_admin_shortcode' );
