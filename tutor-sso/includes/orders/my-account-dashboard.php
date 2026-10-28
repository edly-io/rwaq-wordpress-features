<?php
/**
 * The My Account dashboard: a greeting and a card for each account section.
 *
 * WooCommerce's dashboard is two lines of boilerplate, and on this site most of
 * it is wrong: "Not you? Log out" repeats the menu's own Log out, and it links
 * to password editing, which is off under SSO (see password-reset.php). It is
 * replaced with a greeting and a card per menu entry.
 *
 * This is the one My Account template the plugin replaces, through the
 * `wc_get_template` filter rather than a theme override; the rest of the account
 * area is still styled only (my-account-styles.php). The replacement keeps
 * WooCommerce's dashboard hooks, so anything attached to them still prints.
 *
 * Cards are built from wc_get_account_menu_items(), so they follow the menu,
 * except that Subscriptions and Payment methods have no card — both are a
 * click away in the menu.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serve the plugin's dashboard template in place of WooCommerce's.
 *
 * @param string $template      Resolved template path.
 * @param string $template_name Template name relative to the templates folder.
 * @return string
 */
function my_account_dashboard_template( $template, $template_name ) {
	if ( 'myaccount/dashboard.php' !== $template_name ) {
		return $template;
	}

	return TUTOR_SSO_PATH . 'templates/myaccount/dashboard.php';
}
add_filter( 'wc_get_template', __NAMESPACE__ . '\\my_account_dashboard_template', 10, 2 );

/**
 * One-line description for each dashboard card, keyed by endpoint.
 *
 * An endpoint with no entry gets a card with its title only.
 *
 * @return string[]
 */
function my_account_dashboard_descriptions() {
	/**
	 * Filter the dashboard card descriptions.
	 *
	 * @param string[] $descriptions Descriptions keyed by endpoint.
	 */
	return (array) apply_filters(
		'tutor_sso_my_account_dashboard_descriptions',
		array(
			'orders'          => __( 'تابع طلباتك السابقة وتفاصيل الدفع.', 'tutor-sso' ),
			'edit-address'    => __( 'عنوان الفاتورة المستخدم عند إتمام الطلب.', 'tutor-sso' ),
			'edit-account'    => __( 'اطّلع على تفاصيل حسابك.', 'tutor-sso' ),
		)
	);
}

/**
 * Render the dashboard.
 *
 * @param \WP_User|null $user Current user.
 * @return void
 */
function my_account_dashboard_render( $user = null ) {
	if ( ! $user instanceof \WP_User ) {
		$user = wp_get_current_user();
	}

	$name = trim( (string) $user->first_name );

	if ( '' === $name ) {
		$name = (string) $user->display_name;
	}

	$descriptions = my_account_dashboard_descriptions();
	$items        = function_exists( 'wc_get_account_menu_items' ) ? wc_get_account_menu_items() : array();

	// The dashboard is this page, and Log out is in the menu beside it.
	// Subscriptions and Payment methods stay in the menu only.
	unset(
		$items['dashboard'],
		$items['customer-logout'],
		$items['subscriptions'],
		$items['payment-methods']
	);
	?>
	<div class="rwaq-acc-dash">
		<div class="rwaq-acc-dash__intro">
			<h2 class="rwaq-acc-dash__title">
				<?php
				/* translators: %s: the customer's name. */
				echo esc_html( sprintf( __( 'مرحبًا، %s', 'tutor-sso' ), $name ) );
				?>
			</h2>
			<p class="rwaq-acc-dash__subtitle"><?php esc_html_e( 'من هنا يمكنك متابعة طلباتك وإدارة عنوانك وتفاصيل حسابك.', 'tutor-sso' ); ?></p>
		</div>

		<?php if ( $items ) : ?>
			<ul class="rwaq-acc-dash__cards">
				<?php foreach ( $items as $endpoint => $label ) : ?>
					<li class="rwaq-acc-dash__card rwaq-acc-dash__card--<?php echo esc_attr( $endpoint ); ?>">
						<a class="rwaq-acc-dash__link" href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>">
							<span class="rwaq-acc-dash__icon" aria-hidden="true"></span>
							<span class="rwaq-acc-dash__text">
								<span class="rwaq-acc-dash__label"><?php echo esc_html( $label ); ?></span>
								<?php if ( ! empty( $descriptions[ $endpoint ] ) ) : ?>
									<span class="rwaq-acc-dash__desc"><?php echo esc_html( $descriptions[ $endpoint ] ); ?></span>
								<?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
