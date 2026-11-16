#!/bin/sh
# The release feed wraps JSON in a base64 manifest. Reject malformed or
# executable version strings before they reach workflow outputs.
set -eu
REPO=$(cd "$(dirname "$0")/../.." && pwd)
parser="$REPO/.github/scripts/upstream-version.sh"
feed() {
    jq -cn --arg version "$1" '{version:$version} | tojson | @base64 | {manifest:.,signature:"fixture"}'
}
for version in 3.5.88 3.5.84b; do
    actual=$(feed "$version" | sh "$parser")
    [ "$actual" = "$version" ] || { echo "FAIL: signed-envelope version $version" >&2; exit 1; }
done
for version in 'null' '3.5.88;alert(1)' "3.5.88
injected=1" ''; do
    if feed "$version" | sh "$parser" >/dev/null 2>&1; then
        echo "FAIL: accepted invalid version" >&2
        exit 1
    fi
done
for invalid in '{}' '{"version":"3.5.88"}' '{"manifest":"!"}' '{"manifest":"e30="}'; do
    if printf '%s' "$invalid" | sh "$parser" >/dev/null 2>&1; then
        echo "FAIL: accepted malformed manifest" >&2
        exit 1
    fi
done
echo 'upstream feed ok'
