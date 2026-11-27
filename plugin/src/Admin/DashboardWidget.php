<?php
/**
 * A small "PHP upgrade readiness" widget on the WordPress dashboard.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Env;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the same plain-English status as the Site Health check (no scan yet, or the latest
 * result and its date) with a link to the Airworthy screen. It reads the latest finished scan;
 * it never starts one. Only for users who can scan, in the normal column, and anyone can hide it
 * in Screen Options. When the latest scan found Blockers it adds one line about Airworthy Pro,
 * which the user can dismiss for good (the same choice as "Don't show again" on the results card).
 *
 * Sites and hosts can remove the widget with the airworthy_dashboard_widget filter.
 */
final class DashboardWidget {

	/** Widget ID (the key Screen Options and the dashboard layout use). */
	const ID = 'airworthy_dashboard';

	/** Where the Pro line goes. */
	const PRO_URL = 'https://airworthywp.com/pro/?utm_source=plugin&utm_medium=dashboard&utm_campaign=pro-widget';

	/**
	 * Hooks the widget (the network dashboard on a network, where Airworthy lives).
	 */
	public static function register() {
		add_action( is_multisite() ? 'wp_network_dashboard_setup' : 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the widget is on for the current user.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return false;
		}
		/**
		 * Filters whether Airworthy adds its widget to the dashboard.
		 *
		 * @param bool $enabled True by default; return false to remove the widget.
		 */
		return (bool) apply_filters( 'airworthy_dashboard_widget', true );
	}

	/**
	 * Adds the widget.
	 */
	public static function add_widget() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Airworthy: PHP upgrade readiness', 'airworthy' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Whether the Airworthy Pro line shows: the latest scan (still current for this server's PHP
	 * version) found Blockers, and the user hasn't said Don't show again.
	 *
	 * @return bool
	 */
	public static function pro_line_visible() {
		if ( ! ProCard::menu_visible() ) {
			return false;
		}
		$scan = SiteHealth::latest_scan();
		return $scan && (int) $scan->count_blocker > 0 && version_compare( $scan->target_php, Targets::minor( Env::php_version() ), '>' );
	}

	/**
	 * Loads the small script that handles Don't show again, on the dashboard only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'index.php', 'dashboard.php' ), true ) || ! self::enabled() || ! self::pro_line_visible() ) {
			return;
		}
		wp_enqueue_script( 'airworthy-dashboard', AIRWORTHY_URL . 'assets/dashboard.js', array( 'wp-api-fetch', 'wp-a11y', 'wp-i18n' ), Page::asset_version( 'assets/dashboard.js' ), true );
		wp_set_script_translations( 'airworthy-dashboard', 'airworthy' );
		wp_add_inline_style( 'dashboard', '#' . self::ID . ' .airworthy-dot{display:inline-block;width:10px;height:10px;margin-right:8px;border-radius:50%;background:#2f74e8}#' . self::ID . ' .is-recommended .airworthy-dot{background:#dba617}#' . self::ID . ' .is-good .airworthy-dot{background:#00a32a}' );
	}

	/**
	 * Draws the widget.
	 */
	public static function render() {
		$result = SiteHealth::run();
		$status = isset( $result['status'] ) ? $result['status'] : 'good';
		?>
		<div class="airworthy-widget is-<?php echo esc_attr( $status ); ?>">
			<p><span class="airworthy-dot" aria-hidden="true"></span><strong><?php echo esc_html( $result['label'] ); ?></strong></p>
			<?php echo wp_kses_post( $result['description'] ); ?>
			<?php echo wp_kses_post( $result['actions'] ); ?>
			<?php if ( self::pro_line_visible() ) : ?>
				<p class="airworthy-widget-pro">
					<?php esc_html_e( 'Airworthy Pro turns blockers into an ordered upgrade plan and branded client reports.', 'airworthy' ); ?>
					<a href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See what it adds', 'airworthy' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'airworthy' ); ?></span></a>
					&middot;
					<button type="button" class="button-link airworthy-widget-hide"><?php esc_html_e( 'Don\'t show again', 'airworthy' ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
