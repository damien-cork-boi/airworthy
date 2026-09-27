<?php
/**
 * Decides whether a finding is plain, guarded or suppressed.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * A static scan flags old code even when it can never run on the target PHP version. Step 1
 * found that almost all "errors" in popular plugins are that kind of guarded fallback code. This
 * class tells them apart, per finding:
 *
 * - guarded: the line sits in code that won't run on the target PHP version:
 *     - inside a branch whose condition is false on the target (then-branch), or true on the
 *       target (else-branch, or code after an early `return`/`exit` in the if-body), or after
 *       `condition &&` in the same statement;
 *     - conditions are version checks (PHP_VERSION_ID, PHP_MAJOR_VERSION, version_compare with
 *       PHP_VERSION), evaluated against the target, or capability checks (function_exists,
 *       extension_loaded, class_exists, defined, ...) that name the flagged thing itself;
 *     - or the file is in a known library that picks an engine at runtime (phpseclib, Symfony
 *       polyfills, paragonie compat).
 *   A check about something else (the fake guard `if ( ! class_exists( 'My_Class' ) )`) or one
 *   that doesn't cover the flagged line never counts.
 * - suppressed: not guarded, but silenced by the author's phpcs:ignore / phpcs:disable comment.
 *   Shown as a lower-confidence finding; never hidden.
 * - own: a removed-extension finding that is really a call to the component's own function.
 * - plain: everything else.
 */
final class Classifier {

	const PLAIN      = 'plain';
	const GUARDED    = 'guarded';
	const SUPPRESSED = 'suppressed';

	/**
	 * Not a PHP problem at all: a removed-extension finding whose flagged calls on the line are
	 * all the component's own functions (sqlite_plugin_init() is not the old sqlite extension).
	 * Stored as an informational notice, never counted towards the verdict.
	 */
	const OWN_CODE = 'own';

	/** Libraries that choose a code path at runtime by PHP version or extension. */
	const ENGINE_LIBRARIES = '#(^|/)(phpseclib|symfony/polyfill-[^/]+|paragonie/(random_compat|sodium_compat))/#i';

	/** Capability checks and the argument position holding the name they check. */
	const CAPABILITY_CHECKS = array(
		'function_exists'  => 0,
		'is_callable'      => 0,
		'extension_loaded' => 0,
		'class_exists'     => 0,
		'interface_exists' => 0,
		'defined'          => 0,
		'method_exists'    => 1,
	);

	/**
	 * PHP source of the file.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Path relative to the component.
	 *
	 * @var string
	 */
	private $relative;

	/**
	 * Target version as PHP_VERSION_ID (e.g. 80400) and as a version string ("8.4.0").
	 *
	 * @var int
	 */
	private $target_id;

	/**
	 * Target version string.
	 *
	 * @var string
	 */
	private $target_version;

	/**
	 * Significant tokens: arrays of [type, text, line]; single characters have type 0.
	 *
	 * @var array|null
	 */
	private $tokens;

	/**
	 * Matching bracket index for '(' ')' '{' '}' '[' ']'.
	 *
	 * @var array<int,int>
	 */
	private $match = array();

	/**
	 * Guarded regions: [start_line, end_line, condition token indexes [from, to], branch].
	 *
	 * @var array|null
	 */
	private $regions;

	/**
	 * Suppression annotations: line => list of rule prefixes ([] = all rules).
	 *
	 * @var array<int,array>|null
	 */
	private $ignores;

	/**
	 * Disabled ranges: [start_line, end_line, rules].
	 *
	 * @var array
	 */
	private $disabled = array();

	/**
	 * The component's own functions with removed-extension prefixes (lowercase).
	 *
	 * @var array<string,bool>
	 */
	private $own_functions;

	/**
	 * Prepares a classifier for one file.
	 *
	 * @param string   $source        File contents.
	 * @param string   $relative      Path relative to the component.
	 * @param string   $target        Exact target PHP version, e.g. "8.4".
	 * @param string[] $own_functions The component's own functions with removed-extension prefixes.
	 */
	public function __construct( $source, $relative, $target, array $own_functions = array() ) {
		$this->source         = $source;
		$this->relative       = $relative;
		$this->own_functions  = array_fill_keys( array_map( 'strtolower', $own_functions ), true );
		$parts                = array_map( 'intval', explode( '.', $target ) );
		$this->target_id      = $parts[0] * 10000 + ( isset( $parts[1] ) ? $parts[1] : 0 ) * 100;
		$this->target_version = $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : 0 ) . '.0';
	}

	/**
	 * Classifies one finding.
	 *
	 * @param array $issue Finding with line, severity, rule and message.
	 * @return string One of the PLAIN / GUARDED / SUPPRESSED constants.
	 */
	public function classify( array $issue ) {
		if ( $this->is_own_code( $issue ) ) {
			return self::OWN_CODE;
		}
		if ( preg_match( self::ENGINE_LIBRARIES, $this->relative ) ) {
			return self::GUARDED;
		}
		if ( $this->is_guarded( $issue ) ) {
			return self::GUARDED;
		}
		if ( $this->is_suppressed( (int) $issue['line'], (string) $issue['rule'] ) ) {
			return self::SUPPRESSED;
		}
		return self::PLAIN;
	}

	/**
	 * Whether a removed-extension finding only points at the component's own functions.
	 *
	 * @param array $issue Finding.
	 * @return bool
	 */
	private function is_own_code( array $issue ) {
		if ( ! $this->own_functions || false === strpos( (string) $issue['rule'], '.Extensions.RemovedExtensions.' ) ) {
			return false;
		}
		$parts  = explode( '.', (string) $issue['rule'] );
		$prefix = strtolower( preg_replace( '/(DeprecatedRemoved|Deprecated|Removed)$/', '', (string) end( $parts ) ) );
		$lines  = preg_split( '/\r\n|\r|\n/', $this->source );
		$text   = isset( $lines[ (int) $issue['line'] - 1 ] ) ? $lines[ (int) $issue['line'] - 1 ] : '';
		// Plain function calls on the line (not methods, static calls or declarations).
		if ( '' === $prefix || ! preg_match_all( '/(?<![\w>:$\\\\])([a-z_][a-z0-9_]*)\s*\(/i', $text, $m ) ) {
			return false;
		}
		$calls = array();
		foreach ( $m[1] as $name ) {
			$name = strtolower( $name );
			if ( 0 === strpos( $name, $prefix ) ) {
				$calls[] = $name;
			}
		}
		if ( ! $calls ) {
			return false;
		}
		foreach ( $calls as $name ) {
			if ( ! isset( $this->own_functions[ $name ] ) ) {
				return false;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------------------------------
	// Guards.
	// ---------------------------------------------------------------------------------------

	/**
	 * Whether any guarded region covering the finding's line applies to it.
	 *
	 * @param array $issue Finding.
	 * @return bool
	 */
	private function is_guarded( array $issue ) {
		$this->parse();
		$line     = (int) $issue['line'];
		$subjects = self::subjects( $issue );
		$removed  = 'error' === $issue['severity'];

		foreach ( $this->regions as $region ) {
			list( $start, $end, $cond, $branch ) = $region;
			if ( $line < $start || $line > $end ) {
				continue;
			}
			$value = $this->evaluate( $cond[0], $cond[1], $subjects, $removed );
			if ( null === $value ) {
				continue;
			}
			// A then-branch is dead when its condition is false on the target; an else-branch
			// (or the code after an early exit) is dead when it is true.
			if ( ( 'then' === $branch && false === $value ) || ( 'else' === $branch && true === $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates a condition on the target PHP version: true, false, or null when unknown.
	 *
	 * @param int   $from     First condition token index.
	 * @param int   $to       Last condition token index.
	 * @param array $subjects Names the finding is about (lowercase).
	 * @param bool  $removed  Whether the flagged thing is removed on the target (error) or only deprecated.
	 * @return bool|null
	 */
	private function evaluate( $from, $to, array $subjects, $removed ) {
		// Strip wrapping parentheses and leading negations.
		$negate = false;
		while ( $from <= $to ) {
			$text = $this->tokens[ $from ][1];
			if ( '!' === $text ) {
				$negate = ! $negate;
				$from++;
			} elseif ( '(' === $text && isset( $this->match[ $from ] ) && $this->match[ $from ] === $to ) {
				$from++;
				$to--;
			} else {
				break;
			}
		}
		if ( $from > $to ) {
			return null;
		}

		// Split on top-level && / || (mixing both is left as unknown).
		$ands  = array();
		$ors   = array();
		$depth = 0;
		$part  = $from;
		for ( $i = $from; $i <= $to; $i++ ) {
			$text = $this->tokens[ $i ][1];
			if ( '(' === $text || '[' === $text ) {
				$depth++;
			} elseif ( ')' === $text || ']' === $text ) {
				$depth--;
			} elseif ( 0 === $depth && ( '&&' === $text || 'and' === strtolower( $text ) ) ) {
				$ands[] = array( $part, $i - 1 );
				$part   = $i + 1;
			} elseif ( 0 === $depth && ( '||' === $text || 'or' === strtolower( $text ) ) ) {
				$ors[] = array( $part, $i - 1 );
				$part  = $i + 1;
			}
		}

		if ( $ands && $ors ) {
			return null;
		}
		if ( $ands || $ors ) {
			$parts   = $ands ? $ands : $ors;
			$parts[] = array( $part, $to );
			$values  = array();
			foreach ( $parts as $p ) {
				$values[] = $this->evaluate( $p[0], $p[1], $subjects, $removed );
			}
			if ( $ands ) {
				$value = in_array( false, $values, true ) ? false : ( in_array( null, $values, true ) ? null : true );
			} else {
				$value = in_array( true, $values, true ) ? true : ( in_array( null, $values, true ) ? null : false );
			}
		} else {
			$value = $this->evaluate_atom( $from, $to, $subjects, $removed );
		}

		if ( null === $value ) {
			return null;
		}
		return $negate ? ! $value : $value;
	}

	/**
	 * Evaluates one comparison or capability check.
	 *
	 * @param int   $from     First token index.
	 * @param int   $to       Last token index.
	 * @param array $subjects Names the finding is about.
	 * @param bool  $removed  Whether the flagged thing is removed on the target.
	 * @return bool|null
	 */
	private function evaluate_atom( $from, $to, array $subjects, $removed ) {
		$text = '';
		for ( $i = $from; $i <= $to; $i++ ) {
			$text .= $this->tokens[ $i ][1];
		}
		$text = str_replace( '\\', '', $text ); // \function_exists, \PHP_VERSION_ID.
		$text = str_replace( '"', "'", $text );

		// Capability check naming the flagged thing: false on the target when it was removed.
		if ( preg_match( '/^([a-z_]+)\((.*)\)$/i', $text, $m ) && isset( self::CAPABILITY_CHECKS[ strtolower( $m[1] ) ] ) ) {
			$args = array_map( 'trim', explode( ',', $m[2] ) );
			$pos  = self::CAPABILITY_CHECKS[ strtolower( $m[1] ) ];
			if ( isset( $args[ $pos ] ) && preg_match( "/^'([^']+)'$/", $args[ $pos ], $name ) && self::names_match( strtolower( $name[1] ), $subjects ) ) {
				return ! $removed;
			}
			return null;
		}

		// PHP_VERSION_ID <op> N, N <op> PHP_VERSION_ID.
		if ( preg_match( '/^PHP_VERSION_ID(<=|>=|===|!==|==|!=|<|>)(\d+)$/', $text, $m ) ) {
			return self::compare( $this->target_id, $m[1], (int) $m[2] );
		}
		if ( preg_match( '/^(\d+)(<=|>=|===|!==|==|!=|<|>)PHP_VERSION_ID$/', $text, $m ) ) {
			return self::compare( (int) $m[1], $m[2], $this->target_id );
		}
		// PHP_MAJOR_VERSION <op> N.
		if ( preg_match( '/^PHP_MAJOR_VERSION(<=|>=|===|!==|==|!=|<|>)(\d+)$/', $text, $m ) ) {
			return self::compare( (int) floor( $this->target_id / 10000 ), $m[1], (int) $m[2] );
		}
		// version_compare( PHP_VERSION, 'x.y', 'op' ) [ === true ], or with the operands swapped,
		// or the three-way form compared with a number.
		$ver = "(?:PHP_VERSION|phpversion\(\))";
		if ( preg_match( "/^version_compare\($ver,'([\d.]+)',?'?([<>=!a-z]*)'?\)(.*)$/i", $text, $m ) ) {
			return $this->version_compare_result( $this->target_version, $m[1], $m[2], $m[3] );
		}
		if ( preg_match( "/^version_compare\('([\d.]+)',$ver,?'?([<>=!a-z]*)'?\)(.*)$/i", $text, $m ) ) {
			return $this->version_compare_result( $m[1], $this->target_version, $m[2], $m[3] );
		}
		return null;
	}

	/**
	 * Result of a version_compare() call on the target, including a trailing comparison.
	 *
	 * @param string $a     First version.
	 * @param string $b     Second version.
	 * @param string $op    Operator argument ('' for the three-way form).
	 * @param string $trail Anything after the call, e.g. "===true", "<0".
	 * @return bool|null
	 */
	private function version_compare_result( $a, $b, $op, $trail ) {
		if ( '' !== $op ) {
			$result = version_compare( $a, $b, $op );
			if ( null === $result ) {
				return null;
			}
			if ( '' === $trail || preg_match( '/^(===?true|!==?false)$/i', $trail ) ) {
				return $result;
			}
			if ( preg_match( '/^(===?false|!==?true)$/i', $trail ) ) {
				return ! $result;
			}
			return null;
		}
		if ( preg_match( '/^(<=|>=|===|!==|==|!=|<|>)(-?\d+)$/', $trail, $m ) ) {
			return self::compare( version_compare( $a, $b ), $m[1], (int) $m[2] );
		}
		return null;
	}

	/**
	 * Compares two integers with a PHP comparison operator.
	 *
	 * @param int    $a  Left.
	 * @param string $op Operator.
	 * @param int    $b  Right.
	 * @return bool
	 */
	private static function compare( $a, $op, $b ) {
		switch ( $op ) {
			case '<':
				return $a < $b;
			case '<=':
				return $a <= $b;
			case '>':
				return $a > $b;
			case '>=':
				return $a >= $b;
			case '==':
			case '===':
				return $a === $b;
			default:
				return $a !== $b;
		}
	}

	/**
	 * Names a finding is about, from its rule code and message: function, constant, class,
	 * ini setting or extension (lowercase; extension prefixes keep their trailing "_").
	 *
	 * @param array $issue Finding.
	 * @return string[]
	 */
	private static function subjects( array $issue ) {
		$names = array();
		$parts = explode( '.', (string) $issue['rule'] );
		$code  = (string) end( $parts );
		$name  = preg_replace( '/(DeprecatedRemoved|DeprecatedParamNotPassed|Deprecated|Removed|Found)$/', '', $code );
		if ( '' !== $name && strtolower( $name ) !== strtolower( $code ) ) {
			$names[] = strtolower( $name );
		}
		if ( preg_match_all( "/\b([A-Za-z_][A-Za-z0-9_]*)\(\)|'([A-Za-z_][A-Za-z0-9_]*)'/", (string) $issue['message'], $m ) ) {
			foreach ( array_merge( $m[1], $m[2] ) as $found ) {
				if ( '' !== $found ) {
					$names[] = strtolower( $found );
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether a checked name refers to one of the finding's subjects. Extension checks match
	 * the extension's functions: extension_loaded( 'mysql' ) covers mysql_query().
	 *
	 * @param string $checked  Name in the capability check (lowercase).
	 * @param array  $subjects Finding subjects.
	 * @return bool
	 */
	private static function names_match( $checked, array $subjects ) {
		foreach ( $subjects as $subject ) {
			$prefix = rtrim( $subject, '_' );
			if ( $checked === $subject || $checked === $prefix
				|| 0 === strpos( $subject, $checked . '_' )
				|| ( '_' === substr( $subject, -1 ) && 0 === strpos( $checked, $subject ) ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------
	// Tokens and regions.
	// ---------------------------------------------------------------------------------------

	/**
	 * Tokenises the file and finds guarded regions and suppression comments (once).
	 */
	private function parse() {
		if ( null !== $this->tokens ) {
			return;
		}
		$this->tokens  = array();
		$this->regions = array();
		$this->ignores = array();

		$line = 1;
		try {
			$raw = token_get_all( $this->source );
		} catch ( \Throwable $e ) {
			return; // Unparseable: nothing counts as guarded.
		}
		foreach ( $raw as $t ) {
			if ( is_array( $t ) ) {
				list( $type, $text, $line ) = $t;
				if ( T_COMMENT === $type || T_DOC_COMMENT === $type ) {
					$this->note_comment( $text, $line );
				} elseif ( T_WHITESPACE !== $type && T_OPEN_TAG !== $type && T_CLOSE_TAG !== $type && T_INLINE_HTML !== $type ) {
					$this->tokens[] = array( $type, $text, $line );
				}
				$line += substr_count( $text, "\n" );
			} else {
				$this->tokens[] = array( 0, $t, $line );
			}
		}

		// Bracket matching; T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES open a '{' too.
		$stack = array();
		foreach ( $this->tokens as $i => $t ) {
			$text = $t[1];
			if ( '(' === $text || '[' === $text || '{' === $text || T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
				$stack[] = $i;
			} elseif ( ( ')' === $text || ']' === $text || '}' === $text ) && $stack ) {
				$open                 = array_pop( $stack );
				$this->match[ $open ] = $i;
				$this->match[ $i ]    = $open;
			}
		}

		$this->find_regions();
	}

	/**
	 * Records if/elseif/else branches, early exits and `condition &&` statements.
	 */
	private function find_regions() {
		$n     = count( $this->tokens );
		$last  = $n ? $this->tokens[ $n - 1 ][2] : 1;
		$block = array(); // Stack of open '{' indexes, for early exits.

		for ( $i = 0; $i < $n; $i++ ) {
			$type = $this->tokens[ $i ][0];
			$text = $this->tokens[ $i ][1];

			if ( '{' === $text || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				$block[] = $i;
				continue;
			}
			if ( '}' === $text ) {
				array_pop( $block );
				continue;
			}

			if ( T_IF === $type || T_ELSEIF === $type ) {
				$open = $i + 1;
				if ( ! isset( $this->tokens[ $open ] ) || '(' !== $this->tokens[ $open ][1] || ! isset( $this->match[ $open ] ) ) {
					continue;
				}
				$close = $this->match[ $open ];
				$cond  = array( $open + 1, $close - 1 );
				$body  = $this->body( $close + 1 );
				if ( ! $body ) {
					continue;
				}
				$this->regions[] = array( $this->line( $body[0] ), $this->line( $body[1] ), $cond, 'then' );

				// Early exit: `if ( cond ) { return; }` — code after it in the block runs only
				// when cond is false.
				if ( $this->is_exit_only( $body ) ) {
					$end             = $block ? ( isset( $this->match[ end( $block ) ] ) ? $this->line( $this->match[ end( $block ) ] ) : $last ) : $last;
					$this->regions[] = array( $this->line( $body[1] ) + 1, $end, $cond, 'else' );
				}

				// Plain if/else (not after an elseif): the else branch runs when cond is false.
				$next = $body[1] + 1;
				if ( T_IF === $type && isset( $this->tokens[ $next ] ) && T_ELSE === $this->tokens[ $next ][0] ) {
					$else = $this->body( $next + 1 );
					if ( $else ) {
						$this->regions[] = array( $this->line( $else[0] ), $this->line( $else[1] ), $cond, 'else' );
					}
				}
				continue;
			}

			// `capability_check( ... ) && code;` and `version_check && code;` within one statement.
			if ( '&&' === $text || T_LOGICAL_AND === $type ) {
				$start = $this->statement_start( $i );
				$end   = $this->statement_end( $i );
				if ( $start < $i ) {
					$this->regions[] = array( $this->line( $i ), $this->line( $end ), array( $start, $i - 1 ), 'then' );
				}
			}
		}
	}

	/**
	 * Token range of a branch body starting at $i: a { block }, an alternative-syntax block
	 * (`: ... endif;`), or a single statement (including a nested if).
	 *
	 * @param int $i Index of the first body token.
	 * @return array{0:int,1:int}|null
	 */
	private function body( $i ) {
		if ( ! isset( $this->tokens[ $i ] ) ) {
			return null;
		}
		$text = $this->tokens[ $i ][1];
		if ( '{' === $text ) {
			return isset( $this->match[ $i ] ) ? array( $i, $this->match[ $i ] ) : null;
		}
		if ( ':' === $text ) {
			// Alternative syntax: runs to the matching else/elseif/endif at the same depth.
			$depth = 0;
			$n     = count( $this->tokens );
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$type = $this->tokens[ $j ][0];
				if ( T_IF === $type && isset( $this->tokens[ $j + 1 ] ) && isset( $this->match[ $j + 1 ] ) && isset( $this->tokens[ $this->match[ $j + 1 ] + 1 ] ) && ':' === $this->tokens[ $this->match[ $j + 1 ] + 1 ][1] ) {
					$depth++;
				} elseif ( T_ENDIF === $type ) {
					if ( 0 === $depth ) {
						return array( $i, $j - 1 );
					}
					$depth--;
				} elseif ( 0 === $depth && ( T_ELSE === $type || T_ELSEIF === $type ) ) {
					return array( $i, $j - 1 );
				}
			}
			return null;
		}
		if ( T_IF === $this->tokens[ $i ][0] && isset( $this->tokens[ $i + 1 ] ) && isset( $this->match[ $i + 1 ] ) ) {
			$inner = $this->body( $this->match[ $i + 1 ] + 1 );
			if ( ! $inner ) {
				return null;
			}
			$end = $inner[1];
			// Include a following else/elseif chain.
			while ( isset( $this->tokens[ $end + 1 ] ) && ( T_ELSE === $this->tokens[ $end + 1 ][0] || T_ELSEIF === $this->tokens[ $end + 1 ][0] ) ) {
				$k = $end + 2;
				if ( T_ELSEIF === $this->tokens[ $end + 1 ][0] && isset( $this->match[ $k ] ) ) {
					$k = $this->match[ $k ] + 1;
				}
				$next = $this->body( $k );
				if ( ! $next ) {
					break;
				}
				$end = $next[1];
			}
			return array( $i, $end );
		}
		return array( $i, $this->statement_end( $i ) );
	}

	/**
	 * Whether a body holds nothing but a return / exit / throw / continue / break statement.
	 *
	 * @param array $body Token range.
	 * @return bool
	 */
	private function is_exit_only( array $body ) {
		$first = '{' === $this->tokens[ $body[0] ][1] ? $body[0] + 1 : $body[0];
		if ( ! isset( $this->tokens[ $first ] ) || ! in_array( $this->tokens[ $first ][0], array( T_RETURN, T_EXIT, T_THROW, T_CONTINUE, T_BREAK ), true ) ) {
			return false;
		}
		$end  = $this->statement_end( $first );
		$last = '{' === $this->tokens[ $body[0] ][1] ? $body[1] - 1 : $body[1];
		return $end >= $last;
	}

	/**
	 * Index of the ';' ending the statement containing $i (or the last token before a closing
	 * brace of the enclosing block).
	 *
	 * @param int $i Token index.
	 * @return int
	 */
	private function statement_end( $i ) {
		$n = count( $this->tokens );
		for ( $j = $i; $j < $n; $j++ ) {
			$text = $this->tokens[ $j ][1];
			if ( ( '(' === $text || '[' === $text || '{' === $text ) && isset( $this->match[ $j ] ) && $this->match[ $j ] > $j ) {
				$j = $this->match[ $j ];
				continue;
			}
			if ( ';' === $text ) {
				return $j;
			}
			if ( '}' === $text || ')' === $text || ']' === $text ) {
				return max( $i, $j - 1 );
			}
		}
		return $n - 1;
	}

	/**
	 * Index of the first token of the expression that ends at $i (walking back to the start of
	 * the statement, an assignment, `return`, or an opening bracket).
	 *
	 * @param int $i Token index of an && operator.
	 * @return int
	 */
	private function statement_start( $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$type = $this->tokens[ $j ][0];
			$text = $this->tokens[ $j ][1];
			if ( ( ')' === $text || ']' === $text ) && isset( $this->match[ $j ] ) ) {
				$j = $this->match[ $j ];
				continue;
			}
			if ( in_array( $text, array( ';', '{', '}', '(', '[', ',', '=', '?', ':', '||', '&&' ), true ) || in_array( $type, array( T_RETURN, T_ECHO, T_LOGICAL_OR, T_LOGICAL_AND, T_OPEN_TAG ), true ) ) {
				return $j + 1;
			}
		}
		return 0;
	}

	/**
	 * Line of a token.
	 *
	 * @param int $i Token index.
	 * @return int
	 */
	private function line( $i ) {
		return (int) $this->tokens[ $i ][2];
	}

	// ---------------------------------------------------------------------------------------
	// Suppression comments.
	// ---------------------------------------------------------------------------------------

	/**
	 * Records phpcs:ignore / phpcs:disable / phpcs:enable (and the legacy
	 * @codingStandardsIgnore* forms) from a comment.
	 *
	 * @param string $text Comment text.
	 * @param int    $line Line it starts on.
	 */
	private function note_comment( $text, $line ) {
		if ( ! preg_match( '/(phpcs:(ignore|disable|enable)|@codingStandardsIgnore(Line|Start|End))\b([^\n]*)/i', $text, $m ) ) {
			return;
		}
		$rules = array();
		$list  = preg_replace( '/\s--.*$/', '', trim( $m[4] ) );
		foreach ( preg_split( '/\s*,\s*/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $rule ) {
			$rules[] = trim( $rule, " \t*/" );
		}
		$rules = array_values( array_filter( $rules ) );
		$kind  = strtolower( '' !== $m[2] ? $m[2] : $m[3] );

		if ( 'ignore' === $kind || 'line' === $kind ) {
			// Covers its own line (trailing comment) and the next (comment on its own line).
			$this->ignores[ $line ]     = $rules;
			$this->ignores[ $line + 1 ] = $rules;
		} elseif ( 'disable' === $kind || 'start' === $kind ) {
			$this->disabled[] = array( $line, PHP_INT_MAX, $rules );
		} else {
			// Enable: closes open disabled ranges (all, or the named rules).
			foreach ( $this->disabled as $k => $range ) {
				if ( PHP_INT_MAX === $range[1] && ( ! $rules || $rules === $range[2] ) ) {
					$this->disabled[ $k ][1] = $line;
				}
			}
		}
	}

	/**
	 * Whether the author silenced this rule on this line.
	 *
	 * @param int    $line Line.
	 * @param string $rule Rule code.
	 * @return bool
	 */
	private function is_suppressed( $line, $rule ) {
		$this->parse();
		if ( isset( $this->ignores[ $line ] ) && self::rules_cover( $this->ignores[ $line ], $rule ) ) {
			return true;
		}
		foreach ( $this->disabled as $range ) {
			if ( $line >= $range[0] && $line <= $range[1] && self::rules_cover( $range[2], $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an annotation's rule list covers a rule (an empty list covers everything).
	 *
	 * @param array  $rules Rule prefixes from the annotation.
	 * @param string $rule  Rule code of the finding.
	 * @return bool
	 */
	private static function rules_cover( array $rules, $rule ) {
		if ( ! $rules ) {
			return true;
		}
		foreach ( self::equivalent_rules( $rule ) as $candidate ) {
			foreach ( $rules as $prefix ) {
				if ( $candidate === $prefix || 0 === strpos( $candidate, $prefix . '.' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A rule plus the rules that report the same problem. PHPCompatibility 10 flags a call like
	 * mysql_query() twice, as a removed function and as part of a removed extension, but code
	 * written for version 9 silences only the extension rule (the only one it had). An author
	 * who silenced the extension has acknowledged the function call on that line too.
	 *
	 * @param string $rule Rule code.
	 * @return string[]
	 */
	private static function equivalent_rules( $rule ) {
		$rules = array( $rule );
		if ( preg_match( '/^PHPCompatibility\.FunctionUse\.RemovedFunctions\.([a-z0-9]+)_[a-z0-9_]*?(DeprecatedRemoved|Removed)$/i', $rule, $m ) ) {
			$rules[] = 'PHPCompatibility.Extensions.RemovedExtensions.' . $m[1] . '_' . $m[2];
			$rules[] = 'PHPCompatibility.Extensions.RemovedExtensions.' . $m[1] . 'DeprecatedRemoved';
			$rules[] = 'PHPCompatibility.Extensions.RemovedExtensions.' . $m[1] . 'Removed';
		}
		return $rules;
	}
}
