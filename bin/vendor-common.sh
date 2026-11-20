#!/bin/bash
#
# Copy IWS Common into a plugin under that plugin's own namespace.
#
# Each plugin carries a private, renamed copy, so two plugins can be active
# together with different library versions and never collide, and neither
# needs the other installed. (This is what Strauss does; 20 lines is enough.)
#
# Usage: bin/vendor-common.sh [--from <40-char SHA>] <plugin-dir> <Namespace\Prefix> [modules]
#   bin/vendor-common.sh ../church-sermon-notes 'CSNotes\Vendor' 'Color,Brand,RateLimiter,Mail/Shell,Mail/Transport'
#   [modules] = comma list of src paths without .php (plus "assets" for the CSS/JS).
#   Omit it to vendor everything. Ship only what the plugin uses.
#   -> <plugin-dir>/vendor-iws/IWS/Common/..., namespace CSNotes\Vendor\IWS\Common
#   --from <SHA> exports packages/iws-common at that commit (git archive), never
#   the working tree, and records the SHA in vendor-iws/README.txt. Releases use
#   --from with the launch commit; bin/check-vendor.sh verifies the zip (brand-design.md B6).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG="$ROOT/packages/iws-common"
PIN=""

if [ "${1:-}" = "--from" ]; then
	PIN="${2:?--from needs a commit SHA}"
	shift 2
	if [[ ! "$PIN" =~ ^[0-9a-f]{40}$ ]] || ! git -C "$ROOT" cat-file -e "$PIN:packages/iws-common/src" 2>/dev/null; then
		echo "--from needs a full 40-character commit SHA that contains packages/iws-common: $PIN" >&2
		exit 1
	fi
	TMP="$(mktemp -d)"
	trap 'rm -rf "$TMP"' EXIT
	git -C "$ROOT" archive "$PIN" packages/iws-common/src packages/iws-common/assets | tar -x -C "$TMP"
	PKG="$TMP/packages/iws-common"
fi

SRC="$PKG/src"
DEST_PLUGIN="${1:?plugin directory}"
PREFIX="${2:?namespace prefix, e.g. CSNotes\\Vendor}"
MODULES="${3:-}"
DEST="$DEST_PLUGIN/vendor-iws/IWS/Common"

if [[ ! "$PREFIX" =~ ^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$ ]]; then
	echo "Invalid namespace prefix: $PREFIX" >&2
	exit 1
fi

rm -rf "$DEST_PLUGIN/vendor-iws"
mkdir -p "$DEST"

if [ -z "$MODULES" ]; then
	cp -R "$SRC/." "$DEST/"
	mkdir -p "$DEST_PLUGIN/vendor-iws/assets"
	cp -R "$PKG/assets/." "$DEST_PLUGIN/vendor-iws/assets/"
else
	IFS=',' read -r -a LIST <<< "$MODULES"
	for module in "${LIST[@]}"; do
		if [ "$module" = "assets" ]; then
			mkdir -p "$DEST_PLUGIN/vendor-iws/assets"
			cp -R "$PKG/assets/." "$DEST_PLUGIN/vendor-iws/assets/"
		elif [ -f "$SRC/$module.php" ]; then
			mkdir -p "$DEST/$(dirname "$module")"
			cp "$SRC/$module.php" "$DEST/$module.php"
		else
			echo "Unknown module: $module" >&2
			exit 1
		fi
	done
fi

# Escape backslashes for sed, then rewrite namespace and use statements.
ESC="${PREFIX//\\/\\\\}"
find "$DEST" -name '*.php' -print0 | while IFS= read -r -d '' file; do
	sed -i.bak -E \
		-e "s/^namespace IWS\\\\Common/namespace ${ESC}\\\\IWS\\\\Common/" \
		-e "s/^use IWS\\\\Common/use ${ESC}\\\\IWS\\\\Common/" \
		"$file"
	rm -f "$file.bak"
done

if [ -n "$PIN" ]; then
	SOURCE="$PIN"
else
	SOURCE="unpinned working tree at $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
fi
printf 'IWS Common: Integrity Web Studios\x27 own GPL code, included here under %s\\IWS\\Common.\nSource-SHA: %s\nThese files are the full, readable source; the only change is the namespace prefix, added by bin/vendor-common.sh (https://github.com/integritywebstudios/iws-church-manager-build).\n' "$PREFIX" "$SOURCE" > "$DEST_PLUGIN/vendor-iws/README.txt"

echo "Vendored IWS Common -> $DEST ($PREFIX\\IWS\\Common)"
grep -rh '^namespace ' "$DEST" | sort -u
