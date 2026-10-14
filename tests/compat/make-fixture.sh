#!/bin/sh
# Regenerates tests/fixtures from the maintenance fork's install of the
# provenance verified 3.5.5 schema. The installer SQL is unchanged upstream
# code, so the resulting schema is the 3.5.5 schema; the pristine upstream
# tree cannot run its own installer on PHP 8.3 (baseline blocker 3). The
# fixture contains a known admin, authors, stories (one validated, one
# unvalidated with a coauthor), a rating, a file backed chapter, and the
# installer's default custom pages.
#
# Usage: tests/compat/make-fixture.sh
# Requires: php (8.3+ with mysqli), mysql client + mysqldump, curl, openssl.
set -eu

PHP_BIN="${PHP_BIN:-php}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
DUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
DB_HOST="${EF_DB_HOST:-127.0.0.1}"
DB_NAME="efiction_test_fixturegen"
REPO=$(cd "$(dirname "$0")/../.." && pwd)
FIXTURE_DIR="$REPO/tests/fixtures"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/efiction-fixture.XXXXXX")
cleanup() {
	[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
	"$MYSQL_BIN" -h "$DB_HOST" -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

mkdir -p "$WORK/web"
(cd "$REPO" && tar --exclude='./.git' --exclude='./tests' --exclude='./dist' -cf - .) | tar -xf - -C "$WORK/web"
openssl rand -hex 32 > "$WORK/efiction-install-token.txt"
chmod 600 "$WORK/efiction-install-token.txt"
SECRET=$(cat "$WORK/efiction-install-token.txt")

PORT=$("$PHP_BIN" -r '
for ($i = 0; $i < 50; $i++) {
    $port = random_int(21000, 44000);
    $s = @socket_create_listen($port);
    if ($s !== false) { socket_close($s); echo $port; exit; }
}
exit(1);
')
"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$WORK/web" -d display_errors=1 >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
i=0
until curl -s -o /dev/null "http://127.0.0.1:$PORT/index.php" 2>/dev/null; do
	i=$((i + 1)); [ "$i" -gt 100 ] && { echo "server did not start" >&2; exit 1; }
	"$PHP_BIN" -r 'usleep(100000);'
done
BASE="http://127.0.0.1:$PORT"
BODY="$WORK/body.html"

post() { curl -s -o "$BODY" -c "$WORK/jar" -b "$WORK/jar" --data "$1" "$BASE$2"; }

"$MYSQL_BIN" -h "$DB_HOST" -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"

# Drive the gated installer end to end (POST + CSRF + owner secret).
csrf() { grep -o "name='csrf' value='[0-9a-f]*'" "$BODY" | head -1 | sed "s/.*value='//;s/'//"; }

curl -s -o "$BODY" -c "$WORK/jar" -b "$WORK/jar" "$BASE/install/install.php"
post "submit=submit&csrf=$(csrf)&install_secret=$SECRET&dbhost=$DB_HOST&dbname=$DB_NAME&dbuser=root&dbpass=&sitekey=fixture355key&language=en&settingsprefix=" "/install/install.php?step=1"
for step in 2 3 4 5 6 7 8; do
	curl -s -o "$BODY" -c "$WORK/jar" -b "$WORK/jar" "$BASE/install/install.php?step=$step"
	post "install=automatic&csrf=$(csrf)&install_secret=$SECRET" "/install/install.php?step=$step"
done
curl -s -o "$BODY" -c "$WORK/jar" -b "$WORK/jar" "$BASE/install/install.php?step=9"
post "submit=submit&csrf=$(csrf)&install_secret=$SECRET&newpenname=FixtureAdmin&email=admin@fixture.local&password=fixturepass123&password2=fixturepass123" "/install/install.php?step=9"

grep -q "Installation complete" "$BODY" || { echo "upstream install did not complete; see $WORK/server.log and $BODY" >&2; exit 1; }

# Known rows on top of the installed schema.
"$MYSQL_BIN" -h "$DB_HOST" -u root "$DB_NAME" <<'SQL'
UPDATE fanfiction_settings SET sitename = 'Fixture Archive', url = 'http://fixture.local', siteemail = 'admin@fixture.local', tableprefix = '', store = 'files', storiespath = 'stories', version = '3.5.5' WHERE sitekey = 'fixture355key';
DELETE FROM fanfiction_ratings;
INSERT INTO fanfiction_ratings (rid, rating, ratingwarning, warningtext) VALUES (1, 'Everyone', '0', '');
INSERT INTO fanfiction_authors (uid, penname, realname, email, bio, image, date, admincreated, password) VALUES
 (10, 'AuthorOwner', 'Owner', 'owner@fixture.local', '<p>Bio.</p>', '', NOW(), '0', MD5('authorpass123')),
 (11, 'CoAuthor', 'Co', 'co@fixture.local', '<p>Bio.</p>', '', NOW(), '0', MD5('coauthorpass123')),
 (12, 'OtherMember', 'Other', 'other@fixture.local', '<p>Bio.</p>', '', NOW(), '0', MD5('memberpass123'));
INSERT INTO fanfiction_authorprefs (uid, userskin, level) VALUES (10, 'default', 0), (11, 'default', 0), (12, 'default', 0);
INSERT INTO fanfiction_stories (sid, title, summary, storynotes, catid, classes, charid, rid, date, updated, uid, coauthors, featured, validated, completed, rr, wordcount, rating, reviews, count) VALUES
 (1, 'Fixture Story', 'A preserved fixture story.', '', '0', '0', '0', '1', NOW(), NOW(), 10, '0', '0', '1', '0', '0', 8, 0, 0, 0),
 (2, 'Unvalidated Fixture Story', 'A story only its author, the coauthor, and admins may read.', '', '0', '0', '0', '1', NOW(), NOW(), 10, '1', '0', '0', '0', '0', 8, 0, 0, 0);
INSERT INTO fanfiction_chapters (chapid, title, inorder, notes, storytext, endnotes, validated, wordcount, rating, reviews, sid, uid, count) VALUES
 (1, 'Chapter One', 1, '', '', '', '1', 8, 0, 0, 1, 10, 0),
 (2, 'Hidden Chapter', 1, '', '', '', '0', 8, 0, 0, 2, 10, 0);
INSERT INTO fanfiction_coauthors (sid, uid) VALUES (2, 11);
SQL

mkdir -p "$WORK/web/stories/10"
printf 'Chapter One text preserved fixture.' > "$WORK/web/stories/10/1.txt"
printf 'Hidden chapter text preserved fixture.' > "$WORK/web/stories/10/2.txt"

# Dump and store the fixture.
mkdir -p "$FIXTURE_DIR"
mysqldump_out="$WORK/dump.sql"
MYSQL_PWD= "$DUMP_BIN" -h "$DB_HOST" -u root --skip-comments --skip-extended-insert "$DB_NAME" > "$mysqldump_out"
gzip -c "$mysqldump_out" > "$FIXTURE_DIR/efiction-3.5.5.sql.gz"

rm -rf "$FIXTURE_DIR/stories"
mkdir -p "$FIXTURE_DIR/stories"
cp -R "$WORK/web/stories/." "$FIXTURE_DIR/stories/"

# The fixture config template used by tests/compat/http.sh upgrade mode.
cat > "$FIXTURE_DIR/config.php.in" <<'PHP'
<?php
$dbhost = "%%DBHOST%%";
$dbname = "%%DBNAME%%";
$dbuser = "%%DBUSER%%";
$dbpass = "%%DBPASS%%";
$sitekey = "fixture355key";
$settingsprefix = "";

include_once("includes/dbfunctions.php");
if(!empty($sitekey)) $dbconnect = dbconnect($dbhost, $dbuser, $dbpass, $dbname);
PHP

echo "fixture written to tests/fixtures/"
