<?php
/**
 * The Pro add-on's licence, through Freemius.
 *
 * @package AirworthyPro
 */

namespace AirworthyPro;

defined( 'ABSPATH' ) || exit;

/**
 * One place that knows whether Pro is licensed and how to describe the licence.
 *
 * Expiry rule: when a licence expires, Pro keeps working; updates, support and (once it
 * exists) monitoring alerts stop. Set on every Freemius plan as "Keep features, only block
 * updates and support" (the "Block features after license expiration" switch is OFF). Every
 * message here says so, so nobody is surprised.
 */
final class Licence {

	const STATE_NONE     = 'none';     // Never activated on this site.
	const STATE_ACTIVE   = 'active';   // Valid, more than 30 days to go (or lifetime).
	const STATE_EXPIRING = 'expiring'; // Valid, 30 days or less to go.
	const STATE_EXPIRED  = 'expired';  // Ended; Pro still works.

	/**
	 * Whether Freemius is set up in this build: the Product ID and public key are filled in.
	 * Until they are, Pro runs ungated, so development and pre-launch builds are unaffected.
	 *
	 * @return bool
	 */
	public static function configured() {
		return defined( 'AIRWORTHY_PRO_FS_ID' ) && '' !== AIRWORTHY_PRO_FS_ID
			&& defined( 'AIRWORTHY_PRO_FS_KEY' ) && '' !== AIRWORTHY_PRO_FS_KEY;
	}

	/**
	 * Whether Pro's features may run: an active licence, or an expired one (features are kept
	 * after expiry). Tests switch it with the airworthy_pro_licensed filter.
	 *
	 * @return bool
	 */
	public static function can_use() {
		if ( ! self::configured() ) {
			$allowed = true;
		} else {
			$fs      = self::fs();
			$allowed = $fs ? (bool) $fs->can_use_premium_code() : false;
		}
		return (bool) apply_filters( 'airworthy_pro_licensed', $allowed );
	}

	/**
	 * Whether Pro may send alert emails and run scheduled checks on its own: yes until the
	 * licence has ended. Everything else keeps working after expiry. Tests switch it with the
	 * airworthy_pro_alerts_allowed filter.
	 *
	 * @return bool
	 */
	public static function alerts_allowed() {
		static $expired = null;
		if ( null === $expired ) {
			$expired = self::configured() && self::STATE_EXPIRED === self::summary()['state'];
		}
		return (bool) apply_filters( 'airworthy_pro_alerts_allowed', ! $expired );
	}

	/**
	 * What to show about the licence.
	 *
	 * @return array{state:string,plan:string,expires:int,sites_used:int,sites_quota:int,is_local:bool,account_url:string,activate_url:string,message:string}
	 */
	public static function summary() {
		$fs      = self::fs();
		$license = ( $fs && $fs->is_registered() ) ? $fs->_get_license() : false;

		$out = array(
			'state'        => self::STATE_NONE,
			'plan'         => '',
			'expires'      => 0,
			'sites_used'   => 0,
			'sites_quota'  => 0,
			'is_local'     => false,
			'account_url'  => $fs ? (string) $fs->get_account_url() : '',
			'activate_url' => $fs ? (string) $fs->get_activation_url() : '',
			'message'      => __( 'Activate your licence to use Airworthy Pro. Your licence key is in the email you got when you bought it.', 'airworthy-pro' ),
		);
		if ( ! $license ) {
			return $out;
		}

		$out['plan']        = (string) $fs->get_plan_title();
		$out['sites_used']  = (int) $license->activated;
		$out['sites_quota'] = (int) $license->quota; // 0 = unlimited.
		$site               = $fs->get_site();
		$out['is_local']    = $site && $site->is_localhost(); // Local and staging addresses aren't counted.
		$out['expires']     = empty( $license->expiration ) ? 0 : (int) strtotime( $license->expiration . ' UTC' );

		return array_merge( $out, self::describe( $out['expires'], time() ) );
	}

	/**
	 * State and message for an expiry time (0 = never expires). Notes start 30 days before the
	 * end, get firmer in the last 7 days, and carry on after it.
	 *
	 * @param int $expires Expiry, Unix time; 0 for a lifetime licence.
	 * @param int $now     Now, Unix time.
	 * @return array{state:string,message:string}
	 */
	public static function describe( $expires, $now ) {
		if ( ! $expires ) {
			return array(
				'state'   => self::STATE_ACTIVE,
				'message' => __( 'Lifetime licence: updates and support included.', 'airworthy-pro' ),
			);
		}
		$date = wp_date( (string) get_option( 'date_format', 'j F Y' ), $expires );
		if ( $expires <= $now ) {
			return array(
				'state'   => self::STATE_EXPIRED,
				/* translators: %s: date. */
				'message' => sprintf( __( 'Your licence ended on %s. Airworthy Pro still works, but updates, support and monitoring alerts are off. Renew to switch them back on.', 'airworthy-pro' ), $date ),
			);
		}
		if ( $expires - $now <= 7 * DAY_IN_SECONDS ) {
			return array(
				'state'   => self::STATE_EXPIRING,
				/* translators: %s: date. */
				'message' => sprintf( __( 'Your licence ends on %s, within a week. Renew to keep updates, support and monitoring alerts. If you don\'t, Airworthy Pro keeps working.', 'airworthy-pro' ), $date ),
			);
		}
		if ( $expires - $now <= 30 * DAY_IN_SECONDS ) {
			return array(
				'state'   => self::STATE_EXPIRING,
				/* translators: %s: date. */
				'message' => sprintf( __( 'Your licence ends on %s. Renew to keep updates, support and monitoring alerts. If you don\'t, Airworthy Pro keeps working.', 'airworthy-pro' ), $date ),
			);
		}
		return array(
			'state'   => self::STATE_ACTIVE,
			/* translators: %s: date. */
			'message' => sprintf( __( 'Renews on %s. If you don\'t renew, Airworthy Pro keeps working; updates, support and monitoring alerts stop.', 'airworthy-pro' ), $date ),
		);
	}

	/**
	 * The Freemius instance, if it is set up and the SDK loaded.
	 *
	 * @return \Freemius|null
	 */
	private static function fs() {
		return ( self::configured() && function_exists( 'airworthy_pro_fs' ) ) ? airworthy_pro_fs() : null;
	}
}
