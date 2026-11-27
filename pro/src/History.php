<?php
/**
 * Scan history and "what changed since last time".
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

defined( 'ABSPATH' ) || exit;

/**
 * The free plugin keeps only a few recent scans. Pro keeps a compact snapshot of every finished
 * scan whose results differ from the last one (up to 20), each with what changed since the
 * snapshot before it: new Blockers, fixed ones, and anything that got better or worse.
 */
final class History {

	/** Site option (not autoloaded) holding the snapshots, oldest first. */
	const OPTION = 'airworthy_pro_history';

	/** How many snapshots to keep. */
	const MAX = 20;

	/** How bad each verdict is, for telling better from worse. */
	const RANK = array(
		'ready'      => 0,
		'guarded'    => 1,
		'suppressed' => 2,
		'warnings'   => 3,
		'unknown'    => 4,
		'blocker'    => 5,
	);

	/**
	 * Every snapshot, oldest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$all = get_site_option( self::OPTION, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * The newest snapshot, or null.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$all = self::all();
		return $all ? end( $all ) : null;
	}

	/**
	 * Forgets all snapshots.
	 */
	public static function clear() {
		delete_site_option( self::OPTION );
	}

	/**
	 * Reads a finished scan into a snapshot (no change information yet).
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|null Null when the scan isn't finished.
	 */
	public static function snapshot_from_scan( $scan_id ) {
		global $wpdb;
		$t = \Airworthy\Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
		$scan = $wpdb->get_row( $wpdb->prepare( "SELECT id, target_php, from_php, status, finished_at, count_blocker, count_unknown, count_suppressed, count_guarded, count_warnings, count_ready FROM %i WHERE id = %d AND status = 'complete'", $t['scans'], $scan_id ) );
		if ( ! $scan ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT type, slug, name, version, verdict FROM %i WHERE scan_id = %d AND status = 'done'", $t['components'], $scan_id ) );
		$components = array();
		foreach ( (array) $rows as $r ) {
			$components[ $r->type . '|' . $r->slug ] = array(
				'name'    => (string) $r->name,
				'version' => (string) $r->version,
				'verdict' => (string) $r->verdict,
			);
		}
		ksort( $components );
		return array(
			'scan_id'    => (int) $scan->id,
			'at'         => (string) $scan->finished_at,
			'target'     => (string) $scan->target_php,
			'from'       => (string) $scan->from_php,
			'counts'     => array(
				'blocker'    => (int) $scan->count_blocker,
				'unknown'    => (int) $scan->count_unknown,
				'suppressed' => (int) $scan->count_suppressed,
				'guarded'    => (int) $scan->count_guarded,
				'warnings'   => (int) $scan->count_warnings,
				'ready'      => (int) $scan->count_ready,
			),
			'components' => $components,
			'fp'         => self::fingerprint( $scan->target_php, $components ),
		);
	}

	/**
	 * A short fingerprint of a result set: the target, and every plugin or theme with its version
	 * and verdict. Two scans with the same fingerprint say the same thing.
	 *
	 * @param string $target     PHP version checked against.
	 * @param array  $components Key => name, version, verdict.
	 * @return string
	 */
	public static function fingerprint( $target, array $components ) {
		$parts = array( (string) $target );
		foreach ( $components as $key => $c ) {
			$parts[] = $key . '@' . $c['version'] . '=' . $c['verdict'];
		}
		return md5( implode( "\n", $parts ) );
	}

	/**
	 * What changed from one snapshot to the next.
	 *
	 * @param array|null $old Earlier snapshot, or null for the first one.
	 * @param array      $current Later snapshot.
	 * @return array{blockers:array,fixed:array,worse:array,better:array,added:array,removed:array,updated:array,target_changed:bool} Each list holds arrays: name, from (verdict or ''), to (verdict or ''), old_version, version.
	 */
	public static function diff( $old, array $current ) {
		$out = array(
			'blockers'       => array(),
			'fixed'          => array(),
			'worse'          => array(),
			'better'         => array(),
			'added'          => array(),
			'removed'        => array(),
			'updated'        => array(),
			'target_changed' => false,
		);
		if ( ! $old ) {
			return $out;
		}
		$out['target_changed'] = $old['target'] !== $current['target'];
		if ( $out['target_changed'] ) {
			return $out; // A different PHP version: the verdicts can't be compared one to one.
		}
		foreach ( $current['components'] as $key => $c ) {
			$was = isset( $old['components'][ $key ] ) ? $old['components'][ $key ] : null;
			$row = array(
				'name'        => $c['name'],
				'from'        => $was ? $was['verdict'] : '',
				'to'          => $c['verdict'],
				'old_version' => $was ? $was['version'] : '',
				'version'     => $c['version'],
			);
			if ( ! $was ) {
				$out['added'][] = $row;
				if ( 'blocker' === $c['verdict'] ) {
					$out['blockers'][] = $row;
				}
				continue;
			}
			if ( $was['version'] !== $c['version'] ) {
				$out['updated'][] = $row;
			}
			if ( $was['verdict'] === $c['verdict'] ) {
				continue;
			}
			$rank_was = isset( self::RANK[ $was['verdict'] ] ) ? self::RANK[ $was['verdict'] ] : 0;
			$rank_now = isset( self::RANK[ $c['verdict'] ] ) ? self::RANK[ $c['verdict'] ] : 0;
			if ( 'blocker' === $c['verdict'] ) {
				$out['blockers'][] = $row;
			} elseif ( 'blocker' === $was['verdict'] ) {
				$out['fixed'][] = $row;
			} elseif ( $rank_now > $rank_was ) {
				$out['worse'][] = $row;
			} else {
				$out['better'][] = $row;
			}
		}
		foreach ( $old['components'] as $key => $c ) {
			if ( ! isset( $current['components'][ $key ] ) ) {
				$out['removed'][] = array(
					'name'        => $c['name'],
					'from'        => $c['verdict'],
					'to'          => '',
					'old_version' => $c['version'],
					'version'     => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a diff holds anything worth knowing.
	 *
	 * @param array $diff From diff().
	 * @return bool
	 */
	public static function has_changes( array $diff ) {
		foreach ( array( 'blockers', 'fixed', 'worse', 'better', 'added', 'removed' ) as $k ) {
			if ( $diff[ $k ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Saves a finished scan as a snapshot when its results differ from the newest one.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $trigger scheduled | update | manual.
	 * @return array|null array( snapshot, diff, previous ) when saved; null when the scan isn't
	 *                    finished or nothing changed.
	 */
	public static function record( $scan_id, $trigger ) {
		$snap = self::snapshot_from_scan( (int) $scan_id );
		if ( ! $snap ) {
			return null;
		}
		$all  = self::all();
		$prev = $all ? end( $all ) : null;
		if ( $prev && $prev['fp'] === $snap['fp'] ) {
			return null;
		}
		$diff            = self::diff( $prev, $snap );
		$snap['trigger'] = in_array( $trigger, array( 'scheduled', 'update', 'manual' ), true ) ? $trigger : 'manual';
		$snap['changes'] = self::compact( $diff );
		$all[]           = $snap;
		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, -self::MAX );
		}
		self::save( $all );
		return array(
			'snapshot' => $snap,
			'diff'     => $diff,
			'previous' => $prev,
		);
	}

	/**
	 * Saves the snapshots. Not autoloaded: the list can be large and is only read on the
	 * Monitoring tab.
	 *
	 * @param array[] $all Snapshots, oldest first.
	 */
	private static function save( array $all ) {
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $all );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * A diff trimmed to what the history list shows (names and verdicts, no long lists).
	 *
	 * @param array $diff From diff().
	 * @return array
	 */
	private static function compact( array $diff ) {
		$out = array( 'target_changed' => (bool) $diff['target_changed'] );
		foreach ( array( 'blockers', 'fixed', 'worse', 'better', 'added', 'removed' ) as $k ) {
			$out[ $k ] = array_slice( $diff[ $k ], 0, 25 );
		}
		return $out;
	}
}
