#!/bin/sh
# Apache release gate for the eFiction maintenance fork.
#
# Builds the uploadable ZIP (tests/compat/package.sh), serves the EXTRACTED
# ZIP with Apache 2.4 and the matching PHP in a disposable container built
# from tests/compat/Dockerfile, runs a disposable MariaDB 10.11 container on
# the same network, and drives the exact same browser-install assertions as
# tests/compat/http.sh by pointing it at the container with EF_BASE_URL.
# The php -S probe cannot prove the Apache shared-host path; this script can.
#
# Usage: tests/compat/apache.sh [fresh|upgrade]
#   PHP_VERSION=8.3|8.4    image tag (default 8.3)
#
# Everything is disposable: containers, network, database, and the work
# directory under $HOME (the only host path colima shares with containers).
set -eu

MODE="${1:-fresh}"
if [ "$MODE" != "fresh" ] && [ "$MODE" != "upgrade" ]; then
	echo "usage: $0 [fresh|upgrade]" >&2
	exit 2
fi

PHP_VERSION="${PHP_VERSION:-8.3}"
PHP_BIN="${PHP_BIN:-php}"
# MariaDB-family clients talk to the MariaDB service containers; a MySQL
# 9.x client cannot load the native password plugin at all. Homebrew keeps
# the versioned client outside PATH, so check it before falling back.
pick_client() { # pick_client <varname> <candidates...>
	varname=$1
	shift
	if [ -n "$(eval "echo \${$varname:-}")" ]; then return 0; fi
	for c in "$@"; do
		if [ -n "$c" ] && [ -x "$c" ]; then
			eval "$varname=\$c"
			return 0
		fi
	done
	return 1
}
pick_client MYSQL_BIN \
	"$(command -v mariadb || true)" \
	/opt/homebrew/opt/mariadb@10.6/bin/mariadb \
	"$(command -v mysql || true)" || {
	echo "no mysql or mariadb client found" >&2
	exit 1
}
pick_client MYSQLDUMP_BIN \
	"$(command -v mariadb-dump || true)" \
	/opt/homebrew/opt/mariadb@10.6/bin/mariadb-dump \
	"$(command -v mysqldump || true)" || {
	echo "no mysqldump or mariadb-dump found" >&2
	exit 1
}

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
SOURCE_DIR=$(cd "$SCRIPT_DIR/../.." && pwd)

command -v docker >/dev/null 2>&1 || { echo "docker is required for the Apache gate" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "the docker daemon is not running" >&2; exit 1; }
command -v unzip >/dev/null 2>&1 || { echo "unzip is required" >&2; exit 1; }

# The uploadable ZIP is the release artifact under test.
sh "$SCRIPT_DIR/package.sh" >/dev/null
ZIP="$SOURCE_DIR/dist/efiction-3.5.5-maint.zip"

NAME="efiction-gate-$$"
DBNAME="efiction-gate-db-$$"
NET="efiction-gate-$$"
DB_ADMIN_USER="efgate"
DB_ADMIN_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)

# Containers can only see paths under $HOME on colima, and mounted files
# appear root-owned inside the container, so the work directory must be
# writable by the container's Apache user (www-data).
WORK=$(mktemp -d "${HOME:-/tmp}/.efiction-apache.XXXXXX")
chmod 0777 "$WORK"
cleanup() {
	docker rm -f "$NAME" "$DBNAME" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

# Database service with a privileged test account (the harness creates and
# drops its own randomly named databases).
docker network create "$NET" >/dev/null
docker run -d --name "$DBNAME" --network "$NET" -p 127.0.0.1::3306 \
	-e "MARIADB_ROOT_PASSWORD=$DB_ROOT_PASSWORD" \
	-e "MARIADB_USER=$DB_ADMIN_USER" \
	-e "MARIADB_PASSWORD=$DB_ADMIN_PASSWORD" \
	-e "MARIADB_DATABASE=efiction_gate_init" \
	mariadb:10.11 >/dev/null
i=0
# mariadb-admin ping exits 0 on access denied (the server is alive), so wait
# for a real authenticated query instead.
until docker exec "$DBNAME" mariadb -uroot -p"$DB_ROOT_PASSWORD" -e 'SELECT 1' >/dev/null 2>&1; do
	i=$((i + 1))
	if [ "$i" -gt 150 ]; then
		echo "the MariaDB fixture did not start:" >&2
		docker logs "$DBNAME" 2>&1 | tail -10 >&2
		exit 1
	fi
	"$PHP_BIN" -r 'usleep(200000);'
done
# Global privileges: the harness creates and drops efiction_test_* databases.
docker exec "$DBNAME" mariadb -uroot -p"$DB_ROOT_PASSWORD" \
	-e "GRANT ALL PRIVILEGES ON *.* TO '$DB_ADMIN_USER'@'%'; FLUSH PRIVILEGES;" >/dev/null
# Host-side client access for the harness's mysql_q/db_hash.
DB_PORT=$(docker port "$DBNAME" 3306 | awk -F': ' '{print $NF}' | head -1 | awk -F: '{print $NF}')

# Apache fixture: the harness extracts the ZIP under $WORK/web. Two nested
# mounts give the shared-host layout: the document root /var/www/html is
# $WORK/web, and the installer's private directory /var/www is $WORK, where
# the owner secret and the state marker live.
docker build -q -t "efiction-gate:php$PHP_VERSION" \
	--build-arg "php_version=$PHP_VERSION" \
	-f "$SCRIPT_DIR/Dockerfile" "$SCRIPT_DIR" >/dev/null
docker run -d --name "$NAME" --network "$NET" -p 127.0.0.1::80 \
	-v "$WORK":/var/www \
	-v "$WORK/web":/var/www/html \
	"efiction-gate:php$PHP_VERSION" >/dev/null
PORT=$(docker port "$NAME" 80 | awk -F': ' '{print $NF}' | head -1 | awk -F: '{print $NF}')
[ -n "$PORT" ] || { echo "could not determine the container port" >&2; exit 1; }
BASE="http://127.0.0.1:$PORT"

i=0
until curl -s -o /dev/null "$BASE/"; do
	i=$((i + 1))
	if [ "$i" -gt 100 ]; then
		echo "the Apache fixture did not start:" >&2
		docker logs "$NAME" 2>&1 | tail -20 >&2
		exit 1
	fi
	"$PHP_BIN" -r 'usleep(200000);'
done

# Same assertions as the php -S probe, against Apache and the extracted ZIP.
# The installer sees the database under the service container's name; the
# host-side mysql client goes through the published port.
EF_BASE_URL="$BASE" \
EF_WORK="$WORK" \
EF_RELEASE_ZIP="$ZIP" \
EF_TOKEN_MODE=644 \
EF_DB_HOST="127.0.0.1" \
EF_DB_PORT="$DB_PORT" \
EF_INSTALL_DBHOST="$DBNAME:3306" \
EF_DB_ADMIN_USER="$DB_ADMIN_USER" \
EF_DB_ADMIN_PASSWORD="$DB_ADMIN_PASSWORD" \
PHP_BIN="$PHP_BIN" \
MYSQL_BIN="$MYSQL_BIN" \
MYSQLDUMP_BIN="$MYSQLDUMP_BIN" \
	sh "$SCRIPT_DIR/http.sh" "$MODE"

echo "apache $MODE (php $PHP_VERSION) ok"
