<?php
/**
 * Pre-submission check, step 2: on a clean site with WP_DEBUG, export a sample
 * image, import it again, open the plugin's screen, look at an admin screen
 * that isn't the plugin's, deactivate, uninstall, and count PHP notices.
 * Appends to /sp/runtime-<php>.txt.
 *
 * Calls the plugin's classes directly: runPHP is not an admin request, so the
 * admin hooks are not registered on their own.
 *
 * Run by bin/presubmit/run.sh inside WordPress Playground; not part of the plugin.
 *
 * @package MediaLibraryExportImport
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.WP.GlobalVariablesOverride -- A test script, not plugin code.

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$mlei_lines  = array();
$mlei_errors = array();
set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Counts notices.
	static function ( $number, $message, $file, $line ) use ( &$mlei_errors ) {
		$mlei_errors[] = ( false !== strpos( $file, '/wp-content/plugins/' ) ? 'PLUGIN ' : 'CORE   ' ) . "$number $message @ $file:$line";
		return false;
	}
);
wp_set_current_user( 1 );

$mlei_lines[] = 'active=' . ( is_plugin_active( PRESUBMIT_PLUGIN ) ? 'yes' : 'no' );

// A sample image with alt text.
$mlei_tmp = wp_tempnam( 'presubmit.png' );
file_put_contents( $mlei_tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAeAAAAEsAQMAAAAhMPIZAAAABlBMVEU4WOn////u5JClAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAmklEQVR42u3SvQnCQBiA4QsWaYS0gYCuEAmIoMPoAJnBVM6U0gFcQGwEN7C18cQNvmAhPE9x3Xv/KQEAAAAAAAAAwM+V9zxsUppF4nmbhzpPEorTN65CcfG8jvUitu3y0fSXuovFqW36U30YYnH3yvExGK8/K++DT7Xe5jOvhuCF3fpxt0zVOfbJimHCD50UAwAAAAAAAADwj97b+BXSba+56wAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A sample PNG.
$mlei_id = media_handle_sideload(
	array(
		'name'     => 'presubmit.png',
		'tmp_name' => $mlei_tmp,
	),
	0,
	'Presubmit image'
);
if ( is_wp_error( $mlei_id ) ) {
	$mlei_lines[] = 'sample_image=' . $mlei_id->get_error_message();
	$mlei_id      = 0;
} else {
	update_post_meta( $mlei_id, '_wp_attachment_image_alt', 'Presubmit alt' );
}

// Export: rows, the CSV download, and the CSV read back.
$mlei_rows = MLEI_Exporter::get_rows();
ob_start();
MLEI_CSV::stream( 'presubmit.csv', MLEI_CSV::COLUMNS, array_map( 'array_values', array_map( static function ( $row ) {
	return array_intersect_key( $row, array_flip( MLEI_CSV::COLUMNS ) );
}, $mlei_rows ) ) );
$mlei_csv      = (string) ob_get_clean();
$mlei_csv_file = wp_tempnam( 'presubmit.csv' );
file_put_contents( $mlei_csv_file, $mlei_csv );
$mlei_parsed  = MLEI_CSV::parse( $mlei_csv_file );
$mlei_lines[] = 'export_rows=' . count( $mlei_rows )
	. ' csv_bytes=' . strlen( $mlei_csv )
	. ' parsed_rows=' . ( is_wp_error( $mlei_parsed ) ? $mlei_parsed->get_error_message() : count( $mlei_parsed ) )
	. ' alt_kept=' . ( ! is_wp_error( $mlei_parsed ) && isset( $mlei_parsed[0]['alt'] ) && 'Presubmit alt' === $mlei_parsed[0]['alt'] ? 'yes' : 'no' );

// Import: the same row is already in the library; a copy of the file placed
// in uploads by hand gets registered.
$mlei_existing   = 'skipped';
$mlei_registered = 'skipped';
if ( ! is_wp_error( $mlei_parsed ) && $mlei_parsed ) {
	$mlei_existing = MLEI_Importer::import_row( $mlei_parsed[0], false )['status'];

	$mlei_uploads = wp_upload_dir();
	copy( get_attached_file( $mlei_id ), $mlei_uploads['basedir'] . '/presubmit-copy.png' );
	$mlei_row             = $mlei_parsed[0];
	$mlei_row['file']     = 'presubmit-copy.png';
	$mlei_row['url']      = '';
	$mlei_row['filesize'] = filesize( $mlei_uploads['basedir'] . '/presubmit-copy.png' );
	$mlei_result          = MLEI_Importer::import_row( $mlei_row, false );
	$mlei_registered      = $mlei_result['status'] . ( $mlei_result['message'] ? ' (' . $mlei_result['message'] . ')' : '' );

	$mlei_job_id = MLEI_Jobs::create( $mlei_parsed, array( 'download' => false ) );
}
$mlei_lines[] = 'import_existing=' . $mlei_existing
	. ' import_registered=' . $mlei_registered
	. ' job_stored=' . ( isset( $mlei_job_id ) && MLEI_Jobs::get( $mlei_job_id ) ? 'yes' : 'no' )
	. ' source_meta=' . ( isset( $mlei_result['new_id'] ) && get_post_meta( $mlei_result['new_id'], '_mlei_source_url', true ) === '' ? 'none (no url)' : 'set' );

// Admin: the plugin's screen, then the dashboard (not the plugin's screen).
// Outside a real admin request core's get_admin_page_title() may raise a
// deprecation on PHP 8.1+ (counted as core_notices, not the plugin's).
$GLOBALS['plugin_page'] = MLEI_Admin::PAGE;
$GLOBALS['title']       = 'Media Library Export Import';
MLEI_Admin::init();
ob_start();
MLEI_Admin::render_page();
$mlei_page    = (string) ob_get_clean();
$mlei_lines[] = 'page_bytes=' . strlen( $mlei_page )
	. ' import_form=' . ( false !== strpos( $mlei_page, 'id="mlei-import-form"' ) ? 'yes' : 'no' )
	. ' inline_style_or_script=' . ( preg_match( '/<(style|script)\b/i', $mlei_page ) ? 'yes' : 'no' );

$mlei_plugin_assets = static function ( $hook ) {
	$before = array_merge( wp_scripts()->queue, wp_styles()->queue );
	do_action( 'admin_enqueue_scripts', $hook );
	$found = array();
	foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
		foreach ( array_diff( $deps->queue, $before ) as $handle ) {
			$src = isset( $deps->registered[ $handle ] ) ? (string) $deps->registered[ $handle ]->src : '';
			if ( false !== strpos( $src, '/plugins/' . dirname( PRESUBMIT_PLUGIN ) . '/' ) ) {
				$found[] = $handle;
			}
		}
	}
	return $found ? implode( ',', $found ) : 'none';
};
set_current_screen( 'dashboard' );
$mlei_dashboard = $mlei_plugin_assets( 'index.php' );
ob_start();
do_action( 'admin_notices' );
$mlei_lines[] = 'dashboard_plugin_assets=' . $mlei_dashboard
	. ' dashboard_notice_bytes=' . strlen( (string) ob_get_clean() )
	. ' own_screen_assets=' . $mlei_plugin_assets( 'media_page_' . MLEI_Admin::PAGE );

// Deactivate, then uninstall: jobs and recorded source URLs must go.
deactivate_plugins( PRESUBMIT_PLUGIN );
ob_start();
uninstall_plugin( PRESUBMIT_PLUGIN );
$mlei_uninstall_output = (string) ob_get_clean();
global $wpdb;
$mlei_lines[] = 'uninstall_output_bytes=' . strlen( $mlei_uninstall_output )
	. ' job_options_left=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'mlei_job' ) . '%' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checks the options are gone.
	. ' source_meta_left=' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_mlei_source_url' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checks the meta is gone.
	. ' media_kept=' . count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids' ) ) );

$mlei_plugin_notices = count( preg_grep( '/^PLUGIN /', $mlei_errors ) );
$mlei_lines[]        = 'plugin_notices=' . $mlei_plugin_notices . ' core_notices=' . ( count( $mlei_errors ) - $mlei_plugin_notices );
$mlei_lines          = array_merge( $mlei_lines, $mlei_errors );
file_put_contents( '/sp/runtime-' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.txt', implode( "\n", $mlei_lines ) . "\n", FILE_APPEND );
