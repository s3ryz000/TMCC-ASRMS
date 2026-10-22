#!/usr/bin/env bash
# One-time install of the asrms-update command (#108). Run as root from the
# server checkout:
#
#   cd /var/www/asrms && sudo git pull && sudo bash deploy/install-asrms-update.sh
#
# Re-run it whenever deploy/asrms-update.sh changes.

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash deploy/install-asrms-update.sh" >&2
    exit 1
fi

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
install -m 755 -o root -g root "$here/asrms-update.sh" /usr/local/bin/asrms-update
echo "Installed /usr/local/bin/asrms-update. Update the site with: sudo asrms-update"
