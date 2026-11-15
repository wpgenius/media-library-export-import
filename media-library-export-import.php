<?php
/**
 * Plugin Name:       Media Library Export Import
 * Plugin URI:        https://github.com/wpgenius/media-library-export-import
 * Description:       Export your media library to a CSV file and import it into another site, or transfer media between subsites of a multisite network.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WPGenius
 * Author URI:        https://wpgenius.in
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       media-library-export-import
 *
 * @package MediaLibraryExportImport
 */

/*
Copyright 2022-2026 WPGenius

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MLEI_VERSION', '1.0.0' );
define( 'MLEI_FILE', __FILE__ );
define( 'MLEI_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLEI_URL', plugin_dir_url( __FILE__ ) );

require_once MLEI_PATH . 'includes/class-mlei-jobs.php';
require_once MLEI_PATH . 'includes/class-mlei-csv.php';
require_once MLEI_PATH . 'includes/class-mlei-exporter.php';
require_once MLEI_PATH . 'includes/class-mlei-importer.php';
require_once MLEI_PATH . 'includes/class-mlei-admin.php';

if ( is_admin() ) {
	MLEI_Admin::init();
}
