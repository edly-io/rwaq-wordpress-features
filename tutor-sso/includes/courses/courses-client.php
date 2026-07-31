<?php
/**
 * Courses listing data layer.
 *
 * ⚠️ PLACEHOLDER DATA: the real "courses listing" and "organization filter" APIs
 * are not wired yet. Until they are, this file serves sample courses / orgs and
 * does the search / org-filter / sort / pagination in memory, so the UI is fully
 * interactive. When the APIs arrive, replace the bodies of courses_fetch() and
 * courses_organizations() (or hook the filters below) — the shortcode, AJAX and
 * card renderer already consume the shapes documented here.
 *
 * Course shape (per result):
 *   {
 *     id, slug, title, image, url,
 *     org_slug, org_name, org_logo,
 *     duration, lessons_count, level, is_featured, created (sort key)
 *   }
 *
 * Organization shape (filter options):
 *   { slug, name, logo? }
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed sort keys.
 *
 * @return string[]
 */
function courses_allowed_ordering() {
	return array( 'newest', 'oldest', 'title_asc', 'title_desc' );
}

/**
 * Organizations for the filter dropdown.
 *
 * TODO: replace with the organization filter API. Filterable so real data can be
 * injected without editing this file.
 *
 * @return array<int,array{slug:string,name:string,logo?:string}>
 */
function courses_organizations() {
	return apply_filters( 'tutor_sso_courses_organizations', courses_sample_organizations() );
}

/**
 * Fetch a page of courses (search / org filter / sort / pagination applied).
 *
 * TODO: replace the in-memory handling with the courses listing API. Hooking
 * `tutor_sso_courses_results` (return an array to short-circuit) is the seam to
 * use once the endpoint exists.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Courses per page.
 * @param array $args     { search, ordering, org (string[]) }.
 * @return array{results:array[],total:int,num_pages:int}
 */
function courses_fetch( $page = 1, $per_page = 9, $args = array() ) {
	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $per_page );

	// Real-API seam: return an array from this filter to bypass the sample data.
	$external = apply_filters( 'tutor_sso_courses_results', null, $page, $per_page, $args );
	if ( is_array( $external ) ) {
		return wp_parse_args(
			$external,
			array(
				'results'   => array(),
				'total'     => 0,
				'num_pages' => 0,
			)
		);
	}

	$courses = apply_filters( 'tutor_sso_courses_source', courses_sample_courses(), $args );

	// ── Search (title or organization name contains) ──
	$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
	if ( '' !== $search ) {
		$courses = array_values(
			array_filter(
				$courses,
				function ( $c ) use ( $search ) {
					$haystack = ( $c['title'] ?? '' ) . ' ' . ( $c['org_name'] ?? '' );
					return false !== mb_stripos( $haystack, $search );
				}
			)
		);
	}

	// ── Organization filter (OR across selected org slugs) ──
	$orgs = isset( $args['org'] ) ? array_values( array_filter( array_map( 'strval', (array) $args['org'] ) ) ) : array();
	if ( ! empty( $orgs ) ) {
		$courses = array_values(
			array_filter(
				$courses,
				function ( $c ) use ( $orgs ) {
					return in_array( (string) ( $c['org_slug'] ?? '' ), $orgs, true );
				}
			)
		);
	}

	// ── Sort ──
	$ordering = isset( $args['ordering'] ) ? (string) $args['ordering'] : 'newest';
	usort(
		$courses,
		function ( $a, $b ) use ( $ordering ) {
			switch ( $ordering ) {
				case 'oldest':
					return ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 );
				case 'title_asc':
					return strcmp( $a['title'] ?? '', $b['title'] ?? '' );
				case 'title_desc':
					return strcmp( $b['title'] ?? '', $a['title'] ?? '' );
				case 'newest':
				default:
					return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
			}
		}
	);

	// ── Paginate ──
	$total     = count( $courses );
	$num_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
	$results   = array_slice( $courses, ( $page - 1 ) * $per_page, $per_page );

	return array(
		'results'   => $results,
		'total'     => $total,
		'num_pages' => $num_pages,
	);
}

/**
 * PLACEHOLDER organizations. Remove once the org filter API is wired.
 *
 * @return array[]
 */
function courses_sample_organizations() {
	$names = array(
		'rawaq'      => 'رواق',
		'edraak'     => 'إدراك',
		'microsoft'  => 'مايكروسوفت',
		'google'     => 'جوجل',
		'ksu'        => 'جامعة الملك سعود',
		'kaust'      => 'جامعة الملك عبدالله',
		'hrsd'       => 'وزارة الموارد البشرية',
		'tuwaiq'     => 'أكاديمية طويق',
		'misk'       => 'مسك',
		'coursera'   => 'كورسيرا',
		'udacity'    => 'يوداسيتي',
		'ibm'        => 'آي بي إم',
	);

	$orgs = array();
	foreach ( $names as $slug => $name ) {
		$orgs[] = array(
			'slug' => $slug,
			'name' => $name,
			'logo' => '',
		);
	}

	return $orgs;
}

/**
 * PLACEHOLDER courses. Remove once the courses listing API is wired.
 *
 * @return array[]
 */
function courses_sample_courses() {
	$rows = array(
		array( 'علوم البيانات التطبيقية باستخدام بايثون', 'rawaq', 'رواق', '٦ أسابيع', 8, 'مبتدئ', true ),
		array( 'أساسيات تصميم تجربة المستخدم', 'edraak', 'إدراك', '٤ أسابيع', 6, 'مبتدئ', true ),
		array( 'هندسة تعلم الآلة من الصفر', 'microsoft', 'مايكروسوفت', '٨ أسابيع', 12, 'متقدم', true ),
		array( 'إدارة المشاريع الاحترافية', 'ksu', 'جامعة الملك سعود', '٥ أسابيع', 9, 'متوسط', false ),
		array( 'التسويق الرقمي وتحليل البيانات', 'hrsd', 'وزارة الموارد البشرية', '٣ أسابيع', 5, 'مبتدئ', false ),
		array( 'الحوسبة السحابية وأنظمتها', 'google', 'جوجل', '٧ أسابيع', 10, 'متقدم', true ),
		array( 'مقدمة في الأمن السيبراني', 'tuwaiq', 'أكاديمية طويق', '٦ أسابيع', 8, 'متوسط', false ),
		array( 'مهارات القيادة وبناء الفرق', 'misk', 'مسك', '٤ أسابيع', 6, 'متوسط', false ),
		array( 'تطوير تطبيقات الويب الحديثة', 'ibm', 'آي بي إم', '٩ أسابيع', 14, 'متقدم', false ),
	);

	$courses = array();
	$i       = count( $rows );
	foreach ( $rows as $index => $row ) {
		list( $title, $org_slug, $org_name, $duration, $lessons, $level, $featured ) = $row;
		$courses[] = array(
			'id'            => $index + 1,
			'slug'          => 'course-' . ( $index + 1 ),
			'title'         => $title,
			'image'         => '',
			'url'           => '#',
			'org_slug'      => $org_slug,
			'org_name'      => $org_name,
			'org_logo'      => '',
			'duration'      => $duration,
			'lessons_count' => $lessons,
			'level'         => $level,
			'is_featured'   => $featured,
			// Descending index → first row is "newest".
			'created'       => $i - $index,
		);
	}

	return $courses;
}
