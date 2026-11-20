#!/bin/bash
#
# Build the private, prefixed PDF library (docs/statement-pdf-design.md §2.3).
#
# Consumes committed inputs only (tools/pdf):
#   composer.json + composer.lock  dompdf and every dependency at exact versions;
#   archives.sha256                 the SHA-256 of each upstream dist archive (checked before use);
#   strauss.sha256                  the pinned Strauss release .phar, its version, URL and SHA-256 (dev-only, never shipped);
#   strauss.json                    the Strauss config (prefix IWSCM\Vendor\Pdf\, target vendor-pdf);
#   patches.php                     the build patches (WordPress.org reviews 1 and 3): exact, counted edits, each with its dated change notice;
#   rewrite.php                     review 3: heredocs/nowdocs to identical quoted strings, dompdf's globals renamed, and the
#                                   dated "Modified by Integrity Web Studios" notice on every PHP file (LGPL-2.1 section 2(b));
#   dejavu-fonts.LICENSE            the DejaVu fonts licence (upstream LICENSE at tag version_2_37, the shipped fonts' version);
#   gpl-3.0.txt                     the GNU GPL 3.0 text (https://www.gnu.org/licenses/gpl-3.0.txt), shipped next to
#                                   php-svg-lib's LGPL-3.0, which is a set of additional permissions on top of it.
# Composer is not run: each archive is downloaded from the URL in composer.lock (or read from the cache),
# verified, unpacked, and Strauss prefixes it. Only the allow-listed runtime files are kept, plus README.txt (versions,
# hashes and every modification) and this build's composer.json, composer.lock and strauss.json. The manifest (every
# shipped path and its hash) is written outside the shipped tree (--manifest).
#
# Usage: bin/vendor-pdf.sh [--inputs <dir>] [--out <dir>] [--manifest <file>]
#   --inputs    default tools/pdf (bin/check-vendor.sh passes the inputs exported at a commit)
#   --out       default plugin/iws-church-manager/vendor-pdf (replaced only when the build succeeds)
#   --manifest  default bin/vendor-pdf.manifest next to this script (committed; bin/check-vendor.sh checks a zip against
#               the copy committed at the release SHA)
#   IWSCM_PDF_CACHE  download cache (default ~/.cache/iwscm-pdf); a cached file is re-verified on every use.
#   IWSCM_PDF_OFFLINE  set (tests only) to never download: a cached file is checked as it is, so a wrong pin fails locally.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IN="$ROOT/tools/pdf"
OUT="$ROOT/plugin/iws-church-manager/vendor-pdf"
MANIFEST="$ROOT/bin/vendor-pdf.manifest"
CACHE="${IWSCM_PDF_CACHE:-$HOME/.cache/iwscm-pdf}"

while [ $# -gt 0 ]; do
	case "$1" in
		--inputs) IN="${2:?--inputs needs a directory}"; shift 2 ;;
		--out) OUT="${2:?--out needs a directory}"; shift 2 ;;
		--manifest) MANIFEST="${2:?--manifest needs a file}"; shift 2 ;;
		*) echo "Unknown argument: $1" >&2; exit 1 ;;
	esac
done

for f in composer.json composer.lock archives.sha256 strauss.sha256 strauss.json patches.php rewrite.php dejavu-fonts.LICENSE gpl-3.0.txt; do
	[ -f "$IN/$f" ] || { echo "FAIL missing input $IN/$f" >&2; exit 1; }
done

sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
fetch() { # fetch <url> <file> <sha256>
	if [ -n "${IWSCM_PDF_OFFLINE:-}" ] && [ -f "$2" ]; then
		: # Offline (the vendor gate's cache): never download; the check below decides.
	elif [ ! -f "$2" ] || [ "$(sha "$2")" != "$3" ]; then
		rm -f "$2"
		curl -fsSL --proto '=https' --tlsv1.2 -o "$2.part" "$1" || { echo "FAIL could not download $1" >&2; return 1; }
		mv "$2.part" "$2"
	fi
	if [ "$(sha "$2")" != "$3" ]; then
		echo "FAIL $1: SHA-256 $(sha "$2") is not the pinned $3" >&2
		rm -f "$2"
		return 1
	fi
}

mkdir -p "$CACHE"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# 1. Strauss: "<sha256>  strauss.phar <version> <url>".
read -r STRAUSS_SHA STRAUSS_NAME STRAUSS_VERSION STRAUSS_URL < "$IN/strauss.sha256"
if [[ ! "$STRAUSS_SHA" =~ ^[0-9a-f]{64}$ ]] || [ "$STRAUSS_NAME" != "strauss.phar" ] || [[ ! "$STRAUSS_URL" =~ ^https://github\.com/BrianHenryIE/strauss/releases/download/ ]]; then
	echo "FAIL strauss.sha256 is not \"<sha256>  strauss.phar <version> <release url>\"" >&2
	exit 1
fi
STRAUSS="$CACHE/strauss-$STRAUSS_VERSION-$STRAUSS_SHA.phar"
fetch "$STRAUSS_URL" "$STRAUSS" "$STRAUSS_SHA"

# 2. The archives named by composer.lock, each verified against archives.sha256 before it is unpacked.
php -r '
$lock = json_decode( file_get_contents( $argv[1] ), true );
foreach ( $lock["packages"] as $p ) {
	echo $p["name"], " ", $p["version"], " ", $p["dist"]["url"], "\n";
}
if ( ! empty( $lock["packages-dev"] ) ) {
	fwrite( STDERR, "FAIL composer.lock has dev packages\n" );
	exit( 1 );
}
' "$IN/composer.lock" > "$WORK/packages.txt"

mkdir -p "$WORK/project/vendor/composer"
while read -r name version url; do
	want="$(awk -v n="$name@$version" '$2 == n { print $1 }' "$IN/archives.sha256")"
	if [[ ! "$want" =~ ^[0-9a-f]{64}$ ]]; then
		echo "FAIL archives.sha256 has no SHA-256 for $name@$version" >&2
		exit 1
	fi
	if [[ ! "$url" =~ ^https://api\.github\.com/repos/ ]]; then
		echo "FAIL $name@$version: unexpected dist URL $url" >&2
		exit 1
	fi
	zip="$CACHE/${name//\//_}-$version-$want.zip"
	fetch "$url" "$zip" "$want"
	mkdir -p "$WORK/x/$name" "$WORK/project/vendor/$name"
	unzip -q "$zip" -d "$WORK/x/$name"
	top="$(find "$WORK/x/$name" -mindepth 1 -maxdepth 1)"
	if [ "$(printf '%s\n' "$top" | grep -c .)" != 1 ] || [ ! -d "$top" ]; then
		echo "FAIL $name@$version: the archive does not hold exactly one top directory" >&2
		exit 1
	fi
	cp -R "$top/." "$WORK/project/vendor/$name/"
done < "$WORK/packages.txt"
if [ "$(wc -l < "$WORK/packages.txt" | tr -d ' ')" != "$(grep -c . "$IN/archives.sha256")" ]; then
	echo "FAIL archives.sha256 does not list exactly the packages in composer.lock" >&2
	exit 1
fi

# 3. The project Strauss reads: composer.json with the committed config, the lock, and installed.json from the lock.
php -r '
$json  = json_decode( file_get_contents( $argv[1] ), true );
$conf  = json_decode( file_get_contents( $argv[2] ), true );
$lock  = json_decode( file_get_contents( $argv[3] ), true );
if ( ! is_array( $json ) || ! is_array( $conf ) || ! is_array( $lock ) ) {
	fwrite( STDERR, "FAIL composer.json, strauss.json or composer.lock is not JSON\n" );
	exit( 1 );
}
$json["extra"]["strauss"] = $conf;
file_put_contents( $argv[4] . "/composer.json", json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
$installed = array( "packages" => array(), "dev" => false, "dev-package-names" => array() );
foreach ( $lock["packages"] as $p ) {
	$p["install-path"]       = "../" . $p["name"];
	$installed["packages"][] = $p;
}
file_put_contents( $argv[4] . "/vendor/composer/installed.json", json_encode( $installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
' "$IN/composer.json" "$IN/strauss.json" "$IN/composer.lock" "$WORK/project"
cp "$IN/composer.lock" "$WORK/project/composer.lock"

( cd "$WORK/project" && php "$STRAUSS" dependencies --silent > "$WORK/strauss.log" 2>&1 ) || { cat "$WORK/strauss.log" >&2; echo "FAIL Strauss did not complete" >&2; exit 1; }
PREFIXED="$WORK/project/vendor-pdf"
[ -d "$PREFIXED/dompdf/dompdf/src" ] || { echo "FAIL Strauss produced no dompdf/dompdf/src" >&2; exit 1; }

# Strauss cannot rewrite a class name built inside an interpolated string, nor code that reads a
# namespace segment by position. Each such spot is listed here and rewritten exactly once; a missing
# or repeated match fails the build.
php -r '
$fixes = array(
	array( "dompdf/php-font-lib/src/FontLib/TrueType/File.php", "\"FontLib\\\\\$type\\\\TableDirectoryEntry\"", "\"IWSCM\\\\Vendor\\\\Pdf\\\\FontLib\\\\\$type\\\\TableDirectoryEntry\"" ),
	// FontLib\TrueType\File → "TrueType": the segment before the class, wherever the prefix puts it.
	array( "dompdf/php-font-lib/src/FontLib/TrueType/File.php", "return \$class_parts[1];", "return \$class_parts[count(\$class_parts) - 2];" ),
);
foreach ( $fixes as $fix ) {
	$file = array_shift( $fix );
	$path = $argv[1] . "/" . $file;
	$code = (string) file_get_contents( $path );
	if ( 1 !== substr_count( $code, $fix[0] ) ) {
		fwrite( STDERR, "FAIL expected one $fix[0] in $file\n" );
		exit( 1 );
	}
	file_put_contents( $path, str_replace( $fix[0], $fix[1], $code ) );
}
' "$PREFIXED"

# 4. The allow-list: what dompdf needs at run time, and the licences. Fonts: DejaVu Sans Regular and Bold only.
STAGE="$WORK/stage"
mkdir -p "$STAGE"
keep() { # keep <path relative to the package root> <package> [source root]
	local from="${3:-$PREFIXED/$2}"
	[ -e "$from/$1" ] || { echo "FAIL $2/$1 is missing" >&2; exit 1; }
	mkdir -p "$STAGE/$2/$(dirname "$1")"
	cp -R "$from/$1" "$STAGE/$2/$1"
}
keep src dompdf/dompdf
# eval() in PhpEvaluator.php is not allowed on WordPress.org; PHP in documents is off (statement-pdf.php), so dompdf never loads it.
rm -f "$STAGE/dompdf/dompdf/src/PhpEvaluator.php"
keep lib/Cpdf.php dompdf/dompdf
keep lib/res/html.css dompdf/dompdf "$WORK/project/vendor/dompdf/dompdf"
for font in DejaVuSans.ttf DejaVuSans.ufm DejaVuSans-Bold.ttf DejaVuSans-Bold.ufm; do
	keep "lib/fonts/$font" dompdf/dompdf "$WORK/project/vendor/dompdf/dompdf"
done
keep VERSION dompdf/dompdf "$WORK/project/vendor/dompdf/dompdf"
keep LICENSE.LGPL dompdf/dompdf "$WORK/project/vendor/dompdf/dompdf"
# The dompdf archive ships the DejaVu fonts without their licence: the upstream text (dejavu-fonts tag version_2_37
# LICENSE, the version in the shipped .ttf name tables), committed and pinned here, goes next to the fonts.
DEJAVU_LICENCE_SHA=7a083b136e64d064794c3419751e5c7dd10d2f64c108fe5ba161eae5e5958a93
[ "$(sha "$IN/dejavu-fonts.LICENSE")" = "$DEJAVU_LICENCE_SHA" ] || { echo "FAIL dejavu-fonts.LICENSE is not the pinned upstream text ($DEJAVU_LICENCE_SHA)" >&2; exit 1; }
cp "$IN/dejavu-fonts.LICENSE" "$STAGE/dompdf/dompdf/lib/fonts/LICENSE-DejaVu.txt"
keep src dompdf/php-font-lib
keep LICENSE dompdf/php-font-lib "$WORK/project/vendor/dompdf/php-font-lib"
keep src dompdf/php-svg-lib
keep LICENSE dompdf/php-svg-lib "$WORK/project/vendor/dompdf/php-svg-lib"
# php-svg-lib is LGPL-3.0, a set of additional permissions on the GPL-3.0, so the GPL-3.0 text ships beside it (review 3).
GPL3_SHA=3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986
[ "$(sha "$IN/gpl-3.0.txt")" = "$GPL3_SHA" ] || { echo "FAIL gpl-3.0.txt is not the canonical GNU GPL 3.0 text ($GPL3_SHA)" >&2; exit 1; }
cp "$IN/gpl-3.0.txt" "$STAGE/dompdf/php-svg-lib/LICENSE-GPL-3.0.txt"
keep src masterminds/html5
keep LICENSE.txt masterminds/html5 "$WORK/project/vendor/masterminds/html5"
keep src sabberworm/php-css-parser
keep LICENSE sabberworm/php-css-parser "$WORK/project/vendor/sabberworm/php-css-parser"

# The bundled font list, trimmed to the two shipped faces (italic falls back to upright), so no
# family can point at a file that is not shipped.
printf '{\n    "dejavu sans": {\n        "bold": "DejaVuSans-Bold",\n        "bold_italic": "DejaVuSans-Bold",\n        "italic": "DejaVuSans",\n        "normal": "DejaVuSans"\n    }\n}\n' > "$STAGE/dompdf/dompdf/lib/fonts/installed-fonts.dist.json"

# WordPress.org review 1 (amendment A3) and review 3: the build patches in patches.php. Every edit must match exactly as
# recorded (an exact text found once, or a span between two once-only anchors with a pinned SHA-256), or the build stops.
# Each patch's dated note goes to notes.json; rewrite.php notices puts it after the file's opening tag and README.txt
# lists it (LGPL-2.1 section 2(b)).
php -r '
$patches = require $argv[2];
$notes   = array( "dompdf/php-font-lib/src/FontLib/TrueType/File.php" => array( array( "2026-11-07", "two class-name lookups adapted to the prefix (bin/vendor-pdf.sh)." ) ) );
foreach ( $patches as $p ) {
	$path = $argv[1] . "/" . $p["file"];
	if ( ! is_file( $path ) || "" === trim( (string) $p["note"] ) ) {
		fwrite( STDERR, "FAIL patch target {$p["file"]} is missing or has no change notice\n" );
		exit( 1 );
	}
	$code = (string) file_get_contents( $path );
	foreach ( $p["edits"] as $i => $e ) {
		$at = "{$p["file"]} edit " . ( $i + 1 );
		if ( isset( $e["find"] ) ) {
			if ( 1 !== substr_count( $code, $e["find"] ) ) {
				fwrite( STDERR, "FAIL $at: expected the text exactly once, found " . substr_count( $code, $e["find"] ) . "\n" );
				exit( 1 );
			}
			$code = str_replace( $e["find"], $e["replace"], $code );
			continue;
		}
		if ( 1 !== substr_count( $code, $e["from"] ) || 1 !== substr_count( $code, $e["to"] ) ) {
			fwrite( STDERR, "FAIL $at: expected each anchor exactly once\n" );
			exit( 1 );
		}
		$from = strpos( $code, $e["from"] );
		$to   = strpos( $code, $e["to"] );
		if ( $to < $from ) {
			fwrite( STDERR, "FAIL $at: the end anchor comes before the start anchor\n" );
			exit( 1 );
		}
		$span = substr( $code, $from, $to + strlen( $e["to"] ) - $from );
		if ( hash( "sha256", $span ) !== $e["sha256"] ) {
			fwrite( STDERR, "FAIL $at: the span has SHA-256 " . hash( "sha256", $span ) . ", not the pinned {$e["sha256"]}\n" );
			exit( 1 );
		}
		$code = substr( $code, 0, $from ) . $e["replace"] . substr( $code, $from + strlen( $span ) );
	}
	if ( 0 !== strpos( $code, "<?php\n" ) ) {
		fwrite( STDERR, "FAIL {$p["file"]} does not start with an opening tag line\n" );
		exit( 1 );
	}
	$notes[ $p["file"] ][] = array( isset( $p["date"] ) ? $p["date"] : "2026-11-18", $p["note"] . " (tools/pdf/patches.php)" );
	file_put_contents( $path, $code );
}
file_put_contents( $argv[3], json_encode( $notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
' "$STAGE" "$IN/patches.php" "$WORK/notes.json"

# WordPress.org review 3: no heredoc/nowdoc syntax, and dompdf's globals renamed (tools/pdf/rewrite.php; counted, or the build stops).
php "$IN/rewrite.php" code "$STAGE" "$WORK/notes.json"

# WordPress.org: every shipped PHP file refuses a direct request. The guard goes after the file's namespace statement
# (PHP allows only declare() before it); a file without one gets it after declare() or the opening tag. A file that
# already has it is left alone, so a run twice changes nothing.
php -r '
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $argv[1], FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( ! preg_match( "/\\.php\$/", $f->getPathname() ) ) {
		continue;
	}
	$code = (string) file_get_contents( $f->getPathname() );
	if ( false !== strpos( $code, "defined( \x27ABSPATH\x27 ) || exit;" ) ) {
		continue;
	}
	$at   = null;
	$pos  = 0;
	$kind = null;
	foreach ( token_get_all( $code ) as $t ) {
		$pos += strlen( is_array( $t ) ? $t[1] : $t );
		if ( is_array( $t ) && T_OPEN_TAG === $t[0] && null === $at ) {
			$at = $pos;
		} elseif ( is_array( $t ) && null === $kind && in_array( $t[0], array( T_NAMESPACE, T_DECLARE ), true ) ) {
			$kind = $t[0];
		} elseif ( ";" === $t && null !== $kind ) {
			$at = $pos;
			if ( T_NAMESPACE === $kind ) {
				break;
			}
			$kind = null;
		}
	}
	file_put_contents( $f->getPathname(), substr( $code, 0, $at ) . "\n// phpcs:ignoreFile -- Third-party library, pinned and byte-checked by bin/check-vendor.sh.\ndefined( \x27ABSPATH\x27 ) || exit;" . substr( $code, $at ) );
}
' "$STAGE"

# LGPL-2.1 section 2(b): every PHP file carries a dated notice that it was modified, then its own dated change notes.
php "$IN/rewrite.php" notices "$STAGE" "$WORK/notes.json" "$WORK/notices.txt"

# This build's Composer and Strauss inputs, so the shipped library traces to its exact sources (review 3).
cp "$IN/composer.json" "$IN/composer.lock" "$IN/strauss.json" "$STAGE/"

find "$STAGE" \( -name '.DS_Store' -o -name '._*' -o -name '.git*' \) -exec rm -rf {} +

# 5. The denial scan: no unprefixed namespace, use or class string may remain.
if grep -rnE '^[[:space:]]*(namespace|use)[[:space:]]+\\?(Dompdf|FontLib|Svg|Masterminds|Sabberworm)(\\|;|[[:space:]])' "$STAGE" --include='*.php' > "$WORK/deny.txt" \
	|| grep -rnE "[\"'](\\\\\\\\|\\\\)?(Dompdf|FontLib|Svg|Masterminds|Sabberworm)\\\\" "$STAGE" --include='*.php' >> "$WORK/deny.txt"; then
	head -20 "$WORK/deny.txt" >&2
	echo "FAIL unprefixed namespace or class string left in the prefixed copy" >&2
	exit 1
fi

# 6. README.txt, then the manifest of every shipped file (written outside the shipped tree, --manifest).
{
	echo "Private, prefixed PDF library for IWS Church Manager (IWSCM\\Vendor\\Pdf\\). Built by bin/vendor-pdf.sh from the public build tools at https://github.com/integritywebstudios/iws-church-manager-build; do not edit."
	echo "Packages (composer.lock):"
	sed 's/^/  /' "$WORK/packages.txt"
	echo "Archive SHA-256 (archives.sha256):"
	sed 's/^/  /' "$IN/archives.sha256"
	echo "Strauss: $STRAUSS_VERSION, strauss.phar SHA-256 $STRAUSS_SHA"
	echo "Config: strauss.json SHA-256 $(sha "$IN/strauss.json"), composer.lock SHA-256 $(sha "$IN/composer.lock")"
	echo "Composer metadata: composer.json (the five packages at their exact versions), composer.lock and strauss.json in this folder are this build's inputs."
	echo "Licences: dompdf and php-font-lib LGPL-2.1, php-svg-lib LGPL-3.0, html5 and php-css-parser MIT (each package's own file)."
	echo "  LGPL-3.0 adds permissions to the GNU GPL 3.0, so its full text is shipped beside it: dompdf/php-svg-lib/LICENSE-GPL-3.0.txt (https://www.gnu.org/licenses/gpl-3.0.txt, SHA-256 $GPL3_SHA)."
	echo "Fonts: DejaVu Sans and DejaVu Sans Bold 2.37, Bitstream Vera and Arev font licences, DejaVu changes public domain (dompdf/dompdf/lib/fonts/LICENSE-DejaVu.txt, upstream dejavu-fonts LICENSE at tag version_2_37, SHA-256 $DEJAVU_LICENCE_SHA)."
	echo "Modifications by Integrity Web Studios (notice of change, LGPL-2.1 section 2(b) and LGPL-3.0; the unmodified code is in the archives above):"
	echo "  Every PHP file: namespaces, use statements and class strings prefixed with IWSCM\\Vendor\\Pdf\\ by Strauss, a direct-request guard added, and a dated notice of modification after the opening tag."
	echo "  dompdf/dompdf/src/PhpEvaluator.php: removed (PHP in documents is off)."
	echo "  dompdf/dompdf/lib/fonts: only DejaVu Sans and DejaVu Sans Bold are kept; installed-fonts.dist.json lists only those two."
	echo "  Per file (date, file: change; each file also carries its notes after the opening tag):"
	cat "$WORK/notices.txt"
} > "$STAGE/README.txt"
( cd "$STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort | while IFS= read -r f; do printf '%s  %s\n' "$(sha "$f")" "$f"; done ) > "$WORK/manifest"

rm -rf "$OUT.new"
mkdir -p "$(dirname "$OUT")" "$(dirname "$MANIFEST")"
cp -R "$STAGE" "$OUT.new"
rm -rf "$OUT"
mv "$OUT.new" "$OUT"
cp "$WORK/manifest" "$MANIFEST"
echo "Vendored PDF library -> $OUT ($(grep -c . "$MANIFEST") files; manifest $MANIFEST)"
