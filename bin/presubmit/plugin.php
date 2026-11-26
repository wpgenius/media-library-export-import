<?php
/**
 * What the pre-submission runtime check exercises in THIS plugin. The only
 * file in bin/presubmit/ to edit for your plugin; runtime.php does the rest.
 *
 * Every key is optional. Callbacks run on a clean site with WP_DEBUG, as the
 * administrator, after activation. Callbacks that return text add it to the
 * results line as "key=value" pairs.
 *
 * Media Library Export Import has no front end, so "render" is empty: setup
 * runs an export and an import, check_render reports them, and settings_page
 * prints Media > Export / Import.
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
			$GLOBALS['mlei_presubmit'] = 'sample_image=' . rawurlencode( $id->get_error_message() );
			return;
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

		$GLOBALS['mlei_presubmit'] = implode( ' ', $results );
	},

	// No blocks or shortcodes: report the setup results and the assets on the
	// plugin's own screen, then dequeue them so the Dashboard check stays honest.
	'check_render'    => static function () {
		set_current_screen( 'media_page_' . MLEI_Admin::PAGE ); // Core's own admin_enqueue_scripts callbacks read the screen.
		do_action( 'admin_enqueue_scripts', 'media_page_' . MLEI_Admin::PAGE );
		$own = array();
		foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
			if ( in_array( 'mlei-admin', $deps->queue, true ) ) {
				$own[] = 'mlei-admin';
			}
		}
		wp_dequeue_script( 'mlei-admin' );
		wp_dequeue_style( 'mlei-admin' );
		$setup = isset( $GLOBALS['mlei_presubmit'] ) ? $GLOBALS['mlei_presubmit'] : 'setup=missing';
		return $setup . ' own_screen_assets=' . ( $own ? count( $own ) . 'x' . 'mlei-admin' : 'none' );
	},

	// Media > Export / Import.
	'settings_page'   => static function () {
		$GLOBALS['plugin_page'] = MLEI_Admin::PAGE;
		MLEI_Admin::render_page();
	},

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
