#!/usr/bin/env bash
#
# Build a distributable PostyCal zip.
#
# The plugin version is declared in three places that have drifted apart
# before — a stale header version silently served cached admin assets to
# everyone who upgraded. This script refuses to build unless they agree, and
# unless they agree with the tag being released.
#
# Usage:
#   bin/build-release.sh           Build from the version in postycal.php
#   bin/build-release.sh v2.2.0    Also assert that version matches this tag
#
# Output: dist/postycal-<version>.zip, containing a single postycal/ directory
# (WordPress requires the plugin folder at the top level of the archive).

set -euo pipefail

SLUG="postycal"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# ---------------------------------------------------------------------------
# Read the version from every place it is declared
#
# Read from HEAD, not the working tree: the zip is built with `git archive
# HEAD`, so reading the working tree would let an uncommitted bump produce a
# zip whose filename and contents disagree.
# ---------------------------------------------------------------------------

if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo "error: no commits yet — there is nothing to archive" >&2
    exit 1
fi

if ! git diff --quiet HEAD 2>/dev/null; then
    echo "warning: working tree has uncommitted changes." >&2
    echo "         The zip is built from HEAD and will not include them." >&2
fi

header_version="$(
    git show HEAD:postycal.php | grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' \
        | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'
)"

constant_version="$(
    git show HEAD:postycal.php | grep -m1 -E "define\([[:space:]]*'POSTYCAL_VERSION'" \
        | sed -E "s/.*'POSTYCAL_VERSION'[^']*'([^']+)'.*/\1/"
)"

readme_version="$(
    git show HEAD:README.md | grep -m1 -E '^\*\*Version:\*\*' \
        | sed -E 's/.*\*\*Version:\*\*[[:space:]]*//' | tr -d '[:space:]'
)"

if [ -z "$header_version" ]; then
    echo "error: could not read the Version header from postycal.php" >&2
    exit 1
fi

fail_mismatch() {
    echo "error: plugin version declarations disagree at HEAD" >&2
    echo "  postycal.php header:   ${header_version:-<missing>}" >&2
    echo "  POSTYCAL_VERSION:      ${constant_version:-<missing>}" >&2
    echo "  README.md:             ${readme_version:-<missing>}" >&2
    echo >&2
    echo "Run bin/bump-version.sh <version> to set all three at once." >&2
    exit 1
}

[ "$header_version" = "$constant_version" ] || fail_mismatch
[ "$header_version" = "$readme_version" ] || fail_mismatch

version="$header_version"

# ---------------------------------------------------------------------------
# If a tag was supplied, it must match too
# ---------------------------------------------------------------------------

if [ "$#" -ge 1 ]; then
    tag="$1"
    tag_version="${tag#v}"

    if [ "$tag_version" != "$version" ]; then
        echo "error: tag '$tag' does not match plugin version '$version'" >&2
        echo "Tag the release as 'v$version', or bump the plugin first." >&2
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

# git archive ships only committed, non-export-ignored files, so the zip can
# never pick up local scratch files or an uncommitted edit.
rm -rf dist
mkdir -p dist

git archive --format=zip --prefix="${SLUG}/" -o "dist/${SLUG}-${version}.zip" HEAD

echo "Built dist/${SLUG}-${version}.zip"
unzip -Z1 "dist/${SLUG}-${version}.zip" | sed 's/^/  /'
