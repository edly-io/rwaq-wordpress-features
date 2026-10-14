<?php
/**
 * The "run renewals now" control on the settings screen.
 *
 * Renewals are driven by Action Scheduler, which WordPress runs from WP-Cron —
 * and WP-Cron only fires when somebody visits the site. On a quiet staging
 * install a renewal can therefore sit waiting for hours, which makes it
 * impossible to tell a broken renewal from one that simply has not run yet.
 * This runs the due ones immediately and reports what happened, so the answer
 * is never ambiguous.
 *
 * It is useful beyond testing: it is also the way to clear a backlog after
 * downtime, or to push a renewal through after fixing a card.
 *
 * Renewals run here synchronously rather than being queued — queueing would put
 * the wait straight back.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin-post action name.
 */
const SUBSCRIPTION_RUN_ACTION = 'tutor_sso_run_renewals';

/**
 * How many renewals one click may take on.
 *
 * Bounded because each one calls out to the payment gateway, and a request that
 * runs out of time mid-way would leave the rest unprocessed with nothing said
 * about it.
 */
const SUBSCRIPTION_RUN_LIMIT = 20;

/**
 * The settings page this button is shown on.
 *
 * @return string
 */
function subscription_admin_page_slug() {
	return class_exists( '\\TutorSSO\\Admin\\Settings_Page' )
		? \TutorSSO\Admin\Settings_Page::PAGE_SLUG
		: 'tutor-sso-settings';
}

/**
 * Add the button to the Subscriptions section.
 *
 * Registered from here rather than in the settings class so the whole feature
 * stays in one place.
 *
 * @return void
 */
function subscription_admin_register_button() {
	add_settings_field(
		'tutor_sso_run_renewals',
		__( 'Renewals', 'tutor-sso' ),
		__NAMESPACE__ . '\\subscription_admin_render_button',
		subscription_admin_page_slug(),
		'tutor_sso_section_subscriptions'
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\\subscription_admin_register_button', 20 );

/**
 * Render the button and what it would do.
 *
 * @return void
 */
function subscription_admin_render_button() {
	$due = count( subscription_due_ids( SUBSCRIPTION_RUN_LIMIT ) );

	$url = wp_nonce_url(
		add_query_arg( 'action', SUBSCRIPTION_RUN_ACTION, admin_url( 'admin-post.php' ) ),
		SUBSCRIPTION_RUN_ACTION
	);

	printf(
		'<a href="%1$s" class="button">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'Run renewals now', 'tutor-sso' )
	);

	echo '<p class="description">';

	if ( $due > 0 ) {
		printf(
			esc_html(
				/* translators: %d: number of subscriptions due. */
				_n(
					'%d subscription is due to be charged.',
					'%d subscriptions are due to be charged.',
					$due,
					'tutor-sso'
				)
			),
			(int) $due
		);
	} else {
		esc_html_e( 'Nothing is due right now. Renewals also run on their own, every hour.', 'tutor-sso' );
	}

	echo '</p>';
}

/**
 * Charge everything that is due, now.
 *
 * @return void
 */
function subscription_admin_run_renewals() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'tutor-sso' ) );
	}

	check_admin_referer( SUBSCRIPTION_RUN_ACTION );

	$due = subscription_due_ids( SUBSCRIPTION_RUN_LIMIT );

	foreach ( $due as $id ) {
		subscription_renew( $id );
	}

	wp_safe_redirect(
		add_query_arg(
			'tutor_sso_renewals_run',
			count( $due ),
			admin_url( 'admin.php?page=' . subscription_admin_page_slug() )
		)
	);
	exit;
}
add_action( 'admin_post_' . SUBSCRIPTION_RUN_ACTION, __NAMESPACE__ . '\\subscription_admin_run_renewals' );

/**
 * Say what happened.
 *
 * Reports what was attempted, not what succeeded: the gateway may finish a
 * payment by webhook after this request has ended, so claiming success here
 * could be wrong. The orders list is the record of the outcome.
 *
 * @return void
 */
function subscription_admin_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['tutor_sso_renewals_run'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$count = (int) $_GET['tutor_sso_renewals_run'];

	$message = $count > 0
		? sprintf(
			/* translators: %d: number of renewals attempted. */
			_n(
				'Attempted %d renewal. Check the orders list for the result.',
				'Attempted %d renewals. Check the orders list for the result.',
				$count,
				'tutor-sso'
			),
			$count
		)
		: __( 'Nothing was due, so no renewal was attempted.', 'tutor-sso' );

	printf(
		'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
		esc_html( $message )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\subscription_admin_notice' );
