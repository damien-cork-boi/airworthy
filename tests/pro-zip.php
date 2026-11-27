<?php
/**
 * Airworthy Pro "Check a zip" tab checks.
 *
 * Needs the free Airworthy (with the update check) and Airworthy Pro active.
 * Run: wp eval-file tests/pro-zip.php. It adds a finished scan of its own and removes it again.
 *
 * @package AirworthyPro
 */

// phpcs:disable

use AirworthyPro\ZipTab;

if ( ! class_exists( 'AirworthyPro\\ZipTab' ) || ! class_exists( 'Airworthy\\Scan\\UpdateCheck' ) || ! class_exists( 'ZipArchive' ) ) {
	WP_CLI::error( 'Needs Airworthy, Airworthy Pro (with the zip tab) and ZipArchive.' );
}
$failed = 0;
$check  = static function ( $label, $ok, $detail = '' ) use ( &$failed ) {
	if ( ! $ok ) {
		++$failed;
	}
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " ($detail)" : '' );
};

global $wpdb;
$t   = \Airworthy\Installer::tables();
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( $t['scans'], array( 'target_php' => '8.4', 'from_php' => '', 'status' => 'complete', 'created_at' => $now, 'finished_at' => $now ) );
$scan = (int) $wpdb->insert_id;
$wpdb->insert( $t['components'], array( 'scan_id' => $scan, 'type' => 'plugin', 'slug' => 'zip-demo', 'name' => 'Zip Demo', 'version' => '1.0', 'rel_path' => 'zip-demo/zip-demo.php', 'status' => 'done', 'verdict' => 'blocker', 'errors' => 4 ) );

$make = static function ( array $files ) {
	$path = wp_tempnam( 'aw-zip' );
	$za   = new ZipArchive();
	$za->open( $path, ZipArchive::OVERWRITE );
	foreach ( $files as $name => $code ) {
		$za->addFromString( $name, $code );
	}
	$za->close();
	return $path;
};
$render = static function () {
	ob_start();
	ZipTab::render();
	return ob_get_clean();
};
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );

$tabs = apply_filters( 'airworthy_admin_tabs', array() );
$check( 'Tab: it appears in the Airworthy tabs', isset( $tabs['zipcheck'] ) );
unset( $_GET['job'], $_GET['notice'] );
$html = $render();
$check( 'Tab (idle): the upload form is multipart, has a nonce, a file field and a PHP version choice', false !== strpos( $html, 'enctype="multipart/form-data"' ) && false !== strpos( $html, 'name="_wpnonce"' ) && false !== strpos( $html, 'type="file"' ) && false !== strpos( $html, 'name="target"' ) );
$check( 'Tab (idle): says nothing is installed or run', false !== strpos( $html, 'Nothing is installed or run' ) );

// identify(): plugin or theme, and the folder name.
$rm = new ReflectionMethod( ZipTab::class, 'identify' );
if ( PHP_VERSION_ID < 80100 ) {
	$rm->setAccessible( true );
}
$plugin_zip = $make( array( 'zip-demo/zip-demo.php' => "<?php\n/** Plugin Name: Zip Demo */\n" ) );
$theme_zip  = $make( array( 'zip-theme/style.css' => "/* Theme Name: Zip Theme */\n", 'zip-theme/index.php' => "<?php\n" ) );
$check( 'Identify: a plugin zip is a plugin, named by its folder', array( 'plugin', 'zip-demo' ) === $rm->invoke( null, $plugin_zip ) );
$check( 'Identify: a zip with a style.css is a theme', array( 'theme', 'zip-theme' ) === $rm->invoke( null, $theme_zip ) );
wp_delete_file( $theme_zip );

// A real check, rendered the way the tab does it: each render takes a step, until it is done.
$good = "<?php\n/** Plugin Name: Zip Demo */\nfunction zip_demo( \$v ) {\n\treturn implode( ', ', (array) \$v );\n}\n";
$job  = \Airworthy\Scan\UpdateCheck::from_zip( $plugin_zip, '8.4', '', array( 'type' => 'plugin', 'slug' => 'zip-demo', 'version' => '2.0' ) );
wp_delete_file( $plugin_zip );
$check( 'Check: a zip becomes a job', is_string( $job ), is_wp_error( $job ) ? $job->get_error_message() : '' );
$_GET['job'] = $job;
$html        = '';
for ( $i = 0; $i < 60; $i++ ) {
	$html = $render();
	if ( false === strpos( $html, 'Checking' ) ) {
		break;
	}
}
$check( 'Tab (result): shows the answer, the installed result and the zip\'s result', false !== strpos( $html, 'airworthy-pro-zip-result' ) && false !== strpos( $html, 'Installed now' ) && false !== strpos( $html, 'This zip' ) && false !== strpos( $html, 'PHP 8.4' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
$check( 'Tab (result): a clean zip compared with a Blocker says it fixes it', false !== strpos( $html, 'fixes it' ), substr( wp_strip_all_tags( $html ), 0, 300 ) );
$check( 'Tab (result): the unpacked files are gone', array() === glob( trailingslashit( get_temp_dir() ) . 'airworthy-uc-*' ) );

$_GET['job'] = 'doesnotexist123';
$html        = $render();
$check( 'Tab (result): a job that expired says the check ran out of time', false !== strpos( $html, 'could not finish' ) && false !== strpos( $html, 'ran out of time' ) );

$_GET['notice'] = 'toobig';
unset( $_GET['job'] );
$check( 'Tab: upload problems are explained', false !== strpos( $render(), '60 MB' ) );
unset( $_GET['notice'] );

$wpdb->delete( $t['components'], array( 'scan_id' => $scan ) );
$wpdb->delete( $t['scans'], array( 'id' => $scan ) );
wp_clear_scheduled_hook( \Airworthy\Scan\UpdateCheck::CLEANUP_HOOK );
echo $failed ? "\n$failed check(s) FAILED\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
