<?php
/**
 * A single, dismissible mention of Airworthy Pro on the results screen.
 *
 * @package Airworthy
 */

namespace Airworthy\Admin;

use Airworthy\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * After a finished scan that found something to fix, Airworthy's own results screen offers one
 * card that explains what Airworthy Pro adds. It never blocks anything, never shows when Pro is
 * installed, and each user decides for themselves: "Not now" hides it for 60 days and "Don't
 * show again" ends it. The check and its results stay free; nothing in the free plugin is held
 * back for Pro.
 *
 * Sites and hosts can hide it for everyone with the airworthy_show_pro_card filter.
 */
final class ProCard {

	/** User meta: "never", or the Unix time to show it again after. */
	const META = 'airworthy_pro_card';

	/** How long Not now waits. */
	const SNOOZE = 60 * DAY_IN_SECONDS;

	/** Where the button goes. The utm values say which placement it came from. */
	const URL = 'https://airworthywp.com/pro/?utm_source=plugin&utm_medium=results&utm_campaign=pro-card';

	/** Answers the results screen can send. */
	const CHOICES = array( 'later', 'never' );

	/**
	 * Whether Airworthy Pro is installed and active.
	 *
	 * @return bool
	 */
	public static function pro_active() {
		return defined( 'AIRWORTHY_PRO_VERSION' );
	}

	/**
	 * Whether to show the card to the current user now.
	 *
	 * @return bool
	 */
	public static function should_show() {
		$user = get_current_user_id();
		if ( ! $user || ! Capabilities::current_user_can_scan() || self::pro_active() ) {
			return false;
		}
		/**
		 * Filters whether the Airworthy Pro card may appear on the results screen.
		 *
		 * @param bool $show True by default; return false to hide it for everyone.
		 */
		if ( ! apply_filters( 'airworthy_show_pro_card', true ) ) {
			return false;
		}
		$answer = get_user_meta( $user, self::META, true );
		if ( '' === $answer ) {
			return true;
		}
		return is_numeric( $answer ) && time() >= (int) $answer;
	}

	/**
	 * What the results screen needs to show the card, or null when it shouldn't.
	 *
	 * @return array{url:string}|null
	 */
	public static function config() {
		return self::should_show() ? array( 'url' => self::URL ) : null;
	}

	/**
	 * Saves the current user's answer.
	 *
	 * @param string $choice One of self::CHOICES.
	 * @return bool Whether it was a valid answer.
	 */
	public static function answer( $choice ) {
		$user = get_current_user_id();
		if ( ! $user || ! in_array( $choice, self::CHOICES, true ) ) {
			return false;
		}
		update_user_meta( $user, self::META, 'later' === $choice ? (string) ( time() + self::SNOOZE ) : $choice );
		return true;
	}

	/**
	 * POST /pro-card: saves the answer from the results screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_answer( \WP_REST_Request $request ) {
		if ( ! self::answer( (string) $request['choice'] ) ) {
			return new \WP_Error( 'airworthy_bad_choice', __( 'Unknown answer.', 'airworthy' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * Removes every user's answer (on uninstall).
	 */
	public static function forget_all() {
		delete_metadata( 'user', 0, self::META, '', true );
	}
}
