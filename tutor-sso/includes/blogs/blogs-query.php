<?php
/**
 * Blogs listing data layer (WordPress posts).
 *
 * The blogs listing is the WordPress-native counterpart of the programs catalog:
 * instead of an external LMS API (see programs-client.php) it queries local posts
 * via WP_Query. This file holds the query wrapper and the taxonomy-term helpers
 * used to build the filter sidebar. The presentation lives in blogs-catalog.php
 * and the AJAX (search / sort / filter / load-more) in blogs-ajax.php.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelisted `ordering` values → WP_Query orderby/order pairs.
 *
 * Guards against arbitrary orderby coming from the query string / AJAX.
 *
 * @return array<string,array{orderby:string,order:string}>
 */
function blogs_allowed_ordering() {
	return array(
		'newest'     => array(
			'orderby' => 'date',
			'order'   => 'DESC',
		),
		'oldest'     => array(
			'orderby' => 'date',
			'order'   => 'ASC',
		),
		'title_asc'  => array(
			'orderby' => 'title',
			'order'   => 'ASC',
		),
		'title_desc' => array(
			'orderby' => 'title',
			'order'   => 'DESC',
		),
	);
}

/**
 * Fetch one page of published posts for the blogs listing.
 *
 * @param int   $page     1-based page number.
 * @param int   $per_page Posts per page.
 * @param array $args     Optional {
 *     @type string   $search    Free-text search term (WP_Query `s`).
 *     @type string   $ordering  Ordering key (see blogs_allowed_ordering()).
 *     @type string   $post_type Post type to query. Default 'post'.
 *     @type array    $tax       Map of taxonomy => term-slug[] to AND-filter by.
 *     @type bool     $featured  Limit to posts flagged featured (ACF is_featured).
 * }
 * @return array{results:\WP_Post[],total:int,num_pages:int}
 */
function blogs_fetch( $page = 1, $per_page = 9, $args = array() ) {
	$page     = max( 1, (int) $page );
	$per_page = max( 1, (int) $per_page );

	$post_type = ( ! empty( $args['post_type'] ) && post_type_exists( $args['post_type'] ) )
		? $args['post_type']
		: 'post';

	$query_args = array(
		'post_type'           => $post_type,
		'post_status'         => 'publish',
		'posts_per_page'      => $per_page,
		'paged'               => $page,
		'ignore_sticky_posts' => true,
	);

	if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
		$query_args['s'] = trim( (string) $args['search'] );
	}

	$allowed  = blogs_allowed_ordering();
	$ordering = isset( $args['ordering'] ) ? (string) $args['ordering'] : '';
	if ( isset( $allowed[ $ordering ] ) ) {
		$query_args['orderby'] = $allowed[ $ordering ]['orderby'];
		$query_args['order']   = $allowed[ $ordering ]['order'];
	} else {
		$query_args['orderby'] = 'date';
		$query_args['order']   = 'DESC';
	}

	// Filter dimensions: each selected taxonomy (its terms are OR-combined) and
	// the featured flag. A single active dimension uses WP_Query's own tax_query
	// / meta_query. Two or more must be OR-combined together — which WP_Query
	// can't express across tax_query + meta_query (they always AND) — so each
	// dimension is resolved to a set of post IDs and unioned into post__in.
	// Search (`s`) is a separate WP_Query clause, so it always ANDs on top.
	$tax_dims = array();
	if ( ! empty( $args['tax'] ) && is_array( $args['tax'] ) ) {
		foreach ( $args['tax'] as $taxonomy => $slugs ) {
			$taxonomy = sanitize_key( $taxonomy );
			$slugs    = array_values( array_filter( array_map( 'sanitize_title', (array) $slugs ) ) );

			if ( $slugs && taxonomy_exists( $taxonomy ) ) {
				$tax_dims[] = array(
					'taxonomy' => $taxonomy,
					'slugs'    => $slugs,
				);
			}
		}
	}

	$has_featured = ! empty( $args['featured'] );
	$dimensions   = count( $tax_dims ) + ( $has_featured ? 1 : 0 );

	if ( $dimensions >= 2 ) {
		// OR across dimensions: union of the matching post IDs.
		$ids = array();
		foreach ( $tax_dims as $dim ) {
			$ids = array_merge( $ids, blogs_tax_post_ids( $post_type, $dim['taxonomy'], $dim['slugs'] ) );
		}
		if ( $has_featured ) {
			$ids = array_merge( $ids, blogs_featured_post_ids( $post_type ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		// Empty union → force no matches (WP_Query ignores an empty post__in).
		$query_args['post__in'] = ! empty( $ids ) ? $ids : array( 0 );
	} elseif ( 1 === count( $tax_dims ) ) {
		$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => $tax_dims[0]['taxonomy'],
				'field'    => 'slug',
				'terms'    => $tax_dims[0]['slugs'],
			),
		);
	} elseif ( $has_featured ) {
		$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => 'is_featured',
				'value'   => '1',
				'compare' => '=',
			),
		);
	}

	/**
	 * Filter the WP_Query args used for the blogs listing.
	 *
	 * @param array $query_args WP_Query arguments.
	 * @param array $args       Original high-level args passed to blogs_fetch().
	 */
	$query_args = apply_filters( 'tutor_sso_blogs_query_args', $query_args, $args );

	$query = new \WP_Query( $query_args );

	return array(
		'results'   => $query->posts,
		'total'     => (int) $query->found_posts,
		'num_pages' => (int) $query->max_num_pages,
	);
}

/**
 * Published post IDs in ANY of the given taxonomy terms (for OR-combining
 * filter dimensions in blogs_fetch()).
 *
 * @param string   $post_type Post type.
 * @param string   $taxonomy  Taxonomy slug.
 * @param string[] $slugs     Term slugs.
 * @return int[]
 */
function blogs_tax_post_ids( $post_type, $taxonomy, $slugs ) {
	return get_posts(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'nopaging'               => true,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slugs,
				),
			),
		)
	);
}

/**
 * Published post IDs flagged featured (ACF is_featured) — for OR-combining
 * filter dimensions in blogs_fetch().
 *
 * @param string $post_type Post type.
 * @return int[]
 */
function blogs_featured_post_ids( $post_type ) {
	return get_posts(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'nopaging'               => true,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'is_featured',
					'value'   => '1',
					'compare' => '=',
				),
			),
		)
	);
}

/**
 * Terms of a taxonomy for the filter sidebar (non-empty only, by default).
 *
 * @param string $taxonomy Taxonomy slug.
 * @param array  $args     Optional get_terms() overrides.
 * @return \WP_Term[]
 */
function blogs_terms( $taxonomy, $args = array() ) {
	$taxonomy = sanitize_key( $taxonomy );

	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	$terms = get_terms(
		wp_parse_args(
			$args,
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		)
	);

	return is_wp_error( $terms ) ? array() : $terms;
}
