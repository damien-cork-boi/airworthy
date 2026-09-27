<?php
/**
 * REST endpoints for the admin screen and other clients.
 *
 * @package Airworthy
 */

namespace Airworthy\Rest;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Scan\Queue;
use Airworthy\Scan\Verdict;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /airworthy/v1. The admin screen only ever talks to these; they read results
 * back from the plugin's tables.
 *
 * Security: every route requires the scan capability (manage_options, or
 * manage_network_options on multisite). Browser requests authenticate with the logged-in
 * cookie, which WordPress only honours together with a valid wp_rest nonce (X-WP-Nonce);
 * without one the request is anonymous and refused. Queries are prepared, and file paths in
 * results are always relative to the plugin or theme folder.
 */
final class Controller {

	const NAMESPACE_V1 = 'airworthy/v1';

	/**
	 * Registers routes (on rest_api_init).
	 */
	public static function register_routes() {
		$can  = array( Capabilities::class, 'current_user_can_scan' );
		$id   = array(
			'validate_callback' => static function ( $v ) {
				return is_numeric( $v ) && (int) $v > 0;
			},
			'sanitize_callback' => 'absint',
		);
		$ns   = self::NAMESPACE_V1;
		$scan = '/scans/(?P<id>\d+)';

		register_rest_route(
			$ns,
			'/scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => $can,
				'args'                => array(
					'target' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => Targets::ALL,
						'description'       => __( 'Exact PHP version to check against.', 'airworthy' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/scans/current',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'current' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$ns,
			$scan,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_scan' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/components/(?P<component>\d+)/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'issues' ),
				'permission_callback' => $can,
				'args'                => array(
					'id'        => $id,
					'component' => $id,
					'severity'  => array(
						'type' => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => array( 'error', 'warning', 'scan', 'notice' ),
						),
					),
					'context'   => array(
						'type' => 'array',
						'items' => array(
							'type' => 'string',
							'enum' => array( 'plain', 'guarded', 'suppressed' ),
						),
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
		register_rest_route(
			$ns,
			$scan . '/nudge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'nudge' ),
				'permission_callback' => $can,
				'args'                => array( 'id' => $id ),
			)
		);
	}

	/**
	 * POST /scans: starts a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( \WP_REST_Request $request ) {
		$id = Queue::start( (string) $request['target'], get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			$id->add_data( array( 'status' => 'airworthy_scan_running' === $id->get_error_code() ? 409 : 400 ) );
			return $id;
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( $id ) ), 201 );
	}

	/**
	 * GET /scans/current: the unfinished scan, or else the latest one, with per-component progress.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function current() {
		global $wpdb;
		$t  = Installer::tables();
		$id = Queue::active_scan_id();
		if ( ! $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
			$id = (int) $wpdb->get_var( "SELECT id FROM {$t['scans']} ORDER BY id DESC LIMIT 1" );
		}
		if ( ! $id ) {
			return new \WP_Error( 'airworthy_no_scan', __( 'No scan has been run yet.', 'airworthy' ), array( 'status' => 404 ) );
		}
		return self::get_scan( array( 'id' => $id ) );
	}

	/**
	 * GET /scans/{id}: summary and every component with its verdict and counts.
	 *
	 * @param \WP_REST_Request|array $request Request (or ['id' => …]).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_scan( $request ) {
		global $wpdb;
		$scan = self::scan_row( (int) $request['id'] );
		if ( ! $scan ) {
			return self::not_found();
		}
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, slug, name, version, is_active, status, verdict, errors, guarded, suppressed, warnings, files_total, files_done, files_failed, files_skipped, files_ignored, wporg FROM {$t['components']} WHERE scan_id = %d ORDER BY id", $scan->id ) );

		$order      = array_flip( Verdict::ALL );
		$components = array();
		foreach ( $rows as $r ) {
			$components[] = array(
				'id'         => (int) $r->id,
				'type'       => $r->type,
				'slug'       => $r->slug,
				'name'       => $r->name,
				'version'    => $r->version,
				'active'     => (bool) $r->is_active,
				'status'     => $r->status,
				'verdict'    => $r->verdict,
				'counts'     => array(
					'errors'     => (int) $r->errors,
					'suppressed' => (int) $r->suppressed,
					'guarded'    => (int) $r->guarded,
					'warnings'   => (int) $r->warnings,
				),
				'files'      => array(
					'total'   => (int) $r->files_total,
					'checked' => (int) $r->files_done,
					'failed'  => (int) $r->files_failed,
					'skipped' => (int) $r->files_skipped,
					'ignored' => (int) $r->files_ignored,
				),
				'wporg'      => $r->wporg ? json_decode( $r->wporg, true ) : null,
			);
		}
		// Most serious first, then by name.
		usort(
			$components,
			static function ( $a, $b ) use ( $order ) {
				$va = isset( $order[ $a['verdict'] ] ) ? $order[ $a['verdict'] ] : count( $order );
				$vb = isset( $order[ $b['verdict'] ] ) ? $order[ $b['verdict'] ] : count( $order );
				return $va === $vb ? strcasecmp( $a['name'], $b['name'] ) : $va - $vb;
			}
		);

		$out               = self::scan_summary( $scan );
		$out['components'] = $components;
		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * GET /scans/{id}/components/{component}/issues: findings with file, line and message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function issues( \WP_REST_Request $request ) {
		global $wpdb;
		$t     = Installer::tables();
		$scan  = (int) $request['id'];
		$comp  = (int) $request['component'];
		$where = array( 'scan_id = %d', 'component_id = %d' );
		$args  = array( $scan, $comp );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['components']} WHERE id = %d AND scan_id = %d", $comp, $scan ) ) ) {
			return self::not_found();
		}
		foreach ( array( 'severity', 'context' ) as $field ) {
			$values = array_values( array_filter( (array) $request[ $field ] ) );
			if ( $values ) {
				$where[] = $field . ' IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$args    = array_merge( $args, $values );
			}
		}
		$per_page = (int) $request['per_page'];
		$offset   = ( (int) $request['page'] - 1 ) * $per_page;
		$sql_where = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; placeholders built above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['issues']} WHERE $sql_where", $args ) );
		// Scan problems first, then errors, then warnings; within each by file and line.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; placeholders built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT file, line, severity, context, rule, message FROM {$t['issues']} WHERE $sql_where ORDER BY CASE severity WHEN 'scan' THEN 0 WHEN 'error' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END, CASE context WHEN 'plain' THEN 0 WHEN 'suppressed' THEN 1 ELSE 2 END, file, line LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ) );

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'file'     => $r->file,
				'line'     => (int) $r->line,
				'severity' => $r->severity,
				'context'  => $r->context,
				'rule'     => $r->rule,
				'message'  => $r->message,
			);
		}
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	/**
	 * POST /scans/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function cancel( \WP_REST_Request $request ) {
		if ( ! self::scan_row( (int) $request['id'] ) ) {
			return self::not_found();
		}
		Queue::cancel( (int) $request['id'] );
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $request['id'] ) ), 200 );
	}

	/**
	 * POST /scans/{id}/nudge: runs one batch in this request. The admin screen calls it while
	 * open, so scans still progress on sites where WP-Cron is disabled or rarely triggered.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function nudge( \WP_REST_Request $request ) {
		$scan = self::scan_row( (int) $request['id'] );
		if ( ! $scan ) {
			return self::not_found();
		}
		if ( in_array( $scan->status, array( 'queued', 'running' ), true ) ) {
			Queue::run_batch( (int) $scan->id ); // Returns at once if another runner holds the lock.
		}
		return new \WP_REST_Response( self::scan_summary( self::scan_row( (int) $scan->id ) ), 200 );
	}

	/**
	 * Loads a scan row.
	 *
	 * @param int $id Scan ID.
	 * @return object|null
	 */
	private static function scan_row( $id ) {
		global $wpdb;
		$t = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['scans']} WHERE id = %d", $id ) );
	}

	/**
	 * Public shape of a scan.
	 *
	 * @param object $s Scan row.
	 * @return array
	 */
	private static function scan_summary( $s ) {
		$counts = array();
		foreach ( Verdict::ALL as $verdict ) {
			$counts[ $verdict ] = (int) $s->{'count_' . $verdict};
		}
		$total = (int) $s->files_total;
		return array(
			'id'          => (int) $s->id,
			'target_php'  => $s->target_php,
			'host_php'    => $s->host_php,
			'status'      => $s->status,
			'progress'    => array(
				'files_done'  => (int) $s->files_done,
				'files_total' => $total,
				'percent'     => $total ? (int) floor( 100 * (int) $s->files_done / $total ) : ( 'complete' === $s->status ? 100 : 0 ),
				'components'  => (int) $s->components_total,
			),
			'counts'      => $counts,
			'created_at'  => self::iso( $s->created_at ),
			'started_at'  => self::iso( $s->started_at ),
			'finished_at' => self::iso( $s->finished_at ),
			'error'       => 'failed' === $s->status ? __( 'The scan could not be completed. Try again, or ask your host whether background tasks (WP-Cron) are working.', 'airworthy' ) : null,
		);
	}

	/**
	 * MySQL UTC datetime to ISO 8601.
	 *
	 * @param string|null $datetime Datetime.
	 * @return string|null
	 */
	private static function iso( $datetime ) {
		return $datetime ? gmdate( 'c', (int) strtotime( $datetime . ' UTC' ) ) : null;
	}

	/**
	 * 404 error.
	 *
	 * @return \WP_Error
	 */
	private static function not_found() {
		return new \WP_Error( 'airworthy_not_found', __( 'Not found.', 'airworthy' ), array( 'status' => 404 ) );
	}
}
