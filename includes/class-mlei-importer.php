<?php
/**
 * Imports one media row into the current site.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media importer.
 *
 * For each row the importer, in order:
 * 1. reuses an attachment that already points at the same file,
 * 2. registers the file if it is already in this site's uploads folder,
 * 3. copies it from the source subsite (network transfers),
 * 4. downloads it from the source URL (when allowed).
 */
class MLEI_Importer {

	/**
	 * Import a row.
	 *
	 * @param array $row      Normalized row (see MLEI_CSV).
	 * @param bool  $download Download missing files from the row URL.
	 * @return array Result: old_id, new_id, status, old_url, new_url, message.
	 */
	public static function import_row( array $row, $download ) {
		$result = array(
			'old_id'  => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'new_id'  => 0,
			'status'  => 'failed',
			'old_url' => isset( $row['url'] ) ? (string) $row['url'] : '',
			'new_url' => '',
			'message' => '',
		);

		$file = self::clean_relative_path( isset( $row['file'] ) ? $row['file'] : '' );
		if ( is_wp_error( $file ) ) {
			$result['message'] = $file->get_error_message();
			return $result;
		}

		$existing = self::find_attachment( $file );
		if ( $existing ) {
			$result['new_id']  = $existing;
			$result['status']  = 'existing';
			$result['new_url'] = (string) wp_get_attachment_url( $existing );
			return $result;
		}

		$type = wp_check_filetype( wp_basename( $file ) );
		if ( empty( $type['type'] ) ) {
			$result['message'] = __( 'This file type is not allowed on this site.', 'media-library-export-import' );
			return $result;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			$result['message'] = $uploads['error'];
			return $result;
		}
		$path = trailingslashit( $uploads['basedir'] ) . $file;

		if ( file_exists( $path ) ) {
			$status = 'registered';
		} elseif ( ! empty( $row['source_path'] ) && is_readable( $row['source_path'] ) ) {
			$path = self::place_file( $row['source_path'], $file, $uploads, false );
			if ( is_wp_error( $path ) ) {
				$result['message'] = $path->get_error_message();
				return $result;
			}
			$status = 'copied';
		} elseif ( $download && '' !== $result['old_url'] ) {
			$path = self::download( $result['old_url'], $file, $uploads );
			if ( is_wp_error( $path ) ) {
				$result['message'] = $path->get_error_message();
				return $result;
			}
			$status = 'downloaded';
		} else {
			$result['message'] = __( 'The file is not in the uploads folder of this site.', 'media-library-export-import' );
			return $result;
		}

		$id = self::insert_attachment( $path, $row, $uploads );
		if ( is_wp_error( $id ) ) {
			$result['message'] = $id->get_error_message();
			return $result;
		}

		$result['new_id']  = $id;
		$result['status']  = $status;
		$result['new_url'] = (string) wp_get_attachment_url( $id );
		return $result;
	}

	/**
	 * Validate an uploads-relative path.
	 *
	 * @param string $file Relative path such as 2024/05/photo.jpg.
	 * @return string|WP_Error
	 */
	public static function clean_relative_path( $file ) {
		$file = ltrim( wp_normalize_path( (string) $file ), '/' );
		if ( '' === $file || 0 !== validate_file( $file ) || false !== strpos( $file, "\0" ) ) {
			return new WP_Error( 'mlei_bad_path', __( 'The file path is not valid.', 'media-library-export-import' ) );
		}
		return $file;
	}

	/**
	 * Find an attachment that uses this uploads-relative file.
	 *
	 * @param string $file Relative path.
	 * @return int Attachment ID or 0.
	 */
	public static function find_attachment( $file ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact lookup by attached file; result changes during the import.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
				WHERE p.post_type = 'attachment' AND m.meta_key = '_wp_attached_file' AND m.meta_value = %s LIMIT 1",
				$file
			)
		);
		return (int) $id;
	}

	/**
	 * Download a file into the uploads folder.
	 *
	 * @param string $url     Source URL.
	 * @param string $file    Wanted relative path.
	 * @param array  $uploads wp_upload_dir() result.
	 * @return string|WP_Error Absolute path.
	 */
	private static function download( $url, $file, array $uploads ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'mlei_bad_url', __( 'The source URL is not valid.', 'media-library-export-import' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		/**
		 * Filters the timeout, in seconds, for downloading one file from its source URL.
		 *
		 * @param int $timeout Timeout in seconds. Default 60.
		 */
		$timeout = (int) apply_filters( 'mlei_download_timeout', 60 );
		$tmp     = download_url( $url, $timeout );
		if ( is_wp_error( $tmp ) ) {
			/* translators: %s: error message. */
			return new WP_Error( 'mlei_download', sprintf( __( 'Download failed: %s', 'media-library-export-import' ), $tmp->get_error_message() ) );
		}

		$check = wp_check_filetype_and_ext( $tmp, wp_basename( $file ) );
		if ( ( empty( $check['type'] ) || empty( $check['ext'] ) ) && ! current_user_can( 'unfiltered_upload' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'mlei_download_type', __( 'The downloaded file does not match its file type.', 'media-library-export-import' ) );
		}

		$path = self::place_file( $tmp, $file, $uploads, true );
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return $path;
	}

	/**
	 * Copy or move a file to its relative path inside the uploads folder.
	 *
	 * @param string $source  Source file.
	 * @param string $file    Wanted relative path.
	 * @param array  $uploads wp_upload_dir() result.
	 * @param bool   $move    Move instead of copy.
	 * @return string|WP_Error Absolute path (the name may get a suffix if taken).
	 */
	private static function place_file( $source, $file, array $uploads, $move ) {
		$dir = trailingslashit( $uploads['basedir'] ) . dirname( $file );
		if ( '.' === dirname( $file ) ) {
			$dir = $uploads['basedir'];
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mlei_mkdir', __( 'The upload folder could not be created.', 'media-library-export-import' ) );
		}

		$name = wp_unique_filename( $dir, wp_basename( $file ) );
		$dest = trailingslashit( $dir ) . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Same handling as core's _wp_handle_upload().
		$ok = $move ? @rename( $source, $dest ) : false;
		if ( ! $ok ) {
			$ok = @copy( $source, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $ok ) {
			return new WP_Error( 'mlei_copy', __( 'The file could not be saved in the uploads folder.', 'media-library-export-import' ) );
		}

		$stat = stat( dirname( $dest ) );
		if ( $stat ) {
			chmod( $dest, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}

		return $dest;
	}

	/**
	 * Create the attachment post, its metadata and image sizes.
	 *
	 * @param string $path    Absolute file path.
	 * @param array  $row     Normalized row.
	 * @param array  $uploads wp_upload_dir() result.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function insert_attachment( $path, array $row, array $uploads ) {
		$relative = ltrim( substr( wp_normalize_path( $path ), strlen( wp_normalize_path( $uploads['basedir'] ) ) ), '/' );
		$type     = wp_check_filetype( wp_basename( $path ) );

		$title = isset( $row['title'] ) ? $row['title'] : '';
		if ( '' === $title ) {
			$title = preg_replace( '/\.[^.]+$/', '', wp_basename( $path ) );
		}

		$post = array(
			'guid'           => trailingslashit( $uploads['baseurl'] ) . $relative,
			'post_mime_type' => $type['type'],
			'post_title'     => $title,
			'post_excerpt'   => isset( $row['caption'] ) ? $row['caption'] : '',
			'post_content'   => isset( $row['description'] ) ? $row['description'] : '',
			'post_status'    => 'inherit',
		);

		$date_gmt = isset( $row['date_gmt'] ) ? $row['date_gmt'] : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_gmt ) && '0000-00-00 00:00:00' !== $date_gmt ) {
			$post['post_date_gmt'] = $date_gmt;
			$post['post_date']     = get_date_from_gmt( $date_gmt );
		}

		$id = wp_insert_attachment( $post, $path, 0, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( ! empty( $row['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $row['alt'] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_raise_memory_limit( 'image' );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );

		return (int) $id;
	}
}
