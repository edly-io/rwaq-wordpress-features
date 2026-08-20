<?php
/**
 * "Rwaq Ambassadors" page template registration.
 *
 * Adds a selectable page template (Page Attributes → Template → "Rwaq
 * Ambassadors") provided by the plugin — no theme edits needed. The template is
 * a static Arabic (RTL) landing page: hero, the three working groups, the 5-step
 * application timeline, the requirements grid, a photo gallery, and an
 * application form. See templates/page-rwaq-ambassadors.php.
 *
 * Two things are editable per page, via custom fields (ACF or plain post meta):
 *
 *   cf7_form_id             The Contact Form 7 form rendered in the application
 *                           section. Accepts a numeric form ID, a CF7 hash id,
 *                           or a full [contact-form-7 …] shortcode.
 *   ambassadors_gallery_1-4 The four gallery images (attachment ID, ACF image
 *                           array, or URL). Each slot falls back to a
 *                           CDN-hosted default, so the gallery is filled
 *                           without any configuration.
 *
 * The CF7 form is configured in the WordPress admin (Contact > Contact Forms),
 * not here. assets/css/ambassadors.css styles CF7's own markup, and additionally
 * hooks these wrapper classes if the form's Form tab wraps fields in them:
 *
 *   rwaq-amb-row      two-column grid (collapses to one below 767px)
 *   rwaq-amb-field    single full-width field
 *   rwaq-amb-caption  label text for controls that cannot be wrapped in <label>
 *   rwaq-amb-upload   designed CV drop zone, with __copy / __title / __hint / __icon
 *   rwaq-amb-submit   centred submit + trailing hint
 *   rwaq-amb-hint     small muted helper line
 *
 * A form without them still inherits all field, button and message styling; it
 * only loses the grid and the drop zone. Note that a CF7 tag must sit on the
 * SAME LINE as the markup before it, or CF7's autop inserts a <br>.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-template slug stored in the `_wp_page_template` post meta.
 */
const AMBASSADORS_TEMPLATE = 'tutor-sso-rwaq-ambassadors';

/**
 * Add "Rwaq Ambassadors" to the Page Attributes → Template dropdown.
 *
 * @param array $templates Existing page templates (slug => label).
 * @return array
 */
function ambassadors_register_page_template( $templates ) {
	$templates[ AMBASSADORS_TEMPLATE ] = __( 'Rwaq Ambassadors', 'tutor-sso' );
	return $templates;
}
add_filter( 'theme_page_templates', __NAMESPACE__ . '\\ambassadors_register_page_template' );

/**
 * Whether the current main request is a page using the Ambassadors template.
 *
 * @return bool
 */
function ambassadors_is_page_template() {
	return is_singular() && AMBASSADORS_TEMPLATE === get_page_template_slug( get_queried_object_id() );
}

/**
 * Load the plugin's template file when the Ambassadors template is selected.
 *
 * @param string $template Template path resolved by the theme hierarchy.
 * @return string
 */
function ambassadors_load_page_template( $template ) {
	if ( ambassadors_is_page_template() ) {
		$file = TUTOR_SSO_PATH . 'templates/page-rwaq-ambassadors.php';
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return $template;
}
add_filter( 'template_include', __NAMESPACE__ . '\\ambassadors_load_page_template' );

/**
 * Register the page stylesheet.
 *
 * Depends on the IBM Plex Sans Arabic webfont ('tutor-sso-programs-font',
 * registered and enqueued globally in tutor-sso.php) so it always loads first.
 * ambassadors.css uses logical properties throughout and needs no RTL companion
 * stylesheet.
 */
function ambassadors_register_assets() {
	wp_register_style(
		'tutor-sso-ambassadors',
		TUTOR_SSO_URL . 'assets/css/ambassadors.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);

	// Reports the chosen CV file name inside the designed drop zone, which hides
	// the native file input. Vanilla JS, no dependencies, loaded in the footer.
	wp_register_script(
		'tutor-sso-ambassadors',
		TUTOR_SSO_URL . 'assets/js/ambassadors.js',
		array(),
		TUTOR_SSO_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\ambassadors_register_assets' );

/**
 * Enqueue the stylesheet on pages using the Ambassadors template.
 */
function ambassadors_page_template_assets() {
	if ( ambassadors_is_page_template() ) {
		wp_enqueue_style( 'tutor-sso-ambassadors' );
		wp_enqueue_script( 'tutor-sso-ambassadors' );
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\ambassadors_page_template_assets' );

/**
 * Read a custom field: ACF's get_field() first (so return-format handling and
 * field aliases work), then a raw post meta fallback.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @return mixed Field value (string, array or attachment ID), or ''.
 */
function ambassadors_field( $post_id, $field ) {
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $field, $post_id );
		if ( ! empty( $value ) ) {
			return $value;
		}
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * Resolve an image field to a URL. Accepts an ACF image array, an attachment ID
 * or a plain URL.
 *
 * @param mixed  $value Field value.
 * @param string $size  Image size to request for attachment IDs.
 * @return string URL, or '' when unresolvable.
 */
function ambassadors_image_url( $value, $size = 'large' ) {
	if ( empty( $value ) ) {
		return '';
	}

	if ( is_array( $value ) ) {
		if ( ! empty( $value['url'] ) ) {
			return (string) $value['url'];
		}
		$value = isset( $value['ID'] ) ? $value['ID'] : ( isset( $value['id'] ) ? $value['id'] : 0 );
	}

	if ( is_numeric( $value ) ) {
		$url = wp_get_attachment_image_url( (int) $value, $size );
		return $url ? $url : '';
	}

	return (string) $value;
}

/**
 * Default gallery image URL for a slot, or '' when the slot has none.
 *
 * These are the CDN-hosted defaults, so the gallery is populated without any
 * per-page configuration. A `ambassadors_gallery_{n}` custom field still takes
 * precedence, which is the route to use for page-specific photography.
 *
 * The four assets are sized to the grid: slot 1 spans both rows (596x422),
 * slot 2 is the wide cell (596x239), and slots 3-4 share the bottom cell
 * (290x167 each).
 *
 * @param int $slot Slot number (1-4).
 * @return string Absolute URL, or ''.
 */
function ambassadors_gallery_default( $slot ) {
	$urls = array(
		1 => 'https://dsa1pgbddfj1a.cloudfront.net/2026/08/2Ry2akr8-people-large-inst.svg',
		2 => 'https://dsa1pgbddfj1a.cloudfront.net/2026/08/cyEAfJNT-laptop-coffee.svg',
		3 => 'https://dsa1pgbddfj1a.cloudfront.net/2026/08/izWphsbx-laptop-working.svg',
		4 => 'https://dsa1pgbddfj1a.cloudfront.net/2026/08/2EhyPO1r-laptop.svg',
	);

	return isset( $urls[ $slot ] ) ? $urls[ $slot ] : '';
}

/**
 * Render one gallery slot: the image when its field is set, otherwise a dashed
 * placeholder carrying the slot's hint text.
 *
 * @param int    $post_id     Post ID.
 * @param int    $slot        Slot number (1-4) → `ambassadors_gallery_{n}`.
 * @param string $modifier    BEM modifier suffix for the tile (e.g. '1').
 * @param string $placeholder Hint shown when the slot is empty.
 * @return string HTML.
 */
function ambassadors_gallery_slot( $post_id, $slot, $modifier, $placeholder ) {
	$raw = ambassadors_field( $post_id, 'ambassadors_gallery_' . $slot );
	$url = ambassadors_image_url( $raw );
	$alt = '';

	// Prefer the attachment's own alt text when the field stores an ID/array.
	if ( is_array( $raw ) && ! empty( $raw['alt'] ) ) {
		$alt = (string) $raw['alt'];
	} elseif ( is_numeric( $raw ) ) {
		$alt = (string) get_post_meta( (int) $raw, '_wp_attachment_image_alt', true );
	}

	// Fall back to the CDN-hosted default so the gallery is filled out of the
	// box; a per-slot custom field still wins when one is set. These are
	// decorative brand images, hence the empty alt.
	if ( '' === $url ) {
		$default = ambassadors_gallery_default( $slot );
		if ( '' !== $default ) {
			$url = $default;
			$alt = '';
		}
	}

	$class = 'rwaq-amb__shot rwaq-amb__shot--' . $modifier;

	if ( '' === $url ) {
		return '<div class="' . esc_attr( $class ) . ' rwaq-amb__shot--empty">'
			. '<span class="rwaq-amb__shot-placeholder">' . esc_html( $placeholder ) . '</span>'
			. '</div>';
	}

	return '<div class="' . esc_attr( $class ) . '">'
		. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async">'
		. '</div>';
}

/**
 * Render the application form for a page.
 *
 * The `cf7_form_id` custom field may hold a numeric CF7 form ID, a CF7 hash id,
 * or a complete [contact-form-7 …] shortcode (pasted straight from the CF7 admin
 * screen) — all three are accepted. When the field is empty, editors see an
 * inline hint and visitors see nothing.
 *
 * @param int $post_id Post ID.
 * @return string HTML.
 */
function ambassadors_form_html( $post_id ) {
	$value    = trim( (string) ambassadors_field( $post_id, 'cf7_form_id' ) );
	$can_edit = current_user_can( 'edit_post', $post_id );

	// Without CF7 active the shortcode would print as literal text.
	if ( ! shortcode_exists( 'contact-form-7' ) ) {
		if ( $can_edit ) {
			return '<p class="rwaq-amb__form-missing">'
				. esc_html__( 'Contact Form 7 is not active, so the application form cannot be rendered.', 'tutor-sso' )
				. '</p>';
		}
		return '';
	}

	if ( '' === $value ) {
		if ( $can_edit ) {
			return '<p class="rwaq-amb__form-missing">'
				. esc_html__( 'Add a Contact Form 7 form ID to this page\'s "cf7_form_id" custom field to show the application form here.', 'tutor-sso' )
				. '</p>';
		}
		return '';
	}

	// A full shortcode was pasted in — run it as-is.
	if ( false !== strpos( $value, '[' ) ) {
		return do_shortcode( $value );
	}

	return do_shortcode( sprintf( '[contact-form-7 id="%s"]', esc_attr( $value ) ) );
}

/**
 * Return an inline SVG icon used by the Ambassadors page, by name.
 *
 * The markup is static and trusted (no user input), so callers echo the result
 * directly without escaping.
 *
 * @param string $name Icon key.
 * @return string SVG markup, or '' for an unknown name.
 */
function ambassadors_icon( $name ) {
	$icons = array(
		// Hero highlights.
		'certificate' => '<svg width="30" height="30" viewBox="0 0 30 30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 3.75V8.75C17.5 9.08152 17.6317 9.39946 17.8661 9.63388C18.1005 9.8683 18.4185 10 18.75 10H23.75"/><path d="M6.25 10V6.25C6.25 5.58696 6.51339 4.95107 6.98223 4.48223C7.45107 4.01339 8.08696 3.75 8.75 3.75H17.5L23.75 10V23.75C23.75 24.413 23.4866 25.0489 23.0178 25.5178C22.5489 25.9866 21.913 26.25 21.25 26.25H15"/><path d="M3.75 17.5C3.75 18.4946 4.14509 19.4484 4.84835 20.1517C5.55161 20.8549 6.50544 21.25 7.5 21.25C8.49456 21.25 9.44839 20.8549 10.1517 20.1517C10.8549 19.4484 11.25 18.4946 11.25 17.5C11.25 16.5054 10.8549 15.5516 10.1517 14.8483C9.44839 14.1451 8.49456 13.75 7.5 13.75C6.50544 13.75 5.55161 14.1451 4.84835 14.8483C4.14509 15.5516 3.75 16.5054 3.75 17.5Z"/><path d="M5.625 21.25L3.75 27.5L7.5 25.625L11.25 27.5L9.375 21.25"/></svg>',
		'globe'       => '<svg width="30" height="30" viewBox="0 0 30 30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.75 15C3.75 16.4774 4.04099 17.9403 4.60636 19.3052C5.17172 20.6701 6.00039 21.9103 7.04505 22.955C8.08971 23.9996 9.3299 24.8283 10.6948 25.3936C12.0597 25.959 13.5226 26.25 15 26.25C16.4774 26.25 17.9403 25.959 19.3052 25.3936C20.6701 24.8283 21.9103 23.9996 22.955 22.955C23.9996 21.9103 24.8283 20.6701 25.3936 19.3052C25.959 17.9403 26.25 16.4774 26.25 15C26.25 12.0163 25.0647 9.15483 22.955 7.04505C20.8452 4.93526 17.9837 3.75 15 3.75C12.0163 3.75 9.15483 4.93526 7.04505 7.04505C4.93526 9.15483 3.75 12.0163 3.75 15Z"/><path d="M4.5 11.25H25.5"/><path d="M4.5 18.75H25.5"/><path d="M14.375 3.75C12.2692 7.12451 11.1528 11.0223 11.1528 15C11.1528 18.9777 12.2692 22.8755 14.375 26.25"/><path d="M15.625 3.75C17.7308 7.12451 18.8472 11.0223 18.8472 15C18.8472 18.9777 17.7308 22.8755 15.625 26.25"/></svg>',
		'clock'       => '<svg width="30" height="30" viewBox="0 0 30 30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.60636 19.3052C4.04099 17.9403 3.75 16.4774 3.75 15C3.75 13.5226 4.04099 12.0597 4.60636 10.6948C5.17172 9.3299 6.00039 8.08971 7.04505 7.04505C8.08971 6.00039 9.3299 5.17172 10.6948 4.60636C12.0597 4.04099 13.5226 3.75 15 3.75C16.4774 3.75 17.9403 4.04099 19.3052 4.60636C20.6701 5.17172 21.9103 6.00039 22.955 7.04505C23.9996 8.08971 24.8283 9.3299 25.3936 10.6948C25.959 12.0597 26.25 13.5226 26.25 15C26.25 16.4774 25.959 17.9403 25.3936 19.3052C24.8283 20.6701 23.9996 21.9103 22.955 22.955C21.9103 23.9996 20.6701 24.8283 19.3052 25.3936C17.9403 25.959 16.4774 26.25 15 26.25C13.5226 26.25 12.0597 25.959 10.6948 25.3936C9.3299 24.8283 8.08971 23.9996 7.04505 22.955C6.00039 21.9103 5.17172 20.6701 4.60636 19.3052Z"/><path d="M15 15L18.75 17.5"/><path d="M15 8.75V15"/></svg>',

		// Working groups.
		'building'    => '<svg width="24" height="24" viewBox="12 12 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 33H33"/><path d="M17 33V19L25 15V33"/><path d="M31 33V23L25 19"/><path d="M21 21V21.01"/><path d="M21 24V24.01"/><path d="M21 27V27.01"/><path d="M21 30V30.01"/></svg>',
		'campus'      => '<svg width="24" height="24" viewBox="12 12 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 33H33"/><path d="M15 22H33"/><path d="M17 18L24 15L31 18"/><path d="M16 22V33"/><path d="M32 22V33"/><path d="M20 26V29"/><path d="M24 26V29"/><path d="M28 26V29"/></svg>',
		'graduation'  => '<svg width="24" height="24" viewBox="12 12 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M34 21L24 17L14 21L24 25L34 21ZM34 21V27"/><path d="M18 22.6V28C18 28.7956 18.6321 29.5587 19.7574 30.1213C20.8826 30.6839 22.4087 31 24 31C25.5913 31 27.1174 30.6839 28.2426 30.1213C29.3679 29.5587 30 28.7956 30 28V22.6"/></svg>',

		// Requirements.
		'chat'        => '<svg width="24" height="24" viewBox="12 12 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21H28"/><path d="M20 25H26"/><path d="M30 16C30.7956 16 31.5587 16.3161 32.1213 16.8787C32.6839 17.4413 33 18.2044 33 19V27C33 27.7956 32.6839 28.5587 32.1213 29.1213C31.5587 29.6839 30.7956 30 30 30H25L20 33V30H18C17.2044 30 16.4413 29.6839 15.8787 29.1213C15.3161 28.5587 15 27.7956 15 27V19C15 18.2044 15.3161 17.4413 15.8787 16.8787C16.4413 16.3161 17.2044 16 18 16H30Z"/></svg>',
		'alarm'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9.5V13l2.5 1.8M5 4l2.5 2M19 4l-2.5 2"/></svg>',
		'book'        => '<svg width="24" height="24" viewBox="12 12 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M31 16V32H19C18.4696 32 17.9609 31.7893 17.5858 31.4142C17.2107 31.0391 17 30.5304 17 30V18C17 17.4696 17.2107 16.9609 17.5858 16.5858C17.9609 16.2107 18.4696 16 19 16H31Z"/><path d="M31 28H19C18.4696 28 17.9609 28.2107 17.5858 28.5858C17.2107 28.9609 17 29.4696 17 30"/><path d="M21 20H27"/></svg>',

		// Gallery footer.
		'instagram'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/></svg>',
	);

	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}
