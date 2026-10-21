<?php
/**
 * "الاشتراك" — the subscription page in My Account.
 *
 * Designs: Figma 10529:104916 (active), 10529:104991 (cancelled),
 * 10529:105124 (cancel dialog), 10529:105059 (no subscription).
 *
 * Four states, decided by subscription_user_current():
 *
 *   - active                → the plan, its next renewal, and a cancel button;
 *   - cancelled, still paid → the same card with renewal stopped, the date the
 *                             benefits end, and a resume button;
 *   - had one, now over     → a prompt to buy a new subscription;
 *   - never had one         → the empty state, pointing at the catalogs.
 *
 * Payment history below the card lists only this customer's subscription
 * payments; every purchase already has WooCommerce's own Orders page. Each row
 * links to WooCommerce's order view rather than to a PDF.
 *
 * Cancel and resume are posted back to this page and handled on
 * template_redirect, the way WooCommerce's own account forms are, so notices go
 * through the customer's session and appear on the page they return to.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The My Account endpoint.
 */
const SUBSCRIPTION_ACCOUNT_ENDPOINT = 'subscriptions';

/**
 * Payments per page in the history table.
 */
const SUBSCRIPTION_HISTORY_PER_PAGE = 10;

/**
 * Option recording that rewrite rules include the endpoint.
 */
const SUBSCRIPTION_ACCOUNT_REWRITE_OPTION = 'tutor_sso_subscriptions_endpoint_rewrite';

/**
 * Body class on this page, which the stylesheet is scoped to.
 */
const SUBSCRIPTION_ACCOUNT_BODY_CLASS = 'rwaq-account-subscriptions';

/**
 * Register the endpoint with WooCommerce.
 *
 * Registered whether or not subscriptions are switched on, so the rewrite rules
 * never change with the setting; the menu entry and the page are what the
 * setting controls.
 *
 * @param array $vars Query vars, keyed by endpoint.
 * @return array
 */
function subscription_account_query_vars( $vars ) {
	if ( is_array( $vars ) ) {
		$vars[ SUBSCRIPTION_ACCOUNT_ENDPOINT ] = SUBSCRIPTION_ACCOUNT_ENDPOINT;
	}

	return $vars;
}
add_filter( 'woocommerce_get_query_vars', __NAMESPACE__ . '\\subscription_account_query_vars' );

/**
 * Make sure the rewrite rules know the endpoint, once.
 *
 * A new endpoint 404s until rewrite rules are rebuilt, and there is no
 * activation hook to do it from. Flushing is expensive, so it is done a single
 * time and remembered. Runs after WooCommerce has added its endpoints (init,
 * priority 10).
 *
 * @return void
 */
function subscription_account_flush_rewrite() {
	if ( '1' === (string) get_option( SUBSCRIPTION_ACCOUNT_REWRITE_OPTION ) ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( SUBSCRIPTION_ACCOUNT_REWRITE_OPTION, '1', false );
}
add_action( 'init', __NAMESPACE__ . '\\subscription_account_flush_rewrite', 20 );

/**
 * Whether the current request is this page.
 *
 * @return bool
 */
function subscription_account_is_page() {
	return function_exists( 'is_account_page' ) && is_account_page()
		&& function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( SUBSCRIPTION_ACCOUNT_ENDPOINT );
}

/**
 * The page's title, used for the menu entry and the page heading.
 *
 * @return string
 */
function subscription_account_title() {
	return __( 'الاشتراك', 'tutor-sso' );
}

/**
 * Add the page to the account menu, just before Log out.
 *
 * @param array $items Menu items, keyed by endpoint.
 * @return array
 */
function subscription_account_menu_item( $items ) {
	if ( ! is_array( $items ) || ! subscriptions_enabled() ) {
		return $items;
	}

	$out = array();

	foreach ( $items as $key => $label ) {
		if ( 'customer-logout' === $key ) {
			$out[ SUBSCRIPTION_ACCOUNT_ENDPOINT ] = subscription_account_title();
		}

		$out[ $key ] = $label;
	}

	if ( ! isset( $out[ SUBSCRIPTION_ACCOUNT_ENDPOINT ] ) ) {
		$out[ SUBSCRIPTION_ACCOUNT_ENDPOINT ] = subscription_account_title();
	}

	return $out;
}
add_filter( 'woocommerce_account_menu_items', __NAMESPACE__ . '\\subscription_account_menu_item', 20 );

/**
 * The page heading.
 *
 * @return string
 */
function subscription_account_endpoint_title() {
	return subscription_account_title();
}
add_filter( 'woocommerce_endpoint_' . SUBSCRIPTION_ACCOUNT_ENDPOINT . '_title', __NAMESPACE__ . '\\subscription_account_endpoint_title' );

/**
 * Mark the page so its stylesheet can be scoped to it.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function subscription_account_body_class( $classes ) {
	if ( subscription_account_is_page() ) {
		$classes[] = SUBSCRIPTION_ACCOUNT_BODY_CLASS;
	}

	return $classes;
}
add_filter( 'body_class', __NAMESPACE__ . '\\subscription_account_body_class' );

/**
 * Load the page's stylesheet and script, on this page only.
 *
 * @return void
 */
function subscription_account_assets() {
	if ( ! subscription_account_is_page() ) {
		return;
	}

	wp_enqueue_style(
		'tutor-sso-subscriptions-account',
		TUTOR_SSO_URL . 'assets/css/subscriptions-account.css',
		array( 'tutor-sso-my-account' ),
		TUTOR_SSO_VERSION
	);

	wp_enqueue_script(
		'tutor-sso-subscriptions-account',
		TUTOR_SSO_URL . 'assets/js/subscriptions-account.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\subscription_account_assets', 22 );

/**
 * A stored timestamp as the site shows dates: "31 أكتوبر 2026".
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function subscription_account_date( $timestamp ) {
	$timestamp = (int) $timestamp;

	return $timestamp > 0 ? (string) wp_date( 'j F Y', $timestamp ) : '—';
}

/**
 * A stored UTC datetime as a timestamp.
 *
 * @param string|null $value Datetime from the table.
 * @return int 0 when empty or unreadable.
 */
function subscription_account_timestamp( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return 0;
	}

	$ts = strtotime( $value . ' UTC' );

	return $ts ? (int) $ts : 0;
}

/**
 * The plan's period label: شهري / سنوي.
 *
 * @param string $period Stored period.
 * @return string
 */
function subscription_account_period_label( $period ) {
	$periods = subscription_periods();

	return isset( $periods[ $period ] ) ? (string) $periods[ $period ] : (string) $period;
}

/**
 * Where "buy a new subscription" should go.
 *
 * The plans page when one is configured, otherwise the plan the customer had,
 * provided it can still be bought.
 *
 * @param array|null $row The customer's latest subscription.
 * @return string URL, or ''.
 */
function subscription_account_resubscribe_url( $row ) {
	$url = subscription_plans_page_url();

	if ( '' !== $url ) {
		return $url;
	}

	if ( ! is_array( $row ) || empty( $row['product_id'] ) || ! function_exists( 'wc_get_product' ) ) {
		return '';
	}

	$product = wc_get_product( (int) $row['product_id'] );

	if ( ! $product || ! $product->is_purchasable() || 'publish' !== $product->get_status() ) {
		return '';
	}

	return (string) $product->get_permalink();
}

/**
 * The nonce action for a customer's cancel or resume of one subscription.
 *
 * Per subscription, so a form for one cannot act on another.
 *
 * @param string $action cancel | resume.
 * @param int    $id     Row id.
 * @return string
 */
function subscription_account_nonce_action( $action, $id ) {
	return 'tutor_sso_sub_' . $action . '_' . (int) $id;
}

/**
 * Hidden fields for a cancel or resume form.
 *
 * @param string $action cancel | resume.
 * @param int    $id     Row id.
 * @return void
 */
function subscription_account_form_fields( $action, $id ) {
	printf( '<input type="hidden" name="tutor_sso_sub_action" value="%s" />', esc_attr( $action ) );
	printf( '<input type="hidden" name="subscription_id" value="%d" />', (int) $id );
	wp_nonce_field( subscription_account_nonce_action( $action, $id ), '_tutor_sso_sub_nonce', false );
}

/**
 * Handle a cancel or resume posted from the page.
 *
 * @return void
 */
function subscription_account_handle_post() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, per subscription.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['tutor_sso_sub_action'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! function_exists( 'wc_add_notice' ) ) {
		return;
	}

	$action = sanitize_key( wp_unslash( $_POST['tutor_sso_sub_action'] ) );
	$id     = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
	$nonce  = isset( $_POST['_tutor_sso_sub_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_tutor_sso_sub_nonce'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$back = wc_get_account_endpoint_url( SUBSCRIPTION_ACCOUNT_ENDPOINT );

	if ( ! in_array( $action, array( 'cancel', 'resume' ), true ) ) {
		return;
	}

	$row = $id ? subscription_get( $id ) : null;

	// The same message for a bad nonce and for someone else's subscription, so
	// the form cannot be used to find out which ids exist.
	if ( ! wp_verify_nonce( $nonce, subscription_account_nonce_action( $action, $id ) )
		|| ! $row
		|| (int) $row['user_id'] !== get_current_user_id() ) {
		wc_add_notice( __( 'تعذّر تنفيذ الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.', 'tutor-sso' ), 'error' );
		wp_safe_redirect( $back );
		exit;
	}

	if ( 'cancel' === $action ) {
		if ( 'active' !== $row['status'] ) {
			wc_add_notice( __( 'هذا الاشتراك ليس فعّالاً.', 'tutor-sso' ), 'error' );
		} else {
			// The LMS outcome is not the customer's concern: billing has stopped
			// either way, and a failed call is recorded for the admin to retry.
			subscription_cancel( $id, __( 'Customer cancelled from their account', 'tutor-sso' ) );

			wc_add_notice(
				sprintf(
					/* translators: %s: date the benefits end. */
					__( 'تم إلغاء اشتراكك. ستبقى مزاياه متاحة حتى %s.', 'tutor-sso' ),
					subscription_account_date( subscription_paid_until( $row ) )
				),
				'success'
			);
		}
	} else {
		$result = subscription_reactivate( $id, __( 'Customer resumed from their account', 'tutor-sso' ) );

		if ( is_wp_error( $result ) ) {
			// Not the LMS's own wording: that is for the admin, not a customer.
			wc_add_notice(
				'tutor_sso_subscription_not_resumable' === $result->get_error_code()
					? $result->get_error_message()
					: __( 'تعذّر استئناف اشتراكك الآن. يرجى المحاولة لاحقاً.', 'tutor-sso' ),
				'error'
			);
		} else {
			wc_add_notice( __( 'تم استئناف اشتراكك، وسيتجدد تلقائياً.', 'tutor-sso' ), 'success' );
		}
	}

	wp_safe_redirect( $back );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\subscription_account_handle_post', 5 );

/**
 * Send visitors back to the account dashboard while subscriptions are off.
 *
 * @return void
 */
function subscription_account_redirect_when_disabled() {
	if ( subscriptions_enabled() || ! subscription_account_is_page() ) {
		return;
	}

	wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
	exit;
}
add_action( 'template_redirect', __NAMESPACE__ . '\\subscription_account_redirect_when_disabled', 6 );

/**
 * One page of the customer's subscription payments.
 *
 * Paid orders only, of any subscription they have had — a failed renewal is
 * not a payment, and a past subscription's payments are still theirs.
 *
 * @param int $user_id Customer.
 * @param int $page    Page number, from 1.
 * @return array{orders:array,pages:int}
 */
function subscription_account_history( $user_id, $page ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array(
			'orders' => array(),
			'pages'  => 0,
		);
	}

	$result = subscription_get_orders(
		array(
			'customer_id' => (int) $user_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => SUBSCRIPTION_HISTORY_PER_PAGE,
			'paged'       => max( 1, (int) $page ),
			'paginate'    => true,
			'orderby'     => 'date',
			'order'       => 'DESC',
		),
		array(
			array(
				'key'     => SUBSCRIPTION_ORDER_ID_META,
				'compare' => 'EXISTS',
			),
		)
	);

	return array(
		'orders' => is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : array(),
		'pages'  => is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 0,
	);
}

/**
 * What a payment was for: "خطة الاشتراك — شهري".
 *
 * @param \WC_Order $order Subscription order.
 * @return string
 */
function subscription_account_order_description( $order ) {
	$name = '';

	foreach ( $order->get_items() as $item ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		if ( $product_id && subscription_product_is_subscription( $product_id ) ) {
			$name = (string) $item->get_name();
			break;
		}
	}

	$period = subscription_account_period_label( (string) $order->get_meta( SUBSCRIPTION_ORDER_PLAN_META ) );

	if ( '' === $name ) {
		return $period;
	}

	return '' === $period ? $name : $name . ' — ' . $period;
}

/**
 * Render the page.
 *
 * @param string $value The endpoint's value: the history page number.
 * @return void
 */
function subscription_account_render( $value = '' ) {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();
	$page    = max( 1, absint( $value ) );
	$current = subscription_user_current( $user_id );

	// The payment history stays below the card in every state: past payments
	// are still the customer's after the subscription ends.
	if ( ! $current ) {
		$latest = subscription_user_latest( $user_id );

		echo '<div class="rwaq-sub rwaq-sub--empty">';

		if ( $latest ) {
			subscription_account_render_expired( $latest );
		} else {
			subscription_account_render_empty();
		}

		subscription_account_render_history( $user_id, $page );
		echo '</div>';

		return;
	}

	echo '<div class="rwaq-sub">';
	subscription_account_render_card( $current );
	subscription_account_render_history( $user_id, $page );
	subscription_account_render_cancel_dialog( $current );
	echo '</div>';
}
add_action( 'woocommerce_account_' . SUBSCRIPTION_ACCOUNT_ENDPOINT . '_endpoint', __NAMESPACE__ . '\\subscription_account_render' );

/**
 * The receipt icon used on the empty states, as in the design.
 *
 * @return string SVG markup.
 */
function subscription_account_receipt_icon() {
	return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3h12a1 1 0 0 1 1 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/><path d="M9 8h6M9 12h6"/></svg>';
}

/**
 * Never subscribed: point at the catalogs.
 *
 * @return void
 */
function subscription_account_render_empty() {
	?>
		<section class="rwaq-sub-card rwaq-sub-empty">
			<span class="rwaq-sub-empty__icon"><?php echo subscription_account_receipt_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
			<h2 class="rwaq-sub-empty__title"><?php esc_html_e( 'لا يوجد شيء هنا بعد.', 'tutor-sso' ); ?></h2>
			<p class="rwaq-sub-empty__text"><?php esc_html_e( 'سيظهر اشتراكك وسجل مدفوعاته هنا بعد اشتراكك الأول.', 'tutor-sso' ); ?></p>
			<div class="rwaq-sub-empty__actions">
				<?php // Right-to-left: courses on the right, programs (primary) on the left, as designed. ?>
				<a class="rwaq-sub-btn rwaq-sub-btn--secondary" href="<?php echo esc_url( search_archive_url( 'courses' ) ); ?>"><?php esc_html_e( 'تصفّح الدورات', 'tutor-sso' ); ?></a>
				<a class="rwaq-sub-btn rwaq-sub-btn--primary" href="<?php echo esc_url( search_archive_url( 'programs' ) ); ?>"><?php esc_html_e( 'تصفّح البرامج', 'tutor-sso' ); ?></a>
			</div>
		</section>
	<?php
}

/**
 * Had a subscription, and its paid period is over: buy a new one.
 *
 * @param array $latest The customer's most recent subscription.
 * @return void
 */
function subscription_account_render_expired( $latest ) {
	$url = subscription_account_resubscribe_url( $latest );
	?>
		<section class="rwaq-sub-card rwaq-sub-empty">
			<span class="rwaq-sub-empty__icon"><?php echo subscription_account_receipt_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
			<h2 class="rwaq-sub-empty__title"><?php esc_html_e( 'انتهى اشتراكك.', 'tutor-sso' ); ?></h2>
			<p class="rwaq-sub-empty__text"><?php esc_html_e( 'لمواصلة الوصول إلى محتوى الاشتراك، يرجى الاشتراك من جديد.', 'tutor-sso' ); ?></p>
			<?php if ( '' !== $url ) : ?>
				<div class="rwaq-sub-empty__actions">
					<a class="rwaq-sub-btn rwaq-sub-btn--primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'اشترك من جديد', 'tutor-sso' ); ?></a>
				</div>
			<?php endif; ?>
		</section>
	<?php
}

/**
 * One label/value cell of the details grid.
 *
 * @param string $label Escaped-on-output label.
 * @param string $value Already-escaped value HTML.
 * @return void
 */
function subscription_account_render_cell( $label, $value ) {
	printf(
		'<div class="rwaq-sub-grid__cell"><span class="rwaq-sub-grid__label">%1$s</span><span class="rwaq-sub-grid__value">%2$s</span></div>',
		esc_html( $label ),
		$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
	);
}

/**
 * The subscription card: status, details, and cancel or resume.
 *
 * @param array $row Current subscription.
 * @return void
 */
function subscription_account_render_card( $row ) {
	$active     = 'active' === $row['status'];
	$paid_until = subscription_paid_until( $row );
	$amount     = function_exists( 'wc_price' )
		? wc_price( (float) $row['amount'], array( 'currency' => (string) $row['currency'] ) )
		: esc_html( $row['amount'] . ' ' . $row['currency'] );
	?>
	<section class="rwaq-sub-card rwaq-sub-details">
		<header class="rwaq-sub-card__header">
			<h2 class="rwaq-sub-card__title"><?php esc_html_e( 'تفاصيل الاشتراك', 'tutor-sso' ); ?></h2>
			<?php if ( $active ) : ?>
				<span class="rwaq-sub-pill rwaq-sub-pill--active"><?php esc_html_e( 'فعّال', 'tutor-sso' ); ?></span>
			<?php else : ?>
				<span class="rwaq-sub-pill rwaq-sub-pill--cancelled"><?php esc_html_e( 'ملغي — فعّال حتى نهاية الفترة', 'tutor-sso' ); ?></span>
			<?php endif; ?>
		</header>

		<?php if ( $active && (int) $row['failure_count'] > 0 ) : ?>
			<div class="rwaq-sub-message" role="status">
				<?php
				printf(
					/* translators: %s: date of the next payment attempt. */
					esc_html__( 'تعذّر تحصيل آخر دفعة لاشتراكك. سنعيد المحاولة تلقائياً في %s.', 'tutor-sso' ),
					esc_html( subscription_account_date( subscription_account_timestamp( $row['next_payment_at'] ) ) )
				);
				?>
			</div>
		<?php endif; ?>

		<div class="rwaq-sub-grid">
			<?php
			// Right-to-left: each pair is right cell then left cell, as laid out
			// in the design — amount and date on the right, type and renewal on
			// the left.
			subscription_account_render_cell( __( 'قيمة الاشتراك', 'tutor-sso' ), wp_kses_post( $amount ) );
			subscription_account_render_cell(
				__( 'نوع الاشتراك', 'tutor-sso' ),
				'<span class="rwaq-sub-badge">' . esc_html( subscription_account_period_label( (string) $row['period'] ) ) . '</span>'
			);

			if ( $active ) {
				// The renewal date is when the paid period ends, not when the
				// card is charged — a day earlier, see subscription_charge_at().
				subscription_account_render_cell(
					__( 'تاريخ التجديد القادم', 'tutor-sso' ),
					esc_html( subscription_account_date( $paid_until ) )
				);
			} else {
				subscription_account_render_cell( __( 'تنتهي المزايا في', 'tutor-sso' ), esc_html( subscription_account_date( $paid_until ) ) );
			}

			subscription_account_render_cell(
				__( 'التجديد التلقائي', 'tutor-sso' ),
				esc_html( $active ? __( 'مفعّل', 'tutor-sso' ) : __( 'متوقف', 'tutor-sso' ) )
			);
			?>
		</div>

		<?php
		// The card is charged a day before the renewal date (subscription_charge_at()),
		// so the charge on their statement is not a surprise. Not while a payment
		// is failing — the message above already gives the retry date.
		if ( $active && 0 === (int) $row['failure_count'] && subscription_charge_lead() > 0 && $paid_until > 0 ) :
			?>
			<p class="rwaq-sub-card__note">
				<?php
				printf(
					/* translators: %s: date the card is charged. */
					esc_html__( 'تُحصَّل قيمة التجديد قبل يوم واحد من تاريخ التجديد، أي في %s.', 'tutor-sso' ),
					esc_html( subscription_account_date( subscription_charge_at( $paid_until ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<div class="rwaq-sub-card__actions">
			<?php if ( $active ) : ?>
				<button type="button" class="rwaq-sub-btn rwaq-sub-btn--danger" data-rwaq-sub-open="rwaq-sub-cancel-dialog" aria-haspopup="dialog">
					<?php esc_html_e( 'إلغاء الاشتراك', 'tutor-sso' ); ?>
				</button>
			<?php elseif ( subscription_can_resume( $row ) ) : ?>
				<form method="post" class="rwaq-sub-resume">
					<?php subscription_account_form_fields( 'resume', (int) $row['id'] ); ?>
					<p class="rwaq-sub-resume__note">
						<?php
						printf(
							/* translators: %s: date of the next payment. */
							esc_html__( 'عند الاستئناف، يُعاد تفعيل التجديد التلقائي وتُحصَّل الدفعة القادمة في %s.', 'tutor-sso' ),
							esc_html( subscription_account_date( $paid_until ) )
						);
						?>
					</p>
					<button type="submit" class="rwaq-sub-btn rwaq-sub-btn--primary"><?php esc_html_e( 'استئناف الاشتراك', 'tutor-sso' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * Payment history: the customer's paid subscription orders.
 *
 * @param int $user_id Customer.
 * @param int $page    Page number.
 * @return void
 */
function subscription_account_render_history( $user_id, $page ) {
	$history = subscription_account_history( $user_id, $page );

	if ( empty( $history['orders'] ) && 1 === $page ) {
		return;
	}
	?>
	<section class="rwaq-sub-card rwaq-sub-history">
		<header class="rwaq-sub-history__header">
			<h2 class="rwaq-sub-card__title rwaq-sub-card__title--small"><?php esc_html_e( 'سجل المدفوعات', 'tutor-sso' ); ?></h2>
			<p class="rwaq-sub-history__subtitle"><?php esc_html_e( 'جميع مدفوعات اشتراكك، ويمكنك الاطلاع على تفاصيل أي منها.', 'tutor-sso' ); ?></p>
		</header>

		<div class="rwaq-sub-table-wrap">
			<table class="rwaq-sub-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'رقم الطلب', 'tutor-sso' ); ?></th>
						<th scope="col"><?php esc_html_e( 'التاريخ', 'tutor-sso' ); ?></th>
						<th scope="col"><?php esc_html_e( 'الوصف', 'tutor-sso' ); ?></th>
						<th scope="col"><?php esc_html_e( 'المبلغ', 'tutor-sso' ); ?></th>
						<th scope="col"><?php esc_html_e( 'الإجراءات', 'tutor-sso' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history['orders'] as $order ) : ?>
						<?php
						if ( ! is_object( $order ) || ! method_exists( $order, 'get_view_order_url' ) ) {
							continue;
						}

						$paid = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
						?>
						<tr>
							<td data-title="<?php esc_attr_e( 'رقم الطلب', 'tutor-sso' ); ?>"><?php echo esc_html( '#' . $order->get_order_number() ); ?></td>
							<td data-title="<?php esc_attr_e( 'التاريخ', 'tutor-sso' ); ?>"><?php echo esc_html( $paid ? subscription_account_date( $paid->getTimestamp() ) : '—' ); ?></td>
							<td data-title="<?php esc_attr_e( 'الوصف', 'tutor-sso' ); ?>"><?php echo esc_html( subscription_account_order_description( $order ) ); ?></td>
							<td data-title="<?php esc_attr_e( 'المبلغ', 'tutor-sso' ); ?>" class="rwaq-sub-table__amount"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td class="rwaq-sub-table__action">
								<a class="rwaq-sub-btn rwaq-sub-btn--small" href="<?php echo esc_url( $order->get_view_order_url() ); ?>"><?php esc_html_e( 'التفاصيل', 'tutor-sso' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php subscription_account_render_pagination( $page, (int) $history['pages'] ); ?>
	</section>
	<?php
}

/**
 * Previous / page numbers / Next.
 *
 * @param int $page  Current page.
 * @param int $pages Total pages.
 * @return void
 */
function subscription_account_render_pagination( $page, $pages ) {
	if ( $pages < 2 ) {
		return;
	}

	$url = static function ( $n ) {
		return wc_get_account_endpoint_url( SUBSCRIPTION_ACCOUNT_ENDPOINT ) . ( $n > 1 ? $n . '/' : '' );
	};

	echo '<nav class="rwaq-sub-pagination" aria-label="' . esc_attr__( 'صفحات سجل المدفوعات', 'tutor-sso' ) . '">';

	if ( $page > 1 ) {
		printf( '<a class="rwaq-sub-page rwaq-sub-page--step" href="%1$s">%2$s</a>', esc_url( $url( $page - 1 ) ), esc_html__( 'السابق', 'tutor-sso' ) );
	}

	for ( $n = 1; $n <= $pages; $n++ ) {
		if ( $n === $page ) {
			printf( '<span class="rwaq-sub-page is-current" aria-current="page">%d</span>', (int) $n );
		} else {
			printf( '<a class="rwaq-sub-page" href="%1$s">%2$d</a>', esc_url( $url( $n ) ), (int) $n );
		}
	}

	if ( $page < $pages ) {
		printf( '<a class="rwaq-sub-page rwaq-sub-page--step" href="%1$s">%2$s</a>', esc_url( $url( $page + 1 ) ), esc_html__( 'التالي', 'tutor-sso' ) );
	}

	echo '</nav>';
}

/**
 * The cancel confirmation dialog. Only for an active subscription.
 *
 * A native <dialog>: it brings the modal backdrop, Escape to close and focus
 * containment with it, rather than reimplementing them.
 *
 * @param array $row Current subscription.
 * @return void
 */
function subscription_account_render_cancel_dialog( $row ) {
	if ( 'active' !== $row['status'] ) {
		return;
	}

	$check = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9.25"/><path d="m8.5 12.2 2.3 2.3 4.7-4.9"/></svg>';
	?>
	<dialog id="rwaq-sub-cancel-dialog" class="rwaq-sub-dialog" aria-labelledby="rwaq-sub-cancel-title">
		<form method="post" class="rwaq-sub-dialog__body">
			<?php subscription_account_form_fields( 'cancel', (int) $row['id'] ); ?>

			<span class="rwaq-sub-dialog__icon">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9.25"/><path d="M12 11v5.5"/><circle cx="12" cy="7.75" r=".9" fill="currentColor" stroke="none"/></svg>
			</span>

			<h2 id="rwaq-sub-cancel-title" class="rwaq-sub-dialog__title"><?php esc_html_e( 'هل تريد إلغاء اشتراكك؟', 'tutor-sso' ); ?></h2>
			<p class="rwaq-sub-dialog__subtitle"><?php esc_html_e( 'لن يتغيّر شيء اليوم. إليك ما سيحدث بعد الإلغاء:', 'tutor-sso' ); ?></p>

			<ul class="rwaq-sub-dialog__list">
				<li><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'سيتوقف التجديد التلقائي، ولن يتجدد اشتراكك بعد انتهاء الفترة الحالية.', 'tutor-sso' ); ?></span></li>
				<li><?php echo $check; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'ستواصل الاستفادة من جميع مزايا اشتراكك حتى نهاية الفترة الحالية.', 'tutor-sso' ); ?></span></li>
			</ul>

			<div class="rwaq-sub-dialog__date">
				<span><?php esc_html_e( 'تنتهي مزايا اشتراكك في', 'tutor-sso' ); ?></span>
				<strong><?php echo esc_html( subscription_account_date( subscription_paid_until( $row ) ) ); ?></strong>
			</div>

			<div class="rwaq-sub-dialog__actions">
				<button type="submit" class="rwaq-sub-btn rwaq-sub-btn--danger"><?php esc_html_e( 'نعم، ألغِ الاشتراك', 'tutor-sso' ); ?></button>
				<?php // autofocus: showModal() focuses the first focusable control, which would be the destructive one — Enter would cancel. ?>
				<button type="button" class="rwaq-sub-btn rwaq-sub-btn--secondary" data-rwaq-sub-close autofocus><?php esc_html_e( 'تراجع', 'tutor-sso' ); ?></button>
			</div>
		</form>
	</dialog>
	<?php
}
