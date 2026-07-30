<?php

/**
 * Blog detail: single-post template loader + blog detail render / shortcode.
 *
 * Companion to the programs pattern (see program-single.php /
 * programs-detail.php). Unlike programs — which fetch their detail from the LMS
 * public API — a blog post is native WordPress content, so this file renders the
 * current `post`'s own title, image, meta and content directly; no external
 * client or cache is involved.
 *
 * The layout follows the blog detail mockup: a centered 900px article column
 * (title, category pills, hero, author row, then the post content) followed by a
 * wider 1232px "related posts" grid drawn from posts sharing a category.
 *
 * A few fields come from custom fields (ACF first, post meta fallback):
 *   - author_name   Display name for the byline (falls back to the WP author).
 *   - author_image  Avatar image (URL / attachment ID / ACF image array).
 *   - is_featured    When truthy, an orange "مميز" pill is shown.
 *
 * Two responsibilities live here:
 *
 *   1. A `single_template` filter that serves templates/single-blog.php for
 *      single `post` views, so the styled detail view is used by default with no
 *      page editing or theme work (a theme may still override with its own
 *      single-blog.php).
 *   2. The [rwaq_blog_detail] shortcode (used by that template) which renders the
 *      detail view for the current post.
 *
 * Usage:
 *   [rwaq_blog_detail]              render the current post in the loop
 *   [rwaq_blog_detail id="123"]     render an explicit post by ID
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Post type whose single view uses the plugin's blog template.
 *
 * @return string
 */
function blog_single_post_type()
{
	/**
	 * Filter the post type served by the plugin's single-blog template.
	 *
	 * @param string $post_type Default 'post'.
	 */
	return (string) apply_filters('tutor_sso_blog_post_type', 'post');
}

/**
 * Use the plugin's single-blog.php for single blog posts.
 *
 * Runs on the `single_template` filter, which fires with the template the theme
 * hierarchy resolved. We only intervene for the blog post type, and we defer to
 * a theme-provided single-blog.php when one exists.
 *
 * @param string $template Path to the template the hierarchy resolved.
 * @return string
 */
function blog_single_template($template)
{
	if (! is_singular(blog_single_post_type())) {
		return $template;
	}

	// Respect a theme-provided single-blog.php if one exists.
	$theme_template = locate_template(array('single-blog.php'));
	if ($theme_template) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/single-blog.php';

	return file_exists($plugin_template) ? $plugin_template : $template;
}
add_filter('single_template', __NAMESPACE__ . '\\blog_single_template');

/**
 * Register the (lazily enqueued) combined blog stylesheet.
 *
 * This is the single registration of 'tutor-sso-blog' → blog.css, which holds
 * both the detail and the listing styles. The listing (blogs-catalog.php /
 * blogs-page-template.php) enqueues this same handle rather than registering its
 * own, so the file is only ever registered once.
 *
 * Depends on the IBM Plex Sans Arabic webfont ('tutor-sso-programs-font',
 * registered and enqueued globally in tutor-sso.php) so it is guaranteed to load
 * first. Like programs.css, blog.css uses direction-agnostic layout and needs no
 * RTL companion stylesheet.
 */
function blog_detail_register_assets()
{
	wp_register_style(
		'tutor-sso-blog',
		TUTOR_SSO_URL . 'assets/css/blog.css',
		array('tutor-sso-programs-font'),
		TUTOR_SSO_VERSION
	);
}
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\blog_detail_register_assets');

/**
 * Return an inline SVG icon used on the blog detail view, by name.
 *
 * The markup is static and trusted (no user input), so callers echo the result
 * directly without escaping.
 *
 * @param string $name One of: calendar, chevron-next, chevron-prev.
 * @return string SVG markup, or '' for an unknown name.
 */
function blog_detail_icon($name)
{
	$icons = array(
		'calendar'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4.5" width="18" height="17" rx="2.5"/><path d="M3 9h18M8 3v3M16 3v3"/></svg>',
		'chevron-next' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
		'chevron-prev' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>',
	);

	return isset($icons[$name]) ? $icons[$name] : '';
}

/**
 * Read a custom field: ACF's get_field() first (so return-format handling and
 * field aliases work), then a raw post meta fallback.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @return mixed Field value (may be string, array or attachment ID), or ''.
 */
function blog_detail_field($post_id, $field)
{
	if (function_exists('get_field')) {
		$value = get_field($field, $post_id);
		if (! empty($value)) {
			return $value;
		}
	}

	return get_post_meta($post_id, $field, true);
}

/**
 * Byline name for a post: the `author_name` custom field, else the WP author's
 * display name.
 *
 * @param \WP_Post $post Post object.
 * @return string
 */
function blog_detail_author_name($post)
{
	$name = trim((string) blog_detail_field($post->ID, 'author_name'));
	if ('' !== $name) {
		return $name;
	}

	return (string) get_the_author_meta('display_name', $post->post_author);
}

/**
 * Render the blog detail view for a post following the mockup: a centered
 * article column (title, pills, hero, byline, content) plus a related-posts
 * grid built from other posts in the same categories.
 *
 * @param int|\WP_Post $post Post ID or object.
 * @return string HTML, or '' when the post cannot be resolved.
 */
function blog_detail_render($post)
{
	$post = get_post($post);

	if (! $post instanceof \WP_Post) {
		return '';
	}

	$post_id = $post->ID;

	$title    = get_the_title($post);
	$image    = get_the_post_thumbnail_url($post, 'large');
	$date     = get_the_date('', $post);
	$author   = blog_detail_author_name($post);
	// Reuse the catalog avatar resolution so the byline uses the same bundled
	// SVG fallback (avatar-fallback-author.svg) as the listing cards.
	$avatar          = blogs_author_avatar($post);
	$avatar_fallback = blogs_author_avatar_fallback();
	$featured = ! empty(blog_detail_field($post_id, 'is_featured'));

	$categories = get_the_category($post_id);
	if (is_wp_error($categories)) {
		$categories = array();
	}

	// Run the content through the_content filters (shortcodes, embeds, wpautop).
	$content = apply_filters('the_content', $post->post_content);

	// Related posts: strictly other posts sharing a category (section hidden if
	// none — see the "Only category matches" product decision).
	$cat_ids = wp_get_post_categories($post_id);
	$related = array();
	if (! empty($cat_ids)) {
		$related = get_posts(
			array(
				'post_type'           => blog_single_post_type(),
				'posts_per_page'      => 4,
				'post__not_in'        => array($post_id),
				'category__in'        => $cat_ids,
				'ignore_sticky_posts' => true,
			)
		);
	}

	// Adjacent posts for the prev / next navigation.
	$next_post = get_next_post();
	$prev_post = get_previous_post();

	ob_start();
?>
	<div class="rwaq-bd" dir="rtl">

		<div class="rwaq-bd__breadcrumb-bar">
			<nav class="rwaq-bd__breadcrumb" aria-label="breadcrumb">
				<a href="<?php echo esc_url(home_url('/blog')); ?>"><?php echo esc_html__('المدونات', 'tutor-sso'); ?></a>
				<?php if ('' !== $title) : ?>
					<span class="rwaq-bd__sep">‹</span>
					<span class="rwaq-bd__current"><?php echo esc_html($title); ?></span>
				<?php endif; ?>
			</nav>
		</div>

		<article class="rwaq-bd__article">

			<?php if ('' !== $title) : ?>
				<h1 class="rwaq-bd__title"><?php echo esc_html($title); ?></h1>
			<?php endif; ?>

			<?php if ($featured || ! empty($categories)) : ?>
				<div class="rwaq-bd__pills">
					<?php if ($featured) : ?>
						<span class="rwaq-bd__pill rwaq-bd__pill--featured"><?php echo esc_html__('مميز', 'tutor-sso'); ?></span>
					<?php endif; ?>
					<?php foreach ($categories as $category) : ?>
						<span class="rwaq-bd__pill rwaq-bd__pill--cat"><?php echo esc_html($category->name); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ($image) : ?>
				<div class="rwaq-bd__hero">
					<img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
				</div>
			<?php endif; ?>

			<div class="rwaq-bd__byline">
				<div class="rwaq-bd__author">
					<?php if ('' !== $avatar) : ?>
						<span class="rwaq-bd__author-avatar"><img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($author); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo esc_url($avatar_fallback); ?>';" /></span>
					<?php endif; ?>
					<?php if ('' !== $author) : ?>
						<span class="rwaq-bd__author-name"><?php echo esc_html($author); ?></span>
					<?php endif; ?>
				</div>
				<?php if ('' !== $date) : ?>
					<div class="rwaq-bd__date">
						<span><?php echo esc_html($date); ?></span>
						<?php echo blog_detail_icon('calendar'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</div>

			<hr class="rwaq-bd__rule">

			<div class="rwaq-bd__prose">
				<?php echo wp_kses_post($content); ?>
			</div>

			<hr class="rwaq-bd__rule rwaq-bd__rule--end">

			<nav class="rwaq-bd__adjacent" aria-label="post navigation">
				<?php if ($next_post instanceof \WP_Post) : ?>
					<a class="rwaq-bd__nav" href="<?php echo esc_url(get_permalink($next_post)); ?>">
						<?php echo blog_detail_icon('chevron-next'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html__('المقالات التالية', 'tutor-sso'); ?>
					</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
				<?php if ($prev_post instanceof \WP_Post) : ?>
					<a class="rwaq-bd__nav" href="<?php echo esc_url(get_permalink($prev_post)); ?>">
						<?php echo esc_html__('المقالات السابقة', 'tutor-sso'); ?>
						<?php echo blog_detail_icon('chevron-prev'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				<?php else : ?>
					<span></span>
				<?php endif; ?>
			</nav>

		</article>

		<?php if (! empty($related)) : ?>
			<section class="rwaq-bd__related">
				<h2 class="rwaq-bd__related-title"><?php echo esc_html__('مقالات ذات صلة', 'tutor-sso'); ?></h2>
				<div class="rwaq-bd__related-grid">
					<?php
					// Reuse the catalog card renderer so the recent-posts cards are
					// identical to the listing page's cards.
					foreach ($related as $item) {
						echo blogs_render_card($item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			</section>
		<?php endif; ?>

	</div>
<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_blog_detail id=""].
 *
 * Renders the current post (or an explicit post by ID) using the blog detail
 * view. Enqueues the blog stylesheet only when actually rendered.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function blog_detail_shortcode($atts)
{
	$atts = shortcode_atts(
		array(
			'id' => '',
		),
		$atts,
		'rwaq_blog_detail'
	);

	wp_enqueue_style('tutor-sso-blog');

	$post_id = '' !== trim((string) $atts['id']) ? (int) $atts['id'] : get_the_ID();

	$html = blog_detail_render($post_id);

	if ('' === $html) {
		return '<div class="rwaq-blog-detail rwaq-blog-detail--error">'
			. esc_html__('لم يتم العثور على المقال.', 'tutor-sso')
			. '</div>';
	}

	return $html;
}
add_shortcode('rwaq_blog_detail', __NAMESPACE__ . '\\blog_detail_shortcode');
