#!/usr/bin/env bash
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../templates" && pwd)/lib.sh"
load_runtime
lock_runtime
[[ -s runtime/secrets/dns.env ]] || die 'Missing runtime/secrets/dns.env.'
python3 templates/runtime_config.py check-dns runtime/secrets/dns.env "$DNS_PROVIDER"
if [[ -s runtime/data/certs/fullchain.pem && -s runtime/data/certs/key.pem ]]; then
  openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkhost "$DOMAIN" >/dev/null
  openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkend 86400 >/dev/null || die 'Certificate needs renewal; use renew-cert.sh.'
  echo 'Certificate already installed. Use renew-cert.sh for renewal; nothing overwritten.'
  exit 0
fi
ensure_images acme
# Logs are root-only. Never enable acme.sh debug output or echo DNS credentials.
log_file="$MAILHUB_RUNTIME/logs/certificate-issue.log"
touch "$log_file"; chmod 0600 "$log_file"
register_args=(--register-account --server letsencrypt)
[[ -z "$ACME_EMAIL" ]] || register_args+=(-m "$ACME_EMAIL")
if ! dc run --rm --no-deps acme "${register_args[@]}" >"$log_file" 2>&1; then
  die "ACME account registration failed. Administrator log: $log_file"
fi
issue_status=0
dc run --rm --no-deps acme --issue --server letsencrypt --dns "$DNS_PROVIDER" \
  --keylength ec-256 -d "$DOMAIN" >>"$log_file" 2>&1 || issue_status=$?
# Exit 2 means an existing certificate is not due yet; install its files below.
if [[ $issue_status != 0 && $issue_status != 2 ]]; then
  die "DNS-01 issuance failed. Administrator log: $log_file"
fi
if ! dc run --rm --no-deps acme --install-cert --ecc -d "$DOMAIN" \
  --key-file /certs/key.pem --fullchain-file /certs/fullchain.pem >>"$log_file" 2>&1; then
  die "Certificate installation failed. Administrator log: $log_file"
fi
chmod 0600 runtime/data/certs/key.pem runtime/data/certs/fullchain.pem
openssl x509 -in runtime/data/certs/fullchain.pem -noout -checkhost "$DOMAIN" >/dev/null
echo 'Certificate installed. Public port 80 is not required by DNS-01.'
