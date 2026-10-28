#!/bin/sh
set -eu
umask 077
set -a
. /run/secrets/dns
set +a
# Execute the pinned image's code; never self-update scripts in the running image.
exec /acmebin/acme.sh --home /acmebin --config-home /acme.sh "$@"
