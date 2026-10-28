#!/bin/sh
set -eu

# Keep the official image's BusyBox daemon. It does not start another instance
# of the same cron line while the previous one is still running.
# This file lives only in the dedicated Mailhub cron container.
printf '%s\n' '* * * * * /bin/sh /mailhub/cron-cycle.sh' > /var/spool/cron/crontabs/www-data
chmod 0600 /var/spool/cron/crontabs/www-data
exec busybox crond -f -L /dev/stdout
