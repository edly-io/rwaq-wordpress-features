<?php
/**
 * [rwaq_price] — a course's or a program's price, for an Elementor Loop item.
 *
 * Shows what the catalog cards show, through the same renderer
 * (sso_render_price_html()):
 *
 *   - not paid             → "مجاني";
 *   - paid, on sale        → the sale price, with the regular price struck through;
 *   - paid, no sale        → the regular price;
 *   - paid, but no price   → nothing (an empty price is not "free").
 *
 * The catalog cards read prices from the LMS API; a loop iterates WordPress
 * posts, so this reads the synced fields instead — through the same readers
 * the WooCommerce product sync uses, so the shortcode, the product and the
 * checkout always agree:
 *
 *   - course:  is_paid, course_regular_price, course_sale_price;
 *   - program: is_paid, program_regular_price, program_sale_price (falling back
 *              to course_* for a program not yet synced under the new names —
 *              see program_product_price_field()).
 *
 * Usage, inside a Loop item: [rwaq_price]. Outside one, name the post:
 * [rwaq_price id="123"].
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stylesheet handle.
 */
const PRICE_SHORTCODE_STYLE = 'tutor-sso-price';

/**
 * Register the stylesheet; it is enqueued only when the shortcode renders.
 *
 * @return void
 */
function price_shortcode_register_style() {
	wp_register_style(
		PRICE_SHORTCODE_STYLE,
		TUTOR_SSO_URL . 'assets/css/price.css',
		array(),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\price_shortcode_register_style' );

/**
 * A course's or program's price, read off the post.
 *
 * @param int $post_id Course or program post ID.
 * @return array{paid:bool,regular:mixed,sale:mixed}|null Null for any other post type.
 */
function price_shortcode_values( $post_id ) {
	$post_id = (int) $post_id;
	$type    = get_post_type( $post_id );

	if ( Courses\Courses_REST_Controller::POST_TYPE === $type ) {
		return array(
			'paid'    => (bool) filter_var( course_product_field( 'is_paid', $post_id ), FILTER_VALIDATE_BOOLEAN ),
			'regular' => course_product_field( 'course_regular_price', $post_id ),
			'sale'    => course_product_field( 'course_sale_price', $post_id ),
		);
	}

	if ( Programs\Programs_REST_Controller::POST_TYPE === $type ) {
		return array(
			'paid'    => (bool) filter_var( program_product_field( 'is_paid', $post_id ), FILTER_VALIDATE_BOOLEAN ),
			'regular' => program_product_price_field( 'regular', $post_id ),
			'sale'    => program_product_price_field( 'sale', $post_id ),
		);
	}

	return null;
}

/**
 * Render [rwaq_price].
 *
 * @param array|string $atts Shortcode attributes: id (optional).
 * @return string
 */
function price_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'rwaq_price' );

	$post_id = absint( $atts['id'] );

	if ( ! $post_id ) {
		$post_id = (int) get_the_ID();
	}

	if ( ! $post_id ) {
		return '';
	}

	$price = price_shortcode_values( $post_id );

	if ( null === $price ) {
		return '';
	}

	$html = sso_render_price_html( 'rwaq-price', $price['paid'], $price['regular'], $price['sale'] );

	if ( '' !== $html ) {
		// Enqueued here rather than site-wide: printed late, in the footer,
		// when the shortcode runs after the head has gone out.
		wp_enqueue_style( PRICE_SHORTCODE_STYLE );
	}

	return $html;
}
add_shortcode( 'rwaq_price', __NAMESPACE__ . '\\price_shortcode' );
