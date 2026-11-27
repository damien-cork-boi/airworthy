<?php
/**
 * The Network tab: every site of a multisite network at a glance.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Scan\Verdict;

defined( 'ABSPATH' ) || exit;

/**
 * A network scan checks every plugin and theme any site uses. This tab turns that into the answer
 * an agency or host wants: which sites are ready for the new PHP version and which are not, and
 * which plugin to fix first because it sits on the most sites. It only reads site settings and the
 * last scan; nothing is scanned again and nothing leaves the network. Shown on multisite only.
 */
final class Network {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'network';

	/** Admin-post action name for the CSV. */
	const CSV = 'airworthy_pro_network_csv';

	/** Most sites read in one go. */
	const MAX_SITES = 300;

	/**
	 * Hooks the tab on a multisite network. Elsewhere it does not exist.
	 */
	public static function register() {
		if ( ! is_multisite() ) {
			return;
		}
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 9 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::CSV, array( __CLASS__, 'download' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Network', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-networking';
		return $icons;
	}

	/**
	 * The tab's address.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( 'tab', self::TAB, \Airworthy\Admin\Page::url() );
	}

	/**
	 * Pro's styles on this tab.
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which styles to load.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || \Airworthy\Admin\Page::SLUG !== $_GET['page'] || self::TAB !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_style( 'airworthy-pro-admin', AIRWORTHY_PRO_URL . 'assets/admin.css', array(), AIRWORTHY_PRO_VERSION . '.' . (int) filemtime( AIRWORTHY_PRO_DIR . 'assets/admin.css' ) );
	}

	// ── Data ───────────────────────────────────────────────────────────────────────────────────

	/**
	 * What every site of the network uses.
	 *
	 * @return array{sites:array[],truncated:bool} Each site: id, name, url, plugins (files), stylesheet, template.
	 */
	public static function read_sites() {
		$out   = array();
		$sites = get_sites(
			array(
				'number'   => self::MAX_SITES + 1,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
				'orderby'  => 'id',
			)
		);
		foreach ( array_slice( $sites, 0, self::MAX_SITES ) as $site ) {
			$id    = (int) $site->blog_id;
			$out[] = array(
				'id'         => $id,
				'name'       => (string) get_blog_option( $id, 'blogname', '' ),
				'url'        => (string) get_home_url( $id ),
				'plugins'    => array_values( (array) get_blog_option( $id, 'active_plugins', array() ) ),
				'stylesheet' => (string) get_blog_option( $id, 'stylesheet', '' ),
				'template'   => (string) get_blog_option( $id, 'template', '' ),
			);
		}
		return array(
			'sites'     => $out,
			'truncated' => count( $sites ) > self::MAX_SITES,
		);
	}

	/**
	 * Plugins active on every site because they are network-activated.
	 *
	 * @return string[] Plugin files.
	 */
	public static function network_files() {
		return array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	/**
	 * The plugins and themes of the latest scan, with their verdicts.
	 *
	 * @return array{scan:object|null,components:array<string,array{name:string,version:string,verdict:string}>}
	 */
	public static function read_components() {
		global $wpdb;
		$t    = Installer::tables();
		$id   = Scans::latest_id();
		$scan = null;
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
			$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target_php, finished_at FROM %i WHERE id = %d', $t['scans'], $id ) );
		}
		$out = array();
		if ( $scan ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, slug, name, version, verdict FROM %i WHERE scan_id = %d AND status = 'done' AND verdict IS NOT NULL", $t['components'], $scan->id ) );
			foreach ( $rows as $r ) {
				$out[ $r->type . '|' . $r->slug ] = array(
					'name'    => (string) $r->name,
					'version' => (string) $r->version,
					'verdict' => (string) $r->verdict,
				);
			}
		}
		return array(
			'scan'       => $scan,
			'components' => $out,
		);
	}

	/**
	 * The component key of a plugin file, as a scan names it.
	 *
	 * @param string $file e.g. "akismet/akismet.php" or "hello.php".
	 * @return string e.g. "plugin|akismet".
	 */
	public static function plugin_key( $file ) {
		return 'plugin|' . ( false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file ) );
	}

	/**
	 * Works out, for every site, how ready it is, and for every problem plugin how many sites it is on.
	 *
	 * @param array[]  $sites         From read_sites().
	 * @param string[] $network_files Network-activated plugin files.
	 * @param array    $components    From read_components(): key => name, version, verdict.
	 * @return array{sites:array[],plugins:array[],totals:array}
	 */
	public static function build( array $sites, array $network_files, array $components ) {
		$net_keys  = array_map( array( __CLASS__, 'plugin_key' ), $network_files );
		$rank      = array_flip( array( 'blocker', 'unknown', 'suppressed', 'warnings', 'guarded', 'ready' ) );
		$by_plugin = array();
		$rows      = array();

		foreach ( $sites as $site ) {
			$keys = $net_keys;
			foreach ( $site['plugins'] as $file ) {
				$keys[] = self::plugin_key( (string) $file );
			}
			foreach ( array( $site['stylesheet'], $site['template'] ) as $theme ) {
				if ( '' !== $theme ) {
					$keys[] = 'theme|' . $theme;
				}
			}
			$keys   = array_values( array_unique( $keys ) );
			$counts = array_fill_keys( Verdict::ALL, 0 );
			$issues = array();
			$known  = 0;
			foreach ( $keys as $key ) {
				if ( ! isset( $components[ $key ] ) ) {
					continue; // Not in the last scan (ignored, or added since).
				}
				++$known;
				$verdict = $components[ $key ]['verdict'];
				if ( isset( $counts[ $verdict ] ) ) {
					++$counts[ $verdict ];
				}
				if ( in_array( $verdict, array( 'blocker', 'unknown', 'suppressed', 'warnings' ), true ) ) {
					$issues[] = array(
						'key'     => $key,
						'name'    => $components[ $key ]['name'],
						'verdict' => $verdict,
					);
					if ( ! isset( $by_plugin[ $key ] ) ) {
						$by_plugin[ $key ] = array(
							'key'        => $key,
							'name'       => $components[ $key ]['name'],
							'version'    => $components[ $key ]['version'],
							'verdict'    => $verdict,
							'sites'      => 0,
							'site_names' => array(),
							'network'    => in_array( $key, $net_keys, true ),
						);
					}
					++$by_plugin[ $key ]['sites'];
					$by_plugin[ $key ]['site_names'][] = '' !== $site['name'] ? $site['name'] : $site['url'];
				}
			}
			usort(
				$issues,
				static function ( $a, $b ) use ( $rank ) {
					return array( $rank[ $a['verdict'] ], $a['name'] ) <=> array( $rank[ $b['verdict'] ], $b['name'] );
				}
			);
			$readiness = Verdict::readiness( $counts );
			$rows[]    = array(
				'id'        => $site['id'],
				'name'      => '' !== $site['name'] ? $site['name'] : $site['url'],
				'url'       => $site['url'],
				'total'     => $known,
				'counts'    => $counts,
				'readiness' => $readiness['percent'],
				'issues'    => $issues,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return array( null === $a['readiness'] ? 101 : $a['readiness'], $a['name'] ) <=> array( null === $b['readiness'] ? 101 : $b['readiness'], $b['name'] );
			}
		);
		$plugins = array_values( $by_plugin );
		usort(
			$plugins,
			static function ( $a, $b ) use ( $rank ) {
				return array( $rank[ $a['verdict'] ], -$a['sites'], $a['name'] ) <=> array( $rank[ $b['verdict'] ], -$b['sites'], $b['name'] );
			}
		);
		$with_blocker = 0;
		foreach ( $rows as $r ) {
			if ( $r['counts']['blocker'] > 0 ) {
				++$with_blocker;
			}
		}
		return array(
			'sites'   => $rows,
			'plugins' => $plugins,
			'totals'  => array(
				'sites'        => count( $rows ),
				'with_blocker' => $with_blocker,
				'ready_sites'  => count( $rows ) - $with_blocker,
			),
		);
	}

	/**
	 * Reads everything and builds the overview.
	 *
	 * @return array{scan:object|null,truncated:bool,view:array}
	 */
	public static function overview() {
		$data  = self::read_components();
		$sites = self::read_sites();
		return array(
			'scan'      => $data['scan'],
			'truncated' => $sites['truncated'],
			'view'      => self::build( $sites['sites'], self::network_files(), $data['components'] ),
		);
	}

	// ── The tab ────────────────────────────────────────────────────────────────────────────────

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$data = self::overview();
		$view = $data['view'];
		?>
		<div class="airworthy-card airworthy-pro-network">
			<h2>🌐 <?php esc_html_e( 'Network overview', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted"><?php esc_html_e( 'Which sites on your network are ready for the PHP version you checked, and which plugin to fix first because it is on the most sites. It uses your last scan and each site\'s list of active plugins and theme; nothing is scanned again.', 'airworthy-pro' ); ?></p>
			<?php if ( ! $data['scan'] ) : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Run a scan first on the Scan tab. The overview is built from its results.', 'airworthy-pro' ); ?></p>
			<?php else : ?>
				<ul class="airworthy-pro-facts">
					<li><span aria-hidden="true">🎯</span> <?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'Checked against PHP %s', 'airworthy-pro' ), $data['scan']->target_php ) ); ?></li>
					<li><span aria-hidden="true">🌐</span> <?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( '%d site', '%d sites', $view['totals']['sites'], 'airworthy-pro' ), $view['totals']['sites'] ) ); ?></li>
					<li><span aria-hidden="true">✅</span> <?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( '%d site with no Blocker', '%d sites with no Blocker', $view['totals']['ready_sites'], 'airworthy-pro' ), $view['totals']['ready_sites'] ) ); ?></li>
					<li><span aria-hidden="true">🔴</span> <?php echo esc_html( sprintf( /* translators: %d: number of sites. */ _n( '%d site with a Blocker', '%d sites with a Blocker', $view['totals']['with_blocker'], 'airworthy-pro' ), $view['totals']['with_blocker'] ) ); ?></li>
					<?php if ( $data['truncated'] ) : ?>
						<li class="is-warn"><span aria-hidden="true">⚠️</span> <?php echo esc_html( sprintf( /* translators: %d: number of sites. */ __( 'Only the first %d sites are shown.', 'airworthy-pro' ), self::MAX_SITES ) ); ?></li>
					<?php endif; ?>
				</ul>
				<div class="airworthy-pro-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::CSV ); ?>" />
						<?php wp_nonce_field( self::CSV ); ?>
						<button type="submit" class="button">⬇️ <?php esc_html_e( 'Download as CSV', 'airworthy-pro' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $data['scan'] && $view['plugins'] ) : ?>
			<div class="airworthy-card airworthy-pro-network-plugins">
				<h2>🔧 <?php esc_html_e( 'Fix these first', 'airworthy-pro' ); ?></h2>
				<p class="airworthy-muted"><?php esc_html_e( 'Plugins and themes with a problem, with the ones on the most sites first within each kind of problem. Fixing or replacing one of these helps every site listed.', 'airworthy-pro' ); ?></p>
				<div class="airworthy-pro-table-wrap">
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Plugin or theme', 'airworthy-pro' ); ?></th><th><?php esc_html_e( 'Result', 'airworthy-pro' ); ?></th><th><?php esc_html_e( 'Sites', 'airworthy-pro' ); ?></th><th><?php esc_html_e( 'Where', 'airworthy-pro' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( array_slice( $view['plugins'], 0, 50 ) as $p ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $p['name'] ); ?></strong> <span class="airworthy-muted"><?php echo esc_html( $p['version'] ? 'v' . $p['version'] : '' ); ?></span></td>
									<td><?php echo esc_html( Alerts::VERDICTS[ $p['verdict'] ][0] . ' ' . Alerts::VERDICTS[ $p['verdict'] ][1] ); ?></td>
									<td><?php echo (int) $p['sites']; ?></td>
									<td>
										<?php
										if ( $p['network'] ) {
											esc_html_e( 'Network-activated: every site', 'airworthy-pro' );
										} else {
											echo esc_html( implode( ', ', array_slice( $p['site_names'], 0, 5 ) ) . ( count( $p['site_names'] ) > 5 ? sprintf( /* translators: %d: number of further sites. */ __( ' and %d more', 'airworthy-pro' ), count( $p['site_names'] ) - 5 ) : '' ) );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
		<?php if ( $data['scan'] && $view['sites'] ) : ?>
			<div class="airworthy-card airworthy-pro-network-sites">
				<h2>🏁 <?php esc_html_e( 'Sites, least ready first', 'airworthy-pro' ); ?></h2>
				<div class="airworthy-pro-table-wrap">
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Site', 'airworthy-pro' ); ?></th><th><?php esc_html_e( 'Ready', 'airworthy-pro' ); ?></th><th>🔴</th><th>⚪</th><th>🟡</th><th><?php esc_html_e( 'What needs attention', 'airworthy-pro' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $view['sites'] as $s ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $s['name'] ); ?></strong><br /><span class="airworthy-muted"><?php echo esc_html( $s['url'] ); ?></span></td>
									<td><?php echo esc_html( null === $s['readiness'] ? '—' : $s['readiness'] . '%' ); ?></td>
									<td><?php echo (int) $s['counts']['blocker']; ?></td>
									<td><?php echo (int) $s['counts']['unknown']; ?></td>
									<td><?php echo (int) ( $s['counts']['warnings'] + $s['counts']['suppressed'] ); ?></td>
									<td>
										<?php
										$names = array();
										foreach ( array_slice( $s['issues'], 0, 6 ) as $i ) {
											$names[] = Alerts::VERDICTS[ $i['verdict'] ][0] . ' ' . $i['name'];
										}
										echo $names ? esc_html( implode( ' · ', $names ) ) : '✅';
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	// ── CSV ────────────────────────────────────────────────────────────────────────────────────

	/**
	 * The CSV rows, one per site.
	 *
	 * @param array $view From build().
	 * @return array[] First row is the header.
	 */
	public static function csv_rows( array $view ) {
		$rows = array( array( 'Site', 'Address', 'Ready %', 'Blockers', 'Unknown', 'Warnings', 'Plugins and themes in use', 'Needs attention' ) );
		foreach ( $view['sites'] as $s ) {
			$names = array();
			foreach ( $s['issues'] as $i ) {
				$names[] = $i['name'] . ' (' . Alerts::VERDICTS[ $i['verdict'] ][1] . ')';
			}
			$rows[] = array(
				self::safe_cell( $s['name'] ),
				self::safe_cell( $s['url'] ),
				null === $s['readiness'] ? '' : $s['readiness'],
				$s['counts']['blocker'],
				$s['counts']['unknown'],
				$s['counts']['warnings'] + $s['counts']['suppressed'],
				$s['total'],
				self::safe_cell( implode( '; ', $names ) ),
			);
		}
		return $rows;
	}

	/**
	 * Stops a spreadsheet running a cell as a formula.
	 *
	 * @param string $cell Text.
	 * @return string
	 */
	public static function safe_cell( $cell ) {
		$cell = (string) $cell;
		return ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) ? "'" . $cell : $cell;
	}

	/**
	 * Sends the CSV.
	 */
	public static function download() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::CSV );
		$data = self::overview();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="airworthy-network-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the download.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Byte order mark so Excel reads UTF-8.
		foreach ( self::csv_rows( $data['view'] ) as $row ) {
			fputcsv( $out, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
