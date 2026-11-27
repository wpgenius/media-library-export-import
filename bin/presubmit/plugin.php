<?php
/**
 * What the pre-submission runtime check exercises in THIS plugin. The only
 * file in bin/presubmit/ to edit for your plugin; runtime.php does the rest.
 *
 * Every key is optional. Callbacks run on a clean site with WP_DEBUG, as the
 * administrator, after activation. Callbacks that return text add it to the
 * results line as "key=value" pairs.
 *
 * The check runs as a script, not an admin page request, so is_admin() is
 * false while the plugin loads and MLEI_Admin::init() never runs: setup starts
 * it, or the Dashboard and own-screen checks would see no admin hooks.
 *
 * Media Library Export Import has no front end, so "render" is empty: setup
 * runs an export and an import and returns the results, settings_page prints
 * Media > Export / Import, and own_screen checks its assets load there.
 *
 * Run by bin/presubmit/run.sh inside WordPress Playground; not part of the plugin.
 */

return array(
	// Options mlei_jobs and mlei_job_<id>.
	'prefix'          => 'mlei',

	// Register the admin hooks (runPHP is not an admin request), add a sample
	// image, export it, read the CSV back, and import it twice: once as a file
	// already in the library, once as a file copied into uploads by hand.
	'setup'           => static function () {
		MLEI_Admin::init();
		$results = array();

		$tmp = wp_tempnam( 'presubmit.png' );
		file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAeAAAAEsAQMAAAAhMPIZAAAABlBMVEU4WOn////u5JClAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAmklEQVR42u3SvQnCQBiA4QsWaYS0gYCuEAmIoMPoAJnBVM6U0gFcQGwEN7C18cQNvmAhPE9x3Xv/KQEAAAAAAAAAwM+V9zxsUppF4nmbhzpPEorTN65CcfG8jvUitu3y0fSXuovFqW36U30YYnH3yvExGK8/K++DT7Xe5jOvhuCF3fpxt0zVOfbJimHCD50UAwAAAAAAAADwj97b+BXSba+56wAAAABJRU5ErkJggg==' ) );
		$id = media_handle_sideload(
			array(
				'name'     => 'presubmit.png',
				'tmp_name' => $tmp,
			),
			0,
			'Presubmit image'
		);
		if ( is_wp_error( $id ) ) {
			return 'sample_image=' . rawurlencode( $id->get_error_message() );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', 'Presubmit alt' );

		// Export: rows, the CSV download, and the CSV read back.
		$rows  = MLEI_Exporter::get_rows();
		$lines = array();
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( MLEI_CSV::COLUMNS as $column ) {
				$line[] = $row[ $column ];
			}
			$lines[] = $line;
		}
		ob_start();
		MLEI_CSV::stream( 'presubmit.csv', MLEI_CSV::COLUMNS, $lines );
		$csv  = (string) ob_get_clean();
		$file = wp_tempnam( 'presubmit.csv' );
		file_put_contents( $file, $csv );
		$parsed    = MLEI_CSV::parse( $file );
		$results[] = 'export_rows=' . count( $rows )
			. ' parsed_rows=' . ( is_wp_error( $parsed ) ? 'error' : count( $parsed ) )
			. ' alt_kept=' . ( ! is_wp_error( $parsed ) && 'Presubmit alt' === $parsed[0]['alt'] ? 'yes' : 'no' );

		if ( ! is_wp_error( $parsed ) ) {
			// Already in the library.
			$results[] = 'import_existing=' . MLEI_Importer::import_row( $parsed[0], false )['status'];

			// A copy placed in uploads by hand gets registered.
			$uploads = wp_upload_dir();
			$copy    = $uploads['basedir'] . '/presubmit-copy.png';
			copy( get_attached_file( $id ), $copy );
			$row             = $parsed[0];
			$row['file']     = 'presubmit-copy.png';
			$row['url']      = '';
			$row['filesize'] = filesize( $copy );
			$results[]       = 'import_registered=' . MLEI_Importer::import_row( $row, false )['status'];

			// A stored job, so uninstall has mlei_ options to remove.
			$job       = MLEI_Jobs::create( $parsed, array( 'download' => false ) );
			$results[] = 'job_stored=' . ( MLEI_Jobs::get( $job ) ? 'yes' : 'no' );
		}

		return implode( ' ', $results );
	},

	// Media > Export / Import.
	'settings_page'   => static function () {
		$GLOBALS['plugin_page'] = MLEI_Admin::PAGE;
		MLEI_Admin::render_page();
	},

	// The Media > Export / Import screen: mlei-admin script and style load here.
	'own_screen'      => 'media_page_' . MLEI_Admin::PAGE,

	// Recorded source URLs go on uninstall; the media stays.
	'after_uninstall' => static function () {
		global $wpdb;
		$meta  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_mlei_source_url' ) );
		$media = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		return 'source_meta_left=' . $meta . ' media_kept=' . count( $media );
	},
);
