<?php
/**
 * Every PHP version at once: verdicts for every PHP version Airworthy knows (8.0 to 8.6) from one scan.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Scan\Verdict;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A scan against the newest PHP version finds every change up to it, and each finding says
 * when its change happened ("deprecated since PHP 7.2 and removed since PHP 8.0"). So one
 * scan against the newest version gives the verdict for every older one too: at PHP 8.2 a
 * function removed in 8.0 is an error, one deprecated in 8.1 a warning, one deprecated in
 * 8.4 nothing yet. The verdict rules are the free plugin's (Verdict::from_counts).
 *
 * One approximation: code guarded by a version check is treated as guarded on every version
 * (the scan knows the guard holds on the newest one). Guards almost always test for PHP 7 or
 * older, where that's exact.
 */
final class Versions {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'versions';

	/** Action name for admin-post.php (start a scan against the newest version). */
	const ACTION = 'airworthy_pro_scan_all';

	/**
	 * Rules whose messages name no PHP version, with the version their change came in.
	 * RemovedSerializable: the Serializable interface was deprecated in PHP 8.1.
	 */
	const RULE_VERSIONS = array(
		'PHPCompatibility.Interfaces.RemovedSerializable' => '8.1',
	);

	/** Findings fetched per REST page. */
	const PAGE = 500;

	/**
	 * Hooks the tab and the "check every version" action.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 7 );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'start_scan' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'PHP versions', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The newest PHP version Airworthy checks.
	 *
	 * @return string
	 */
	public static function newest() {
		$all = Targets::ALL;
		return (string) end( $all );
	}

	/**
	 * Starts a scan against the newest version, then shows its progress.
	 */
	public static function start_scan() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$result = \Airworthy\Scan\Queue::start( self::newest(), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 409 );
		}
		wp_safe_redirect( \Airworthy\Admin\Page::url() );
		exit;
	}

	/**
	 * GET from Airworthy's REST API, as the current user.
	 *
	 * @param string $path   Route under /airworthy/v1.
	 * @param array  $params Query parameters.
	 * @return array|null Data and total ("X-WP-Total"), or null.
	 */
	private static function api( $path, array $params = array() ) {
		$request = new \WP_REST_Request( 'GET', '/airworthy/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return null;
		}
		$headers = $response->get_headers();
		return array(
			'data'  => $response->get_data(),
			'total' => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : 0,
		);
	}

	/**
	 * The latest finished scan against the newest version, with its components.
	 *
	 * @return array|null
	 */
	public static function latest_full_scan() {
		$id = Scans::latest_id( self::newest() );
		if ( ! $id ) {
			return null;
		}
		$res = self::api( '/scans/' . $id );
		return $res ? $res['data'] : null;
	}

	/**
	 * Every finding of one component (all pages).
	 *
	 * @param int $scan_id      Scan ID.
	 * @param int $component_id Component ID.
	 * @return array[]
	 */
	private static function findings( $scan_id, $component_id ) {
		$all  = array();
		$page = 1;
		do {
			$res = self::api(
				'/scans/' . $scan_id . '/components/' . $component_id . '/issues',
				array(
					'per_page' => self::PAGE,
					'page'     => $page,
				)
			);
			if ( ! $res ) {
				break;
			}
			$all  = array_merge( $all, (array) $res['data'] );
			$have = count( $all );
			++$page;
		} while ( $have < $res['total'] && $page < 50 );
		return $all;
	}

	/**
	 * How a finding counts on one PHP version: "error", "warning" or null (not yet). Never more
	 * serious than the scan against the newest version rated it.
	 *
	 * @param array  $finding severity, message.
	 * @param string $target  "x.y".
	 * @return string|null
	 */
	public static function severity_at( array $finding, $target ) {
		$message = (string) $finding['message'];
		// "PHP" is sometimes left out ("deprecated since 8.4").
		$removed = preg_match( '/removed (?:since|in|as of) (?:PHP )?(?:version )?(\d+\.\d+)/i', $message, $m ) ? $m[1] : null;
		$deprec  = preg_match( '/deprecated (?:since|in|as of) (?:PHP )?(?:version )?(\d+\.\d+)/i', $message, $m ) ? $m[1] : null;
		// Findings whose message names no version, dated by their rule.
		foreach ( self::RULE_VERSIONS as $prefix => $version ) {
			if ( null === $removed && null === $deprec && ! empty( $finding['rule'] ) && 0 === strpos( (string) $finding['rule'], $prefix ) ) {
				$deprec = $version;
			}
		}
		if ( null === $removed && null === $deprec ) {
			// No version named: when the message dates the change at all, use that; else it
			// counts as it did on the newest version (the cautious answer).
			$when = \Airworthy\Scan\Queue::change_version( $message );
			if ( null !== $when && version_compare( $target, $when, '<' ) ) {
				return null;
			}
			return $finding['severity'];
		}
		// Never more serious than on the newest version: PHPCompatibility rates some removals
		// as warnings (a removed ini setting is simply ignored), and those stay warnings.
		if ( null !== $removed && version_compare( $target, $removed, '>=' ) ) {
			return $finding['severity'];
		}
		if ( null !== $deprec && version_compare( $target, $deprec, '>=' ) ) {
			return 'warning';
		}
		return null;
	}

	/**
	 * Verdicts on every version for every component of a full scan.
	 *
	 * @param array $scan Scan from the REST API (with components).
	 * @return array{targets:string[],rows:array[],counts:array,ready_up_to:?string}
	 */
	public static function matrix( array $scan ) {
		$targets = array_values(
			array_filter(
				Targets::ALL,
				static function ( $t ) use ( $scan ) {
					return version_compare( $t, $scan['target_php'], '<=' );
				}
			)
		);
		$counts  = array();
		foreach ( $targets as $t ) {
			$counts[ $t ] = array_fill_keys( Verdict::ALL, 0 );
		}
		$rows = array();
		foreach ( (array) $scan['components'] as $c ) {
			if ( 'plugin' === $c['type'] && in_array( $c['slug'], array( 'airworthy', 'airworthy-pro' ), true ) ) {
				continue;
			}
			$has_findings = array_sum( (array) $c['counts'] ) > 0;
			$findings     = $has_findings ? self::findings( (int) $scan['id'], (int) $c['id'] ) : array();
			$requires     = ! empty( $c['wporg']['requires_php'] ) ? Targets::minor( $c['wporg']['requires_php'] ) : '';
			$verdicts     = array();
			foreach ( $targets as $t ) {
				$n = array(
					'errors'                => 0,
					'suppressed'            => 0,
					'guarded'               => 0,
					'warnings'              => 0,
					'files_failed'          => (int) $c['files']['failed'],
					'files_skipped'         => (int) $c['files']['skipped'],
					'requires_php_too_high' => '' !== $requires && version_compare( $requires, $t, '>' ) ? 1 : 0,
				);
				foreach ( $findings as $f ) {
					if ( 'scan' === $f['severity'] || 'notice' === $f['severity'] ) {
						continue; // Files not checked are counted above; notes never count.
					}
					$sev = self::severity_at( $f, $t );
					if ( null === $sev ) {
						continue;
					}
					// The free plugin's counting (Queue::scan_one()): every warning is a warning;
					// an error is existing, guarded, suppressed or a real error, in that order.
					if ( 'error' !== $sev ) {
						++$n['warnings'];
					} elseif ( 'existing' === $f['context'] ) {
						continue; // Already so on the PHP version the site runs now.
					} elseif ( 'guarded' === $f['context'] ) {
						++$n['guarded'];
					} elseif ( 'suppressed' === $f['context'] ) {
						++$n['suppressed'];
					} else {
						++$n['errors'];
					}
				}
				$verdicts[ $t ] = Verdict::from_counts( $n );
				++$counts[ $t ][ $verdicts[ $t ] ];
			}
			// The last version before the first one it can't run on.
			$works_up_to = null;
			$breaks_at   = null;
			foreach ( $targets as $t ) {
				if ( in_array( $verdicts[ $t ], array( Verdict::BLOCKER, Verdict::UNKNOWN ), true ) ) {
					$breaks_at = $t;
					break;
				}
				$works_up_to = $t;
			}
			$rows[] = array(
				'name'        => $c['name'],
				'slug'        => $c['slug'],
				'type'        => $c['type'],
				'version'     => $c['version'],
				'verdicts'    => $verdicts,
				'works_up_to' => $works_up_to,
				'breaks_at'   => $breaks_at,
				'same'        => 1 === count( array_unique( $verdicts ) ),
			);
		}
		// Plugins that break earliest first, then those whose verdict changes, then the rest.
		usort(
			$rows,
			static function ( $a, $b ) {
				$ka = $a['breaks_at'] ? '0' . $a['breaks_at'] : ( $a['same'] ? '2' : '1' );
				$kb = $b['breaks_at'] ? '0' . $b['breaks_at'] : ( $b['same'] ? '2' : '1' );
				return $ka === $kb ? strcasecmp( $a['name'], $b['name'] ) : strcmp( $ka, $kb );
			}
		);
		$ready_up_to = null;
		foreach ( $targets as $t ) {
			if ( $counts[ $t ][ Verdict::BLOCKER ] || $counts[ $t ][ Verdict::UNKNOWN ] ) {
				break;
			}
			$ready_up_to = $t;
		}
		return array(
			'targets'     => $targets,
			'rows'        => $rows,
			'counts'      => $counts,
			'ready_up_to' => $ready_up_to,
		);
	}

	/**
	 * The matrix of the latest full scan, cached until a newer scan finishes.
	 *
	 * @return array|null matrix() plus 'scan'.
	 */
	public static function latest_matrix() {
		$scan = self::latest_full_scan();
		if ( ! $scan ) {
			return null;
		}
		$key    = 'airworthy_pro_matrix_' . (int) $scan['id'] . '_' . md5( (string) $scan['finished_at'] );
		$matrix = get_site_transient( $key );
		if ( ! is_array( $matrix ) ) {
			$matrix = self::matrix( $scan );
			set_site_transient( $key, $matrix, DAY_IN_SECONDS );
		}
		$matrix['scan'] = $scan;
		return $matrix;
	}

	/**
	 * Short labels for the table cells.
	 *
	 * @return array<string,string>
	 */
	private static function labels() {
		return array(
			Verdict::BLOCKER    => __( 'Blocker', 'airworthy-pro' ),
			Verdict::UNKNOWN    => __( 'Unknown', 'airworthy-pro' ),
			Verdict::SUPPRESSED => __( 'Suppressed', 'airworthy-pro' ),
			Verdict::GUARDED    => __( 'Guarded', 'airworthy-pro' ),
			Verdict::WARNINGS   => __( 'Warnings', 'airworthy-pro' ),
			Verdict::READY      => __( 'Ready', 'airworthy-pro' ),
		);
	}

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$matrix  = self::latest_matrix();
		$newest  = self::newest();
		$current = Targets::minor( \Airworthy\Env::php_version() );
		$scan_it = wp_nonce_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ), self::ACTION );
		$running = \Airworthy\Scan\Queue::active_scan_id();
		?>
		<div class="airworthy-card airworthy-pro-versions">
			<h2><?php esc_html_e( 'Every PHP version at once', 'airworthy-pro' ); ?></h2>
			<?php if ( ! $matrix ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: newest PHP version. */
							__( 'One scan against PHP %s shows how the site does on every version from PHP 8.0 up, side by side, and the highest version it\'s ready for today.', 'airworthy-pro' ),
							$newest
						)
					);
					?>
				</p>
				<?php if ( $running ) : ?>
					<p class="airworthy-note"><?php esc_html_e( 'A scan is running. When it finishes, run one against the newest version from here.', 'airworthy-pro' ); ?></p>
				<?php else : ?>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url( $scan_it ); ?>"><?php esc_html_e( 'Check every version', 'airworthy-pro' ); ?></a></p>
				<?php endif; ?>
			</div>
				<?php
				return;
			endif;

			$scan    = $matrix['scan'];
			$labels  = self::labels();
			$targets = $matrix['targets'];
			$ready   = $matrix['ready_up_to'];
			$next    = null;
			foreach ( $targets as $t ) {
				if ( null === $ready || version_compare( $t, $ready, '>' ) ) {
					$next = $t;
					break;
				}
			}
			$blocking = array();
			if ( $next ) {
				foreach ( $matrix['rows'] as $r ) {
					if ( in_array( $r['verdicts'][ $next ], array( Verdict::BLOCKER, Verdict::UNKNOWN ), true ) ) {
						$blocking[] = $r['name'];
					}
				}
			}
			$same_rows = array_filter(
				$matrix['rows'],
				static function ( $r ) {
					return $r['same'] && array( Verdict::READY ) === array_values( array_unique( $r['verdicts'] ) );
				}
			);
			$show_rows = array_filter(
				$matrix['rows'],
				static function ( $r ) {
					return ! ( $r['same'] && array( Verdict::READY ) === array_values( array_unique( $r['verdicts'] ) ) );
				}
			);
		?>
			<div class="airworthy-headline airworthy-headline-<?php echo $ready ? 'good' : 'bad'; ?>">
				<span class="airworthy-headline-icon" aria-hidden="true"><?php echo $ready ? '✓' : '!'; ?></span>
				<div>
					<strong>
						<?php
						if ( $ready && $ready === $newest ) {
							/* translators: %s: PHP version. */
							echo esc_html( sprintf( __( 'Ready for every version up to PHP %s.', 'airworthy-pro' ), $ready ) );
						} elseif ( $ready ) {
							/* translators: %s: PHP version. */
							echo esc_html( sprintf( __( 'Ready for PHP %s today.', 'airworthy-pro' ), $ready ) );
						} else {
							esc_html_e( 'Not ready for PHP 8 yet.', 'airworthy-pro' );
						}
						?>
					</strong>
					<?php if ( $next && $blocking ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: PHP version, 2: list of plugin names. */
									_n( 'PHP %1$s needs attention first: %2$s.', 'PHP %1$s needs attention first: %2$s.', count( $blocking ), 'airworthy-pro' ),
									$next,
									implode( ', ', $blocking )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<p class="airworthy-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date, 2: PHP version, 3: server PHP version. */
						__( 'From the scan of %1$s against PHP %2$s. The server runs PHP %3$s.', 'airworthy-pro' ),
						date_i18n( get_option( 'date_format' ), strtotime( $scan['finished_at'] ) ),
						$scan['target_php'],
						$current
					)
				);
				?>
				<a href="<?php echo esc_url( $scan_it ); ?>"><?php esc_html_e( 'Check again', 'airworthy-pro' ); ?></a>
			</p>

			<div class="airworthy-pro-matrix-wrap">
				<table class="airworthy-pro-matrix">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Plugin or theme', 'airworthy-pro' ); ?></th>
							<?php foreach ( $targets as $t ) : ?>
								<?php $until = Targets::security_until( $t ); ?>
								<th scope="col" class="<?php echo $t === $current ? 'current' : ''; ?>">
									PHP <?php echo esc_html( $t ); ?>
									<span>
										<?php
										if ( $t === $current ) {
											esc_html_e( 'your server', 'airworthy-pro' );
										} elseif ( $until ) {
											echo esc_html(
												Targets::is_supported( $t ) ?
													/* translators: %s: year. */
													sprintf( __( 'fixes to %s', 'airworthy-pro' ), gmdate( 'Y', strtotime( $until ) ) ) :
													__( 'no more fixes', 'airworthy-pro' )
											);
										}
										?>
									</span>
								</th>
							<?php endforeach; ?>
							<th scope="col"><?php esc_html_e( 'Works up to', 'airworthy-pro' ); ?></th>
						</tr>
						<tr class="totals">
							<th scope="row"><?php esc_html_e( 'Need attention', 'airworthy-pro' ); ?></th>
							<?php foreach ( $targets as $t ) : ?>
								<?php $bad = $matrix['counts'][ $t ][ Verdict::BLOCKER ] + $matrix['counts'][ $t ][ Verdict::UNKNOWN ]; ?>
								<td class="<?php echo $bad ? 'bad' : 'good'; ?>"><?php echo $bad ? esc_html( (string) $bad ) : '✓'; ?></td>
							<?php endforeach; ?>
							<td></td>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $show_rows as $r ) : ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $r['name'] ); ?>
									<span><?php echo esc_html( $r['version'] ); ?></span>
								</th>
								<?php foreach ( $targets as $t ) : ?>
									<td><span class="cell cell-<?php echo esc_attr( $r['verdicts'][ $t ] ); ?>"><?php echo esc_html( $labels[ $r['verdicts'][ $t ] ] ); ?></span></td>
								<?php endforeach; ?>
								<td class="upto">
									<?php
									if ( null === $r['breaks_at'] ) {
										esc_html_e( 'Every version', 'airworthy-pro' );
									} elseif ( null === $r['works_up_to'] ) {
										esc_html_e( 'None of these', 'airworthy-pro' );
									} else {
										echo esc_html( 'PHP ' . $r['works_up_to'] );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $same_rows ) : ?>
				<p class="airworthy-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of plugins/themes, 2: their names. */
							_n( '%1$d more is Ready on every version: %2$s.', '%1$d more are Ready on every version: %2$s.', count( $same_rows ), 'airworthy-pro' ),
							count( $same_rows ),
							implode( ', ', wp_list_pluck( $same_rows, 'name' ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<p class="airworthy-small airworthy-muted"><?php esc_html_e( 'Worked out from one scan against the newest version: each finding says in which PHP version its change happened. Code behind a version check is counted as guarded on every version.', 'airworthy-pro' ); ?></p>
		</div>
		<?php
	}
}
