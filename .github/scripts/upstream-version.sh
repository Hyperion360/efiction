#!/bin/sh
# Read the version from the HTTPS feed's signed-envelope format. Parsing
# the envelope does not verify its signature; it supplies no executable code.
set -eu
jq -er '.manifest | strings | @base64d | fromjson | .version | strings | select(test("\\A3\\.[0-9]+\\.[0-9]+[a-z]?\\z"))'
