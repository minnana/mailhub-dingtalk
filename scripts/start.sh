#!/usr/bin/env bash
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../templates" && pwd)/lib.sh"
load_runtime
lock_runtime
prepare_only=0
if [[ $# == 1 && $1 == --prepare-only ]]; then
  prepare_only=1
elif [[ $# != 0 ]]; then
  die 'Usage: start.sh [--prepare-only]'
fi
[[ -s custom_apps/mailhub_dingtalk/appinfo/info.xml ]] || die 'DingTalk integration source is missing.'
[[ -s runtime/secrets/dingtalk.json ]] || die 'Missing DingTalk settings file.'
python3 - runtime/secrets/dingtalk.json <<'PY'
import json,sys
c=json.load(open(sys.argv[1]))
if not isinstance(c,dict) or type(c.get('enabled')) is not bool: raise SystemExit('Invalid DingTalk settings JSON')
PY
chown root:33 runtime/secrets/dingtalk.json
chmod 0640 runtime/secrets/dingtalk.json
if (( ! prepare_only )); then
  [[ -s runtime/data/certs/fullchain.pem && -s runtime/data/certs/key.pem ]] || die 'Run issue-cert.sh before start.'
  openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkhost "$DOMAIN" >/dev/null
  openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkend 86400 >/dev/null || die 'Certificate is expired or expires within one day.'
fi
dc config --quiet
existing_app_id=$(dc ps -aq app)
if [[ -n $existing_app_id ]]; then
  [[ $(docker inspect --format '{{.Config.Image}}' "$existing_app_id") == "$NEXTCLOUD_IMAGE" ]] || die 'Existing Nextcloud image differs; use the reviewed update/restore workflow, not start.sh.'
fi
ensure_images db redis app cron
if (( ! prepare_only )); then ensure_images nginx; fi
# Drain before Compose can recreate existing application containers.
existing_install=0
maintenance_was_on=0
if [[ -n $(dc ps --status running -q app) ]]; then
  prior_status=$(dc exec -T -u www-data app php occ status --output=json)
  prior_flags=$(printf '%s' "$prior_status" | python3 -c 'import json,sys; s=json.load(sys.stdin); assert not s.get("needsDbUpgrade"), "Use reviewed update workflow"; print(int(bool(s.get("installed"))), int(bool(s.get("maintenance"))))')
  read -r existing_install maintenance_was_on <<<"$prior_flags"
fi
if ((existing_install)); then dc exec -T -u www-data app php occ maintenance:mode --on; fi
if ! drain_cron; then
  if ((existing_install && !maintenance_was_on)); then dc exec -T -u www-data app php occ maintenance:mode --off; fi
  die 'Could not drain background work; no containers were recreated.'
fi
startup_complete=0
startup_exit() {
  local result=$?
  trap - EXIT INT TERM
  if (( !startup_complete && existing_install )); then
    dc exec -T -u www-data app php occ maintenance:mode --on >&2 || true
    echo 'Start failed; leave the existing instance in maintenance, inspect and retry.' >&2
  fi
  exit "$result"
}
trap startup_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
dc up -d --wait --wait-timeout 240 db redis app
# Install without transmitting database or admin passwords in OS process arguments.
dc exec -T -e "MAILHUB_ADMIN_USER=$ADMIN_USER" app php /mailhub/install.php
status=$(dc exec -T -u www-data app php occ status --output=json)
printf '%s' "$status" | python3 -c 'import json,sys; s=json.load(sys.stdin); assert s.get("installed") and not s.get("needsDbUpgrade"), "Nextcloud installation is incomplete"'
installed_mail=$(dc exec -T -u www-data app php occ config:app:get mail installed_version 2>/dev/null || true)
if [[ -z "$installed_mail" ]]; then
  [[ ! -e runtime/data/nextcloud/custom_apps/mail ]] || die 'Unregistered mail directory exists; inspect before retrying.'
  archive="$MAILHUB_RUNTIME/downloads/mail-v${MAIL_VERSION}.tar.gz"
  if [[ ! -f "$archive" ]]; then
    curl --fail --show-error --silent --location --proto '=https' --tlsv1.2 \
      --connect-timeout 20 --max-time 600 --retry 2 \
      "https://github.com/nextcloud-releases/mail/releases/download/v${MAIL_VERSION}/mail-v${MAIL_VERSION}.tar.gz" -o "$archive.partial"
    printf '%s  %s\n' "$MAIL_SHA256" "$archive.partial" | sha256sum --check --status || die 'Downloaded Mail archive checksum mismatch.'
    mv -- "$archive.partial" "$archive"
  fi
  printf '%s  %s\n' "$MAIL_SHA256" "$archive" | sha256sum --check --status || die 'Mail archive checksum mismatch.'
  python3 - "$archive" "$MAIL_VERSION" <<'PY'
import sys,tarfile,xml.etree.ElementTree as ET,pathlib
with tarfile.open(sys.argv[1],'r:gz') as t:
    for m in t.getmembers():
        p=pathlib.PurePosixPath(m.name)
        if p.is_absolute() or '..' in p.parts or p.parts[0]!='mail' or m.issym() or m.islnk() or m.isdev():
            raise SystemExit('Unsafe archive member')
    x=ET.fromstring(t.extractfile('mail/appinfo/info.xml').read())
    if x.findtext('version')!=sys.argv[2]: raise SystemExit('Mail version mismatch')
    dep=x.find('dependencies/nextcloud')
    if not int(dep.get('min-version'))<=34<=int(dep.get('max-version')): raise SystemExit('Mail incompatible with Nextcloud 34')
PY
  tar -xzf "$archive" --no-same-owner -C runtime/data/nextcloud/custom_apps
  chown -R 33:33 runtime/data/nextcloud/custom_apps/mail
  dc exec -T -u www-data app php occ app:enable mail
elif [[ "$installed_mail" != "$MAIL_VERSION" ]]; then
  die "Existing Mail $installed_mail differs from pinned $MAIL_VERSION. Use a reviewed upgrade; nothing replaced."
fi
# Reviewed Mail 5.12.5 compatibility fix; preserves the original outside the app.
python3 "$MAILHUB_ROOT/templates/mail_sync_token_patch.py" apply
dc exec -T -u www-data app php occ app:enable mailhub_dingtalk
# Employees should enter Mail directly without the Nextcloud introduction or release-tour modal.
dc exec -T -u www-data app php occ app:disable firstrunwizard
dc exec -T -u www-data app php occ background:cron
if (( !maintenance_was_on )); then
  dc exec -T -u www-data app php occ maintenance:mode --off
  dc exec -T -u www-data app php -f cron.php
fi
if (( prepare_only )); then
  dc up -d --wait --wait-timeout 120 cron
  startup_complete=1
  echo 'Private application stack initialized. No public web entry was started.'
  echo 'Complete DNS-01 issuance, then run start.sh without --prepare-only.'
  exit 0
fi
dc up -d --wait --wait-timeout 120 cron nginx
startup_complete=1
printf 'Base Mail service started: https://%s:%s\n' "$DOMAIN" "$PUBLIC_PORT"
printf 'Administrator name: %s; secret file: %s/secrets/admin_password\n' "$ADMIN_USER" "$MAILHUB_RUNTIME"
echo 'DingTalk integration is installed. Its runtime settings remain fail-closed until corporate credentials and allowed users are configured.'
flock -u 9
if (( !maintenance_was_on )); then bash "$MAILHUB_ROOT/scripts/healthcheck.sh"; fi
