#!/bin/sh
# Documentation assertions: the README must state the fork's unofficial
# status, link Kiption, carry the license notice, and describe the migration
# path without claiming the original project is deprecated.
set -eu
cd "$(dirname "$0")/../.."
rg -qi 'unofficial eFiction' README.md
rg -q 'https://github.com/Hyperion360/kiption' README.md
rg -qi 'GPL|GNU General Public License' README.md CREDITS.md
rg -qi 'export/import|migration' README.md
! rg -qi 'the original eFiction project is deprecated' README.md
echo "docs ok"
