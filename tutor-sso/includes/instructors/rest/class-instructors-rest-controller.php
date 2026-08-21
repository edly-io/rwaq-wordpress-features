<?php
/**
 * REST API for creating / updating "instructor" posts.
 *
 * Exposes a single upsert endpoint:
 *
 *   POST /wp-json/rwaq/v1/instructors
 *
 * Authentication is delegated to the "JWT Authentication for WP REST API"
 * plugin (see the programs controller for the full flow). This controller only
 * checks capabilities; the JWT plugin has already resolved the bearer token to
 * a WordPress user by the time the permission callback runs.
 *
 * Create vs. update is decided by `instructor_lms_id`: if an instructor with
 * that id already exists it is updated, otherwise a new one is created, so one
 * id maps to one post.
 *
 * Accepted body fields (JSON or form-encoded):
 *   - title             → post title            (required on create)
 *   - description       → post content
 *   - slug              → post slug (post_name)  (required on create; optional on update)
 *   - status            → post status (publish|draft)
 *   - instructor_lms_id → ACF field, upsert key  (required, immutable)
 *   - course_count      → ACF field
 *   - image             → sideloaded + set as the post's featured image
 *
 * @package tutor-sso
 */

namespace TutorSSO\Instructors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert controller for the `instructor` custom post type.
 */
class Instructors_REST_Controller extends \WP_REST_Controller {

	/** Remembers the source URL of the featured image to avoid re-downloading. */
	const FEATURED_IMAGE_META = '_instructor_featured_image_source';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'rwaq/v1';
		$this->rest_base = 'instructors';
	}

	/**
	 * The custom post type this endpoint manages.
	 *
	 * Shares the `tutor_sso_instructor_post_type` filter with the detail page so
	 * both sides always agree on the post type.
	 *
	 * @return string
	 */
	public static function post_type() {
		if ( function_exists( '\TutorSSO\instructor_single_post_type' ) ) {
			return \TutorSSO\instructor_single_post_type();
		}

		return 'instructor';
	}

	/**
	 * Map incoming body fields → ACF field names (post meta keys).
	 *
	 * Title/description/slug/status are post fields and `image` is sideloaded
	 * separately, so none of those appear here. Override the ACF slugs via the
	 * `tutor_sso_instructor_acf_fields` filter.
	 *
	 * @return array<string,string> request_field => acf_field_name
	 */
	public static function acf_field_map() {
		/**
		 * Filter the request-field → ACF-field-name map for instructors.
		 *
		 * @param array<string,string> $map Default field map.
		 */
		return apply_filters(
			'tutor_sso_instructor_acf_fields',
			array(
				'instructor_lms_id' => \TutorSSO\INSTRUCTOR_LMS_ID_FIELD,
				'course_count'      => 'course_count',
			)
		);
	}

	/**
	 * Fields that must be present when creating a new instructor.
	 *
	 * @return string[] request field names.
	 */
	protected function required_on_create() {
		return array( 'title', 'slug' );
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
	 * Argument schema for the upsert endpoint (drives validation + sanitizing).
	 *
	 * Only `instructor_lms_id` is schema-required (it locates the instructor on
	 * every request). `title` and `slug` are required only when *creating*, which
	 * is enforced in the create branch so partial updates are not blocked — same
	 * pattern used for courses and programs.
	 *
	 * @return array
	 */
	public function get_endpoint_args() {
		return array(
			'title'             => array(
				'description'       => __( 'Instructor name — used as the post title. Required on create.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'       => array(
				'description'       => __( 'Instructor description → post content (HTML allowed).', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'wp_kses_post',
			),
			'slug'              => array(
				'description'       => __( 'Instructor slug (post_name). Required on create; on update it is only changed when supplied.', 'tutor-sso' ),
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
			'instructor_lms_id' => array(
				'description'       => __( 'Numeric instructor id on the LMS. Unique upsert key; immutable on update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_non_empty' ),
			),
			'course_count'      => array(
				'description'       => __( 'Number of courses the instructor teaches.', 'tutor-sso' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'image'             => array(
				'description'       => __( 'URL of an image to download and set as the featured image.', 'tutor-sso' ),
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
		 * Filter the capability required to create/update instructors via the API.
		 *
		 * @param string           $capability Default 'edit_posts'.
		 * @param \WP_REST_Request $request    The current request.
		 */
		$capability = apply_filters( 'tutor_sso_instructor_api_capability', 'edit_posts', $request );

		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'tutor_sso_rest_forbidden',
				__( 'You do not have permission to manage instructors.', 'tutor-sso' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Create or update an instructor from the request body.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upsert_item( $request ) {
		$post_type = self::post_type();

		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'tutor_sso_no_post_type',
				/* translators: %s: post type slug */
				sprintf( __( 'The "%s" post type is not registered on this site.', 'tutor-sso' ), $post_type ),
				array( 'status' => 500 )
			);
		}

		$lms_id      = $request->get_param( 'instructor_lms_id' );
		$slug        = $request->get_param( 'slug' );
		$status      = $request->get_param( 'status' );
		$existing_id = $this->find_instructor_by_lms_id( $lms_id );

		// ── Assemble the post fields ───────────────────────────────────────────
		$postarr = array( 'post_type' => $post_type );

		if ( null !== $request->get_param( 'title' ) ) {
			$postarr['post_title'] = $request->get_param( 'title' );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$postarr['post_content'] = $request->get_param( 'description' );
		}
		if ( $status ) {
			$postarr['post_status'] = $status;
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;

			// The slug only moves when one is supplied; leaving it out of the body
			// keeps the existing permalink untouched.
			if ( ! empty( $slug ) ) {
				$conflict = $this->slug_conflict_error( $slug, $existing_id );
				if ( $conflict ) {
					return $conflict;
				}

				$postarr['post_name'] = $slug;
			}

			$post_id = wp_update_post( $postarr, true );
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

			// Reject a slug already taken by another instructor before creating.
			$conflict = $this->slug_conflict_error( $slug );
			if ( $conflict ) {
				return $conflict;
			}

			$postarr['post_name'] = $slug;

			// Fall back to the default status only when none was supplied.
			if ( ! isset( $postarr['post_status'] ) ) {
				/**
				 * Filter the post status new instructors are created with.
				 *
				 * @param string $status Default 'publish'.
				 */
				$postarr['post_status'] = apply_filters( 'tutor_sso_instructor_default_status', 'publish' );
			}

			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'tutor_sso_save_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		// ── Custom (ACF) fields ────────────────────────────────────────────────
		$this->save_fields( $post_id, $request, (bool) $existing_id );

		// ── Featured image ─────────────────────────────────────────────────────
		$image_url    = $request->get_param( 'image' );
		$image_result = null;
		if ( ! empty( $image_url ) ) {
			$image_result = $this->set_featured_image_from_url( $post_id, $image_url );
		}

		$response_data = array(
			'id'                => $post_id,
			'action'            => $existing_id ? 'updated' : 'created',
			'instructor_lms_id' => $lms_id,
			'link'              => get_permalink( $post_id ),
		);

		// Surface image trouble without failing the whole request — the
		// instructor data was saved; only the thumbnail could not be fetched.
		if ( is_wp_error( $image_result ) ) {
			$response_data['featured_image_error'] = $image_result->get_error_message();
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
	 * Find an existing instructor by its LMS id.
	 *
	 * @param string $lms_id LMS instructor id.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_instructor_by_lms_id( $lms_id ) {
		$ids = get_posts(
			array(
				'post_type'        => self::post_type(),
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::acf_field_map()['instructor_lms_id'],
						'value' => $lms_id,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Find an existing instructor by its slug (post_name).
	 *
	 * @param string $slug Instructor slug.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_instructor_by_slug( $slug ) {
		$ids = get_posts(
			array(
				'post_type'        => self::post_type(),
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
	 * Check whether a slug is already taken by another instructor.
	 *
	 * @param string $slug      Slug to check.
	 * @param int    $ignore_id Instructor to exclude from the lookup — the one
	 *                          being updated, so re-sending its own slug is not
	 *                          a clash.
	 * @return \WP_Error|null Error when the slug belongs to a different instructor.
	 */
	protected function slug_conflict_error( $slug, $ignore_id = 0 ) {
		$owner_id = $this->find_instructor_by_slug( $slug );

		if ( ! $owner_id || $owner_id === (int) $ignore_id ) {
			return null;
		}

		return new \WP_Error(
			'tutor_sso_slug_exists',
			/* translators: %s: slug */
			sprintf( __( 'An instructor with the slug "%s" already exists.', 'tutor-sso' ), $slug ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Persist the custom (ACF) fields. Prefers ACF's update_field() and falls
	 * back to raw post meta when ACF is inactive.
	 *
	 * @param int              $post_id   Instructor post ID.
	 * @param \WP_REST_Request $request   Request object.
	 * @param bool             $is_update Whether this is an update.
	 */
	protected function save_fields( $post_id, $request, $is_update = false ) {
		$use_acf = function_exists( 'update_field' );

		// The upsert key is set once at creation and never changed on update.
		$immutable = array( 'instructor_lms_id' );

		foreach ( self::acf_field_map() as $param => $field_name ) {
			// Only write fields that were actually sent, so a partial update
			// does not wipe existing values.
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
	 * Download an image URL into the media library and set it as the post's
	 * featured image. Skips the download when the source URL is unchanged, and
	 * reuses an existing attachment when the same URL was already downloaded
	 * (by any instructor, course or program) instead of storing a duplicate.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function set_featured_image_from_url( $post_id, $url ) {
		$previous = get_post_meta( $post_id, self::FEATURED_IMAGE_META, true );
		if ( $previous === $url && has_post_thumbnail( $post_id ) ) {
			return (int) get_post_thumbnail_id( $post_id );
		}

		$attachment_id = \TutorSSO\sideload_image_deduped( $url, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::FEATURED_IMAGE_META, $url );

		return (int) $attachment_id;
	}

	/**
	 * Public schema for the instructor resource (returned by OPTIONS requests).
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'instructor',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => __( 'Instructor post ID.', 'tutor-sso' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'action'            => array(
					'description' => __( 'Whether the instructor was created or updated.', 'tutor-sso' ),
					'type'        => 'string',
					'enum'        => array( 'created', 'updated' ),
					'readonly'    => true,
				),
				'instructor_lms_id' => array(
					'description' => __( 'LMS instructor id.', 'tutor-sso' ),
					'type'        => 'string',
				),
				'link'              => array(
					'description' => __( 'Instructor permalink.', 'tutor-sso' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
