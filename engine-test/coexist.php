<?php
// Another plugin's unscoped PHPCS 4 is loaded first, then ours. Both must work side by side.
$theirs = __DIR__ . '/v10/vendor';
$ours   = dirname( __DIR__ ) . '/build/airworthy/vendor';
require $theirs . '/autoload.php';
require $theirs . '/squizlabs/php_codesniffer/autoload.php';
require $ours . '/autoload.php';
require $ours . '/squizlabs/php_codesniffer/autoload.php';
define( 'PHP_CODESNIFFER_CBF', false );
define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
$run = function ( $ns, $vendor, $file ) {
	class_exists( $ns . 'PHP_CodeSniffer\\Util\\Tokens' );
	$C = $ns . 'PHP_CodeSniffer\\Config';
	$R = $ns . 'PHP_CodeSniffer\\Runner';
	$L = $ns . 'PHP_CodeSniffer\\Files\\LocalFile';
	$config = new $C( array( '--standard=PHPCompatibilityWP', '--runtime-set', 'testVersion', '8.4', '-q' ) );
	$config->setConfigData( 'installed_paths', implode( ',', glob( $vendor . '/phpcompatibility/*' ) ), true );
	$r = new $R(); $r->config = $config; $r->init();
	$f = new $L( $file, $r->ruleset, $r->config ); $f->process();
	return $f->getErrorCount() . ' errors, ' . $f->getWarningCount() . ' warnings';
};
$fx = __DIR__ . '/fixtures/coverage/php84.php';
echo 'theirs (unscoped): ', $run( '', $theirs, $fx ), "\n";
echo 'ours (scoped):     ', $run( 'Airworthy\\Vendor\\', $ours, $fx ), "\n";
echo 'theirs again:      ', $run( '', $theirs, $fx ), "\n";
