<?php
/**
 * Pro's home: the "Pro" tab (what Pro includes, version, support) and the welcome view
 * shown once, right after Pro is activated.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shows people what they have: every Pro feature with its status, and where to get help.
 */
final class Welcome {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'pro';

	/** Site option set on activation: show the welcome view on the next admin screen. */
	const FLAG = 'airworthy_pro_welcome';

	/** Where Pro customers get help. */
	const SUPPORT_EMAIL = 'hello@airworthywp.com';

	/**
	 * Hooks the tab and the activation redirect.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 5 ); // Before Client report.
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * Activation hook: ask for the welcome view.
	 */
	public static function activate() {
		update_site_option( self::FLAG, 1 );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Pro', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The Pro tab's URL.
	 *
	 * @param bool $welcome Show the welcome view.
	 * @return string
	 */
	public static function url( $welcome = false ) {
		$args = array( 'tab' => self::TAB );
		if ( $welcome ) {
			$args['welcome'] = '1';
		}
		return add_query_arg( $args, \Airworthy\Admin\Page::url() );
	}

	/**
	 * After activation, go to the welcome view once (not when several plugins were activated
	 * together, and not for requests that can't redirect).
	 */
	public static function maybe_redirect() {
		if ( ! get_site_option( self::FLAG ) ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! Capabilities::current_user_can_scan() ) {
			return;
		}
		delete_site_option( self::FLAG );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads WordPress's own bulk-activation marker.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}
		wp_safe_redirect( self::url( true ) );
		exit;
	}

	/**
	 * The latest finished scan's ID, or 0.
	 *
	 * @return int
	 */
	private static function latest_scan_id() {
		global $wpdb;
		$t = \Airworthy\Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Airworthy's own table, no user input.
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t['scans']} WHERE status = 'complete'" );
	}

	/**
	 * Every Pro feature, with its status and a link where it's ready to use.
	 *
	 * @param int $scan_id Latest finished scan, or 0.
	 * @return array[] title, text, status (included | next | soon), link, link_text.
	 */
	private static function features( $scan_id ) {
		$report_tab = add_query_arg( 'tab', Branding::TAB, \Airworthy\Admin\Page::url() );
		return array(
			array(
				'title'     => __( 'Client reports', 'airworthy-pro' ),
				'text'      => __( 'A plain-words report of any scan with your logo, name and colour, ready to save as a PDF and send to your client.', 'airworthy-pro' ),
				'status'    => 'included',
				'link'      => $scan_id ? Report::url( $scan_id ) : $report_tab,
				'link_text' => $scan_id ? __( 'Open a report', 'airworthy-pro' ) : __( 'Set up your branding', 'airworthy-pro' ),
				'new_tab'   => (bool) $scan_id,
			),
			array(
				'title'  => __( 'Upgrade plan', 'airworthy-pro' ),
				'text'   => __( 'A step-by-step checklist built from your results: update these, replace those, back up, switch. It ticks itself off as you go.', 'airworthy-pro' ),
				'status' => 'next',
			),
			array(
				'title'  => __( 'Every PHP version at once', 'airworthy-pro' ),
				'text'   => __( 'One scan, PHP 8.0 to 8.5 side by side, and the highest version your site is ready for today.', 'airworthy-pro' ),
				'status' => 'next',
			),
			array(
				'title'  => __( 'Monitoring and email alerts', 'airworthy-pro' ),
				'text'   => __( 'Checks again after every update and every week, and emails you when something changes or your PHP version nears its end.', 'airworthy-pro' ),
				'status' => 'soon',
			),
			array(
				'title'  => __( 'Report history', 'airworthy-pro' ),
				'text'   => __( 'Every client report kept with its date, ready to download again.', 'airworthy-pro' ),
				'status' => 'soon',
			),
		);
	}

	/**
	 * Draws the tab (with the welcome view on top right after activation).
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the welcome view.
		$welcome  = isset( $_GET['welcome'] );
		$scan_id  = self::latest_scan_id();
		$branding = Branding::get();
		$statuses = array(
			'included' => __( 'Included', 'airworthy-pro' ),
			'next'     => __( 'Coming next', 'airworthy-pro' ),
			'soon'     => __( 'Coming soon', 'airworthy-pro' ),
		);
		$support  = 'mailto:' . self::SUPPORT_EMAIL . '?subject=' . rawurlencode( __( 'Airworthy Pro support', 'airworthy-pro' ) );
		?>
		<?php if ( $welcome ) : ?>
			<div class="airworthy-card airworthy-pro-welcome">
				<p class="airworthy-pro-eyebrow"><?php esc_html_e( 'Welcome to Airworthy Pro', 'airworthy-pro' ); ?></p>
				<h2><?php esc_html_e( 'You\'re all set. Here\'s how to get the most out of it.', 'airworthy-pro' ); ?></h2>
				<ol class="airworthy-pro-steps">
					<li class="<?php echo '' !== $branding['name'] || $branding['logo_id'] ? 'done' : ''; ?>">
						<strong><?php esc_html_e( 'Add your branding', 'airworthy-pro' ); ?></strong>
						<span><?php esc_html_e( 'Your logo, name and colour go on every client report.', 'airworthy-pro' ); ?></span>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'tab', Branding::TAB, \Airworthy\Admin\Page::url() ) ); ?>"><?php esc_html_e( 'Add branding', 'airworthy-pro' ); ?></a>
					</li>
					<li class="<?php echo $scan_id ? 'done' : ''; ?>">
						<strong><?php esc_html_e( 'Run a scan', 'airworthy-pro' ); ?></strong>
						<span><?php esc_html_e( 'Check the site\'s plugins and theme against the PHP version you plan to move to.', 'airworthy-pro' ); ?></span>
						<a class="button" href="<?php echo esc_url( \Airworthy\Admin\Page::url() ); ?>"><?php echo $scan_id ? esc_html__( 'See the results', 'airworthy-pro' ) : esc_html__( 'Start a scan', 'airworthy-pro' ); ?></a>
					</li>
					<li>
						<strong><?php esc_html_e( 'Send a client report', 'airworthy-pro' ); ?></strong>
						<span><?php esc_html_e( 'Open the report, save it as a PDF, and send it on.', 'airworthy-pro' ); ?></span>
						<?php if ( $scan_id ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( Report::url( $scan_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the report', 'airworthy-pro' ); ?></a>
						<?php else : ?>
							<span class="airworthy-muted"><?php esc_html_e( 'Available after your first scan.', 'airworthy-pro' ); ?></span>
						<?php endif; ?>
					</li>
				</ol>
			</div>
		<?php endif; ?>

		<div class="airworthy-card airworthy-pro-home">
			<div class="airworthy-pro-home-head">
				<div>
					<h2><?php esc_html_e( 'Airworthy Pro', 'airworthy-pro' ); ?> <span class="airworthy-edition"><?php esc_html_e( 'Pro', 'airworthy-pro' ); ?></span></h2>
					<p class="airworthy-muted"><?php esc_html_e( 'The free plugin tells you what will break. Pro helps you plan the upgrade, show your clients, and keep watch afterwards.', 'airworthy-pro' ); ?></p>
				</div>
				<dl class="airworthy-pro-facts">
					<dt><?php esc_html_e( 'Version', 'airworthy-pro' ); ?></dt>
					<dd><?php echo esc_html( AIRWORTHY_PRO_VERSION ); ?></dd>
					<dt><?php esc_html_e( 'Licence', 'airworthy-pro' ); ?></dt>
					<dd><?php esc_html_e( 'Early access', 'airworthy-pro' ); ?></dd>
				</dl>
			</div>

			<ul class="airworthy-pro-features">
				<?php foreach ( self::features( $scan_id ) as $f ) : ?>
					<li class="airworthy-pro-feature airworthy-pro-<?php echo esc_attr( $f['status'] ); ?>">
						<span class="airworthy-pro-status"><?php echo esc_html( $statuses[ $f['status'] ] ); ?></span>
						<h3><?php echo esc_html( $f['title'] ); ?></h3>
						<p><?php echo esc_html( $f['text'] ); ?></p>
						<?php if ( ! empty( $f['link'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $f['link'] ); ?>"<?php echo ! empty( $f['new_tab'] ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $f['link_text'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="airworthy-card airworthy-pro-support">
			<h2><?php esc_html_e( 'Priority support', 'airworthy-pro' ); ?></h2>
			<p><?php esc_html_e( 'Pro customers get help straight from the people who make Airworthy. Tell us your site\'s PHP version and what you\'re seeing; a CSV export of the results helps.', 'airworthy-pro' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $support ); ?>"><?php esc_html_e( 'Email support', 'airworthy-pro' ); ?></a> <span class="airworthy-muted"><?php echo esc_html( self::SUPPORT_EMAIL ); ?></span></p>
		</div>
		<?php
	}
}
