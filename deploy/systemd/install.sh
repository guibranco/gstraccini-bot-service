#!/usr/bin/env bash
# Installs and enables all GStraccini Bot worker services on Ubuntu.
# Run as root or with sudo: sudo bash deploy/systemd/install.sh
#
# Optional env vars:
#   APP_DIR  — path where the repo is deployed (default: /var/www/gstraccini-bot-service)
#   APP_USER — OS user that runs the workers   (default: www-data)

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/gstraccini-bot-service}"
APP_USER="${APP_USER:-www-data}"

SERVICES=(
    gstraccini-branches
    gstraccini-comments
    gstraccini-issues
    gstraccini-pull-requests
    gstraccini-pushes
    gstraccini-repositories
    gstraccini-signature
)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "==> Installing GStraccini Bot worker services"
echo "    App directory : ${APP_DIR}"
echo "    Run as user   : ${APP_USER}"
echo ""

# Validate the app directory exists
if [[ ! -d "${APP_DIR}/Src" ]]; then
    echo "ERROR: ${APP_DIR}/Src not found. Deploy the application first." >&2
    exit 1
fi

# Validate php is available
if ! command -v php &>/dev/null; then
    echo "ERROR: php not found in PATH. Install PHP CLI first." >&2
    exit 1
fi

# Verify pcntl extension is loaded (required for signal handling)
if ! php -r "exit(extension_loaded('pcntl') ? 0 : 1);"; then
    echo "ERROR: The pcntl PHP extension is not available." >&2
    echo "       Install it with: sudo apt-get install php-pcntl" >&2
    exit 1
fi

echo "==> Copying service files to /etc/systemd/system/"
for SERVICE in "${SERVICES[@]}"; do
    SRC="${SCRIPT_DIR}/${SERVICE}.service"
    DST="/etc/systemd/system/${SERVICE}.service"

    # Replace placeholder path with the actual APP_DIR
    sed "s|/var/www/gstraccini-bot-service|${APP_DIR}|g; s|User=www-data|User=${APP_USER}|g; s|Group=www-data|Group=${APP_USER}|g" \
        "${SRC}" > "${DST}"

    echo "    Installed ${DST}"
done

echo ""
echo "==> Reloading systemd daemon"
systemctl daemon-reload

echo ""
echo "==> Enabling and starting services"
for SERVICE in "${SERVICES[@]}"; do
    systemctl enable "${SERVICE}"
    systemctl start  "${SERVICE}"
    echo "    ${SERVICE}: $(systemctl is-active "${SERVICE}")"
done

echo ""
echo "==> Done. View logs with:"
echo "    journalctl -u gstraccini-branches -f"
echo "    journalctl -u gstraccini-comments -f"
echo "    # etc."
echo ""
echo "==> Manage services with:"
echo "    sudo systemctl status  gstraccini-branches"
echo "    sudo systemctl restart gstraccini-branches"
echo "    sudo systemctl stop    gstraccini-branches"
