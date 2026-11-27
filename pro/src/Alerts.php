<?php
/**
 * The emails monitoring sends.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

defined( 'ABSPATH' ) || exit;

/**
 * Short, plain emails with a symbol per kind of news. Each is sent with wp_mail() as HTML with a
 * plain-text alternative. Nothing is sent anywhere else.
 */
final class Alerts {

	/** Symbol and name for each verdict. */
	const VERDICTS = array(
		'blocker'    => array( '🔴', 'Blocker' ),
		'unknown'    => array( '⚪', 'Unknown' ),
		'suppressed' => array( '🟡', 'Suppressed by author' ),
		'guarded'    => array( '🔵', 'Guarded legacy code' ),
		'warnings'   => array( '🟡', 'Warnings' ),
		'ready'      => array( '✅', 'Ready' ),
	);

	/**
	 * Who gets the emails: the addresses in the settings, or the site's admin email.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$s    = Monitor::get();
		$list = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $s['recipients'] ) as $address ) {
			if ( is_email( $address ) ) {
				$list[] = $address;
			}
		}
		if ( ! $list ) {
			$admin = (string) get_site_option( 'admin_email' );
			if ( is_email( $admin ) ) {
				$list[] = $admin;
			}
		}
		return $list;
	}

	/**
	 * Something new would break (or got worse).
	 *
	 * @param array  $record  From History::record(): snapshot, diff, previous.
	 * @param string $trigger scheduled | update.
	 * @return bool
	 */
	public static function send_changes( array $record, $trigger ) {
		$snap = $record['snapshot'];
		$diff = $record['diff'];
		$n    = count( $diff['blockers'] );
		$site = self::site();

		if ( $n ) {
			/* translators: 1: number, 2: PHP version, 3: site name. */
			$subject = sprintf( _n( 'Airworthy: %1$d new Blocker for PHP %2$s on %3$s', 'Airworthy: %1$d new Blockers for PHP %2$s on %3$s', $n, 'airworthy-pro' ), $n, $snap['target'], $site );
		} else {
			/* translators: 1: PHP version, 2: site name. */
			$subject = sprintf( __( 'Airworthy: new warnings for PHP %1$s on %2$s', 'airworthy-pro' ), $snap['target'], $site );
		}

		$why = 'update' === $trigger ?
			__( 'A plugin or theme was just updated, and Airworthy checked the site again.', 'airworthy-pro' ) :
			__( 'This is your scheduled check.', 'airworthy-pro' );

		$html = '<p style="margin:0 0 14px">' . esc_html( $why ) . '</p>' . self::counts_line( $snap );
		$text = $why . "\n" . self::counts_text( $snap ) . "\n";

		$blocks = array(
			array( 'blockers', '🔴', __( 'New Blockers', 'airworthy-pro' ), __( 'These would break on the PHP version you are heading for. Update them, or replace them, before you upgrade.', 'airworthy-pro' ) ),
			array( 'worse', '🟡', __( 'Got worse', 'airworthy-pro' ), '' ),
			array( 'fixed', '✅', __( 'Fixed since the last check', 'airworthy-pro' ), '' ),
			array( 'better', '✅', __( 'Improved', 'airworthy-pro' ), '' ),
		);
		foreach ( $blocks as $b ) {
			list( $key, $symbol, $title, $hint ) = $b;
			if ( empty( $diff[ $key ] ) ) {
				continue;
			}
			$html .= self::section( $symbol . ' ' . $title, $hint, $diff[ $key ] );
			$text .= "\n" . $symbol . ' ' . $title . "\n" . self::section_text( $diff[ $key ] );
		}

		$html .= self::button( self::results_url(), __( 'Open the results', 'airworthy-pro' ) );
		$text .= "\n" . __( 'Open the results:', 'airworthy-pro' ) . ' ' . self::results_url() . "\n";

		return self::deliver( $subject, __( 'Something changed on your site', 'airworthy-pro' ), $html, $text );
	}

	/**
	 * A scheduled scan couldn't finish.
	 *
	 * @return bool
	 */
	public static function send_failed() {
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Airworthy: the scheduled check could not finish on %s', 'airworthy-pro' ), self::site() );
		$body    = __( 'The scan stopped before it finished, so nothing new was checked this time. Airworthy will try again at the next scheduled check. You can also start a scan by hand.', 'airworthy-pro' );
		$html    = '<p style="margin:0 0 14px">' . esc_html( $body ) . '</p>' . self::button( \Airworthy\Admin\Page::url(), __( 'Open Airworthy', 'airworthy-pro' ) );
		return self::deliver( $subject, __( 'A check could not finish', 'airworthy-pro' ), $html, $body . "\n\n" . \Airworthy\Admin\Page::url() . "\n" );
	}

	/**
	 * The server's PHP version is close to the end of security fixes.
	 *
	 * @param string $version Full PHP version.
	 * @param int    $days    Days left (negative when over).
	 * @param string $until   Y-m-d of the last security fix.
	 * @return bool
	 */
	public static function send_support_ending( $version, $days, $until ) {
		$minor = \Airworthy\Targets::minor( $version );
		$date  = date_i18n( get_option( 'date_format' ), strtotime( $until . ' 12:00:00 UTC' ) );
		if ( $days < 0 ) {
			/* translators: 1: PHP version, 2: site name. */
			$subject = sprintf( __( 'Airworthy: PHP %1$s no longer gets security fixes (%2$s)', 'airworthy-pro' ), $minor, self::site() );
			/* translators: 1: PHP version, 2: date. */
			$line = sprintf( __( 'PHP %1$s stopped getting security fixes on %2$s. Your site runs it today.', 'airworthy-pro' ), $minor, $date );
		} else {
			/* translators: 1: PHP version, 2: number of days, 3: site name. */
			$subject = sprintf( _n( 'Airworthy: PHP %1$s gets security fixes for %2$d more day (%3$s)', 'Airworthy: PHP %1$s gets security fixes for %2$d more days (%3$s)', max( 1, $days ), 'airworthy-pro' ), $minor, max( 1, $days ), self::site() );
			/* translators: 1: PHP version, 2: date. */
			$line = sprintf( __( 'PHP %1$s stops getting security fixes on %2$s.', 'airworthy-pro' ), $minor, $date );
		}
		$next = __( 'Plan the move now: run a check against a newer PHP version, fix what it finds, then ask your host to switch. Airworthy\'s upgrade plan lists the steps.', 'airworthy-pro' );
		$html = '<p style="margin:0 0 10px">⏳ <strong>' . esc_html( $line ) . '</strong></p><p style="margin:0 0 14px">' . esc_html( $next ) . '</p>' . self::button( \Airworthy\Admin\Page::url(), __( 'Open Airworthy', 'airworthy-pro' ) );
		return self::deliver( $subject, __( 'PHP is nearing the end of support', 'airworthy-pro' ), $html, '⏳ ' . $line . "\n\n" . $next . "\n\n" . \Airworthy\Admin\Page::url() . "\n" );
	}

	/**
	 * The server's PHP is missing the latest security release.
	 *
	 * @param string $version Full PHP version.
	 * @param array  $status  From Targets::missing_patch().
	 * @return bool
	 */
	public static function send_patch_missing( $version, array $status ) {
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Airworthy: the PHP on %s is missing the latest security fixes', 'airworthy-pro' ), self::site() );
		$line    = isset( $status['text'] ) ? wp_strip_all_tags( (string) $status['text'] ) : '';
		$next    = __( 'Ask your host to apply the latest PHP security update. A minor update within the same PHP version is routine and does not change how your plugins work.', 'airworthy-pro' );
		$html    = '<p style="margin:0 0 10px">🛡️ <strong>' . esc_html( $line ) . '</strong></p><p style="margin:0 0 14px">' . esc_html( $next ) . '</p>' . self::button( \Airworthy\Admin\Page::url(), __( 'Open Airworthy', 'airworthy-pro' ) );
		return self::deliver( $subject, __( 'A PHP security update is waiting', 'airworthy-pro' ), $html, '🛡️ ' . $line . "\n\n" . $next . "\n\n" . \Airworthy\Admin\Page::url() . "\n" );
	}

	/**
	 * A fatal error showed up after the PHP switch.
	 *
	 * @param array $report From Watch::report().
	 * @return bool
	 */
	public static function send_watch_fatal( array $report ) {
		$php = $report['state']['php_to'];
		/* translators: 1: PHP version, 2: site name. */
		$subject = sprintf( __( 'Airworthy: a fatal error since the switch to PHP %1$s on %2$s', 'airworthy-pro' ), $php, self::site() );
		$rows    = array_filter(
			$report['new'],
			static function ( $r ) {
				return in_array( $r['level'], array( 'fatal', 'error' ), true );
			}
		);
		$intro   = __( 'Airworthy is watching your site after the PHP switch and saw an error that stops code from running. It did not happen on the old PHP version.', 'airworthy-pro' );
		$html    = '<p style="margin:0 0 10px">🔴 <strong>' . esc_html( $intro ) . '</strong></p>' . self::watch_list( $rows, 5 ) . self::button( WatchTab::url(), __( 'See the watch', 'airworthy-pro' ) );
		$text    = '🔴 ' . $intro . "\n\n" . self::watch_list_text( $rows, 5 ) . "\n" . WatchTab::url() . "\n";
		return self::deliver( $subject, __( 'A fatal error after the switch', 'airworthy-pro' ), $html, $text, false, WatchTab::url(), __( 'you started an after-the-switch watch', 'airworthy-pro' ) );
	}

	/**
	 * The watch is over: what it found.
	 *
	 * @param array $report From Watch::report().
	 * @return bool
	 */
	public static function send_watch_done( array $report ) {
		$php    = $report['state']['php_to'];
		$symbol = array(
			'ok'   => '✅',
			'warn' => '🟡',
			'bad'  => '🔴',
		);
		$sym    = isset( $symbol[ $report['level'] ] ) ? $symbol[ $report['level'] ] : '✅';
		/* translators: 1: PHP version, 2: site name, 3: the result in a few words. */
		$subject = sprintf( __( 'Airworthy: the PHP %1$s watch is over on %2$s: %3$s', 'airworthy-pro' ), $php, self::site(), strtolower( $report['headline'] ) );

		$parts = array();
		$text  = $sym . ' ' . $report['headline'] . "\n\n";
		$html  = '<p style="margin:0 0 10px;font-size:16px">' . $sym . ' <strong>' . esc_html( $report['headline'] ) . '</strong></p>';
		/* translators: 1: PHP version, 2: hours. */
		$line  = sprintf( __( 'Airworthy watched this site for %2$d hours after the switch to PHP %1$s.', 'airworthy-pro' ), $php, (int) $report['state']['window'] );
		$html .= '<p style="margin:0 0 12px;color:#4f5d6e">' . esc_html( $line ) . '</p>';
		$text .= $line . "\n\n";

		if ( $report['new'] ) {
			$html .= '<h2 style="margin:18px 0 6px;font-size:16px">' . esc_html__( 'New on this PHP version', 'airworthy-pro' ) . '</h2>' . self::watch_list( $report['new'], 8 );
			$text .= __( 'New on this PHP version', 'airworthy-pro' ) . ":\n" . self::watch_list_text( $report['new'], 8 ) . "\n";
		} else {
			$none  = __( 'No new errors or warnings were recorded.', 'airworthy-pro' );
			$html .= '<p style="margin:0 0 8px">' . esc_html( $none ) . '</p>';
			$text .= $none . "\n\n";
		}
		foreach ( $report['pages'] as $p ) {
			if ( in_array( $p['change'], array( 'worse', 'slower' ), true ) ) {
				$parts[] = self::page_line( $p );
			}
		}
		if ( $parts ) {
			$html .= '<h2 style="margin:18px 0 6px;font-size:16px">' . esc_html__( 'Pages', 'airworthy-pro' ) . '</h2><ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">';
			foreach ( $parts as $part ) {
				$html .= '<li>' . esc_html( $part ) . '</li>';
			}
			$html .= '</ul>';
			$text .= __( 'Pages', 'airworthy-pro' ) . ":\n- " . implode( "\n- ", $parts ) . "\n\n";
		}
		$html .= self::button( WatchTab::url(), __( 'See the full report', 'airworthy-pro' ) );
		$text .= WatchTab::url() . "\n";
		return self::deliver( $subject, __( 'Your after-the-switch watch is over', 'airworthy-pro' ), $html, $text, false, WatchTab::url(), __( 'you started an after-the-switch watch', 'airworthy-pro' ) );
	}

	/**
	 * A page that changed, in a sentence.
	 *
	 * @param array $p Row from Pages::compare().
	 * @return string
	 */
	public static function page_line( array $p ) {
		if ( 'slower' === $p['change'] ) {
			/* translators: 1: page name, 2: milliseconds before, 3: milliseconds after. */
			return sprintf( __( '%1$s is slower: %2$d ms before, %3$d ms now', 'airworthy-pro' ), $p['label'], (int) $p['before']['ms'], (int) $p['after']['ms'] );
		}
		$states = array(
			'ok'          => __( 'answers normally', 'airworthy-pro' ),
			'missing'     => __( 'says the page was not found', 'airworthy-pro' ),
			'shows_error' => __( 'shows an error on the page', 'airworthy-pro' ),
			'error'       => __( 'fails with a server error', 'airworthy-pro' ),
		);
		$now    = $p['after'] && isset( $states[ $p['after']['state'] ] ) ? $states[ $p['after']['state'] ] : '';
		/* translators: 1: page name, 2: what it does now. */
		return sprintf( __( '%1$s now %2$s', 'airworthy-pro' ), $p['label'], $now );
	}

	/**
	 * A list of recorded errors as HTML.
	 *
	 * @param array $rows  Rows from Watch::report().
	 * @param int   $limit How many to show.
	 * @return string
	 */
	private static function watch_list( array $rows, $limit ) {
		$html = '<ul style="margin:0 0 8px;padding-left:20px;font-size:14px;line-height:1.6">';
		foreach ( array_slice( array_values( $rows ), 0, $limit ) as $r ) {
			$html .= '<li>' . esc_html( self::watch_row_text( $r ) ) . '</li>';
		}
		if ( count( $rows ) > $limit ) {
			/* translators: %d: number of further items. */
			$html .= '<li>' . esc_html( sprintf( __( 'and %d more', 'airworthy-pro' ), count( $rows ) - $limit ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * A list of recorded errors as text.
	 *
	 * @param array $rows  Rows.
	 * @param int   $limit How many to show.
	 * @return string
	 */
	private static function watch_list_text( array $rows, $limit ) {
		$out = '';
		foreach ( array_slice( array_values( $rows ), 0, $limit ) as $r ) {
			$out .= '- ' . self::watch_row_text( $r ) . "\n";
		}
		return $out;
	}

	/**
	 * One recorded error in a sentence.
	 *
	 * @param array $r Row.
	 * @return string
	 */
	public static function watch_row_text( array $r ) {
		/* translators: 1: plugin or theme, 2: message, 3: file and line, 4: how many times. */
		return sprintf( _n( '%1$s: %2$s (%3$s, %4$d time)', '%1$s: %2$s (%3$s, %4$d times)', max( 1, (int) $r['to'] ), 'airworthy-pro' ), $r['name'], $r['msg'], $r['file'] . ':' . $r['line'], (int) $r['to'] );
	}

	/**
	 * A test message, so the owner can see the emails arrive.
	 *
	 * @return bool
	 */
	public static function send_test() {
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Airworthy: test email from %s', 'airworthy-pro' ), self::site() );
		$body    = __( 'This is a test. If you can read it, Airworthy\'s monitoring emails will reach this address.', 'airworthy-pro' );
		$html    = '<p style="margin:0 0 14px">✅ ' . esc_html( $body ) . '</p>' . self::button( self::monitor_url(), __( 'Monitoring settings', 'airworthy-pro' ) );
		return self::deliver( $subject, __( 'Test email', 'airworthy-pro' ), $html, '✅ ' . $body . "\n\n" . self::monitor_url() . "\n", true );
	}

	/**
	 * Sends one message to every recipient.
	 *
	 * @param string $subject Subject.
	 * @param string $title   Heading inside the email.
	 * @param string $html    Body HTML.
	 * @param string $text       Plain-text version.
	 * @param bool   $force      Send even when alerts are paused (the test email).
	 * @param string $footer_url Where "change or stop it" points; the Monitoring tab by default.
	 * @param string $footer_why Why they get it, as in "monitoring is on".
	 * @return bool Whether WordPress accepted it for every recipient.
	 */
	private static function deliver( $subject, $title, $html, $text, $force = false, $footer_url = '', $footer_why = '' ) {
		$to = self::recipients();
		if ( ! $to || ( ! $force && ! Licence::alerts_allowed() ) ) {
			return false; // Nobody to tell, or the licence has ended (alerts stop; the test email still works).
		}
		$footer_url  = '' !== $footer_url ? $footer_url : self::monitor_url();
		$footer_why  = '' !== $footer_why ? $footer_why : __( 'monitoring is on', 'airworthy-pro' );
		$footer_text = sprintf(
			/* translators: 1: why they get it, 2: site address, 3: settings link. */
			__( 'You get this because %1$s for %2$s. Change or stop it here: %3$s', 'airworthy-pro' ),
			$footer_why,
			wp_parse_url( home_url(), PHP_URL_HOST ),
			$footer_url
		);
		$page = self::wrap( $title, $html, $footer_text );
		$alt  = static function ( $mailer ) use ( $text, $footer_text ) {
			$mailer->AltBody = $text . "\n--\n" . $footer_text . "\n"; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer's property.
		};
		add_action( 'phpmailer_init', $alt );
		$ok = true;
		foreach ( $to as $address ) {
			$ok = wp_mail( $address, $subject, $page, array( 'Content-Type: text/html; charset=UTF-8' ) ) && $ok;
		}
		remove_action( 'phpmailer_init', $alt );
		return $ok;
	}

	/**
	 * The email's frame.
	 *
	 * @param string $title  Heading.
	 * @param string $body   Body HTML.
	 * @param string $footer Footer text.
	 * @return string
	 */
	private static function wrap( $title, $body, $footer ) {
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f7fb;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f1b2d">'
			. '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #dfe6ef;border-radius:10px;padding:24px">'
			. '<p style="margin:0 0 4px;font-size:13px;color:#4f5d6e">✈️ Airworthy Pro · ' . esc_html( self::site() ) . '</p>'
			. '<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3">' . esc_html( $title ) . '</h1>'
			. $body
			. '<p style="margin:24px 0 0;padding-top:14px;border-top:1px solid #dfe6ef;font-size:12px;color:#4f5d6e">' . esc_html( $footer ) . '</p>'
			. '</div></body></html>';
	}

	/**
	 * The one-line summary of a snapshot's counts.
	 *
	 * @param array $snap Snapshot.
	 * @return string HTML.
	 */
	private static function counts_line( array $snap ) {
		return '<p style="margin:0 0 14px;padding:10px 12px;background:#f4f7fb;border-radius:8px;font-size:14px">' . esc_html( self::counts_text( $snap ) ) . '</p>';
	}

	/**
	 * The summary of a snapshot's counts as text.
	 *
	 * @param array $snap Snapshot.
	 * @return string
	 */
	private static function counts_text( array $snap ) {
		$c     = $snap['counts'];
		$parts = array();
		foreach ( array( 'blocker', 'warnings', 'unknown', 'ready' ) as $k ) {
			if ( ! empty( $c[ $k ] ) || 'ready' === $k ) {
				$parts[] = self::VERDICTS[ $k ][0] . ' ' . (int) $c[ $k ] . ' ' . self::VERDICTS[ $k ][1];
			}
		}
		return sprintf(
			/* translators: 1: PHP version, 2: the counts, for example "2 Blockers · 3 Warnings". */
			__( 'Checked against PHP %1$s: %2$s', 'airworthy-pro' ),
			$snap['target'],
			implode( ' · ', $parts )
		);
	}

	/**
	 * A titled list of changed plugins or themes.
	 *
	 * @param string $title Title with its symbol.
	 * @param string $hint  One sentence under the title, or ''.
	 * @param array  $rows  Rows from History::diff().
	 * @return string HTML.
	 */
	private static function section( $title, $hint, array $rows ) {
		$html = '<h2 style="margin:18px 0 6px;font-size:16px">' . esc_html( $title ) . '</h2>';
		if ( '' !== $hint ) {
			$html .= '<p style="margin:0 0 8px;font-size:14px;color:#4f5d6e">' . esc_html( $hint ) . '</p>';
		}
		$html .= '<ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6">';
		foreach ( array_slice( $rows, 0, 15 ) as $r ) {
			$html .= '<li>' . esc_html( self::row_text( $r ) ) . '</li>';
		}
		if ( count( $rows ) > 15 ) {
			/* translators: %d: number of further plugins or themes. */
			$html .= '<li>' . esc_html( sprintf( __( 'and %d more', 'airworthy-pro' ), count( $rows ) - 15 ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * The text version of a list.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function section_text( array $rows ) {
		$out = '';
		foreach ( array_slice( $rows, 0, 15 ) as $r ) {
			$out .= '- ' . self::row_text( $r ) . "\n";
		}
		return $out;
	}

	/**
	 * One changed plugin or theme in a sentence.
	 *
	 * @param array $r Row from History::diff().
	 * @return string
	 */
	public static function row_text( array $r ) {
		$name = '' !== $r['name'] ? $r['name'] : __( '(unnamed)', 'airworthy-pro' );
		$to   = isset( self::VERDICTS[ $r['to'] ] ) ? self::VERDICTS[ $r['to'] ][1] : '';
		$from = isset( self::VERDICTS[ $r['from'] ] ) ? self::VERDICTS[ $r['from'] ][1] : '';
		$ver  = '' !== $r['version'] ? ' ' . $r['version'] : '';
		if ( '' === $r['from'] ) {
			/* translators: 1: plugin name and version, 2: verdict. */
			return sprintf( __( '%1$s is new: %2$s', 'airworthy-pro' ), $name . $ver, $to );
		}
		if ( $r['old_version'] && $r['old_version'] !== $r['version'] ) {
			/* translators: 1: plugin name, 2: old version, 3: new version, 4: old verdict, 5: new verdict. */
			return sprintf( __( '%1$s %2$s to %3$s: %4$s to %5$s', 'airworthy-pro' ), $name, $r['old_version'], $r['version'], $from, $to );
		}
		/* translators: 1: plugin name and version, 2: old verdict, 3: new verdict. */
		return sprintf( __( '%1$s: %2$s to %3$s', 'airworthy-pro' ), $name . $ver, $from, $to );
	}

	/**
	 * A button-looking link.
	 *
	 * @param string $url   Address.
	 * @param string $label Text.
	 * @return string HTML.
	 */
	private static function button( $url, $label ) {
		return '<p style="margin:22px 0 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 18px;background:#1f5fd0;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * The site's name for subject lines.
	 *
	 * @return string
	 */
	private static function site() {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Where the results are.
	 *
	 * @return string
	 */
	private static function results_url() {
		return \Airworthy\Admin\Page::url();
	}

	/**
	 * The Monitoring tab.
	 *
	 * @return string
	 */
	private static function monitor_url() {
		return add_query_arg( 'tab', MonitorTab::TAB, \Airworthy\Admin\Page::url() );
	}
}
