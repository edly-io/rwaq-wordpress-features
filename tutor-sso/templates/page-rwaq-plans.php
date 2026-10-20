<?php
/**
 * "Rwaq Subscription Plans" page template.
 *
 * Loaded for the page using the plans template (see
 * includes/subscriptions/subscriptions-plans.php). Renders the page's own
 * content — so a heading built in Elementor or the block editor shows — and then
 * the plans, unless that content already placed [rwaq_subscription_plans]
 * itself, in which case it is not repeated.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main rwaq-plans-page">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}

	if ( ! \TutorSSO\subscription_plans_rendered() ) {
		echo do_shortcode( '[rwaq_subscription_plans]' );
	}
	?>
</main>
<?php
get_footer();
