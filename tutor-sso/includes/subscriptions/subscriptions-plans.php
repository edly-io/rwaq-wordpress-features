<?php
/**
 * The subscription plans page (Figma 10647:679).
 *
 * A "Rwaq Subscription Plans" page template. The page it is assigned to becomes
 * the plans page: it renders the page's own content (so a heading written in
 * Elementor shows above the plans) followed by the plans, and it is where every
 * "subscribe" link on the site points — subscription_plans_page_url().
 *
 * The plans themselves are the Subscription products: one card each, grouped by
 * billing period, with a monthly / yearly toggle that shows one group at a time.
 * The same block is available anywhere as [rwaq_subscription_plans].
 *
 * Each card's text comes from its product: name, short description, price,
 * billing period, and the "Plan features" list (subscriptions-product-type.php).
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-template slug stored in `_wp_page_template`.
 */
const SUBSCRIPTION_PLANS_TEMPLATE = 'tutor-sso-rwaq-plans';

/**
 * Transient caching which page uses the template.
 */
const SUBSCRIPTION_PLANS_PAGE_CACHE = 'tutor_sso_plans_page_id';

/**
 * Asset handle, for both the stylesheet and the script.
 */
const SUBSCRIPTION_PLANS_ASSET = 'tutor-sso-subscription-plans';

/**
 * Add "Rwaq Subscription Plans" to Page Attributes → Template.
 *
 * @param array $templates Page templates, slug => label.
 * @return array
 */
function subscription_plans_register_template( $templates ) {
	$templates[ SUBSCRIPTION_PLANS_TEMPLATE ] = __( 'Rwaq Subscription Plans', 'tutor-sso' );

	return $templates;
}
add_filter( 'theme_page_templates', __NAMESPACE__ . '\\subscription_plans_register_template' );

/**
 * Whether the current request is a page on the plans template.
 *
 * @return bool
 */
function subscription_plans_is_template_page() {
	return is_singular( 'page' ) && SUBSCRIPTION_PLANS_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

/**
 * Load the plugin's template file for a page on the plans template.
 *
 * @param string $template Template the theme resolved.
 * @return string
 */
function subscription_plans_load_template( $template ) {
	if ( subscription_plans_is_template_page() ) {
		$file = TUTOR_SSO_PATH . 'templates/page-rwaq-plans.php';

		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\subscription_plans_load_template' );

/**
 * The page using the plans template, if any.
 *
 * Same rule as the search page (search_find_template_page()): published pages
 * only, and when several use the template the oldest — lowest ID — wins, so
 * publishing a second one (a copy, a draft made live) cannot silently move every
 * subscribe link on the site. Cached; the cache is dropped whenever a page is
 * saved or deleted, so reassigning the template takes effect at once.
 *
 * @return int Page ID, or 0.
 */
function subscription_plans_find_page() {
	$cached = get_transient( SUBSCRIPTION_PLANS_PAGE_CACHE );

	// A cached page that is no longer published — changed some way that did not
	// pass through the hooks below — is looked up again rather than linked to.
	if ( false !== $cached && ( 0 === (int) $cached || 'publish' === get_post_status( (int) $cached ) ) ) {
		return (int) $cached;
	}

	$ids = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_wp_page_template',
					'value' => SUBSCRIPTION_PLANS_TEMPLATE,
				),
			),
		)
	);

	$id = ! empty( $ids ) ? (int) $ids[0] : 0;

	set_transient( SUBSCRIPTION_PLANS_PAGE_CACHE, $id, DAY_IN_SECONDS );

	return $id;
}

/**
 * Drop the cached lookup when a page changes.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function subscription_plans_flush_page_cache( $post_id ) {
	if ( 'page' === get_post_type( $post_id ) ) {
		delete_transient( SUBSCRIPTION_PLANS_PAGE_CACHE );
	}
}
add_action( 'save_post', __NAMESPACE__ . '\\subscription_plans_flush_page_cache' );
// Before, not after: once a page is deleted get_post_type() no longer knows it
// was one, so a flush on `deleted_post` would never fire for the plans page.
// Trashing and unpublishing go through save_post.
add_action( 'before_delete_post', __NAMESPACE__ . '\\subscription_plans_flush_page_cache' );

/**
 * The plans page URL — where every "subscribe" link goes.
 *
 * @return string URL, or '' when no page uses the template.
 */
function subscription_plans_page_url() {
	$id  = subscription_plans_find_page();
	$url = $id ? (string) get_permalink( $id ) : '';

	/**
	 * Filter the plans page URL.
	 *
	 * @param string $url URL of the page on the plans template, or ''.
	 */
	return (string) apply_filters( 'tutor_sso_subscription_page_url', $url );
}

/**
 * Register the page's stylesheet and script; enqueued when the block renders.
 *
 * @return void
 */
function subscription_plans_register_assets() {
	wp_register_style(
		SUBSCRIPTION_PLANS_ASSET,
		TUTOR_SSO_URL . 'assets/css/subscription-plans.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	wp_register_script(
		SUBSCRIPTION_PLANS_ASSET,
		TUTOR_SSO_URL . 'assets/js/subscription-plans.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\subscription_plans_register_assets' );

/**
 * The plans to show, grouped by billing period.
 *
 * Published, purchasable Subscription products, in their menu order.
 *
 * @return array<string,\WC_Product[]> period => products; only periods with plans.
 */
function subscription_plans_by_period() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$products = wc_get_products(
		array(
			'type'    => array( SUBSCRIPTION_PRODUCT_TYPE ),
			'status'  => 'publish',
			'limit'   => -1,
			'orderby' => 'menu_order',
			'order'   => 'ASC',
		)
	);

	$groups = array();

	foreach ( array_keys( subscription_periods() ) as $period ) {
		$groups[ $period ] = array();
	}

	foreach ( (array) $products as $product ) {
		if ( ! is_object( $product ) || ! $product->is_purchasable() ) {
			continue;
		}

		$period = subscription_period( $product->get_id() );

		if ( isset( $groups[ $period ] ) ) {
			$groups[ $period ][] = $product;
		}
	}

	return array_filter( $groups );
}

/**
 * A price as the card shows it: no decimals when it is a whole number.
 *
 * "480", not "480.00" — the design's large figure — while a price with a
 * fraction keeps the store's decimals and separators.
 *
 * @param float $amount Price.
 * @return string
 */
function subscription_plans_format_amount( $amount ) {
	$amount   = (float) $amount;
	$decimals = ( floor( $amount ) === $amount ) ? 0 : wc_get_price_decimals();

	return number_format( $amount, $decimals, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
}

/**
 * "/ شهريًا" or "/ سنويًا".
 *
 * @param string $period monthly | yearly.
 * @return string
 */
function subscription_plans_per_label( $period ) {
	return 'yearly' === $period ? __( 'سنويًا', 'tutor-sso' ) : __( 'شهريًا', 'tutor-sso' );
}

/**
 * Where a plan's button goes.
 *
 * Straight to checkout with the plan in the cart. A logged-out visitor goes
 * through SSO login first and comes back to that same address, so the plan is
 * added to their own account's cart rather than to a guest one.
 *
 * @param \WC_Product $product Plan.
 * @return string
 */
function subscription_plans_buy_url( $product ) {
	$checkout = add_query_arg( 'add-to-cart', (int) $product->get_id(), wc_get_checkout_url() );

	if ( is_user_logged_in() ) {
		return $checkout;
	}

	return add_query_arg(
		array(
			LOGIN_TRIGGER => '1',
			'redirect_to' => rawurlencode( $checkout ),
		),
		home_url( '/' )
	);
}

/**
 * The tick used in the features list.
 *
 * @return string SVG markup.
 */
function subscription_plans_check_icon() {
	return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
}

/**
 * One plan card.
 *
 * @param \WC_Product $product     Plan.
 * @param string      $period      Its billing period.
 * @param int         $active_plan Product the customer is subscribed to, or 0.
 * @return string HTML.
 */
function subscription_plans_render_card( $product, $period, $active_plan ) {
	$name     = $product->get_name();
	$desc     = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
	$features = subscription_features( $product->get_id() );
	$price    = (float) $product->get_price();
	$regular  = (float) $product->get_regular_price();
	$on_sale  = $product->is_on_sale() && $regular > $price;
	$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

	ob_start();
	?>
	<article class="rwaq-plan">
		<h3 class="rwaq-plan__name"><?php echo esc_html( $name ); ?></h3>

		<?php if ( '' !== $desc ) : ?>
			<p class="rwaq-plan__desc"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>

		<p class="rwaq-plan__price">
			<span class="rwaq-plan__amount"><?php echo esc_html( subscription_plans_format_amount( $price ) ); ?></span>
			<span class="rwaq-plan__currency"><?php echo esc_html( $symbol ); ?></span>
			<span class="rwaq-plan__per">/ <?php echo esc_html( subscription_plans_per_label( $period ) ); ?></span>
			<?php if ( $on_sale ) : ?>
				<del class="rwaq-plan__was"><?php echo esc_html( subscription_plans_format_amount( $regular ) . ' ' . $symbol ); ?></del>
			<?php endif; ?>
		</p>

		<?php if ( $active_plan ) : ?>
			<div class="rwaq-plan__subscribed">
				<span>
					<?php
					echo esc_html(
						$active_plan === (int) $product->get_id()
							? __( 'هذه باقتك الحالية', 'tutor-sso' )
							: __( 'لديك اشتراك فعّال بالفعل', 'tutor-sso' )
					);
					?>
				</span>
				<?php if ( function_exists( 'wc_get_account_endpoint_url' ) && defined( __NAMESPACE__ . '\\SUBSCRIPTION_ACCOUNT_ENDPOINT' ) ) : ?>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( SUBSCRIPTION_ACCOUNT_ENDPOINT ) ); ?>"><?php esc_html_e( 'إدارة الاشتراك', 'tutor-sso' ); ?></a>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<a class="rwaq-plan__cta" href="<?php echo esc_url( subscription_plans_buy_url( $product ) ); ?>">
				<?php
				/* translators: %s: plan name. */
				echo esc_html( sprintf( __( 'اختر %s', 'tutor-sso' ), $name ) );
				?>
			</a>
		<?php endif; ?>

		<?php if ( 'SAR' === get_woocommerce_currency() ) : ?>
			<p class="rwaq-plan__note"><?php esc_html_e( 'الأسعار بالريال السعودي', 'tutor-sso' ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $features ) ) : ?>
			<hr class="rwaq-plan__divider" />
			<h4 class="rwaq-plan__features-title"><?php esc_html_e( 'ماذا تشمل الباقة', 'tutor-sso' ); ?></h4>
			<ul class="rwaq-plan__features">
				<?php foreach ( $features as $feature ) : ?>
					<li>
						<span class="rwaq-plan__check"><?php echo subscription_plans_check_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
						<span><?php echo esc_html( $feature ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Whether the block has rendered on this request.
 *
 * Lets the page template add the plans after the page content only when the
 * content has not already placed them itself.
 *
 * @param bool|null $set Mark as rendered.
 * @return bool
 */
function subscription_plans_rendered( $set = null ) {
	static $rendered = false;

	if ( true === $set ) {
		$rendered = true;
	}

	return $rendered;
}

/**
 * [rwaq_subscription_plans] — the heading, the toggle and the plan cards.
 *
 * The heading and subtitle default to the design's text. Either can be changed
 * with an attribute, or left out by passing it empty:
 * [rwaq_subscription_plans title="…" subtitle=""].
 *
 * @param array|string $atts Shortcode attributes: title, subtitle.
 * @return string HTML.
 */
function subscription_plans_shortcode( $atts = array() ) {
	if ( ! subscriptions_enabled() || ! function_exists( 'wc_get_checkout_url' ) ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'title'    => __( 'اختر الباقة المناسبة لرحلة تعلّمك', 'tutor-sso' ),
			'subtitle' => __( 'باقة واحدة بسيطة تمنحك وصولًا كاملًا إلى المحتوى. بدّل بين الفوترة الشهرية والسنوية وقارن الأسعار بسهولة.', 'tutor-sso' ),
		),
		$atts,
		'rwaq_subscription_plans'
	);

	$title    = trim( (string) $atts['title'] );
	$subtitle = trim( (string) $atts['subtitle'] );

	// The page's main heading on the plans page; a section heading anywhere
	// else, where the page will already have its own h1.
	$heading_tag = subscription_plans_is_template_page() ? 'h1' : 'h2';

	$groups = subscription_plans_by_period();

	if ( empty( $groups ) ) {
		return '';
	}

	subscription_plans_rendered( true );
	wp_enqueue_style( SUBSCRIPTION_PLANS_ASSET );
	wp_enqueue_script( SUBSCRIPTION_PLANS_ASSET );

	// Yearly first when there are yearly plans, as the design opens on it.
	$default = isset( $groups['yearly'] ) ? 'yearly' : (string) array_key_first( $groups );
	$labels  = subscription_periods();
	$active  = is_user_logged_in() && function_exists( __NAMESPACE__ . '\\subscription_user_active_product' )
		? subscription_user_active_product( get_current_user_id() )
		: 0;

	// Unique per block, in case the shortcode is placed more than once.
	static $instance = 0;
	++$instance;

	ob_start();
	?>
	<div class="rwaq-plans" data-rwaq-plans>
		<?php if ( '' !== $title || '' !== $subtitle ) : ?>
			<header class="rwaq-plans__header">
				<?php if ( '' !== $title ) : ?>
					<<?php echo esc_html( $heading_tag ); ?> class="rwaq-plans__title"><?php echo esc_html( $title ); ?></<?php echo esc_html( $heading_tag ); ?>>
				<?php endif; ?>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="rwaq-plans__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<?php if ( count( $groups ) > 1 ) : ?>
			<div class="rwaq-plans__toggle" role="tablist" aria-label="<?php esc_attr_e( 'مدة الاشتراك', 'tutor-sso' ); ?>">
				<?php foreach ( array_keys( $groups ) as $period ) : ?>
					<button
						type="button"
						role="tab"
						class="rwaq-plans__tab"
						id="<?php echo esc_attr( 'rwaq-plans-tab-' . $instance . '-' . $period ); ?>"
						aria-controls="<?php echo esc_attr( 'rwaq-plans-' . $instance . '-' . $period ); ?>"
						aria-selected="<?php echo $period === $default ? 'true' : 'false'; ?>"
						tabindex="<?php echo $period === $default ? '0' : '-1'; ?>"
						data-rwaq-period="<?php echo esc_attr( $period ); ?>"
					><?php echo esc_html( $labels[ $period ] ?? $period ); ?></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php foreach ( $groups as $period => $products ) : ?>
			<div
				class="rwaq-plans__group"
				id="<?php echo esc_attr( 'rwaq-plans-' . $instance . '-' . $period ); ?>"
				<?php if ( count( $groups ) > 1 ) : ?>
					role="tabpanel"
					aria-labelledby="<?php echo esc_attr( 'rwaq-plans-tab-' . $instance . '-' . $period ); ?>"
				<?php endif; ?>
				data-rwaq-period="<?php echo esc_attr( $period ); ?>"
				<?php echo $period === $default ? '' : 'hidden'; ?>
			>
				<?php
				foreach ( $products as $product ) {
					echo subscription_plans_render_card( $product, $period, (int) $active ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
				}
				?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'rwaq_subscription_plans', __NAMESPACE__ . '\\subscription_plans_shortcode' );
