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
				<?php if ( '' !== $org_name ) : ?>
					<div class="rwaq-cd__org-text">
						<span class="rwaq-cd__org-label"><?php echo esc_html__( 'مُقدَّم من', 'tutor-sso' ); ?></span>
						<span class="rwaq-cd__org-name"><?php echo esc_html( $org_name ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $org_logo ) : ?>
					<div class="rwaq-cd__org-logo">
						<img src="<?php echo esc_url( $org_logo ); ?>" alt="<?php echo esc_attr( $org_name ); ?>" loading="lazy" decoding="async" />
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
 * Render the enroll card: the course image, then [tutor_enroll_button] keyed to
 * the course's edX id (login / enroll / unenroll are the shortcode's own).
 *
 * @param array $data View model.
 * @return string HTML.
 */
function course_render_enroll_card( $data ) {
	$image      = isset( $data['image'] ) ? (string) $data['image'] : '';
	$course_key = isset( $data['course_key'] ) ? (string) $data['course_key'] : '';

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
		if ( '' !== $course_key ) {
			echo render_enroll_button( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$course_key,
				array(
					'enroll_label'   => __( 'سجّل الآن', 'tutor-sso' ),
					'login_label'    => __( 'سجّل الآن', 'tutor-sso' ),
					'goto_label'     => __( 'اذهب إلى المساق', 'tutor-sso' ),
					'unenroll_label' => __( 'إلغاء التسجيل', 'tutor-sso' ),
				)
			);
		}
		?>
	</div>
	<?php
	return ob_get_clean();
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
