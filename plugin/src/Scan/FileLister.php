<?php
/**
 * Lists the PHP files in a plugin or theme.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Walks a component folder and returns its .php files as sorted paths relative to that folder.
 *
 * Symbolic links are never followed, so a scan can't leave the plugins and themes folders.
 * The vendor folder is included, since that code runs too. Generated data files (Symfony
 * charset tables, Composer and Jetpack classmaps, WordPress .l10n.php translations) are left
 * out: they hold no logic and are the heaviest files to scan (a 363 KB charset table needs
 * 134 MB). So are test suites inside bundled libraries, which never load on a live site.
 */
final class FileLister {

	/** Folders never worth descending into. */
	const SKIP_DIRS = array( '.git', '.svn', 'node_modules' );

	/**
	 * Test suites inside bundled libraries: never loaded on a live site. Only under library
	 * folders, so a plugin's own code is always scanned.
	 */
	const LIBRARY_TESTS = '#(^|/)(vendor|vendor_prefixed|vendor-prefixed|vendor-patched|third-party|jetpack_vendor)/(.+/)?(tests?|Tests?)/#';

	/** Generated data files, matched against the path relative to the component. */
	const DATA_FILES = '#(^|/)(polyfill-[^/]+/Resources/(charset|unidata)/|composer/(autoload_classmap|autoload_static|installed|jetpack_autoload_classmap|jetpack_autoload_filemap)\.php$)|\.l10n\.php$#';

	/**
	 * Lists a component's PHP files.
	 *
	 * @param string $root   Absolute path of the component folder, or of a single-file plugin.
	 * @return array{files:string[],ignored:int} Relative paths, and how many data and library-test files were left out.
	 */
	public static function list_files( $root ) {
		$root = rtrim( $root, '/\\' );

		if ( is_file( $root ) ) {
			return array(
				'files'   => array( basename( $root ) ),
				'ignored' => 0,
			);
		}
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return array(
				'files'   => array(),
				'ignored' => 0,
			);
		}

		$files   = array();
		$ignored = 0;
		$prefix  = strlen( $root ) + 1;

		$dirs = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO );
		// Skip unwanted folders and every symbolic link; RecursiveDirectoryIterator does not
		// descend into linked folders by default, and linked files are dropped below.
		$filter = new \RecursiveCallbackFilterIterator(
			$dirs,
			static function ( \SplFileInfo $item ) {
				if ( $item->isLink() ) {
					return false;
				}
				return ! ( $item->isDir() && in_array( $item->getFilename(), self::SKIP_DIRS, true ) );
			}
		);
		$items = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );

		foreach ( $items as $item ) {
			if ( 'php' !== strtolower( $item->getExtension() ) || ! $item->isFile() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), $prefix ) );
			if ( preg_match( self::DATA_FILES, $relative ) || preg_match( self::LIBRARY_TESTS, $relative ) ) {
				$ignored++;
				continue;
			}
			$files[] = $relative;
		}

		sort( $files, SORT_STRING );

		return array(
			'files'   => $files,
			'ignored' => $ignored,
		);
	}

	/**
	 * Prefixes of PHP extensions removed from PHP (as PHPCompatibility matches them). A plugin's
	 * own function that happens to start with one (sqlite_plugin_init) gets flagged as if it were
	 * the old extension; see Classifier::OWN_CODE.
	 */
	const REMOVED_EXTENSION_PREFIXES = array( 'activescript', 'cpdf', 'dbase', 'dbx', 'dio', 'ereg', 'fam', 'fbsql', 'fdf', 'filepro', 'hw_api', 'ibase', 'ingres', 'ircg', 'mcrypt', 'mcve', 'ming', 'mnogosearch', 'msql', 'mssql', 'mysql_', 'ncurses', 'oracle', 'ovrimos', 'pfpro_', 'recode', 'sqlite', 'sybase_ct', 'sybase', 'w32api', 'wddx', 'yp' );

	/**
	 * One read of every file before a component's first batch, for facts that span files:
	 * - serializable: interfaces extending Serializable (see serializable_interfaces());
	 * - functions:    the component's own top-level functions whose names start with a removed
	 *                 extension's prefix (lowercase).
	 *
	 * @param string   $root  Component folder (or single file).
	 * @param string[] $files Relative paths from list_files().
	 * @return array{serializable:string[],functions:string[]}
	 */
	public static function prepass( $root, array $files ) {
		$prefix_re = '/\bfunction\s+&?\s*(' . implode( '|', array_map( 'preg_quote', self::REMOVED_EXTENSION_PREFIXES ) ) . ')/i';
		$functions = array();
		foreach ( $files as $relative ) {
			$path = is_file( $root ) ? $root : rtrim( $root, '/\\' ) . '/' . $relative;
			$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; unreadable files are reported by the scan.
			if ( false === $code || ! preg_match( $prefix_re, $code ) ) {
				continue;
			}
			foreach ( self::top_level_functions( $code ) as $name ) {
				foreach ( self::REMOVED_EXTENSION_PREFIXES as $prefix ) {
					if ( 0 === strpos( $name, $prefix ) ) {
						$functions[ $name ] = true;
						break;
					}
				}
			}
		}
		return array(
			'serializable' => self::serializable_interfaces( $root, $files ),
			'functions'    => array_keys( $functions ),
		);
	}

	/**
	 * Names (lowercase) of functions declared outside any class, trait, interface or enum,
	 * including ones wrapped in `if ( ! function_exists() )`.
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	private static function top_level_functions( $code ) {
		try {
			$tokens = token_get_all( $code );
		} catch ( \Throwable $e ) {
			return array();
		}
		$names      = array();
		$depth      = 0;
		$class_ends = array(); // Depths at which class-like bodies close.
		$pending    = false;   // Saw class/trait/interface/enum; its body opens at the next '{'.
		$count      = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t    = $tokens[ $i ];
			$type = is_array( $t ) ? $t[0] : null;
			$text = is_array( $t ) ? $t[1] : $t;
			// phpcs:ignore PHPCompatibility.Constants.NewConstants.t_enumFound -- Guarded by defined() (PHP 8.1+ only).
			if ( in_array( $type, array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) || ( defined( 'T_ENUM' ) && T_ENUM === $type ) ) {
				// Not `::class` or `new class` (anonymous classes still count as class bodies).
				$pending = true;
			} elseif ( '{' === $text || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				$depth++;
				if ( $pending ) {
					$class_ends[] = $depth;
					$pending      = false;
				}
			} elseif ( '}' === $text ) {
				if ( $class_ends && end( $class_ends ) === $depth ) {
					array_pop( $class_ends );
				}
				$depth--;
			} elseif ( T_FUNCTION === $type && ! $class_ends ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
						$names[] = strtolower( $tokens[ $j ][1] );
						break;
					}
					if ( '(' === $tokens[ $j ] ) {
						break; // Closure.
					}
				}
			}
		}
		return $names;
	}

	/**
	 * Names of interfaces in a component that extend Serializable (directly or through another
	 * such interface). PHPCompatibility's RemovedSerializable rule needs them to spot classes
	 * that implement Serializable indirectly; it can't learn them across separate batches.
	 *
	 * @param string   $root  Component folder (or single file).
	 * @param string[] $files Relative paths from list_files().
	 * @return string[]
	 */
	public static function serializable_interfaces( $root, array $files ) {
		$extends = array(); // interface => names it extends.
		foreach ( $files as $relative ) {
			$path = is_file( $root ) ? $root : rtrim( $root, '/\\' ) . '/' . $relative;
			$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; unreadable files are reported by the scan.
			if ( false === $code || false === stripos( $code, 'interface' ) ) {
				continue;
			}
			if ( preg_match_all( '/\binterface\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([^{]+)\{/i', $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$parents = array();
					foreach ( explode( ',', $match[2] ) as $parent ) {
						$parents[] = strtolower( ltrim( substr( trim( $parent ), (int) strrpos( '\\' . trim( $parent ), '\\' ) ), '\\' ) );
					}
					$extends[ $match[1] ] = $parents;
				}
			}
		}

		// Follow chains: A extends Serializable, B extends A, ... (PHP names are case-insensitive).
		$found   = array(); // lowercase name => name as written.
		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( $extends as $name => $parents ) {
				$key = strtolower( $name );
				if ( isset( $found[ $key ] ) ) {
					continue;
				}
				foreach ( $parents as $parent ) {
					if ( 'serializable' === $parent || isset( $found[ $parent ] ) ) {
						$found[ $key ] = $name;
						$changed       = true;
						break;
					}
				}
			}
		}
		return array_values( $found );
	}
}
