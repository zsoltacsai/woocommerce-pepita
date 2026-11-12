#!/usr/bin/env bash
set -euo pipefail
source "$(dirname -- "$0")/common.sh"

docker info >/dev/null 2>&1 || { echo "Docker daemon unavailable; nothing was removed." >&2; exit 2; }

dc down --volumes --remove-orphans
echo "The isolated integration containers and volumes were removed. Result logs were preserved."
