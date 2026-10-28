#!/usr/bin/env bash
# Only reviewed, pinned Nextcloud images in the currently installed major release.
set -Eeuo pipefail
umask 077
ROOT=/opt/mailhub
BACKUP_DIR=/mnt/mailhub-backup
IMAGE=
usage() { echo 'Usage: update.sh --image nextcloud:34.0.x-apache@sha256:<64 hex> [--root /opt/mailhub] [--backup-dir /mnt/mailhub-backup]' >&2; }
while (($#)); do
  case "$1" in
    --image) IMAGE=${2:?Missing --image value}; shift 2 ;;
    --root) ROOT=${2:?Missing --root value}; shift 2 ;;
    --backup-dir) BACKUP_DIR=${2:?Missing --backup-dir value}; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) usage; exit 2 ;;
  esac
done
die() { echo "Update: $*" >&2; exit 1; }
[[ $EUID == 0 ]] || die 'run as root.'
[[ -n $IMAGE ]] || { usage; exit 2; }
for command in docker python3 flock realpath; do command -v "$command" >/dev/null || die "required command missing: $command"; done
ROOT=$(realpath -e -- "$ROOT")
[[ $ROOT == /opt/mailhub ]] || die 'this deployment supports only /opt/mailhub.'
[[ -f $ROOT/compose.yaml && -f $ROOT/runtime/.env && -f $ROOT/scripts/backup.sh ]] || die 'invalid installation.'
exec 9>/run/lock/mailhub-maintenance.lock
flock -n 9 || die 'another Mailhub maintenance operation is running.'
dc() { docker compose --project-name mailhub --project-directory "$ROOT" --env-file "$ROOT/runtime/.env" -f "$ROOT/compose.yaml" "$@"; }
dc config -q
stage=$(mktemp -d "$ROOT/runtime/.update-stage.XXXXXXXX")
cp -p -- "$ROOT/runtime/.env" "$stage/env.before"
dc exec -T -u www-data app php occ status --output=json >"$stage/status.before.json"
python3 - "$stage/env.before" "$stage/status.before.json" "$IMAGE" "$stage/env.next" <<'PY'
import json, pathlib, re, sys
env_path, status_path, image, output = sys.argv[1:]
m = re.fullmatch(r'nextcloud:(\d+)\.(\d+)\.(\d+)-apache@sha256:([0-9a-f]{64})', image)
if not m:
    raise SystemExit('Use an exact official nextcloud:X.Y.Z-apache@sha256:... image, not latest or an unpinned tag.')
status = json.load(open(status_path))
if not status.get('installed') or status.get('maintenance') or status.get('needsDbUpgrade'):
    raise SystemExit('Current Nextcloud must be installed, healthy and out of maintenance mode.')
current = tuple(int(v) for v in status['version'].split('.')[:3])
new = tuple(int(m.group(i)) for i in (1, 2, 3))
if new[0] != current[0] or new <= current:
    raise SystemExit('Only a higher patch/minor version within the current Nextcloud major is supported. Major migrations require a separate compatibility plan.')
text = pathlib.Path(env_path).read_text()
pattern = r'(?m)^NEXTCLOUD_IMAGE=.*$'
if len(re.findall(pattern, text)) != 1:
    raise SystemExit('.env must contain exactly one NEXTCLOUD_IMAGE line.')
pathlib.Path(output).write_text(re.sub(pattern, 'NEXTCLOUD_IMAGE=' + image, text))
PY
# Download and inspect the immutable candidate before interrupting users.
docker pull "$IMAGE"
docker run --rm --entrypoint php "$IMAGE" -r 'include "/usr/src/nextcloud/version.php"; echo implode(".", array_slice($OC_Version, 0, 3));' >"$stage/image-version"
python3 - "$IMAGE" "$stage/image-version" <<'PY'
import pathlib, re, sys
expected = re.match(r'nextcloud:([0-9.]+)-apache@', sys.argv[1]).group(1)
if pathlib.Path(sys.argv[2]).read_text().strip() != expected:
    raise SystemExit('Image contents do not match the requested version tag.')
PY
changed=0
success=0
quiesced=0
history=$stage
cron_was_running=$(dc ps --status running -q cron)
on_exit() {
  local result=$?
  trap - EXIT INT TERM
  if (( !success && changed )); then
    echo "Update failed. Previous environment and backup pointer: $history" >&2
    echo 'Keep maintenance enabled. Do not downgrade against a migrated database; restore the full backup if necessary.' >&2
    dc exec -T -u www-data app php occ maintenance:mode --on >&2 || true
    python3 "$ROOT/scripts/drain-cron.py" --timeout 900 >&2 || true
    dc stop --timeout 60 nginx >&2 || true
  elif (( !success && quiesced )); then
    dc exec -T -u www-data app php occ maintenance:mode --off >&2 || true
    if [[ -n $cron_was_running ]]; then dc start cron >&2 || true; fi
  fi
  exit "$result"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
quiesced=1
dc exec -T -u www-data app php occ maintenance:mode --on
python3 "$ROOT/scripts/drain-cron.py" --timeout 900
echo 'Taking a consistent backup before changing the installation...' >&2
backup_archive=$(MAILHUB_LOCK_HELD=1 bash "$ROOT/scripts/backup.sh" --root "$ROOT" --backup-dir "$BACKUP_DIR")
[[ -f $backup_archive ]] || die 'backup did not return an archive.'
history="$ROOT/runtime/update-history/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p -m 700 -- "$history"
mv -- "$stage/env.before" "$history/env.before"
cp -p -- "$stage/env.next" "$history/env.after"
printf '%s\n' "$backup_archive" >"$history/backup-path"
dc exec -T -u www-data app php occ maintenance:mode --on
changed=1
dc stop --timeout 60 nginx cron app
install -m 600 -- "$stage/env.next" "$ROOT/runtime/.env"
# The official entrypoint copies the new application and performs its required upgrade.
dc up -d --no-deps app
ready=0
for ((attempt=0; attempt<150; attempt++)); do
  if dc exec -T -u www-data app php occ status --output=json >"$stage/status.after.json" 2>/dev/null &&
    python3 - "$stage/status.after.json" "$stage/image-version" <<'PY'
import json, pathlib, sys
s = json.load(open(sys.argv[1]))
expected = pathlib.Path(sys.argv[2]).read_text().strip()
actual = '.'.join(s.get('version', '').split('.')[:3])
raise SystemExit(0 if s.get('installed') and not s.get('needsDbUpgrade') and actual == expected else 1)
PY
  then ready=1; break; fi
  sleep 2
done
((ready)) || die 'upgrade did not complete within five minutes; inspect app logs before taking further action.'
dc exec -T -u www-data app php occ integrity:check-core
dc exec -T -u www-data app php occ app:list --enabled --output=json >"$stage/apps.after.json"
python3 - "$stage/apps.after.json" <<'PY'
import json, sys
apps = json.load(open(sys.argv[1]))
if 'mail' not in apps.get('enabled', {}):
    raise SystemExit('Mail app is not enabled after upgrade. Resolve compatibility before reopening access.')
PY
python3 "$ROOT/templates/mail_sync_token_patch.py" apply
dc exec -T -u www-data app php occ maintenance:mode --off
dc up -d --no-deps cron
# nginx resolves app when it starts; recreating prevents a stale upstream IP.
dc up -d --no-deps --force-recreate nginx
mv -- "$stage/status.before.json" "$stage/status.after.json" "$stage/apps.after.json" "$stage/image-version" "$history/"
rm -f -- "$stage/env.next"
rmdir -- "$stage"
success=1
echo "Nextcloud updated to $IMAGE. Full backup: $backup_archive"
echo 'Mail, MariaDB, Redis, nginx and acme images were not upgraded; each requires its own compatibility review.'
echo 'Verify sign-in, both sending and receiving, attachment handling, and background notifications.'
