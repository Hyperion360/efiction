#!/bin/sh
# Browser-install integration test for the eFiction maintenance fork.
#
# Usage: tests/compat/http.sh [fresh|upgrade]
#
# Drives the real installer over HTTP (php -S as a fast local probe; the
# Apache release gate lives in tests/compat/apache.sh) against a disposable
# copied webroot and a randomly named database. Required environment:
#   EF_DB_ADMIN_USER     CI-only database admin user (create/drop/dump)
#   EF_DB_ADMIN_PASSWORD optional password for that user
#   EF_DB_HOST           optional, default 127.0.0.1
# Optional overrides: PHP_BIN, MYSQL_BIN, MYSQLDUMP_BIN, SOURCE_DIR (webroot
# source; defaults to the repository this script lives in), FIXTURE_DIR.
# Upgrade mode additionally needs the git history (it builds the 3.5.5
# fixture webroot from the verified upstream import on git main).
set -eu

MODE="${1:-fresh}"
if [ "$MODE" != "fresh" ] && [ "$MODE" != "upgrade" ]; then
	echo "usage: $0 [fresh|upgrade]" >&2
	exit 2
fi

: "${EF_DB_ADMIN_USER:?EF_DB_ADMIN_USER is required}"
EF_DB_HOST="${EF_DB_HOST:-127.0.0.1}"
# Nondefault engine port for local matrix runs; empty means the client default.
EF_DB_PORT="${EF_DB_PORT:-}"
# The installer's dbhost form value: mysqli accepts host:port notation.
# EF_INSTALL_DBHOST overrides it when the HTTP server sees the database under
# a different name than the host-side mysql client (tests/compat/apache.sh).
EF_INSTALL_DBHOST="${EF_INSTALL_DBHOST:-${EF_DB_HOST}${EF_DB_PORT:+:$EF_DB_PORT}}"
EF_DB_ADMIN_PASSWORD="${EF_DB_ADMIN_PASSWORD:-}"
PHP_BIN="${PHP_BIN:-php}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
DUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
SOURCE_DIR="${SOURCE_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
FIXTURE_DIR="${FIXTURE_DIR:-$SCRIPT_DIR/../fixtures}"

FAILURES=0
check() { # check <label> <command...>
	label=$1
	shift
	if "$@" >/dev/null 2>&1; then
		echo "ok: $label"
	else
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $label"
	fi
}

check_contains() { # check_contains <label> <needle> <file>
	if [ -f "$3" ] && grep -qF -- "$2" "$3" 2>/dev/null; then
		echo "ok: $1"
	else
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $1 (missing: $2)"
		if [ -f "$3" ]; then
			grep -oE '(WRONGPASSWORD text|No such account|Fatal error[^<]*|write_error[^>]*>[^<]{0,80})' "$3" | head -2 | sed 's/^/      body: /' >&2 || true
			tail -c 300 "$3" | tr -d '\n' | sed 's/^/      tail: /' >&2 || true
		fi
	fi
}

check_not_contains() { # check_not_contains <label> <needle> <file>
	if [ ! -f "$3" ] || ! grep -qF -- "$2" "$3" 2>/dev/null; then
		echo "ok: $1"
	else
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $1 (found: $2)"
	fi
}

check_status() { # check_status <label> <expected> <actual>
	if [ "$2" = "$3" ]; then
		echo "ok: $1"
	else
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $1 (expected $2, got $3)"
		if [ -f "$BODY" ]; then
			grep -oE '(Recovery denied[^<]*|Installer authentication failed[^<]*|config\.php already exists[^<]*|Fatal error[^<]*|write_error[^>]*>[^<]*)' "$BODY" | head -2 | sed 's/^/      body: /' >&2 || true
		fi
	fi
}

# PHP warnings, fatals, deprecations in a response body are failures.
check_no_php_errors() {
	if [ ! -f "$BODY" ]; then
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $1 (no body file)"
		return
	fi
	if grep -qE '\<(Warning|Fatal error|Parse error|Deprecated|Notice)\b' "$BODY"; then
		FAILURES=$((FAILURES + 1))
		echo "FAIL: $1 (PHP error output present):"
		grep -nE '\<(Warning|Fatal error|Parse error|Deprecated|Notice)\b' "$BODY" | head -3 >&2
	else
		echo "ok: $1"
	fi
}

mysql_q() { # mysql_q <sql>
	MYSQL_PWD="${EF_DB_ADMIN_PASSWORD}" "$MYSQL_BIN" -h "$EF_DB_HOST" ${EF_DB_PORT:+-P "$EF_DB_PORT"} -u "$EF_DB_ADMIN_USER" -e "$1"
}

db_hash() { # db_hash <mode> [tables...]
	m=$1
	shift
	MYSQL_PWD="${EF_DB_ADMIN_PASSWORD}" "$DUMP_BIN" -h "$EF_DB_HOST" ${EF_DB_PORT:+-P "$EF_DB_PORT"} -u "$EF_DB_ADMIN_USER" \
		--skip-comments --skip-extended-insert --order-by-primary "$m" "$DB_NAME" "$@" |
		"$PHP_BIN" -r 'echo hash("sha256", stream_get_contents(STDIN));'
}

row_count() { # row_count <table>
	mysql_q "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$1\`" | tail -1
}

table_count() {
	mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name LIKE 'fanfiction_%'" | tail -1
}

# ---- disposable environment ------------------------------------------------

# EF_WORK lets a driver (tests/compat/apache.sh) supply the disposable
# directory it already mounts into the HTTP fixture.
WORK="${EF_WORK:-$(mktemp -d "${TMPDIR:-/tmp}/efiction-http.XXXXXX")}"
WEB="$WORK/web"
TOKEN_FILE="$WORK/efiction-install-token.txt"
cleanup() {
	if [ -n "${SERVER_PID:-}" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
		kill "$SERVER_PID" 2>/dev/null || true
	fi
	if [ -n "${DB_NAME:-}" ]; then
		mysql_q "DROP DATABASE IF EXISTS \`$DB_NAME\`" >/dev/null 2>&1 || true
	fi
	if [ "${KEEP_WORK:-0}" = "1" ] && [ "$FAILURES" -gt 0 ]; then
		echo "work dir kept for inspection: $WORK" >&2
	else
		rm -rf "$WORK"
	fi
}
trap cleanup EXIT INT TERM

# The installer must never pre-create a default token.
check "installer does not pre-create a default token" test ! -f "$TOKEN_FILE"

# CI-only guard: generated database names must match the test pattern before
# any create or drop happens.
DB_NAME="efiction_test_$(openssl rand -hex 6)"
case "$DB_NAME" in
	efiction_test_[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9]) ;;
	*) echo "refusing to run: generated database name does not match the test pattern" >&2; exit 1 ;;
esac

# The owner secret: 32 random bytes as lowercase hex, private permissions,
# stored OUTSIDE the HTTP document root (parent of $WORK/web is $WORK).
# EF_TOKEN_MODE lets a driver relax the mode when the HTTP server user is a
# different uid (tests/compat/apache.sh); the file still lives in a private
# directory under $HOME for the duration of one run.
openssl rand -hex 32 > "$TOKEN_FILE"
chmod "${EF_TOKEN_MODE:-600}" "$TOKEN_FILE"
ADMIN_SECRET=$(cat "$TOKEN_FILE")

if [ "$MODE" = "fresh" ]; then
	mkdir -p "$WEB"
	if [ -n "${EF_RELEASE_ZIP:-}" ]; then
		# The release gate installs from the built uploadable ZIP.
		unzip -qo "$EF_RELEASE_ZIP" -d "$WEB"
	else
		(cd "$SOURCE_DIR" && tar --exclude='./.git' --exclude='./tests' --exclude='./dist' -cf - .) | tar -xf - -C "$WEB"
	fi
else
	mkdir -p "$WEB"
	# Upgrade fixture webroot: the provenance verified upstream 3.5.5 tree
	# (git main), plus the fixture config.php and uploaded stories below.
	git -C "$SOURCE_DIR" archive main | tar -xf - -C "$WEB"
fi

mysql_q "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4"

# ---- HTTP endpoint ----------------------------------------------------------
# EF_BASE_URL reuses the prepared assertions against an already running
# server (tests/compat/apache.sh points it at the Apache fixture). Without
# it, a php -S server on a dynamically allocated port serves as the fast
# local probe.
if [ -n "${EF_BASE_URL:-}" ]; then
	BASE="${EF_BASE_URL%/}"
else

PORT=$("$PHP_BIN" -r '
for ($i = 0; $i < 50; $i++) {
    $port = random_int(21000, 44000);
    $s = @socket_create_listen($port);
    if ($s !== false) { socket_close($s); echo $port; exit; }
}
exit(1);
')
if [ -z "${PORT:-}" ]; then echo "could not allocate a port" >&2; exit 1; fi

"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$WEB" \
	-d display_errors=1 -d error_reporting=E_ALL \
	-d opcache.enable=0 -d opcache.enable_cli=0 \
	>"$WORK/server.log" 2>&1 &
SERVER_PID=$!

i=0
until curl -s -o /dev/null "http://127.0.0.1:$PORT/index.php" 2>/dev/null; do
	i=$((i + 1))
	if [ "$i" -gt 100 ]; then echo "server did not start" >&2; exit 1; fi
	"$PHP_BIN" -r 'usleep(100000);'
done
BASE="http://127.0.0.1:$PORT"

fi

JAR="$WORK/cookies.txt"
ANON_JAR="$WORK/anon.txt"
BODY="$WORK/body.html"
HEADERS="$WORK/headers.txt"

http_get() { # http_get <url> [jar]
	jar=${2:-$JAR}
	curl -s --max-time 60 -D "$HEADERS" -o "$BODY" -w '%{http_code}' -b "$jar" -c "$jar" "$BASE$1"
}

http_post() { # http_post <url> <data> [jar] [origin]
	url=$1
	data=$2
	jar=${3:-$JAR}
	curl -s --max-time 60 -D "$HEADERS" -o "$BODY" -w '%{http_code}' \
		-b "$jar" -c "$jar" \
		${4:+-H "Origin: $4"} \
		--data "$data" "$BASE$url"
}

csrf_from_body() {
	grep -o "name='csrf' value='[0-9a-f]*'" "$BODY" | head -1 | sed "s/.*value='//;s/'//"
}

if [ "$MODE" = "fresh" ]; then

	# ---- step 1: config ----------------------------------------------------

	# GET renders the form and a session CSRF token; a fresh session sees the
	# visible secret field.
	status=$(http_get /install/install.php)
	check_status "GET installer step 1 returns 200" 200 "$status"
	check_contains "step 1 GET renders the config form" "name='dbhost'" "$BODY"
	check_contains "step 1 GET carries a CSRF token" "name='csrf'" "$BODY"
	check_contains "step 1 GET asks for the owner secret" "name='install_secret'" "$BODY"
	check_no_php_errors "step 1 GET has no PHP errors"

	# Absent owner secret: POST is denied, config untouched.
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_status "step 1 POST without secret is denied (403)" 403 "$status"
	check "step 1 POST without secret wrote no config" test ! -f "$WEB/config.php"

	# Wrong owner secret: denied as well.
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=0000000000000000000000000000000000000000000000000000000000000000&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_status "step 1 POST with wrong secret is denied (403)" 403 "$status"
	check "step 1 POST with wrong secret wrote no config" test ! -f "$WEB/config.php"

	# Missing CSRF: denied.
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_status "step 1 POST without CSRF is denied (403)" 403 "$status"

	# Forged Origin: denied even with valid secret and CSRF.
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=" \
		"$JAR" "https://evil.example")
	check_status "step 1 POST with forged Origin is denied (403)" 403 "$status"
	check "forged Origin attempt wrote no config" test ! -f "$WEB/config.php"

	# Correct step 1 POST: config.php is written atomically with a state
	# marker holding its SHA-256, and restrictive permissions.
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_status "step 1 POST with valid secret writes config (200)" 200 "$status"
	check "config.php exists" test -f "$WEB/config.php"
	check "state marker exists" test -f "$WORK/efiction-install-state.json"
	check_no_php_errors "step 1 POST has no PHP errors"
	CONFIG_SHA=$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")
	MARKER_SHA=$("$PHP_BIN" -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["config_sha256"] ?? "missing";' "$WORK/efiction-install-state.json")
	check "state marker carries the config hash" [ "$CONFIG_SHA" = "$MARKER_SHA" ]
	perms=$(stat -c '%a' "$WEB/config.php" 2>/dev/null || stat -f '%Lp' "$WEB/config.php")
	case "$perms" in
		[0-7]*) check "config.php is not world writable ($perms)" [ "$(( 0$perms & 022 ))" -eq 0 ] ;;
		*) check "config.php permission string parses (got: $perms)" false ;;
	esac

	# GET mutation attempt: the old GET install=automatic created tables and
	# wrote config; a GET must now render status only. Probed here because a
	# step 2 request before config.php exists cannot render at all.
	status=$(http_get "/install/install.php?step=2&install=automatic")
	check_no_php_errors "GET install=automatic renders without errors"
	check "GET install=automatic created no tables" [ "$(table_count)" -eq 0 ]

	# Anonymous new session after config write: cannot resume or overwrite.
	status=$(http_get /install/install.php "$ANON_JAR")
	check_contains "anonymous GET after config shows disabled status" "already configured" "$BODY"
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=" \
		"$ANON_JAR")
	check_status "anonymous step 1 POST without CSRF is denied" 403 "$status"
	CONFIG_AFTER_ANON=$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")
	check "anonymous attempt did not overwrite config" [ "$CONFIG_SHA" = "$CONFIG_AFTER_ANON" ]

	# ---- recovery path -----------------------------------------------------
	# Mismatched hash: tamper config.php, recovery must fail closed. The
	# original bytes are restored exactly (a trailing-newline trim would
	# change the hash the marker carries).
	cp "$WEB/config.php" "$WORK/config.php.bak"
	printf '\n' >> "$WEB/config.php"
	status=$(http_get /install/install.php "$ANON_JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"ef_action=recover&csrf=$CSRF&install_secret=$ADMIN_SECRET" "$ANON_JAR")
	check_status "recovery with tampered config is denied (403)" 403 "$status"
	mv "$WORK/config.php.bak" "$WEB/config.php"

	# Populated archive: an author row must block recovery.
	mysql_q "CREATE TABLE IF NOT EXISTS \`$DB_NAME\`.\`fanfiction_authors\` (uid INT PRIMARY KEY, penname VARCHAR(200))" >/dev/null
	mysql_q "INSERT INTO \`$DB_NAME\`.\`fanfiction_authors\` VALUES (10, 'Someone')" >/dev/null
	status=$(http_get /install/install.php "$ANON_JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"ef_action=recover&csrf=$CSRF&install_secret=$ADMIN_SECRET" "$ANON_JAR")
	check_status "recovery with content rows is denied (403)" 403 "$status"
	mysql_q "DROP TABLE \`$DB_NAME\`.\`fanfiction_authors\`" >/dev/null

	# Valid recovery on the content free partial install: config is backed
	# up and removed.
	status=$(http_get /install/install.php "$ANON_JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"ef_action=recover&csrf=$CSRF&install_secret=$ADMIN_SECRET" "$ANON_JAR")
	check_status "valid recovery returns 200" 200 "$status"
	check "recovery removed config.php" test ! -f "$WEB/config.php"
	check "recovery removed the state marker" test ! -f "$WORK/efiction-install-state.json"
	check "recovery saved a backup directory" test -n "$(find "$WORK" -maxdepth 1 -type d -name 'efiction-install-backup-*')"

	# Recovery deactivates the installer by deleting the one time secret.
	# The owner still controls the private directory and places a fresh
	# secret to continue testing.
	openssl rand -hex 32 > "$TOKEN_FILE"
	chmod 600 "$TOKEN_FILE"
	ADMIN_SECRET=$(cat "$TOKEN_FILE")

	# Quote and semicolon payload as a submitted config value: it must land
	# in config.php as a quoted string literal and never execute. A later
	# page request would print PWNEDMARKER if the payload ran.
	PAYLOAD="'; echo 'PWNEDMARKER'; //"
	status=$(http_get /install/install.php "$ANON_JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=$PAYLOAD" \
		"$ANON_JAR")
	check_status "payload install returns 200" 200 "$status"
	check_not_contains "payload stored escaped, not raw executable" "echo 'PWNEDMARKER'" "$WEB/config.php"
	status=$(http_get "/install/install.php?step=2" "$ANON_JAR")
	check_not_contains "payload never executes on a later request" "PWNEDMARKER" "$BODY"

	# Recovery clears the payload install so the real one starts clean.
	status=$(http_get /install/install.php "$ANON_JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"ef_action=recover&csrf=$CSRF&install_secret=$ADMIN_SECRET" "$ANON_JAR")
	check_status "cleanup recovery after payload probe returns 200" 200 "$status"

	# The cleanup recovery removed the secret again; place a fresh one.
	openssl rand -hex 32 > "$TOKEN_FILE"
	chmod 600 "$TOKEN_FILE"
	ADMIN_SECRET=$(cat "$TOKEN_FILE")

	# Re-run step 1 properly.
	status=$(http_get /install/install.php "$JAR")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_status "step 1 POST after recovery writes config again" 200 "$status"
	check "config.php exists after recovery install" test -f "$WEB/config.php"
	# The reference hash for the no-rewrite assertions is the bytes of THIS
	# install (a fresh sitekey makes each install's config unique).
	CONFIG_SHA=$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")

	# ---- steps 2 to 9 ------------------------------------------------------

	# step 2: settings table (POST only now).
	status=$(http_get "/install/install.php?step=2")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=2" "install=automatic&csrf=$CSRF&install_secret=$ADMIN_SECRET")
	check_status "step 2 automatic install returns 200" 200 "$status"
	check_no_php_errors "step 2 has no PHP errors"
	check "settings table created" test "$(table_count)" -ge 1

	# Missing secret on step 3: table count must not change.
	TABLES_BEFORE=$(table_count)
	status=$(http_get "/install/install.php?step=3")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=3" "install=automatic&csrf=$CSRF")
	check_status "step 3 POST without secret is denied (403)" 403 "$status"
	check "step 3 denied attempt created no tables" [ "$(table_count)" = "$TABLES_BEFORE" ]

	# step 3: the remaining 26 content tables.
	status=$(http_post "/install/install.php?step=3" "install=automatic&csrf=$CSRF&install_secret=$ADMIN_SECRET")
	check_status "step 3 automatic install returns 200" 200 "$status"
	check_no_php_errors "step 3 has no PHP errors"
	check "install created all 27 tables" [ "$(table_count)" -eq 27 ]

	# steps 4 to 8: fixture data (panels, links, blocks, messages, fields).
	for step in 4 5 6 7 8; do
		status=$(http_get "/install/install.php?step=$step")
		CSRF=$(csrf_from_body)
		status=$(http_post "/install/install.php?step=$step" "install=automatic&csrf=$CSRF&install_secret=$ADMIN_SECRET")
		check_status "step $step automatic install returns 200" 200 "$status"
		check_no_php_errors "step $step has no PHP errors"
	done

	# step 9: admin account; repeated submission must not add a second admin.
	ADMINS_BEFORE=$(row_count fanfiction_authors)
	status=$(http_get "/install/install.php?step=9")
	CSRF=$(csrf_from_body)
	status=$(http_post "/install/install.php?step=9" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&newpenname=SiteAdmin&email=admin@example.com&password=correct horse battery staple 123&password2=correct horse battery staple 123")
	check_status "step 9 admin creation returns 200" 200 "$status"
	check_no_php_errors "step 9 has no PHP errors"
	check "admin account created" test "$(row_count fanfiction_authors)" -gt "$ADMINS_BEFORE"
	ADMINS_AFTER=$(row_count fanfiction_authors)
	# Repeated step 9 submission: the secret is gone after completion, so the
	# gate denies and the admin count is stable.
	status=$(http_post "/install/install.php?step=9" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&newpenname=SecondAdmin&email=admin2@example.com&password=another password 456&password2=another password 456")
	ADMINS_REPEAT=$(row_count fanfiction_authors)
	check "repeated step 9 did not create a second admin" [ "$ADMINS_REPEAT" = "$ADMINS_AFTER" ]

	# After success the secret and marker are gone; every installer action is
	# rejected. A locked installer renders its status page (200) and must not
	# mutate anything; the assertions are the status text and the config hash.
	check "owner secret removed after completed install" test ! -f "$TOKEN_FILE"
	check "state marker removed after completed install" test ! -f "$WORK/efiction-install-state.json"
	status=$(http_get /install/install.php)
	check_contains "installer shows disabled status after completion" "already configured" "$BODY"
	status=$(http_post "/install/install.php?step=1" \
		"submit=submit&csrf=$CSRF&install_secret=$ADMIN_SECRET&dbhost=$EF_INSTALL_DBHOST&dbname=$DB_NAME&dbuser=$EF_DB_ADMIN_USER&dbpass=$EF_DB_ADMIN_PASSWORD&sitekey=&language=en&settingsprefix=")
	check_contains "installer actions rejected after completion" "already configured" "$BODY"
	check "post-completion attempt did not rewrite config" [ "$CONFIG_SHA" = "$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")" ]

	# ---- site smoke --------------------------------------------------------

	status=$(http_get /index.php)
	check_status "home page loads (200)" 200 "$status"
	check_no_php_errors "home page has no PHP errors"

	status=$(http_get "/user.php?action=login")
	status=$(http_post "/user.php?action=login" \
		"submit=submit&penname=SiteAdmin&password=correct%20horse%20battery%20staple%20123")
	check_status "admin login returns 200" 200 "$status"
	check_no_php_errors "login has no PHP errors"
	# Session proof: with the login session the account page no longer shows
	# the login form.
	status=$(http_get /user.php)
	check_not_contains "login session shows the account page, not the form" 'name="pswd"' "$BODY"
	check_no_php_errors "account page has no PHP errors"

	status=$(http_get "/viewstory.php?sid=1")
	check_status "missing story page renders safely (200)" 200 "$status"
	check_no_php_errors "missing story page has no PHP errors"

	status=$(http_get "/user.php?action=logout")
	check_status "logout returns 200" 200 "$status"

	# Direct HTTP request must not expose config.php contents or the token.
	# The status is asserted so a dead server or a curl failure cannot make
	# these checks pass vacuously. An empty admin password makes the dbpass
	# value needle match every body (grep -F ""), so that assertion runs
	# only with a nonempty password; the source-literal and dbname needles
	# cover the empty-password case: an executed config.php returns an empty
	# body, a dumped one always contains the literal assignments.
	status=$(curl -s --max-time 60 -o "$BODY" -w '%{http_code}' "$BASE/config.php")
	check_status "config.php request returns 200 (executed, not dumped)" 200 "$status"
	check_not_contains "config.php source not disclosed (no dbpass assignment)" '$dbpass' "$BODY"
	if [ -n "$EF_DB_ADMIN_PASSWORD" ]; then
		check_not_contains "config.php not readable as text (no dbpass value)" "$EF_DB_ADMIN_PASSWORD" "$BODY"
	fi
	check_not_contains "config.php not readable as text (no dbname)" "$DB_NAME" "$BODY"
	# curl squashes /../ dot segments per RFC 3986 before sending, so
	# without --path-as-is the traversal to the token file outside the
	# docroot is never actually requested and the check below cannot fail.
	status=$(curl -s --path-as-is --max-time 60 -o "$BODY" -w '%{http_code}' "$BASE/../efiction-install-token.txt")
	check "token traversal request is rejected (status $status, not 200)" [ "$status" != "200" ]
	check_not_contains "token file not web readable" "$ADMIN_SECRET" "$BODY"

else
	# ---- upgrade mode ------------------------------------------------------
	# Load the preserved 3.5.5 fixture into the database, write the fixture
	# config.php and stories into the upstream 3.5.5 webroot, then copy the
	# fork over it without replacing config.php or stories/.

	MYSQL_PWD="${EF_DB_ADMIN_PASSWORD}" gzip -dc "$FIXTURE_DIR/efiction-3.5.5.sql.gz" |
		MYSQL_PWD="${EF_DB_ADMIN_PASSWORD}" "$MYSQL_BIN" -h "$EF_DB_HOST" ${EF_DB_PORT:+-P "$EF_DB_PORT"} -u "$EF_DB_ADMIN_USER" "$DB_NAME"

	write_fixture_config() {
		sed -e "s|%%DBHOST%%|$EF_INSTALL_DBHOST|" \
			-e "s|%%DBNAME%%|$DB_NAME|" \
			-e "s|%%DBUSER%%|$EF_DB_ADMIN_USER|" \
			-e "s|%%DBPASS%%|$EF_DB_ADMIN_PASSWORD|" \
			"$FIXTURE_DIR/config.php.in" > "$WEB/config.php"
		chmod 644 "$WEB/config.php"
	}
	write_fixture_config
	rm -rf "$WEB/stories"
	cp -R "$FIXTURE_DIR/stories" "$WEB/stories"

	copy_fork_over_fixture() {
		find "$WEB" -mindepth 1 -maxdepth 1 \
			! -name config.php ! -name stories ! -name .htaccess -exec rm -rf {} +
		if [ -n "${EF_RELEASE_ZIP:-}" ]; then
			# File-overwrite upgrade from the release ZIP: config.php and the
			# uploaded stories stay in place.
			unzip -qo "$EF_RELEASE_ZIP" -x "config.php" "stories/*" -d "$WEB"
		else
			(cd "$SOURCE_DIR" && tar --exclude='./.git' --exclude='./tests' --exclude='./dist' -cf - .) | tar -xf - -C "$WEB"
		fi
	}
	copy_fork_over_fixture

	SCHEMA_BEFORE=$(db_hash --no-data)
	CONFIG_BEFORE=$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")
	FILES_BEFORE=$("$PHP_BIN" "$SCRIPT_DIR/hash-tree.php" "$WEB/stories")

	# Admin login with the fixture admin.
	status=$(http_get "/user.php?action=login")
	status=$(http_post "/user.php?action=login" "submit=submit&penname=FixtureAdmin&password=fixturepass123")
	check_status "fixture admin login returns 200" 200 "$status"
	check_no_php_errors "fixture login has no PHP errors"
	# Session proof: the account page no longer shows the login form.
	status=$(http_get /user.php)
	check_not_contains "fixture admin session works" 'name="pswd"' "$BODY"

	status=$(http_get /index.php)
	check_status "upgraded home page loads" 200 "$status"
	check_no_php_errors "upgraded home page has no PHP errors"
	status=$(http_get "/viewstory.php?sid=1")
	check_status "upgraded story page loads" 200 "$status"
	check_no_php_errors "upgraded story page has no PHP errors"
	status=$(http_get "/stories.php?action=editstory&sid=1&admin=1")
	check_status "upgraded edit form loads" 200 "$status"
	check_no_php_errors "upgraded edit form has no PHP errors"
	status=$(http_get "/viewpage.php?page=welcome")
	check_status "upgraded custom page loads" 200 "$status"
	check_no_php_errors "upgraded custom page has no PHP errors"

	# A GET of update.php?confirm=yes is a separate no-write assertion.
	status=$(http_get "/update.php?confirm=yes")
	check_status "update.php GET confirm renders 200" 200 "$status"
	check_contains "update.php reports no database update needed" "no database update" "$BODY"

	# Reader visits bump upstream's own story/chapter view counters, so the
	# data reference for the replacement comparison is taken here, after the
	# smoke and before the second replacement. The replacement itself must
	# cause no writes.
	DATA_AFTER_SMOKE=$(db_hash --no-create-info fanfiction_authors fanfiction_stories fanfiction_chapters fanfiction_ratings)

	# Second identical replacement (idempotence).
	copy_fork_over_fixture

	check "schema hash unchanged after replacement" [ "$SCHEMA_BEFORE" = "$(db_hash --no-data)" ]
	check "preserved data hash unchanged after replacement" [ "$DATA_AFTER_SMOKE" = "$(db_hash --no-create-info fanfiction_authors fanfiction_stories fanfiction_chapters fanfiction_ratings)" ]
	check "config hash unchanged after replacement" [ "$CONFIG_BEFORE" = "$("$PHP_BIN" -r 'echo hash_file("sha256", $argv[1]);' "$WEB/config.php")" ]
	check "story files hash unchanged after replacement" [ "$FILES_BEFORE" = "$("$PHP_BIN" "$SCRIPT_DIR/hash-tree.php" "$WEB/stories")" ]
fi

if [ "$FAILURES" -gt 0 ]; then
	echo "$FAILURES check(s) failed" >&2
	exit 1
fi
echo "http $MODE ok"
