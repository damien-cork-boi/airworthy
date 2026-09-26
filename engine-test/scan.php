<?php
/**
 * Engine test harness: runs PHP_CodeSniffer + PHPCompatibilityWP *in-process* (as a library,
 * the way the plugin will) rather than via the phpcs CLI.
 *
 * Usage: php scan.php --engine=v9|v10 --target=8.2 [--max-bytes=N] <file-or-dir>...
 * Prints JSON: per-file issues, wall time and peak memory.
 */

$opts    = getopt( '', array( 'engine:', 'target:', 'max-bytes::' ), $rest );
$engine  = $opts['engine'] ?? 'v9';
$target  = $opts['target'] ?? '8.0';
$max     = (int) ( $opts['max-bytes'] ?? 1024 * 1024 ); // Skip files over 1 MB by default.
$paths   = array_slice( $argv, $rest );
$vendors = array( 'v9' => '/vendor', 'v10' => '/v10/vendor', 'build' => '/../build/airworthy/vendor' );
$vendor  = __DIR__ . $vendors[ $engine ];
// The scoped build prefixes every engine class.
$ns      = 'build' === $engine ? 'Airworthy\\Vendor\\' : '';

require $vendor . '/autoload.php';
require_once $vendor . '/squizlabs/php_codesniffer/autoload.php';

$Config    = $ns . 'PHP_CodeSniffer\\Config';
$LocalFile = $ns . 'PHP_CodeSniffer\\Files\\LocalFile';
$Runner    = $ns . 'PHP_CodeSniffer\\Runner';
$Tokens    = $ns . 'PHP_CodeSniffer\\Util\\Tokens';

if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
	define( 'PHP_CODESNIFFER_CBF', false );
}
if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
	define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
}
class_exists( $Tokens ); // Loads the T_* token constant polyfills.

$t0 = microtime( true );
// Register rulesets in code (temp=true: never write CodeSniffer.conf) — the plugin must do the same.
$std_dirs = array_filter( glob( $vendor . '/phpcompatibility/*' ), 'is_dir' );
$is_static = ( new ReflectionMethod( $Config, 'setConfigData' ) )->isStatic(); // PHPCS 3: static, 4: instance.
if ( $is_static ) {
	$Config::setConfigData( 'installed_paths', implode( ',', $std_dirs ), true );
}
$config = new $Config(
	array(
		'--standard=PHPCompatibilityWP',
		'--runtime-set', 'testVersion', $target,
		'--extensions=php',
		'--ignore-annotations', // Plugin authors' "phpcs:ignore" comments must not hide real issues.
		'-q',
	)
);
if ( ! $is_static ) {
	$config->setConfigData( 'installed_paths', implode( ',', $std_dirs ), true );
}
$runner         = new $Runner();
$runner->config = $config;
$runner->init();
$setup_ms = ( microtime( true ) - $t0 ) * 1000;

$files = array();
foreach ( $paths as $p ) {
	if ( is_dir( $p ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $p, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isLink() && 'php' === strtolower( $f->getExtension() ) ) {
				$files[] = $f->getPathname();
			}
		}
	} else {
		$files[] = $p;
	}
}
sort( $files );

$out = array(
	'engine'   => $engine,
	'target'   => $target,
	'phpcs'    => $Config::VERSION,
	'host_php' => PHP_VERSION,
	'setup_ms' => round( $setup_ms, 1 ),
	'files'    => array(),
);

foreach ( $files as $path ) {
	$rec = array( 'path' => $path, 'bytes' => @filesize( $path ), 'status' => 'scanned', 'issues' => array() );

	if ( ! is_readable( $path ) ) {
		$rec['status'] = 'unreadable';
		$out['files'][] = $rec;
		continue;
	}
	if ( $rec['bytes'] > $max ) {
		$rec['status'] = 'skipped_too_large';
		$out['files'][] = $rec;
		continue;
	}

	if ( getenv( 'SCAN_TRACE' ) ) {
		fprintf( STDERR, "START\t%s\t%d\n", $path, $rec['bytes'] );
	}
	$mem0 = memory_get_usage();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$t = microtime( true );
	try {
		$file = new $LocalFile( $path, $runner->ruleset, $runner->config );
		$file->process();
		foreach ( array( 'error' => $file->getErrors(), 'warning' => $file->getWarnings() ) as $sev => $by_line ) {
			foreach ( $by_line as $line => $cols ) {
				foreach ( $cols as $msgs ) {
					foreach ( $msgs as $m ) {
						if ( 0 === strpos( $m['source'], 'Internal.' ) ) {
							$rec['status'] = 'internal_error';
						}
						$rec['issues'][] = array( 'line' => $line, 'severity' => $sev, 'source' => $m['source'], 'message' => $m['message'] );
					}
				}
			}
		}
		$file->cleanUp();
		unset( $file );
		if ( getenv( 'SCAN_CLEAR' ) ) {
			// PHPCSUtils keeps static per-file caches that are never freed in a long-running process.
			foreach ( array( $ns . 'PHPCSUtils\\Internal\\Cache', $ns . 'PHPCSUtils\\Internal\\NoFileCache' ) as $c ) {
				if ( class_exists( $c, false ) ) {
					$c::clear();
				}
			}
		}
	} catch ( Throwable $e ) {
		$rec['status'] = 'exception';
		$rec['error']  = get_class( $e ) . ': ' . $e->getMessage();
	}
	$rec['ms']        = round( ( microtime( true ) - $t ) * 1000, 2 );
	$rec['peak_kb']   = round( ( memory_get_peak_usage() - $mem0 ) / 1024 );
	$rec['after_kb']  = round( memory_get_usage() / 1024 );
	if ( getenv( 'SCAN_TRACE' ) ) {
		fprintf( STDERR, "%s\t%d\t%s\t%s\n", $rec['after_kb'], $rec['peak_kb'], $rec['ms'], $path );
	}
	$out['files'][]   = $rec;
}

$out['total_ms']     = round( ( microtime( true ) - $t0 ) * 1000, 1 );
$out['peak_mem_mb']  = round( memory_get_peak_usage() / 1048576, 1 );
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
