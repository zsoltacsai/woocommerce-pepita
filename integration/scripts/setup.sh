#!/usr/bin/env bash
set -euo pipefail
source "$(dirname -- "$0")/common.sh"

command -v docker >/dev/null || { echo "Docker CLI is required." >&2; exit 2; }
docker info >/dev/null 2>&1 || { echo "Docker daemon unavailable; cannot provision PHP 8.5 / MySQL 8.4 integration services." >&2; exit 2; }

if [[ ! -f "$INTEGRATION_DIR/.env" ]]; then
	cp "$INTEGRATION_DIR/.env.example" "$INTEGRATION_DIR/.env"
	chmod 600 "$INTEGRATION_DIR/.env"
	ENV_FILE="$INTEGRATION_DIR/.env"
fi

"$PROJECT_DIR/bin/build.sh"
dc up -d --wait --remove-orphans db wordpress-hpos wordpress-legacy cli-hpos cli-legacy
dc exec -T db sh -ec 'mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/01-create-legacy-db.sql'

for service in wordpress-hpos wordpress-legacy; do
	for attempt in $(seq 1 60); do
		if dc exec -T "$service" php -r 'exit(is_file("/var/www/html/wp-config.php") ? 0 : 1);' >/dev/null 2>&1; then break; fi
		sleep 2
		if [[ "$attempt" == 60 ]]; then echo "Timed out waiting for $service WordPress files." >&2; exit 1; fi
	done
done

source "$ENV_FILE"
install_site cli-hpos hpos "http://wordpress-hpos" yes
install_site cli-legacy legacy "http://wordpress-legacy" no

echo "Integration stores are ready: HPOS at http://localhost:${HPOS_PORT:-8080}, legacy at http://localhost:${LEGACY_PORT:-8081}."
