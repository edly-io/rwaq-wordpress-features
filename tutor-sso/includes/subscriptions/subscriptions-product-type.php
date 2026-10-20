<?php
/**
 * The "Subscription" WooCommerce product type.
 *
 * Extends WC_Product_Simple rather than WC_Product so the plan is purchasable,
 * priced and add-to-cart'able with no extra work — the only difference is its
 * type string and a billing period.
 *
 * Note what the feature switch does and does not gate. The class and its type
 * term are registered unconditionally: WC_Product_Factory falls back to
 * WC_Product_Simple for a type whose class is missing, and
 * WC_Product_Simple::get_type() returns 'simple', so unregistering would
 * quietly turn every existing plan into an ordinary product — and with it stop
 * the gateway tokenizing those orders. The switch gates only what can be newly
 * sold: the entry in the product-type dropdown.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define the product class once WooCommerce's own classes exist.
 *
 * Declared inside a function, not at file scope: WC_Product_Simple is only
 * available after WooCommerce has loaded, and extending a missing class is a
 * fatal at parse time.
 *
 * @return void
 */
function subscription_define_product_class() {
	if ( class_exists( __NAMESPACE__ . '\\WC_Product_Rwaq_Subscription' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		return;
	}

	/**
	 * A subscription plan: a simple product with a billing period.
	 */
	class WC_Product_Rwaq_Subscription extends \WC_Product_Simple {

		/**
		 * The product type.
		 *
		 * @return string
		 */
		public function get_type() {
			return SUBSCRIPTION_PRODUCT_TYPE;
		}

		/**
		 * A plan is always virtual.
		 *
		 * Forced rather than left to the "Virtual" checkbox: a plan that is not
		 * virtual makes WC_Cart::needs_shipping() true, which puts the shipping
		 * address back on checkout for something that is never posted. Nobody
		 * should have to remember to tick a box to avoid that.
		 *
		 * @param string $context View or edit context.
		 * @return bool
		 */
		public function get_virtual( $context = 'view' ) {
			return true;
		}
	}
}
add_action( 'woocommerce_loaded', __NAMESPACE__ . '\\subscription_define_product_class', 5 );

/**
 * Point WooCommerce's product factory at that class.
 *
 * The factory derives a class name from the type ('WC_Product_Rwaq_Subscription'
 * in the global namespace), which would not resolve to the namespaced one.
 *
 * @param string $classname Resolved class name.
 * @param string $type      Product type.
 * @return string
 */
function subscription_product_class( $classname, $type ) {
	if ( SUBSCRIPTION_PRODUCT_TYPE === $type ) {
		subscription_define_product_class();

		return __NAMESPACE__ . '\\WC_Product_Rwaq_Subscription';
	}

	return $classname;
}
add_filter( 'woocommerce_product_class', __NAMESPACE__ . '\\subscription_product_class', 10, 2 );

/**
 * Make sure the `product_type` taxonomy has our term.
 *
 * Checked on every admin load rather than on activation: the plugin registers
 * no activation hook, and one would not fire for an install that is already
 * active — a missing term is the kind of thing that only shows up as an empty
 * dropdown much later.
 *
 * @return void
 */
function subscription_register_product_type_term() {
	// Not gated on the feature switch: an unused product_type term is invisible
	// (the dropdown entry is gated separately) and registering it up front
	// means the type is ready the moment the switch is flipped, rather than on
	// whichever admin page load happens next.
	if ( ! taxonomy_exists( 'product_type' ) || term_exists( SUBSCRIPTION_PRODUCT_TYPE, 'product_type' ) ) {
		return;
	}

	wp_insert_term( SUBSCRIPTION_PRODUCT_TYPE, 'product_type' );
}
add_action( 'admin_init', __NAMESPACE__ . '\\subscription_register_product_type_term' );

/**
 * Whether the product open in the editor is already a subscription.
 *
 * Read straight off the product_type taxonomy rather than through
 * wc_get_product(), which would resolve the product class while the type
 * dropdown that calls this is still being built.
 *
 * @return bool
 */
function subscription_editing_a_subscription() {
	if ( ! is_admin() ) {
		return false;
	}

	$post_id = (int) get_the_ID();

	if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
		return false;
	}

	$terms = get_the_terms( $post_id, 'product_type' );

	if ( ! is_array( $terms ) ) {
		return false;
	}

	foreach ( $terms as $term ) {
		if ( isset( $term->slug ) && SUBSCRIPTION_PRODUCT_TYPE === $term->slug ) {
			return true;
		}
	}

	return false;
}

/**
 * Add "Subscription" to the product-type dropdown.
 *
 * Gated by the feature switch — but only for products that are not already
 * plans. WooCommerce builds this dropdown with
 * `selected( $product->get_type(), $value )`, so a type missing from the list
 * matches nothing and the browser falls back to the first option, "Simple
 * product". Saving an existing plan with the switch off would then post
 * `product-type=simple` and silently convert it, orphaning its billing period
 * and stopping the gateway tokenizing it. Keeping the option present for a
 * product that already uses it means the switch governs what can be *created*,
 * never what happens to what exists.
 *
 * @param array $types Existing type => label pairs.
 * @return array
 */
function subscription_product_type_selector( $types ) {
	if ( subscriptions_enabled() || subscription_editing_a_subscription() ) {
		$types[ SUBSCRIPTION_PRODUCT_TYPE ] = __( 'Subscription', 'tutor-sso' );
	}

	return $types;
}
add_filter( 'product_type_selector', __NAMESPACE__ . '\\subscription_product_type_selector' );

/**
 * Render the usual add-to-cart button for the type.
 *
 * The single-product template dispatches on
 * `woocommerce_{type}_add_to_cart`, which has no listener for a custom type —
 * without this the product page shows no button at all.
 *
 * @return void
 */
function subscription_add_to_cart() {
	woocommerce_simple_add_to_cart();
}
add_action( 'woocommerce_' . SUBSCRIPTION_PRODUCT_TYPE . '_add_to_cart', __NAMESPACE__ . '\\subscription_add_to_cart' );

/**
 * Make the Product data tabs behave for this type.
 *
 * WooCommerce shows and hides tabs with `show_if_{type}` / `hide_if_{type}`
 * classes, hiding everything carrying any `show_if_` first and then revealing
 * only the current type's.
 *
 * Which tab gets which is not symmetric, and the difference matters. Inventory
 * defaults to a list of `show_if_` classes, so it is hidden unless ours is
 * added. General defaults to `hide_if_grouped` with no `show_if_` at all — it is
 * visible for every type — so adding `show_if_` there would do the opposite of
 * what it looks like: it would opt General *into* the hide-first pass and so
 * hide it for simple, variable and external products. General is instead made
 * to appear through the price group's own classes, in
 * assets/js/subscription-product-admin.js.
 *
 * @param array $tabs Product data tabs.
 * @return array
 */
function subscription_product_data_tabs( $tabs ) {
	if ( ! subscriptions_enabled() ) {
		return $tabs;
	}

	if ( isset( $tabs['inventory']['class'] ) && is_array( $tabs['inventory']['class'] ) ) {
		$tabs['inventory']['class'][] = 'show_if_' . SUBSCRIPTION_PRODUCT_TYPE;
	}

	// A plan is always virtual, so there is nothing to ship and nothing on this
	// tab that means anything for it.
	if ( isset( $tabs['shipping']['class'] ) && is_array( $tabs['shipping']['class'] ) ) {
		$tabs['shipping']['class'][] = 'hide_if_' . SUBSCRIPTION_PRODUCT_TYPE;
	}

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', __NAMESPACE__ . '\\subscription_product_data_tabs' );

/**
 * Load the script that reveals the price fields for this type.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function subscription_admin_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
		return;
	}

	// Depend on WooCommerce's own meta-box script where it is registered, so
	// this runs after it has worked the visibility out rather than racing it.
	$deps = array( 'jquery' );

	if ( wp_script_is( 'wc-admin-product-meta-boxes', 'registered' ) ) {
		$deps[] = 'wc-admin-product-meta-boxes';
	}

	wp_enqueue_script(
		'tutor-sso-subscription-product-admin',
		TUTOR_SSO_URL . 'assets/js/subscription-product-admin.js',
		$deps,
		TUTOR_SSO_VERSION,
		true
	);

	wp_localize_script(
		'tutor-sso-subscription-product-admin',
		'tutorSsoSubscriptionAdmin',
		array( 'productType' => SUBSCRIPTION_PRODUCT_TYPE )
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\subscription_admin_assets' );

/**
 * The billing-period selector, under the price fields on the General tab.
 *
 * @return void
 */
function subscription_period_field() {
	// Without this the field is emitted on every product's edit screen — hidden
	// by its show_if_ class, but present in the markup of products that have
	// nothing to do with subscriptions.
	if ( ! subscriptions_enabled() ) {
		return;
	}

	global $post;

	woocommerce_wp_select(
		array(
			'id'          => SUBSCRIPTION_PERIOD_META,
			'label'       => __( 'Billing period', 'tutor-sso' ),
			'description' => __( 'How often the saved card is charged.', 'tutor-sso' ),
			'desc_tip'    => true,
			'options'     => subscription_periods(),
			'value'       => subscription_period( $post ? $post->ID : 0 ),
			// Only meaningful for a subscription, so hidden for every other type.
			'wrapper_class' => 'show_if_' . SUBSCRIPTION_PRODUCT_TYPE,
		)
	);
}
add_action( 'woocommerce_product_options_pricing', __NAMESPACE__ . '\\subscription_period_field' );

/**
 * Save the billing period.
 *
 * @param int $product_id Product being saved.
 * @return void
 */
function subscription_save_period( $product_id ) {
	// Nonce is WooCommerce's own, already verified before this hook runs.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$posted = isset( $_POST[ SUBSCRIPTION_PERIOD_META ] ) ? sanitize_key( wp_unslash( $_POST[ SUBSCRIPTION_PERIOD_META ] ) ) : '';

	if ( ! array_key_exists( $posted, subscription_periods() ) ) {
		return;
	}

	update_post_meta( (int) $product_id, SUBSCRIPTION_PERIOD_META, $posted );
}
add_action( 'woocommerce_process_product_meta_' . SUBSCRIPTION_PRODUCT_TYPE, __NAMESPACE__ . '\\subscription_save_period' );

/**
 * Product meta holding a plan's feature list, one feature per line.
 */
const SUBSCRIPTION_FEATURES_META = '_rwaq_sub_features';

/**
 * The plan's features, shown as the ticked list on the plans page.
 *
 * A plain textarea, one feature per line: plans are made here rather than
 * synced from the LMS, so whoever creates one writes its list too.
 *
 * @return void
 */
function subscription_features_field() {
	if ( ! subscriptions_enabled() ) {
		return;
	}

	global $post;

	woocommerce_wp_textarea_input(
		array(
			'id'            => SUBSCRIPTION_FEATURES_META,
			'label'         => __( 'Plan features', 'tutor-sso' ),
			'description'   => __( 'One feature per line. Shown as the ticked list on the plans page.', 'tutor-sso' ),
			'desc_tip'      => true,
			'rows'          => 6,
			'value'         => $post ? (string) get_post_meta( $post->ID, SUBSCRIPTION_FEATURES_META, true ) : '',
			'wrapper_class' => 'show_if_' . SUBSCRIPTION_PRODUCT_TYPE,
		)
	);
}
add_action( 'woocommerce_product_options_pricing', __NAMESPACE__ . '\\subscription_features_field', 11 );

/**
 * Save the feature list.
 *
 * @param int $product_id Product being saved.
 * @return void
 */
function subscription_save_features( $product_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own nonce, verified before this hook.
	if ( ! isset( $_POST[ SUBSCRIPTION_FEATURES_META ] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$value = sanitize_textarea_field( wp_unslash( $_POST[ SUBSCRIPTION_FEATURES_META ] ) );

	update_post_meta( (int) $product_id, SUBSCRIPTION_FEATURES_META, $value );
}
add_action( 'woocommerce_process_product_meta_' . SUBSCRIPTION_PRODUCT_TYPE, __NAMESPACE__ . '\\subscription_save_features' );

/**
 * A plan's features as a list, blank lines dropped.
 *
 * @param int $product_id Plan product.
 * @return string[]
 */
function subscription_features( $product_id ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) get_post_meta( (int) $product_id, SUBSCRIPTION_FEATURES_META, true ) );

	return array_values( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) );
}
