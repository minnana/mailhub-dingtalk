#!/usr/bin/env bash
# Historical filename retained; this shared-host edition ONLY checks Docker.
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
command -v docker >/dev/null || { echo 'Docker missing. Host administrator must install it separately.' >&2; exit 1; }
docker info >/dev/null
docker compose version >/dev/null
echo 'Existing Docker and Compose detected. No installation, restart, daemon or firewall changes made.'
