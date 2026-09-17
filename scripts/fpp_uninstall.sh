#!/bin/bash
# fpp-plugin-AudienceText uninstall script.
#
# The Apache config is the one thing this plugin puts outside its own directory,
# so it is the one thing that would otherwise be left behind -- an Alias
# pointing at a deleted directory, which apache2ctl configtest is perfectly
# happy with and which then 404s forever. Remove it here.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APACHE_CONF_NAME="fpp-audience-text"
APACHE_CONF_DST="/etc/apache2/conf-available/${APACHE_CONF_NAME}.conf"

echo "Uninstalling Audience Text Plugin"

if [ -x "$PLUGIN_DIR/scripts/postStop.sh" ]; then
    "$PLUGIN_DIR/scripts/postStop.sh"
fi

if [ -f "$APACHE_CONF_DST" ]; then
    sudo a2disconf "$APACHE_CONF_NAME" >/dev/null 2>&1
    sudo rm -f "$APACHE_CONF_DST"
    if sudo apache2ctl configtest >/dev/null 2>&1; then
        sudo service apache2 reload >/dev/null 2>&1
    fi
    echo "✓ Removed the /audience Apache config."
fi

# The queue database goes with the plugin directory FPP is about to delete.
# Nothing in it is worth preserving -- it is a log of messages that have already
# been on a screen.
echo "Audience Text uninstalled."
