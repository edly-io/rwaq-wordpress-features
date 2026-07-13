<?php
/**
 * Shared media helpers.
 *
 * Deduplicating image sideload: a remote image is downloaded into the Media
 * Library only once per source URL. Every later request for the same URL —
 * from any course or program — reuses that single attachment instead of
 * downloading and storing a duplicate copy.
 *
 * The first download tags its attachment with the source URL (see
 * SOURCE_URL_META). Subsequent lookups query that meta key to find and reuse
 * the existing attachment.
 *
 * @package TutorSSO
 */

namespace TutorSSO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attachment meta key recording the remote URL an image was sideloaded from.
 *
 * Acts as a global lookup key so a given source URL maps to a single
 * attachment across the whole site.
 */
const SOURCE_URL_META = '_tutor_sso_source_url';

/**
 * Find an existing attachment previously sideloaded from the given URL.
 *
 * Filtering is by the indexed `meta_key`, so only our own tagged attachments
 * are considered — a small set regardless of overall media-library size.
 *
 * @param string $url Remote source URL.
 * @return int Attachment ID, or 0 when none is found.
 */
function find_attachment_by_source_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 0;
	}

	$ids = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => SOURCE_URL_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $url,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	return ! empty( $ids ) ? (int) $ids[0] : 0;
}

/**
 * Reuse-or-download an image.
 *
 * Returns an existing attachment previously sideloaded from the same URL when
 * one is available (no network request); otherwise downloads the image, tags
 * the new attachment with its source URL for future reuse, and returns its id.
 *
 * @param string $url     Remote image URL.
 * @param int    $post_id Post to attach a freshly-downloaded image to. Only
 *                        used on a cache miss; reused attachments keep the
 *                        post_parent they were first created with.
 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
 */
function sideload_image_deduped( $url, $post_id ) {
	$existing = find_attachment_by_source_url( $url );
	if ( $existing ) {
		return $existing;
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Core media_sideload_image() only accepts a fixed set of raster extensions
	// (jpg/jpeg/png/gif/webp) and rejects everything else with "Invalid image
	// URL." before it even downloads. The subsequent MIME check would then also
	// reject SVG unless the site allows the image/svg+xml type for the acting
	// user (the "SVG Support" plugin gates that by role, and the API's JWT user
	// is typically not in an allowed role). So for an SVG URL we enable the
	// extension + MIME + filetype ourselves, scoped to this one call, then
	// remove the filters — nothing is widened globally. Sanitizing the SVG
	// markup remains the job of the site's SVG plugin.
	$is_svg = (bool) preg_match( '#\.svg(?:[?\#].*)?$#i', (string) $url );

	if ( $is_svg ) {
		add_filter( 'image_sideload_extensions', __NAMESPACE__ . '\\allow_svg_sideload_extension' );
		add_filter( 'upload_mimes', __NAMESPACE__ . '\\allow_svg_mime' );
		add_filter( 'wp_check_filetype_and_ext', __NAMESPACE__ . '\\fix_svg_filetype', 10, 3 );
	}

	$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

	if ( $is_svg ) {
		remove_filter( 'image_sideload_extensions', __NAMESPACE__ . '\\allow_svg_sideload_extension' );
		remove_filter( 'upload_mimes', __NAMESPACE__ . '\\allow_svg_mime' );
		remove_filter( 'wp_check_filetype_and_ext', __NAMESPACE__ . '\\fix_svg_filetype', 10 );
	}

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	update_post_meta( (int) $attachment_id, SOURCE_URL_META, esc_url_raw( $url ) );

	return (int) $attachment_id;
}

/**
 * Add `svg` to the extensions core media_sideload_image() will accept.
 *
 * Registered only for the duration of an SVG sideload (see
 * sideload_image_deduped()), so it never widens the whitelist globally.
 *
 * @param string[] $extensions Allowed image sideload extensions.
 * @return string[]
 */
function allow_svg_sideload_extension( $extensions ) {
	$extensions[] = 'svg';

	return $extensions;
}

/**
 * Register the image/svg+xml MIME type for the duration of an SVG sideload, so
 * WordPress's upload filetype check accepts the downloaded file.
 *
 * @param array<string,string> $mimes Allowed MIME types keyed by extension.
 * @return array<string,string>
 */
function allow_svg_mime( $mimes ) {
	$mimes['svg'] = 'image/svg+xml';

	return $mimes;
}

/**
 * Force the ext/type of a `.svg` file to image/svg+xml during our sideload.
 *
 * WordPress sniffs the real MIME of an SVG (via finfo) and often gets
 * text/plain or text/html, which then mismatches the extension and makes
 * wp_check_filetype_and_ext() null out `ext`/`type` — rejecting the upload.
 *
 * @param array  $data     { ext, type, proper_filename }.
 * @param string $file     Full path to the file.
 * @param string $filename Name of the file.
 * @return array
 */
function fix_svg_filetype( $data, $file, $filename ) {
	if ( preg_match( '/\.svg$/i', (string) $filename ) ) {
		$data['ext']  = 'svg';
		$data['type'] = 'image/svg+xml';
	}

	return $data;
}
