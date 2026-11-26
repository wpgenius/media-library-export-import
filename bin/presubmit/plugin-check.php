<?php
/**
 * Pre-submission check: Plugin Check with every static check, experimental
 * ones included, on the packaged plugin. Writes /sp/plugin-check.txt.
 *
 * Run by bin/presubmit/run.sh inside WordPress Playground; not part of the plugin.
 *
 * @package MediaLibraryExportImport
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Writes its results to the mounted folder.

require_once '/wordpress/wp-load.php';

$mlei_lines = array();
try {
	$mlei_repository = 'WordPress\Plugin_Check\Checker\Check_Repository';
	$mlei_checks     = ( new WordPress\Plugin_Check\Checker\Default_Check_Repository() )->get_checks(
		constant( $mlei_repository . '::TYPE_STATIC' ) | constant( $mlei_repository . '::INCLUDE_EXPERIMENTAL' )
	);
	$mlei_context    = new WordPress\Plugin_Check\Checker\Check_Context( WP_PLUGIN_DIR . '/' . PRESUBMIT_PLUGIN );
	$mlei_result     = new WordPress\Plugin_Check\Checker\Check_Result( $mlei_context );

	$mlei_lines[] = 'checks: ' . implode( ', ', array_keys( iterator_to_array( $mlei_checks ) ) );
	foreach ( $mlei_checks as $mlei_slug => $mlei_check ) {
		try {
			$mlei_check->run( $mlei_result );
		} catch ( Throwable $mlei_e ) {
			$mlei_lines[] = "EXCEPTION $mlei_slug: " . $mlei_e->getMessage();
		}
	}

	$mlei_found = array(
		'ERROR'   => $mlei_result->get_errors(),
		'WARNING' => $mlei_result->get_warnings(),
	);
	foreach ( $mlei_found as $mlei_type => $mlei_files ) {
		foreach ( $mlei_files as $mlei_file => $mlei_rows ) {
			foreach ( $mlei_rows as $mlei_line => $mlei_columns ) {
				foreach ( $mlei_columns as $mlei_messages ) {
					foreach ( $mlei_messages as $mlei_message ) {
						$mlei_lines[] = "$mlei_type $mlei_file:$mlei_line {$mlei_message['code']} " . wp_strip_all_tags( $mlei_message['message'] );
					}
				}
			}
		}
	}
	$mlei_lines[] = 'errors=' . $mlei_result->get_error_count() . ' warnings=' . $mlei_result->get_warning_count();
} catch ( Throwable $mlei_e ) {
	$mlei_lines[] = 'FATAL ' . get_class( $mlei_e ) . ': ' . $mlei_e->getMessage() . ' @ ' . $mlei_e->getFile() . ':' . $mlei_e->getLine();
}

file_put_contents( '/sp/plugin-check.txt', implode( "\n", $mlei_lines ) . "\n" );
