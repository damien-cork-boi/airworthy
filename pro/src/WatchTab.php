<?php
/**
 * The "After the switch" tab.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Start a watch, see how it is going, read what it found.
 */
final class WatchTab {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'watch';

	/** Admin-post action names. */
	const START = 'airworthy_pro_watch_start';
	const STOP  = 'airworthy_pro_watch_stop';
	const NOW   = 'airworthy_pro_watch_now';

	/**
	 * Hooks the tab and its form handlers.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 8 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::START, array( __CLASS__, 'start' ) );
		add_action( 'admin_post_' . self::STOP, array( __CLASS__, 'stop' ) );
		add_action( 'admin_post_' . self::NOW, array( __CLASS__, 'check_now' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'After the switch', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-flag';
		return $icons;
	}

	/**
	 * The tab's address, with an optional message flag.
	 *
	 * @param string $flag Query flag for the notice, or ''.
	 * @return string
	 */
	public static function url( $flag = '' ) {
		$url = add_query_arg( 'tab', self::TAB, \Airworthy\Admin\Page::url() );
		return '' === $flag ? $url : add_query_arg( 'notice', $flag, $url );
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

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$state  = Watch::state();
		$report = Watch::report();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a saved message.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$status = $state['status'];
		$names  = array(
			'idle'   => __( 'Not started', 'airworthy-pro' ),
			'before' => __( 'Waiting for the switch', 'airworthy-pro' ),
			'after'  => __( 'Watching', 'airworthy-pro' ),
			'done'   => __( 'Finished', 'airworthy-pro' ),
		);
		?>
		<div class="airworthy-card airworthy-pro-watch">
			<h2>
				<?php esc_html_e( 'After the switch', 'airworthy-pro' ); ?>
				<span class="airworthy-pro-pill <?php echo in_array( $status, array( 'before', 'after' ), true ) ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $names[ $status ] ); ?></span>
			</h2>
			<p class="airworthy-muted"><?php esc_html_e( 'A scan tells you what might break. This tells you what did. Start a watch before your host switches PHP. Airworthy notes how your main pages answer today, notices the switch by itself, then for one to three days records every PHP error and warning your site raises, and names the plugin or theme behind each one.', 'airworthy-pro' ); ?></p>
			<?php self::steps( $status ); ?>
			<?php self::notices( $notice, $state ); ?>
		</div>
		<?php
		if ( 'idle' === $status ) {
			self::start_card();
		} else {
			self::status_card( $state, $report );
			self::pages_card( $report, $state );
			self::errors_card( $report );
		}
	}

	/**
	 * The four steps, with the current one marked.
	 *
	 * @param string $status Watch status.
	 */
	private static function steps( $status ) {
		$at    = array(
			'idle'   => 1,
			'before' => 2,
			'after'  => 3,
			'done'   => 4,
		);
		$now   = $at[ $status ];
		$steps = array(
			array( '🏁', __( 'Start the watch', 'airworthy-pro' ), __( 'Before the switch', 'airworthy-pro' ) ),
			array( '🔀', __( 'Switch PHP', 'airworthy-pro' ), __( 'Your host does this', 'airworthy-pro' ) ),
			array( '👀', __( 'Watch', 'airworthy-pro' ), __( 'Errors and pages', 'airworthy-pro' ) ),
			array( '📋', __( 'Result', 'airworthy-pro' ), __( 'What broke, and why', 'airworthy-pro' ) ),
		);
		echo '<ol class="airworthy-pro-watch-steps">';
		foreach ( $steps as $i => $step ) {
			$n     = $i + 1;
			$class = $n < $now ? 'is-done' : ( $n === $now ? 'is-now' : '' );
			echo '<li class="' . esc_attr( $class ) . '"><span class="airworthy-pro-watch-icon" aria-hidden="true">' . esc_html( $step[0] ) . '</span><strong>' . esc_html( $step[1] ) . '</strong><span class="airworthy-muted">' . esc_html( $step[2] ) . '</span></li>';
		}
		echo '</ol>';
	}

	/**
	 * Saved-message lines.
	 *
	 * @param string $notice Flag from the address.
	 * @param array  $state  Watch state.
	 */
	private static function notices( $notice, array $state ) {
		$messages = array(
			'started' => __( 'The watch has started. Now ask your host to switch PHP. Airworthy will notice.', 'airworthy-pro' ),
			'already' => __( 'The watch has started from now. Everything it records counts as "since the switch".', 'airworthy-pro' ),
			'checked' => __( 'Pages checked.', 'airworthy-pro' ),
			'stopped' => __( 'The watch was stopped and its records removed.', 'airworthy-pro' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<p class="airworthy-note" role="status">' . esc_html( $messages[ $notice ] ) . '</p>';
		}
		if ( '' !== $state['note'] ) {
			echo '<p class="airworthy-note" role="status">' . esc_html( $state['note'] ) . '</p>';
		}
	}

	/**
	 * The form that starts a watch.
	 */
	private static function start_card() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::START ); ?>" />
			<?php wp_nonce_field( self::START ); ?>
			<h2><?php esc_html_e( 'Start a watch', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: PHP version. */
						__( 'Your site runs PHP %s now. Start the watch, then ask your host to move you to the newer version. Airworthy sees the change by itself.', 'airworthy-pro' ),
						\Airworthy\Targets::minor( \Airworthy\Env::php_version() )
					)
				);
				?>
			</p>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-window">⏱️ <?php esc_html_e( 'How long to watch after the switch', 'airworthy-pro' ); ?></label></legend>
				<select id="airworthy-pro-window" name="window">
					<?php foreach ( Watch::WINDOWS as $hours ) : ?>
						<option value="<?php echo (int) $hours; ?>" <?php selected( 72, $hours ); ?>>
							<?php
							/* translators: %d: number of hours. */
							echo esc_html( sprintf( _n( '%d hour', '%d hours', $hours, 'airworthy-pro' ), $hours ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Three days catches the things that only run once a day, like scheduled jobs and backups.', 'airworthy-pro' ); ?></p>
			</fieldset>

			<fieldset class="airworthy-setting">
				<label class="airworthy-choice"><input type="checkbox" name="email" value="1" checked="checked" /> <span><?php esc_html_e( 'Email me if something breaks, and when the watch is over', 'airworthy-pro' ); ?></span></label>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: email addresses. */
							__( 'Goes to: %s. Change who gets the emails on the Monitoring tab.', 'airworthy-pro' ),
							implode( ', ', Alerts::recipients() )
						)
					);
					?>
				</p>
			</fieldset>

			<p class="airworthy-pro-actions">
				<button type="submit" class="button button-primary" name="already" value="0">🏁 <?php esc_html_e( 'Start the watch', 'airworthy-pro' ); ?></button>
				<button type="submit" class="button" name="already" value="1">🔀 <?php esc_html_e( 'I already switched: start now', 'airworthy-pro' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'The second button is for when PHP has already been switched. There is no "before" to compare with, so everything it records counts as new.', 'airworthy-pro' ); ?></p>

			<div class="airworthy-pro-support-privacy">
				<div>
					<h4>👀 <?php esc_html_e( 'What it records', 'airworthy-pro' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'PHP errors, warnings and deprecations, with the file, line and plugin or theme.', 'airworthy-pro' ); ?></li>
						<li><?php esc_html_e( 'How your home page, newest post, login page and REST API answer (and the cart and checkout, with WooCommerce).', 'airworthy-pro' ); ?></li>
					</ul>
				</div>
				<div>
					<h4>🔒 <?php esc_html_e( 'What it does not do', 'airworthy-pro' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Nothing leaves your site. The page checks are your site asking itself.', 'airworthy-pro' ); ?></li>
						<li><?php esc_html_e( 'Visitors see no difference, and no error is shown that was not shown before.', 'airworthy-pro' ); ?></li>
						<li><?php esc_html_e( 'It records only while a watch runs, and removes the server\'s folder names and web-address queries from what it keeps.', 'airworthy-pro' ); ?></li>
					</ul>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * The running or finished watch: where it is and what it says so far.
	 *
	 * @param array $state  Watch state.
	 * @param array $report Watch report.
	 */
	private static function status_card( array $state, array $report ) {
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$status = $state['status'];
		$symbol = array(
			'wait' => '⏳',
			'ok'   => '✅',
			'warn' => '🟡',
			'bad'  => '🔴',
		);
		?>
		<div class="airworthy-card airworthy-pro-watch-status is-<?php echo esc_attr( $report['level'] ); ?>">
			<h2><?php echo esc_html( $symbol[ $report['level'] ] . ' ' . $report['headline'] ); ?></h2>
			<?php if ( 'before' === $status ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: PHP version, 2: date. */
							__( 'Watching on PHP %1$s since %2$s. Now ask your host to switch PHP to the newer version.', 'airworthy-pro' ),
							$state['php_from'],
							wp_date( $fmt, $state['started'] )
						)
					);
					?>
				</p>
				<p class="airworthy-muted"><?php esc_html_e( 'Until then Airworthy records what already goes wrong on the old version, so it can tell old problems from new ones.', 'airworthy-pro' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					if ( '' !== $state['php_from'] ) {
						/* translators: 1: old PHP version, 2: new PHP version, 3: date. */
						$line = sprintf( __( 'PHP changed from %1$s to %2$s on %3$s.', 'airworthy-pro' ), $state['php_from'], $state['php_to'], wp_date( $fmt, $state['switched'] ) );
					} else {
						/* translators: 1: PHP version, 2: date. */
						$line = sprintf( __( 'Watching PHP %1$s since %2$s.', 'airworthy-pro' ), $state['php_to'], wp_date( $fmt, $state['switched'] ) );
					}
					echo esc_html( $line );
					?>
					<?php
					if ( 'after' === $status ) {
						echo esc_html(
							sprintf(
								/* translators: %s: date. */
								__( 'Watching until %s.', 'airworthy-pro' ),
								wp_date( $fmt, $state['ends'] )
							)
						);
					} else {
						echo esc_html(
							sprintf(
								/* translators: %s: date. */
								__( 'The watch ended on %s.', 'airworthy-pro' ),
								wp_date( $fmt, $state['finished'] )
							)
						);
					}
					?>
				</p>
				<?php if ( 'after' === $status && $state['ends'] > $state['switched'] ) : ?>
					<?php $done = max( 0, min( 100, (int) round( ( time() - $state['switched'] ) / ( $state['ends'] - $state['switched'] ) * 100 ) ) ); ?>
					<div class="airworthy-pro-watch-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent. */ __( '%d percent of the watch has passed', 'airworthy-pro' ), $done ) ); ?>"><span style="width:<?php echo (int) $done; ?>%"></span></div>
				<?php endif; ?>
				<ul class="airworthy-pro-facts">
					<li><span aria-hidden="true">🔴</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d new crash', '%d new crashes', $report['fatals'], 'airworthy-pro' ), $report['fatals'] ) ); ?></li>
					<li><span aria-hidden="true">🟡</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d new warning or deprecation', '%d new warnings or deprecations', count( $report['new'] ) - $report['fatals'], 'airworthy-pro' ), count( $report['new'] ) - $report['fatals'] ) ); ?></li>
					<li><span aria-hidden="true">⚪</span> <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d also seen before the switch', '%d also seen before the switch', count( $report['same'] ), 'airworthy-pro' ), count( $report['same'] ) ) ); ?></li>
				</ul>
			<?php endif; ?>

			<div class="airworthy-pro-actions">
				<?php if ( in_array( $status, array( 'before', 'after' ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::NOW ); ?>" />
						<?php wp_nonce_field( self::NOW ); ?>
						<button type="submit" class="button">▶️ <?php esc_html_e( 'Check pages now', 'airworthy-pro' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-inline">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::STOP ); ?>" />
					<?php wp_nonce_field( self::STOP ); ?>
					<button type="submit" class="button"><?php echo 'done' === $status ? esc_html__( 'Start a new watch', 'airworthy-pro' ) : esc_html__( 'Stop and clear', 'airworthy-pro' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The page checks, before and after.
	 *
	 * @param array $report Watch report.
	 * @param array $state  Watch state.
	 */
	private static function pages_card( array $report, array $state ) {
		$rows = $report['pages'];
		if ( ! $rows ) {
			return;
		}
		$answers = array(
			'ok'          => array( '✅', __( 'Answers', 'airworthy-pro' ) ),
			'missing'     => array( '🟡', __( 'Not found', 'airworthy-pro' ) ),
			'shows_error' => array( '🔴', __( 'Shows an error', 'airworthy-pro' ) ),
			'error'       => array( '🔴', __( 'Server error', 'airworthy-pro' ) ),
			'blocked'     => array( '⚪', __( 'Could not check', 'airworthy-pro' ) ),
		);
		$changes = array(
			'same'    => array( '✅', __( 'Same', 'airworthy-pro' ) ),
			'better'  => array( '✅', __( 'Better', 'airworthy-pro' ) ),
			'worse'   => array( '🔴', __( 'Worse', 'airworthy-pro' ) ),
			'slower'  => array( '🟡', __( 'Slower', 'airworthy-pro' ) ),
			'unknown' => array( '⚪', __( 'Not compared', 'airworthy-pro' ) ),
		);
		$cell    = static function ( $a ) use ( $answers ) {
			if ( ! $a ) {
				return '<span class="airworthy-muted">&mdash;</span>';
			}
			$s = $answers[ $a['state'] ];
			return esc_html( $s[0] . ' ' . $s[1] ) . ( $a['status'] ? ' <span class="airworthy-muted">' . esc_html( $a['status'] . ' · ' . $a['ms'] . ' ms' ) . '</span>' : '' );
		};
		$blocked = true;
		foreach ( $rows as $r ) {
			foreach ( array( $r['before'], $r['after'] ) as $a ) {
				if ( $a && 'blocked' !== $a['state'] ) {
					$blocked = false;
				}
			}
		}
		?>
		<div class="airworthy-card airworthy-pro-watch-pages">
			<h2>📄 <?php esc_html_e( 'Your pages', 'airworthy-pro' ); ?></h2>
			<?php if ( $blocked ) : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Your site could not ask itself for its pages. Some hosts block that. The error watch below still works.', 'airworthy-pro' ); ?></p>
			<?php else : ?>
				<p class="airworthy-muted"><?php echo esc_html( 'before' === $state['status'] ? __( 'How each page answers now, on the old PHP. After the switch the same pages are asked again and compared.', 'airworthy-pro' ) : __( 'How each page answered before the switch, and at the latest check.', 'airworthy-pro' ) ); ?></p>
				<div class="airworthy-pro-table-wrap">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Page', 'airworthy-pro' ); ?></th>
								<th><?php esc_html_e( 'Before the switch', 'airworthy-pro' ); ?></th>
								<th><?php esc_html_e( 'Latest check', 'airworthy-pro' ); ?></th>
								<th><?php esc_html_e( 'Change', 'airworthy-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $r['label'] ); ?></strong><br /><code><?php echo esc_html( $r['path'] ); ?></code></td>
									<td><?php echo wp_kses( $cell( $r['before'] ), array( 'span' => array( 'class' => true ) ) ); ?></td>
									<td><?php echo wp_kses( $cell( $r['after'] ), array( 'span' => array( 'class' => true ) ) ); ?></td>
									<td><?php echo esc_html( $changes[ $r['change'] ][0] . ' ' . $changes[ $r['change'] ][1] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The recorded errors.
	 *
	 * @param array $report Watch report.
	 */
	private static function errors_card( array $report ) {
		$status = $report['status'];
		?>
		<div class="airworthy-card airworthy-pro-watch-errors">
			<h2>🚨 <?php echo esc_html( 'before' === $status ? __( 'Errors recorded so far', 'airworthy-pro' ) : __( 'New since the switch', 'airworthy-pro' ) ); ?></h2>
			<?php if ( 'before' === $status ) : ?>
				<p class="airworthy-muted"><?php esc_html_e( 'These happen on the old PHP version already, so they are not caused by the switch. They show up again after the switch, marked as "also seen before".', 'airworthy-pro' ); ?></p>
				<?php
				$table = Watch::table();
				$count = count( $table['rows'] );
				?>
				<p class="airworthy-note"><?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d different error recorded so far.', '%d different errors recorded so far.', $count, 'airworthy-pro' ), $count ) ); ?></p>
			<?php else : ?>
				<?php if ( ! $report['new'] ) : ?>
					<p class="airworthy-note">✅ <?php esc_html_e( 'No new errors or warnings so far. Anything that appears will be listed here under the plugin or theme that raised it.', 'airworthy-pro' ); ?></p>
				<?php else : ?>
					<p class="airworthy-muted"><?php esc_html_e( 'These did not happen on the old PHP version. Each is listed under the plugin or theme whose file raised it. "Via WordPress" means WordPress raised it while running that plugin\'s code.', 'airworthy-pro' ); ?></p>
					<?php self::groups( $report['new'] ); ?>
				<?php endif; ?>
				<?php if ( $report['same'] ) : ?>
					<details class="airworthy-pro-change">
						<summary>⚪ <?php echo esc_html( sprintf( /* translators: %d: number. */ _n( '%d also seen before the switch', '%d also seen before the switch', count( $report['same'] ), 'airworthy-pro' ), count( $report['same'] ) ) ); ?></summary>
						<?php self::groups( $report['same'] ); ?>
					</details>
				<?php endif; ?>
				<?php if ( $report['dropped'] > 0 ) : ?>
					<p class="airworthy-muted"><?php echo esc_html( sprintf( /* translators: %d: number. */ __( 'Airworthy keeps up to 200 different errors. %d more were not kept.', 'airworthy-pro' ), $report['dropped'] ) ); ?></p>
				<?php endif; ?>
				<p class="airworthy-muted"><?php esc_html_e( 'On a busy site the counts are close, not exact.', 'airworthy-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Errors grouped by plugin or theme.
	 *
	 * @param array $rows Rows from Watch::report().
	 */
	private static function groups( array $rows ) {
		$icons  = array(
			'fatal'      => '🔴',
			'error'      => '🔴',
			'warning'    => '🟡',
			'deprecated' => '🟡',
			'notice'     => '⚪',
		);
		$labels = array(
			'fatal'      => __( 'Crash', 'airworthy-pro' ),
			'error'      => __( 'Error', 'airworthy-pro' ),
			'warning'    => __( 'Warning', 'airworthy-pro' ),
			'deprecated' => __( 'Deprecated', 'airworthy-pro' ),
			'notice'     => __( 'Notice', 'airworthy-pro' ),
		);
		$by     = array();
		foreach ( $rows as $r ) {
			$by[ $r['name'] ][] = $r;
		}
		echo '<div class="airworthy-pro-watch-groups">';
		foreach ( array_slice( $by, 0, 12, true ) as $name => $list ) {
			echo '<div class="airworthy-pro-watch-group"><h3>' . esc_html( $name ) . '</h3><ul>';
			foreach ( array_slice( $list, 0, 6 ) as $r ) {
				echo '<li><span aria-hidden="true">' . esc_html( $icons[ $r['level'] ] ) . '</span> <strong>' . esc_html( $labels[ $r['level'] ] ) . '</strong> ' . esc_html( $r['msg'] )
					. '<br /><span class="airworthy-muted"><code>' . esc_html( $r['file'] . ':' . $r['line'] ) . '</code>'
					. ( $r['via'] ? ' · ' . esc_html__( 'via WordPress', 'airworthy-pro' ) : '' )
					. ' · ' . esc_html( sprintf( /* translators: %d: number of times. */ _n( '%d time', '%d times', (int) $r['to'], 'airworthy-pro' ), (int) $r['to'] ) )
					. ' · ' . esc_html( $r['page'] ) . '</span></li>';
			}
			if ( count( $list ) > 6 ) {
				/* translators: %d: number of further items. */
				echo '<li class="airworthy-muted">' . esc_html( sprintf( __( 'and %d more', 'airworthy-pro' ), count( $list ) - 6 ) ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( count( $by ) > 12 ) {
			/* translators: %d: number of further plugins or themes. */
			echo '<p class="airworthy-muted">' . esc_html( sprintf( __( 'and %d more plugins or themes', 'airworthy-pro' ), count( $by ) - 12 ) ) . '</p>';
		}
		echo '</div>';
	}

	// ── Form handlers ──────────────────────────────────────────────────────────────────────────

	/**
	 * Starts a watch.
	 */
	public static function start() {
		self::guard( self::START );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked in guard().
		$window  = isset( $_POST['window'] ) ? absint( wp_unslash( $_POST['window'] ) ) : 72;
		$email   = ! empty( $_POST['email'] );
		$already = isset( $_POST['already'] ) && '1' === $_POST['already'];
		// phpcs:enable
		Watch::start( $window, $email, $already );
		self::back( $already ? 'already' : 'started' );
	}

	/**
	 * Stops and clears the watch.
	 */
	public static function stop() {
		self::guard( self::STOP );
		Watch::stop();
		self::back( 'stopped' );
	}

	/**
	 * Takes a page check now.
	 */
	public static function check_now() {
		self::guard( self::NOW );
		Watch::check_now();
		self::back( 'checked' );
	}

	/**
	 * Checks the permission and the form's nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the tab with a message.
	 *
	 * @param string $flag Notice flag.
	 */
	private static function back( $flag ) {
		wp_safe_redirect( self::url( $flag ) );
		exit;
	}
}
