#!/usr/bin/env bash
# Install daily backup scheduling. No unattended image or app upgrades.
set -Eeuo pipefail
umask 077
ROOT=/opt/mailhub
BACKUP_DIR=/mnt/mailhub-backup
CERT_ONLY=0
usage() { echo 'Usage: install-maintenance.sh [--cert-only] [--root /opt/mailhub] [--backup-dir /mnt/mailhub-backup]' >&2; }
while (($#)); do
  case "$1" in
    --root) ROOT=${2:?Missing --root value}; shift 2 ;;
    --backup-dir) BACKUP_DIR=${2:?Missing --backup-dir value}; shift 2 ;;
    --cert-only) CERT_ONLY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage; exit 2 ;;
  esac
done
die() { echo "Maintenance installer: $*" >&2; exit 1; }
[[ $EUID == 0 ]] || die 'run as root.'
for command in systemctl systemd-analyze findmnt mountpoint realpath; do command -v "$command" >/dev/null || die "required command missing: $command"; done
ROOT=$(realpath -e -- "$ROOT")
[[ $ROOT == /opt/mailhub ]] || die 'this deployment supports only /opt/mailhub.'
# Restrict unit interpolations to paths that cannot introduce systemd directives/specifiers.
[[ $ROOT =~ ^/[a-zA-Z0-9_./-]+$ && $BACKUP_DIR =~ ^/[a-zA-Z0-9_./-]+$ ]] || die 'paths must use letters, numbers, slash, dot, underscore or hyphen.'
[[ -f $ROOT/scripts/backup.sh && -f $ROOT/scripts/renew-cert.sh && -f $ROOT/compose.yaml && -f $ROOT/runtime/.env ]] || die 'installation is incomplete.'
units=(mailhub-cert-renew.service mailhub-cert-renew.timer)
if (( ! CERT_ONLY )); then
  BACKUP_DIR=$(realpath -e -- "$BACKUP_DIR")
  mountpoint -q -- "$BACKUP_DIR" || die 'backup-dir must be mounted. Use --cert-only to install certificate renewal before a backup disk is available.'
  [[ $(findmnt -n -o MAJ:MIN --target "$BACKUP_DIR") != "$(findmnt -n -o MAJ:MIN --target "$ROOT")" ]] || die 'backup filesystem must differ from installation filesystem.'
  units+=(mailhub-backup.service mailhub-backup.timer)
fi
for unit in "${units[@]}"; do
  file="/etc/systemd/system/$unit"
  if [[ -e $file ]] && ! grep -qx '# Managed by mailhub-deploy/install-maintenance.sh' "$file"; then
    die "will not overwrite an unmanaged unit: $file"
  fi
done
if (( ! CERT_ONLY )); then
cat >/etc/systemd/system/mailhub-backup.service <<EOF
# Managed by mailhub-deploy/install-maintenance.sh
[Unit]
Description=Consistent Mailhub application and database backup
Requires=docker.service
After=docker.service
RequiresMountsFor=$BACKUP_DIR

[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/bin/bash $ROOT/scripts/backup.sh --root $ROOT --backup-dir $BACKUP_DIR
TimeoutStartSec=infinity
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7
StandardOutput=journal
StandardError=journal
EOF
cat >/etc/systemd/system/mailhub-backup.timer <<'EOF'
# Managed by mailhub-deploy/install-maintenance.sh
[Unit]
Description=Daily Mailhub backup at 02:00 Asia/Shanghai

[Timer]
OnCalendar=*-*-* 02:00:00 Asia/Shanghai
Persistent=false
RandomizedDelaySec=5m
Unit=mailhub-backup.service

[Install]
WantedBy=timers.target
EOF
fi
cat >/etc/systemd/system/mailhub-cert-renew.service <<EOF
# Managed by mailhub-deploy/install-maintenance.sh
[Unit]
Description=Mailhub DNS certificate renewal and proxy reload
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/bin/bash $ROOT/scripts/renew-cert.sh
TimeoutStartSec=30min
StandardOutput=journal
StandardError=journal
EOF
cat >/etc/systemd/system/mailhub-cert-renew.timer <<'EOF'
# Managed by mailhub-deploy/install-maintenance.sh
[Unit]
Description=Daily Mailhub certificate renewal check

[Timer]
OnCalendar=*-*-* 01:00:00 Asia/Shanghai
Persistent=true
RandomizedDelaySec=15m
Unit=mailhub-cert-renew.service

[Install]
WantedBy=timers.target
EOF
unit_paths=()
for unit in "${units[@]}"; do
  chmod 644 "/etc/systemd/system/$unit"
  unit_paths+=("/etc/systemd/system/$unit")
done
systemd-analyze verify "${unit_paths[@]}"
systemctl daemon-reload
systemctl enable --now mailhub-cert-renew.timer
echo 'Certificate renewal is checked daily at 01:00 Asia/Shanghai (up to fifteen minutes jitter); the running web proxy reloads after success.'
echo 'Check systemctl status mailhub-cert-renew.service and journalctl -u mailhub-cert-renew.service. No email/chat failure notification is configured.'
if (( CERT_ONLY )); then
  echo 'Certificate renewal installed. Backup scheduling was not changed; run this installer without --cert-only once an external backup filesystem is mounted.'
  exit 0
fi
systemctl enable --now mailhub-backup.timer
echo 'Daily backups scheduled for 02:00 Asia/Shanghai (up to five minutes jitter). Missed runs are not replayed during office hours.'
echo 'Run systemctl start mailhub-backup.service once now to validate the full backup path.'
echo 'Inspect systemctl status mailhub-backup.service and journalctl -u mailhub-backup.service; timer installation alone does not verify a backup.'
echo 'No retention deletion is enabled. Monitor free space, rotate backups deliberately and keep encrypted off-host copies.'
echo 'No automatic software updates are enabled. Use update.sh for reviewed, pinned Nextcloud updates.'
