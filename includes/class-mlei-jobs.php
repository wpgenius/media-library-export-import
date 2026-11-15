<?php
/**
 * Storage for running import jobs.
 *
 * A job holds the parsed CSV rows and the results collected so far. Jobs are
 * stored as network options on multisite (so a network transfer and a subsite
 * import use the same store) and as non-autoloaded options on single sites.
 *
 * @package MediaLibraryExportImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import job store.
 */
class MLEI_Jobs {

	const INDEX_KEY  = 'mlei_jobs';
	const KEY_PREFIX = 'mlei_job_';
	const LIFETIME   = DAY_IN_SECONDS;

	/**
	 * Create a job.
	 *
	 * @param array $rows Normalized rows to import.
	 * @param array $args Job settings (target, network, download).
	 * @return string Job ID.
	 */
	public static function create( array $rows, array $args ) {
		self::purge_expired();

		$id  = strtolower( wp_generate_password( 16, false ) );
		$job = array(
			'user'     => get_current_user_id(),
			'created'  => time(),
			'target'   => isset( $args['target'] ) ? (int) $args['target'] : get_current_blog_id(),
			'source'   => isset( $args['source'] ) ? (int) $args['source'] : 0,
			'network'  => ! empty( $args['network'] ),
			'download' => ! empty( $args['download'] ),
			'pos'      => 0,
			'rows'     => array_values( $rows ),
			'results'  => array(),
		);

		self::save( $id, $job );

		$index        = self::index();
		$index[ $id ] = $job['created'];
		self::update( self::INDEX_KEY, $index );

		return $id;
	}

	/**
	 * Get a job owned by the current user.
	 *
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return null;
		}
		$job = self::read( self::KEY_PREFIX . $id );
		if ( ! is_array( $job ) || get_current_user_id() !== (int) $job['user'] ) {
			return null;
		}
		return $job;
	}

	/**
	 * Save a job.
	 *
	 * @param string $id  Job ID.
	 * @param array  $job Job data.
	 */
	public static function save( $id, array $job ) {
		self::update( self::KEY_PREFIX . sanitize_key( $id ), $job );
	}

	/**
	 * Delete a job.
	 *
	 * @param string $id Job ID.
	 */
	public static function delete( $id ) {
		$id = sanitize_key( $id );
		self::remove( self::KEY_PREFIX . $id );
		$index = self::index();
		unset( $index[ $id ] );
		self::update( self::INDEX_KEY, $index );
	}

	/**
	 * Delete jobs older than the lifetime.
	 */
	public static function purge_expired() {
		$index   = self::index();
		$changed = false;
		foreach ( $index as $id => $created ) {
			if ( (int) $created < time() - self::LIFETIME ) {
				self::remove( self::KEY_PREFIX . $id );
				unset( $index[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::update( self::INDEX_KEY, $index );
		}
	}

	/**
	 * Delete every job and the index (used on uninstall).
	 */
	public static function delete_all() {
		foreach ( array_keys( self::index() ) as $id ) {
			self::remove( self::KEY_PREFIX . $id );
		}
		self::remove( self::INDEX_KEY );
	}

	/**
	 * Job index: ID => created timestamp.
	 *
	 * @return array
	 */
	private static function index() {
		$index = self::read( self::INDEX_KEY );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Read a stored value.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	private static function read( $key ) {
		return is_multisite() ? get_site_option( $key ) : get_option( $key );
	}

	/**
	 * Write a stored value (never autoloaded).
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value.
	 */
	private static function update( $key, $value ) {
		if ( is_multisite() ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value, false );
		}
	}

	/**
	 * Delete a stored value.
	 *
	 * @param string $key Option key.
	 */
	private static function remove( $key ) {
		if ( is_multisite() ) {
			delete_site_option( $key );
		} else {
			delete_option( $key );
		}
	}
}
