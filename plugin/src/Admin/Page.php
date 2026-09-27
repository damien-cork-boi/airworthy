<?php
/**
 * The plugin's single admin screen.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Airworthy (single site) or Network Admin > Settings > Airworthy
 * (multisite, where the network admin has no Tools menu).
 */
final class Page {

	const SLUG = 'airworthy';

	/**
	 * Hook suffix returned when the page is registered; used to load assets only here.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers menu and asset hooks.
	 */
	public function register() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the menu item for users with the required capability.
	 */
	public function add_menu() {
		$title = __( 'Airworthy – PHP Compatibility & Upgrade Checker', 'airworthy' );
		$menu  = __( 'Airworthy', 'airworthy' );

		if ( is_multisite() ) {
			$this->hook = (string) add_submenu_page( 'settings.php', $title, $menu, Capabilities::required(), self::SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_management_page( $title, $menu, Capabilities::required(), self::SLUG, array( $this, 'render' ) );
		}
	}

	/**
	 * Loads the screen's assets on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( $hook_suffix ) {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'airworthy-admin', AIRWORTHY_URL . 'assets/admin.css', array(), AIRWORTHY_VERSION );
	}

	/**
	 * Picker label: version, security-support status, and "recommended" for the default.
	 *
	 * @param string $target  Target version.
	 * @param string $default Default target.
	 * @return string
	 */
	private static function target_label( $target, $default ) {
		$until = Targets::security_until( $target );
		$date  = $until ? date_i18n( get_option( 'date_format' ), strtotime( $until . ' 12:00:00 UTC' ) ) : '';
		if ( ! Targets::is_supported( $target ) ) {
			/* translators: 1: PHP version, 2: date security support ended. */
			$label = sprintf( __( 'PHP %1$s (security fixes ended %2$s)', 'airworthy' ), $target, $date );
		} elseif ( $until ) {
			/* translators: 1: PHP version, 2: date security support ends. */
			$label = sprintf( __( 'PHP %1$s (security fixes until %2$s)', 'airworthy' ), $target, $date );
		} else {
			/* translators: %s: PHP version. */
			$label = sprintf( __( 'PHP %s', 'airworthy' ), $target );
		}
		return $target === $default ? $label . ' ' . __( '– recommended', 'airworthy' ) : $label;
	}

	/**
	 * One line explaining why the default target was chosen.
	 *
	 * @param string $current Server PHP version.
	 * @param string $default Default target.
	 * @return string
	 */
	private static function default_explanation( $current, $default ) {
		$current_minor = implode( '.', array_slice( explode( '.', $current ), 0, 2 ) );
		if ( version_compare( $default, $current_minor, '<=' ) ) {
			return __( 'Your server already runs the newest PHP version Airworthy can check.', 'airworthy' );
		}
		$oldest = null;
		foreach ( Targets::ALL as $target ) {
			if ( Targets::is_supported( $target ) ) {
				$oldest = $target;
				break;
			}
		}
		if ( $default === $oldest ) {
			/* translators: %s: PHP version. */
			return sprintf( __( 'PHP %s is recommended: it is the oldest version that still gets security fixes, so it is the smallest upgrade that keeps your site protected. Pick a newer version to plan further ahead.', 'airworthy' ), $default );
		}
		/* translators: %s: PHP version. */
		return sprintf( __( 'PHP %s is recommended: it is the next version up from your server\'s that still gets security fixes. Pick a newer version to plan further ahead.', 'airworthy' ), $default );
	}

	/**
	 * Renders the screen. Step 2 shows the Start view only; progress and results follow later.
	 */
	public function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'airworthy' ), 403 );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_count = count( get_plugins() );
		$theme        = wp_get_theme();
		/**
		 * Filters the server PHP version the screen starts from (for tests and previews).
		 *
		 * @param string $version Default PHP_VERSION.
		 */
		$current      = (string) apply_filters( 'airworthy_server_php_version', PHP_VERSION );
		$default      = Targets::default_for( $current );
		?>
		<div class="wrap airworthy-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="airworthy-lede">
				<?php esc_html_e( 'Check which plugins and themes will break on a newer PHP version, and which look abandoned, before you upgrade. This tool only reads files; it never changes your site or your server.', 'airworthy' ); ?>
			</p>

			<div class="airworthy-card">
				<dl class="airworthy-facts">
					<dt><?php esc_html_e( 'Server PHP version', 'airworthy' ); ?></dt>
					<dd>
						<strong><?php echo esc_html( $current ); ?></strong>
						<?php if ( ! Targets::is_supported( $current ) ) : ?>
							<span class="airworthy-muted"><?php esc_html_e( '(no longer receives security fixes)', 'airworthy' ); ?></span>
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Will be scanned', 'airworthy' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of plugins, 2: theme name. */
								_n( '%1$d plugin (active and inactive) and the active theme, %2$s', '%1$d plugins (active and inactive) and the active theme, %2$s', $plugin_count, 'airworthy' ),
								$plugin_count,
								$theme->get( 'Name' )
							)
						);
						?>
					</dd>
				</dl>

				<form class="airworthy-start" method="post" action="">
					<?php wp_nonce_field( 'airworthy_start_scan', 'airworthy_nonce' ); ?>
					<label for="airworthy-target"><?php esc_html_e( 'Check against PHP', 'airworthy' ); ?></label>
					<select id="airworthy-target" name="target" aria-describedby="airworthy-target-why">
						<?php foreach ( Targets::ALL as $target ) : ?>
							<option value="<?php echo esc_attr( $target ); ?>" <?php selected( $target, $default ); ?>><?php echo esc_html( self::target_label( $target, $default ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary" disabled aria-describedby="airworthy-start-note">
						<?php esc_html_e( 'Start scan', 'airworthy' ); ?>
					</button>
					<p id="airworthy-target-why" class="description"><?php echo esc_html( self::default_explanation( $current, $default ) ); ?></p>
					<p id="airworthy-start-note" class="description"><?php esc_html_e( 'Scanning is not available in this development build yet.', 'airworthy' ); ?></p>
				</form>
			</div>
		</div>
		<?php
	}
}
