<?php
/**
 * The page checks the after-the-switch watch takes before and after the PHP switch.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the site for a handful of its own pages (home, the newest post, the login page, the REST
 * API, and WooCommerce's cart and checkout when they exist) and notes how each answered. The
 * requests go from the site to itself; nothing leaves it. A visitor sees the same thing.
 */
final class Pages {

	/** Seconds to wait for one page. */
	const TIMEOUT = 8;

	/** How bad each state is, to tell better from worse. */
	const RANK = array(
		'ok'          => 0,
		'missing'     => 1,
		'shows_error' => 2,
		'error'       => 3,
	);

	/**
	 * The pages to check.
	 *
	 * @return array[] Each: key, label, url.
	 */
	public static function targets() {
		$out = array(
			array(
				'key'   => 'home',
				'label' => __( 'Home page', 'airworthy-pro' ),
				'url'   => home_url( '/' ),
			),
		);

		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		if ( $posts ) {
			$out[] = array(
				'key'   => 'post',
				'label' => __( 'Latest post', 'airworthy-pro' ),
				'url'   => (string) get_permalink( $posts[0] ),
			);
		}

		$out[] = array(
			'key'   => 'login',
			'label' => __( 'Login page', 'airworthy-pro' ),
			'url'   => wp_login_url(),
		);
		$out[] = array(
			'key'   => 'rest',
			'label' => __( 'REST API', 'airworthy-pro' ),
			'url'   => rest_url(),
		);

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array(
				'cart'     => __( 'Cart', 'airworthy-pro' ),
				'checkout' => __( 'Checkout', 'airworthy-pro' ),
			) as $page => $label ) {
				$url = (string) wc_get_page_permalink( $page );
				if ( '' !== $url ) {
					$out[] = array(
						'key'   => $page,
						'label' => $label,
						'url'   => $url,
					);
				}
			}
		}

		/**
		 * Filters the pages the watch checks.
		 *
		 * @param array[] $out Each: key, label, url.
		 */
		return array_values( (array) apply_filters( 'airworthy_pro_watch_pages', $out ) );
	}

	/**
	 * Checks every page now.
	 *
	 * @return array{at:int,php:string,pages:array[]}
	 */
	public static function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort: a host may forbid it.
		}
		$pages = array();
		foreach ( self::targets() as $t ) {
			$pages[] = array_merge(
				array(
					'key'   => $t['key'],
					'label' => $t['label'],
					'path'  => self::path_of( $t['url'] ),
				),
				self::fetch( $t['url'] )
			);
		}
		return array(
			'at'    => time(),
			'php'   => \Airworthy\Targets::minor( \Airworthy\Env::php_version() ),
			'pages' => $pages,
		);
	}

	/**
	 * One page's answer.
	 *
	 * @param string $url Address.
	 * @return array{status:int,ms:int,state:string,note:string}
	 */
	public static function fetch( $url ) {
		// A throwaway query so a page cache doesn't answer for the site's own code.
		$url   = add_query_arg( 'aw_watch', wp_generate_password( 8, false ), $url );
		$start = microtime( true );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own filter, as Site Health uses it.
				'user-agent'  => 'Airworthy Pro watch (' . home_url() . ')',
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array(
				'status' => 0,
				'ms'     => $ms,
				'state'  => 'blocked',
				'note'   => wp_strip_all_tags( $res->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		return array(
			'status' => $code,
			'ms'     => $ms,
			'state'  => self::classify( $code, $body ),
			'note'   => '',
		);
	}

	/**
	 * What an answer means.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return string ok | missing | shows_error | error
	 */
	public static function classify( $code, $body ) {
		if ( $code >= 500 ) {
			return 'error';
		}
		if ( 404 === $code || 410 === $code ) {
			return 'missing';
		}
		// A page that answers 200 but prints a PHP error, or WordPress's own critical-error notice.
		if ( preg_match( '/There has been a critical error|<b>(?:Fatal error|Parse error|Warning|Deprecated|Notice)<\/b>:|(?:Fatal error|Parse error): /i', substr( $body, 0, 400000 ) ) ) {
			return 'shows_error';
		}
		return 'ok';
	}

	/**
	 * The path of an address, without the query (which may hold tokens).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function path_of( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Before and after, page by page.
	 *
	 * @param array|null $before A run() result, or null.
	 * @param array|null $after  A run() result, or null.
	 * @return array[] Each: key, label, path, before (answer|null), after (answer|null), change.
	 *                 change: same | worse | better | slower | unknown.
	 */
	public static function compare( $before, $after ) {
		$rows = array();
		$map  = static function ( $run ) {
			$m = array();
			if ( is_array( $run ) && ! empty( $run['pages'] ) ) {
				foreach ( $run['pages'] as $p ) {
					$m[ $p['key'] ] = $p;
				}
			}
			return $m;
		};
		$b    = $map( $before );
		$a    = $map( $after );
		foreach ( array_unique( array_merge( array_keys( $b ), array_keys( $a ) ) ) as $key ) {
			$was    = isset( $b[ $key ] ) ? $b[ $key ] : null;
			$now    = isset( $a[ $key ] ) ? $a[ $key ] : null;
			$source = $now ? $now : $was;
			$rows[] = array(
				'key'    => $key,
				'label'  => $source['label'],
				'path'   => $source['path'],
				'before' => $was,
				'after'  => $now,
				'change' => self::change( $was, $now ),
			);
		}
		return $rows;
	}

	/**
	 * How a page changed.
	 *
	 * @param array|null $was Earlier answer.
	 * @param array|null $now Later answer.
	 * @return string same | worse | better | slower | unknown
	 */
	public static function change( $was, $now ) {
		if ( ! $now || 'blocked' === $now['state'] || ( $was && 'blocked' === $was['state'] ) ) {
			return 'unknown';
		}
		if ( ! $was ) {
			return 'ok' === $now['state'] ? 'same' : 'worse';
		}
		$rank_was = self::RANK[ $was['state'] ];
		$rank_now = self::RANK[ $now['state'] ];
		if ( $rank_now > $rank_was ) {
			return 'worse';
		}
		if ( $rank_now < $rank_was ) {
			return 'better';
		}
		if ( 'ok' === $now['state'] && $now['ms'] > 2 * $was['ms'] && $now['ms'] - $was['ms'] > 700 ) {
			return 'slower';
		}
		return 'same';
	}
}
