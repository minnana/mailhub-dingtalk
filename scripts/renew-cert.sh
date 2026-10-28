#!/usr/bin/env bash
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../templates" && pwd)/lib.sh"
load_runtime
lock_runtime
docker image inspect "$ACME_IMAGE" >/dev/null 2>&1 || die 'Pinned ACME image is missing; restore it before renewal. No automatic image upgrade attempted.'
python3 templates/runtime_config.py check-dns runtime/secrets/dns.env "$DNS_PROVIDER"
[[ -s runtime/data/certs/fullchain.pem ]] || die 'Run issue-cert.sh first.'
log_file="$MAILHUB_RUNTIME/logs/certificate-renew.log"
touch "$log_file"; chmod 0600 "$log_file"
# acme.sh cron checks due dates and uses the recorded DNS-01 and install paths.
# With AUTO_UPGRADE=0 the pinned image code is not replaced during renewal.
if ! dc run --rm --no-deps acme --cron >"$log_file" 2>&1; then
  die "Certificate renewal failed. Administrator log: $log_file"
fi
chmod 0600 runtime/data/certs/key.pem runtime/data/certs/fullchain.pem
openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkhost "$DOMAIN" >/dev/null
openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkend 604800 >/dev/null || die 'Certificate expires within seven days; inspect renewal.'
if [[ -n $(dc ps --status running -q nginx) ]]; then
  dc exec -T nginx nginx -t
  dc exec -T nginx nginx -s reload
fi
echo 'Certificate checked and running proxy reloaded.'
