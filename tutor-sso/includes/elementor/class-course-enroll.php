<?php
/**
 * Elementor widget for the Tutor SSO enroll / unenroll / go-to-course button.
 *
 * This file is only ever required from inside the `elementor/widgets/register`
 * hook (see elementor-widget-loader.php), so \Elementor\* classes are
 * guaranteed to exist by the time this class is declared.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the enroll button as an Elementor widget. Reuses the exact same
 * markup/behaviour as the [tutor_enroll_button] shortcode.
 */
class Course_Enroll_Widget extends \Elementor\Widget_Base {

	/**
	 * Unique widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'tutor_sso_enroll';
	}

	/**
	 * Human-readable widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Enroll Course', 'tutor-sso' );
	}

	/**
	 * Widget icon (Elementor icon font class).
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-button';
	}

	/**
	 * Editor panel categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords in the editor.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'enroll', 'unenroll', 'course', 'tutor', 'sso', 'openedx', 'edx' );
	}

	/**
	 * Make sure the front-end enroll assets load with the widget.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'tutor-sso-enroll' );
	}

	/**
	 * Styles the widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'tutor-sso-enroll' );
	}

	/**
	 * Register the widget's editor controls.
	 */
	protected function register_controls() {

		$this->start_controls_section(
			'tutor_sso_section_content',
			array(
				'label' => __( 'Enrollment', 'tutor-sso' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'course_id',
			array(
				'label'       => __( 'Course ID', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => 'course-v1:Org+Course+Run',
				'description' => __( 'Leave blank to use the ACF field "openedx_course_id" from the current post.', 'tutor-sso' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'enroll_label',
			array(
				'label'       => __( 'Enroll Label', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Enroll', 'tutor-sso' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'goto_label',
			array(
				'label'       => __( 'Go to Course Label', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Go to Course', 'tutor-sso' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'unenroll_label',
			array(
				'label'       => __( 'Unenroll Label', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Unenroll', 'tutor-sso' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'login_label',
			array(
				'label'       => __( 'Log In Label', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Log in to Enroll', 'tutor-sso' ),
				'description' => __( 'Shown to logged-out visitors; clicking starts the SSO login.', 'tutor-sso' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'messages_heading',
			array(
				'label'     => __( 'Result Messages', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'enroll_message',
			array(
				'label'       => __( 'Enroll Success Message', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'placeholder' => __( 'You have been enrolled successfully.', 'tutor-sso' ),
				'description' => __( 'Shown after a successful enroll. Leave blank to use the default.', 'tutor-sso' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'unenroll_message',
			array(
				'label'       => __( 'Unenroll Success Message', 'tutor-sso' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'placeholder' => __( 'You have been unenrolled from this course.', 'tutor-sso' ),
				'description' => __( 'Shown after a successful unenroll. Leave blank to use the default.', 'tutor-sso' ),
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$this->add_control(
			'show_unenroll',
			array(
				'label'        => __( 'Show Unenroll Button', 'tutor-sso' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tutor-sso' ),
				'label_off'    => __( 'No', 'tutor-sso' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_goto',
			array(
				'label'        => __( 'Show "Go to Course" Button', 'tutor-sso' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'tutor-sso' ),
				'label_off'    => __( 'No', 'tutor-sso' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Register the Style-tab controls: shared typography/sizing plus per-state
	 * colour controls for the Enroll, Unenroll and Go-to-Course buttons.
	 */
	protected function register_style_controls() {

		// ── Shared button box: typography, padding, radius, alignment ────────
		$this->start_controls_section(
			'tutor_sso_section_style_button',
			array(
				'label' => __( 'Button', 'tutor-sso' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'button_align',
			array(
				'label'     => __( 'Alignment', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'tutor-sso' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'tutor-sso' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'tutor-sso' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .tutor-sso-enroll-wrap' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .tutor-sso-enroll-btn',
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'tutor-sso' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tutor-sso-enroll-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_radius',
			array(
				'label'      => __( 'Border Radius', 'tutor-sso' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .tutor-sso-enroll-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ── Per-state colours + border (each button has its own defaults) ─────
		$this->add_button_color_section(
			'tutor_sso_section_style_enroll',
			__( 'Enroll Button', 'tutor-sso' ),
			'.tutor-sso-enroll-btn--enroll',
			'enroll_btn',
			array(
				'text'       => '#ffffff',
				'bg'         => '#565199',
				'text_hover' => '#ffffff',
				'bg_hover'   => '#474293',
			)
		);

		$this->add_button_color_section(
			'tutor_sso_section_style_goto',
			__( 'Go to Course Button', 'tutor-sso' ),
			'.tutor-sso-enroll-btn--goto',
			'goto_btn',
			array(
				'text'       => '#ffffff',
				'bg'         => '#565199',
				'text_hover' => '#ffffff',
				'bg_hover'   => '#474293',
			)
		);

		$this->add_button_color_section(
			'tutor_sso_section_style_unenroll',
			__( 'Unenroll Button', 'tutor-sso' ),
			'.tutor-sso-enroll-btn--unenroll',
			'unenroll_btn',
			array(
				'text'       => '#242424',
				'bg'         => '#ffffff',
				'text_hover' => '#242424',
				'bg_hover'   => '#f5f5f5',
			),
			array(
				'style' => 'solid',
				'width' => '1',
				'color' => '#D1D1D1',
			)
		);
	}

	/**
	 * Add a Style-tab section with Normal/Hover tabs for one button variant.
	 *
	 * @param string $section_id Unique section id.
	 * @param string $label      Section label.
	 * @param string $class      CSS class of the button variant (with leading dot).
	 * @param string $prefix     Unique control-id prefix.
	 * @param array  $defaults   Default colours: text, bg, text_hover, bg_hover.
	 * @param array  $border     Default border: style, width, color (optional).
	 */
	protected function add_button_color_section( $section_id, $label, $class, $prefix, $defaults = array(), $border = array() ) {

		$selector = '{{WRAPPER}} ' . $class;

		$defaults = wp_parse_args(
			$defaults,
			array(
				'text'       => '',
				'bg'         => '',
				'text_hover' => '',
				'bg_hover'   => '',
			)
		);

		$this->start_controls_section(
			$section_id,
			array(
				'label' => $label,
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( $prefix . '_tabs' );

		// Normal.
		$this->start_controls_tab(
			$prefix . '_tab_normal',
			array( 'label' => __( 'Normal', 'tutor-sso' ) )
		);

		$this->add_control(
			$prefix . '_color',
			array(
				'label'     => __( 'Text Color', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => $defaults['text'],
				'selectors' => array( $selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			$prefix . '_bg',
			array(
				'label'     => __( 'Background Color', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => $defaults['bg'],
				'selectors' => array( $selector => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		// Hover.
		$this->start_controls_tab(
			$prefix . '_tab_hover',
			array( 'label' => __( 'Hover', 'tutor-sso' ) )
		);

		$this->add_control(
			$prefix . '_color_hover',
			array(
				'label'     => __( 'Text Color', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => $defaults['text_hover'],
				'selectors' => array( $selector . ':hover' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			$prefix . '_bg_hover',
			array(
				'label'     => __( 'Background Color', 'tutor-sso' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => $defaults['bg_hover'],
				'selectors' => array( $selector . ':hover' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		// Border field for this button (state-independent).
		$border_args = array(
			'name'      => $prefix . '_border',
			'selector'  => $selector,
			'separator' => 'before',
		);

		if ( ! empty( $border ) ) {
			$border = wp_parse_args(
				$border,
				array(
					'style' => 'solid',
					'width' => '1',
					'color' => '#D1D1D1',
				)
			);

			$border_args['fields_options'] = array(
				'border' => array( 'default' => $border['style'] ),
				'width'  => array(
					'default' => array(
						'top'      => $border['width'],
						'right'    => $border['width'],
						'bottom'   => $border['width'],
						'left'     => $border['width'],
						'unit'     => 'px',
						'isLinked' => true,
					),
				),
				'color'  => array( 'default' => $border['color'] ),
			);
		}

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), $border_args );

		$this->end_controls_section();
	}

	/**
	 * Front-end + editor render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$course_id = \TutorSSO\resolve_course_id( isset( $settings['course_id'] ) ? $settings['course_id'] : '' );

		if ( '' === $course_id ) {
			// Help the editor; output nothing on the front end.
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="tutor-sso-enroll-notice">'
					. esc_html__( 'Set a Course ID, or add an "openedx_course_id" ACF value to this post.', 'tutor-sso' )
					. '</div>';
			}
			return;
		}

		$args = array(
			'show_unenroll' => ( ! isset( $settings['show_unenroll'] ) || 'yes' === $settings['show_unenroll'] ),
			'show_goto'     => ( ! isset( $settings['show_goto'] ) || 'yes' === $settings['show_goto'] ),
		);

		foreach ( array( 'enroll_label', 'unenroll_label', 'goto_label', 'login_label', 'enroll_message', 'unenroll_message' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		// render_enroll_button() escapes all of its own output.
		echo \TutorSSO\render_enroll_button( $course_id, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
