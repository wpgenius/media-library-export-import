<?php
/**
 * CSV reading and writing.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV helpers.
 */
class MLEI_CSV {

	/**
	 * Columns written by the exporter, in order.
	 */
	const COLUMNS = array( 'id', 'url', 'file', 'title', 'caption', 'description', 'alt', 'date_gmt', 'mime_type', 'filesize' );

	/**
	 * Columns written to the ID mapping file, in order.
	 */
	const MAP_COLUMNS = array( 'old_id', 'new_id', 'status', 'old_url', 'new_url', 'message' );

	/**
	 * Parse an export CSV into normalized rows.
	 *
	 * @param string $path Path to the CSV file.
	 * @return array|WP_Error Rows, or an error.
	 */
	public static function parse( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading an uploaded temp file line by line.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'mlei_csv_open', __( 'The CSV file could not be read.', 'media-library-export-import' ) );
		}

		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'mlei_csv_empty', __( 'The CSV file is empty.', 'media-library-export-import' ) );
		}

		$header    = array_map( array( __CLASS__, 'normalize_header' ), $header );
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

		if ( ! in_array( 'url', $header, true ) && ! in_array( 'file', $header, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'mlei_csv_header', __( 'The CSV file needs a "url" or "file" column. Use a file created by the export on this page.', 'media-library-export-import' ) );
		}

		$rows = array();
		while ( false !== ( $line = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $line ) {
				continue; // Blank line.
			}
			$data = array();
			foreach ( $header as $i => $column ) {
				$data[ $column ] = isset( $line[ $i ] ) ? trim( (string) $line[ $i ] ) : '';
			}
			$row = self::normalize_row( $data );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( empty( $rows ) ) {
			return new WP_Error( 'mlei_csv_rows', __( 'The CSV file has no media rows.', 'media-library-export-import' ) );
		}

		return $rows;
	}

	/**
	 * Send download headers and write rows as CSV to the output.
	 *
	 * @param string $filename Download file name.
	 * @param array  $columns  Header row.
	 * @param array  $rows     Rows (arrays in column order).
	 */
	public static function stream( $filename, array $columns, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing to the output stream.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $columns, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'protect_cell' ), $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Normalize a header cell.
	 *
	 * @param string $cell Header cell.
	 * @return string
	 */
	private static function normalize_header( $cell ) {
		return strtolower( trim( (string) $cell ) );
	}

	/**
	 * Turn a CSV row into an import row, or null to skip it.
	 *
	 * @param array $data Cells keyed by column.
	 * @return array|null
	 */
	private static function normalize_row( array $data ) {
		$url  = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		$file = isset( $data['file'] ) ? self::unprotect_cell( $data['file'] ) : '';

		if ( '' === $file && '' !== $url ) {
			$file = self::file_from_url( $url );
		}
		if ( '' === $file ) {
			return null;
		}

		return array(
			'id'          => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
			'url'         => $url,
			'file'        => $file,
			'title'       => isset( $data['title'] ) ? sanitize_text_field( self::unprotect_cell( $data['title'] ) ) : '',
			'caption'     => isset( $data['caption'] ) ? wp_kses_post( self::unprotect_cell( $data['caption'] ) ) : '',
			'description' => isset( $data['description'] ) ? wp_kses_post( self::unprotect_cell( $data['description'] ) ) : '',
			'alt'         => isset( $data['alt'] ) ? sanitize_text_field( self::unprotect_cell( $data['alt'] ) ) : '',
			'date_gmt'    => isset( $data['date_gmt'] ) ? sanitize_text_field( $data['date_gmt'] ) : '',
			'filesize'    => isset( $data['filesize'] ) ? absint( $data['filesize'] ) : 0,
		);
	}

	/**
	 * Guess the uploads-relative file path from a media URL
	 * (for CSV files that have a URL but no "file" column).
	 *
	 * @param string $url Media URL.
	 * @return string
	 */
	private static function file_from_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$pos  = strpos( $path, '/uploads/' );
		if ( false === $pos ) {
			return '';
		}
		$file = substr( $path, $pos + strlen( '/uploads/' ) );
		// Multisite subsite uploads live in sites/{id}/.
		$file = preg_replace( '#^sites/\d+/#', '', $file );
		return rawurldecode( $file );
	}

	/**
	 * Prefix cells that a spreadsheet would run as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private static function protect_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( '=+-@', $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Undo protect_cell() when reading an exported file back.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function unprotect_cell( $value ) {
		if ( strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( '=+-@', $value[1] ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}
}
