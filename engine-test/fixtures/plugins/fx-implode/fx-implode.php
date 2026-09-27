<?php
/**
 * Plugin Name: FX Implode
 * Description: The reversed implode() arguments found in Essential Addons: a fatal TypeError on PHP 8.
 * Expect: Blocker for every target 8.0-8.5.
 */
function fx_implode_cell( $values ) {
	return '<td>' . implode( (array) $values, ', ' ) . '</td>';
}
