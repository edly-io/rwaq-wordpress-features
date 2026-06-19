<?php
/**
 * Elementor integration loader.
 *
 * Registers the "Enroll Course" widget only when Elementor is active. The class
 * is required lazily inside the register hook so that extending
 * \Elementor\Widget_Base never fatals on sites without Elementor installed.
 *
 * Requires Elementor 3.5+ (the `elementor/widgets/register` hook).
 *
 * @package tutor-sso
 */

namespace TutorSSO\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the "Enroll Course" widget with Elementor.
 *
 * @param mixed $widgets_manager Elementor widgets manager.
 */
function register_elementor_widget( $widgets_manager ) {
	require_once TUTOR_SSO_PATH . 'includes/elementor/class-course-enroll.php';

	$widget = new Course_Enroll_Widget();

	if ( method_exists( $widgets_manager, 'register' ) ) {
		$widgets_manager->register( $widget );
	} elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
		// Back-compat for older Elementor versions.
		$widgets_manager->register_widget_type( $widget );
	}
}

/**
 * Attach the widget registration hook. Only ever called once Elementor's core
 * has loaded, so the integration is a complete no-op when Elementor is not
 * installed or is deactivated.
 */
function bootstrap_elementor() {
	add_action( 'elementor/widgets/register', __NAMESPACE__ . '\\register_elementor_widget' );
}

// Gate everything behind `elementor/loaded`. If Elementor already loaded before
// this plugin (later load order), wire up immediately; otherwise wait for it.
// When Elementor is disabled, `elementor/loaded` never fires and nothing runs.
if ( did_action( 'elementor/loaded' ) ) {
	bootstrap_elementor();
} else {
	add_action( 'elementor/loaded', __NAMESPACE__ . '\\bootstrap_elementor' );
}
