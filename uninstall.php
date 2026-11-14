<?php
/**
 * Remove stored import jobs when the plugin is deleted.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-mlei-jobs.php';

MLEI_Jobs::delete_all();
