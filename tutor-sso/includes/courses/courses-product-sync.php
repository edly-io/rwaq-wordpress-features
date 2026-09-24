<?php
/**
 * Mirror paid courses into WooCommerce products for Open edX Commerce.
 *
 * A course that syncs with `is_paid` gets a matching simple product carrying the
 * course's title, image and prices. The product is flagged for the Open edX
 * Commerce plugin (wordpress.org/plugins/openedx-commerce), which enrolls the
 * buyer on order completion when — and only when — the product has both:
 *
 *   is_openedx_course = 'yes'   (its "Open edX Course" product checkbox)
 *   _course_id        = <key>   (the Open edX course id)
 *
 * so this writes exactly those, plus `_mode` for the enrollment mode. See
 * Openedx_Commerce_Admin::select_course_items() for the check it performs.
 *
 * The product is not a storefront page: catalog visibility is `hidden` and any
 * request for its permalink redirects to the course detail page, which is where
 * the buy button lives. The product exists only to be added to the cart and to
 * carry the Open edX metadata through the order.
 *
 * Lifecycle — a course that stops being paid (`is_paid` false, or no usable
 * price) moves its product to `draft` rather than the trash, so re-enabling it
 * restores the same product id and its order history stays intact.
 *
 * The mirror runs on three triggers: a course sync (`tutor_sso_course_synced`),
 * saving a course in wp-admin, and a course changing post status — so a course
 * that is trashed or unpublished stops being sold.
 *
 * Everything here no-ops when WooCommerce is not active.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta pointing back at the course post the product mirrors. This, not
 * `_course_id`, is the link used to find a course's product: it survives an
 * Open edX course key being corrected, and cannot collide with a product an
 * admin flagged for the same course by hand.
 */
const COURSE_PRODUCT_LINK_META = '_tutor_sso_course_post_id';

/**
 * Course meta pointing at its mirrored product (the reverse lookup).
 */
const COURSE_PRODUCT_ID_META = '_tutor_sso_product_id';

/**
 * Open edX Commerce: the product-type checkbox meta, expecting 'yes' / 'no'.
 */
const OEC_COURSE_FLAG_META = 'is_openedx_course';

/**
 * Open edX Commerce: the course id the enrollment request is built from.
 */
const OEC_COURSE_ID_META = '_course_id';

/**
 * Open edX Commerce: the enrollment mode.
 */
const OEC_MODE_META = '_mode';

/**
 * The enrollment mode this sync last wrote. Kept so a changed default can move
 * existing products onto it, while a mode picked in the admin is recognised as
 * a deliberate override and left alone.
 */
const COURSE_PRODUCT_MODE_META = '_tutor_sso_product_mode';

/**
 * Whether the course → product mirror runs at all.
 *
 * @return bool
 */
function course_product_sync_enabled() {
	/**
	 * Filter whether paid courses are mirrored into WooCommerce products.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'tutor_sso_course_product_sync_enabled', true );
}

/**
 * Whether WooCommerce is available to mirror into.
 *
 * @return bool
 */
function course_product_woocommerce_active() {
	return class_exists( '\WooCommerce' ) && function_exists( 'wc_get_product' );
}

/**
 * The Open edX enrollment mode mirrored products are sold as.
 *
 * `no-id-professional` is the paid track that needs no ID verification, which is
 * what these courses are sold as. Validated against the modes Open edX Commerce
 * offers, so a typo in the filter cannot write a mode the LMS will reject.
 *
 * @return string One of course_product_modes().
 */
function course_product_default_mode() {
	/**
	 * Filter the Open edX enrollment mode used for mirrored products.
	 *
	 * @param string $mode Default 'no-id-professional'.
	 */
	$mode = (string) apply_filters( 'tutor_sso_course_product_mode', 'no-id-professional' );

	return in_array( $mode, course_product_modes(), true ) ? $mode : 'no-id-professional';
}

/**
 * The enrollment modes Open edX Commerce offers, mirroring its own
 * get_enrollment_options().
 *
 * @return string[]
 */
function course_product_modes() {
	return array( 'honor', 'audit', 'verified', 'credit', 'professional', 'no-id-professional' );
}

/**
 * Read an ACF field with a raw-meta fallback, so the mirror works whether or
 * not ACF is active.
 *
 * @param string $field   Field name.
 * @param int    $post_id Post ID.
 * @return mixed
 */
function course_product_field( $field, $post_id ) {
	if ( function_exists( 'get_field' ) ) {
		return get_field( $field, $post_id );
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * Normalize a price the LMS sent as text into something WooCommerce can store.
 *
 * The sync stores prices verbatim (they are ACF text fields), so this is where
 * a stray currency suffix or thousands separator is dropped. Anything that is
 * not a positive number at all returns '' — the caller treats that as "no
 * price", not as free.
 *
 * @param mixed $value Raw field value.
 * @return string Numeric string, or '' when unusable.
 */
function course_product_price( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		return '';
	}

	$value = trim( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	// A negative price is rejected outright — stripping the sign would silently
	// turn "-10" into a 10 charge.
	if ( 0 === strpos( $value, '-' ) ) {
		return '';
	}

	// Keep digits and a decimal point; drop separators, symbols and spaces.
	$value = str_replace( ',', '', $value );
	$value = preg_replace( '/[^0-9.]/', '', $value );

	if ( '' === $value || ! is_numeric( $value ) ) {
		return '';
	}

	$number = (float) $value;

	if ( $number <= 0 ) {
		return '';
	}

	return wc_format_decimal( $number );
}

/**
 * What the product for a course should look like, read off the course post.
 *
 * Split out from the WooCommerce writes so the decision — mirror or draft, and
 * with which values — is testable without a product object.
 *
 * @param int $course_id Course post ID.
 * @return array{
 *     paid:bool, title:string, regular:string, sale:string, course_key:string,
 *     thumbnail_id:int, excerpt:string, reason:string
 * }
 */
function course_product_desired_state( $course_id ) {
	$course_id = (int) $course_id;

	$state = array(
		'paid'         => false,
		'title'        => get_the_title( $course_id ),
		'regular'      => '',
		'sale'         => '',
		'course_key'   => trim( (string) course_product_field( 'openedx_course_id', $course_id ) ),
		'thumbnail_id' => (int) get_post_thumbnail_id( $course_id ),
		'excerpt'      => trim( (string) course_product_field( 'short_description', $course_id ) ),
		'reason'       => '',
	);

	$is_paid = course_product_field( 'is_paid', $course_id );

	// ACF true/false stores 1/0; the raw-meta fallback can read '1'/'0'/''.
	if ( ! filter_var( $is_paid, FILTER_VALIDATE_BOOLEAN ) ) {
		$state['reason'] = 'not_paid';

		return $state;
	}

	// Without a course key the plugin cannot build an enrollment request, so a
	// product would take money and enroll nobody.
	if ( '' === $state['course_key'] ) {
		$state['reason'] = 'no_course_key';

		return $state;
	}

	$regular = course_product_price( course_product_field( 'course_regular_price', $course_id ) );

	if ( '' === $regular ) {
		$state['reason'] = 'no_price';

		return $state;
	}

	$sale = course_product_price( course_product_field( 'course_sale_price', $course_id ) );

	// WooCommerce ignores a sale price that is not below the regular one; drop
	// it rather than storing a sale that never displays.
	if ( '' !== $sale && (float) $sale >= (float) $regular ) {
		$sale = '';
	}

	$state['paid']    = true;
	$state['regular'] = $regular;
	$state['sale']    = $sale;

	return $state;
}

/**
 * Find the product mirroring a course, if one exists.
 *
 * @param int $course_id Course post ID.
 * @return int Product ID, or 0.
 */
function course_product_find( $course_id ) {
	$course_id = (int) $course_id;

	// The pointer stored on the course is the fast path; it is verified below so
	// a deleted or re-typed product falls back to the meta query.
	$linked = (int) get_post_meta( $course_id, COURSE_PRODUCT_ID_META, true );

	if ( $linked && 'product' === get_post_type( $linked ) ) {
		return $linked;
	}

	$ids = get_posts(
		array(
			'post_type'        => 'product',
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => COURSE_PRODUCT_LINK_META,
					'value' => $course_id,
				),
			),
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Create or update the product mirroring a course, or draft it when the course
 * is no longer sellable.
 *
 * @param int $course_id Course post ID.
 * @return int Product ID touched, or 0 when nothing was done.
 */
function course_product_sync( $course_id ) {
	$course_id = (int) $course_id;

	if ( ! $course_id || ! course_product_sync_enabled() || ! course_product_woocommerce_active() ) {
		return 0;
	}

	$state      = course_product_desired_state( $course_id );
	$product_id = course_product_find( $course_id );

	// Not sellable: draft any product we already made, and stop.
	if ( ! $state['paid'] ) {
		if ( $product_id ) {
			course_product_set_draft( $product_id, $state['reason'] );
		}

		return $product_id;
	}

	$product = $product_id ? wc_get_product( $product_id ) : null;

	if ( ! $product ) {
		$product = new \WC_Product_Simple();
	}

	$product->set_name( $state['title'] );
	$product->set_status( 'publish' );

	// The product is never browsed: the course page is the storefront and the
	// permalink redirects there (see course_product_redirect()).
	$product->set_catalog_visibility( 'hidden' );

	// A course needs no shipping, and Open edX Commerce grants access itself, so
	// it is virtual but not downloadable.
	$product->set_virtual( true );
	$product->set_downloadable( false );

	$product->set_regular_price( $state['regular'] );
	$product->set_sale_price( $state['sale'] );

	if ( '' !== $state['excerpt'] ) {
		$product->set_short_description( $state['excerpt'] );
	}

	if ( $state['thumbnail_id'] ) {
		// The course's own attachment is reused — no second copy in the library.
		$product->set_image_id( $state['thumbnail_id'] );
	}

	$product_id = $product->save();

	if ( ! $product_id ) {
		return 0;
	}

	course_product_apply_openedx_meta( $product_id, $state['course_key'] );

	// Link both ways so the product is found again and the redirect can resolve
	// its course without a meta query.
	update_post_meta( $product_id, COURSE_PRODUCT_LINK_META, $course_id );
	update_post_meta( $course_id, COURSE_PRODUCT_ID_META, $product_id );

	/**
	 * Fires after a course's product has been created or updated.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $course_id  Course post ID.
	 * @param array $state      Desired state that was applied.
	 */
	do_action( 'tutor_sso_course_product_synced', $product_id, $course_id, $state );

	return $product_id;
}

/**
 * Write the Open edX Commerce metadata the enrollment flow checks for.
 *
 * `is_openedx_course` and `_course_id` are ours to own — they are what make the
 * order enroll anyone.
 *
 * `_mode` is managed more carefully. It is written when the product has no mode,
 * and re-written when the stored mode is still the one this sync last wrote — so
 * changing the default migrates existing products on their next sync instead of
 * leaving them on a stale mode. A mode that differs from what we wrote is taken
 * as a deliberate choice in the admin and left alone.
 *
 * A product mirrored before mode tracking existed carries no record of what we
 * wrote; it is adopted once (we created it, so its mode was ours) and tracked
 * from then on.
 *
 * @param int    $product_id Product ID.
 * @param string $course_key Open edX course id.
 */
function course_product_apply_openedx_meta( $product_id, $course_key ) {
	update_post_meta( $product_id, OEC_COURSE_FLAG_META, 'yes' );
	update_post_meta( $product_id, OEC_COURSE_ID_META, $course_key );

	$desired = course_product_default_mode();
	$current = (string) get_post_meta( $product_id, OEC_MODE_META, true );
	$tracked = get_post_meta( $product_id, COURSE_PRODUCT_MODE_META, true );

	// '' → never set; false/'' tracked → mirrored before tracking existed.
	$is_ours = ( '' === $current )
		|| ( '' === $tracked || false === $tracked )
		|| ( (string) $tracked === $current );

	if ( $is_ours && $current !== $desired ) {
		update_post_meta( $product_id, OEC_MODE_META, $desired );
	}

	if ( $is_ours ) {
		update_post_meta( $product_id, COURSE_PRODUCT_MODE_META, $desired );
	}
}

/**
 * Unpublish a product whose course is no longer sellable, keeping the post (and
 * so its id and order history) intact.
 *
 * @param int    $product_id Product ID.
 * @param string $reason     Why it was drafted, for the debug log.
 */
function course_product_set_draft( $product_id, $reason = '' ) {
	$product_id = (int) $product_id;

	if ( 'draft' === get_post_status( $product_id ) ) {
		return;
	}

	wp_update_post(
		array(
			'ID'          => $product_id,
			'post_status' => 'draft',
		)
	);

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[tutor-sso] product %d drafted (%s)', $product_id, '' !== $reason ? $reason : 'course not sellable' )
		);
	}

	/**
	 * Fires after a mirrored product has been unpublished.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $reason     'not_paid' | 'no_course_key' | 'no_price'.
	 */
	do_action( 'tutor_sso_course_product_drafted', $product_id, $reason );
}

/**
 * Mirror the course right after the sync endpoint has written its fields.
 *
 * @param int $course_id Course post ID.
 */
function course_product_sync_on_course_synced( $course_id ) {
	course_product_sync( $course_id );
}
add_action( 'tutor_sso_course_synced', __NAMESPACE__ . '\\course_product_sync_on_course_synced', 10, 1 );

/**
 * Mirror the course when it is saved in wp-admin, so pressing Update behaves
 * like a sync: a missing product is created, an existing one picks up the new
 * title or image, and a course that is no longer sellable is drafted.
 *
 * The pricing fields are read-only on that screen (see courses-admin-fields.php),
 * so this cannot invent a price — it re-applies whatever the last sync stored.
 *
 * Runs late (priority 20) so ACF has written its fields first; without that the
 * mirror would read the pre-save values.
 *
 * @param int      $post_id Post ID being saved.
 * @param \WP_Post $post    Post object.
 */
function course_product_sync_on_save( $post_id, $post = null ) {
	// Autosaves, revisions and the initial auto-draft are not real saves.
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post || Courses\Courses_REST_Controller::POST_TYPE !== $post->post_type ) {
		return;
	}

	if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return;
	}

	/**
	 * Filter whether saving a course in wp-admin mirrors it to its product.
	 *
	 * @param bool $enabled Default true.
	 * @param int  $post_id Course post ID.
	 */
	if ( ! apply_filters( 'tutor_sso_course_product_sync_on_save', true, $post_id ) ) {
		return;
	}

	course_product_sync( $post_id );
}
add_action( 'save_post', __NAMESPACE__ . '\\course_product_sync_on_save', 20, 2 );

/**
 * Keep the product in step when a course leaves or re-enters circulation:
 * trashing or unpublishing a course should stop selling it.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Post object.
 */
function course_product_sync_on_status_change( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ! $post ) {
		return;
	}

	if ( Courses\Courses_REST_Controller::POST_TYPE !== $post->post_type ) {
		return;
	}

	$product_id = course_product_find( (int) $post->ID );

	if ( ! $product_id ) {
		return;
	}

	// A course that is not publicly available must not stay on sale.
	if ( 'publish' !== $new_status ) {
		course_product_set_draft( $product_id, 'course_' . $new_status );

		return;
	}

	course_product_sync( (int) $post->ID );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\course_product_sync_on_status_change', 10, 3 );

/**
 * The course a product mirrors, if any.
 *
 * Prefers the link meta; falls back to matching the product's `_course_id`
 * against a course's `openedx_course_id`, so a product an admin flagged by hand
 * still redirects to its course.
 *
 * @param int $product_id Product ID.
 * @return int Course post ID, or 0.
 */
function course_product_course_id( $product_id ) {
	$course_id = (int) get_post_meta( (int) $product_id, COURSE_PRODUCT_LINK_META, true );

	if ( $course_id && Courses\Courses_REST_Controller::POST_TYPE === get_post_type( $course_id ) ) {
		return $course_id;
	}

	$course_key = trim( (string) get_post_meta( (int) $product_id, OEC_COURSE_ID_META, true ) );

	if ( '' === $course_key ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'        => Courses\Courses_REST_Controller::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => 'openedx_course_id',
					'value' => $course_key,
				),
			),
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Send single-product views to the course detail page.
 *
 * Runs on `template_redirect`, so WooCommerce has already handled an
 * `?add-to-cart=` on the way in (that is processed on `wp_loaded`) — buying from
 * a product URL still works, the visitor just lands on the course page.
 *
 * Applies to everyone, admins included: the product is an implementation detail
 * and is edited from wp-admin, never viewed on the front end. A product with no
 * resolvable course is left alone rather than redirected somewhere arbitrary.
 */
function course_product_redirect() {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product_id = get_queried_object_id();

	if ( ! $product_id ) {
		return;
	}

	$course_id = course_product_course_id( $product_id );

	if ( ! $course_id ) {
		return;
	}

	$url = get_permalink( $course_id );

	if ( ! $url ) {
		return;
	}

	/**
	 * Filter where a product page sends the visitor.
	 *
	 * @param string $url        Course permalink.
	 * @param int    $product_id Product being viewed.
	 * @param int    $course_id  Course it mirrors.
	 */
	$url = (string) apply_filters( 'tutor_sso_course_product_redirect_url', $url, $product_id, $course_id );

	if ( '' === $url ) {
		return;
	}

	wp_safe_redirect( $url, 301 );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\course_product_redirect' );

/**
 * Whether a course is marked paid by the sync.
 *
 * @param int $course_id Course post ID.
 * @return bool
 */
function course_is_paid( $course_id ) {
	return (bool) filter_var( course_product_field( 'is_paid', (int) $course_id ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * The published product a course can actually be bought through.
 *
 * A drafted product (course no longer paid, or missing its price / course key —
 * see course_product_sync()) deliberately does not count: there is nothing to
 * sell, and falling back to a purchasable state would be worse than showing no
 * button at all.
 *
 * @param int $course_id Course post ID.
 * @return int Product ID, or 0 when the course has no buyable product.
 */
function course_product_buyable( $course_id ) {
	if ( ! course_product_woocommerce_active() ) {
		return 0;
	}

	$product_id = course_product_find( (int) $course_id );

	if ( ! $product_id || 'publish' !== get_post_status( $product_id ) ) {
		return 0;
	}

	return $product_id;
}

/**
 * Where the buy button points: WooCommerce's classic add-to-cart query arg on
 * the cart page, so one click adds the course and lands the visitor in the cart.
 *
 * The product's own permalink would work too (add-to-cart is processed on
 * `wp_loaded`) but would then bounce through the redirect back to the course
 * page, which reads as "nothing happened".
 *
 * @param int $product_id Product ID.
 * @return string URL, or '' when the cart page is unavailable.
 */
function course_product_add_to_cart_url( $product_id ) {
	$product_id = (int) $product_id;
	$cart_url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

	$url = '' !== $cart_url ? add_query_arg( 'add-to-cart', $product_id, $cart_url ) : '';

	/**
	 * Filter the buy button's destination — e.g. to send buyers straight to
	 * checkout instead of the cart.
	 *
	 * @param string $url        Add-to-cart URL.
	 * @param int    $product_id Product being bought.
	 */
	return (string) apply_filters( 'tutor_sso_course_buy_url', $url, $product_id );
}

/**
 * Render the buy button for a paid course.
 *
 * Reuses the enroll button's classes so it inherits the enroll card's styling
 * (see assets/css/enroll.css and course-detail.css); `--buy` is the modifier.
 *
 * @param int   $product_id Product to add to the cart.
 * @param array $args       Optional { label }.
 * @return string HTML, or '' when there is nowhere to send the buyer.
 */
function course_render_buy_button( $product_id, $args = array() ) {
	$url = course_product_add_to_cart_url( $product_id );

	if ( '' === $url ) {
		return '';
	}

	$label = isset( $args['label'] ) && '' !== $args['label']
		? (string) $args['label']
		: __( 'اشترِ الآن', 'tutor-sso' );

	return sprintf(
		'<div class="tutor-sso-enroll-wrap tutor-sso-enroll-wrap--buy">%3$s<a class="tutor-sso-enroll-btn tutor-sso-enroll-btn--buy" href="%1$s" data-product-id="%4$d">%2$s</a></div>',
		esc_url( $url ),
		esc_html( $label ),
		course_product_price_html( $product_id ),
		(int) $product_id
	);
}

/**
 * The SSO login URL paid-course buttons send guests to.
 *
 * @return string
 */
function course_product_login_url() {
	$url = function_exists( 'tutor_sso_get_login_url' )
		? tutor_sso_get_login_url()
		: wp_login_url( get_permalink() ? get_permalink() : home_url() );

	/**
	 * Filter where a guest is sent to log in before buying a course.
	 *
	 * @param string $url Login URL.
	 */
	return (string) apply_filters( 'tutor_sso_course_buy_login_url', $url );
}

/**
 * Render the price plus a "log in to buy" link for a guest on a paid course.
 *
 * Buying requires an account: the enrollment the order triggers is granted to a
 * person, so the purchase is gated on login rather than offered to guests (the
 * checkout guard below enforces the same rule server-side).
 *
 * @param int   $product_id Product the guest will buy after logging in.
 * @param array $args       Optional { label }.
 * @return string HTML.
 */
function course_render_buy_login_button( $product_id, $args = array() ) {
	$label = isset( $args['label'] ) && '' !== $args['label']
		? (string) $args['label']
		: __( 'سجّل الدخول للشراء', 'tutor-sso' );

	return sprintf(
		'<div class="tutor-sso-enroll-wrap tutor-sso-enroll-wrap--buy">%3$s<a class="tutor-sso-enroll-btn tutor-sso-enroll-btn--buy tutor-sso-enroll-btn--buy-login" href="%1$s">%2$s</a></div>',
		esc_url( course_product_login_url() ),
		esc_html( $label ),
		course_product_price_html( $product_id )
	);
}

/**
 * The product's price, as a labelled block for the enroll card.
 *
 * Built from the product's own amounts rather than get_price_html(), so the
 * pieces can be laid out and styled independently: a "السعر" label, the price
 * charged, the struck-through pre-sale price, and a discount badge. Each amount
 * still goes through wc_price(), so currency symbol, position, separators and
 * decimals follow the WooCommerce settings.
 *
 * @param int $product_id Product ID.
 * @return string HTML, or '' when there is no price to show.
 */
function course_product_price_html( $product_id ) {
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_price' ) ) {
		return '';
	}

	$product = wc_get_product( (int) $product_id );

	if ( ! $product ) {
		return '';
	}

	$now     = (float) $product->get_price();
	$regular = (float) $product->get_regular_price();

	if ( $now <= 0 ) {
		return '';
	}

	// On sale only when the regular price is genuinely higher.
	$on_sale  = $regular > $now;
	$discount = $on_sale ? (int) round( ( ( $regular - $now ) / $regular ) * 100 ) : 0;

	ob_start();
	?>
	<div class="tutor-sso-course-price<?php echo $on_sale ? ' is-on-sale' : ''; ?>">
		<div class="tutor-sso-course-price__head">
			<span class="tutor-sso-course-price__label"><?php echo esc_html__( 'السعر', 'tutor-sso' ); ?></span>
			<?php if ( $discount > 0 ) : ?>
				<span class="tutor-sso-course-price__badge">
					<?php
					/* translators: %s: discount percentage. */
					echo esc_html( sprintf( __( 'خصم %s%%', 'tutor-sso' ), number_format_i18n( $discount ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>

		<div class="tutor-sso-course-price__amounts">
			<span class="tutor-sso-course-price__now"><?php echo wp_kses_post( wc_price( $now ) ); ?></span>
			<?php if ( $on_sale ) : ?>
				<span class="tutor-sso-course-price__was"><?php echo wp_kses_post( wc_price( $regular ) ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Whether the cart holds a course product.
 *
 * @return bool
 */
function course_product_cart_has_course() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( $product_id && 'yes' === get_post_meta( $product_id, OEC_COURSE_FLAG_META, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Require an account to check out a course.
 *
 * The buy button already sends guests to log in first, but the cart can be
 * filled by a bare `?add-to-cart=` URL, so the rule is enforced here too: a
 * guest on the checkout with a course in the cart is sent to log in. Without
 * this, an order could complete with no WordPress user to enroll.
 *
 * WooCommerce restores a guest's session cart after login, so nothing is lost.
 */
function course_product_require_login_to_checkout() {
	if ( is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	/**
	 * Filter whether buying a course requires a logged-in user.
	 *
	 * @param bool $required Default true.
	 */
	if ( ! apply_filters( 'tutor_sso_course_require_login_to_buy', true ) ) {
		return;
	}

	if ( ! course_product_cart_has_course() ) {
		return;
	}

	wp_redirect( course_product_login_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the SSO gateway may live off-host.
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\course_product_require_login_to_checkout' );

/**
 * Belt and braces: make WooCommerce itself demand an account whenever a course
 * is in the cart, for any checkout path that does not go through the page view
 * guarded above (block checkout, express payment buttons).
 *
 * @param bool $required Whether registration is required.
 * @return bool
 */
function course_product_force_registration( $required ) {
	if ( $required ) {
		return $required;
	}

	if ( ! apply_filters( 'tutor_sso_course_require_login_to_buy', true ) ) {
		return $required;
	}

	return course_product_cart_has_course() ? true : $required;
}
add_filter( 'woocommerce_checkout_registration_required', __NAMESPACE__ . '\\course_product_force_registration' );
