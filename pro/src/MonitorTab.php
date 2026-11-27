<?php
/**
 * The Monitoring tab: settings, status and history.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * One screen for turning monitoring on, choosing how often and who is told, trying a test email,
 * running a check now, and reading the history of what changed.
 */
final class MonitorTab {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'monitor';

	/** Admin-post action name: save the settings. */
	const SAVE = 'airworthy_pro_save_monitor';
	const NOW  = 'airworthy_pro_monitor_now';
	const TEST = 'airworthy_pro_monitor_test';

	/**
	 * Hooks the tab and its form handlers.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 7 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::NOW, array( __CLASS__, 'check_now' ) );
		add_action( 'admin_post_' . self::TEST, array( __CLASS__, 'test_email' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Monitoring', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-visibility';
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
		// Pick up scans finished by hand since the last visit, so the history is complete.
		$scan_id = Scans::latest_id();
		if ( $scan_id ) {
			History::record( $scan_id, 'manual' );
		}
		$s     = Monitor::get();
		$state = Monitor::state();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a saved message.
		$notice  = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$on      = ! empty( $s['enabled'] );
		$next    = Monitor::next_run();
		$running = $state['pending'] && (int) \Airworthy\Scan\Queue::active_scan_id() === (int) $state['pending']['scan_id'];
		?>
		<div class="airworthy-card airworthy-pro-monitor">
			<h2>
				<?php esc_html_e( 'Monitoring', 'airworthy-pro' ); ?>
				<span class="airworthy-pro-pill <?php echo $on ? 'is-on' : 'is-off'; ?>"><?php echo $on ? esc_html__( 'On', 'airworthy-pro' ) : esc_html__( 'Off', 'airworthy-pro' ); ?></span>
			</h2>
			<p class="airworthy-muted"><?php esc_html_e( 'Airworthy checks your plugins and themes again on a schedule, and after any plugin or theme update, and emails you when something new would break. It only emails when something got worse.', 'airworthy-pro' ); ?></p>

			<?php self::notices( $notice, $state ); ?>

			<ul class="airworthy-pro-facts">
				<li><span aria-hidden="true">🎯</span> <?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'Checks against PHP %s', 'airworthy-pro' ), Monitor::target() ) ); ?></li>
				<?php if ( $on ) : ?>
					<li><span aria-hidden="true">🗓️</span>
						<?php
						echo esc_html(
							$running ?
								__( 'A check is running now.', 'airworthy-pro' ) :
								sprintf(
									/* translators: %s: date. */
									__( 'Next check: %s', 'airworthy-pro' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), max( $next, time() ) )
								)
						);
						?>
					</li>
				<?php endif; ?>
				<?php if ( $state['last_run'] ) : ?>
					<li><span aria-hidden="true">⏱️</span> <?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Last check started: %s', 'airworthy-pro' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['last_run'] ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $on && defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
					<li class="is-warn"><span aria-hidden="true">⚠️</span> <?php esc_html_e( 'WP-Cron is switched off on this site (DISABLE_WP_CRON). Checks only run if your host runs wp-cron.php on a schedule.', 'airworthy-pro' ); ?></li>
				<?php endif; ?>
			</ul>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-settings airworthy-pro-monitor-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>" />
			<?php wp_nonce_field( self::SAVE ); ?>
			<h2><?php esc_html_e( 'Settings', 'airworthy-pro' ); ?></h2>

			<fieldset class="airworthy-setting">
				<label class="airworthy-choice"><input type="checkbox" name="airworthy_pro_monitor[enabled]" value="1" <?php checked( $on ); ?> /> <span><strong><?php esc_html_e( 'Check this site automatically', 'airworthy-pro' ); ?></strong></span></label>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-frequency">🔁 <?php esc_html_e( 'How often', 'airworthy-pro' ); ?></label></legend>
				<select id="airworthy-pro-frequency" name="airworthy_pro_monitor[frequency]">
					<option value="daily" <?php selected( $s['frequency'], 'daily' ); ?>><?php esc_html_e( 'Every day', 'airworthy-pro' ); ?></option>
					<option value="weekly" <?php selected( $s['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Every week', 'airworthy-pro' ); ?></option>
					<option value="monthly" <?php selected( $s['frequency'], 'monthly' ); ?>><?php esc_html_e( 'Every month', 'airworthy-pro' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Airworthy also checks right after a plugin or theme is updated, whatever you pick here.', 'airworthy-pro' ); ?></p>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-target">🎯 <?php esc_html_e( 'Check against', 'airworthy-pro' ); ?></label></legend>
				<select id="airworthy-pro-target" name="airworthy_pro_monitor[target]">
					<option value="auto" <?php selected( $s['target'], 'auto' ); ?>>
						<?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'The suggested version (PHP %s)', 'airworthy-pro' ), Targets::default_for( \Airworthy\Env::php_version() ) ) ); ?>
					</option>
					<?php foreach ( Targets::ALL as $version ) : ?>
						<option value="<?php echo esc_attr( $version ); ?>" <?php selected( $s['target'], $version ); ?>><?php echo esc_html( 'PHP ' . $version ); ?></option>
					<?php endforeach; ?>
				</select>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-recipients">✉️ <?php esc_html_e( 'Who gets the emails', 'airworthy-pro' ); ?></label></legend>
				<input type="text" id="airworthy-pro-recipients" class="regular-text" name="airworthy_pro_monitor[recipients]" value="<?php echo esc_attr( $s['recipients'] ); ?>" placeholder="<?php echo esc_attr( (string) get_site_option( 'admin_email' ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Up to five addresses, separated by commas. Leave it empty to use the site\'s admin email (shown here).', 'airworthy-pro' ); ?></p>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend>🔔 <?php esc_html_e( 'Email me when', 'airworthy-pro' ); ?></legend>
				<label class="airworthy-choice"><input type="checkbox" name="airworthy_pro_monitor[alert_blockers]" value="1" <?php checked( $s['alert_blockers'] ); ?> /> <span><?php esc_html_e( 'a plugin or theme newly becomes a Blocker', 'airworthy-pro' ); ?></span></label>
				<label class="airworthy-choice"><input type="checkbox" name="airworthy_pro_monitor[alert_warnings]" value="1" <?php checked( $s['alert_warnings'] ); ?> /> <span><?php esc_html_e( 'something gets new Warnings (off by default: warnings are rarely urgent)', 'airworthy-pro' ); ?></span></label>
				<label class="airworthy-choice"><input type="checkbox" name="airworthy_pro_monitor[alert_support]" value="1" <?php checked( $s['alert_support'] ); ?> /> <span><?php esc_html_e( 'my PHP version is close to the end of security fixes, or is missing the latest security update', 'airworthy-pro' ); ?></span></label>
				<label class="airworthy-choice"><input type="checkbox" name="airworthy_pro_monitor[alert_failed]" value="1" <?php checked( $s['alert_failed'] ); ?> /> <span><?php esc_html_e( 'a scheduled check could not finish', 'airworthy-pro' ); ?></span></label>
			</fieldset>

			<p class="airworthy-pro-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'airworthy-pro' ); ?></button></p>
		</form>

		<div class="airworthy-card airworthy-pro-monitor-try">
			<h2><?php esc_html_e( 'Try it', 'airworthy-pro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST ); ?>" />
				<?php wp_nonce_field( self::TEST ); ?>
				<button type="submit" class="button">✉️ <?php esc_html_e( 'Send a test email', 'airworthy-pro' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::NOW ); ?>" />
				<?php wp_nonce_field( self::NOW ); ?>
				<button type="submit" class="button">▶️ <?php esc_html_e( 'Check now', 'airworthy-pro' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'A check you start here is saved in the history but does not send an email.', 'airworthy-pro' ); ?></p>
		</div>

		<?php self::history(); ?>
		<?php
	}

	/**
	 * The history list.
	 */
	private static function history() {
		$all = array_reverse( History::all() );
		?>
		<div class="airworthy-card airworthy-pro-history">
			<h2><?php esc_html_e( 'History', 'airworthy-pro' ); ?></h2>
			<?php if ( ! $all ) : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Nothing here yet. Run a scan on the Scan tab, or press Check now, and the first snapshot appears. A new line is added each time the results change.', 'airworthy-pro' ); ?></p>
				<?php return; ?>
			<?php endif; ?>
			<p class="airworthy-muted"><?php echo esc_html( sprintf( /* translators: %d: number of snapshots kept. */ __( 'The last %d times the results changed, newest first.', 'airworthy-pro' ), History::MAX ) ); ?></p>
			<ol class="airworthy-pro-history-list">
				<?php foreach ( $all as $i => $snap ) : ?>
					<?php
					$c       = $snap['counts'];
					$changes = isset( $snap['changes'] ) ? $snap['changes'] : array();
					$first   = count( $all ) - 1 === $i;
					?>
					<li>
						<p class="airworthy-pro-history-head">
							<strong><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $snap['at'] ) ) ); ?></strong>
							<span class="airworthy-muted"> · <?php echo esc_html( self::trigger_label( isset( $snap['trigger'] ) ? $snap['trigger'] : 'manual' ) ); ?> · <?php echo esc_html( 'PHP ' . $snap['target'] ); ?></span>
							<?php echo 0 === $i ? '<span class="airworthy-pro-pill is-on">' . esc_html__( 'Latest', 'airworthy-pro' ) . '</span>' : ''; ?>
						</p>
						<p class="airworthy-pro-history-counts">
							<span title="<?php esc_attr_e( 'Blockers', 'airworthy-pro' ); ?>">🔴 <?php echo (int) $c['blocker']; ?></span>
							<span title="<?php esc_attr_e( 'Warnings', 'airworthy-pro' ); ?>">🟡 <?php echo (int) $c['warnings']; ?></span>
							<span title="<?php esc_attr_e( 'Unknown', 'airworthy-pro' ); ?>">⚪ <?php echo (int) $c['unknown']; ?></span>
							<span title="<?php esc_attr_e( 'Ready', 'airworthy-pro' ); ?>">✅ <?php echo (int) $c['ready']; ?></span>
						</p>
						<?php self::changes( $changes, $first ); ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * What changed in one snapshot.
	 *
	 * @param array $changes The snapshot's saved changes.
	 * @param bool  $first   Whether this is the oldest snapshot kept.
	 */
	private static function changes( array $changes, $first ) {
		if ( $first ) {
			echo '<p class="airworthy-muted">' . esc_html__( 'The first snapshot: nothing to compare it with.', 'airworthy-pro' ) . '</p>';
			return;
		}
		if ( ! empty( $changes['target_changed'] ) ) {
			echo '<p class="airworthy-muted">' . esc_html__( 'Checked against a different PHP version than the one before, so the two are not compared.', 'airworthy-pro' ) . '</p>';
			return;
		}
		$groups = array(
			'blockers' => array( '🔴', __( 'New Blockers', 'airworthy-pro' ) ),
			'worse'    => array( '🟡', __( 'Got worse', 'airworthy-pro' ) ),
			'fixed'    => array( '✅', __( 'Fixed', 'airworthy-pro' ) ),
			'better'   => array( '✅', __( 'Improved', 'airworthy-pro' ) ),
			'added'    => array( '➕', __( 'Added', 'airworthy-pro' ) ),
			'removed'  => array( '➖', __( 'Removed', 'airworthy-pro' ) ),
		);
		$any    = false;
		foreach ( $groups as $key => $g ) {
			if ( empty( $changes[ $key ] ) ) {
				continue;
			}
			$any = true;
			echo '<details class="airworthy-pro-change"><summary>' . esc_html( $g[0] . ' ' . $g[1] . ' (' . count( $changes[ $key ] ) . ')' ) . '</summary><ul>';
			foreach ( $changes[ $key ] as $row ) {
				echo '<li>' . esc_html( Alerts::row_text( $row ) ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( ! $any ) {
			echo '<p class="airworthy-muted">' . esc_html__( 'Only version numbers changed.', 'airworthy-pro' ) . '</p>';
		}
	}

	/**
	 * What started a snapshot, in words.
	 *
	 * @param string $trigger scheduled | update | manual.
	 * @return string
	 */
	private static function trigger_label( $trigger ) {
		switch ( $trigger ) {
			case 'scheduled':
				return __( 'Scheduled check', 'airworthy-pro' );
			case 'update':
				return __( 'After an update', 'airworthy-pro' );
			default:
				return __( 'Run by hand', 'airworthy-pro' );
		}
	}

	/**
	 * The saved-message line.
	 *
	 * @param string $notice Flag from the address.
	 * @param array  $state  Monitoring state.
	 */
	private static function notices( $notice, array $state ) {
		$messages = array(
			'saved'       => array( 'ok', __( 'Settings saved.', 'airworthy-pro' ) ),
			'test_sent'   => array( 'ok', __( 'Test email sent. Check the inbox (and spam) of the address above.', 'airworthy-pro' ) ),
			'test_fail'   => array( 'warn', __( 'WordPress could not send the test email. Check that your site can send email (a plugin such as an SMTP mailer helps on some hosts).', 'airworthy-pro' ) ),
			'started'     => array( 'ok', __( 'A check has started. It runs in the background; the result appears in the history below.', 'airworthy-pro' ) ),
			'not_started' => array( 'warn', __( 'A check could not be started. A scan may already be running.', 'airworthy-pro' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<p class="airworthy-note" role="status">' . esc_html( $messages[ $notice ][1] ) . '</p>';
		}
		if ( ! empty( $state['last_error'] ) ) {
			echo '<p class="airworthy-note" role="status">' . esc_html( sprintf( /* translators: %s: reason. */ __( 'The last scheduled check could not start: %s', 'airworthy-pro' ), $state['last_error'] ) ) . '</p>';
		}
	}

	/**
	 * Saves the settings form.
	 */
	public static function save() {
		self::guard( self::SAVE );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in guard(); every value is checked by Monitor::clean().
		$input = isset( $_POST['airworthy_pro_monitor'] ) ? wp_unslash( (array) $_POST['airworthy_pro_monitor'] ) : array();
		Monitor::save( Monitor::clean( $input ) );
		self::back( 'saved' );
	}

	/**
	 * Starts a check by hand.
	 */
	public static function check_now() {
		self::guard( self::NOW );
		$result = Monitor::run( 'manual' );
		self::back( is_wp_error( $result ) ? 'not_started' : 'started' );
	}

	/**
	 * Sends the test email.
	 */
	public static function test_email() {
		self::guard( self::TEST );
		self::back( Alerts::send_test() ? 'test_sent' : 'test_fail' );
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
