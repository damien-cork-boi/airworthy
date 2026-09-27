<?php
/**
 * Supported target PHP versions.
 *
 * @package Airworthy
 */

namespace Airworthy;

defined( 'ABSPATH' ) || exit;

/**
 * The PHP versions a scan can check against. Scans always use one exact version, never a range.
 */
final class Targets {

	/** Oldest first. */
	const ALL = array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );

	/**
	 * Whether a target is supported.
	 *
	 * @param string $target e.g. "8.4".
	 * @return bool
	 */
	public static function is_valid( $target ) {
		return in_array( $target, self::ALL, true );
	}

	/**
	 * The default target: the next minor version above the server's, within the supported range.
	 *
	 * @param string $current PHP version string, e.g. "8.1.27".
	 * @return string
	 */
	public static function default_for( $current ) {
		$current_minor = implode( '.', array_slice( explode( '.', $current ), 0, 2 ) );
		foreach ( self::ALL as $target ) {
			if ( version_compare( $target, $current_minor, '>' ) ) {
				return $target;
			}
		}
		return self::ALL[ count( self::ALL ) - 1 ];
	}
}
