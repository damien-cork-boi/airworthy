<?php
/**
 * The upgrade plan: a checklist from a scan's results that ticks itself off.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;
use Airworthy\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the latest scan: one step per plugin or theme that needs something (update,
 * fix, replace, check the files that couldn't be read), then recheck, back up, test on
 * staging, switch PHP and a final check. Steps Airworthy can see tick themselves off (a
 * plugin updated, a new scan with nothing to fix, the server on the new version); backup and
 * staging are ticked by hand, and any step can be.
 */
final class Plan {

	/** Tab slug on the Airworthy screen. */
	const TAB = 'plan';

	/** Site option holding the plan. */
	const OPTION = 'airworthy_pro_plan';

	/** Action names for admin-post.php. */
	const CREATE = 'airworthy_pro_plan_create';
	const TICK   = 'airworthy_pro_plan_tick';

	/**
	 * Hooks the tab and the form handlers.
	 */
	public static function register() {
		add_filter( 'airworthy_admin_tabs', array( __CLASS__, 'add_tab' ), 6 );
		add_action( 'airworthy_render_tab_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::CREATE, array( __CLASS__, 'create' ) );
		add_action( 'admin_post_' . self::TICK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Adds the tab.
	 *
	 * @param array $tabs slug => label.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs[ self::TAB ] = __( 'Upgrade plan', 'airworthy-pro' );
		return $tabs;
	}

	/**
	 * The tab's URL.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( 'tab', self::TAB, \Airworthy\Admin\Page::url() );
	}

	/**
	 * GET from Airworthy's REST API, as the current user.
	 *
	 * @param string $path Route under /airworthy/v1.
	 * @return array|null
	 */
	private static function api( $path ) {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/airworthy/v1' . $path ) );
		return $response->is_error() ? null : $response->get_data();
	}

	/**
	 * The latest finished scan (with components), or null.
	 *
	 * @return array|null
	 */
	private static function latest_scan() {
		$id = Scans::latest_id();
		return $id ? self::api( '/scans/' . $id ) : null;
	}

	/**
	 * The saved plan, or null.
	 *
	 * @return array|null
	 */
	public static function get() {
		$plan = get_site_option( self::OPTION );
		return is_array( $plan ) && ! empty( $plan['target'] ) ? $plan : null;
	}

	/**
	 * Builds a plan from a scan's results.
	 *
	 * @param array  $scan   Scan from the REST API.
	 * @param string $target PHP version the plan moves to.
	 * @return array
	 */
	public static function build( array $scan, $target ) {
		$items = array();
		foreach ( (array) $scan['components'] as $c ) {
			if ( 'plugin' === $c['type'] && in_array( $c['slug'], array( 'airworthy', 'airworthy-pro' ), true ) ) {
				continue;
			}
			$base    = array(
				'ctype' => $c['type'],
				'slug'  => $c['slug'],
				'name'  => $c['name'],
			);
			$closed  = ! empty( $c['wporg']['status'] ) && 'closed' === $c['wporg']['status'];
			$attn    = in_array( $c['verdict'], array( 'blocker', 'unknown' ), true );
			$watch   = in_array( $c['verdict'], array( 'suppressed', 'warnings' ), true );
			$version = ! empty( $c['update']['version'] ) ? (string) $c['update']['version'] : '';
			if ( $closed ) {
				$items[] = $base + array( 'kind' => 'replace' );
			} elseif ( $version && ( $attn || $watch ) ) {
				$items[] = $base + array(
					'kind' => 'update',
					'to'   => $version,
				);
			} elseif ( 'blocker' === $c['verdict'] ) {
				$items[] = $base + array( 'kind' => 'fix' );
			} elseif ( 'unknown' === $c['verdict'] ) {
				$items[] = $base + array( 'kind' => 'files' );
			}
		}
		return array(
			'target'  => (string) $target,
			'scan_id' => (int) $scan['id'],
			'created' => time(),
			'items'   => $items,
			'manual'  => array(),
		);
	}

	/**
	 * Builds a new plan from the latest scan (the plan form).
	 */
	public static function create() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::CREATE );
		$scan   = self::latest_scan();
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		if ( ! $scan || ! Targets::is_valid( $target ) ) {
			wp_die( esc_html__( 'Run a scan first: the plan is built from the latest finished scan.', 'airworthy-pro' ), 400 );
		}
		// A plan for a version below the scanned one can use the matrix's verdicts; for the
		// scanned version (the usual case) the scan's own.
		if ( $target !== $scan['target_php'] ) {
			$full = Versions::latest_full_scan();
			if ( $full && version_compare( $target, $full['target_php'], '<=' ) ) {
				$scan = self::at_version( $full, $target );
			}
		}
		update_site_option( self::OPTION, self::build( $scan, $target ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * A full scan's components with their verdicts on one older version.
	 *
	 * @param array  $scan   Full scan (newest version).
	 * @param string $target PHP version.
	 * @return array
	 */
	private static function at_version( array $scan, $target ) {
		$matrix = Versions::matrix( $scan );
		$by     = array();
		foreach ( $matrix['rows'] as $r ) {
			$by[ $r['type'] . '|' . $r['slug'] ] = isset( $r['verdicts'][ $target ] ) ? $r['verdicts'][ $target ] : null;
		}
		foreach ( $scan['components'] as &$c ) {
			$key = $c['type'] . '|' . $c['slug'];
			if ( isset( $by[ $key ] ) ) {
				$c['verdict'] = $by[ $key ];
			}
		}
		unset( $c );
		$scan['target_php'] = $target;
		return $scan;
	}

	/**
	 * Ticks or unticks a step by hand.
	 */
	public static function tick() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::TICK );
		$plan = self::get();
		$key  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( $plan && '' !== $key ) {
			if ( empty( $plan['manual'][ $key ] ) ) {
				$plan['manual'][ $key ] = time();
			} else {
				unset( $plan['manual'][ $key ] );
			}
			update_site_option( self::OPTION, $plan );
		}
		wp_safe_redirect( self::url() . '#step-' . $key );
		exit;
	}

	/**
	 * Installed versions, keyed "type|slug" (plugins and themes).
	 *
	 * @return array<string,array{version:string,active:bool}>
	 */
	private static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug                     = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
			$out[ 'plugin|' . $slug ] = array(
				'version' => (string) $data['Version'],
				'active'  => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
			);
		}
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[ 'theme|' . $stylesheet ] = array(
				'version' => (string) $theme->get( 'Version' ),
				'active'  => true,
			);
		}
		return $out;
	}

	/**
	 * Every step with its text and whether it's done (and how).
	 *
	 * @param array $plan Plan.
	 * @return array[] key, title, text, done (bool), how (auto | manual | ''), action (url, label).
	 */
	public static function steps( array $plan ) {
		$target    = $plan['target'];
		$installed = self::installed();
		$updates   = \Airworthy\Scan\Updates::all();
		$latest    = self::latest_scan();
		$after     = $latest && strtotime( (string) $latest['finished_at'] ) >= (int) $plan['created'] ? $latest : null;
		// A newer scan against a later version (e.g. for the PHP versions tab) still counts:
		// use its worked-out verdicts for the plan's version.
		if ( $after && $after['target_php'] !== $target && version_compare( $after['target_php'], $target, '>' ) && Versions::newest() === $after['target_php'] ) {
			$after = self::at_version( $after, $target );
		}
		$verdicts = array();
		if ( $after ) {
			foreach ( $after['components'] as $c ) {
				$verdicts[ $c['type'] . '|' . $c['slug'] ] = $c['verdict'];
			}
		}
		$server  = Targets::minor( \Airworthy\Env::php_version() );
		$results = \Airworthy\Admin\Page::url();
		$steps   = array();

		foreach ( $plan['items'] as $item ) {
			$id   = $item['ctype'] . '|' . $item['slug'];
			$key  = sanitize_key( $item['kind'] . '-' . $item['ctype'] . '-' . $item['slug'] );
			$have = isset( $installed[ $id ] ) ? $installed[ $id ] : null;
			$auto = false;
			$act  = null;
			switch ( $item['kind'] ) {
				case 'update':
					$auto = ! $have || version_compare( $have['version'], $item['to'], '>=' );
					/* translators: 1: plugin name, 2: version. */
					$title = sprintf( __( 'Update %1$s to %2$s', 'airworthy-pro' ), $item['name'], $item['to'] );
					$text  = __( 'Updates often fix compatibility. Airworthy checks it again by itself once it\'s updated.', 'airworthy-pro' );
					if ( ! $auto && ! empty( $updates[ $id ]['url'] ) ) {
						$act = array( $updates[ $id ]['url'], __( 'Update now', 'airworthy-pro' ) );
					}
					break;
				case 'replace':
					$auto = ! $have || ! $have['active'];
					/* translators: %s: plugin name. */
					$title = sprintf( __( 'Replace %s', 'airworthy-pro' ), $item['name'] );
					$text  = __( 'It was removed from WordPress.org and gets no more updates or security fixes. Find a maintained alternative, then deactivate and delete it.', 'airworthy-pro' );
					$act   = array( self_admin_url( 'plugins.php' ), __( 'Go to Plugins', 'airworthy-pro' ) );
					break;
				case 'files':
					$auto = ! $have || ( isset( $verdicts[ $id ] ) && 'unknown' !== $verdicts[ $id ] );
					/* translators: %s: plugin or theme name. */
					$title = sprintf( __( 'Get %s fully checked', 'airworthy-pro' ), $item['name'] );
					$text  = __( 'Some of its files couldn\'t be read. Raising the server\'s PHP memory limit usually fixes that; then scan again.', 'airworthy-pro' );
					$act   = array( $results, __( 'See which files', 'airworthy-pro' ) );
					break;
				default: // fix.
					$auto = ! $have || ( isset( $verdicts[ $id ] ) && ! in_array( $verdicts[ $id ], array( 'blocker', 'unknown' ), true ) );
					/* translators: %s: plugin or theme name. */
					$title = sprintf( __( 'Fix or replace %s', 'airworthy-pro' ), $item['name'] );
					/* translators: %s: PHP version. */
					$text = sprintf( __( 'Some of its code won\'t work on PHP %s and no update is out yet. Ask its author for a fix, or replace it.', 'airworthy-pro' ), $target );
					$act  = array( $results, __( 'See what breaks', 'airworthy-pro' ) );
			}
			$steps[] = array(
				'key'    => $key,
				'title'  => $title,
				'text'   => $text,
				'auto'   => $auto,
				'action' => $act,
			);
		}

		$items_done = ! array_filter(
			$steps,
			static function ( $s ) use ( $plan ) {
				return ! $s['auto'] && empty( $plan['manual'][ $s['key'] ] );
			}
		);
		$clean      = $after && ! array_filter(
			$after['components'],
			static function ( $c ) {
				return in_array( $c['verdict'], array( 'blocker', 'unknown' ), true ) && ! ( 'plugin' === $c['type'] && in_array( $c['slug'], array( 'airworthy', 'airworthy-pro' ), true ) );
			}
		);
		if ( $steps ) {
			$steps[] = array(
				'key'    => 'recheck',
				'title'  => __( 'Run Airworthy again', 'airworthy-pro' ),
				'text'   => __( 'Confirm the changes above cleared the findings before going on.', 'airworthy-pro' ),
				'auto'   => $items_done && $after && $clean && $after['target_php'] === $target,
				'action' => array( $results, __( 'Go to Scan', 'airworthy-pro' ) ),
			);
		}
		$steps[] = array(
			'key'    => 'backup',
			'title'  => __( 'Back up the whole site', 'airworthy-pro' ),
			'text'   => __( 'Files and database, somewhere off the server. Tick this when it\'s done.', 'airworthy-pro' ),
			'auto'   => false,
			'action' => null,
		);
		$steps[] = array(
			'key'    => 'staging',
			'title'  => __( 'Try it on a staging copy', 'airworthy-pro' ),
			/* translators: %s: PHP version. */
			'text'   => sprintf( __( 'Switch a staging copy to PHP %s and click through what matters: forms, checkout, logins, bookings. Tick this when it\'s done.', 'airworthy-pro' ), $target ),
			'auto'   => false,
			'action' => null,
		);
		$steps[] = array(
			'key'    => 'switch',
			/* translators: %s: PHP version. */
			'title'  => sprintf( __( 'Switch the live site to PHP %s', 'airworthy-pro' ), $target ),
			/* translators: %s: server PHP version. */
			'text'   => sprintf( __( 'In your hosting control panel, or ask your host. The server runs PHP %s now; this ticks itself once it changes.', 'airworthy-pro' ), $server ),
			'auto'   => version_compare( $server, $target, '>=' ),
			'action' => null,
		);
		$steps[] = array(
			'key'    => 'confirm',
			'title'  => __( 'Final check', 'airworthy-pro' ),
			/* translators: %s: PHP version. */
			'text'   => sprintf( __( 'Run Airworthy once more on PHP %s, and look over the site.', 'airworthy-pro' ), $target ),
			'auto'   => $after && $clean && version_compare( Targets::minor( (string) $after['host_php'] ), $target, '>=' ),
			'action' => array( $results, __( 'Go to Scan', 'airworthy-pro' ) ),
		);

		foreach ( $steps as &$s ) {
			$manual    = ! empty( $plan['manual'][ $s['key'] ] );
			$s['done'] = $s['auto'] || $manual;
			$s['how']  = $s['auto'] ? 'auto' : ( $manual ? 'manual' : '' );
		}
		unset( $s );
		return $steps;
	}

	/**
	 * Draws the tab.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			return;
		}
		$plan   = self::get();
		$latest = self::latest_scan();
		$full   = Versions::latest_full_scan();
		$form   = static function ( $label, $primary ) use ( $latest, $full, $plan ) {
			$default = $plan ? $plan['target'] : ( $latest ? $latest['target_php'] : '' );
			$options = array();
			foreach ( Targets::ALL as $t ) {
				// Versions the latest scan covers: its own, or any below a full scan's.
				if ( ( $latest && $t === $latest['target_php'] ) || ( $full && version_compare( $t, $full['target_php'], '<=' ) ) ) {
					$options[] = $t;
				}
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="airworthy-pro-plan-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE ); ?>" />
				<?php wp_nonce_field( self::CREATE ); ?>
				<label for="airworthy-pro-plan-target"><?php esc_html_e( 'Moving to', 'airworthy-pro' ); ?></label>
				<select id="airworthy-pro-plan-target" name="target">
					<?php foreach ( $options as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $default ); ?>>PHP <?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		};

		if ( ! $plan ) {
			?>
			<div class="airworthy-card airworthy-pro-plan-intro">
				<h2><?php esc_html_e( 'Your upgrade plan', 'airworthy-pro' ); ?></h2>
				<p><?php esc_html_e( 'A step-by-step checklist built from your results: what to update, what to replace, then back up, test, switch and check. Airworthy ticks steps off as they happen; you tick the ones it can\'t see, like the backup.', 'airworthy-pro' ); ?></p>
				<?php if ( $latest ) : ?>
					<?php $form( __( 'Build my plan', 'airworthy-pro' ), true ); ?>
				<?php else : ?>
					<p class="airworthy-note"><?php esc_html_e( 'Run a scan first: the plan is built from its results.', 'airworthy-pro' ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		$steps = self::steps( $plan );
		$done  = count( wp_list_filter( $steps, array( 'done' => true ) ) );
		$total = count( $steps );
		$pct   = $total ? (int) round( 100 * $done / $total ) : 0;
		$ended = $done === $total;
		?>
		<div class="airworthy-card airworthy-pro-plan">
			<div class="airworthy-pro-plan-head">
				<div>
					<p class="airworthy-pro-eyebrow"><?php esc_html_e( 'Upgrade plan', 'airworthy-pro' ); ?></p>
					<h2>
						<?php
						/* translators: %s: PHP version. */
						echo esc_html( sprintf( __( 'Moving to PHP %s', 'airworthy-pro' ), $plan['target'] ) );
						?>
					</h2>
				</div>
				<div class="airworthy-pro-plan-score">
					<strong><?php echo esc_html( $done . ' / ' . $total ); ?></strong>
					<span><?php esc_html_e( 'steps done', 'airworthy-pro' ); ?></span>
				</div>
			</div>
			<div class="airworthy-pro-plan-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $pct ); ?>" aria-valuemin="0" aria-valuemax="100"><span style="width: <?php echo esc_attr( (string) $pct ); ?>%"></span></div>

			<?php if ( $ended ) : ?>
				<div class="airworthy-headline airworthy-headline-good">
					<span class="airworthy-headline-icon" aria-hidden="true">✓</span>
					<div>
						<strong>
							<?php
							/* translators: %s: PHP version. */
							echo esc_html( sprintf( __( 'Upgrade complete: this site runs PHP %s and nothing needs attention.', 'airworthy-pro' ), $plan['target'] ) );
							?>
						</strong>
						<p><?php esc_html_e( 'Keep plugins updated, and check again before the next PHP version.', 'airworthy-pro' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<ol class="airworthy-pro-plan-steps">
				<?php foreach ( $steps as $i => $s ) : ?>
					<li id="step-<?php echo esc_attr( $s['key'] ); ?>" class="<?php echo $s['done'] ? 'done' : ''; ?>">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::TICK ); ?>" />
							<input type="hidden" name="step" value="<?php echo esc_attr( $s['key'] ); ?>" />
							<?php wp_nonce_field( self::TICK ); ?>
							<button type="submit" class="airworthy-pro-check" <?php disabled( 'auto' === $s['how'] ); ?> aria-pressed="<?php echo $s['done'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $s['done'] ? __( 'Mark as not done', 'airworthy-pro' ) : __( 'Mark as done', 'airworthy-pro' ) ); ?>">
								<?php echo $s['done'] ? '✓' : esc_html( (string) ( $i + 1 ) ); ?>
							</button>
						</form>
						<div class="airworthy-pro-step-body">
							<strong><?php echo esc_html( $s['title'] ); ?></strong>
							<p><?php echo esc_html( $s['text'] ); ?></p>
							<?php if ( 'auto' === $s['how'] ) : ?>
								<span class="airworthy-pro-how"><?php esc_html_e( 'Done: Airworthy saw it happen', 'airworthy-pro' ); ?></span>
							<?php elseif ( 'manual' === $s['how'] ) : ?>
								<span class="airworthy-pro-how"><?php esc_html_e( 'Ticked by you', 'airworthy-pro' ); ?></span>
							<?php elseif ( $s['action'] ) : ?>
								<a class="button" href="<?php echo esc_url( $s['action'][0] ); ?>"><?php echo esc_html( $s['action'][1] ); ?></a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="airworthy-pro-plan-foot">
				<span class="airworthy-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date. */
							__( 'Plan made %s from the scan results at the time.', 'airworthy-pro' ),
							date_i18n( get_option( 'date_format' ), (int) $plan['created'] )
						)
					);
					?>
				</span>
				<?php $form( __( 'Start a new plan', 'airworthy-pro' ), false ); ?>
			</div>
		</div>
		<?php
	}
}
