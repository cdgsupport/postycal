#!/usr/bin/env bash
#
# Set the plugin version everywhere it is declared.
#
# Usage: bin/bump-version.sh 2.3.0
#
# Updates the postycal.php header, POSTYCAL_VERSION, and the README badge.
# It does not touch the changelog — write that entry yourself.

set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "usage: bin/bump-version.sh <version>   (e.g. 2.3.0)" >&2
    exit 1
fi

version="${1#v}"

if ! printf '%s' "$version" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "error: '$version' is not a three-part version number" >&2
    exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# sed -i differs between BSD and GNU; write to a temp file instead.
replace() {
    local pattern="$1" file="$2"
    sed -E "$pattern" "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

replace "s|^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*|\1${version}|" postycal.php
replace "s|(define\([[:space:]]*'POSTYCAL_VERSION'[^']*')[^']+(')|\1${version}\2|" postycal.php
replace "s|^(\*\*Version:\*\*[[:space:]]*).*|\1${version}  |" README.md

echo "Set version to ${version} in:"
grep -nE "^[[:space:]]*\*[[:space:]]*Version:|POSTYCAL_VERSION" postycal.php | sed 's/^/  postycal.php:/'
grep -nE '^\*\*Version:\*\*' README.md | sed 's/^/  README.md:/'
echo
echo "Next: add a '### ${version}' changelog entry to README.md, commit, then:"
echo "  git tag v${version} && git push origin v${version}"
