<?php
/**
 * REST API for creating / updating "program" posts.
 *
 * Exposes a single upsert endpoint:
 *
 *   POST /wp-json/rwaq/v1/programs
 *
 * Authentication is delegated to the "JWT Authentication for WP REST API"
 * plugin. That plugin validates the `Authorization: Bearer <token>` header via
 * the `determine_current_user` filter and sets the current WordPress user
 * *before* our permission_callback runs — so here we only check capabilities,
 * never signatures. To obtain a token the client first calls the plugin's own
 * endpoint:
 *
 *   POST /wp-json/jwt-auth/v1/token   { "username": "...", "password": "..." }
 *
 * (Requires JWT_AUTH_SECRET_KEY defined in wp-config.php.)
 *
 * Accepted body fields (JSON or form-encoded):
 *   - name                 → post title            (required)
 *   - slug                 → post slug (post_name)  (required on create; ignored on update)
 *   - program_key          → ACF field, upsert key (required)
 *   - uuid                 → ACF field
 *   - organization         → ACF field
 *   - start_date           → ACF field
 *   - status               → post status (publish|draft)
 *   - featured_image_url   → sideloaded + set as the post's featured image
 *
 * Create vs. update is decided by `program_key`: if a program with that key
 * already exists it is updated, otherwise a new one is created.
 *
 * @package tutor-sso
 */

namespace TutorSSO\Programs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert controller for the `program` custom post type.
 */
class Programs_REST_Controller extends \WP_REST_Controller {

	/**
	 * The custom post type this endpoint manages.
	 *
	 * @var string
	 */
	const POST_TYPE = 'program';

	/**
	 * Meta key used to remember the source URL of the featured image, so we can
	 * skip re-downloading it on updates when it has not changed.
	 *
	 * @var string
	 */
	const IMAGE_SOURCE_META = '_program_featured_image_source';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'rwaq/v1';
		$this->rest_base = 'programs';
	}

	/**
	 * Map incoming body fields → ACF field names (post meta keys).
	 *
	 * `name` maps to the post title and `featured_image_url` is handled
	 * separately (sideloaded), so neither appears here. Override the ACF slugs
	 * via the `tutor_sso_program_acf_fields` filter if your field group uses
	 * different names.
	 *
	 * @return array<string,string> request_field => acf_field_name
	 */
	public static function acf_field_map() {
		/**
		 * Filter the request-field → ACF-field-name map.
		 *
		 * @param array<string,string> $map Default field map.
		 */
		return apply_filters(
			'tutor_sso_program_acf_fields',
			array(
				'program_key'  => 'program_key',
				'uuid'         => 'uuid',
				'organization' => 'organization',
				'start_date'   => 'start_date',
			)
		);
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
	 * @return array
	 */
	public function get_endpoint_args() {
		return array(
			'name'              => array(
				'description'       => __( 'Program name — used as the post title.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_non_empty' ),
			),
			'slug'              => array(
				'description'       => __( 'Program slug (post_name). Required on create, ignored on update. Must be unique among programs.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'program_key'       => array(
				'description'       => __( 'Unique program key. Determines create vs. update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_non_empty' ),
			),
			'uuid'              => array(
				'description'       => __( 'Program UUID.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'organization'      => array(
				'description'       => __( 'Owning organization.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'start_date'        => array(
				'description'       => __( 'Program start date (e.g. YYYY-MM-DD or ISO 8601).', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'            => array(
				'description'       => __( 'Post status: publish or draft. Applied on both create and update.', 'tutor-sso' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => array( 'publish', 'draft' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'featured_image_url' => array(
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
	 * Permission check. By the time this runs the JWT plugin has already
	 * resolved the bearer token to a WordPress user (or left the request
	 * anonymous), so we only need to confirm the user may edit programs.
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
		 * Filter the capability required to create/update programs via the API.
		 *
		 * @param string           $capability Default 'edit_posts'.
		 * @param \WP_REST_Request $request    The current request.
		 */
		$capability = apply_filters( 'tutor_sso_program_api_capability', 'edit_posts', $request );

		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'tutor_sso_rest_forbidden',
				__( 'You do not have permission to manage programs.', 'tutor-sso' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Create or update a program from the request body.
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

		$name        = $request->get_param( 'name' );
		$program_key = $request->get_param( 'program_key' );
		$slug        = $request->get_param( 'slug' );
		$status      = $request->get_param( 'status' );

		$existing_id = $this->find_program_by_key( $program_key );

		// ── Insert or update the post itself ───────────────────────────────────
		$postarr = array(
			'post_type'  => self::POST_TYPE,
			'post_title' => $name,
		);

		// Apply the requested status when provided (works on create and update).
		if ( $status ) {
			$postarr['post_status'] = $status;
		}

		if ( $existing_id ) {
			// The slug is set once at creation and left untouched on updates so
			// existing permalinks stay stable — `slug` in the body is ignored here.
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			// A slug is required when creating a new program.
			if ( empty( $slug ) ) {
				return new \WP_Error(
					'tutor_sso_missing_slug',
					__( 'A slug is required when creating a program.', 'tutor-sso' ),
					array( 'status' => 400 )
				);
			}

			// Reject a slug already taken by another program before creating.
			if ( $this->find_program_by_slug( $slug ) ) {
				return new \WP_Error(
					'tutor_sso_slug_exists',
					/* translators: %s: slug */
					sprintf( __( 'A program with the slug "%s" already exists.', 'tutor-sso' ), $slug ),
					array( 'status' => 409 )
				);
			}

			$postarr['post_name'] = $slug;

			// Fall back to the default status only when none was supplied.
			if ( ! isset( $postarr['post_status'] ) ) {
				/**
				 * Filter the post status new programs are created with.
				 *
				 * @param string $status Default 'publish'.
				 */
				$postarr['post_status'] = apply_filters( 'tutor_sso_program_default_status', 'publish' );
			}

			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'tutor_sso_save_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// ── Custom (ACF) fields ────────────────────────────────────────────────
		$this->save_fields( $post_id, $request, (bool) $existing_id );

		// ── Featured image ─────────────────────────────────────────────────────
		$image_url    = $request->get_param( 'featured_image_url' );
		$image_result = null;
		if ( ! empty( $image_url ) ) {
			$image_result = $this->set_featured_image_from_url( $post_id, $image_url );
		}

		$response_data = array(
			'id'          => $post_id,
			'action'      => $existing_id ? 'updated' : 'created',
			'program_key' => $program_key,
			'link'        => get_permalink( $post_id ),
		);

		// Surface image trouble without failing the whole request — the program
		// data was saved; only the thumbnail could not be fetched.
		if ( is_wp_error( $image_result ) ) {
			$response_data['featured_image_error'] = $image_result->get_error_message();
		}

		$response = rest_ensure_response( $response_data );
		$response->set_status( $existing_id ? 200 : 201 );

		return $response;
	}

	/**
	 * Find an existing program by its program key.
	 *
	 * @param string $program_key Program key.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_program_by_key( $program_key ) {
		$meta_key = self::acf_field_map()['program_key'];

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
						'value' => $program_key,
					),
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Find an existing program by its slug (post_name).
	 *
	 * @param string $slug Program slug.
	 * @return int Post ID, or 0 when none matches.
	 */
	protected function find_program_by_slug( $slug ) {
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
	 * Persist the custom fields, preferring ACF's update_field() (so ACF's
	 * value formatting + field-key references are written) and falling back to
	 * raw post meta when ACF is not active.
	 *
	 * @param int              $post_id   Program post ID.
	 * @param \WP_REST_Request $request   Request object.
	 * @param bool             $is_update Whether this is an update of an existing program.
	 */
	protected function save_fields( $post_id, $request, $is_update = false ) {
		$use_acf = function_exists( 'update_field' );

		// Identifiers set once at creation and never changed on update.
		$immutable = array( 'program_key', 'uuid' );

		foreach ( self::acf_field_map() as $param => $field_name ) {
			// Only write fields that were actually sent, so a partial update
			// does not wipe existing values.
			if ( null === $request->get_param( $param ) ) {
				continue;
			}

			// Leave identifiers untouched when updating an existing program.
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
	 * featured image. Skips the download when the same source URL is already
	 * the featured image, and reuses an existing attachment when the same URL
	 * was already downloaded (by any program or course) instead of storing a
	 * duplicate copy.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image URL.
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	protected function set_featured_image_from_url( $post_id, $url ) {
		// Nothing to do if the source URL is unchanged and a thumbnail is set.
		$previous = get_post_meta( $post_id, self::IMAGE_SOURCE_META, true );
		if ( $previous === $url && has_post_thumbnail( $post_id ) ) {
			return (int) get_post_thumbnail_id( $post_id );
		}

		$attachment_id = \TutorSSO\sideload_image_deduped( $url, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::IMAGE_SOURCE_META, $url );

		return (int) $attachment_id;
	}

	/**
	 * Public schema for the program resource (returned by OPTIONS requests).
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'program',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Program post ID.', 'tutor-sso' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'action'      => array(
					'description' => __( 'Whether the program was created or updated.', 'tutor-sso' ),
					'type'        => 'string',
					'enum'        => array( 'created', 'updated' ),
					'readonly'    => true,
				),
				'program_key' => array(
					'description' => __( 'Program key.', 'tutor-sso' ),
					'type'        => 'string',
				),
				'link'        => array(
					'description' => __( 'Program permalink.', 'tutor-sso' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
