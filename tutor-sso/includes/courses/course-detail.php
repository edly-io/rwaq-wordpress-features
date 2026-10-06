<?php
/**
 * Course detail: single-course template loader + detail render / shortcode.
 * Usage:
 *   [rwaq_course_detail]            render the current course in the loop
 *   [rwaq_course_detail id="123"]   render an explicit course post by ID
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field holding the edX course key on the course post. The same field
 * [tutor_enroll_button] reads, so the enroll card needs no second source.
 */
const COURSE_KEY_FIELD = 'openedx_course_id';

/**
 * Use the plugin's single-course.php for single course posts, unless the theme
 * provides its own.
 *
 * @param string $template Path the template hierarchy resolved.
 * @return string
 */
function course_single_template( $template ) {
	if ( ! is_singular( courses_archive_post_type() ) ) {
		return $template;
	}

	$theme_template = locate_template( array( 'single-course.php' ) );
	if ( $theme_template ) {
		return $theme_template;
	}

	$plugin_template = TUTOR_SSO_PATH . 'templates/single-course.php';

	return file_exists( $plugin_template ) ? $plugin_template : $template;
}
add_filter( 'single_template', __NAMESPACE__ . '\\course_single_template' );

/**
 * Register the (lazily enqueued) course detail stylesheet.
 */
function course_detail_register_assets() {
	wp_register_style(
		'tutor-sso-price-panel',
		TUTOR_SSO_URL . 'assets/css/price-panel.css',
		array(),
		TUTOR_SSO_VERSION
	);

	wp_register_style(
		'tutor-sso-course-detail',
		TUTOR_SSO_URL . 'assets/css/course-detail.css',
		array( 'tutor-sso-programs-font' ),
		TUTOR_SSO_VERSION
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\course_detail_register_assets' );

/**
 * Enqueue the detail assets. Called lazily by the shortcode.
 */
function course_detail_enqueue_assets() {
	wp_enqueue_style( 'tutor-sso-course-detail' );
	wp_enqueue_style( 'tutor-sso-price-panel' );

	// Related courses use the catalog's own card component.
	wp_enqueue_style( 'tutor-sso-courses' );
}

/**
 * URL of a bundled course-detail asset.
 *
 * @param string $file File name inside assets/images/course/.
 * @return string
 */
function course_asset( $file ) {
	return TUTOR_SSO_URL . 'assets/images/course/' . $file;
}

/**
 * Read a custom field: ACF's get_field() first, then raw post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field name.
 * @return mixed
 */
function course_field( $post_id, $field ) {
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $field, $post_id );
		if ( ! empty( $value ) ) {
			return $value;
		}
	}

	return get_post_meta( $post_id, $field, true );
}

/**
 * Build the view model for one course post, falling back to the WordPress post
 * for the fields it can supply itself so a failed request still renders.
 *
 * @param int $post_id Course post ID.
 * @return array View model, plus `course_key`.
 */
function course_detail_data( $post_id ) {
	$course_key = trim( (string) course_field( $post_id, COURSE_KEY_FIELD ) );

	$data = course_detail_data_remote( $course_key );

	if ( '' === $data['title'] ) {
		$data['title'] = (string) get_the_title( $post_id );
	}

	if ( '' === $data['image'] ) {
		$data['image'] = (string) get_the_post_thumbnail_url( $post_id, 'large' );
	}

	$data['course_key'] = $course_key;
	$data['post_id']    = (int) $post_id;

	/**
	 * Filter the course detail view model, after the API response has been
	 * mapped and the WordPress fallbacks applied.
	 *
	 * @param array  $data       View model.
	 * @param int    $post_id    Course post ID.
	 * @param string $course_key edX course key.
	 */
	return (array) apply_filters( 'tutor_sso_course_view_model', $data, $post_id, $course_key );
}

/**
 * Render the breadcrumb: the courses archive link, then the current title.
 *
 * @param string $title Course title.
 * @return string HTML.
 */
function course_render_breadcrumb( $title ) {
	$archive = get_post_type_archive_link( courses_archive_post_type() );

	$chevron = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 3L4.5 6L7.5 9" stroke="#616161" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	ob_start();
	?>
	<nav class="rwaq-cd__breadcrumb" aria-label="<?php echo esc_attr__( 'مسار التنقل', 'tutor-sso' ); ?>">
		<?php if ( $archive ) : ?>
			<a class="rwaq-cd__crumb" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html__( 'الدورات', 'tutor-sso' ); ?></a>
		<?php else : ?>
			<span class="rwaq-cd__crumb"><?php echo esc_html__( 'الدورات', 'tutor-sso' ); ?></span>
		<?php endif; ?>

		<?php if ( '' !== $title ) : ?>
			<span class="rwaq-cd__crumb-sep" aria-hidden="true"><?php echo $chevron; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="rwaq-cd__crumb rwaq-cd__crumb--current" aria-current="page"><?php echo esc_html( $title ); ?></span>
		<?php endif; ?>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Render the intro video. Returns '' when the API carries no YouTube link, so
 * the main column simply starts at the category pills.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_video( $data ) {
	$video = isset( $data['video'] ) ? (string) $data['video'] : '';

	if ( '' === $video ) {
		return '';
	}

	ob_start();
	?>
	<div class="rwaq-cd__video">
		<iframe
			src="<?php echo esc_url( $video ); ?>"
			title="<?php echo esc_attr( isset( $data['title'] ) ? (string) $data['title'] : '' ); ?>"
			loading="lazy"
			allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
			referrerpolicy="strict-origin-when-cross-origin"
			allowfullscreen
		></iframe>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the header block: category pills, title, description, the organization
 * (label + name + logo), then the meta chips card.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_header( $data ) {
	$title       = isset( $data['title'] ) ? (string) $data['title'] : '';
	$description = isset( $data['description'] ) ? (string) $data['description'] : '';
	$org_name    = isset( $data['org_name'] ) ? (string) $data['org_name'] : '';
	$org_logo    = isset( $data['org_logo'] ) ? (string) $data['org_logo'] : '';
	$org_url     = isset( $data['org_url'] ) ? (string) $data['org_url'] : '';
	$categories  = isset( $data['categories'] ) ? (array) $data['categories'] : array();
	$enrolled    = isset( $data['enrolled'] ) ? (int) $data['enrolled'] : 0;
	$certificate = ! empty( $data['certificate'] );

	ob_start();
	?>
	<div class="rwaq-cd__header">
		<?php if ( ! empty( $categories ) ) : ?>
			<ul class="rwaq-cd__pills">
				<?php foreach ( $categories as $category ) : ?>
					<li class="rwaq-cd__pill"><?php echo esc_html( $category ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<div class="rwaq-cd__intro">
			<?php if ( '' !== $title ) : ?>
				<h1 class="rwaq-cd__title"><?php echo esc_html( $title ); ?></h1>
			<?php endif; ?>
			<?php if ( '' !== $description ) : ?>
				<p class="rwaq-cd__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( '' !== $org_name || '' !== $org_logo ) : ?>
			<div class="rwaq-cd__org">
				<?php // RTL: first child sits at the right edge, where the design puts the logo. ?>
				<?php if ( '' !== $org_logo ) : ?>
					<?php // The logo repeats the name's link, so it stays out of the tab order. ?>
					<<?php echo '' !== $org_url ? 'a' : 'div'; ?> class="rwaq-cd__org-logo"<?php echo '' !== $org_url ? ' href="' . esc_url( $org_url ) . '" tabindex="-1" aria-hidden="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<img src="<?php echo esc_url( $org_logo ); ?>" alt="<?php echo esc_attr( $org_name ); ?>" loading="lazy" decoding="async" />
					</<?php echo '' !== $org_url ? 'a' : 'div'; ?>>
				<?php endif; ?>
				<?php if ( '' !== $org_name ) : ?>
					<div class="rwaq-cd__org-text">
						<span class="rwaq-cd__org-label"><?php echo esc_html__( 'مُقدَّم من', 'tutor-sso' ); ?></span>
						<?php if ( '' !== $org_url ) : ?>
							<a class="rwaq-cd__org-name" href="<?php echo esc_url( $org_url ); ?>"><?php echo esc_html( $org_name ); ?></a>
						<?php else : ?>
							<span class="rwaq-cd__org-name"><?php echo esc_html( $org_name ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="rwaq-cd__chips">
			<span class="rwaq-cd__chip">
				<img src="<?php echo esc_url( course_asset( 'ic-learners.svg' ) ); ?>" width="20" height="20" alt="" aria-hidden="true" />
				<span class="rwaq-cd__chip-value"><?php echo esc_html( number_format_i18n( $enrolled ) ); ?></span>
				<span class="rwaq-cd__chip-label"><?php echo esc_html__( 'المتعلّمون', 'tutor-sso' ); ?></span>
			</span>

			<?php if ( $certificate ) : ?>
				<span class="rwaq-cd__chip">
					<img src="<?php echo esc_url( course_asset( 'ic-certificate.svg' ) ); ?>" width="20" height="20" alt="" aria-hidden="true" />
					<span class="rwaq-cd__chip-label"><?php echo esc_html__( 'شهادة متضمنة', 'tutor-sso' ); ?></span>
				</span>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the overview section (الوصف). The API returns HTML.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_overview( $data ) {
	$overview = isset( $data['overview'] ) ? (string) $data['overview'] : '';

	if ( '' === trim( wp_strip_all_tags( $overview ) ) ) {
		return '';
	}

	ob_start();
	?>
	<section class="rwaq-cd__section">
		<h2 class="rwaq-cd__section-title"><?php echo esc_html__( 'الوصف', 'tutor-sso' ); ?></h2>
		<div class="rwaq-cd__prose"><?php echo wp_kses_post( $overview ); ?></div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Render the related courses section, using the catalog's card component.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_related( $data ) {
	$related = isset( $data['related'] ) ? (array) $data['related'] : array();

	if ( empty( $related ) ) {
		return '';
	}

	ob_start();
	?>
	<section class="rwaq-cd__section rwaq-cd__section--related">
		<h2 class="rwaq-cd__section-title"><?php echo esc_html__( 'مساقات ذات صلة', 'tutor-sso' ); ?></h2>
		<div class="rwaq-cd__related">
			<?php echo courses_render_cards( $related ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</section>
	<?php
	return ob_get_clean();
}

/**
 * Render the price panel between the cover and the call to action.
 *
 * Paid shows the amount charged, the struck-through regular price and a discount
 * badge; free shows مجاني. Amounts are the LMS's, formatting is WooCommerce's.
 *
 * @param bool   $paid     Whether the LMS sells this course.
 * @param string $regular  Regular price.
 * @param string $sale     Sale price, or '' when not on sale.
 * @param string $discount Discount percentage from the LMS, e.g. "25.13".
 * @return string HTML.
 */
function course_render_price_panel( $paid, $regular, $sale, $discount = '' ) {
	$regular = sso_price_to_float( $regular );
	$sale    = sso_price_to_float( $sale );

	// Paid but priced at nothing: no panel rather than a misleading "free".
	if ( $paid && $regular <= 0 ) {
		return '';
	}

	$on_sale = ( $sale > 0 && $sale < $regular );
	$now     = $on_sale ? $sale : $regular;

	// The LMS sends fractions ("25.13"); the badge shows whole percent. Falls
	// back to working it out when the API sends nothing.
	if ( '' !== trim( (string) $discount ) ) {
		$discount = (int) round( (float) $discount );
	} else {
		$discount = $on_sale ? (int) round( ( ( $regular - $sale ) / $regular ) * 100 ) : 0;
	}

	$discount = $on_sale ? max( 0, $discount ) : 0;

	$money = static function ( $amount ) {
		return function_exists( 'wc_price' ) ? wc_price( $amount ) : esc_html( number_format_i18n( $amount, 2 ) );
	};

	ob_start();
	?>
	<div class="rwaq-cd__price">
		<span class="rwaq-cd__price-label"><?php echo esc_html__( 'السعر', 'tutor-sso' ); ?></span>

		<div class="rwaq-cd__price-row">
			<?php if ( ! $paid ) : ?>
				<span class="rwaq-cd__price-free"><?php echo esc_html__( 'مجاني', 'tutor-sso' ); ?></span>
			<?php else : ?>
				<?php // RTL: the amounts lead, so the badge sits at the far left. ?>
				<span class="rwaq-cd__price-amounts">
					<span class="rwaq-cd__price-now"><?php echo wp_kses_post( $money( $now ) ); ?></span>
					<?php if ( $on_sale ) : ?>
						<span class="rwaq-cd__price-was"><?php echo wp_kses_post( $money( $regular ) ); ?></span>
					<?php endif; ?>
				</span>

				<?php if ( $discount > 0 ) : ?>
					<span class="rwaq-cd__price-badge">
						<?php
						/* translators: %s: discount percentage. */
						echo esc_html( sprintf( __( '%s%% خصم', 'tutor-sso' ), number_format_i18n( $discount ) ) );
						?>
					</span>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the enroll card: the course image, then the call to action.
 *
 * Free courses get [tutor_enroll_button] as before (login / enroll / unenroll
 * are the shortcode's own). A paid course is not enrollable for free, so the
 * enroll button is replaced by a buy button that adds the course's WooCommerce
 * product to the cart — see courses-product-sync.php, which mirrors paid courses
 * into products and carries the Open edX metadata through the order.
 *
 * Buying requires an account — the enrollment the order triggers belongs to a
 * person — so a guest on a paid course gets a "log in to buy" button instead.
 *
 * Once the buyer is enrolled the enroll button comes back, minus its unenroll
 * action: cancelling a purchased enrollment from a button is not something the
 * page should offer.
 *
 * A paid course with no buyable product (WooCommerce inactive, or the product
 * drafted because its price or course key is missing) renders no call to action
 * at all — showing the free enroll button there would give the course away.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_enroll_card( $data ) {
	$image      = isset( $data['image'] ) ? (string) $data['image'] : '';
	$course_key = isset( $data['course_key'] ) ? (string) $data['course_key'] : '';
	$post_id    = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;

	if ( '' === $image && '' === $course_key ) {
		return '';
	}

	ob_start();
	?>
	<div class="rwaq-cd__card rwaq-cd__enroll">
		<?php if ( '' !== $image ) : ?>
			<div class="rwaq-cd__enroll-media">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( isset( $data['title'] ) ? (string) $data['title'] : '' ); ?>" decoding="async" />
			</div>
		<?php endif; ?>

		<?php
		$part_of_program = ! empty( $data['part_of_program'] );

		// A course that belongs to a program is never sold on its own, so its
		// price has nothing to attach to — the panel would just be misleading.
		if ( ! $part_of_program ) {
			echo course_render_price_panel( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				! empty( $data['paid'] ),
				isset( $data['regular'] ) ? $data['regular'] : '',
				isset( $data['sale'] ) ? $data['sale'] : '',
				isset( $data['discount'] ) ? $data['discount'] : ''
			);
		}

		if ( '' !== $course_key ) {
			echo course_render_call_to_action( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$post_id,
				$course_key,
				! empty( $data['paid'] ),
				array(
					'regular' => isset( $data['regular'] ) ? $data['regular'] : '',
					'sale'    => isset( $data['sale'] ) ? $data['sale'] : '',
				),
				$part_of_program,
				isset( $data['program_url'] ) ? (string) $data['program_url'] : '',
				! empty( $data['enrollment_closed'] )
			);
		}
		?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the call to action: a free enroll button, a paid buy button, or —
 * when the course belongs to a program — a notice pointing at the program
 * instead, since a program-only course is never sold on its own.
 *
 * Order of checks, each outranking the ones below it:
 *
 *   part_of_program + enrolled      go to course, no unenroll (see below)
 *   part_of_program + not enrolled  notice + go to program; no enroll/buy UI
 *   enrolled                        go to course, no checkout
 *   has_order                       enroll, never charge twice
 *   enrollment_closed               notice, no enroll/buy UI
 *   paid                            buy
 *   otherwise                       free course — enroll
 *
 * A program-course buyer never sees an unenroll button: the enrollment belongs
 * to the program purchase, not to a standalone action this page can undo.
 *
 * enrollment_closed (the LMS's `is_enrollment_full` / `is_enrollment_closed`,
 * folded into one flag in course_detail_data_remote()) only blocks a *new*
 * enrollment or purchase — it never revokes access someone already has. For a
 * paid course that is already checked via course_detail_status() either way;
 * for a free one, a status check is normally skipped entirely (to save the API
 * call on the common, non-closed case), so the free branch below makes that
 * one call only when enrollment_closed is actually true.
 *
 * @param int    $post_id           Course post ID (0 when unknown).
 * @param string $course_key        edX course key.
 * @param bool   $paid              Whether the LMS sells this course on its own.
 * @param array  $price             { regular, sale } from the LMS.
 * @param bool   $part_of_program   Whether the course belongs to a program.
 * @param string $program_url       The program's detail page, when it does.
 * @param bool   $enrollment_closed Whether the LMS has closed new enrollment.
 * @return string HTML.
 */
function course_render_call_to_action( $post_id, $course_key, $paid = false, $price = array(), $part_of_program = false, $program_url = '', $enrollment_closed = false ) {
	$labels = array(
		'enroll_label'   => __( 'سجّل الآن', 'tutor-sso' ),
		'login_label'    => __( 'سجّل الآن', 'tutor-sso' ),
		'goto_label'     => __( 'اذهب إلى المساق', 'tutor-sso' ),
		'unenroll_label' => __( 'إلغاء التسجيل', 'tutor-sso' ),
	);

	if ( $part_of_program ) {
		$status = course_detail_status( $course_key );

		// Enrolled via the program: into the course, with no unenroll action —
		// the enrollment is not this page's to cancel.
		if ( $status['enrolled'] ) {
			$labels['show_unenroll'] = false;

			return render_enroll_button( $course_key, $labels );
		}

		// Not enrolled: there is nothing to buy or enroll into here.
		return course_render_program_notice( $program_url );
	}

	// Free course: unchanged behaviour, except when enrollment is closed — in
	// which case a status check (normally skipped here, to save the API call
	// on the common case) is needed to tell "not enrolled, so blocked" apart
	// from "already enrolled, so still shown the enroll button's own go-to/
	// unenroll state" — enrollment_closed blocks new enrollment, not access
	// someone already has.
	if ( ! $paid ) {
		if ( $enrollment_closed ) {
			$status = course_detail_status( $course_key );

			if ( $status['enrolled'] ) {
				return render_enroll_button( $course_key, $labels );
			}

			return course_render_enrollment_closed_notice();
		}

		return render_enroll_button( $course_key, $labels );
	}

	$status = course_detail_status( $course_key );

	// Already enrolled: into the course, not the checkout. Unenroll stays
	// available here — only a program-course enrollment is not this page's to
	// cancel (see the part_of_program branch above).
	if ( $status['enrolled'] ) {
		return render_enroll_button( $course_key, $labels );
	}

	// Bought it (or owns it through a program): enroll, never charge twice.
	// A paid-for seat is honoured even once the LMS closes enrollment — the
	// closed check below is only for viewers with nothing already owed to them.
	if ( $status['has_order'] ) {
		return render_enroll_button( $course_key, $labels );
	}

	// Nothing enrolled and nothing bought: the LMS has closed new enrollment,
	// so there is nothing to offer.
	if ( $enrollment_closed ) {
		return course_render_enrollment_closed_notice();
	}

	// The price has its own panel above the button now.
	$buy = array( 'price_html' => '' );

	$product_id = $post_id ? course_product_buyable( $post_id ) : 0;

	// Guests are sent to log in first: no guest checkout, so there is always a
	// WordPress user for the order's enrollment to belong to.
	if ( $product_id && ! is_user_logged_in() ) {
		return course_render_buy_login_button( $product_id, $buy );
	}

	if ( ! $product_id ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[tutor-sso] course %d is paid but has no buyable product; no call to action rendered', $post_id )
			);
		}

		return '';
	}

	return course_render_buy_button( $product_id, $buy );
}

/**
 * Render the "this course is part of a program" notice, with a button to the
 * program's own detail page — where enrollment or purchase actually happens.
 *
 * @param string $program_url The program's detail page.
 * @return string HTML, or '' when the program could not be resolved.
 */
function course_render_program_notice( $program_url ) {
	$program_url = trim( (string) $program_url );

	if ( '' === $program_url ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[tutor-sso] course is part_of_program but its program_url could not be resolved; no call to action rendered'
			);
		}

		return '';
	}

	ob_start();
	?>
	<div class="tutor-sso-enroll-wrap tutor-sso-enroll-wrap--program">
		<p class="tutor-sso-enroll-notice">
			<?php echo esc_html__( 'هذا المساق جزء من برنامج، ويمكن التسجيل فيه من خلال صفحة البرنامج.', 'tutor-sso' ); ?>
		</p>
		<a class="tutor-sso-enroll-btn tutor-sso-enroll-btn--goto" href="<?php echo esc_url( $program_url ); ?>">
			<?php echo esc_html__( 'الذهاب إلى البرنامج', 'tutor-sso' ); ?>
		</a>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the "enrollment is closed" notice — the LMS's `is_enrollment_full`
 * or `is_enrollment_closed` reported true and the viewer isn't already in, so
 * there is nothing to offer: no enroll button (free) and no buy button (paid).
 *
 * @return string HTML.
 */
function course_render_enrollment_closed_notice() {
	ob_start();
	?>
	<div class="tutor-sso-enroll-wrap tutor-sso-enroll-wrap--program">
		<p class="tutor-sso-enroll-notice">
			<?php echo esc_html__( 'التسجيل في هذا المساق مغلق حاليًا.', 'tutor-sso' ); ?>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * The current user's enrollment state for a course, asked once per render.
 *
 * @param string $course_key edX course key.
 * @return array{enrolled:bool,has_order:bool}
 */
function course_detail_status( $course_key ) {
	static $cache = array();

	$none = array(
		'enrolled'  => false,
		'has_order' => false,
	);

	if ( ! is_user_logged_in() || ! function_exists( __NAMESPACE__ . '\\enroll_status' ) ) {

		return $none;
	}

	if ( ! isset( $cache[ $course_key ] ) ) {
		$status = enroll_status( $course_key );

		// WP_Error (LMS unreachable) counts as neither, matching
		// render_enroll_button()'s own handling.
		$cache[ $course_key ] = is_wp_error( $status ) ? $none : $status;
	}

	return $cache[ $course_key ];
}

/**
 * Render the instructor panel: one row per instructor with their avatar, name
 * (linked to the local instructor post when one exists) and count rows.
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_instructors( $data ) {
	$instructors = isset( $data['instructors'] ) ? (array) $data['instructors'] : array();

	if ( empty( $instructors ) ) {
		return '';
	}

	$fallback = TUTOR_SSO_URL . 'assets/images/avatar-fallback-author.svg';

	ob_start();
	?>
	<div class="rwaq-cd__card rwaq-cd__people">
		<h2 class="rwaq-cd__people-title"><?php echo esc_html__( 'المدرب', 'tutor-sso' ); ?></h2>

		<?php
		foreach ( $instructors as $person ) :
			$name   = isset( $person['name'] ) ? (string) $person['name'] : '';
			$image  = isset( $person['image'] ) ? (string) $person['image'] : '';
			$url    = isset( $person['url'] ) ? (string) $person['url'] : '';
			$counts = isset( $person['counts'] ) ? (array) $person['counts'] : array();

			// Instructor avatars are presigned S3 links that expire, so the shared
			// fallback also stands in via onerror.
			$src = '' !== $image ? $image : $fallback;

			$rows = array(
				array(
					'icon'  => 'stat-courses.svg',
					/* translators: %s: number of courses. */
					'label' => sprintf( __( '%s مساق', 'tutor-sso' ), number_format_i18n( isset( $counts['courses'] ) ? (int) $counts['courses'] : 0 ) ),
				),
				array(
					'icon'  => 'stat-learners.svg',
					/* translators: %s: number of learners. */
					'label' => sprintf( __( '%s طالب', 'tutor-sso' ), number_format_i18n( isset( $counts['learners'] ) ? (int) $counts['learners'] : 0 ) ),
				),
				array(
					'icon'  => 'stat-programs.svg',
					/* translators: %s: number of programs. */
					'label' => sprintf( __( '%s برنامج', 'tutor-sso' ), number_format_i18n( isset( $counts['programs'] ) ? (int) $counts['programs'] : 0 ) ),
				),
			);
			?>
			<div class="rwaq-cd__person">
				<span class="rwaq-cd__person-media">
					<img
						class="rwaq-cd__person-image<?php echo '' !== $image ? '' : ' is-fallback'; ?>"
						src="<?php echo esc_url( $src ); ?>"
						alt="<?php echo esc_attr( $name ); ?>"
						loading="lazy"
						decoding="async"
						onerror="this.onerror=null;this.src='<?php echo esc_url( $fallback ); ?>';this.classList.add('is-fallback');"
					/>
				</span>

				<div class="rwaq-cd__person-body">
					<?php if ( '' !== $url ) : ?>
						<a class="rwaq-cd__person-name" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
					<?php else : ?>
						<span class="rwaq-cd__person-name"><?php echo esc_html( $name ); ?></span>
					<?php endif; ?>

					<ul class="rwaq-cd__person-stats">
						<?php foreach ( $rows as $row ) : ?>
							<li class="rwaq-cd__person-stat">
								<img src="<?php echo esc_url( course_asset( $row['icon'] ) ); ?>" width="16" height="16" alt="" aria-hidden="true" />
								<span><?php echo esc_html( $row['label'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render the whole course detail view for a post.
 *
 * @param int $post_id Course post ID.
 * @return string HTML.
 */
function course_render_detail( $post_id ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 ) {
		return '';
	}

	course_detail_enqueue_assets();

	$data  = course_detail_data( $post_id );
	$error = isset( $data['error'] ) ? (string) $data['error'] : '';

	ob_start();
	?>
	<div class="rwaq-cd" dir="rtl">
		<div class="rwaq-cd__inner">
			<?php echo course_render_breadcrumb( isset( $data['title'] ) ? (string) $data['title'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( '' !== $error ) : ?>
				<p class="rwaq-cd__error"><?php echo esc_html( $error ); ?></p>
			<?php else : ?>
				<div class="rwaq-cd__layout">
					<div class="rwaq-cd__main">
						<?php
						echo course_render_video( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo course_render_header( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo course_render_overview( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>

					<aside class="rwaq-cd__rail">
						<?php
						echo course_render_enroll_card( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo course_render_instructors( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</aside>
				</div>

				<?php echo course_render_related( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode: [rwaq_course_detail id="123"].
 *
 * With no id, renders the current post — which is how
 * templates/single-course.php uses it.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML.
 */
function course_detail_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'rwaq_course_detail' );

	$post_id = (int) $atts['id'];

	if ( $post_id <= 0 ) {
		$post_id = get_the_ID();
	}

	return course_render_detail( $post_id );
}
add_shortcode( 'rwaq_course_detail', __NAMESPACE__ . '\\course_detail_shortcode' );
