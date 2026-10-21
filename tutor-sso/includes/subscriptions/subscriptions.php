<?php
/**
 * Subscriptions: shared constants and the feature switch.
 *
 * A subscription is a WordPress-side product — unlike courses and programs it
 * is created by hand in WooCommerce rather than synced from the LMS, so none of
 * the sync code is involved.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce product type for a subscription plan.
 *
 * The literal matters beyond being an identifier: the Moyasar gateway decides
 * whether to tokenize a card by testing the product type for the substring
 * "subscription" (lowercase), so a type named without it would take payment
 * and save no card, leaving nothing to charge at renewal.
 *
 * That is a fallback here rather than the mechanism — checkout asks for the
 * card to be saved through WooCommerce's own
 * `wc-{gateway}-new-payment-method` field, which every gateway supporting
 * tokenization reads — but the naming costs nothing and covers the case where
 * that field does not reach the gateway.
 */
const SUBSCRIPTION_PRODUCT_TYPE = 'rwaq_subscription';

/**
 * Product meta holding the billing period: 'monthly' or 'yearly'.
 */
const SUBSCRIPTION_PERIOD_META = '_rwaq_sub_period';

/**
 * Whether the subscriptions feature is switched on.
 *
 * This gates what can be *sold*: the product type in the admin, the account
 * area and the renewal schedule. It deliberately does not gate the product
 * class itself (see subscriptions-product-type.php) and must never gate
 * renewals of subscriptions that are already running — a settings checkbox
 * should not silently stop billing paying customers.
 *
 * @return bool
 */
function subscriptions_enabled() {
	return (bool) apply_filters( 'tutor_sso_subscriptions_enabled', '1' === (string) sso_option( 'enable_subscriptions' ) );
}

/**
 * The billing periods a plan can use, as value => label.
 *
 * @return array<string,string>
 */
function subscription_periods() {
	return array(
		'monthly' => __( 'شهري', 'tutor-sso' ),
		'yearly'  => __( 'سنوي', 'tutor-sso' ),
	);
}

/**
 * A product's billing period, defaulting to monthly.
 *
 * @param int $product_id Product ID.
 * @return string 'monthly' or 'yearly'.
 */
function subscription_period( $product_id ) {
	$period = (string) get_post_meta( (int) $product_id, SUBSCRIPTION_PERIOD_META, true );

	return array_key_exists( $period, subscription_periods() ) ? $period : 'monthly';
}

/**
 * How long before a period ends its renewal is charged.
 *
 * The LMS ends access the moment a period runs out with no renewal recorded,
 * so a renewal charged exactly then leaves a customer whose card fails with no
 * access from that second on — every retry happens after access is gone.
 * Charging a day early means the first attempt, and its failure email, come
 * while the customer still has access and time to change card.
 *
 * Only the charge moves. The period it pays for still starts when the current
 * one ends, so subscription dates never shift.
 *
 * @return int Seconds.
 */
function subscription_charge_lead() {
	/**
	 * Filter how long before a period ends its renewal is charged.
	 *
	 * @param int $seconds Seconds. 0 charges at the moment the period ends.
	 */
	return max( 0, (int) apply_filters( 'tutor_sso_subscription_charge_lead', DAY_IN_SECONDS ) );
}

/**
 * When to charge the renewal of a period ending at a given time.
 *
 * @param int $period_end Unix timestamp the paid period ends.
 * @return int Unix timestamp.
 */
function subscription_charge_at( $period_end ) {
	return max( 0, (int) $period_end - subscription_charge_lead() );
}

/**
 * Advance a timestamp by one billing period.
 *
 * Lands on the subscription's billing day — the day of the month it started on
 * — clamped to the last day of the target month when that month is shorter.
 * Started on 31 January: 28 February, then back to 31 March, 30 April,
 * 31 May. Yearly the same for 29 February: 28 February in ordinary years,
 * 29 February again in leap years.
 *
 * The billing day has to be passed in for renewals. Each renewal counts from
 * the end of the previous period, and after a short month that end is already
 * clamped (28 February); taking the day from it would keep the subscription on
 * the 28th for good, a few days short every year.
 *
 * PHP's own `strtotime( '+1 month' )` is not used: from 31 January it gives
 * "31 February", which overflows to 3 March — no charge in February, and the
 * billing day moves to the 3rd from then on.
 *
 * @param string $period     'monthly' or 'yearly'.
 * @param int    $from       Unix timestamp to count from. Defaults to now.
 * @param int    $anchor_day The subscription's billing day, 1–31. 0 takes
 *                           the day of $from — right for a first period.
 * @return int Unix timestamp of the next payment.
 */
function subscription_next_payment( $period, $from = 0, $anchor_day = 0 ) {
	$from       = $from > 0 ? (int) $from : time();
	$anchor_day = (int) $anchor_day;

	$day   = ( $anchor_day >= 1 && $anchor_day <= 31 ) ? $anchor_day : (int) date( 'j', $from );
	$month = (int) date( 'n', $from );
	$year  = (int) date( 'Y', $from );
	$hour  = (int) date( 'G', $from );
	$min   = (int) date( 'i', $from );
	$sec   = (int) date( 's', $from );

	if ( 'yearly' === $period ) {
		++$year;
	} else {
		++$month;

		if ( $month > 12 ) {
			$month = 1;
			++$year;
		}
	}

	// 't' on the first of the target month gives that month's length.
	$days_in_month = (int) date( 't', mktime( 0, 0, 0, $month, 1, $year ) );

	return (int) mktime( $hour, $min, $sec, $month, min( $day, $days_in_month ), $year );
}
