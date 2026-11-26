<?php
/**
 * Admin screens, AJAX batch processing and downloads.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
class MLEI_Admin {

	const PAGE         = 'media-library-export-import';
	const NETWORK_PAGE = 'media-library-transfer';
	const NONCE        = 'mlei_ajax';

	/**
	 * Hook everything.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'add_network_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_mlei_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mlei_map', array( __CLASS__, 'handle_map' ) );
		add_action( 'wp_ajax_mlei_start_import', array( __CLASS__, 'ajax_start_import' ) );
		add_action( 'wp_ajax_mlei_start_transfer', array( __CLASS__, 'ajax_start_transfer' ) );
		add_action( 'wp_ajax_mlei_process', array( __CLASS__, 'ajax_process' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MLEI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Media > Export / Import.
	 */
	public static function add_menu() {
		add_media_page(
			__( 'Media Library Export Import', 'media-library-export-import' ),
			__( 'Export / Import', 'media-library-export-import' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Network Admin > Sites > Media Transfer.
	 */
	public static function add_network_menu() {
		add_submenu_page(
			'sites.php',
			__( 'Media Transfer', 'media-library-export-import' ),
			__( 'Media Transfer', 'media-library-export-import' ),
			'manage_network_options',
			self::NETWORK_PAGE,
			array( __CLASS__, 'render_network_page' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'upload.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export / Import', 'media-library-export-import' ) . '</a>' );
		return $links;
	}

	/**
	 * Load assets on our screens only.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'media_page_' . self::PAGE !== $hook && 'sites_page_' . self::NETWORK_PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mlei-admin', MLEI_URL . 'assets/css/admin.css', array(), MLEI_VERSION );
		wp_enqueue_script( 'mlei-admin', MLEI_URL . 'assets/js/admin.js', array(), MLEI_VERSION, true );
		wp_localize_script(
			'mlei-admin',
			'mleiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'mapUrl'  => add_query_arg(
					array(
						'action'   => 'mlei_map',
						'_wpnonce' => wp_create_nonce( 'mlei_map' ),
					),
					admin_url( 'admin-post.php' )
				),
				'i18n'    => array(
					'starting' => __( 'Preparing…', 'media-library-export-import' ),
					/* translators: 1: rows done, 2: total rows. */
					'progress' => __( '%1$s of %2$s processed', 'media-library-export-import' ),
					'done'     => __( 'Finished.', 'media-library-export-import' ),
					'error'    => __( 'The request failed. Check your connection and resume.', 'media-library-export-import' ),
					'leave'    => __( 'The import is still running. Leave anyway?', 'media-library-export-import' ),
					'noFile'   => __( 'Choose a CSV file first.', 'media-library-export-import' ),
					'sameSite' => __( 'Choose two different sites.', 'media-library-export-import' ),
					'statuses' => self::status_labels(),
				),
			)
		);
	}

	/**
	 * Human labels for result statuses.
	 *
	 * @return array
	 */
	private static function status_labels() {
		return array(
			'existing'   => __( 'Already in library', 'media-library-export-import' ),
			'registered' => __( 'Registered', 'media-library-export-import' ),
			'copied'     => __( 'Copied', 'media-library-export-import' ),
			'downloaded' => __( 'Downloaded', 'media-library-export-import' ),
			'failed'     => __( 'Failed', 'media-library-export-import' ),
		);
	}

	/**
	 * Export / Import screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = wp_count_attachments();
		$total  = 0;
		foreach ( (array) $counts as $mime => $count ) {
			if ( 'trash' !== $mime ) {
				$total += (int) $count;
			}
		}
		?>
		<div class="wrap mlei">
			<h1><?php esc_html_e( 'Media Library Export Import', 'media-library-export-import' ); ?></h1>

			<?php if ( is_multisite() && current_user_can( 'manage_network_options' ) ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: %s: link to the network media transfer page. */
						esc_html__( 'Moving media between subsites of this network? %s copies files directly, without a CSV file.', 'media-library-export-import' ),
						'<a href="' . esc_url( network_admin_url( 'sites.php?page=' . self::NETWORK_PAGE ) ) . '">' . esc_html__( 'Media Transfer', 'media-library-export-import' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="mlei-card">
				<h2><?php esc_html_e( '1. Export', 'media-library-export-import' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: number of media items. */
						esc_html( _n( 'Download a CSV file listing the %s item in this media library: file URL, path, title, caption, description, alt text and upload date.', 'Download a CSV file listing the %s items in this media library: file URL, path, title, caption, description, alt text and upload date.', $total, 'media-library-export-import' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mlei_export" />
					<?php wp_nonce_field( 'mlei_export' ); ?>
					<?php submit_button( __( 'Download export CSV', 'media-library-export-import' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="mlei-card">
				<h2><?php esc_html_e( '2. Import', 'media-library-export-import' ); ?></h2>
				<p><?php esc_html_e( 'Upload a CSV file exported from another site. For each item the importer:', 'media-library-export-import' ); ?></p>
				<ul class="mlei-list">
					<li><?php esc_html_e( 'skips it if this library already has an item for the same file,', 'media-library-export-import' ); ?></li>
					<li><?php esc_html_e( 'registers the file if you have already copied it into this site’s uploads folder (same year/month path),', 'media-library-export-import' ); ?></li>
					<li><?php esc_html_e( 'otherwise downloads it from its original URL, if you allow it below.', 'media-library-export-import' ); ?></li>
				</ul>
				<form id="mlei-import-form" class="mlei-form" enctype="multipart/form-data">
					<p>
						<label for="mlei-csv"><strong><?php esc_html_e( 'CSV file', 'media-library-export-import' ); ?></strong></label><br />
						<input type="file" id="mlei-csv" name="csv" accept=".csv,text/csv" required />
					</p>
					<p>
						<label>
							<input type="checkbox" name="download" value="1" checked />
							<?php esc_html_e( 'Download files that are missing on this site from their original URL', 'media-library-export-import' ); ?>
						</label>
					</p>
					<?php submit_button( __( 'Start import', 'media-library-export-import' ), 'primary', 'submit', false ); ?>
				</form>
				<?php self::render_progress(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Network media transfer screen.
	 */
	public static function render_network_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$sites = get_sites(
			array(
				'number'   => 1000,
				'deleted'  => 0,
				'archived' => 0,
			)
		);
		?>
		<div class="wrap mlei">
			<h1><?php esc_html_e( 'Media Transfer', 'media-library-export-import' ); ?></h1>
			<div class="mlei-card">
				<p><?php esc_html_e( 'Copy every media item from one site of this network to another. Files are copied on the server and keep their year/month path; items the target site already has are skipped. Posts and pages are not changed: download the ID mapping file at the end if you need to update references.', 'media-library-export-import' ); ?></p>
				<form id="mlei-transfer-form" class="mlei-form">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mlei-source"><?php esc_html_e( 'Copy media from', 'media-library-export-import' ); ?></label></th>
							<td><?php self::site_select( 'mlei-source', 'source', $sites ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="mlei-target"><?php esc_html_e( 'Into', 'media-library-export-import' ); ?></label></th>
							<td><?php self::site_select( 'mlei-target', 'target', $sites ); ?></td>
						</tr>
					</table>
					<?php submit_button( __( 'Start transfer', 'media-library-export-import' ), 'primary', 'submit', false ); ?>
				</form>
				<?php self::render_progress(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Site dropdown.
	 *
	 * @param string    $id    Element ID.
	 * @param string    $name  Field name.
	 * @param WP_Site[] $sites Sites.
	 */
	private static function site_select( $id, $name, array $sites ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" required>';
		echo '<option value="">' . esc_html__( '— Select a site —', 'media-library-export-import' ) . '</option>';
		foreach ( $sites as $site ) {
			$label = $site->blogname ? $site->blogname . ' (' . $site->domain . $site->path . ')' : $site->domain . $site->path;
			echo '<option value="' . esc_attr( $site->blog_id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Progress area shared by both screens (filled by admin.js).
	 */
	private static function render_progress() {
		?>
		<div class="mlei-progress" hidden>
			<div class="mlei-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
			<p class="mlei-status" aria-live="polite"></p>
			<p class="mlei-actions">
				<button type="button" class="button mlei-resume" hidden><?php esc_html_e( 'Resume', 'media-library-export-import' ); ?></button>
				<a class="button button-primary mlei-map" href="#" hidden><?php esc_html_e( 'Download ID mapping CSV', 'media-library-export-import' ); ?></a>
			</p>
			<table class="widefat striped mlei-results">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Old ID', 'media-library-export-import' ); ?></th>
						<th><?php esc_html_e( 'Status', 'media-library-export-import' ); ?></th>
						<th><?php esc_html_e( 'New ID', 'media-library-export-import' ); ?></th>
						<th><?php esc_html_e( 'New URL / message', 'media-library-export-import' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Export download (admin-post.php?action=mlei_export).
	 */
	public static function handle_export() {
		check_admin_referer( 'mlei_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export media.', 'media-library-export-import' ), 403 );
		}
		MLEI_Exporter::download();
		exit;
	}

	/**
	 * ID mapping download (admin-post.php?action=mlei_map&job=…).
	 */
	public static function handle_map() {
		check_admin_referer( 'mlei_map' );
		$job_id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		$job    = MLEI_Jobs::get( $job_id );
		if ( ! $job || ! self::can_run( $job ) ) {
			wp_die( esc_html__( 'This import was not found or has expired.', 'media-library-export-import' ), 404 );
		}

		$rows = array();
		foreach ( $job['results'] as $result ) {
			$line = array();
			foreach ( MLEI_CSV::MAP_COLUMNS as $column ) {
				$line[] = isset( $result[ $column ] ) ? $result[ $column ] : '';
			}
			$rows[] = $line;
		}

		MLEI_CSV::stream( 'media-id-mapping-' . gmdate( 'Ymd-His' ) . '.csv', MLEI_CSV::MAP_COLUMNS, $rows );
		exit;
	}

	/**
	 * Start a CSV import: parse the upload and create a job.
	 */
	public static function ajax_start_import() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
			self::fail( __( 'Sorry, you are not allowed to import media.', 'media-library-export-import' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload array; fields are validated below.
		$upload = isset( $_FILES['csv'] ) ? $_FILES['csv'] : null;
		if ( ! $upload || ! isset( $upload['error'], $upload['tmp_name'], $upload['name'] ) || UPLOAD_ERR_OK !== (int) $upload['error'] || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			self::fail( __( 'The CSV file was not uploaded.', 'media-library-export-import' ) );
		}

		$check = wp_check_filetype( sanitize_file_name( $upload['name'] ), array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $check['ext'] ) {
			self::fail( __( 'Please upload a .csv file.', 'media-library-export-import' ) );
		}

		$rows = MLEI_CSV::parse( $upload['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			self::fail( $rows->get_error_message() );
		}

		$job_id = MLEI_Jobs::create(
			$rows,
			array(
				'target'   => get_current_blog_id(),
				'download' => isset( $_POST['download'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['download'] ) ),
			)
		);

		wp_send_json_success(
			array(
				'job'   => $job_id,
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * Start a network transfer between two subsites.
	 */
	public static function ajax_start_transfer() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			self::fail( __( 'Sorry, you are not allowed to transfer media.', 'media-library-export-import' ), 403 );
		}

		$source = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;
		$target = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		if ( ! $source || ! $target || $source === $target || ! get_site( $source ) || ! get_site( $target ) ) {
			self::fail( __( 'Choose two different sites.', 'media-library-export-import' ) );
		}

		$rows = MLEI_Exporter::get_rows( $source, true );
		if ( empty( $rows ) ) {
			self::fail( __( 'The source site has no media.', 'media-library-export-import' ) );
		}

		$job_id = MLEI_Jobs::create(
			$rows,
			array(
				'source'   => $source,
				'target'   => $target,
				'network'  => true,
				'download' => false,
			)
		);

		wp_send_json_success(
			array(
				'job'   => $job_id,
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * Process the next batch of a job.
	 */
	public static function ajax_process() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$job_id = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job    = MLEI_Jobs::get( $job_id );
		if ( ! $job ) {
			self::fail( __( 'This import was not found or has expired.', 'media-library-export-import' ), 404 );
		}
		if ( ! self::can_run( $job ) ) {
			self::fail( __( 'Sorry, you are not allowed to run this import.', 'media-library-export-import' ), 403 );
		}

		$switched = false;
		if ( is_multisite() && get_current_blog_id() !== $job['target'] ) {
			switch_to_blog( $job['target'] );
			$switched = true;
		}

		/**
		 * Filters how many media items are imported per request.
		 *
		 * @param int $size Batch size. Default 5.
		 */
		$size  = max( 1, (int) apply_filters( 'mlei_batch_size', 5 ) );
		$total = count( $job['rows'] );
		$done  = array();

		for ( $i = 0; $i < $size && $job['pos'] < $total; $i++ ) {
			$result           = MLEI_Importer::import_row( $job['rows'][ $job['pos'] ], $job['download'] );
			$job['results'][] = $result;
			$done[]           = $result;
			++$job['pos'];
		}

		if ( $switched ) {
			restore_current_blog();
		}

		MLEI_Jobs::save( $job_id, $job );

		wp_send_json_success(
			array(
				'pos'     => $job['pos'],
				'total'   => $total,
				'done'    => $job['pos'] >= $total,
				'results' => $done,
			)
		);
	}

	/**
	 * Whether the current user may run or read a job.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function can_run( array $job ) {
		if ( $job['network'] ) {
			return is_multisite() && current_user_can( 'manage_network_options' );
		}
		return get_current_blog_id() === $job['target'] && current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Send a JSON error and stop.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function fail( $message, $status = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}
}
