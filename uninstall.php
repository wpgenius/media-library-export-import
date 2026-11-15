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

// Source URLs recorded on imported attachments.
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $mlei_site_id ) {
		switch_to_blog( $mlei_site_id );
		delete_post_meta_by_key( '_mlei_source_url' );
		restore_current_blog();
	}
} else {
	delete_post_meta_by_key( '_mlei_source_url' );
}
