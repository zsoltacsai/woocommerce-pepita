#!/usr/bin/env bash
set -euo pipefail

INTEGRATION_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_DIR="$(cd -- "$INTEGRATION_DIR/.." && pwd)"
ENV_FILE="$INTEGRATION_DIR/.env"
[[ -f "$ENV_FILE" ]] || ENV_FILE="$INTEGRATION_DIR/.env.example"

dc() {
	docker compose --project-directory "$PROJECT_DIR" --env-file "$ENV_FILE" -f "$INTEGRATION_DIR/docker-compose.yml" "$@"
}

wp_cli() {
	local site_path="$1" site_url="$2"
	shift 2
	dc exec -T -e "WPS_IT_SITE_URL=$site_url" cli wp --path="$site_path" "$@"
}

install_site() {
	local suffix="$1" url="$2" hpos="$3" site_path="/var/www/html/$suffix"
	local admin_user admin_password admin_email wc_version feed_token order_key
	admin_user="$(awk -F= '$1=="WORDPRESS_ADMIN_USER" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
	admin_password="$(awk -F= '$1=="WORDPRESS_ADMIN_PASSWORD" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
	admin_email="$(awk -F= '$1=="WORDPRESS_ADMIN_EMAIL" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
	wc_version="$(awk -F= '$1=="WOOCOMMERCE_VERSION" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
	feed_token="$(awk -F= '$1=="WPS_FEED_TOKEN" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"
	order_key="$(awk -F= '$1=="WPS_ORDER_API_KEY" {print substr($0,index($0,"=")+1)}' "$ENV_FILE")"

	if ! wp_cli "$site_path" "$url" core is-installed >/dev/null 2>&1; then
		wp_cli "$site_path" "$url" core install --url="$url" --title="WP Pepita Integration ($suffix)" --admin_user="$admin_user" --admin_password="$admin_password" --admin_email="$admin_email" --skip-email
	fi
	wp_cli "$site_path" "$url" plugin install woocommerce --version="$wc_version" --activate
	dc exec -T cli sh -ec "mkdir -p '$site_path/wp-content/mu-plugins'; cp /integration/mu-plugins/*.php '$site_path/wp-content/mu-plugins/'"
	dc exec -T cli wp --path="$site_path" plugin install /dist/wp-pepita-sync.zip --force --activate
	wp_cli "$site_path" "$url" option update woocommerce_currency HUF
	wp_cli "$site_path" "$url" option update woocommerce_calc_taxes no
	wp_cli "$site_path" "$url" option update wps_pepita_feed_token "$feed_token"
	wp_cli "$site_path" "$url" option update wps_pepita_order_api_key "$order_key"
	if [[ "$hpos" == yes ]]; then
		wp_cli "$site_path" "$url" option update woocommerce_custom_orders_table_enabled yes
	else
		wp_cli "$site_path" "$url" option update woocommerce_custom_orders_table_enabled no
	fi
	wp_cli "$site_path" "$url" eval-file /integration/tests/seed.php
}
