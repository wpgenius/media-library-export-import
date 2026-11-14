<?php
/**
 * Collects media library rows for export.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media exporter.
 */
class MLEI_Exporter {

	/**
	 * Get one row per attachment of a site.
	 *
	 * @param int  $blog_id          Site to read (0 = current site).
	 * @param bool $with_source_path Add the absolute file path (for local copies inside one network).
	 * @return array Rows keyed by MLEI_CSV::COLUMNS (plus source_path when asked).
	 */
	public static function get_rows( $blog_id = 0, $with_source_path = false ) {
		$switched = false;
		if ( $blog_id && is_multisite() && get_current_blog_id() !== (int) $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$switched = true;
		}

		$rows = array();
		$page = 1;

		do {
			$ids = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Paged export.
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'suppress_filters'       => true,
					'update_post_term_cache' => false,
				)
			);

			if ( $ids ) {
				_prime_post_caches( $ids, false, true );
			}

			foreach ( $ids as $id ) {
				$row = self::get_row( $id, $with_source_path );
				if ( $row ) {
					$rows[] = $row;
				}
			}
			++$page;
			$found = count( $ids );
		} while ( 200 === $found );

		if ( $switched ) {
			restore_current_blog();
		}

		return $rows;
	}

	/**
	 * Build the export row for one attachment.
	 *
	 * @param int  $id               Attachment ID.
	 * @param bool $with_source_path Add the absolute file path.
	 * @return array|null
	 */
	private static function get_row( $id, $with_source_path ) {
		$post = get_post( $id );
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $post || '' === $file ) {
			return null;
		}

		$row = array(
			'id'          => $id,
			'url'         => (string) wp_get_attachment_url( $id ),
			'file'        => $file,
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'date_gmt'    => $post->post_date_gmt,
			'mime_type'   => $post->post_mime_type,
		);

		if ( $with_source_path ) {
			$row['source_path'] = (string) get_attached_file( $id, true );
		}

		return $row;
	}

	/**
	 * Send the current site's media library as a CSV download.
	 */
	public static function download() {
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = sprintf( 'media-%s-%s.csv', $host, gmdate( 'Ymd-His' ) );
		$rows     = array();

		foreach ( self::get_rows() as $row ) {
			$line = array();
			foreach ( MLEI_CSV::COLUMNS as $column ) {
				$line[] = $row[ $column ];
			}
			$rows[] = $line;
		}

		MLEI_CSV::stream( $filename, MLEI_CSV::COLUMNS, $rows );
	}
}
