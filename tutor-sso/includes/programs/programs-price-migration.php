<?php
/**
 * Carry program prices over from the old course_* field names.
 *
 * Programs used to store their prices in ACF fields named course_regular_price
 * and course_sale_price; they are now program_regular_price and
 * program_sale_price. Renaming a field in ACF does not move the values already
 * saved under the old name, so a program synced before the rename would show
 * empty price fields on its edit screen until the LMS next synced it.
 *
 * This copies each old value across, once, wherever the new field has never
 * been saved. It never touches a program_* value that exists — even an empty
 * one, which the LMS sends to end a sale — and it leaves the course_* values in
 * place: the product sync still falls back to them for a program_* that has
 * never been saved (program_product_price_field()), so nothing depends on this
 * having run.
 *
 * @package tutor-sso
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option recording that the copy has run.
 */
const PROGRAM_PRICE_MIGRATION_OPTION = 'tutor_sso_program_price_fields_migrated';

/**
 * Old field name => new field name.
 *
 * @return array<string,string>
 */
function program_price_migration_map() {
	return array(
		'course_regular_price' => 'program_regular_price',
		'course_sale_price'    => 'program_sale_price',
	);
}

/**
 * Whether the program_* ACF fields exist yet.
 *
 * Without ACF there are no fields to wait for: values live in post meta under
 * whatever name the code writes, which is already program_*.
 *
 * @return bool
 */
function program_price_fields_renamed() {
	if ( ! function_exists( 'acf_get_field' ) ) {
		return true;
	}

	foreach ( program_price_migration_map() as $new ) {
		if ( ! acf_get_field( $new ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Copy the old prices into the new fields, for every program that needs it.
 *
 * @return int Number of values copied.
 */
function program_price_migrate() {
	$ids = get_posts(
		array(
			'post_type'        => program_single_post_type(),
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => 'course_regular_price',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'course_sale_price',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$use_acf = function_exists( 'update_field' );
	$copied  = 0;

	foreach ( (array) $ids as $post_id ) {
		$post_id = (int) $post_id;

		foreach ( program_price_migration_map() as $old => $new ) {
			// Raw meta on both sides: after the rename there is no ACF field
			// called course_* to read through, and an empty new field is
			// exactly the case to fill.
			$value = get_post_meta( $post_id, $old, true );

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			// Never saved under the new name only. An empty program_* that exists
			// was cleared on purpose by the LMS (an ended sale sends ""), and
			// filling it would bring the old price back. Same rule as
			// program_product_price_field().
			if ( metadata_exists( 'post', $post_id, $new ) ) {
				continue;
			}

			// The same way the program sync writes these fields, so ACF's
			// field-key reference is stored alongside the value.
			if ( $use_acf ) {
				update_field( $new, $value, $post_id );
			} else {
				update_post_meta( $post_id, $new, $value );
			}

			++$copied;
		}
	}

	return $copied;
}

/**
 * Run the copy once, from the admin.
 *
 * Safe to run again — it only ever fills empty fields — so the flag is there to
 * avoid the query on every admin page load, not to guard against repeats.
 *
 * @return void
 */
function program_price_migrate_once() {
	if ( '1' === (string) get_option( PROGRAM_PRICE_MIGRATION_OPTION ) ) {
		return;
	}

	// Wait for the ACF rename. Until the program_* fields exist, the edit
	// screen still shows and saves course_* — and the product sync prefers a
	// non-empty program_* — so copying early would leave any price edited in
	// that window ignored. Not marked done, so it runs once they appear.
	if ( ! program_price_fields_renamed() ) {
		return;
	}

	program_price_migrate();
	update_option( PROGRAM_PRICE_MIGRATION_OPTION, '1', false );
}
add_action( 'admin_init', __NAMESPACE__ . '\\program_price_migrate_once' );
