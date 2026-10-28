#!/usr/bin/env bash
# Coexistence-safe initializer: does not install software or change global settings.
set -Eeuo pipefail
umask 077
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/../templates/lib.sh"
need_root
lock_runtime
[[ $# == 2 && $1 == --config ]] || die 'Usage: bootstrap.sh --config /root/mailhub-bootstrap.json'
config=$(realpath -e -- "$2")
[[ -f $config && ! -L $config ]] || die 'Settings must be a regular JSON file.'
[[ $(stat -c %u "$config") == 0 ]] || die 'Settings must be owned by root.'
[[ $(realpath -e -- "$SCRIPT_DIR/..") == "$MAILHUB_ROOT" ]] || die 'Upload source to /opt/mailhub before initialization.'
[[ ! -L "$MAILHUB_ROOT" && ! -L "$MAILHUB_RUNTIME" ]] || die 'Deployment directories must not be symlinks.'
for executable in docker python3 openssl curl tar gzip flock ip ss; do
  command -v "$executable" >/dev/null || die "Missing $executable; have the host administrator install it. No packages changed."
done
bash "$SCRIPT_DIR/install-docker.sh"
if [[ -f "$MAILHUB_RUNTIME/.mailhub-managed" && -f "$MAILHUB_RUNTIME/.env" ]]; then
  load_runtime
  echo 'Existing runtime retained; no settings, secrets or containers changed.'
  exit 0
fi
if [[ -d "$MAILHUB_RUNTIME" && ! -f "$MAILHUB_RUNTIME/.mailhub-managed" ]] &&
   [[ -n $(find "$MAILHUB_RUNTIME" -mindepth 1 -maxdepth 1 -print -quit) ]]; then
  die 'Runtime is nonempty and unmanaged; nothing overwritten.'
fi
# Validate all non-secret settings and network/port availability before writing.
rendered=$(python3 "$MAILHUB_ROOT/templates/runtime_config.py" render "$config")
install -d -m 0700 "$MAILHUB_RUNTIME"
for d in data secrets config downloads logs; do install -d -m 0700 "$MAILHUB_RUNTIME/$d"; done
for d in nextcloud database acme certs; do install -d -m 0700 "$MAILHUB_RUNTIME/data/$d"; done
printf 'mailhub-runtime-v2\n' > "$MAILHUB_RUNTIME/.mailhub-managed"
for secret in db_root_password db_password admin_password; do
  if [[ ! -e "$MAILHUB_RUNTIME/secrets/$secret" ]]; then openssl rand -hex 32 > "$MAILHUB_RUNTIME/secrets/$secret"; fi
  chmod 0600 "$MAILHUB_RUNTIME/secrets/$secret"
done
[[ -e "$MAILHUB_RUNTIME/secrets/dns.env" ]] || install -m 0600 /dev/null "$MAILHUB_RUNTIME/secrets/dns.env"
if [[ ! -e "$MAILHUB_RUNTIME/secrets/dingtalk.json" ]]; then
  install -m 0640 -o root -g 33 "$MAILHUB_ROOT/templates/dingtalk.disabled.json" "$MAILHUB_RUNTIME/secrets/dingtalk.json"
fi
env_tmp=$(mktemp "$MAILHUB_RUNTIME/.env.tmp.XXXXXX")
cat "$MAILHUB_ROOT/templates/versions.env" > "$env_tmp"
printf '%s\n' "$rendered" >> "$env_tmp"
python3 "$MAILHUB_ROOT/templates/runtime_config.py" check-env "$env_tmp"
python3 - "$MAILHUB_ROOT" "$env_tmp" <<'PY'
import pathlib,sys
root=pathlib.Path(sys.argv[1]); values={}
for line in pathlib.Path(sys.argv[2]).read_text().splitlines():
    if line and not line.startswith('#'):
        k,v=line.split('=',1); values[k]=v
for name in ['nginx.conf','mailhub.config.php']:
    text=(root/'templates'/(name+'.in')).read_text()
    for key in ['DOMAIN','PUBLIC_PORT','PROXY_IP']: text=text.replace('@@'+key+'@@',values[key])
    dest=root/'runtime'/'config'/name
    if dest.exists() and dest.read_text()!=text: raise SystemExit('Existing rendered config differs; inspect '+str(dest))
    if not dest.exists(): dest.write_text(text)
PY
chown 33:33 "$MAILHUB_RUNTIME/data/nextcloud"
install -d -m 0750 -o 33 -g 33 "$MAILHUB_RUNTIME/data/nextcloud/config" "$MAILHUB_RUNTIME/data/nextcloud/custom_apps"
chown root:33 "$MAILHUB_RUNTIME/config/mailhub.config.php"
chmod 0640 "$MAILHUB_RUNTIME/config/mailhub.config.php"
chmod 0600 "$MAILHUB_RUNTIME/config/nginx.conf" "$env_tmp"
mv -n -- "$env_tmp" "$MAILHUB_RUNTIME/.env"
load_runtime
dc config --quiet
printf 'Runtime ready. Administrator secret: %s/secrets/admin_password\n' "$MAILHUB_RUNTIME"
printf 'Upload selected DNS credentials to %s/secrets/dns.env (root:root 0600).\n' "$MAILHUB_RUNTIME"
echo 'Then run issue-cert.sh and start.sh. No existing service or global host setting was changed.'
