<?php
/**
 * REST API for creating / updating "course" posts.
 *
 * Exposes a single upsert endpoint:
 *
 *   POST /wp-json/rwaq/v1/courses
 *
 * Authentication is delegated to the "JWT Authentication for WP REST API"
 * plugin (see the programs controller for the full flow). This controller only
 * checks capabilities; the JWT plugin has already resolved the bearer token to
 * a WordPress user by the time the permission callback runs.
 *
 * Create vs. update is decided by `openedx_course_id`: if a course with that id
 * already exists it is updated, otherwise a new one is created.
 *
 * Accepted body fields (JSON or form-encoded):
 *   - title              → post title             (required on create)
 *   - content            → post content
 *   - slug               → post slug (post_name)  (required on create; ignored on update)
 *   - status             → post status (publish|draft)
 *   - openedx_course_id  → ACF field, upsert key  (required, immutable)
 *   - short_description  → ACF field              (required on create)
 *   - course_start_date  → ACF field              (required on create)
 *   - course_end_date    → ACF field              (required on create)
 *   - instructor         → ACF field
 *   - youtube_link       → ACF field (URL)
 *   - course_duration    → ACF field
 *   - total_enrollment   → ACF field
 *   - category           → course-category term(s), matched by name / created if missing
 *   - org                → course-partner term,   matched by name / created if missing
 *   - featured_image     → sideloaded + set as the post's featured image
 *   - instructor_image   → sideloaded; attachment id stored in the ACF image field
 *
 * @package tutor-sso
 */

namespace TutorSSO\Courses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert controller for the `course` custom post type.
 */
class Courses_REST_Controller extends \WP_REST_Controller {

	/** The custom post type this endpoint manages. */
	const POST_TYPE = 'course';

	/** Category taxonomy, populated from the `category` field. */
	const TAX_CATEGORY = 'course-category';

	/** Partner taxonomy, populated from the `org` field. */
	const TAX_PARTNER = 'course-partner';

	/** Remembers the source URL of the featured image to avoid re-downloading. */
	const FEATURED_IMAGE_META = '_course_featured_image_source';

	/** Remembers the source URL of the instructor image to avoid re-downloading. */
	const INSTRUCTOR_IMAGE_META = '_course_instructor_image_source';

	/** ACF field name the instructor image attachment id is stored in. */
	const INSTRUCTOR_IMAGE_FIELD = 'instructor_image';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'rwaq/v1';
		$this->rest_base = 'courses';
	}

	/**
	 * Map incoming body fields → ACF field names (post meta keys).
	 *
	 * Title/content/slug/status are post fields, the two images and the two
	 * taxonomies are handled separately, so none of those appear here. Override
	 * the ACF slugs via the `tutor_sso_course_acf_fields` filter.
	 *
	 * @return array<string,string> request_field => acf_field_name
	 */
	public static function acf_field_map() {
		/**
		 * Filter the request-field → ACF-field-name map for courses.
		 *
		 * @param array<string,string> $map Default field map.
		 */
		return apply_filters(
			'tutor_sso_course_acf_fields',
			array(
				'openedx_course_id' => 'openedx_course_id',
				'short_description' => 'short_description',
				'course_start_date' => 'course_start_date',
				'course_end_date'   => 'course_end_date',
				'instructor'        => 'instructor',
				'youtube_link'      => 'youtube_link',
				'course_duration'   => 'course_duration',
				'total_enrollment'  => 'total_enrollment',
			)
		);
	}

	/**
	 * Fields that must be present when creating a new course.
	 *
	 * @return string[] request field names.
	 */
	protected function required_on_create() {
		return array( 'title', 'slug', 'course_start_date' );
	}

	/**
	 * Register the REST route.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE, // POST.
					'callback'            => array( $this, 'upsert_item' ),
					'permission_callback' => array( $this, 'upsert_permissions_check' ),
					'args'                => $this->get_endpoint_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Argument schema for the upsert endpoint.
	 *
	 * Only `openedx_course_id` is schema-required (it's needed on every request
	 * to locate the course). Fields that are required only when *creating* are
	 * left optional here and enforced in the create branch, so partial updates
	 * are not blocked — same pattern used for programs.
	 *
	 * @return array
	 */
	public function get_endpoint_args() {
		return array(
			'title'             => array(
				'description'       => __( 'Course title. Required on create.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'           => array(
				'description'       => __( 'Course content (HTML allowed).', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'wp_kses_post',
			),
			'slug'              => array(
				'description'       => __( 'Course slug (post_name). Required on create, ignored on update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'status'            => array(
				'description'       => __( 'Post status: publish or draft. Applied on create and update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => array( 'publish', 'draft' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'openedx_course_id' => array(
				'description'       => __( 'Open edX course id. Unique upsert key; immutable on update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_non_empty' ),
			),
			'short_description' => array(
				'description'       => __( 'Short description. Required on create.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'course_start_date' => array(
				'description'       => __( 'Course start date. Required on create.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'course_end_date'   => array(
				'description'       => __( 'Course end date. Required on create.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'instructor'        => array(
				'description'       => __( 'Instructor name.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'youtube_link'      => array(
				'description'       => __( 'YouTube URL.', 'tutor-sso' ),
				'type'              => 'string',
				'format'            => 'uri',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'course_duration'   => array(
				'description'       => __( 'Course duration.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'total_enrollment'  => array(
				'description'       => __( 'Total enrollment count.', 'tutor-sso' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'category'          => array(
				'description'       => __( 'course-category name(s) — string or array. Created if missing.', 'tutor-sso' ),
				'required'          => false,
			),
			'org'               => array(
				'description'       => __( 'Organization → course-partner term name. Created if missing.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'featured_image'    => array(
				'description'       => __( 'URL of an image to download and set as the featured image.', 'tutor-sso' ),
				'type'              => 'string',
				'format'            => 'uri',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
			'instructor_image'  => array(
				'description'       => __( 'URL of the instructor image to download into the ACF image field.', 'tutor-sso' ),
				'type'              => 'string',
				'format'            => 'uri',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
			),
		);
	}

	/**
	 * Validate that a required string is not empty after sanitizing.
	 *
	 * @param mixed $value Field value.
	 * @return bool
	 */
	public function validate_non_empty( $value ) {
		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Permission check. The JWT plugin has already authenticated the request
	 * into a WordPress user (or left it anonymous) before this runs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function upsert_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'tutor_sso_rest_unauthorized',
				__( 'Authentication required. Send a valid JWT as an Authorization: Bearer header.', 'tutor-sso' ),
				array( 'status' => 401 )
			);
		}

		/**
		 * Filter the capability required to create/update courses via the API.
		 *
		 * @param string           $capability Default 'edit_posts'.
		 * @param \WP_REST_Request $request    The current request.
		 */
		$capability = apply_filters( 'tutor_sso_course_api_capability', 'edit_posts', $request );

		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'tutor_sso_rest_forbidden',
				__( 'You do not have permission to manage courses.', 'tutor-sso' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Create or update a course from the request body.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upsert_item( $request ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return new \WP_Error(
				'tutor_sso_no_post_type',
				/* translators: %s: post type slug */
				sprintf( __( 'The "%s" post type is not registered on this site.', 'tutor-sso' ), self::POST_TYPE ),
				array( 'status' => 500 )
			);
		}

		$openedx_id  = $request->get_param( 'openedx_course_id' );
		$slug        = $request->get_param( 'slug' );
		$status      = $request->get_param( 'status' );
		$existing_id = $this->find_course_by_openedx_id( $openedx_id );

		// ── Assemble the post fields ───────────────────────────────────────────
		$postarr = array( 'post_type' => self::POST_TYPE );

		if ( null !== $request->get_param( 'title' ) ) {
			$postarr['post_title'] = $request->get_param( 'title' );
		}
		if ( null !== $request->get_param( 'content' ) ) {
			$postarr['post_content'] = $request->get_param( 'content' );
		}
		if ( $status ) {
			$postarr['post_status'] = $status;
		}

		if ( $existing_id ) {
			// slug is set once at creation and left untouched on updates so the
			// permalink stays stable — `slug` in the body is ignored here.
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			// Enforce create-only required fields.
			$missing = $this->missing_required_fields( $request );
			if ( $missing ) {
				return new \WP_Error(
					'tutor_sso_missing_fields',
					/* translators: %s: comma-separated field names */
					sprintf( __( 'Missing required fields on create: %s', 'tutor-sso' ), implode( ', ', $missing ) ),
					array( 'status' => 400 )
				);
			}

			// Reject a slug already taken by another course before creating.
			if ( $this->find_course_by_slug( $slug ) ) {
				return new \WP_Error(
					'tutor_sso_slug_exists',
					/* translators: %s: slug */
					sprintf( __( 'A course with the slug "%s" already exists.', 'tutor-sso' ), $slug ),
					array( 'status' => 409 )
				);
			}

			$postarr['post_name'] = $slug;

			if ( ! isset( $postarr['post_status'] ) ) {
				/**
				 * Filter the post status new courses are created with.
				 *
				 * @param string $status Default 'publish'.
				 */
				$postarr['post_status'] = apply_filters( 'tutor_sso_course_default_status', 'publish' );
			}

			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'tutor_sso_save_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		// ── Custom (ACF) fields ────────────────────────────────────────────────
		$this->save_fields( $post_id, $request, (bool) $existing_id );

		// ── Taxonomies (only when supplied) ─────────────────────────────────────
		if ( null !== $request->get_param( 'category' ) ) {
			$this->assign_terms_by_name( $post_id, self::TAX_CATEGORY, $request->get_param( 'category' ) );
		}
		if ( null !== $request->get_param( 'org' ) ) {
			$this->assign_terms_by_name( $post_id, self::TAX_PARTNER, $request->get_param( 'org' ) );
		}

		// ── Images ───────────────────────────────────────────────────────────
		$image_errors = array();

		$featured = $request->get_param( 'featured_image' );
		if ( ! empty( $featured ) ) {
			$result = $this->set_featured_image_from_url( $post_id, $featured );
			if ( is_wp_error( $result ) ) {
				$image_errors['featured_image'] = $result->get_error_message();
			}
		}

		$instructor_img = $request->get_param( 'instructor_image' );
		if ( ! empty( $instructor_img ) ) {
			$result = $this->set_instructor_image_from_url( $post_id, $instructor_img );
			if ( is_wp_error( $result ) ) {
				$image_errors['instructor_image'] = $result->get_error_message();
			}
		}

		$response_data = array(
			'id'                => $post_id,
			'action'            => $existing_id ? 'updated' : 'created',
			'openedx_course_id' => $openedx_id,
			'link'              => get_permalink( $post_id ),
		);

		if ( $image_errors ) {
			$response_data['image_errors'] = $image_errors;
		}

		$response = rest_ensure_response( $response_data );
		$response->set_status( $existing_id ? 200 : 201 );

		return $response;
	}

	/**
	 * Return the list of create-only required fields absent from the request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return string[] Missing field names.
	 */
	protected function missing_required_fields( $request ) {
		$missing = array();

		foreach ( $this->required_on_create() as $field ) {
			$value = $request->get_param( $field );
			if ( null === $value || '' === $value ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	/**
	 * Find an existing course by its Open edX course id.
	 *
	 * @param string $openedx_id Open edX course id.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_course_by_openedx_id( $openedx_id ) {
		$meta_key = self::acf_field_map()['openedx_course_id'];

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => $meta_key,
						'value' => $openedx_id,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Find an existing course by its slug (post_name).
	 *
	 * @param string $slug Course slug.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_course_by_slug( $slug ) {
		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'name'             => $slug,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Persist the custom (ACF) fields. Prefers ACF's update_field() and falls
	 * back to raw post meta when ACF is inactive.
	 *
	 * @param int              $post_id   Course post ID.
	 * @param \WP_REST_Request $request   Request object.
	 * @param bool             $is_update Whether this is an update.
	 */
	protected function save_fields( $post_id, $request, $is_update = false ) {
		$use_acf = function_exists( 'update_field' );

		// The upsert key is set once at creation and never changed on update.
		$immutable = array( 'openedx_course_id' );

		foreach ( self::acf_field_map() as $param => $field_name ) {
			if ( null === $request->get_param( $param ) ) {
				continue;
			}

			if ( $is_update && in_array( $param, $immutable, true ) ) {
				continue;
			}

			$value = $request->get_param( $param );

			if ( $use_acf ) {
				update_field( $field_name, $value, $post_id );
			} else {
				update_post_meta( $post_id, $field_name, $value );
			}
		}
	}

	/**
	 * Assign taxonomy terms by name, creating any that do not yet exist.
	 *
	 * @param int          $post_id  Post ID.
	 * @param string       $taxonomy Taxonomy slug.
	 * @param string|array $names    Term name, comma-separated names, or array of names.
	 */
	protected function assign_terms_by_name( $post_id, $taxonomy, $names ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		// Accept a single string, a comma-separated string, or an array.
		if ( is_string( $names ) ) {
			$names = explode( ',', $names );
		}

		$term_ids = array();

		foreach ( (array) $names as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}

			$name = trim( sanitize_text_field( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}

			$term = get_term_by( 'name', $name, $taxonomy );

			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			} else {
				$created = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
					$term_ids[] = (int) $created['term_id'];
				}
			}
		}

		// Replace the course's terms for this taxonomy with the resolved set.
		wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
	}

	/**
	 * Sideload an image URL into the media library, reusing an existing
	 * attachment when the same URL was already downloaded (by any course or
	 * program) rather than storing a duplicate copy.
	 *
	 * @param int    $post_id Post to attach a freshly-downloaded image to.
	 * @param string $url     Image URL.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	protected function sideload_image( $post_id, $url ) {
		return \TutorSSO\sideload_image_deduped( $url, $post_id );
	}

	/**
	 * Download an image URL and set it as the post's featured image, skipping
	 * the download when the source URL is unchanged.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	protected function set_featured_image_from_url( $post_id, $url ) {
		$previous = get_post_meta( $post_id, self::FEATURED_IMAGE_META, true );
		if ( $previous === $url && has_post_thumbnail( $post_id ) ) {
			return (int) get_post_thumbnail_id( $post_id );
		}

		$attachment_id = $this->sideload_image( $post_id, $url );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::FEATURED_IMAGE_META, $url );

		return (int) $attachment_id;
	}

	/**
	 * Download the instructor image and store its attachment id in the ACF
	 * image field, skipping the download when the source URL is unchanged.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	protected function set_instructor_image_from_url( $post_id, $url ) {
		$previous = get_post_meta( $post_id, self::INSTRUCTOR_IMAGE_META, true );
		if ( $previous === $url ) {
			$existing = get_post_meta( $post_id, self::INSTRUCTOR_IMAGE_FIELD, true );
			if ( $existing ) {
				return (int) $existing;
			}
		}

		$attachment_id = $this->sideload_image( $post_id, $url );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( function_exists( 'update_field' ) ) {
			update_field( self::INSTRUCTOR_IMAGE_FIELD, $attachment_id, $post_id );
		} else {
			update_post_meta( $post_id, self::INSTRUCTOR_IMAGE_FIELD, $attachment_id );
		}

		update_post_meta( $post_id, self::INSTRUCTOR_IMAGE_META, $url );

		return (int) $attachment_id;
	}

	/**
	 * Public schema for the course resource.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'course',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'Course post ID.', 'tutor-sso' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'action'            => array(
					'description' => __( 'Whether the course was created or updated.', 'tutor-sso' ),
					'type'        => 'string',
					'enum'        => array( 'created', 'updated' ),
					'readonly'    => true,
				),
				'openedx_course_id' => array(
					'description' => __( 'Open edX course id.', 'tutor-sso' ),
					'type'        => 'string',
				),
				'link'              => array(
					'description' => __( 'Course permalink.', 'tutor-sso' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
