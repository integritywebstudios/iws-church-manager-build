<?php
/**
 * Build rewrites for the prefixed PDF library, run by bin/vendor-pdf.sh on its staging copy. Not shipped.
 *
 *   php rewrite.php code <stage> <notes.json>
 *     WordPress.org review 3:
 *     1. Every heredoc/nowdoc becomes a quoted string with the same value: a heredoc becomes a double-quoted string (same
 *        escapes and interpolation; only " is escaped), a nowdoc a single-quoted string (\ and ' escaped). The closing
 *        marker must be unindented and the body must hold no \" sequence, no trailing backslash and no CR, so the value
 *        cannot change; anything else stops the build. Exactly HEREDOCS are expected, and none may remain.
 *     2. dompdf's global variables are renamed: $_dompdf_warnings and friends become $_iwscm_dompdf_* (exactly GLOBALS
 *        occurrences), and no global $_dompdf_* / $_DOMPDF_* may remain.
 *     3. Round 3b: diagnostic output is removed. Every if-statement whose condition is a debug switch (the generic
 *        global debug constant, the debug-CSS/debug-PNG options, a class DEBUG constant) is deleted with its body, the
 *        flag variables are removed, and debug-only comment lines are dropped. Then every file is checked: no echo,
 *        print, printf-family or dump call may remain except the exact allow-listed statements that write the PDF
 *        bytes (IWSCM_OUTPUT_ALLOW), and none of the removed switch names may appear anywhere, comments included.
 *        The build fails if any of them is found, or if an expected count changes.
 *     Each changed file gets a dated note in notes.json.
 *
 *   php rewrite.php notices <stage> <notes.json> <list out>
 *     LGPL-2.1 section 2(b) / LGPL-3.0: every shipped PHP file (all are modified: Strauss prefix and direct-request guard)
 *     gets a dated "Modified by Integrity Web Studios" notice after its opening tag, followed by each of its own notes.
 *     The list of every note is written for vendor-pdf/README.txt.
 */

const IWSCM_REWRITE_DATE = '2026-11-20';
const IWSCM_HEREDOCS     = 4;
const IWSCM_GLOBALS      = 11;
// Round 3b counts: debug-switch if-statements removed, and flag-variable assignments removed.
const IWSCM_DEBUG_IFS    = 115;
const IWSCM_DEBUG_ASSIGN = 7;

// The only output statements that may remain: they write the PDF document itself (Dompdf::stream and Cpdf::stream).
// Statement text without whitespace => number of times it must occur in that file.
const IWSCM_OUTPUT_ALLOW = array(
	'dompdf/dompdf/lib/Cpdf.php'                => array( 'echo$tmp;' => 1 ),
	'dompdf/php-svg-lib/src/Svg/Surface/CPdf.php' => array( 'echo$tmp;' => 1 ),
	'dompdf/dompdf/src/Adapter/CPDF.php'        => array( 'echo$tmp;' => 1 ),
	'dompdf/dompdf/src/Adapter/PDFLib.php'      => array( 'echo$data;' => 1, 'echofread($fh,$chunk);' => 1 ),
);
// A debug switch: the generic global constant, the dompdf debug options, or a class DEBUG constant.
const IWSCM_DEBUG_COND   = '/DEBUGPNG|DEBUGKEEPIMAGE|DEBUG_LAYOUT|\$DEBUGCSS\b|\$debug_png\b|\$debugPng\b|getDebugPng\(\)|getDebugCss\(\)|self::DEBUG\b/';
// Names and calls that must not remain anywhere in the shipped PDF library (raw text, comments included).
const IWSCM_BANNED_TEXT  = '/\b(?:print_r|var_dump|var_export|debug_zval_dump|debug_print_backtrace|fprintf)\b|\bSTDOUT\b|\bSTDERR\b|DEBUGPNG|DEBUGKEEPIMAGE|DEBUG_LAYOUT|DEBUGCSS|\$debug_png\b/';
const IWSCM_BANNED_CALLS = array( 'print_r', 'var_dump', 'var_export', 'printf', 'vprintf', 'fprintf', 'vfprintf', 'debug_zval_dump', 'debug_print_backtrace' );

function iwscm_fail( $msg ) {
	fwrite( STDERR, "FAIL $msg\n" );
	exit( 1 );
}

function iwscm_php_files( $stage ) {
	$out = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( '.php' === substr( $f->getPathname(), -4 ) ) {
			$out[] = substr( $f->getPathname(), strlen( $stage ) + 1 );
		}
	}
	sort( $out );
	return $out;
}

function iwscm_note( &$notes, $file, $note ) {
	$notes[ $file ][] = array( IWSCM_REWRITE_DATE, $note . ' (tools/pdf/rewrite.php)' );
}

/** Rewrites every heredoc/nowdoc in $code; returns the new code and adds one entry per rewrite to $done. */
function iwscm_unheredoc( $code, $file, &$done ) {
	$tokens = token_get_all( $code );
	$pos    = 0;
	$open   = null;
	$func   = null;
	$want   = false;
	$edits  = array();
	foreach ( $tokens as $t ) {
		$text = is_array( $t ) ? $t[1] : $t;
		if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
			$want = true;
		} elseif ( $want && is_array( $t ) && T_STRING === $t[0] ) {
			$func = $t[1];
			$want = false;
		} elseif ( $want && '(' === $t ) {
			$want = false;
		}
		if ( is_array( $t ) && T_START_HEREDOC === $t[0] ) {
			if ( null !== $open ) {
				iwscm_fail( "$file: nested heredoc at line {$t[2]}" );
			}
			$open = array( $pos, $text, $t[2], $func );
		} elseif ( is_array( $t ) && T_END_HEREDOC === $t[0] ) {
			if ( null === $open ) {
				iwscm_fail( "$file: heredoc end without a start at line {$t[2]}" );
			}
			list( $start, $head, $line, $in ) = $open;
			if ( ! preg_match( '/^<<<[ \t]*(\'?)([A-Za-z_][A-Za-z0-9_]*)\1\n$/', $head, $m ) && ! preg_match( '/^<<<[ \t]*(")([A-Za-z_][A-Za-z0-9_]*)"\n$/', $head, $m ) ) {
				iwscm_fail( "$file:$line: unexpected heredoc opening " . json_encode( $head ) );
			}
			if ( $text !== $m[2] ) {
				iwscm_fail( "$file:$line: the closing marker is indented or differs (" . json_encode( $text ) . ')' );
			}
			$body = substr( $code, $start + strlen( $head ), $pos - $start - strlen( $head ) );
			if ( '' === $body || "\n" !== substr( $body, -1 ) ) {
				iwscm_fail( "$file:$line: empty heredoc or no newline before the closing marker" );
			}
			$body = substr( $body, 0, -1 );
			if ( false !== strpos( $body, "\r" ) || '\\' === substr( $body, -1 ) ) {
				iwscm_fail( "$file:$line: heredoc body has a CR or ends in a backslash" );
			}
			if ( "'" === $m[1] ) {
				$quoted = "'" . strtr( $body, array( '\\' => '\\\\', "'" => "\\'" ) ) . "'";
				$kind   = 'nowdoc';
			} else {
				if ( false !== strpos( $body, '\\"' ) ) {
					iwscm_fail( "$file:$line: heredoc body has a \\\" sequence" );
				}
				$quoted = '"' . str_replace( '"', '\\"', $body ) . '"';
				$kind   = 'heredoc';
			}
			$edits[] = array( $start, $pos + strlen( $text ), $quoted );
			$done[]  = "$kind in " . ( null === $in ? 'file scope' : "$in()" );
			$open    = null;
		}
		$pos += strlen( $text );
	}
	foreach ( array_reverse( $edits ) as $e ) {
		$code = substr( $code, 0, $e[0] ) . $e[2] . substr( $code, $e[1] );
	}
	return $code;
}

/** Splits $code into [id|null, text, byte position] triples. */
function iwscm_tokens( $code ) {
	$out = array();
	$pos = 0;
	foreach ( token_get_all( $code ) as $t ) {
		$text  = is_array( $t ) ? $t[1] : $t;
		$out[] = array( is_array( $t ) ? $t[0] : null, $text, $pos );
		$pos  += strlen( $text );
	}
	return $out;
}

function iwscm_next_sig( $T, $i ) {
	$n = count( $T );
	while ( $i < $n && in_array( $T[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
		++$i;
	}
	return $i;
}

function iwscm_prev_sig( $T, $i ) {
	while ( $i >= 0 && in_array( $T[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
		--$i;
	}
	return $i;
}

/** Removes every if-statement whose condition is a debug switch, with its body; returns the new code and the count. */
function iwscm_strip_debug_ifs( $code, $file, &$count ) {
	$T     = iwscm_tokens( $code );
	$n     = count( $T );
	$edits = array();
	$skip  = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_IF !== $T[ $i ][0] || $i < $skip ) {
			continue;
		}
		$j = iwscm_next_sig( $T, $i + 1 );
		if ( '(' !== $T[ $j ][1] ) {
			iwscm_fail( "$file: an if without a condition at token $i" );
		}
		$depth = 0;
		$cond  = '';
		for ( $k = $j; $k < $n; $k++ ) {
			$cond .= $T[ $k ][1];
			if ( '(' === $T[ $k ][1] ) {
				++$depth;
			} elseif ( ')' === $T[ $k ][1] && 0 === --$depth ) {
				break;
			}
		}
		if ( ! preg_match( IWSCM_DEBUG_COND, $cond ) ) {
			continue;
		}
		$prev = iwscm_prev_sig( $T, $i - 1 );
		if ( $prev >= 0 && ! in_array( $T[ $prev ][1], array( ';', '{', '}', ':' ), true ) && T_OPEN_TAG !== $T[ $prev ][0] ) {
			iwscm_fail( "$file: a debug if is not a plain statement (after " . json_encode( $T[ $prev ][1] ) . '): ' . trim( $cond ) );
		}
		$b = iwscm_next_sig( $T, $k + 1 );
		if ( '{' === $T[ $b ][1] ) {
			$depth = 0;
			for ( $e = $b; $e < $n; $e++ ) {
				if ( ( null === $T[ $e ][0] && '{' === $T[ $e ][1] ) || T_CURLY_OPEN === $T[ $e ][0] || T_DOLLAR_OPEN_CURLY_BRACES === $T[ $e ][0] ) {
					++$depth;
				} elseif ( '}' === $T[ $e ][1] && 0 === --$depth ) {
					break;
				}
			}
		} else {
			for ( $e = $b; $e < $n && ';' !== $T[ $e ][1]; $e++ ) {
				if ( null === $T[ $e ][0] && '{' === $T[ $e ][1] ) {
					iwscm_fail( "$file: a braceless debug if holds a block: " . trim( $cond ) );
				}
			}
		}
		$after = iwscm_next_sig( $T, $e + 1 );
		if ( $after < $n && in_array( $T[ $after ][0], array( T_ELSE, T_ELSEIF ), true ) ) {
			iwscm_fail( "$file: a debug if has an else branch: " . trim( $cond ) );
		}
		$start = $T[ $i ][2];
		$end   = $T[ $e ][2] + strlen( $T[ $e ][1] );
		// Take the whole line when the statement is alone on it.
		$ls = strrpos( substr( $code, 0, $start ), "\n" );
		$ls = false === $ls ? 0 : $ls + 1;
		if ( '' === trim( substr( $code, $ls, $start - $ls ) ) ) {
			$start = $ls;
			if ( preg_match( '/\G[ \t]*\n/', $code, $m, 0, $end ) ) {
				$end += strlen( $m[0] );
			}
		}
		$edits[] = array( $start, $end );
		$skip    = $e + 1;
		++$count;
	}
	foreach ( array_reverse( $edits ) as $e ) {
		$code = substr( $code, 0, $e[0] ) . substr( $code, $e[1] );
	}
	return $code;
}

/** After the build's changes: no output statement, dump call or removed switch name may remain. Returns the allow-list uses. */
function iwscm_verify_no_diagnostics( $code, $file, &$allowed ) {
	if ( preg_match( IWSCM_BANNED_TEXT, $code, $m, PREG_OFFSET_CAPTURE ) ) {
		$line = substr_count( substr( $code, 0, $m[0][1] ), "\n" ) + 1;
		iwscm_fail( "$file:$line: a removed diagnostic name or call remains: " . trim( $m[0][0] ) );
	}
	$T = iwscm_tokens( $code );
	$n = count( $T );
	for ( $i = 0; $i < $n; $i++ ) {
		$id = $T[ $i ][0];
		if ( T_OPEN_TAG_WITH_ECHO === $id || T_PRINT === $id ) {
			iwscm_fail( "$file: a " . trim( $T[ $i ][1] ) . ' output statement remains' );
		}
		if ( T_ECHO === $id ) {
			$stmt = '';
			for ( $k = $i; $k < $n && ';' !== $T[ $k ][1]; $k++ ) {
				if ( ! in_array( $T[ $k ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
					$stmt .= $T[ $k ][1];
				}
			}
			$stmt .= ';';
			if ( ! isset( IWSCM_OUTPUT_ALLOW[ $file ][ $stmt ] ) ) {
				iwscm_fail( "$file: an echo statement that is not the PDF body output remains: $stmt" );
			}
			$allowed[ $file ][ $stmt ] = ( $allowed[ $file ][ $stmt ] ?? 0 ) + 1;
		}
		if ( T_STRING === $id && in_array( strtolower( $T[ $i ][1] ), IWSCM_BANNED_CALLS, true ) ) {
			$nx = iwscm_next_sig( $T, $i + 1 );
			$pv = iwscm_prev_sig( $T, $i - 1 );
			if ( '(' === ( $T[ $nx ][1] ?? '' ) && ! in_array( $T[ $pv ][0] ?? null, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				iwscm_fail( "$file: a call to {$T[ $i ][1]}() remains" );
			}
		}
	}
}

$mode  = $argv[1] ?? '';
$stage = rtrim( $argv[2] ?? '', '/' );
$store = $argv[3] ?? '';
if ( ! is_dir( $stage ) || ! is_file( $store ) ) {
	iwscm_fail( 'usage: php rewrite.php code|notices <stage> <notes.json> [<list out>]' );
}
$notes = json_decode( (string) file_get_contents( $store ), true );
if ( ! is_array( $notes ) ) {
	iwscm_fail( "$store is not JSON" );
}

if ( 'code' === $mode ) {
	$heredocs = 0;
	$globals  = 0;
	$dbg_ifs  = 0;
	$dbg_asg  = 0;
	$allowed  = array();
	foreach ( iwscm_php_files( $stage ) as $file ) {
		$path = "$stage/$file";
		$code = (string) file_get_contents( $path );
		$new  = $code;

		$done = array();
		$new  = iwscm_unheredoc( $new, $file, $done );
		foreach ( array_count_values( $done ) as $what => $n ) {
			iwscm_note( $notes, $file, ( 1 === $n ? "the $what" : "$n {$what}s" ) . ' rewritten as an identical quoted string (no heredoc/nowdoc syntax).' );
		}
		$heredocs += count( $done );
		foreach ( token_get_all( $new ) as $t ) {
			if ( is_array( $t ) && T_START_HEREDOC === $t[0] ) {
				iwscm_fail( "$file: a heredoc remains at line {$t[2]}" );
			}
		}

		$n   = 0;
		$new = preg_replace( '/\$_dompdf_(warnings|show_warnings|debug)\b/', '\$_iwscm_dompdf_$1', $new, -1, $n );
		if ( $n ) {
			iwscm_note( $notes, $file, 'dompdf\'s global variables renamed to $_iwscm_dompdf_* (not shared with any other copy of dompdf).' );
		}
		$globals += $n;
		if ( preg_match( '/^\s*global\s[^;]*\$_(dompdf|DOMPDF)_/mi', $new, $m ) ) {
			iwscm_fail( "$file: an unprefixed dompdf global remains: " . trim( $m[0] ) );
		}

		// Round 3b: debug-switch branches, flag variables and debug-only comment lines.
		$n   = 0;
		$new = iwscm_strip_debug_ifs( $new, $file, $n );
		if ( $n ) {
			iwscm_note( $notes, $file, ( 1 === $n ? 'a diagnostic branch' : "$n diagnostic branches" ) . ' (output printed under a debug switch) removed; the PDF output is unchanged.' );
		}
		$dbg_ifs += $n;
		$n   = 0;
		$new = preg_replace( '/^[ \t]*\$(?:DEBUGCSS|debug_png) = [^;\n]*;\n/m', '', $new, -1, $n );
		$dbg_asg += $n;
		$new = preg_replace( '/^[ \t]*(?:\/\/[ \t]*(?:debugpng|DEBUG_IMG_TEMP)[ \t]*|\/\*[ \t]*DEBUGCSS[^\n*]*\*\/[ \t]*|\/\/(?:.*(?:print_r\(|var_dump\(|var_export\(|debug_zval_dump\(|DEBUGPNG|DEBUGCSS|fprintf|STDOUT)|[ \t]*(?:print|echo)\b).*)\n/mi', '', $new );
		iwscm_verify_no_diagnostics( $new, $file, $allowed );

		if ( $new !== $code ) {
			file_put_contents( $path, $new );
		}
	}
	foreach ( IWSCM_OUTPUT_ALLOW as $file => $stmts ) {
		foreach ( $stmts as $stmt => $want ) {
			if ( ( $allowed[ $file ][ $stmt ] ?? 0 ) !== $want ) {
				iwscm_fail( "$file: expected $want of the PDF output statement $stmt, found " . ( $allowed[ $file ][ $stmt ] ?? 0 ) );
			}
		}
	}
	if ( IWSCM_DEBUG_IFS !== $dbg_ifs ) {
		iwscm_fail( 'expected ' . IWSCM_DEBUG_IFS . " debug-switch branches to remove, found $dbg_ifs" );
	}
	if ( IWSCM_DEBUG_ASSIGN !== $dbg_asg ) {
		iwscm_fail( 'expected ' . IWSCM_DEBUG_ASSIGN . " debug flag assignments to remove, found $dbg_asg" );
	}
	if ( IWSCM_HEREDOCS !== $heredocs ) {
		iwscm_fail( "expected " . IWSCM_HEREDOCS . " heredocs/nowdocs to rewrite, found $heredocs" );
	}
	if ( IWSCM_GLOBALS !== $globals ) {
		iwscm_fail( "expected " . IWSCM_GLOBALS . " dompdf global variable uses to rename, found $globals" );
	}
	file_put_contents( $store, json_encode( $notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	exit( 0 );
}

if ( 'notices' === $mode && isset( $argv[4] ) ) {
	$list  = '';
	$files = iwscm_php_files( $stage );
	foreach ( array_keys( $notes ) as $file ) {
		if ( ! in_array( $file, $files, true ) ) {
			iwscm_fail( "a note names $file, which is not shipped" );
		}
	}
	foreach ( $files as $file ) {
		$path = "$stage/$file";
		$code = (string) file_get_contents( $path );
		if ( 0 !== strpos( $code, "<?php\n" ) ) {
			iwscm_fail( "$file does not start with an opening tag line" );
		}
		$head = '// Modified by Integrity Web Studios for IWS Church Manager, ' . IWSCM_REWRITE_DATE . ': namespaced under IWSCM\\Vendor\\Pdf\\ (Strauss) and a direct-access guard added; every change is listed in vendor-pdf/README.txt.' . "\n";
		foreach ( $notes[ $file ] ?? array() as $n ) {
			$head .= "// Modified by Integrity Web Studios, {$n[0]}: {$n[1]}\n";
			$list .= "    {$n[0]} $file: {$n[1]}\n";
		}
		file_put_contents( $path, "<?php\n" . $head . substr( $code, 6 ) );
	}
	file_put_contents( $argv[4], $list );
	exit( 0 );
}

iwscm_fail( 'usage: php rewrite.php code|notices <stage> <notes.json> [<list out>]' );
