<?php
/**
 * Builds a translation template (.pot) without WP-CLI. Same output format as
 * `wp i18n make-pot`, which you can use instead when WP-CLI is installed.
 *
 * Usage, from the folder that holds both plugins:
 *   php tagwright-seo/bin/make-pot.php tagwright-seo tagwright-seo tagwright-seo/languages/tagwright-seo.pot
 *   php tagwright-seo/bin/make-pot.php tagwright-seo-pro tagwright-seo-pro tagwright-seo-pro/languages/tagwright-seo-pro.pot
 *
 * Reads gettext calls in PHP and JS (with translator notes on the same or the
 * line above), contexts, plurals and the plugin header, and lists any text
 * with a placeholder that has no translator note. Not shipped (bin/ is in
 * .distignore).
 *
 * @package TagwrightSEO
 */

namespace TagwrightSEO\Dev;

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

function add_entry( &$entries, &$missing, $rel, $line, $spec, $args, $comment, $domain ) {
	[ $si, $pi, $ci, $di ] = $spec;
	if ( ( $args[ $di ] ?? null ) !== $domain || ! is_string( $args[ $si ] ?? null ) ) {
		return;
	}
	$msgid = $args[ $si ];
	$ctx   = null !== $ci ? ( $args[ $ci ] ?? null ) : null;
	$plu   = null !== $pi ? ( $args[ $pi ] ?? null ) : null;
	$key   = ( $ctx ?? '' ) . "\4" . $msgid;
	if ( ! isset( $entries[ $key ] ) ) {
		$entries[ $key ] = array( 'msgid' => $msgid, 'ctx' => $ctx, 'plural' => $plu, 'refs' => array(), 'comments' => array() );
	}
	$entries[ $key ]['refs'][] = "$rel:$line";
	if ( $comment && ! in_array( $comment, $entries[ $key ]['comments'], true ) ) {
		$entries[ $key ]['comments'][] = $comment;
	}
	if ( preg_match( '/%(\d+\$)?[sdfFuxXbceEgGo]/', $msgid . $plu ) && ! $comment ) {
		$missing[] = "$rel:$line  " . substr( $msgid, 0, 70 );
	}
}

function clean_comment( $text ) {
	$text = preg_replace( '#^/\*+|\*+/$|^//#', '', trim( $text ) );
	$text = preg_replace( '#^\s*\*\s?#m', '', $text );
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	return 0 === stripos( $text, 'translators:' ) ? $text : null;
}

// ---------- PHP ----------
function php_string( $tok ) {
	$s = $tok[1];
	if ( "'" === $s[0] ) {
		return strtr( substr( $s, 1, -1 ), array( '\\\\' => '\\', "\\'" => "'" ) );
	}
	return stripcslashes( str_replace( '\\$', '$', substr( $s, 1, -1 ) ) );
}

function scan_php( $file, $rel, $PHP, $domain, &$entries, &$missing ) {
	$t = token_get_all( file_get_contents( $file ) );
	$n = count( $t );
	$last_comment = null; // [text, end line]
	for ( $i = 0; $i < $n; $i++ ) {
		$tok = $t[ $i ];
		if ( is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$c = clean_comment( $tok[1] );
			if ( $c ) {
				$last_comment = array( $c, $tok[2] + substr_count( $tok[1], "\n" ) );
			}
			continue;
		}
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] || ! isset( $PHP[ $tok[1] ] ) ) {
			continue;
		}
		$p = $i - 1;
		while ( $p >= 0 && is_array( $t[ $p ] ) && T_WHITESPACE === $t[ $p ][0] ) {
			$p--;
		}
		if ( $p >= 0 && is_array( $t[ $p ] ) && in_array( $t[ $p ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( is_array( $t[ $j ] ) && T_WHITESPACE === $t[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $t[ $j ] ) {
			continue;
		}
		$line  = $tok[2];
		$args  = array();
		$cur   = array();
		$depth = 0;
		for ( $k = $j; $k < $n; $k++ ) {
			$x = $t[ $k ];
			$s = is_array( $x ) ? $x[1] : $x;
			if ( in_array( $s, array( '(', '[', '{' ), true ) || ( is_array( $x ) && in_array( $x[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			}
			if ( in_array( $s, array( ')', ']', '}' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
			}
			if ( ',' === $s && 1 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			if ( is_array( $x ) && in_array( $x[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && 1 === $depth && ! $cur ) {
				$c = clean_comment( $x[1] );
				if ( $c ) {
					$last_comment = array( $c, $x[2] );
				}
				continue;
			}
			if ( ! ( is_array( $x ) && T_WHITESPACE === $x[0] ) ) {
				$cur[] = $x;
			}
		}
		// Each argument: string literal(s) joined by "." only.
		$vals = array();
		foreach ( $args as $a ) {
			$v  = '';
			$ok = (bool) $a;
			foreach ( $a as $idx => $x ) {
				if ( 0 === $idx % 2 && is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] ) {
					$v .= php_string( $x );
				} elseif ( 1 === $idx % 2 && '.' === $x ) {
					continue;
				} else {
					$ok = false;
					break;
				}
			}
			$vals[] = $ok ? $v : null;
		}
		$comment = ( $last_comment && $last_comment[1] >= $line - 1 ) ? $last_comment[0] : null;
		add_entry( $entries, $missing, $rel, $line, $PHP[ $tok[1] ], $vals, $comment, $domain );

	}
}

// ---------- JS ----------
function scan_js( $file, $rel, $JS, $domain, &$entries, &$missing ) {
	$src = file_get_contents( $file );
	$len = strlen( $src );
	$line = 1;
	$last_comment = null;
	$i = 0;
	$read_string = function ( $q ) use ( &$i, &$line, $src, $len ) {
		$out = '';
		$i++;
		while ( $i < $len && $src[ $i ] !== $q ) {
			if ( '\\' === $src[ $i ] ) {
				$nx = $src[ $i + 1 ];
				$map = array( 'n' => "\n", 't' => "\t", 'r' => "\r", '\\' => '\\', "'" => "'", '"' => '"', '`' => '`' );
				if ( 'u' === $nx ) {
					$out .= mb_chr( hexdec( substr( $src, $i + 2, 4 ) ) );
					$i  += 6;
					continue;
				}
				$out .= $map[ $nx ] ?? $nx;
				$i   += 2;
				continue;
			}
			if ( '`' === $q && '$' === $src[ $i ] && '{' === ( $src[ $i + 1 ] ?? '' ) ) {
				return null; // interpolated template: not extractable
			}
			if ( "\n" === $src[ $i ] ) {
				$line++;
			}
			$out .= $src[ $i ];
			$i++;
		}
		$i++;
		return $out;
	};
	$prev_sig = ''; // previous significant char, for regex detection
	while ( $i < $len ) {
		$c = $src[ $i ];
		if ( "\n" === $c ) {
			$line++;
			$i++;
			continue;
		}
		if ( '/' === $c && '/' === ( $src[ $i + 1 ] ?? '' ) ) {
			$end = strpos( $src, "\n", $i );
			$end = false === $end ? $len : $end;
			$cm  = clean_comment( substr( $src, $i, $end - $i ) );
			if ( $cm ) {
				$last_comment = array( $cm, $line );
			}
			$i = $end;
			continue;
		}
		if ( '/' === $c && '*' === ( $src[ $i + 1 ] ?? '' ) ) {
			$end  = strpos( $src, '*/', $i + 2 ) + 2;
			$text = substr( $src, $i, $end - $i );
			$line += substr_count( $text, "\n" );
			$cm   = clean_comment( $text );
			if ( $cm ) {
				$last_comment = array( $cm, $line );
			}
			$i = $end;
			continue;
		}
		if ( "'" === $c || '"' === $c || '`' === $c ) {
			$read_string( $c );
			$prev_sig = 'a';
			continue;
		}
		if ( '/' === $c && ( '' === $prev_sig || false !== strpos( '(,=:[!&|?{};+-*%<>~^', $prev_sig ) ) ) {
			// Regex literal.
			$i++;
			$cls = false;
			while ( $i < $len ) {
				$ch = $src[ $i ];
				if ( '\\' === $ch ) {
					$i += 2;
					continue;
				}
				if ( '[' === $ch ) {
					$cls = true;
				} elseif ( ']' === $ch ) {
					$cls = false;
				} elseif ( '/' === $ch && ! $cls ) {
					break;
				}
				$i++;
			}
			$i++;
			$prev_sig = 'a';
			continue;
		}
		if ( ( ctype_alpha( $c ) || '_' === $c || '$' === $c ) ) {
			$start = $i;
			while ( $i < $len && ( ctype_alnum( $src[ $i ] ) || '_' === $src[ $i ] || '$' === $src[ $i ] ) ) {
				$i++;
			}
			$word   = substr( $src, $start, $i - $start );
			$before = $start > 0 ? $src[ $start - 1 ] : ' ';
			$prev_sig = 'a';
			if ( ! isset( $JS[ $word ] ) || ctype_alnum( $before ) || '_' === $before || '$' === $before ) {
				continue;
			}
			$k = $i;
			while ( $k < $len && ctype_space( $src[ $k ] ) ) {
				$k++;
			}
			if ( '(' !== ( $src[ $k ] ?? '' ) ) {
				continue;
			}
			// Read arguments: only string literals (with +) are kept; others become null.
			$call_line = $line;
			$i     = $k + 1;
			$vals  = array();
			$cur   = '';
			$ok    = true;
			$empty = true;
			$depth = 0;
			while ( $i < $len ) {
				$ch = $src[ $i ];
				if ( "\n" === $ch ) {
					$line++;
					$i++;
					continue;
				}
				if ( ctype_space( $ch ) || ( '+' === $ch && 0 === $depth ) ) {
					$i++;
					continue;
				}
				if ( '/' === $ch && in_array( $src[ $i + 1 ] ?? '', array( '/', '*' ), true ) ) {
					if ( '*' === $src[ $i + 1 ] ) {
						$end   = strpos( $src, '*/', $i + 2 ) + 2;
						$line += substr_count( substr( $src, $i, $end - $i ), "\n" );
					} else {
						$end = strpos( $src, "\n", $i );
					}
					$i = $end;
					continue;
				}
				if ( 0 === $depth && ( "'" === $ch || '"' === $ch || '`' === $ch ) ) {
					$s = $read_string( $ch );
					if ( null === $s ) {
						$ok = false;
					} else {
						$cur .= $s;
					}
					$empty = false;
					continue;
				}
				if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
					$read_string( $ch );
					continue;
				}
				if ( in_array( $ch, array( '(', '[', '{' ), true ) ) {
					$depth++;
					$ok = false;
				} elseif ( in_array( $ch, array( ')', ']', '}' ), true ) ) {
					if ( 0 === $depth ) {
						$vals[] = ( $ok && ! $empty ) ? $cur : null;
						$i++;
						break;
					}
					$depth--;
				} elseif ( ',' === $ch && 0 === $depth ) {
					$vals[] = ( $ok && ! $empty ) ? $cur : null;
					$cur    = '';
					$ok     = true;
					$empty  = true;
				} else {
					$ok    = false;
					$empty = false;
				}
				$i++;
			}
			$comment = ( $last_comment && $last_comment[1] >= $call_line - 1 ) ? $last_comment[0] : null;
			add_entry( $entries, $missing, $rel, $call_line, $JS[ $word ], $vals, $comment, $domain );

			$prev_sig = ')';
			continue;
		}
		if ( ! ctype_space( $c ) ) {
			$prev_sig = ( ctype_digit( $c ) ) ? 'a' : $c;
		}
		$i++;
	}
}

function po( $s ) {
	$s = addcslashes( $s, "\\\"\t" );
	if ( false === strpos( $s, "\n" ) ) {
		return '"' . $s . '"';
	}
	$parts = explode( "\n", $s );
	$last  = count( $parts ) - 1;
	$lines = array( '""' );
	foreach ( $parts as $idx => $p ) {
		$seg = $p . ( $idx < $last ? '\n' : '' );
		if ( '' !== $seg ) {
			$lines[] = '"' . $seg . '"';
		}
	}
	return implode( "\n", $lines );
}

/**
 * Runs the whole build. Kept in a function so nothing leaks into the global scope.
 *
 * @param string[] $argv Command line arguments.
 * @return void
 */
function run( array $argv ) {
	[$_, $dir, $domain, $out] = $argv;
	$dir     = rtrim( realpath( $dir ), '/' );
	$exclude = array_merge( array( 'vendor', 'node_modules', 'bin', 'tests', 'languages', '.git' ), isset( $argv[4] ) ? explode( ',', $argv[4] ) : array() );

	// fn => [ text arg positions: singular, plural, context, domain ] (0-based, null if none)
	$PHP = array(
		'__' => array( 0, null, null, 1 ), '_e' => array( 0, null, null, 1 ), 'esc_html__' => array( 0, null, null, 1 ), 'esc_html_e' => array( 0, null, null, 1 ),
		'esc_attr__' => array( 0, null, null, 1 ), 'esc_attr_e' => array( 0, null, null, 1 ), '_x' => array( 0, null, 1, 2 ), '_ex' => array( 0, null, 1, 2 ),
		'esc_html_x' => array( 0, null, 1, 2 ), 'esc_attr_x' => array( 0, null, 1, 2 ), '_n' => array( 0, 1, null, 3 ), '_nx' => array( 0, 1, 3, 4 ),
		'_n_noop' => array( 0, 1, null, 2 ), '_nx_noop' => array( 0, 1, 2, 3 ),
	);
	$JS = array( '__' => array( 0, null, null, 1 ), '_x' => array( 0, null, 1, 2 ), '_n' => array( 0, 1, null, 3 ), '_nx' => array( 0, 1, 3, 4 ) );

	$entries = array();
	$missing = array();

	// ---------- Walk ----------
	$files = array();
	$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = substr( $f->getPathname(), strlen( $dir ) + 1 );
		foreach ( $exclude as $ex ) {
			if ( 0 === strpos( $rel, $ex . '/' ) || false !== strpos( $rel, '/' . $ex . '/' ) ) {
				continue 2;
			}
		}
		if ( preg_match( '/\.php$/', $rel ) || ( preg_match( '/\.js$/', $rel ) && ! preg_match( '/\.min\.js$/', $rel ) ) ) {
			$files[] = $rel;
		}
	}
	sort( $files );
	// ---------- Plugin header ----------
	$main   = basename( $dir ) . '.php';
	$head   = file_get_contents( "$dir/$main", false, null, 0, 8192 );
	$header = array();
	foreach ( array( 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ) as $field ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $m ) && trim( $m[1] ) ) {
			$header[ $field ] = trim( $m[1] );
		}
	}
	preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $vm );
	$version = trim( $vm[1] ?? '' );

	foreach ( $header as $field => $value ) {
		$entries[ "\4" . $value ] = array( 'msgid' => $value, 'ctx' => null, 'plural' => null, 'refs' => array( $main ), 'comments' => array( "$field of the plugin" ) );
	}

	foreach ( $files as $rel ) {
		if ( str_ends_with( $rel, '.php' ) ) {
			scan_php( "$dir/$rel", $rel, $PHP, $domain, $entries, $missing );
		} else {
			scan_js( "$dir/$rel", $rel, $JS, $domain, $entries, $missing );
		}
	}

	$name = $header['Plugin Name'] ?? $domain;
	$year = gmdate( 'Y' );
	$o    = "# Copyright (C) $year " . ( $header['Author'] ?? '' ) . "\n# This file is distributed under the GPL-2.0-or-later.\nmsgid \"\"\nmsgstr \"\"\n";
	$o   .= "\"Project-Id-Version: $name $version\\n\"\n";
	$o   .= '"Report-Msgid-Bugs-To: ' . ( 'tagwright-seo' === $domain ? "https://wordpress.org/support/plugin/$domain" : ( $header['Plugin URI'] ?? '' ) ) . "\\n\"\n";
	$o   .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n\"MIME-Version: 1.0\\n\"\n";
	$o   .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
	$o   .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d\TH:i:s+00:00' ) . "\\n\"\n\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
	$o   .= "\"X-Generator: Tagwright SEO make-pot\\n\"\n\"X-Domain: $domain\\n\"\n";


	foreach ( $entries as $e ) {
		$o .= "\n";
		foreach ( $e['comments'] as $c ) {
			$o .= "#. $c\n";
		}
		foreach ( array_chunk( $e['refs'], 4 ) as $chunk ) {
			$o .= '#: ' . implode( ' ', $chunk ) . "\n";
		}
		if ( preg_match( '/%(\d+\$)?[sdf]/', $e['msgid'] . $e['plural'] ) ) {
			$o .= preg_match( '/\.js:\d+$/', $e['refs'][0] ) && ! preg_grep( '/\.php:\d+$/', $e['refs'] ) ? "#, js-format\n" : "#, php-format\n";
		}
		if ( null !== $e['ctx'] ) {
			$o .= 'msgctxt ' . po( $e['ctx'] ) . "\n";
		}
		$o .= 'msgid ' . po( $e['msgid'] ) . "\n";
		if ( null !== $e['plural'] ) {
			$o .= 'msgid_plural ' . po( $e['plural'] ) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
		} else {
			$o .= "msgstr \"\"\n";
		}
	}
	file_put_contents( $out, $o );

	// Plain CLI output, there is no WordPress loaded here to escape for a browser.
	fprintf( STDOUT, '%s', basename( $out ) . ': ' . count( $entries ) . ' strings from ' . count( $files ) . " files\n" );
	fprintf( STDOUT, '%s', 'placeholders without translator note: ' . count( $missing ) . "\n" . implode( "\n", array_slice( $missing, 0, 40 ) ) . "\n" );
}

run( $argv );
