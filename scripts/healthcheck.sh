#!/usr/bin/env bash
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../templates" && pwd)/lib.sh"
load_runtime
dc config --quiet
for service in db redis app cron nginx; do
  [[ -n $(dc ps --status running -q "$service") ]] || die "Service $service is not running."
done
dc exec -T db healthcheck.sh --connect --innodb_initialized
[[ $(dc exec -T redis redis-cli ping) == PONG ]] || die 'Redis did not answer.'
dc exec -T nginx nginx -t
status=$(dc exec -T -u www-data app php occ status --output=json)
printf '%s' "$status" | python3 -c 'import json,sys; s=json.load(sys.stdin); assert s.get("installed") and not s.get("maintenance") and not s.get("needsDbUpgrade"), "Nextcloud not ready"; print("Nextcloud",s["versionstring"],"ready")'
[[ $(dc exec -T -u www-data app php occ config:app:get mail installed_version) == "$MAIL_VERSION" ]] || die 'Mail version differs from pinned version.'
python3 "$MAILHUB_ROOT/templates/mail_sync_token_patch.py" status |
  python3 -c 'import json,sys; s=json.load(sys.stdin); assert s["state"]=="patched", "Reviewed Mail sync-token compatibility fix is missing"; print("Mail sync-token compatibility hash verified")'
[[ $(dc exec -T cron cat /var/spool/cron/crontabs/www-data) == '* * * * * /bin/sh /mailhub/cron-cycle.sh' ]] || die 'Mailhub sync/notification cycle is not scheduled every minute.'
dc exec -T -u www-data cron php /var/www/html/custom_apps/mailhub_dingtalk/tests/fast-sync-integration.php
dc exec -T -u www-data app php occ app:list --enabled --output=json |
  python3 -c 'import json,sys; assert "mailhub_dingtalk" in json.load(sys.stdin).get("enabled",{}), "DingTalk integration is disabled"'
# Resolve locally but verify the actual public hostname and certificate (never -k).
listen_ip=${BIND_ADDRESS:-0.0.0.0}
[[ "$listen_ip" != 0.0.0.0 ]] || listen_ip=127.0.0.1
curl --fail --silent --show-error --connect-timeout 10 --max-time 30 \
  --resolve "$DOMAIN:$PUBLIC_PORT:$listen_ip" "https://$DOMAIN:$PUBLIC_PORT/status.php" |
  python3 -c 'import json,sys; s=json.load(sys.stdin); assert s.get("installed") and not s.get("maintenance"), "HTTPS status not ready"; print("HTTPS certificate and public hostname verified locally")'
last_cron=$(dc exec -T -u www-data app php occ config:app:get core lastcron)
now=$(date +%s)
[[ $last_cron =~ ^[0-9]+$ ]] && ((now-last_cron < 900)) || die 'Background tasks have not completed within 15 minutes.'
openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkend 604800 >/dev/null || die 'Certificate expires within seven days.'
echo 'Local checks passed. Still test outside the office using mobile data and test actual mailbox send/receive.'
