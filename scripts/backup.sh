#!/usr/bin/env bash
# Consistent, root-only backup. Archives contain credentials and are not encrypted.
set -Eeuo pipefail
umask 077

ROOT=/opt/mailhub
BACKUP_DIR=/mnt/mailhub-backup
usage() { echo 'Usage: backup.sh [--root /opt/mailhub] [--backup-dir /mnt/mailhub-backup]' >&2; }
while (($#)); do
  case "$1" in
    --root) ROOT=${2:?Missing --root value}; shift 2 ;;
    --backup-dir) BACKUP_DIR=${2:?Missing --backup-dir value}; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) usage; exit 2 ;;
  esac
done
die() { echo "Backup: $*" >&2; exit 1; }
[[ $EUID == 0 ]] || die 'run as root.'
for command in docker python3 findmnt mountpoint flock tar gzip sha256sum realpath; do
  command -v "$command" >/dev/null || die "required command missing: $command"
done
ROOT=$(realpath -e -- "$ROOT")
BACKUP_DIR=$(realpath -e -- "$BACKUP_DIR")
[[ $ROOT == /opt/mailhub ]] || die 'this deployment supports only /opt/mailhub.'
[[ $ROOT != / && -f $ROOT/compose.yaml && -f $ROOT/runtime/.env ]] || die 'invalid installation directory.'
mountpoint -q -- "$BACKUP_DIR" || die 'backup-dir must be an already mounted external backup filesystem.'
[[ $(findmnt -n -o MAJ:MIN --target "$BACKUP_DIR") != "$(findmnt -n -o MAJ:MIN --target "$ROOT")" ]] || die 'backup filesystem must differ from the installation filesystem.'
[[ $(stat -c %u -- "$BACKUP_DIR") == 0 ]] || die 'backup mountpoint must be owned by root.'
backup_mode=$(stat -c %a -- "$BACKUP_DIR")
(( (8#$backup_mode & 022) == 0 )) || die 'backup mountpoint must not be writable by group or other users.'
case "$BACKUP_DIR/" in "$ROOT/"*) die 'backup destination cannot be inside the installation.' ;; esac

# update.sh inherits the same lock while invoking this script.
if [[ ${MAILHUB_LOCK_HELD:-0} != 1 || ! -e /proc/self/fd/9 ]]; then
  exec 9>/run/lock/mailhub-maintenance.lock
fi
flock -n 9 || die 'another Mailhub maintenance operation is running.'
dc() { docker compose --project-name mailhub --project-directory "$ROOT" --env-file "$ROOT/runtime/.env" -f "$ROOT/compose.yaml" "$@"; }
dc config -q
running=$(dc ps --status running --services)
grep -qx app <<<"$running" || die 'app must be running before backup.'
grep -qx db <<<"$running" || die 'database must be running before backup.'

stage=$(mktemp -d "$BACKUP_DIR/.mailhub-backup.XXXXXXXX")
mkdir -m 700 "$stage/backup-meta"
partial=
restore_runtime=0
maintenance_was_on=0
resume_services=()
for service in nginx app cron; do
  if grep -qx "$service" <<<"$running"; then resume_services+=("$service"); fi
done
cleanup() {
  local result=$?
  trap - EXIT INT TERM
  if ((restore_runtime)); then
    echo 'Restoring original Mailhub service state...' >&2
    if ! dc start "${resume_services[@]}" >&2; then
      echo 'ERROR: service restart failed. Run docker compose start in the installation directory.' >&2
      result=1
    elif (( ! maintenance_was_on )); then
      local ready=0
      for ((attempt=0; attempt<60; attempt++)); do
        if dc exec -T -u www-data app php occ maintenance:mode --off >&2; then ready=1; break; fi
        sleep 2
      done
      if (( ! ready )); then
        echo 'ERROR: maintenance mode remains enabled; investigate app startup and turn it off after recovery.' >&2
        result=1
      fi
    fi
  fi
  [[ -z $partial || ! -f $partial ]] || rm -f -- "$partial"
  rm -f -- "$stage/backup-meta/database.sql" "$stage/backup-meta/manifest.json" "$stage/backup-meta/nextcloud-status.json"
  rmdir -- "$stage/backup-meta" "$stage" 2>/dev/null || true
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

dc exec -T -u www-data app php occ status --output=json >"$stage/backup-meta/nextcloud-status.json"
maintenance_was_on=$(python3 - "$stage/backup-meta/nextcloud-status.json" <<'PY'
import json, sys
status = json.load(open(sys.argv[1]))
if not status.get('installed') or status.get('needsDbUpgrade'):
    raise SystemExit('Nextcloud must be installed and fully upgraded before backup.')
print(int(bool(status.get('maintenance'))))
PY
)
restore_runtime=1
if (( ! maintenance_was_on )); then dc exec -T -u www-data app php occ maintenance:mode --on >&2; fi
# Maintenance mode does not finish a SyncJob that already reserved its row.
python3 "$ROOT/scripts/drain-cron.py" --timeout 900
echo 'Stopping app, cron and the web entry for a consistent backup...' >&2
dc stop --timeout 60 "${resume_services[@]}" >&2

echo 'Exporting the database...' >&2
dc exec -T db sh -ec 'export MYSQL_PWD="$(cat "$MARIADB_ROOT_PASSWORD_FILE")"; exec mariadb-dump --user=root --single-transaction --quick --routines --triggers --events --hex-blob --databases nextcloud' >"$stage/backup-meta/database.sql"
[[ -s $stage/backup-meta/database.sql ]] || die 'database dump is empty.'
python3 - "$ROOT" "$stage/backup-meta/manifest.json" <<'PY'
import datetime, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
values = {}
for line in (root / 'runtime' / '.env').read_text().splitlines():
    line = line.strip()
    if line and not line.startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip().strip('"\'')
if not values.get('DOMAIN') or not values.get('PUBLIC_PORT'):
    raise SystemExit('Generated .env must contain DOMAIN and PUBLIC_PORT.')
manifest = dict(format='mailhub-backup-v2', created_utc=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                source_root=str(root), domain=values['DOMAIN'], public_port=values['PUBLIC_PORT'],
                nextcloud_image=values.get('NEXTCLOUD_IMAGE'), database='nextcloud', encrypted=False)
pathlib.Path(sys.argv[2]).write_text(json.dumps(manifest, indent=2) + '\n')
PY
archive="$BACKUP_DIR/mailhub-$(date -u +%Y%m%dT%H%M%SZ)-${stage##*.}.tar.gz"
partial="$archive.partial"
echo 'Archiving application files, data, configuration, credentials and certificates...' >&2
# Transform member and hard-link names, but preserve relative symbolic-link targets.
# Live database files are deliberately excluded; database.sql is the consistent copy.
tar --create --gzip --file "$partial" --numeric-owner --acls --xattrs \
  --exclude='./runtime/data/database' --exclude='./.git' --exclude='./runtime/.update-stage.*' \
  --transform='flags=rh;s,^\.$,installation,;s,^\./,installation/,;s,^backup-meta,backup,' \
  --directory "$ROOT" . --directory "$stage" backup-meta
gzip -t -- "$partial"
mv -- "$partial" "$archive"
partial=
(cd -- "$BACKUP_DIR" && sha256sum -- "${archive##*/}" >"${archive##*/}.sha256")
chmod 600 -- "$archive" "$archive.sha256"
echo 'Backup written. It contains credentials and private mail data; protect/encrypt the backup volume and keep an off-host copy.' >&2
printf '%s\n' "$archive"
