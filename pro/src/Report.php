<?php
/**
 * The client report: a print-ready page of one scan's results, in plain words, with the
 * agency's branding. Browsers save it as a PDF.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the report from Airworthy's REST API (the same data the results screen uses) and
 * prints it as a standalone page, through admin-post.php.
 */
final class Report {

	/** Action name for admin-post.php. */
	const ACTION = 'airworthy_pro_report';

	/** Findings shown per plugin that needs attention. */
	const FINDINGS_PER_PLUGIN = 4;

	/**
	 * Hooks the report page and the "Client report" button on the results.
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'render' ) );
		add_filter( 'airworthy_results_actions', array( __CLASS__, 'results_action' ) );
	}

	/**
	 * The report's URL for a scan.
	 *
	 * @param int|string $scan_id Scan ID, or "__SCAN__" for the results screen to fill in.
	 * @return string
	 */
	public static function url( $scan_id ) {
		// Not wp_nonce_url(): it returns an HTML-escaped URL ("&amp;"), and this one goes into JSON.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'scan'     => $scan_id,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Adds "Client report" next to Export CSV on the results.
	 *
	 * @param array $actions Buttons.
	 * @return array
	 */
	public static function results_action( $actions ) {
		$actions[] = array(
			'label' => __( 'Client report', 'airworthy-pro' ),
			'url'   => self::url( '__SCAN__' ),
		);
		return $actions;
	}

	/**
	 * A GET request to Airworthy's REST API, as the current user.
	 *
	 * @param string $path   Route under /airworthy/v1.
	 * @param array  $params Query parameters.
	 * @return array|null Response data, or null on error.
	 */
	private static function api( $path, array $params = array() ) {
		$request = new \WP_REST_Request( 'GET', '/airworthy/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		return $response->is_error() ? null : $response->get_data();
	}

	/**
	 * One sentence per verdict, for a client (plainer than the admin screen).
	 *
	 * @param string $verdict Verdict key.
	 * @param string $target  PHP version.
	 * @return string
	 */
	private static function verdict_line( $verdict, $target ) {
		switch ( $verdict ) {
			case 'blocker':
				/* translators: %s: PHP version. */
				return sprintf( __( 'Will not work on PHP %s. It needs updating, fixing or replacing first.', 'airworthy-pro' ), $target );
			case 'unknown':
				return __( 'Some of its files could not be checked, so there is no verdict yet.', 'airworthy-pro' );
			case 'suppressed':
				return __( 'Its authors marked some old code as intentional. Usually safe.', 'airworthy-pro' );
			case 'guarded':
				/* translators: %s: PHP version. */
				return sprintf( __( 'Contains old code that never runs on PHP %s. Safe.', 'airworthy-pro' ), $target );
			case 'warnings':
				/* translators: %s: PHP version. */
				return sprintf( __( 'Works on PHP %s. Will need updating before a later PHP version.', 'airworthy-pro' ), $target );
			default:
				/* translators: %s: PHP version. */
				return sprintf( __( 'No problems found for PHP %s.', 'airworthy-pro' ), $target );
		}
	}

	/**
	 * Verdict labels.
	 *
	 * @return array<string,string>
	 */
	private static function labels() {
		return array(
			'blocker'    => __( 'Blocker', 'airworthy-pro' ),
			'unknown'    => __( 'Unknown', 'airworthy-pro' ),
			'suppressed' => __( 'Suppressed by author', 'airworthy-pro' ),
			'guarded'    => __( 'Guarded legacy code', 'airworthy-pro' ),
			'warnings'   => __( 'Warnings', 'airworthy-pro' ),
			'ready'      => __( 'Ready', 'airworthy-pro' ),
		);
	}

	/**
	 * The recommended next steps, built from the results.
	 *
	 * @param array  $groups  fix / watch / good component lists.
	 * @param array  $closed  Plugins removed from WordPress.org.
	 * @param string $target  PHP version.
	 * @return string[]
	 */
	private static function next_steps( array $groups, array $closed, $target ) {
		$names   = static function ( array $items ) {
			return implode( ', ', wp_list_pluck( $items, 'name' ) );
		};
		$updates = array_filter(
			array_merge( $groups['fix'], $groups['watch'] ),
			static function ( $c ) {
				return ! empty( $c['update'] );
			}
		);
		$stuck   = array_filter(
			$groups['fix'],
			static function ( $c ) {
				return 'blocker' === $c['verdict'] && empty( $c['update'] );
			}
		);
		$unknown = array_filter(
			$groups['fix'],
			static function ( $c ) {
				return 'unknown' === $c['verdict'];
			}
		);
		$steps   = array();
		if ( $updates ) {
			/* translators: %s: list of plugin names. */
			$steps[] = sprintf( __( 'Update these first; updates often fix compatibility: %s.', 'airworthy-pro' ), $names( $updates ) );
		}
		if ( $stuck ) {
			/* translators: %s: list of plugin names. */
			$steps[] = sprintf( __( 'Ask the authors for a fix, or replace: %s.', 'airworthy-pro' ), $names( $stuck ) );
		}
		if ( $closed ) {
			/* translators: %s: list of plugin names. */
			$steps[] = sprintf( __( 'Replace plugins removed from WordPress.org, which get no more security fixes: %s.', 'airworthy-pro' ), $names( $closed ) );
		}
		if ( $unknown ) {
			/* translators: %s: list of plugin names. */
			$steps[] = sprintf( __( 'Have the files that could not be checked looked at: %s.', 'airworthy-pro' ), $names( $unknown ) );
		}
		$steps[] = __( 'Back up the whole site: files and database.', 'airworthy-pro' );
		/* translators: %s: PHP version. */
		$steps[] = sprintf( __( 'Try PHP %s on a staging copy first and click through what matters: forms, checkout, logins, bookings.', 'airworthy-pro' ), $target );
		/* translators: %s: PHP version. */
		$steps[] = sprintf( __( 'Switch the live site to PHP %s, then run Airworthy again to confirm.', 'airworthy-pro' ), $target );
		return $steps;
	}

	/**
	 * Text for a double-quoted CSS string inside a <style> element: backslashes and quotes
	 * escaped, line breaks flattened, and < > written as CSS escapes so it can't close the tag.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function css_string( $text ) {
		return str_replace( array( '\\', '"', "\n", "\r", '<', '>' ), array( '\\\\', '\\"', ' ', ' ', '\\3c ', '\\3e ' ), (string) $text );
	}

	/**
	 * Prints the report page.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can_scan() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'airworthy-pro' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$scan_id = isset( $_GET['scan'] ) ? absint( $_GET['scan'] ) : 0;
		$scan    = $scan_id ? self::api( '/scans/' . $scan_id ) : null;
		if ( ! $scan || empty( $scan['components'] ) ) {
			wp_die( esc_html__( 'That scan could not be found. Run a scan first, then open the report from its results.', 'airworthy-pro' ), 404 );
		}

		$target     = (string) $scan['target_php'];
		$components = (array) $scan['components'];
		$groups     = array(
			'fix'   => array(),
			'watch' => array(),
			'good'  => array(),
		);
		foreach ( $components as $c ) {
			if ( in_array( $c['verdict'], array( 'blocker', 'unknown' ), true ) ) {
				$groups['fix'][] = $c;
			} elseif ( in_array( $c['verdict'], array( 'suppressed', 'warnings' ), true ) ) {
				$groups['watch'][] = $c;
			} elseif ( $c['verdict'] ) {
				$groups['good'][] = $c;
			}
		}
		$closed    = array_values(
			array_filter(
				$components,
				static function ( $c ) {
					return ! empty( $c['wporg']['status'] ) && 'closed' === $c['wporg']['status'];
				}
			)
		);
		$abandoned = array_values(
			array_filter(
				$components,
				static function ( $c ) {
					return ! empty( $c['wporg']['abandoned'] ) && ( empty( $c['wporg']['status'] ) || 'closed' !== $c['wporg']['status'] );
				}
			)
		);
		// What fails, for each plugin that needs attention.
		$findings = array();
		foreach ( $groups['fix'] as $c ) {
			$items                = self::api(
				'/scans/' . $scan_id . '/components/' . (int) $c['id'] . '/issues',
				array(
					'severity' => array( 'scan', 'error' ),
					'context'  => array( 'plain' ),
					'per_page' => self::FINDINGS_PER_PLUGIN,
					'page'     => 1,
				)
			);
			$findings[ $c['id'] ] = is_array( $items ) ? $items : array();
		}

		$branding   = Branding::get();
		$logo       = Branding::logo_url( $branding );
		$site_name  = get_bloginfo( 'name' );
		$site_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$checked    = ! empty( $scan['finished_at'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $scan['finished_at'] ) ) : '';
		$counts     = (array) $scan['counts'];
		$labels     = self::labels();
		$needs      = (int) $counts['blocker'];
		$support    = isset( $scan['host_support'] ) && is_array( $scan['host_support'] ) ? $scan['host_support'] : null;
		$steps      = self::next_steps( $groups, $closed, $target );
		$is_stopped = 'complete' !== $scan['status'];

		if ( $needs ) {
			$headline = sprintf(
				/* translators: 1: number of plugins/themes, 2: PHP version. */
				_n( '%1$d plugin or theme needs attention before the upgrade to PHP %2$s.', '%1$d plugins or themes need attention before the upgrade to PHP %2$s.', $needs, 'airworthy-pro' ),
				$needs,
				$target
			);
			$kind = 'bad';
		} elseif ( (int) $counts['unknown'] ) {
			/* translators: %s: PHP version. */
			$headline = sprintf( __( 'Nothing found that would break on PHP %s, but some files could not be checked.', 'airworthy-pro' ), $target );
			$kind     = 'unknown';
		} else {
			/* translators: %s: PHP version. */
			$headline = sprintf( __( 'Ready for PHP %s: nothing found that would break.', 'airworthy-pro' ), $target );
			$kind     = 'good';
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		/* translators: 1: site name, 2: PHP version. */
		$title = sprintf( __( 'PHP %2$s readiness report – %1$s', 'airworthy-pro' ), $site_name, $target );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $title ); ?></title>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- A standalone print page with no wp_head(). ?>
	<link rel="stylesheet" href="<?php echo esc_url( AIRWORTHY_PRO_URL . 'assets/report.css?ver=' . AIRWORTHY_PRO_VERSION ); ?>" />
	<style>
		:root { --accent: <?php echo esc_html( $branding['color'] ); ?>; }
		@page {
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- css_string() escapes for a CSS string. ?>
			@bottom-left { content: "<?php echo self::css_string( $branding['name'] ? $branding['name'] : $site_name ); ?>"; font: 9pt -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #8c8f94; }
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- css_string() escapes for a CSS string. ?>
			@bottom-right { content: "<?php echo self::css_string( __( 'Page', 'airworthy-pro' ) ); ?> " counter(page) " / " counter(pages); font: 9pt -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #8c8f94; }
		}
		@page :first {
			<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- css_string() escapes for a CSS string. ?>
			@bottom-center { content: "<?php echo self::css_string( __( 'Made with Airworthy · airworthywp.com', 'airworthy-pro' ) ); ?>"; font: 8pt -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #a7aaad; }
		}
	</style>
</head>
<body>
	<div class="toolbar">
		<button type="button" class="primary" onclick="window.print()"><?php esc_html_e( 'Download PDF', 'airworthy-pro' ); ?></button>
		<span><?php esc_html_e( 'In the print window, choose "Save as PDF" as the destination.', 'airworthy-pro' ); ?></span>
	</div>

	<main class="report">
		<header class="cover">
			<div class="cover-band"></div>
			<div class="cover-top">
				<?php if ( $logo ) : ?>
					<img class="logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $branding['name'] ); ?>" />
				<?php elseif ( $branding['name'] ) : ?>
					<p class="agency"><?php echo esc_html( $branding['name'] ); ?></p>
				<?php endif; ?>
				<p class="date"><?php echo esc_html( $checked ); ?></p>
			</div>
			<p class="eyebrow"><?php esc_html_e( 'PHP upgrade readiness report', 'airworthy-pro' ); ?></p>
			<h1><?php echo esc_html( $site_name ? $site_name : $site_host ); ?></h1>
			<p class="site"><?php echo esc_html( $site_host ); ?></p>
			<p class="meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: target PHP version, 2: server PHP version. */
						__( 'Checked against PHP %1$s · the server runs PHP %2$s', 'airworthy-pro' ),
						$target,
						$scan['host_php']
					)
				);
				?>
			</p>
			<?php if ( $branding['name'] ) : ?>
				<p class="prepared">
					<?php
					/* translators: %s: agency name. */
					echo esc_html( sprintf( __( 'Prepared by %s', 'airworthy-pro' ), $branding['name'] ) );
					?>
				</p>
			<?php endif; ?>
		</header>

		<?php if ( $is_stopped ) : ?>
			<p class="note warn"><?php esc_html_e( 'This scan was stopped before it finished, so this report is incomplete.', 'airworthy-pro' ); ?></p>
		<?php endif; ?>

		<section class="headline headline-<?php echo esc_attr( $kind ); ?>">
			<strong><?php echo esc_html( $headline ); ?></strong>
			<?php if ( $support ) : ?>
				<p><?php echo esc_html( $support['text'] ); ?></p>
			<?php endif; ?>
		</section>

		<ul class="tiles">
			<?php foreach ( $labels as $key => $label ) : ?>
				<li class="tile tile-<?php echo esc_attr( $key ); ?><?php echo (int) $counts[ $key ] ? ' has' : ''; ?>">
					<span class="n"><?php echo esc_html( (string) (int) $counts[ $key ] ); ?></span>
					<span class="l"><?php echo esc_html( $label ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<section class="block">
			<h2><?php esc_html_e( 'Recommended next steps', 'airworthy-pro' ); ?></h2>
			<ol class="steps">
				<?php foreach ( $steps as $step ) : ?>
					<li><?php echo esc_html( $step ); ?></li>
				<?php endforeach; ?>
			</ol>
		</section>

		<div class="details">
		<?php if ( $groups['fix'] ) : ?>
			<section class="block">
				<h2><?php esc_html_e( 'Fix before the upgrade', 'airworthy-pro' ); ?></h2>
				<?php foreach ( $groups['fix'] as $c ) : ?>
					<article class="item">
						<div class="item-head">
							<strong><?php echo esc_html( $c['name'] ); ?></strong>
							<span class="badge badge-<?php echo esc_attr( $c['verdict'] ); ?>"><?php echo esc_html( $labels[ $c['verdict'] ] ); ?></span>
						</div>
						<p class="muted">
							<?php
							/* translators: %s: version number. */
							echo esc_html( $c['version'] ? sprintf( __( 'Version %s', 'airworthy-pro' ), $c['version'] ) : '' );
							if ( ! empty( $c['update'] ) ) {
								/* translators: %s: version number. */
								echo ' · <span class="update">' . esc_html( sprintf( __( 'update available: %s', 'airworthy-pro' ), $c['update']['version'] ) ) . '</span>';
							}
							?>
						</p>
						<p><?php echo esc_html( self::verdict_line( $c['verdict'], $target ) ); ?></p>
						<?php if ( ! empty( $findings[ $c['id'] ] ) ) : ?>
							<div class="tech">
								<p class="tech-title"><?php esc_html_e( 'Technical detail, for your developer', 'airworthy-pro' ); ?></p>
								<ul>
									<?php foreach ( $findings[ $c['id'] ] as $f ) : ?>
										<li><code><?php echo esc_html( $f['file'] . ( $f['line'] ? ':' . $f['line'] : '' ) ); ?></code> <?php echo esc_html( $f['message'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>

		<?php if ( $closed || $abandoned ) : ?>
			<section class="block">
				<h2><?php esc_html_e( 'Maintenance concerns', 'airworthy-pro' ); ?></h2>
				<ul class="list">
					<?php foreach ( $closed as $c ) : ?>
						<li><strong><?php echo esc_html( $c['name'] ); ?></strong> · <?php esc_html_e( 'removed from WordPress.org: no more updates or security fixes. Plan to replace it.', 'airworthy-pro' ); ?></li>
					<?php endforeach; ?>
					<?php foreach ( $abandoned as $c ) : ?>
						<li><strong><?php echo esc_html( $c['name'] ); ?></strong> · <?php esc_html_e( 'not updated in over two years. Keep an eye on it, or look for a maintained alternative.', 'airworthy-pro' ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( $groups['watch'] ) : ?>
			<section class="block">
				<h2><?php esc_html_e( 'Keep an eye on', 'airworthy-pro' ); ?></h2>
				<p class="muted">
					<?php
					/* translators: %s: PHP version. */
					echo esc_html( sprintf( __( 'These work on PHP %s. Keeping them updated keeps them working on later versions.', 'airworthy-pro' ), $target ) );
					?>
				</p>
				<table class="rows">
					<?php foreach ( $groups['watch'] as $c ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $c['name'] ); ?></strong><?php echo ! empty( $c['update'] ) ? ' <span class="update">' . esc_html( sprintf( /* translators: %s: version number. */ __( 'update available: %s', 'airworthy-pro' ), $c['update']['version'] ) ) . '</span>' : ''; ?></td>
							<td><span class="badge badge-<?php echo esc_attr( $c['verdict'] ); ?>"><?php echo esc_html( $labels[ $c['verdict'] ] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</section>
		<?php endif; ?>

		<?php if ( $groups['good'] ) : ?>
			<section class="block">
				<h2><?php esc_html_e( 'All good', 'airworthy-pro' ); ?></h2>
				<p class="muted">
					<?php
					/* translators: %s: PHP version. */
					echo esc_html( sprintf( __( 'Nothing to do for these on PHP %s.', 'airworthy-pro' ), $target ) );
					?>
				</p>
				<p class="names"><?php echo esc_html( implode( ' · ', wp_list_pluck( $groups['good'], 'name' ) ) ); ?></p>
			</section>
		<?php endif; ?>

		<section class="block about">
			<h2><?php esc_html_e( 'About this report', 'airworthy-pro' ); ?></h2>
			<p><?php esc_html_e( 'Every PHP file in the site\'s plugins and theme was read, without running any of it, and checked against what changes in PHP between the version the site runs now and the version checked against. Old code that can never run on the new version is recognised as safe.', 'airworthy-pro' ); ?></p>
			<p><?php esc_html_e( 'A check that reads code can\'t catch everything: some problems only show up when code runs, with the site\'s own data and settings, or come from the server\'s configuration. Use this report to plan the upgrade, not as a guarantee. Always back up and test on a staging copy before changing the PHP version of a live site.', 'airworthy-pro' ); ?></p>
			<p class="credit"><?php esc_html_e( 'Made with Airworthy · airworthywp.com', 'airworthy-pro' ); ?></p>
		</section>

		</div>

		<footer class="foot">
			<span><?php echo esc_html( $branding['name'] ); ?></span>
		</footer>
	</main>
</body>
</html>
		<?php
		exit;
	}
}
