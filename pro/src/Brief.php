<?php
/**
 * The "Fix brief" tab: a ready-to-paste brief for a developer or an AI assistant.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Installer;
use Airworthy\Scan\Components;
use Airworthy\Scan\Explain;

defined( 'ABSPATH' ) || exit;

/**
 * Pick a plugin or theme that has problems on the PHP version you are heading for and Airworthy
 * writes a plain-text brief: what to fix, in which file and line, the line itself, why PHP objects,
 * and how to change it, with instructions that keep a developer or an AI assistant from rewriting
 * more than it should. Nothing leaves the site: the brief is built here and you copy it.
 */
final class Brief {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'brief';

	/** Most problems written into one brief. */
	const MAX_ISSUES = 40;

	/**
	 * Hooks the tab.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 9 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Fix brief', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-clipboard';
		return $icons;
	}

	/**
	 * The tab's address.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'tab' => self::TAB ), $args ), \Airworthy\Admin\Page::url() );
	}

	/**
	 * Pro's styles and the copy button's script on this tab.
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which assets to load.
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || \Airworthy\Admin\Page::SLUG !== $_GET['page'] || self::TAB !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_style( 'airworthy-pro-admin', AIRWORTHY_PRO_URL . 'assets/admin.css', array(), AIRWORTHY_PRO_VERSION . '.' . (int) filemtime( AIRWORTHY_PRO_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'airworthy-pro-brief', AIRWORTHY_PRO_URL . 'assets/brief.js', array(), AIRWORTHY_PRO_VERSION, true );
	}

	/**
	 * Plugins and themes in the latest scan that have problems to fix.
	 *
	 * @return array{scan:object|null,rows:object[]}
	 */
	public static function candidates() {
		global $wpdb;
		$t    = Installer::tables();
		$id   = Scans::latest_id();
		$scan = null;
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
			$scan = $wpdb->get_row( $wpdb->prepare( 'SELECT id, target_php, from_php, host_php, finished_at FROM %i WHERE id = %d', $t['scans'], $id ) );
		}
		if ( ! $scan ) {
			return array(
				'scan' => null,
				'rows' => array(),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, slug, name, version, rel_path, verdict, errors, warnings, suppressed FROM %i WHERE scan_id = %d AND status = 'done' AND ( errors > 0 OR warnings > 0 OR suppressed > 0 ) ORDER BY FIELD( verdict, 'blocker', 'unknown', 'suppressed', 'warnings' ), name", $t['components'], $scan->id ) );
		return array(
			'scan' => $scan,
			'rows' => (array) $rows,
		);
	}

	/**
	 * Writes the brief for one plugin or theme of the latest scan.
	 *
	 * @param int $component_id Component row ID.
	 * @return string|null Null when the component isn't in the latest scan.
	 */
	public static function build( $component_id ) {
		global $wpdb;
		$t    = Installer::tables();
		$data = self::candidates();
		$scan = $data['scan'];
		$comp = null;
		foreach ( $data['rows'] as $row ) {
			if ( (int) $row->id === (int) $component_id ) {
				$comp = $row;
			}
		}
		if ( ! $scan || ! $comp ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Airworthy's own tables, read-only.
		$issues = (array) $wpdb->get_results( $wpdb->prepare( "SELECT file, line, severity, context, rule, message FROM %i WHERE component_id = %d AND severity IN ( 'error', 'warning' ) AND context IN ( 'plain', 'suppressed' ) ORDER BY ( severity = 'error' ) DESC, file, line LIMIT %d", $t['issues'], $comp->id, self::MAX_ISSUES + 1 ) );
		$more   = count( $issues ) > self::MAX_ISSUES;
		$issues = array_slice( $issues, 0, self::MAX_ISSUES );
		$root   = Components::root( $comp->type, $comp->rel_path, $comp->slug );
		$target = (string) $scan->target_php;
		$kind   = 'theme' === $comp->type ? __( 'theme', 'airworthy-pro' ) : __( 'plugin', 'airworthy-pro' );

		$out   = array();
		$out[] = sprintf(
			/* translators: 1: plugin or theme name, 2: version, 3: "plugin" or "theme", 4: PHP version. */
			__( 'Task: fix the PHP compatibility problems in the WordPress %3$s "%1$s" %2$s so that it runs on PHP %4$s.', 'airworthy-pro' ),
			$comp->name,
			$comp->version,
			$kind,
			$target
		);
		$out[] = sprintf(
			/* translators: 1: PHP version the site runs now, 2: PHP version to check against, 3: date. */
			__( 'The site runs PHP %1$s now. Airworthy checked the code against PHP %2$s on %3$s by reading it (nothing was run).', 'airworthy-pro' ),
			'' !== (string) $scan->host_php ? $scan->host_php : \Airworthy\Env::php_version(),
			$target,
			$scan->finished_at ? mysql2date( get_option( 'date_format' ), get_date_from_gmt( $scan->finished_at ) ) : wp_date( get_option( 'date_format' ) )
		);
		$out[] = '';
		$out[] = sprintf(
			/* translators: %d: number of problems. */
			_n( '%d problem to fix:', '%d problems to fix:', count( $issues ) + ( $more ? 1 : 0 ), 'airworthy-pro' ),
			count( $issues ) + ( $more ? 1 : 0 )
		);
		foreach ( $issues as $i => $issue ) {
			$out[] = '';
			$out[] = sprintf(
				/* translators: 1: number, 2: file, 3: line, 4: "error" or "warning". */
				__( '%1$d. %2$s, line %3$d (%4$s)', 'airworthy-pro' ),
				$i + 1,
				$issue->file,
				(int) $issue->line,
				'error' === $issue->severity ? __( 'breaks on this PHP version', 'airworthy-pro' ) : __( 'deprecated, still works for now', 'airworthy-pro' )
			);
			$out[] = '   ' . __( 'PHP says:', 'airworthy-pro' ) . ' ' . self::clean( $issue->message, $root );
			$code  = self::source_line( $root, (string) $issue->file, (int) $issue->line );
			if ( '' !== $code ) {
				$out[] = '   ' . __( 'The line:', 'airworthy-pro' ) . ' ' . $code;
			}
			$e = Explain::issue( (string) $issue->rule, (string) $issue->message, (string) $issue->severity, (string) $issue->context, $target );
			if ( $e && '' !== $e['fix'] ) {
				$out[] = '   ' . __( 'How to fix:', 'airworthy-pro' ) . ' ' . $e['fix'];
			}
			if ( $e && '' !== $e['before'] && '' !== $e['after'] ) {
				$out[] = '   ' . __( 'Before:', 'airworthy-pro' ) . ' ' . trim( preg_replace( '/\s+/', ' ', $e['before'] ) );
				$out[] = '   ' . __( 'After:', 'airworthy-pro' ) . ' ' . trim( preg_replace( '/\s+/', ' ', $e['after'] ) );
			}
		}
		if ( $more ) {
			$out[] = '';
			$out[] = __( 'There are more problems than are listed here. Fix these first, then run Airworthy again for the rest.', 'airworthy-pro' );
		}
		$out[] = '';
		$out[] = __( 'Rules:', 'airworthy-pro' );
		$out[] = '- ' . __( 'Change only what is needed to fix the problems above. Do not refactor, rename, reformat or "improve" other code.', 'airworthy-pro' );
		$out[] = '- ' . __( 'Keep the behaviour the same on the PHP version the site runs now, and keep support for any older PHP version the plugin already supports.', 'airworthy-pro' );
		$out[] = '- ' . sprintf(
			/* translators: %s: PHP version. */
			__( 'Where a fix needs a version check, use PHP\'s own functions and constants (no new dependencies), and make sure the code still parses on PHP %s.', 'airworthy-pro' ),
			$target
		);
		$out[] = '- ' . __( 'Show every change as a diff with the file name, so it can be reviewed line by line.', 'airworthy-pro' );
		$out[] = '- ' . __( 'If a problem looks like a false alarm (code that never runs on this PHP version, or is guarded by a version check), say so instead of changing it.', 'airworthy-pro' );
		$out[] = '- ' . __( 'This is third-party code: if the plugin has a newer version that already fixes these, tell me so I can update instead.', 'airworthy-pro' );
		$out[] = '';
		$out[] = __( 'Test the result on a staging copy before it goes on the live site.', 'airworthy-pro' );
		return implode( "\n", $out );
	}

	/**
	 * One line of a plugin's file, trimmed, for the brief.
	 *
	 * @param string $root Component folder (or single file).
	 * @param string $file Path inside the component.
	 * @param int    $line Line number.
	 * @return string Empty when it can't be read.
	 */
	private static function source_line( $root, $file, $line ) {
		$path = is_file( $root ) ? $root : rtrim( $root, '/\\' ) . '/' . ltrim( $file, '/\\' );
		$real = realpath( $path );
		$base = realpath( is_file( $root ) ? dirname( $root ) : $root );
		if ( false === $real || false === $base || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) . '/' ) || filesize( $real ) > 2097152 || $line < 1 ) {
			return '';
		}
		$obj = new \SplFileObject( $real );
		$obj->seek( $line - 1 );
		$text = trim( (string) $obj->current() );
		if ( '' === $text ) {
			return '';
		}
		return mb_strlen( $text ) > 220 ? mb_substr( $text, 0, 217 ) . '...' : $text;
	}

	/**
	 * A message without the server's folder names.
	 *
	 * @param string $message Message.
	 * @param string $root    Component folder.
	 * @return string
	 */
	private static function clean( $message, $root ) {
		$message = str_replace( array( $root, wp_normalize_path( $root ), ABSPATH, wp_normalize_path( ABSPATH ) ), '', (string) $message );
		$message = trim( preg_replace( '/\s+/', ' ', $message ) );
		return trim( (string) preg_replace( '/\s+in$/', '', $message ) ); // A message that ended "in <path>" would end "in".
	}

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$data = self::candidates();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which plugin to write the brief for.
		$pick = isset( $_GET['component'] ) ? absint( wp_unslash( $_GET['component'] ) ) : 0;
		$text = $pick ? self::build( $pick ) : null;
		?>
		<div class="airworthy-card airworthy-pro-brief">
			<h2>📝 <?php esc_html_e( 'Fix brief', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted"><?php esc_html_e( 'Turns a plugin\'s or theme\'s problems into a brief you can paste to a developer or an AI assistant: each problem with its file, line and the line itself, why PHP objects, how to fix it, and rules that stop it changing more than it should. It is built here from your last scan, and nothing is sent anywhere.', 'airworthy-pro' ); ?></p>
			<?php if ( ! $data['scan'] ) : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Run a scan first on the Scan tab. The brief is written from its results.', 'airworthy-pro' ); ?></p>
			<?php elseif ( ! $data['rows'] ) : ?>
				<p class="airworthy-note">✅ <?php esc_html_e( 'Nothing to fix in your last scan: no plugin or theme has problems to write a brief for.', 'airworthy-pro' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' ) ); ?>" class="airworthy-pro-brief-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( \Airworthy\Admin\Page::SLUG ); ?>" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB ); ?>" />
					<label for="airworthy-pro-brief-component" class="screen-reader-text"><?php esc_html_e( 'Plugin or theme', 'airworthy-pro' ); ?></label>
					<select id="airworthy-pro-brief-component" name="component">
						<?php foreach ( $data['rows'] as $row ) : ?>
							<?php $n = (int) $row->errors + (int) $row->warnings + (int) $row->suppressed; ?>
							<option value="<?php echo (int) $row->id; ?>" <?php selected( $pick, (int) $row->id ); ?>>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: name, 2: number of problems, 3: verdict. */
										_n( '%1$s: %2$d problem (%3$s)', '%1$s: %2$d problems (%3$s)', $n, 'airworthy-pro' ),
										$row->name,
										$n,
										Alerts::VERDICTS[ $row->verdict ][1]
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary">📝 <?php esc_html_e( 'Write the brief', 'airworthy-pro' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( null !== $text ) : ?>
			<div class="airworthy-card airworthy-pro-brief-result">
				<h2><?php esc_html_e( 'Your brief', 'airworthy-pro' ); ?></h2>
				<textarea id="airworthy-pro-brief-text" class="large-text code" rows="18" readonly="readonly"><?php echo esc_textarea( $text ); ?></textarea>
				<p class="airworthy-pro-actions">
					<button type="button" class="button button-primary airworthy-pro-copy-brief" data-target="airworthy-pro-brief-text" data-done="<?php esc_attr_e( 'Copied', 'airworthy-pro' ); ?>">📋 <?php esc_html_e( 'Copy the brief', 'airworthy-pro' ); ?></button>
				</p>
				<p class="airworthy-muted"><?php esc_html_e( 'Check what an AI assistant changes before you use it, and try it on a staging copy first. Updating the plugin is usually the better fix when a newer version exists.', 'airworthy-pro' ); ?></p>
			</div>
		<?php elseif ( $pick ) : ?>
			<div class="airworthy-card"><p class="airworthy-note"><?php esc_html_e( 'That plugin or theme is not in your last scan. Pick one from the list.', 'airworthy-pro' ); ?></p></div>
		<?php endif; ?>
		<?php
	}
}
