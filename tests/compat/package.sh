#!/bin/sh
# Builds the uploadable release ZIP from the working tree.
#
# Output: dist/efiction-3.5.5-maint.zip. The ZIP contains the fork files
# only: no tests, no Git metadata, no database backups, no config.php, no
# installer owner secret or state marker, no export tokens, and no backup
# directories. Entry data (timestamps, permissions) is normalized so the
# same tree produces the same ZIP bytes. The SHA-256 of the ZIP is printed;
# release notes quote it.
set -eu

REPO=$(cd "$(dirname "$0")/../.." && pwd)
OUT_DIR="$REPO/dist"
OUT="$OUT_DIR/efiction-3.5.5-maint.zip"

STAGE=$(mktemp -d "${TMPDIR:-/tmp}/efiction-package.XXXXXX")
SOURCE_LIST="$STAGE.files"
trap 'rm -rf "$STAGE"; rm -f "$SOURCE_LIST"' EXIT INT TERM

# Use the working copies of tracked and nonignored files. Git ignores are
# also release exclusions, so local plans and diagnostics stay on this host.
git -C "$REPO" ls-files --cached --others --exclude-standard -z > "$SOURCE_LIST"

# The fork tree, without development and private files.
(cd "$REPO" && tar \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='tests' \
	--exclude='AGENTS.md' \
	--exclude='AGENTS.local.md' \
	--exclude='dist' \
	--exclude='config.php' \
	--exclude='docs/config.php' \
	--exclude='export-token.php' \
	--exclude='*/export-token.php' \
	--exclude='*/efiction-install-token.txt' \
	--exclude='*/efiction-install-state.json' \
	--exclude='*efiction-install-backup-*' \
	--exclude='*.sql.gz' \
	--exclude='.DS_Store' \
	--null -T "$SOURCE_LIST" -cf -) | tar -xf - -C "$STAGE"

# Normalize metadata so the archive is reproducible.
find "$STAGE" -print0 | xargs -0 touch -t 202601140000.00

mkdir -p "$OUT_DIR"
rm -f "$OUT"
(cd "$STAGE" && zip -qrX "$OUT" .)

# Gate: none of the excluded classes of file may be inside.
if unzip -l "$OUT" | grep -Eq 'efiction-install-(token|state)|export-token|efiction-install-backup|(^|/)config\.php$|(^|/)tests/|\.sql\.gz|\.git/'; then
	echo "FAIL: release ZIP contains excluded files:" >&2
	unzip -l "$OUT" | grep -E 'efiction-install-(token|state)|export-token|efiction-install-backup|(^|/)config\.php$|(^|/)tests/|\.sql\.gz|\.git/' >&2
	exit 1
fi

# Gate: local working material must never become a release payload.
PRIVATE_PATHS='^(docs/superpowers/|research/|qa-full-reports/|daily-qa-reports/|\.gstack/|TODOS\.md$|handoff-[^/]*\.md$|AGENTS(\.local)?\.md$)'
if unzip -Z1 "$OUT" | grep -Eq "$PRIVATE_PATHS"; then
    echo "FAIL: release ZIP contains private working material" >&2
    exit 1
fi

# Gate: upstream notices must still be present.
for f in LICENSE CREDITS.md README.txt INSTALL.md UPGRADE.md docs/PROVENANCE.md docs/COMPATIBILITY.md docs/SECURITY.md tinymce/lgpl.txt skins/Berrygood/cclicense.txt skins/E-zfiction/cclicense.txt; do
	unzip -l "$OUT" | grep -q " $f\$" || { echo "FAIL: release ZIP is missing $f" >&2; exit 1; }
done

echo "package ok: $OUT"
shasum -a 256 "$OUT" || sha256sum "$OUT"
