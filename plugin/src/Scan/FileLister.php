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
 * 134 MB).
 */
final class FileLister {

	/** Folders never worth descending into. */
	const SKIP_DIRS = array( '.git', '.svn', 'node_modules' );

	/** Generated data files, matched against the path relative to the component. */
	const DATA_FILES = '#(^|/)(polyfill-[^/]+/Resources/(charset|unidata)/|composer/(autoload_classmap|autoload_static|installed|jetpack_autoload_classmap|jetpack_autoload_filemap)\.php$)|\.l10n\.php$#';

	/**
	 * Lists a component's PHP files.
	 *
	 * @param string $root   Absolute path of the component folder, or of a single-file plugin.
	 * @return array{files:string[],ignored:int} Relative paths, and how many data files were left out.
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
			if ( preg_match( self::DATA_FILES, $relative ) ) {
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
}
