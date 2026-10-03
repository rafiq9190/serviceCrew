<?php
/**
 * Shared hardened photo-upload validation: extension+MIME sniff
 * (`wp_check_filetype_and_ext()`), a `getimagesize()` re-check that the
 * bytes actually decode as an image, a size cap, and `wp_handle_upload()`
 * for the actual move/attachment insert.
 *
 * Extracted from class-service-crew-quotes.php's original quote-photo
 * upload (moved here verbatim, not rewritten) so the employee job-status
 * page's completion photos (class-service-crew-job-status-page.php) reuse
 * the exact same validation rather than a second copy of security-sensitive
 * upload code that could silently drift from this one.
 *
 * @package ServiceCrew
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Crew_Uploads {

	/**
	 * Extensions/mimes an upload through this choke point may be — image
	 * only, nothing else, no matter what a renamed file claims to be
	 * (checked against the file's actual bytes below, not just its name).
	 *
	 * @var array<string,string>
	 */
	const ALLOWED_IMAGE_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * Validates and uploads every photo in a raw $_FILES-shaped field
	 * (single or multi-file), image-only and size-capped. Any single
	 * invalid file fails the whole request (rather than silently dropping
	 * it) so the caller knows to fix it, rather than it only turning up
	 * missing later.
	 *
	 * @param array<string,mixed> $files_field Raw $_FILES['xyz'] shape, or empty array if none submitted.
	 * @param int                 $max_count   Max photos accepted in one request.
	 * @param int                 $max_bytes   Max size per photo, in bytes.
	 * @return int[]|WP_Error Attachment IDs.
	 */
	public static function handle_photo_uploads( array $files_field, $max_count, $max_bytes ) {
		if ( empty( $files_field ) ) {
			return array();
		}

		$normalized = self::normalize_file_array( $files_field );

		if ( count( $normalized ) > $max_count ) {
			return new WP_Error(
				'sc_upload_too_many',
				sprintf(
					/* translators: %d: max photo count. */
					__( 'Please attach no more than %d photos.', 'service-crew' ),
					$max_count
				),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_ids = array();

		foreach ( $normalized as $file ) {
			if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return new WP_Error( 'sc_upload_error', __( 'One of your photos could not be uploaded. Please try again.', 'service-crew' ), array( 'status' => 400 ) );
			}

			if ( $file['size'] > $max_bytes ) {
				return new WP_Error(
					'sc_upload_too_large',
					sprintf(
						/* translators: %s: max size in MB. */
						__( 'Each photo must be %s MB or smaller.', 'service-crew' ),
						number_format_i18n( $max_bytes / MB_IN_BYTES, 0 )
					),
					array( 'status' => 400 )
				);
			}

			$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::ALLOWED_IMAGE_MIMES );

			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || ! in_array( $filetype['type'], self::ALLOWED_IMAGE_MIMES, true ) ) {
				return new WP_Error( 'sc_upload_invalid_type', __( 'Photos must be JPG, PNG, GIF or WEBP files.', 'service-crew' ), array( 'status' => 400 ) );
			}

			// Belt-and-suspenders: confirm the bytes actually decode as an
			// image, not just that the extension/MIME sniff passed — rejects
			// a non-image renamed to look like one.
			if ( false === @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately quiets the warning for a non-image file; the false return is the check.
				return new WP_Error( 'sc_upload_invalid_type', __( 'Photos must be JPG, PNG, GIF or WEBP files.', 'service-crew' ), array( 'status' => 400 ) );
			}

			$overrides = array(
				'test_form' => false,
				'mimes'     => self::ALLOWED_IMAGE_MIMES,
			);

			$moved = wp_handle_upload( $file, $overrides );

			if ( isset( $moved['error'] ) ) {
				return new WP_Error( 'sc_upload_failed', $moved['error'], array( 'status' => 400 ) );
			}

			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => $moved['type'],
					'post_title'     => sanitize_file_name( $file['name'] ),
					'post_status'    => 'inherit',
				),
				$moved['file']
			);

			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				return new WP_Error( 'sc_upload_attachment_failed', __( 'Could not save one of your photos. Please try again.', 'service-crew' ), array( 'status' => 500 ) );
			}

			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

			$attachment_ids[] = (int) $attachment_id;
		}

		return $attachment_ids;
	}

	/**
	 * Reshapes PHP's multi-file $_FILES['xyz'] (parallel arrays keyed
	 * name/type/tmp_name/error/size) into a list of single-file arrays, the
	 * shape wp_handle_upload() and every check above expects.
	 *
	 * @param array<string,mixed> $files_field Raw $_FILES['xyz'] shape, as WordPress hands it back.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_file_array( array $files_field ) {
		if ( ! is_array( $files_field['name'] ?? null ) ) {
			return array( $files_field );
		}

		$normalized = array();
		$count      = count( $files_field['name'] );

		for ( $i = 0; $i < $count; $i++ ) {
			$normalized[] = array(
				'name'     => $files_field['name'][ $i ],
				'type'     => $files_field['type'][ $i ],
				'tmp_name' => $files_field['tmp_name'][ $i ],
				'error'    => $files_field['error'][ $i ],
				'size'     => $files_field['size'][ $i ],
			);
		}

		return $normalized;
	}
}
