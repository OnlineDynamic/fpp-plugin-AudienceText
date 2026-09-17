#!/bin/bash
# fpp-plugin-AudienceText install script.
#
# Reports through warn()/fail() rather than bare echoes so the closing banner
# can tell the truth: an install that could not enable the Apache config has
# produced a plugin whose whole point (a page the audience can reach without a
# password) does not work, and that must not end on "Installed Successfully".

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APACHE_CONF_NAME="fpp-audience-text"
APACHE_CONF_SRC="$PLUGIN_DIR/scripts/apache-audience.conf"
APACHE_CONF_DST="/etc/apache2/conf-available/${APACHE_CONF_NAME}.conf"

INSTALL_WARNINGS=()
INSTALL_ERRORS=()

warn() { echo "⚠ WARNING: $1"; INSTALL_WARNINGS+=("$1"); }
fail() { echo "✗ ERROR: $1"; INSTALL_ERRORS+=("$1"); }

install_summary() {
    echo ""
    echo "=========================================="
    if [ ${#INSTALL_ERRORS[@]} -gt 0 ]; then
        echo "Audience Text: installation INCOMPLETE"
    elif [ ${#INSTALL_WARNINGS[@]} -gt 0 ]; then
        echo "Audience Text: installed with warnings"
    else
        echo "Audience Text: installed successfully"
    fi
    echo "=========================================="
    if [ ${#INSTALL_ERRORS[@]} -gt 0 ]; then
        echo ""
        echo "These MUST be fixed before the plugin will work:"
        for ITEM in "${INSTALL_ERRORS[@]}"; do echo "  ✗ $ITEM"; done
    fi
    if [ ${#INSTALL_WARNINGS[@]} -gt 0 ]; then
        echo ""
        for ITEM in "${INSTALL_WARNINGS[@]}"; do echo "  ⚠ $ITEM"; done
    fi
    echo ""
    echo "Next: open Content Setup > Audience Text and choose the model to"
    echo "display on. Until a model is chosen the audience page will say so"
    echo "rather than silently swallowing messages."
    echo ""
    [ ${#INSTALL_ERRORS[@]} -gt 0 ] && exit 1
    exit 0
}

echo "=========================================="
echo "Installing Audience Text Plugin"
echo "=========================================="

# ── Writable data directory ──────────────────────────────────────────────────
# Both php-fpm (running as fpp) and the worker write queue.db here, so this has
# to be fpp-owned before either of them touches it. A root-owned queue.db is a
# submit button that fails for every guest in the room.
mkdir -p "$PLUGIN_DIR/data"
chmod 775 "$PLUGIN_DIR/data"
if [ "$(id -u)" = "0" ]; then
    chown -R fpp:fpp "$PLUGIN_DIR/data"
fi
if ! sudo -u fpp test -w "$PLUGIN_DIR/data"; then
    fail "$PLUGIN_DIR/data is not writable by the fpp user; submissions will fail."
fi

chmod +x "$PLUGIN_DIR/scripts/postStart.sh" "$PLUGIN_DIR/scripts/postStop.sh" 2>/dev/null

# ── Python ───────────────────────────────────────────────────────────────────
# The worker is deliberately stdlib-only (urllib + sqlite3), so there is nothing
# to pip install -- but check the interpreter is actually there before promising
# the plugin works.
if ! command -v python3 >/dev/null 2>&1; then
    fail "python3 not found; the queue worker cannot run."
else
    if ! python3 -c "import sqlite3, urllib.request" 2>/dev/null; then
        fail "python3 is missing sqlite3 or urllib; the queue worker cannot run."
    fi
fi

# ── Apache: the anonymous page ───────────────────────────────────────────────
# See scripts/apache-audience.conf for why this is needed at all.
if [ ! -f "$APACHE_CONF_SRC" ]; then
    fail "apache-audience.conf missing from the plugin; the audience page will not be reachable."
else
    if sudo cp "$APACHE_CONF_SRC" "$APACHE_CONF_DST"; then
        sudo chmod 644 "$APACHE_CONF_DST"
        if sudo a2enconf "$APACHE_CONF_NAME" >/dev/null 2>&1; then
            # configtest before reload: a reload with a broken config takes the
            # whole FPP UI down, not just this plugin.
            if sudo apache2ctl configtest >/dev/null 2>&1; then
                sudo service apache2 reload >/dev/null 2>&1 \
                    && echo "✓ Audience page enabled at /audience" \
                    || warn "apache2 reload failed; run 'sudo service apache2 reload' by hand."
            else
                sudo a2disconf "$APACHE_CONF_NAME" >/dev/null 2>&1
                fail "apache2 rejected the audience config; it has been disabled again. Run 'sudo apache2ctl configtest' to see why."
            fi
        else
            fail "a2enconf ${APACHE_CONF_NAME} failed; the audience page will not be reachable."
        fi
    else
        fail "Could not write ${APACHE_CONF_DST}; the audience page will not be reachable."
    fi
fi

# ── Start the worker ─────────────────────────────────────────────────────────
# Normally FPP's postStart hook does this when fppd starts, but on a fresh
# install fppd is already up, so nothing would start the worker until the next
# restart -- and the queue would just fill.
if [ -x "$PLUGIN_DIR/scripts/postStart.sh" ]; then
    "$PLUGIN_DIR/scripts/postStart.sh" || warn "Could not start the queue worker; it will start with fppd."
fi

install_summary
