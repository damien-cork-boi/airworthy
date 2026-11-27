<?php
/**
 * The "Check a zip" tab: scan a plugin or theme before installing it.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Env;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Upload a plugin or theme zip, pick the PHP version, and Airworthy unpacks it in the server's temp
 * folder, runs the same checks as a site scan, and says whether it would work, set against the
 * version you have installed. Nothing is installed or run, the files are deleted afterwards, and
 * nothing leaves the site.
 */
final class ZipTab {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'zipcheck';

	/** Admin-post action name. */
	const UPLOAD = 'airworthy_pro_zip_upload';

	/**
	 * Hooks the tab and its form handler.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 9 );
		add_filter( 'airworthy_admin_tab_icons', array( __CLASS__, 'tab_icon' ) );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::UPLOAD, array( __CLASS__, 'upload' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Check a zip', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's icon.
	 *
	 * @param array $icons slug => Dashicons class.
	 * @return array
	 */
	public static function tab_icon( $icons ) {
		$icons[ self::TAB ] = 'dashicons-upload';
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
	 * Pro's styles on this tab, and the reload loop while a check runs.
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which assets to load.
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
		?>
		<div class="airworthy-card airworthy-pro-zip">
			<h2>📦 <?php esc_html_e( 'Check a zip before you install it', 'airworthy-pro' ); ?></h2>
			<p class="airworthy-muted"><?php esc_html_e( 'Got a plugin or theme zip from a developer, a marketplace or a client? Upload it here first. Airworthy reads it and tells you whether it would work on the PHP version you are heading for, and whether it is better or worse than the version you have installed. Nothing is installed or run.', 'airworthy-pro' ); ?></p>
		</div>
		<?php
		if ( ! class_exists( '\\Airworthy\\Scan\\UpdateCheck' ) ) {
			echo '<div class="airworthy-card"><p class="airworthy-note">' . esc_html__( 'Update the free Airworthy plugin to use this: it does the checking.', 'airworthy-pro' ) . '</p></div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads a job ID and a message flag.
		$job = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		self::notice( $notice );
		if ( '' !== $job ) {
			self::result( $job );
		}
		self::form();
	}

	/**
	 * A message from the last upload.
	 *
	 * @param string $notice Flag.
	 */
	private static function notice( $notice ) {
		$messages = array(
			'nofile'  => __( 'Choose a .zip file first.', 'airworthy-pro' ),
			'toobig'  => __( 'That file is too large to check (the limit is 60 MB).', 'airworthy-pro' ),
			'notzip'  => __( 'That does not look like a zip file.', 'airworthy-pro' ),
			'nofirst' => __( 'Run a scan on the Scan tab first, so Airworthy knows which PHP version to compare with.', 'airworthy-pro' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<p class="airworthy-note" role="status">' . esc_html( $messages[ $notice ] ) . '</p>';
		}
	}

	/**
	 * The running or finished check.
	 *
	 * @param string $job Job ID.
	 */
	private static function result( $job ) {
		$r = \Airworthy\Scan\UpdateCheck::advance( $job ); // Takes the next step here, in this request.
		?>
		<div class="airworthy-card airworthy-pro-zip-result">
			<?php if ( 'running' === $r['status'] ) : ?>
				<h2>⏳ <?php esc_html_e( 'Checking…', 'airworthy-pro' ); ?></h2>
				<div class="airworthy-pro-watch-bar" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percent. */ __( '%d percent done', 'airworthy-pro' ), (int) $r['percent'] ) ); ?>"><span style="width:<?php echo (int) $r['percent']; ?>%"></span></div>
				<p class="airworthy-muted"><?php esc_html_e( 'This page reloads by itself until the check is done. Big plugins take a minute or two.', 'airworthy-pro' ); ?></p>
				<?php wp_print_inline_script_tag( 'setTimeout( function () { window.location.reload(); }, 1500 );' ); ?>
			<?php elseif ( 'problem' === $r['status'] ) : ?>
				<h2>⚠️ <?php esc_html_e( 'The check could not finish', 'airworthy-pro' ); ?></h2>
				<p><?php echo esc_html( $r['message'] ); ?></p>
			<?php else : ?>
				<?php
				$icons = array(
					'fixes'   => '✅',
					'ok'      => '✅',
					'same'    => '🟡',
					'better'  => '🟡',
					'unknown' => '⚪',
					'blocker' => '🔴',
					'worse'   => '🔴',
				);
				?>
				<h2><?php echo esc_html( $icons[ $r['outcome'] ] . ' ' . $r['message'] ); ?></h2>
				<ul class="airworthy-pro-facts">
					<?php if ( $r['before'] ) : ?>
						<li><span aria-hidden="true">📌</span> <?php echo esc_html( sprintf( /* translators: 1: verdict, 2: number of problems. */ __( 'Installed now: %1$s (%2$d problems)', 'airworthy-pro' ), Alerts::VERDICTS[ $r['before']['verdict'] ][1], (int) $r['before']['errors'] ) ); ?></li>
					<?php endif; ?>
					<li><span aria-hidden="true">📦</span> <?php echo esc_html( sprintf( /* translators: 1: verdict, 2: number of problems. */ __( 'This zip: %1$s (%2$d problems)', 'airworthy-pro' ), Alerts::VERDICTS[ $r['after']['verdict'] ][1], (int) $r['after']['errors'] ) ); ?></li>
					<li><span aria-hidden="true">🎯</span> <?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'Checked against PHP %s', 'airworthy-pro' ), $r['target'] ) ); ?></li>
				</ul>
				<?php if ( $r['samples'] && ! in_array( $r['outcome'], array( 'fixes', 'ok' ), true ) ) : ?>
					<h3><?php esc_html_e( 'Where it breaks', 'airworthy-pro' ); ?></h3>
					<ul class="airworthy-pro-watch-group">
						<?php foreach ( array_slice( $r['samples'], 0, 5 ) as $smp ) : ?>
							<li><code><?php echo esc_html( $smp['file'] . ':' . $smp['line'] ); ?></code> <?php echo esc_html( $smp['message'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="airworthy-muted"><?php esc_html_e( 'Airworthy reads code without running it, so still try anything new on a staging copy first.', 'airworthy-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The upload form.
	 */
	private static function form() {
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-card airworthy-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::UPLOAD ); ?>" />
			<?php wp_nonce_field( self::UPLOAD ); ?>
			<h2><?php esc_html_e( 'Choose a zip', 'airworthy-pro' ); ?></h2>
			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-zip">📦 <?php esc_html_e( 'Plugin or theme (.zip)', 'airworthy-pro' ); ?></label></legend>
				<input type="file" id="airworthy-pro-zip" name="zip" accept=".zip,application/zip" required="required" />
				<p class="description"><?php esc_html_e( 'Up to 60 MB. It is unpacked in the server\'s temporary folder and deleted when the check ends.', 'airworthy-pro' ); ?></p>
			</fieldset>
			<fieldset class="airworthy-setting">
				<legend><label for="airworthy-pro-zip-target">🎯 <?php esc_html_e( 'Check against', 'airworthy-pro' ); ?></label></legend>
				<select id="airworthy-pro-zip-target" name="target">
					<option value="auto"><?php echo esc_html( sprintf( /* translators: %s: PHP version. */ __( 'The version of your last scan (PHP %s)', 'airworthy-pro' ), self::last_target() ) ); ?></option>
					<?php foreach ( Targets::ALL as $version ) : ?>
						<option value="<?php echo esc_attr( $version ); ?>"><?php echo esc_html( 'PHP ' . $version ); ?></option>
					<?php endforeach; ?>
				</select>
			</fieldset>
			<p class="airworthy-pro-actions"><button type="submit" class="button button-primary">🔍 <?php esc_html_e( 'Check it', 'airworthy-pro' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * The PHP version the last finished scan checked against.
	 *
	 * @return string
	 */
	private static function last_target() {
		if ( ! class_exists( '\\Airworthy\\Scan\\UpdateCheck' ) ) {
			return '';
		}
		$scan = \Airworthy\Scan\UpdateCheck::latest_scan();
		return $scan ? (string) $scan->target_php : Targets::default_for( Env::php_version() );
	}

	/**
	 * Takes the upload and starts the check.
	 */
	public static function upload() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::UPLOAD );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		if ( empty( $_FILES['zip']['tmp_name'] ) || ! empty( $_FILES['zip']['error'] ) ) {
			self::back( array( 'notice' => 'nofile' ) );
		}
		$tmp  = (string) $_FILES['zip']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A server path PHP made; validated by is_uploaded_file() below.
		$name = isset( $_FILES['zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['zip']['name'] ) ) : '';
		$pick = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'auto';
		// phpcs:enable
		if ( ! is_uploaded_file( $tmp ) || '.zip' !== strtolower( substr( $name, -4 ) ) ) {
			self::back( array( 'notice' => 'notzip' ) );
		}
		if ( filesize( $tmp ) > \Airworthy\Scan\UpdateCheck::MAX_ZIP ) {
			self::back( array( 'notice' => 'toobig' ) );
		}
		$scan = \Airworthy\Scan\UpdateCheck::latest_scan();
		if ( ! $scan ) {
			self::back( array( 'notice' => 'nofirst' ) );
		}
		$target = Targets::is_valid( $pick ) ? $pick : (string) $scan->target_php;

		list( $type, $slug ) = self::identify( $tmp );
		$job                 = \Airworthy\Scan\UpdateCheck::from_zip(
			$tmp,
			$target,
			isset( $scan->from_php ) ? (string) $scan->from_php : '',
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);
		if ( is_wp_error( $job ) ) {
			self::back( array( 'notice' => 'notzip' ) );
		}
		self::back( array( 'job' => $job ) );
	}

	/**
	 * Whether a zip is a plugin or a theme, and its folder name (the slug).
	 *
	 * @param string $zip Path.
	 * @return array{0:string,1:string} Type and slug ('' when unknown).
	 */
	private static function identify( $zip ) {
		$za = new \ZipArchive();
		if ( true !== $za->open( $zip ) ) {
			return array( 'plugin', '' );
		}
		$first = '';
		$theme = false;
		$count = $za->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$name = (string) $za->getNameIndex( $i );
			$bits = explode( '/', $name );
			if ( '' === $first && count( $bits ) > 1 ) {
				$first = $bits[0];
			}
			if ( '' !== $first && $name === $first . '/style.css' ) {
				$theme = true;
			}
		}
		$za->close();
		return array( $theme ? 'theme' : 'plugin', sanitize_file_name( $first ) );
	}

	/**
	 * Back to the tab.
	 *
	 * @param array $args Query arguments.
	 */
	private static function back( array $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
