#!/usr/bin/env bash
#
# Release helper for Lexa Search.
#
#   bin/release.sh check          # verify the version is consistent and the tree is releasable
#   bin/release.sh zip            # additionally build dist/lexa-search-X.Y.Z.zip for hand-install
#
# The version that actually matters is the "Version:" header in lexa-search.php.
# WordPress and plugin-update-checker both read it and ignore everything else --
# including the LEXA_VERSION constant and readme.txt's "Stable tag". Tagging a
# release without bumping that header produces NO update and NO error message,
# so this script refuses to proceed when they disagree.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
MAIN="lexa-search.php"

fail() { printf '\033[31mFAIL\033[0m  %s\n' "$1" >&2; exit 1; }
ok()   { printf '\033[32mok\033[0m    %s\n' "$1"; }
warn() { printf '\033[33mwarn\033[0m  %s\n' "$1"; }

# --- version consistency -----------------------------------------------------

HEADER_VERSION=$(grep -m1 -E '^\s*\*\s*Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
[ -n "$HEADER_VERSION" ] || fail "no 'Version:' header found in $MAIN"
ok "plugin header version: $HEADER_VERSION"

CONST_VERSION=$(grep -m1 -E "define\('LEXA_VERSION'" "$MAIN" | sed -E "s/.*'LEXA_VERSION'[[:space:]]*,[[:space:]]*'([^']*)'.*/\1/")
if [ -n "$CONST_VERSION" ] && [ "$CONST_VERSION" != "$HEADER_VERSION" ]; then
    fail "version drift: header is $HEADER_VERSION but LEXA_VERSION is $CONST_VERSION -- bump both in $MAIN"
fi
ok "LEXA_VERSION matches header"

if [ -f readme.txt ]; then
    STABLE=$(grep -m1 -iE '^Stable tag:' readme.txt | sed -E 's/.*[Ss]table tag:[[:space:]]*//' | tr -d '[:space:]' || true)
    if [ -n "${STABLE:-}" ] && [ "$STABLE" != "$HEADER_VERSION" ]; then
        warn "readme.txt Stable tag ($STABLE) != header ($HEADER_VERSION) -- cosmetic only, PUC ignores it"
    fi
fi

# --- the update checker must actually be wired up ----------------------------

grep -q "buildUpdateChecker" "$MAIN" \
    || fail "$MAIN never calls buildUpdateChecker() -- the update system would be a silent no-op"
ok "update checker is wired up"

[ -f plugin-update-checker/plugin-update-checker.php ] \
    || fail "plugin-update-checker/ is missing -- it is required at runtime and must be committed"
ok "plugin-update-checker/ present"

# --- syntax ------------------------------------------------------------------

if command -v php >/dev/null 2>&1; then
    if find . -name '*.php' -not -path './dist/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors' | grep -q .; then
        fail "PHP syntax errors present (run: find . -name '*.php' -print0 | xargs -0 -n1 php -l)"
    fi
    ok "PHP syntax clean"
else
    warn "php not on PATH -- skipped syntax check"
fi

# --- no secrets --------------------------------------------------------------

if grep -rInE "(ghp_|gho_|github_pat_)[A-Za-z0-9_]{10,}" --exclude-dir=plugin-update-checker --exclude-dir=dist . >/dev/null 2>&1; then
    fail "a GitHub token appears to be committed in the plugin tree -- it belongs in wp-config.php"
fi
ok "no GitHub token in the plugin tree"

# --- git state ---------------------------------------------------------------

if git rev-parse --git-dir >/dev/null 2>&1; then
    if [ -n "$(git status --porcelain)" ]; then
        warn "working tree is dirty -- commit before tagging, or the release will not match the tag"
    fi
    if git rev-parse "v$HEADER_VERSION" >/dev/null 2>&1; then
        warn "tag v$HEADER_VERSION already exists -- bump the header before releasing again"
    fi
fi

if [ "${1:-check}" = "check" ]; then
    echo
    echo "Ready to release $HEADER_VERSION. Next:"
    echo "  git commit -am \"Release $HEADER_VERSION\" && git push"
    echo "  gh release create v$HEADER_VERSION --repo NguyenThong251/lexa-search --title \"v$HEADER_VERSION\" --generate-notes"
    exit 0
fi

# --- build a hand-installable zip -------------------------------------------

[ "${1:-}" = "zip" ] || fail "unknown command '${1:-}' (expected: check | zip)"

command -v zip >/dev/null 2>&1 || fail "'zip' is not installed"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/lexa-search"   # the zip's top-level folder must be the plugin slug
mkdir -p "$DEST"

# Mirror .gitattributes export-ignore so this zip matches what GitHub serves.
tar -cf - \
    --exclude='./.git' --exclude='./.github' \
    --exclude='./tests' --exclude='./bin' --exclude='./dist' \
    --exclude='./PLAN.md' --exclude='./RELEASING.md' \
    --exclude='./.gitignore' --exclude='./.gitattributes' \
    --exclude='./composer.lock' --exclude='*.zip' \
    . | tar -xf - -C "$DEST"

mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/lexa-search-$HEADER_VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" lexa-search )

ok "built $ZIP ($(du -h "$ZIP" | cut -f1))"
echo
echo "Top-level folder inside the zip (must be exactly 'lexa-search/'):"
unzip -l "$ZIP" | awk 'NR>3 {print $4}' | cut -d/ -f1 | sort -u | grep -v '^$' | sed 's/^/  /'
