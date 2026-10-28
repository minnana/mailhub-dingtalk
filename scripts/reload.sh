#!/usr/bin/env bash
# Safely reload only this project's app/cron/proxy after configuration changes.
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../templates" && pwd)/lib.sh"
[[ $# == 0 ]] || die 'Usage: reload.sh'
load_runtime
lock_runtime
dc config --quiet
app_id=$(dc ps --status running -q app)
[[ -n $app_id ]] || die 'App must be running before reload.'
[[ $(docker inspect --format '{{.Config.Image}}' "$app_id") == "$NEXTCLOUD_IMAGE" ]] || die 'Nextcloud image changes require update.sh and its backup; reload only handles runtime/source changes.'
status=$(dc exec -T -u www-data app php occ status --output=json)
maintenance_was_on=$(printf '%s' "$status" | python3 -c 'import json,sys; s=json.load(sys.stdin); assert s.get("installed") and not s.get("needsDbUpgrade"), "Application must be installed and upgraded"; print(int(bool(s.get("maintenance"))))')
dc exec -T -u www-data app php occ maintenance:mode --on
if ! drain_cron; then
  if (( !maintenance_was_on )); then dc exec -T -u www-data app php occ maintenance:mode --off; fi
  die 'Could not drain current background work; runtime was not reloaded.'
fi
complete=0
on_exit() {
  local result=$?
  trap - EXIT INT TERM
  if (( !complete )); then
    dc exec -T -u www-data app php occ maintenance:mode --on >&2 || true
    echo 'Reload failed; leave maintenance enabled, inspect app/proxy state and retry reload.sh.' >&2
  fi
  exit "$result"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
dc up -d --no-deps --force-recreate --wait --wait-timeout 240 app
dc up -d --no-deps --force-recreate --wait --wait-timeout 120 nginx
if (( !maintenance_was_on )); then dc exec -T -u www-data app php occ maintenance:mode --off; fi
dc up -d --no-deps --force-recreate --wait --wait-timeout 120 cron
complete=1
echo 'Mailhub app, cron and proxy reloaded; the original maintenance setting is preserved.'
flock -u 9
if (( !maintenance_was_on )); then bash "$MAILHUB_ROOT/scripts/healthcheck.sh"; fi
