<?php
/**
 * Link the buyer to what they bought, from the order page.
 *
 * The order-received page confirms the purchase but offers no way into the
 * course, so this lists each purchased course or program under the order
 * details. It also shows on a past order under My Account, where the same hook
 * runs.
 *
 * Laid out as a table of "name → view details" rather than a row of buttons:
 * an order with several items produced several equally-weighted buttons, each
 * labelled by type, which read as competing calls to action rather than as a
 * list of what was bought. One quiet link per row scales to any number of
 * items and leaves the page with a single primary action.
 *
 * Each link points at the local detail page rather than the LMS: that page
 * already decides between "enroll" and "go to the course" from the learner's
 * enrollment state, so it stays correct even when an enrollment has not
 * propagated yet.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The courses and programs an order bought, as links.
 *
 * @param \WC_Order $order Order.
 * @return array<int,array{url:string,title:string,label:string,type:string}> Keyed by post ID.
 */
function order_links_for( $order ) {
	$links = array();

	foreach ( $order->get_items() as $item ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		if ( ! $product_id ) {
			continue;
		}

		$post_id = 0;
		$type    = '';

		if ( function_exists( __NAMESPACE__ . '\\course_product_course_id' ) ) {
			$post_id = (int) course_product_course_id( $product_id );
			$type    = 'course';
		}

		if ( ! $post_id && function_exists( __NAMESPACE__ . '\\program_product_program_id' ) ) {
			$post_id = (int) program_product_program_id( $product_id );
			$type    = 'program';
		}

		// Not a course or program, or its post is gone.
		if ( ! $post_id || isset( $links[ $post_id ] ) ) {
			continue;
		}

		$url = (string) get_permalink( $post_id );

		if ( '' === $url ) {
			continue;
		}

		$links[ $post_id ] = array(
			'url'   => $url,
			'title' => (string) get_the_title( $post_id ),
			// One label for every row: the action is the same whatever was
			// bought, and naming the type per button was what made a
			// multi-item order look like several competing choices.
			'label' => __( 'عرض التفاصيل', 'tutor-sso' ),
			'type'  => $type,
		);
	}

	/**
	 * Filter the links shown on an order.
	 *
	 * @param array     $links Links keyed by post ID.
	 * @param \WC_Order $order Order.
	 */
	return (array) apply_filters( 'tutor_sso_order_links', $links, $order );
}

/**
 * Render the purchased-items table under the order details.
 *
 * @param int|\WC_Order $order Order or its ID, depending on the hook.
 */
function order_render_links( $order ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	if ( ! $order instanceof \WC_Order ) {
		$order = wc_get_order( $order );
	}

	if ( ! $order ) {
		return;
	}

	$links = order_links_for( $order );

	if ( empty( $links ) ) {
		return;
	}

	// The enroll card's stylesheet carries this component's styling.
	wp_enqueue_style( 'tutor-sso-enroll' );
	?>
	<section class="tutor-sso-order-links" dir="rtl">
		<h2 class="tutor-sso-order-links__title"><?php echo esc_html__( 'متابعة التعلّم', 'tutor-sso' ); ?></h2>

		<table class="tutor-sso-order-links__table">
			<thead>
				<tr>
					<th class="tutor-sso-order-links__col-name" scope="col"><?php echo esc_html__( 'الدورة / البرنامج', 'tutor-sso' ); ?></th>
					<th class="tutor-sso-order-links__col-action" scope="col"><?php echo esc_html__( 'الإجراءات', 'tutor-sso' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td class="tutor-sso-order-links__col-name"><?php echo esc_html( $link['title'] ); ?></td>
						<td class="tutor-sso-order-links__col-action">
							<a class="tutor-sso-order-links__link" href="<?php echo esc_url( $link['url'] ); ?>">
								<?php echo esc_html( $link['label'] ); ?>
								<?php // The row's name is not in the link text, so it is given to assistive tech here — otherwise every row reads as the same "view details". ?>
								<span class="screen-reader-text"><?php echo esc_html( $link['title'] ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php
}
add_action( 'woocommerce_thankyou', __NAMESPACE__ . '\\order_render_links', 20, 1 );
add_action( 'woocommerce_view_order', __NAMESPACE__ . '\\order_render_links', 20, 1 );
