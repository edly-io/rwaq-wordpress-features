<?php
/**
 * "Rwaq Ambassadors" page template.
 *
 * Registered programmatically by the plugin (see
 * includes/ambassadors/ambassadors-page-template.php) — it is selectable under
 * Page Attributes → Template → "Rwaq Ambassadors", not via this file's header.
 *
 * The copy on this page is static (translatable) Arabic; only two things come
 * from the page itself:
 *
 *   cf7_form_id             The Contact Form 7 form shown in the application
 *                           section at the bottom.
 *   ambassadors_gallery_1-4 The four gallery images.
 *
 * The page content (the editor body) is rendered as an optional intro block
 * above the working groups, so an editor can add extra copy without touching
 * this file. A theme can override this template by providing its own.
 *
 * @package tutor-sso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// Set the post up once, before any markup: the template renders fixed sections
// rather than looping, so the_post() is called here and the editor body is
// captured for the optional intro block below.
if ( have_posts() ) {
	the_post();
}

$post_id          = get_queried_object_id();

/* Working groups — icon key, title, description. */
$rwaq_amb_groups = array(
	array(
		'icon'  => 'building',
		'title' => __( 'استقطاب المحاضرين', 'tutor-sso' ),
		'text'  => __( 'إعداد قوائم بالمحاضرين والجهات التعليمية، وابتكار أساليب استقطابهم، والتواصل المباشر معهم', 'tutor-sso' ),
	),
	array(
		'icon'  => 'campus',
		'title' => __( 'تمثيل رواق في الفعاليات', 'tutor-sso' ),
		'text'  => __( 'حضور المؤتمرات والملتقيات الجامعية، والتسويق لمفهوم التعليم المفتوح', 'tutor-sso' ),
	),
	array(
		'icon'  => 'graduation',
		'title' => __( 'التصوير والمونتاج', 'tutor-sso' ),
		'text'  => __( 'العمل مع الفريق الفني على تصوير المحاضرات وإدارة المحتوى', 'tutor-sso' ),
	),
);

/* Application steps — name + short description, numbered in order. */
$rwaq_amb_steps = array(
	array(
		'name' => __( 'قدّم طلبك', 'tutor-sso' ),
		'text' => __( 'أرفق سيرتك ورسالة قصيرة', 'tutor-sso' ),
	),
	array(
		'name' => __( 'القبول المبدئي', 'tutor-sso' ),
		'text' => __( 'نراجع طلبك ونرد عليك', 'tutor-sso' ),
	),
	array(
		'name' => __( 'مقابلة تعارف', 'tutor-sso' ),
		'text' => __( 'مكالمة قصيرة عبر الإنترنت', 'tutor-sso' ),
	),
	array(
		'name' => __( 'القبول النهائي', 'tutor-sso' ),
		'text' => __( 'توزيعك على مجموعاتك', 'tutor-sso' ),
	),
	array(
		'name' => __( 'ابدأ رحلتك', 'tutor-sso' ),
		'text' => __( 'انضم لمجتمع السفراء', 'tutor-sso' ),
	),
);

/* Requirements — icon key, title, description. */
$rwaq_amb_reqs = array(
	array(
		'icon'  => 'graduation',
		'title' => __( 'طالب جامعي حاليًا', 'tutor-sso' ),
		'text'  => __( 'بأي تخصص، بأي جامعة عربية معترف بها', 'tutor-sso' ),
	),
	array(
		'icon'  => 'chat',
		'title' => __( 'قدرة على التواصل بثقة', 'tutor-sso' ),
		'text'  => __( 'لا حاجة لخبرة إعلامية سابقة', 'tutor-sso' ),
	),
	array(
		'icon'  => 'alarm',
		'title' => __( 'بضع ساعات شهريًا', 'tutor-sso' ),
		'text'  => __( 'تطوّع مرن حول جدولك الدراسي', 'tutor-sso' ),
	),
	array(
		'icon'  => 'book',
		'title' => __( 'اهتمام بالتعليم المفتوح', 'tutor-sso' ),
		'text'  => __( 'هذا ما يهمّنا أكثر من أي شيء', 'tutor-sso' ),
	),
);
?>

<?php /* dir is set here rather than inherited: the copy on this page is
   hardcoded Arabic, so it must read RTL even when the site locale is LTR. */ ?>
<div class="rwaq-amb">

	<?php /* ---------- Hero ---------- */ ?>
	<section class="rwaq-amb__hero">
		<span class="rwaq-amb__blob rwaq-amb__blob--primary" aria-hidden="true"></span>
		<span class="rwaq-amb__blob rwaq-amb__blob--gold" aria-hidden="true"></span>

		<div class="rwaq-amb__inner">
			<div class="rwaq-amb__hero-inner">
				<h1 class="rwaq-amb__hero-title"><?php esc_html_e( 'جامعتك تحتاج صوتاً يمثّل رواق', 'tutor-sso' ); ?></h1>
				<p class="rwaq-amb__hero-text">
					<?php esc_html_e( 'كن حلقة الوصل بين رواق وآلاف الطلاب والمحاضرين في جامعتك. لا حاجة لخبرة سابقة — فقط حماسك واهتمامك بالتعليم المفتوح.', 'tutor-sso' ); ?>
				</p>
				<a class="rwaq-amb__cta" href="#rwaq-amb-apply"><?php esc_html_e( 'انضم كسفير', 'tutor-sso' ); ?></a>
			</div>

			<div class="rwaq-amb__highlights">
				<div class="rwaq-amb__highlight">
					<?php echo \TutorSSO\ambassadors_icon( 'certificate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="rwaq-amb__highlight-label"><?php esc_html_e( 'مرجع تطوّع موثّق', 'tutor-sso' ); ?></span>
				</div>
				<div class="rwaq-amb__highlight">
					<?php echo \TutorSSO\ambassadors_icon( 'globe' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="rwaq-amb__highlight-label"><?php esc_html_e( 'مجتمع طلابي عربي واسع', 'tutor-sso' ); ?></span>
				</div>
				<div class="rwaq-amb__highlight">
					<?php echo \TutorSSO\ambassadors_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="rwaq-amb__highlight-label"><?php esc_html_e( 'مرونة حول جدولك الدراسي', 'tutor-sso' ); ?></span>
				</div>
			</div>
		</div>
	</section>

	<?php /* ---------- Working groups ---------- */ ?>
	<section class="rwaq-amb__section">
		<div class="rwaq-amb__inner">
			<h2 class="rwaq-amb__title"><?php esc_html_e( 'مجموعات العمل الثلاث', 'tutor-sso' ); ?></h2>
			<p class="rwaq-amb__subtitle"><?php esc_html_e( 'اختر مجموعة أو أكثر تناسب اهتماماتك — ويمكن المشاركة في أكثر من نشاط', 'tutor-sso' ); ?></p>

			<div class="rwaq-amb__groups">
				<?php foreach ( $rwaq_amb_groups as $rwaq_amb_group ) : ?>
					<article class="rwaq-amb__group">
						<div class="rwaq-amb__icon">
							<?php echo \TutorSSO\ambassadors_icon( $rwaq_amb_group['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<h3 class="rwaq-amb__group-title"><?php echo esc_html( $rwaq_amb_group['title'] ); ?></h3>
						<p class="rwaq-amb__group-text"><?php echo esc_html( $rwaq_amb_group['text'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php /* ---------- Application steps ---------- */ ?>
	<section class="rwaq-amb__section rwaq-amb__steps-band">
		<div class="rwaq-amb__inner">
			<div class="rwaq-amb__steps-card">
				<h2 class="rwaq-amb__steps-title"><?php esc_html_e( 'من التقديم إلى الانضمام في 5 خطوات', 'tutor-sso' ); ?></h2>

				<ol class="rwaq-amb__steps">
					<?php foreach ( $rwaq_amb_steps as $rwaq_amb_index => $rwaq_amb_step ) : ?>
						<li class="rwaq-amb__step">
							<?php /* Number first so it sits at the inline-start (right, in RTL) and is read before the copy. */ ?>
							<span class="rwaq-amb__step-num" aria-hidden="true"><?php echo (int) $rwaq_amb_index + 1; ?></span>
							<span class="rwaq-amb__step-copy">
								<span class="rwaq-amb__step-name"><?php echo esc_html( $rwaq_amb_step['name'] ); ?></span>
								<span class="rwaq-amb__step-text"><?php echo esc_html( $rwaq_amb_step['text'] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		</div>
	</section>

	<?php /* ---------- Requirements ---------- */ ?>
	<section class="rwaq-amb__section">
		<div class="rwaq-amb__inner">
			<h2 class="rwaq-amb__title"><?php esc_html_e( 'تحتاج فقط إلى الحماس، لا إلى خبرة سابقة', 'tutor-sso' ); ?></h2>
			<p class="rwaq-amb__subtitle"><?php esc_html_e( 'سنساعدك في بقية الخطوات — إليك ما نبحث عنه فعلاً', 'tutor-sso' ); ?></p>

			<div class="rwaq-amb__reqs">
				<?php foreach ( $rwaq_amb_reqs as $rwaq_amb_req ) : ?>
					<article class="rwaq-amb__req">
						<div class="rwaq-amb__icon rwaq-amb__icon--sm">
							<?php echo \TutorSSO\ambassadors_icon( $rwaq_amb_req['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="rwaq-amb__req-copy">
							<h3 class="rwaq-amb__req-title"><?php echo esc_html( $rwaq_amb_req['title'] ); ?></h3>
							<p class="rwaq-amb__req-text"><?php echo esc_html( $rwaq_amb_req['text'] ); ?></p>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<?php /* ---------- Gallery ---------- */ ?>
	<section class="rwaq-amb__section rwaq-amb__section--surface">
		<div class="rwaq-amb__inner">
			<h2 class="rwaq-amb__title"><?php esc_html_e( 'لحظات من رحلة سفراء رواق', 'tutor-sso' ); ?></h2>
			<p class="rwaq-amb__subtitle"><?php esc_html_e( 'من الملتقيات الجامعية إلى لقاءات الفريق — هذه رحلتك القادمة', 'tutor-sso' ); ?></p>

			<div class="rwaq-amb__gallery">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
				echo \TutorSSO\ambassadors_gallery_slot( $post_id, 1, '1', __( 'صورة من ملتقى جامعي', 'tutor-sso' ) );
				echo \TutorSSO\ambassadors_gallery_slot( $post_id, 2, '2', __( 'صورة لفريق السفراء', 'tutor-sso' ) );
				?>
				<div class="rwaq-amb__gallery-split">
					<?php
					echo \TutorSSO\ambassadors_gallery_slot( $post_id, 3, '3', __( 'لقطة من ورشة', 'tutor-sso' ) );
					echo \TutorSSO\ambassadors_gallery_slot( $post_id, 4, '4', __( 'لقطة تصوير', 'tutor-sso' ) );
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</div>

			<div class="rwaq-amb__hashtag">
				<?php echo \TutorSSO\ambassadors_icon( 'instagram' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span>
					<?php
					printf(
						/* translators: %s: the campaign hashtag, wrapped in <b>. */
						esc_html__( 'شارك لحظاتك كسفير عبر %s وقد تظهر صورتك هنا', 'tutor-sso' ),
						'<b>' . esc_html__( '#سفراء_رواق', 'tutor-sso' ) . '</b>'
					);
					?>
				</span>
			</div>
		</div>
	</section>

	<?php /* ---------- Application form (Contact Form 7) ---------- */ ?>
	<section id="rwaq-amb-apply" class="rwaq-amb__section rwaq-amb__form-band">
		<div class="rwaq-amb__inner">
			<h2 class="rwaq-amb__title rwaq-amb__center"><?php esc_html_e( 'قدّم طلبك', 'tutor-sso' ); ?></h2>
			<p class="rwaq-amb__subtitle rwaq-amb__center"><?php esc_html_e( 'يستغرق حوالي دقيقتين', 'tutor-sso' ); ?></p>

			<div class="rwaq-amb__form-card">
				<?php echo \TutorSSO\ambassadors_form_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</section>

</div>

<?php
get_footer();
