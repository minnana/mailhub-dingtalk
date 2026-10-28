#!/usr/bin/env bash
# Restore only into a new or completely empty installation directory.
set -Eeuo pipefail
umask 077
ROOT=/opt/mailhub
ARCHIVE=
EXPECTED_HOST=
EXPECTED_PORT=
usage() { echo 'Usage: restore.sh --archive /mnt/mailhub-backup/mailhub-....tar.gz --hostname mail.example.com --port 18443 [--root /opt/mailhub]' >&2; }
while (($#)); do
  case "$1" in
    --archive) ARCHIVE=${2:?Missing --archive value}; shift 2 ;;
    --hostname) EXPECTED_HOST=${2:?Missing --hostname value}; shift 2 ;;
    --port) EXPECTED_PORT=${2:?Missing --port value}; shift 2 ;;
    --root) ROOT=${2:?Missing --root value}; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) usage; exit 2 ;;
  esac
done
die() { echo "Restore: $*" >&2; exit 1; }
[[ $EUID == 0 ]] || die 'run as root.'
[[ -n $ARCHIVE && -n $EXPECTED_HOST && -n $EXPECTED_PORT ]] || { usage; exit 2; }
for command in docker python3 flock tar realpath; do command -v "$command" >/dev/null || die "required command missing: $command"; done
[[ $ROOT == /* && ! -L $ROOT ]] || die 'root must be an absolute path, not a symlink.'
ROOT=$(realpath -m -- "$ROOT")
[[ $ROOT == /opt/mailhub ]] || die 'this deployment supports only /opt/mailhub.'
ARCHIVE=$(realpath -e -- "$ARCHIVE")
[[ -f $ARCHIVE && -f $ARCHIVE.sha256 ]] || die 'archive and its .sha256 sidecar are required.'
[[ ! -e $ROOT || ( -d $ROOT && -z $(find "$ROOT" -mindepth 1 -maxdepth 1 -print -quit) ) ]] || die 'destination must be new or completely empty; existing installations are never overwritten.'
exec 9>/run/lock/mailhub-maintenance.lock
flock -n 9 || die 'another Mailhub maintenance operation is running.'
[[ -z $(docker ps -aq --filter label=com.docker.compose.project=mailhub) ]] || die 'Mailhub containers already exist. Restore on a fresh host; this script will not remove existing containers.'

echo 'Verifying checksum, hostname, port and archive safety...' >&2
python3 - "$ARCHIVE" "$EXPECTED_HOST" "$EXPECTED_PORT" <<'PY'
import hashlib, json, pathlib, posixpath, re, sys, tarfile
archive = pathlib.Path(sys.argv[1])
lines = pathlib.Path(str(archive) + '.sha256').read_text().splitlines()
if len(lines) != 1 or not re.fullmatch(r'[0-9a-fA-F]{64} [ *].+', lines[0]):
    raise SystemExit('Invalid checksum sidecar.')
digest = hashlib.file_digest(archive.open('rb'), 'sha256').hexdigest()
if digest != lines[0][:64].lower():
    raise SystemExit('Backup checksum mismatch.')
with tarfile.open(archive, 'r:gz') as tf:
    names = set()
    for member in tf:
        name = member.name.rstrip('/')
        if name in names:
            raise SystemExit('Duplicate archive member: ' + name)
        names.add(name)
        parts = pathlib.PurePosixPath(name).parts
        if not parts or parts[0] not in ('installation', 'backup') or name.startswith('/') or '..' in parts:
            raise SystemExit('Unsafe archive path: ' + name)
        if not (member.isfile() or member.isdir() or member.issym() or member.islnk()):
            raise SystemExit('Special files are not accepted: ' + name)
        if member.issym() or member.islnk():
            link = member.linkname
            target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link) if member.issym() else link)
            if link.startswith('/') or target.split('/')[0] != parts[0]:
                raise SystemExit('Link points outside its backup tree: ' + name)
    required = {'installation/compose.yaml', 'installation/runtime/.env', 'backup/manifest.json', 'backup/database.sql', 'backup/nextcloud-status.json'}
    if not required <= names:
        raise SystemExit('Backup is missing required files.')
    for name in required:
        if not tf.getmember(name).isfile():
            raise SystemExit('Required metadata must be regular files.')
    manifest = json.load(tf.extractfile('backup/manifest.json'))
    if manifest.get('format') != 'mailhub-backup-v2':
        raise SystemExit('Unsupported backup format.')
    if manifest.get('domain') != sys.argv[2] or str(manifest.get('public_port')) != sys.argv[3]:
        raise SystemExit('Hostname or HTTPS port differs from backup. Migration to a new address requires manual configuration review.')
    if any(n == 'installation/runtime/data/database' or n.startswith('installation/runtime/data/database/') for n in names):
        raise SystemExit('Archive unexpectedly contains live database files.')
PY
mkdir -p -- "$(dirname -- "$ROOT")"
stage=$(mktemp -d "$(dirname -- "$ROOT")/.mailhub-restore.XXXXXXXX")
completed=0
on_exit() {
  local result=$?
  trap - EXIT INT TERM
  if (( ! completed )); then
    echo "Restore stopped. Existing files were not overwritten. Recovery files remain at $stage; investigate before retrying." >&2
    echo 'If app was started, keep it in maintenance mode until recovery is complete.' >&2
  fi
  exit "$result"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
tar --extract --gzip --file "$ARCHIVE" --directory "$stage" --numeric-owner --same-owner --acls --xattrs
[[ ! -e $ROOT || -z $(find "$ROOT" -mindepth 1 -maxdepth 1 -print -quit) ]] || die 'destination changed while checking archive.'
mkdir -p -m 700 -- "$ROOT"
chown root:root -- "$ROOT"
chmod 700 -- "$ROOT"
shopt -s dotglob nullglob
entries=("$stage/installation/"*)
mv -- "${entries[@]}" "$ROOT/"
rmdir -- "$stage/installation"
mkdir -p -- "$ROOT/runtime/data/database"
dc() { docker compose --project-name mailhub --project-directory "$ROOT" --env-file "$ROOT/runtime/.env" -f "$ROOT/compose.yaml" "$@"; }
dc config -q
echo 'Starting the pinned database and cache images...' >&2
dc up -d db redis
database_ready=0
for ((attempt=0; attempt<90; attempt++)); do
  if dc exec -T db sh -ec 'export MYSQL_PWD="$(cat "$MARIADB_ROOT_PASSWORD_FILE")"; mariadb --user=root -Nse "SELECT 1"' >/dev/null 2>&1; then database_ready=1; break; fi
  sleep 2
done
((database_ready)) || die 'database did not become ready; inspect docker compose logs db.'
echo 'Importing the consistent database dump...' >&2
dc exec -T db sh -ec 'export MYSQL_PWD="$(cat "$MARIADB_ROOT_PASSWORD_FILE")"; exec mariadb --user=root' <"$stage/backup/database.sql"
dc up -d app
app_ready=0
for ((attempt=0; attempt<90; attempt++)); do
  if dc exec -T -u www-data app php occ status --output=json >"$stage/status-restored.json" 2>/dev/null &&
    python3 - "$stage/status-restored.json" <<'PY'
import json, sys
s = json.load(open(sys.argv[1]))
raise SystemExit(0 if s.get('installed') and not s.get('needsDbUpgrade') else 1)
PY
  then app_ready=1; break; fi
  sleep 2
done
((app_ready)) || die 'restored app did not become ready; inspect app logs and the backup image version.'
dc exec -T -u www-data app php occ maintenance:data-fingerprint
was_maintenance=$(python3 - "$stage/backup/nextcloud-status.json" <<'PY'
import json, sys
print(int(bool(json.load(open(sys.argv[1])).get('maintenance'))))
PY
)
if (( ! was_maintenance )); then dc exec -T -u www-data app php occ maintenance:mode --off; fi
dc up -d cron nginx
rm -f -- "$stage/backup/database.sql" "$stage/backup/manifest.json" "$stage/backup/nextcloud-status.json" "$stage/status-restored.json"
rmdir -- "$stage/backup" "$stage"
completed=1
echo "Restored at $ROOT. Verify https://$EXPECTED_HOST:$EXPECTED_PORT, mailbox access, scheduled jobs and certificate renewal before admitting users."
echo 'On this fresh host, recreate certificate renewal with: bash /opt/mailhub/scripts/install-maintenance.sh --cert-only'
echo 'Recreate backup scheduling with install-maintenance.sh after mounting the external backup filesystem.'
if ((was_maintenance)); then echo 'The source was already in maintenance mode; maintenance mode remains enabled.'; fi
