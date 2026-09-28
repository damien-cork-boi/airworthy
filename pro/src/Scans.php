<?php
/**
 * Finding Airworthy's latest finished scan.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Pro reads Airworthy's tables directly (everything else goes through the REST
 * API): which finished scan is the latest, overall or for one PHP version.
 */
final class Scans {

	/**
	 * The latest finished scan: id, target_php, finished_at (UTC).
	 *
	 * @param string|null $target Only scans against this PHP version; null for any.
	 * @return object|null
	 */
	public static function latest( $target = null ) {
		global $wpdb;
		$table = \Airworthy\Installer::tables()['scans'];
		if ( null === $target ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Airworthy's own table name (a constant prefix), no user input.
			$row = $wpdb->get_row( "SELECT id, target_php, finished_at FROM {$table} WHERE status = 'complete' ORDER BY id DESC LIMIT 1" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Airworthy's own table name (a constant prefix); the version is a placeholder.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, target_php, finished_at FROM {$table} WHERE status = 'complete' AND target_php = %s ORDER BY id DESC LIMIT 1", $target ) );
		}
		return $row ? $row : null;
	}

	/**
	 * The latest finished scan's ID, or 0.
	 *
	 * @param string|null $target Only scans against this PHP version; null for any.
	 * @return int
	 */
	public static function latest_id( $target = null ) {
		$row = self::latest( $target );
		return $row ? (int) $row->id : 0;
	}
}
