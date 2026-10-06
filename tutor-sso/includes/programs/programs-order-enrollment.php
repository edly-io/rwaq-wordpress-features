<?php
/**
 * Enroll buyers into programs when their WooCommerce order is paid, and record
 * each attempt so it can be seen and retried in wp-admin.
 *
 * Open edX Commerce does this for courses: its order handler picks up items
 * flagged `is_openedx_course` and enrolls through eox-core. It has no concept of
 * a program, so program items pass through it untouched and are handled here
 * instead — a separate hook on the same WooCommerce actions, a separate post
 * type, and no call into its code. Nothing here can affect course enrollments.
 *
 * Records live in the `rwaq_program_enroll` post type, shown as "Program
 * Enrollments" under Open edX Commerce's own Enrollment Requests menu. The
 * column keys are namespaced because that plugin filters
 * `manage_posts_custom_column` globally and matches on column name alone.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type holding one record per (order, program) enrollment attempt.
 * WordPress caps post type names at 20 characters.
 */
const PROGRAM_ENROLLMENT_POST_TYPE = 'rwaq_program_enroll';

/**
 * Open edX Commerce's enrollment post type; the menu we nest under.
 */
const OEC_ENROLLMENT_POST_TYPE = 'openedx_enrollment';

/**
 * Whether Open edX Commerce is present. The whole feature is gated on it, so
 * with the plugin gone the menu entry disappears with it.
 *
 * @return bool
 */
function program_enrollment_manager_active() {
	return post_type_exists( OEC_ENROLLMENT_POST_TYPE );
}

/**
 * Register the records post type, nested under the Enrollment Requests menu.
 */
function program_enrollment_register_post_type() {
	register_post_type(
		PROGRAM_ENROLLMENT_POST_TYPE,
		array(
			'labels'          => array(
				'name'          => __( 'Program Enrollments', 'tutor-sso' ),
				'singular_name' => __( 'Program Enrollment', 'tutor-sso' ),
				'menu_name'     => __( 'Program Enrollments', 'tutor-sso' ),
			),
			'public'          => false,
			'show_ui'         => true,
			// Nested under Open edX Commerce's menu; hidden entirely without it.
			'show_in_menu'    => program_enrollment_manager_active()
				? 'edit.php?post_type=' . OEC_ENROLLMENT_POST_TYPE
				: false,
			'capability_type' => 'post',
			'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'    => true,
			'supports'        => array( 'title' ),
			'hierarchical'    => false,
			'has_archive'     => false,
			'rewrite'         => false,
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\program_enrollment_register_post_type', 11 );

/**
 * Read a record's status: 'pending' | 'success' | 'error'.
 *
 * @param int $post_id Record ID.
 * @return string
 */
function program_enrollment_status( $post_id ) {
	$status = (string) get_post_meta( (int) $post_id, 'status', true );

	return '' !== $status ? $status : 'pending';
}

/**
 * Find the record for an (order, program) pair.
 *
 * @param int    $order_id    WooCommerce order ID.
 * @param string $program_key Program key.
 * @return int Record ID, or 0.
 */
function program_enrollment_find( $order_id, $program_key ) {
	$ids = get_posts(
		array(
			'post_type'        => PROGRAM_ENROLLMENT_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => 'order_id',
					'value' => (int) $order_id,
				),
				array(
					'key'   => 'program_key',
					'value' => (string) $program_key,
				),
			),
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Create or update the record for an (order, program) pair.
 *
 * @param array $args { order_id, program_key, user_id, email, username }.
 * @return int Record ID, or 0 on failure.
 */
function program_enrollment_record( $args ) {
	$order_id    = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
	$program_key = isset( $args['program_key'] ) ? trim( (string) $args['program_key'] ) : '';

	if ( ! $order_id || '' === $program_key ) {
		return 0;
	}

	$post_id = program_enrollment_find( $order_id, $program_key );

	/* translators: 1: program key, 2: order ID. */
	$title = sprintf( __( '%1$s — order #%2$d', 'tutor-sso' ), $program_key, $order_id );

	if ( $post_id ) {
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);
	} else {
		$post_id = wp_insert_post(
			array(
				'post_type'   => PROGRAM_ENROLLMENT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, 'order_id', $order_id );
	update_post_meta( $post_id, 'program_key', $program_key );

	foreach ( array( 'user_id', 'email', 'username', 'program_uuid' ) as $key ) {
		if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
			update_post_meta( $post_id, $key, $args[ $key ] );
		}
	}

	return (int) $post_id;
}

/**
 * Store the outcome of an attempt on its record.
 *
 * @param int    $post_id Record ID.
 * @param string $status  'pending' | 'success' | 'error'.
 * @param string $message Human-readable detail.
 */
function program_enrollment_set_status( $post_id, $status, $message = '' ) {
	update_post_meta( (int) $post_id, 'status', (string) $status );
	update_post_meta( (int) $post_id, 'message', (string) $message );
	update_post_meta( (int) $post_id, 'updated', current_time( 'mysql' ) );
}


/**
 * Base URL for the admin API, from the credentials Open edX Commerce stores.
 *
 * @return string Without a trailing slash, or ''.
 */
function program_enrollment_api_base() {
	$base = rtrim( (string) get_option( 'openedx-domain' ), '/' );

	if ( '' === $base ) {
		$base = enroll_lms_base_url();
	}

	/**
	 * Filter the host the program bulk-enroll endpoint is called on.
	 *
	 * @param string $base Base URL, no trailing slash.
	 */
	return (string) apply_filters( 'tutor_sso_program_enroll_api_base', $base );
}

/**
 * Enroll one email into a program, server to server.
 *
 * POST /api/v1/admin/programs/{uuid}/bulk-enroll/ with a service JWT. The
 * learner is also enrolled into every course the program contains, and `emails`
 * is a single comma-separated string rather than a list.
 *
 * @param string $program_uuid Program uuid.
 * @param string $email        Learner email.
 * @param int    $order_id     Order the enrollment is for, sent as the reason.
 * @return true|\WP_Error
 */
function program_enrollment_request( $program_uuid, $email, $order_id = 0 ) {
	$program_uuid = trim( (string) $program_uuid );
	$email        = trim( (string) $email );

	if ( '' === $program_uuid ) {
		return new \WP_Error( 'tutor_sso_no_program_uuid', __( 'No program uuid for this product.', 'tutor-sso' ) );
	}

	if ( '' === $email ) {
		return new \WP_Error( 'tutor_sso_no_email', __( 'No buyer email on this order.', 'tutor-sso' ) );
	}

	$base = program_enrollment_api_base();

	if ( '' === $base ) {
		return new \WP_Error( 'tutor_sso_no_base', __( 'LMS Base URL is not configured.', 'tutor-sso' ) );
	}

	$token = sso_lms_service_token();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$body = array( 'emails' => $email );

	if ( $order_id ) {
		/* translators: %d: WooCommerce order ID. */
		$body['reason'] = sprintf( __( 'WooCommerce order %d', 'tutor-sso' ), (int) $order_id );
	}

	$response = wp_remote_post(
		$base . '/api/v1/admin/programs/' . rawurlencode( $program_uuid ) . '/bulk-enroll/',
		array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'tutor_sso_ssl_verify', true ),
			'headers'   => array(
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Authorization' => 'JWT ' . $token,
			),
			'body'      => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( $status < 200 || $status >= 300 ) {
		// A stale cached token is the one failure worth retrying automatically.
		if ( 401 === $status ) {
			delete_transient( 'tutor_sso_service_token' );
		}

		return new \WP_Error(
			'tutor_sso_program_enroll_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: response body. */
				__( 'The LMS rejected the enrollment (HTTP %1$d): %2$s', 'tutor-sso' ),
				$status,
				wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) )
			)
		);
	}

	return true;
}

/**
 * Attempt the enrollment for a record and store the outcome.
 *
 * Runs server to server with the stored API credentials, so it does not need the
 * buyer present — an order paid by webhook or marked paid in wp-admin enrolls
 * just the same.
 *
 * @param int $post_id Record ID.
 * @return bool Whether the enrollment succeeded.
 */
function program_enrollment_process( $post_id ) {
	$post_id = (int) $post_id;

	$program_uuid = (string) get_post_meta( $post_id, 'program_uuid', true );
	$email        = (string) get_post_meta( $post_id, 'email', true );
	$order_id     = (int) get_post_meta( $post_id, 'order_id', true );

	$result = program_enrollment_request( $program_uuid, $email, $order_id );

	if ( is_wp_error( $result ) ) {
		program_enrollment_set_status( $post_id, 'error', $result->get_error_message() );


		return false;
	}

	program_enrollment_set_status( $post_id, 'success', __( 'Enrolled.', 'tutor-sso' ) );


	return true;
}

/**
 * Enroll the buyer into every program in a paid order.
 *
 * Items with no program key — course products, everything else — are skipped,
 * so Open edX Commerce keeps handling its own.
 *
 * @param int $order_id WooCommerce order ID.
 */
function program_enrollment_handle_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	// Runs during checkout: a failure here must not blank the page.
	try {
		program_enrollment_handle_order_items( $order_id );
	} catch ( \Throwable $e ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( '[tutor-sso] program enrollment for order %d crashed: %s', (int) $order_id, $e->getMessage() )
		);


	}
}

/**
 * Enroll the buyer into every program in the order.
 *
 * @param int $order_id WooCommerce order ID.
 */
function program_enrollment_handle_order_items( $order_id ) {

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$user_id = (int) $order->get_user_id();
	$user    = $user_id ? get_userdata( $user_id ) : null;

	foreach ( $order->get_items() as $item ) {
		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;

		if ( ! $product_id ) {
			continue;
		}

		if ( ! program_product_is_program( $product_id ) ) {
			continue;
		}

		$program_key = trim( (string) get_post_meta( $product_id, PROGRAM_PRODUCT_KEY_META, true ) );

		if ( '' === $program_key ) {
			continue;
		}

		$post_id = program_enrollment_record(
			array(
				'order_id'     => (int) $order_id,
				'program_key'  => $program_key,
				'program_uuid' => program_product_uuid( $product_id, $program_key ),
				'user_id'      => $user_id,
				// Billing email, as the course flow uses — the buyer may have no
				// WordPress account matching their LMS one.
				'email'        => (string) $order->get_billing_email(),
				'username'     => $user ? $user->user_login : '',
			)
		);

		if ( ! $post_id ) {
			continue;
		}

		// Already enrolled by an earlier status change: do not ask twice.
		if ( 'success' === program_enrollment_status( $post_id ) ) {
			continue;
		}

		program_enrollment_process( $post_id );
	}
}
add_action( 'woocommerce_order_status_processing', __NAMESPACE__ . '\\program_enrollment_handle_order', 10, 1 );
add_action( 'woocommerce_order_status_completed', __NAMESPACE__ . '\\program_enrollment_handle_order', 10, 1 );

/**
 * Columns for the Program Enrollments list. Namespaced keys: Open edX Commerce
 * filters `manage_posts_custom_column` globally and matches on name alone.
 *
 * @param array $columns Default columns.
 * @return array
 */
function program_enrollment_columns( $columns ) {
	return array(
		'cb'              => isset( $columns['cb'] ) ? $columns['cb'] : '',
		'title'           => __( 'Request', 'tutor-sso' ),
		'rwaq_program'    => __( 'Program', 'tutor-sso' ),
		'rwaq_user'       => __( 'User', 'tutor-sso' ),
		'rwaq_order'      => __( 'Order', 'tutor-sso' ),
		'rwaq_status'     => __( 'Status', 'tutor-sso' ),
		'rwaq_message'    => __( 'Message', 'tutor-sso' ),
		'rwaq_updated'    => __( 'Updated', 'tutor-sso' ),
	);
}
add_filter( 'manage_' . PROGRAM_ENROLLMENT_POST_TYPE . '_posts_columns', __NAMESPACE__ . '\\program_enrollment_columns' );

/**
 * Fill one cell of the Program Enrollments list.
 *
 * @param string $column  Column key.
 * @param int    $post_id Record ID.
 */
function program_enrollment_column( $column, $post_id ) {
	if ( PROGRAM_ENROLLMENT_POST_TYPE !== get_post_type( $post_id ) ) {
		return;
	}

	switch ( $column ) {
		case 'rwaq_program':
			echo esc_html( (string) get_post_meta( $post_id, 'program_key', true ) );
			break;

		case 'rwaq_user':
			$user_id = (int) get_post_meta( $post_id, 'user_id', true );
			$user    = $user_id ? get_userdata( $user_id ) : null;
			echo esc_html( $user ? $user->user_login : (string) get_post_meta( $post_id, 'email', true ) );
			break;

		case 'rwaq_order':
			$order_id = (int) get_post_meta( $post_id, 'order_id', true );
			if ( $order_id ) {
				printf(
					'<a href="%s">#%d</a>',
					esc_url( get_edit_post_link( $order_id ) ? get_edit_post_link( $order_id ) : '#' ),
					$order_id // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}
			break;

		case 'rwaq_status':
			$status = program_enrollment_status( $post_id );
			$colors = array(
				'success' => 'green',
				'error'   => 'red',
				'pending' => 'orange',
			);
			printf(
				'<b style="color:%s;">%s</b>',
				esc_attr( isset( $colors[ $status ] ) ? $colors[ $status ] : 'inherit' ),
				esc_html( ucfirst( $status ) )
			);
			break;

		case 'rwaq_message':
			echo esc_html( (string) get_post_meta( $post_id, 'message', true ) );
			break;

		case 'rwaq_updated':
			echo esc_html( (string) get_post_meta( $post_id, 'updated', true ) );
			break;
	}
}
add_action( 'manage_' . PROGRAM_ENROLLMENT_POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\\program_enrollment_column', 10, 2 );

/**
 * Row actions for the list: a retry link, and nothing that implies editing.
 *
 * Re-added explicitly because Open edX Commerce filters `post_row_actions`
 * globally and strips edit/trash/view without checking the post type.
 *
 * @param array    $actions Row actions.
 * @param \WP_Post $post    Record.
 * @return array
 */
function program_enrollment_row_actions( $actions, $post ) {
	if ( PROGRAM_ENROLLMENT_POST_TYPE !== $post->post_type ) {
		return $actions;
	}

	$actions = array();

	if ( 'success' !== program_enrollment_status( $post->ID ) ) {
		$actions['rwaq_retry'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'post_type'          => PROGRAM_ENROLLMENT_POST_TYPE,
							'rwaq_retry_enroll'  => (int) $post->ID,
						),
						admin_url( 'edit.php' )
					),
					'rwaq_retry_enroll_' . $post->ID
				)
			),
			esc_html__( 'Retry', 'tutor-sso' )
		);
	}

	return $actions;
}
add_filter( 'post_row_actions', __NAMESPACE__ . '\\program_enrollment_row_actions', 20, 2 );

/**
 * Run a retry requested from the list.
 */
function program_enrollment_handle_retry() {
	if ( ! isset( $_GET['rwaq_retry_enroll'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$post_id = (int) $_GET['rwaq_retry_enroll']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $post_id || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	check_admin_referer( 'rwaq_retry_enroll_' . $post_id );

	if ( PROGRAM_ENROLLMENT_POST_TYPE === get_post_type( $post_id ) ) {
		program_enrollment_process( $post_id );
	}

	wp_safe_redirect( admin_url( 'edit.php?post_type=' . PROGRAM_ENROLLMENT_POST_TYPE ) );
	exit;
}
add_action( 'admin_init', __NAMESPACE__ . '\\program_enrollment_handle_retry' );
