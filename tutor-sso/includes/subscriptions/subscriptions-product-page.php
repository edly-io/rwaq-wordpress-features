<?php
/**
 * Keep subscription plan products out of sight.
 *
 * A plan's product page is WooCommerce's generic product layout, not the
 * plans page design, and it shows one plan with nothing to compare it to.
 * Plans are chosen on the plans page, so the product page redirects there, and
 * WooCommerce is told the product is not visible so it stops linking to it —
 * the item name in the cart, checkout, order details and order emails becomes
 * plain text instead of a link into the page that would only redirect.
 *
 * Buying is unaffected: adding to the cart and paying check whether a product
 * is purchasable, never whether it is visible.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report subscription plans as not visible.
 *
 * Only when a plans page exists: without one, the product page is the only
 * place a plan can be bought from, so it stays visible and reachable.
 *
 * @param bool $visible    Whether WooCommerce considers the product visible.
 * @param int  $product_id Product ID.
 * @return bool
 */
function subscription_product_visible( $visible, $product_id ) {
	if ( ! $visible || ! subscription_product_is_subscription( $product_id ) ) {
		return $visible;
	}

	return '' === subscription_plans_page_url();
}
add_filter( 'woocommerce_product_is_visible', __NAMESPACE__ . '\\subscription_product_visible', 10, 2 );

/**
 * Send a plan's product page to the plans page.
 *
 * After the course and program product redirects (priorities 10 and 11),
 * which answer for their own products and never for a plan.
 *
 * @return void
 */
function subscription_product_redirect() {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product_id = get_queried_object_id();

	if ( ! $product_id || ! subscription_product_is_subscription( $product_id ) ) {
		return;
	}

	// No plans page: leave the product page working rather than lose the
	// visitor, since it is then the only way to buy the plan.
	$url = subscription_plans_page_url();

	if ( '' === $url ) {
		return;
	}

	/**
	 * Filter the redirect status. 302 for the same reason as the shop
	 * redirect: a 301 is cached hard and could not be undone from here.
	 *
	 * @param int $status     HTTP status code.
	 * @param int $product_id Plan product being viewed.
	 */
	$status = (int) apply_filters( 'tutor_sso_subscription_product_redirect_status', 302, $product_id );

	wp_safe_redirect( $url, $status );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\subscription_product_redirect', 12 );
