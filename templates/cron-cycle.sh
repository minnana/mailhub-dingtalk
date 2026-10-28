#!/bin/sh
set -eu

# Preserve normal Nextcloud background scheduling and its account sync locks.
# Scan notifications again after sync jobs finish, even if the cron task order
# placed the normal notification job before the mail synchronizer this minute.
cron_status=0
php -f /var/www/html/cron.php || cron_status=$?
notify_status=0
php -f /var/www/html/custom_apps/mailhub_dingtalk/cli/notify-after-sync.php || notify_status=$?
if [ "$cron_status" -ne 0 ]; then
    printf '%s\n' 'Mailhub: Nextcloud cron failed; inspect private server logs.' >&2
    exit "$cron_status"
fi
exit "$notify_status"
