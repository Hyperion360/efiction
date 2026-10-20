#!/bin/sh
# Documentation assertions: the README must state the fork's unofficial
# status, link Kiption, carry the license notice, and describe the migration
# path without claiming the original project is deprecated. The 3.5.7 port
# needles pin the bcrypt-era claims: the column-widening step, the retired
# can't-fix wording, the vendored mailer, and the supported-source matrix.
set -eu
cd "$(dirname "$0")/../.."
rg -qi 'unofficial eFiction' README.md
rg -q 'https://github.com/Hyperion360/kiption' README.md
rg -qi 'GPL|GNU General Public License' README.md CREDITS.md
rg -qi 'export/import|migration' README.md
! rg -qi 'the original eFiction project is deprecated' README.md
rg -q 'varchar\(255\)' README.md
rg -qi 'bcrypt' README.md docs/SECURITY.md
! rg -qi 'the fix needs the schema change this fork forbids' docs/SECURITY.md
! rg -qi 'Passwords are unsalted MD5' docs/SECURITY.md
rg -qi 'PHPMailer 6' CREDITS.md
rg -qi '3\.5\.7' README.md docs/COMPATIBILITY.md
rg -q 'eFiction 3\.5\.5\+ maintenance fork' README.md
echo "docs ok"
