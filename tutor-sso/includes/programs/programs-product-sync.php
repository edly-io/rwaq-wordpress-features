<?php
/**
 * Mirror paid programs into WooCommerce products.
 *
 * A program that syncs with `is_paid` gets a matching simple product carrying
 * the program's title, image and prices — the same arrangement
 * courses-product-sync.php makes for courses, with one deliberate difference.
 *
 * The course mirror flags its products for Open edX Commerce with
 * `is_openedx_course` / `_course_id`, and that plugin enrolls the buyer on order
 * completion. It knows nothing about programs, so a program product must NOT
 * carry those two metas: it would send a program key to the course enrollment
 * API and fail. Program products are marked with `_tutor_sso_program_key`
 * instead, which Open edX Commerce ignores and programs-order-enrollment.php
 * acts on.
 *
 * Everything else matches the course mirror: catalog visibility is `hidden`, the
 * permalink redirects to the program detail page, and a program that stops being
 * paid moves its product to `draft` rather than the trash so re-enabling it
 * restores the same product id and its order history.
 *
 * Everything here no-ops when WooCommerce is not active.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

use TutorSSO\Programs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product meta pointing back at the program post.
 */
const PROGRAM_PRODUCT_LINK_META = '_tutor_sso_program_post_id';

/**
 * Program meta pointing at its product.
 */
const PROGRAM_PRODUCT_ID_META = '_tutor_sso_program_product_id';

/**
 * Product meta holding the LMS program key. What the order handler enrolls on.
 */
const PROGRAM_PRODUCT_KEY_META = '_tutor_sso_program_key';

/**
 * Product meta holding the program uuid, which the bulk-enroll endpoint is
 * keyed on. Resolved from the program post when a product carries only the key.
 */
const PROGRAM_PRODUCT_UUID_META = '_tutor_sso_program_uuid';

/**
 * Product checkbox meta: 'yes' marks the product as a program purchase. Mirrors
 * Open edX Commerce's `is_openedx_course`, and is mutually exclusive with it.
 */
const PROGRAM_PRODUCT_FLAG_META = '_tutor_sso_is_program';

/**
 * Whether the program → product mirror runs at all.
 *
 * @return bool
 */
function program_product_sync_enabled() {
	/**
	 * Filter whether paid programs are mirrored into WooCommerce products.
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'tutor_sso_program_product_sync_enabled', true );
}

/**
 * Read a field off a program post: ACF first, then raw post meta.
 *
 * Unlike the course reader this falls through on an empty ACF result. The sync
 * writes with update_field(), which stores the value as plain meta when no ACF
 * field of that name is registered for the `program` post type — get_field()
 * then reports nothing and the program would look unpaid forever.
 *
 * @param string $field   Field name.
 * @param int    $post_id Program post ID.
 * @return mixed
 */
function program_product_field( $field, $post_id ) {
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $field, $post_id );

		if ( null !== $value && '' !== $value && false !== $value ) {
			return $value;
		}
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * A program price, preferring the program_* field and falling back to the
 * course_* one a program synced before the rename still carries.
 *
 * @param string $role    'regular' or 'sale'.
 * @param int    $post_id Program post ID.
 * @return mixed
 */
function program_product_price_field( $role, $post_id ) {
	$value = program_product_field( 'program_' . $role . '_price', $post_id );

	if ( '' !== trim( (string) $value ) ) {
		return $value;
	}

	return program_product_field( 'course_' . $role . '_price', $post_id );
}

/**
 * What the product for a program should look like, read off the program post.
 *
 * @param int $program_id Program post ID.
 * @return array{
 *     paid:bool, title:string, regular:string, sale:string, program_key:string,
 *     thumbnail_id:int, excerpt:string, reason:string
 * }
 */
function program_product_desired_state( $program_id ) {
	$program_id = (int) $program_id;

	$state = array(
		'paid'         => false,
		'title'        => get_the_title( $program_id ),
		'regular'      => '',
		'sale'         => '',
		'program_key'  => trim( (string) program_product_field( 'program_key', $program_id ) ),
		'program_uuid' => trim( (string) program_product_field( 'uuid', $program_id ) ),
		'thumbnail_id' => (int) get_post_thumbnail_id( $program_id ),
		'excerpt'      => '',
		'reason'       => '',
	);

	$is_paid = program_product_field( 'is_paid', $program_id );

	if ( ! filter_var( $is_paid, FILTER_VALIDATE_BOOLEAN ) ) {
		$state['reason'] = 'not_paid';

		return $state;
	}

	// Without a program key the order handler cannot enroll anyone.
	if ( '' === $state['program_key'] ) {
		$state['reason'] = 'no_program_key';

		return $state;
	}

	$regular = course_product_price( program_product_price_field( 'regular', $program_id ) );

	if ( '' === $regular ) {
		$state['reason'] = 'no_price';

		return $state;
	}

	$sale = course_product_price( program_product_price_field( 'sale', $program_id ) );

	// WooCommerce ignores a sale price that is not below the regular one.
	if ( '' !== $sale && (float) $sale >= (float) $regular ) {
		$sale = '';
	}

	$state['paid']    = true;
	$state['regular'] = $regular;
	$state['sale']    = $sale;

	return $state;
}

/**
 * Find the product mirroring a program, if one exists.
 *
 * @param int $program_id Program post ID.
 * @return int Product ID, or 0.
 */
function program_product_find( $program_id ) {
	$program_id = (int) $program_id;

	$linked = (int) get_post_meta( $program_id, PROGRAM_PRODUCT_ID_META, true );

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
					'key'   => PROGRAM_PRODUCT_LINK_META,
					'value' => $program_id,
				),
			),
		)
	);

	if ( ! empty( $ids ) ) {
		return (int) $ids[0];
	}

	// Last resort: a product an admin flagged by hand carries the key but no
	// link. Adopting it keeps one product per program instead of creating a
	// second one alongside it.
	$program_key = trim( (string) program_product_field( 'program_key', $program_id ) );

	if ( '' === $program_key ) {
		return 0;
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
					'key'   => PROGRAM_PRODUCT_KEY_META,
					'value' => $program_key,
				),
			),
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Create or update the product mirroring a program, or draft it when the
 * program is no longer sellable.
 *
 * @param int $program_id Program post ID.
 * @return int Product ID touched, or 0 when nothing was done.
 */
function program_product_sync( $program_id ) {
	$program_id = (int) $program_id;

	if ( ! $program_id || ! program_product_sync_enabled() || ! course_product_woocommerce_active() ) {
		return 0;
	}

	$state      = program_product_desired_state( $program_id );
	$product_id = program_product_find( $program_id );

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

	// Never browsed: the program page is the storefront and the permalink
	// redirects there (see program_product_redirect()).
	$product->set_catalog_visibility( 'hidden' );
	$product->set_virtual( true );
	$product->set_downloadable( false );

	// One enrollment per person — WooCommerce caps the line at one and hides the
	// quantity input.
	$product->set_sold_individually( true );

	$product->set_regular_price( $state['regular'] );
	$product->set_sale_price( $state['sale'] );

	if ( $state['thumbnail_id'] ) {
		$product->set_image_id( $state['thumbnail_id'] );
	}

	$product_id = $product->save();

	if ( ! $product_id ) {
		return 0;
	}

	// The program key is what the order handler enrolls on. Open edX Commerce's
	// own flags are deliberately not written — see the file header.
	update_post_meta( $product_id, PROGRAM_PRODUCT_FLAG_META, 'yes' );
	update_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META, $state['program_key'] );
	update_post_meta( $product_id, PROGRAM_PRODUCT_UUID_META, $state['program_uuid'] );

	// A product cannot be both; the program flag wins on a product the sync owns.
	update_post_meta( $product_id, 'is_openedx_course', 'no' );

	update_post_meta( $product_id, PROGRAM_PRODUCT_LINK_META, $program_id );
	update_post_meta( $program_id, PROGRAM_PRODUCT_ID_META, $product_id );

	/**
	 * Fires after a program's product has been created or updated.
	 *
	 * @param int   $product_id Product ID.
	 * @param int   $program_id Program post ID.
	 * @param array $state      Desired state that was applied.
	 */
	do_action( 'tutor_sso_program_product_synced', $product_id, $program_id, $state );

	return $product_id;
}

/**
 * Sell program products one at a time, including any an admin flagged by hand.
 *
 * @param bool        $sold    Whether the product is sold individually.
 * @param \WC_Product $product Product.
 * @return bool
 */
function program_product_sold_individually( $sold, $product ) {
	if ( $sold || ! $product ) {
		return $sold;
	}

	return program_product_is_program( $product->get_id() );
}
add_filter( 'woocommerce_is_sold_individually', __NAMESPACE__ . '\\program_product_sold_individually', 10, 2 );

/**
 * Mirror the program right after the sync endpoint has written its fields.
 *
 * @param int $program_id Program post ID.
 */
function program_product_sync_on_program_synced( $program_id ) {
	program_product_sync( $program_id );
}
add_action( 'tutor_sso_program_synced', __NAMESPACE__ . '\\program_product_sync_on_program_synced', 10, 1 );

/**
 * Mirror the program when it is saved in wp-admin, so pressing Update behaves
 * like a sync. Priority 20 so ACF has written its fields first.
 *
 * @param int      $post_id Post ID being saved.
 * @param \WP_Post $post    Post object.
 */
function program_product_sync_on_save( $post_id, $post = null ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post || Programs\Programs_REST_Controller::POST_TYPE !== $post->post_type ) {
		return;
	}

	if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return;
	}

	/**
	 * Filter whether saving a program in wp-admin mirrors it to its product.
	 *
	 * @param bool $enabled    Default true.
	 * @param int  $program_id Program post ID.
	 */
	if ( ! apply_filters( 'tutor_sso_program_product_sync_on_save', true, $post_id ) ) {
		return;
	}

	program_product_sync( $post_id );
}
add_action( 'save_post', __NAMESPACE__ . '\\program_product_sync_on_save', 20, 2 );

/**
 * Stop selling a program that leaves circulation, and resume when it returns.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Post object.
 */
function program_product_sync_on_status_change( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ! $post ) {
		return;
	}

	if ( Programs\Programs_REST_Controller::POST_TYPE !== $post->post_type ) {
		return;
	}

	$product_id = program_product_find( (int) $post->ID );

	if ( ! $product_id ) {
		return;
	}

	if ( 'publish' !== $new_status ) {
		course_product_set_draft( $product_id, 'program_' . $new_status );

		return;
	}

	program_product_sync( (int) $post->ID );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\program_product_sync_on_status_change', 10, 3 );

/**
 * The program a product mirrors, if any.
 *
 * @param int $product_id Product ID.
 * @return int Program post ID, or 0.
 */
function program_product_program_id( $product_id ) {
	$program_id = (int) get_post_meta( (int) $product_id, PROGRAM_PRODUCT_LINK_META, true );

	if ( $program_id && Programs\Programs_REST_Controller::POST_TYPE === get_post_type( $program_id ) ) {
		return $program_id;
	}

	$program_key = trim( (string) get_post_meta( (int) $product_id, PROGRAM_PRODUCT_KEY_META, true ) );

	if ( '' === $program_key ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'        => Programs\Programs_REST_Controller::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => 'program_key',
					'value' => $program_key,
				),
			),
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Send single-product views to the program detail page. Mirrors
 * course_product_redirect(); runs at a later priority so the course mirror
 * answers first for its own products.
 */
function program_product_redirect() {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product_id = get_queried_object_id();

	if ( ! $product_id ) {
		return;
	}

	$program_id = program_product_program_id( $product_id );

	if ( ! $program_id ) {
		return;
	}

	$url = get_permalink( $program_id );

	if ( ! $url ) {
		return;
	}

	/**
	 * Filter where a program product page sends the visitor.
	 *
	 * @param string $url        Program permalink.
	 * @param int    $product_id Product being viewed.
	 * @param int    $program_id Program it mirrors.
	 */
	$url = (string) apply_filters( 'tutor_sso_program_product_redirect_url', $url, $product_id, $program_id );

	if ( '' === $url ) {
		return;
	}

	wp_safe_redirect( $url, 301 );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\program_product_redirect', 11 );

/**
 * Whether a product is flagged as a program purchase.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function program_product_is_program( $product_id ) {
	$flag = get_post_meta( (int) $product_id, PROGRAM_PRODUCT_FLAG_META, true );

	if ( 'yes' === $flag || 'no' === $flag ) {
		return 'yes' === $flag;
	}

	// No flag: a product mirrored before the checkbox existed. The key it still
	// carries is what makes it a program product.
	return '' !== trim( (string) get_post_meta( (int) $product_id, PROGRAM_PRODUCT_KEY_META, true ) );
}

/**
 * The product currently being edited, or 0 outside a product edit screen.
 *
 * @return int
 */
function program_product_editing_id() {
	global $post;

	$product_id = ( $post && 'product' === get_post_type( $post ) ) ? (int) $post->ID : (int) get_the_ID();

	return ( $product_id && 'product' === get_post_type( $product_id ) ) ? $product_id : 0;
}

/**
 * Add the "Open edX Program" checkbox beside Virtual / Downloadable / Open edX
 * Course.
 *
 * @param array $options Product type options.
 * @return array
 */
function program_product_type_option( $options ) {
	$product_id = program_product_editing_id();

	$options['tutor_sso_program'] = array(
		'id'            => PROGRAM_PRODUCT_FLAG_META,
		'wrapper_class' => 'show_if_simple',
		'label'         => __( 'Open edX Program', 'tutor-sso' ),
		'description'   => __( 'Check this box if the product is an Open edX Program', 'tutor-sso' ),
		// Carries the stored state itself, as Open edX Commerce's checkbox does:
		// WooCommerce looks for meta named after the array key, not the id.
		'default'       => ( $product_id && program_product_is_program( $product_id ) ) ? 'yes' : 'no',
	);

	return $options;
}
// WooCommerce renamed this filter from `woocommerce_product_type_options`; both
// are registered so the checkbox appears on either version.
add_filter( 'product_type_options', __NAMESPACE__ . '\\program_product_type_option' );
add_filter( 'woocommerce_product_type_options', __NAMESPACE__ . '\\program_product_type_option' );

/**
 * Add the "Program" tab. Shown only while the checkbox is ticked — see
 * assets/js/program-product-admin.js.
 *
 * @param array $tabs Product data tabs.
 * @return array
 */
function program_product_data_tab( $tabs ) {
	$tabs['tutor_sso_program'] = array(
		'label'    => __( 'Program', 'tutor-sso' ),
		'target'   => 'tutor_sso_program_product_data',
		'class'    => array(),
		'priority' => 80,
	);

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', __NAMESPACE__ . '\\program_product_data_tab' );

/**
 * Render the Program tab: the key, and the program it resolves to.
 */
function program_product_data_panel() {
	$product_id = program_product_editing_id();

	$program_key = $product_id ? (string) get_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META, true ) : '';
	$linked      = $product_id ? (int) get_post_meta( $product_id, PROGRAM_PRODUCT_LINK_META, true ) : 0;
	$program_id  = $product_id ? program_product_program_id( $product_id ) : 0;
	$edit_link   = $program_id ? get_edit_post_link( $program_id ) : '';
	?>
	<div id="tutor_sso_program_product_data" class="panel woocommerce_options_panel hidden">
		<div class="options_group">
			<?php
			woocommerce_wp_text_input(
				array(
					'id'          => PROGRAM_PRODUCT_KEY_META,
					'value'       => $program_key,
					'label'       => __( 'Program key', 'tutor-sso' ),
					'placeholder' => 'program-v1:Org+Program+Run',
					'desc_tip'    => true,
					'description' => __( 'The LMS program this product enrolls the buyer into.', 'tutor-sso' ),
				)
			);
			?>

			<?php if ( $program_id ) : ?>
				<p class="form-field">
					<label><?php echo esc_html__( 'Program', 'tutor-sso' ); ?></label>
					<span class="description">
						<?php if ( $edit_link ) : ?>
							<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( get_the_title( $program_id ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( get_the_title( $program_id ) ); ?>
						<?php endif; ?>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( $linked ) : ?>
				<p class="form-field">
					<span class="description">
						<?php echo esc_html__( 'This product is managed by the program sync — it overwrites the key, title and prices on the next sync.', 'tutor-sso' ); ?>
					</span>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_product_data_panels', __NAMESPACE__ . '\\program_product_data_panel' );

/**
 * Save the checkbox and key, and enforce the exclusion server-side so a crafted
 * POST cannot leave a product flagged as both program and course.
 *
 * @param int $product_id Product ID.
 */
function program_product_save_meta( $product_id ) {
	$product_id = (int) $product_id;

	// `woocommerce_update_product` fires on every WC_Product::save(), including
	// stock updates during checkout and our own sync — not just the admin form.
	// Without this guard those saves look like an unticked checkbox and wipe the
	// program meta. WooCommerce's own product nonce marks a real form submit.
	if ( empty( $_POST['woocommerce_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
	$is_program = isset( $_POST[ PROGRAM_PRODUCT_FLAG_META ] );
	$key        = isset( $_POST[ PROGRAM_PRODUCT_KEY_META ] )
		? sanitize_text_field( wp_unslash( $_POST[ PROGRAM_PRODUCT_KEY_META ] ) )
		: '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( ! $is_program ) {
		update_post_meta( $product_id, PROGRAM_PRODUCT_FLAG_META, 'no' );
		delete_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META );

		return;
	}

	update_post_meta( $product_id, PROGRAM_PRODUCT_FLAG_META, 'yes' );

	if ( '' !== $key ) {
		update_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META, $key );
	} else {
		delete_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META );
	}

	// A product is one or the other. Open edX Commerce writes 'yes'/'no' on
	// woocommerce_update_product at priority 10, so this runs after it and has
	// the last word rather than being overwritten.
	update_post_meta( $product_id, 'is_openedx_course', 'no' );
}
add_action( 'woocommerce_update_product', __NAMESPACE__ . '\\program_product_save_meta', 20 );

/**
 * Load the checkbox behaviour on the product edit screens.
 *
 * @param string $hook Current admin page hook.
 */
function program_product_admin_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}

	wp_enqueue_script(
		'tutor-sso-program-product-admin',
		TUTOR_SSO_URL . 'assets/js/program-product-admin.js',
		array( 'jquery' ),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\program_product_admin_assets' );

/**
 * The program uuid for a product: its own meta first, then the program post the
 * key points at — so a hand-flagged product needs only the key.
 *
 * @param int    $product_id  Product ID.
 * @param string $program_key Program key on the product.
 * @return string Uuid, or ''.
 */
function program_product_uuid( $product_id, $program_key = '' ) {
	$uuid = trim( (string) get_post_meta( (int) $product_id, PROGRAM_PRODUCT_UUID_META, true ) );

	if ( '' !== $uuid ) {
		return $uuid;
	}

	$program_id = program_product_program_id( (int) $product_id );

	if ( ! $program_id && '' !== $program_key ) {
		$ids = get_posts(
			array(
				'post_type'        => Programs\Programs_REST_Controller::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'program_key',
						'value' => $program_key,
					),
				),
			)
		);

		$program_id = ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	return $program_id ? trim( (string) program_product_field( 'uuid', $program_id ) ) : '';
}

/**
 * Whether a program is marked paid by the sync.
 *
 * @param int $program_id Program post ID.
 * @return bool
 */
function program_is_paid( $program_id ) {
	return (bool) filter_var( program_product_field( 'is_paid', (int) $program_id ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * The published product a program can actually be bought through.
 *
 * @param int $program_id Program post ID.
 * @return int Product ID, or 0.
 */
function program_product_buyable( $program_id ) {
	if ( ! course_product_woocommerce_active() ) {
		return 0;
	}

	$product_id = program_product_find( (int) $program_id );

	if ( ! $product_id || 'publish' !== get_post_status( $product_id ) ) {
		return 0;
	}

	return $product_id;
}

/**
 * Where the buy button points: add-to-cart on the cart page.
 *
 * @param int $product_id Product ID.
 * @return string URL, or '' when the cart page is unavailable.
 */
function program_product_add_to_cart_url( $product_id ) {
	$product_id = (int) $product_id;
	$cart_url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

	$url = '' !== $cart_url ? add_query_arg( 'add-to-cart', $product_id, $cart_url ) : '';

	/**
	 * Filter the program buy button's destination.
	 *
	 * @param string $url        Add-to-cart URL.
	 * @param int    $product_id Product being bought.
	 */
	return (string) apply_filters( 'tutor_sso_program_buy_url', $url, $product_id );
}

/**
 * Whether the cart holds a program product.
 *
 * @return bool
 */
function program_product_cart_has_program() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;

		if ( $product_id && program_product_is_program( $product_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Require an account to check out a program: the enrollment the order triggers
 * is granted to a person. Mirrors the course guard, for carts the course one
 * does not match.
 */
function program_product_require_login_to_checkout() {
	if ( is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	/**
	 * Filter whether buying a program requires a logged-in user.
	 *
	 * @param bool $required Default true.
	 */
	if ( ! apply_filters( 'tutor_sso_program_require_login_to_buy', true ) ) {
		return;
	}

	if ( ! program_product_cart_has_program() ) {
		return;
	}

	wp_redirect( course_product_login_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the SSO gateway may live off-host.
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\program_product_require_login_to_checkout' );

/**
 * Make WooCommerce demand an account whenever a program is in the cart, for
 * checkout paths that skip the page view guarded above.
 *
 * @param bool $required Whether registration is required.
 * @return bool
 */
function program_product_force_registration( $required ) {
	if ( $required ) {
		return $required;
	}

	if ( ! apply_filters( 'tutor_sso_program_require_login_to_buy', true ) ) {
		return $required;
	}

	return program_product_cart_has_program() ? true : $required;
}
add_filter( 'woocommerce_checkout_registration_required', __NAMESPACE__ . '\\program_product_force_registration' );
