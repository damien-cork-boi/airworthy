<?php
/**
 * Report branding: the agency's name, logo and colour, on a "Client report" tab.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Client report" tab to the Airworthy screen, where agencies set their branding and
 * open a report for the latest scan.
 */
final class Branding {

	/** Site option holding the branding. */
	const OPTION = 'airworthy_pro_branding';

	/** Action name for admin-post.php (the branding form). */
	const ACTION = 'airworthy_pro_save_branding';

	/** Tab slug on the Airworthy screen. */
	const TAB = 'report';

	/** Default accent colour (Airworthy blue). */
	const DEFAULT_COLOR = '#1f5fd0';

	/**
	 * Hooks the tab, its assets and the form handler.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ) );
		add_filter(
			'airworthy_admin_badge',
			static function () {
				return __( 'Pro', 'airworthy-pro' );
			}
		);
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Client report', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The branding, with defaults.
	 *
	 * @return array{name:string,logo_id:int,color:string}
	 */
	public static function get() {
		$saved = get_site_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Branding from any input, every value checked.
	 *
	 * @param array $input Raw values.
	 * @return array{name:string,logo_id:int,color:string}
	 */
	public static function clean( array $input ) {
		$color = isset( $input['color'] ) ? sanitize_hex_color( (string) $input['color'] ) : '';
		$logo  = isset( $input['logo_id'] ) ? absint( $input['logo_id'] ) : 0;
		return array(
			'name'    => isset( $input['name'] ) ? mb_substr( sanitize_text_field( (string) $input['name'] ), 0, 100 ) : '',
			'logo_id' => $logo && wp_attachment_is_image( $logo ) ? $logo : 0,
			'color'   => $color ? $color : self::DEFAULT_COLOR,
		);
	}

	/**
	 * The logo's URL, or ''.
	 *
	 * @param array $branding get() result.
	 * @return string
	 */
	public static function logo_url( array $branding ) {
		if ( ! $branding['logo_id'] ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $branding['logo_id'], 'medium' );
		return $url ? $url : '';
	}

	/**
	 * Whether one of these tabs is on screen.
	 *
	 * @param string[] $tabs Tab slugs.
	 * @return bool
	 */
	private static function on_tab( array $tabs ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides which assets to load.
		return isset( $_GET['page'], $_GET['tab'] ) && 'airworthy' === sanitize_key( wp_unslash( $_GET['page'] ) ) && in_array( sanitize_key( wp_unslash( $_GET['tab'] ) ), $tabs, true );
	}

	/**
	 * Pro's styles on its tabs; Media Library and colour picker for the branding form.
	 */
	public static function enqueue() {
		if ( self::on_tab( array( self::TAB, Welcome::TAB, Plan::TAB, Versions::TAB ) ) ) {
			wp_enqueue_style( 'airworthy-pro-admin', AIRWORTHY_PRO_URL . 'assets/admin.css', array(), AIRWORTHY_PRO_VERSION );
		}
		if ( ! self::on_tab( array( self::TAB ) ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'airworthy-pro-branding', AIRWORTHY_PRO_URL . 'assets/branding.js', array( 'jquery', 'wp-color-picker' ), AIRWORTHY_PRO_VERSION, true );
		wp_localize_script(
			'airworthy-pro-branding',
			'airworthyProBranding',
			array(
				'choose' => __( 'Choose your logo', 'airworthy-pro' ),
				'use'    => __( 'Use this logo', 'airworthy-pro' ),
			)
		);
	}

	/**
	 * The latest finished scan, for the "open report" button.
	 *
	 * @return object|null id, target_php, finished_at.
	 */
	private static function latest_scan() {
		global $wpdb;
		$t = \Airworthy\Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Airworthy's own table, no user input.
		$scan = $wpdb->get_row( "SELECT id, target_php, finished_at FROM {$t['scans']} WHERE status = 'complete' ORDER BY id DESC LIMIT 1" );
		return $scan ? $scan : null;
	}

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$branding = self::get();
		$logo     = self::logo_url( $branding );
		$scan     = self::latest_scan();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows the saved message.
		$updated = isset( $_GET['updated'] );
		?>
		<div class="airworthy-card airworthy-pro-report">
			<h2><?php esc_html_e( 'Client report', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted"><?php esc_html_e( 'A report of the latest scan for your client, in plain words, with your logo, name and colour. Open it, then save it as a PDF from your browser.', 'airworthy-pro' ); ?></p>
			<?php if ( $scan ) : ?>
				<p>
					<a class="button button-primary button-hero" href="<?php echo esc_url( Report::url( (int) $scan->id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the report', 'airworthy-pro' ); ?></a>
				</p>
				<p class="airworthy-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: PHP version, 2: date. */
							__( 'Uses the latest scan: PHP %1$s, checked %2$s. Run a new scan on the Scan tab to update it.', 'airworthy-pro' ),
							$scan->target_php,
							mysql2date( get_option( 'date_format' ), get_date_from_gmt( $scan->finished_at ) )
						)
					);
					?>
				</p>
			<?php else : ?>
				<p class="airworthy-note"><?php esc_html_e( 'Run a scan first: the report is built from the latest finished scan.', 'airworthy-pro' ); ?></p>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-settings airworthy-pro-branding">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<h2><?php esc_html_e( 'Your branding', 'airworthy-pro' ); ?></h2>
			<?php if ( $updated ) : ?>
				<p class="airworthy-note" role="status"><?php esc_html_e( 'Branding saved.', 'airworthy-pro' ); ?></p>
			<?php endif; ?>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-name"><?php esc_html_e( 'Agency name', 'airworthy-pro' ); ?></label></legend>
				<input type="text" id="airworthy-pro-name" class="regular-text" name="airworthy_pro[name]" maxlength="100" value="<?php echo esc_attr( $branding['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Acme Web Studio', 'airworthy-pro' ); ?>" />
				<p class="description"><?php esc_html_e( 'Shown on the cover as "Prepared by".', 'airworthy-pro' ); ?></p>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><?php esc_html_e( 'Logo', 'airworthy-pro' ); ?></legend>
				<div class="airworthy-pro-logo">
					<img src="<?php echo esc_url( $logo ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?> />
					<input type="hidden" name="airworthy_pro[logo_id]" value="<?php echo esc_attr( (string) $branding['logo_id'] ); ?>" />
					<button type="button" class="button airworthy-pro-logo-choose"><?php esc_html_e( 'Choose logo', 'airworthy-pro' ); ?></button>
					<button type="button" class="button-link airworthy-pro-logo-remove" <?php echo $logo ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'airworthy-pro' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'From your Media Library. A wide logo on a transparent or white background works best.', 'airworthy-pro' ); ?></p>
			</fieldset>

			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-color"><?php esc_html_e( 'Brand colour', 'airworthy-pro' ); ?></label></legend>
				<input type="text" id="airworthy-pro-color" class="airworthy-pro-color" name="airworthy_pro[color]" value="<?php echo esc_attr( $branding['color'] ); ?>" data-default-color="<?php echo esc_attr( self::DEFAULT_COLOR ); ?>" />
				<p class="description"><?php esc_html_e( 'Used for the report\'s headings and cover band.', 'airworthy-pro' ); ?></p>
			</fieldset>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save branding', 'airworthy-pro' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Saves the branding form, then returns to the tab.
	 */
	public static function save() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is checked by clean().
		$input = isset( $_POST['airworthy_pro'] ) ? wp_unslash( (array) $_POST['airworthy_pro'] ) : array();
		update_site_option( self::OPTION, self::clean( $input ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'     => self::TAB,
					'updated' => '1',
				),
				\Airworthy\Admin\Page::url()
			)
		);
		exit;
	}
}
