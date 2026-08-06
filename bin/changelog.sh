#!/usr/bin/env bash
#
# Print the README changelog entry for one version, for use as release notes.
#
# Usage: bin/changelog.sh 2.2.0

set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "usage: bin/changelog.sh <version>" >&2
    exit 1
fi

version="${1#v}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

notes="$(
    awk -v ver="### $version" '
        $0 == ver   { found = 1; next }
        found && /^### / { exit }
        found       { print }
    ' "$ROOT/README.md" | sed -e 's/[[:space:]]*$//'
)"

# Trim leading and trailing blank lines.
notes="$(printf '%s\n' "$notes" | awk 'NF {p = 1} p' | awk '{ lines[NR] = $0 } END { last = NR; while (last > 0 && lines[last] == "") last--; for (i = 1; i <= last; i++) print lines[i] }')"

if [ -z "$notes" ]; then
    echo "error: no '### $version' section found in README.md" >&2
    exit 1
fi

printf '%s\n' "$notes"
