#!/bin/bash
# FPP postStart hook -- starts the Audience Text worker.
#
# Singleton by process, not just by pid file. A stale or root-owned
# data/daemon.pid used to be enough to convince a hook like this that nothing
# was running, and two workers would then each claim messages and put two Text
# effects on the same matrix -- which is the one failure this plugin exists to
# prevent. So we look for the actual process before deciding.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DAEMON="$PLUGIN_DIR/daemon/audiencetextd.py"
PID_FILE="$PLUGIN_DIR/data/daemon.pid"
LOG_FILE="$PLUGIN_DIR/data/daemon.log"

mkdir -p "$PLUGIN_DIR/data"

# This hook runs as whoever started fppd, which from systemd is root -- but the
# audience pages are served by php-fpm as the fpp user, and both write the same
# queue.db. A worker started as root creates that database root-owned, and every
# guest submission then dies on "attempt to write a readonly database" while the
# operator's own page looks perfectly healthy. So: fix the ownership of anything
# already there, and drop to fpp before launching.
if [ "$(id -u)" = "0" ]; then
    chown -R fpp:fpp "$PLUGIN_DIR/data" 2>/dev/null
    RUN_AS="sudo -u fpp"
else
    RUN_AS=""
fi

# Echo the PIDs of every running instance of *this* plugin's worker. Matching on
# the script path rather than the bare name keeps us from ever touching another
# plugin's python process, or this shell.
running_pids() {
    pgrep -f "python3? .*${DAEMON}" 2>/dev/null | grep -v "^$$\$"
}

PIDS=$(running_pids)
if [ -n "$PIDS" ]; then
    echo "Audience Text worker already running (pid $(echo $PIDS | tr '\n' ' '))."
    exit 0
fi

# A pid file with no process behind it is just litter; clear it so the next
# start is not second-guessing it.
if [ -e "$PID_FILE" ]; then
    rm -f "$PID_FILE"
fi

# The redirect has to happen inside the su'd shell, not out here: done by a
# root shell it would recreate daemon.log root-owned, which is the same trap the
# database fell into above.
$RUN_AS nohup bash -c "exec python3 '$DAEMON' >> '$LOG_FILE' 2>&1" &
DAEMON_PID=$!

# $! is the wrapper (sudo/bash), not python3. Good enough to record, but confirm
# a real worker actually came up rather than reporting success for a process
# that exited immediately.
sleep 1
PIDS=$(running_pids)
if [ -z "$PIDS" ]; then
    echo "Audience Text worker failed to start -- see $LOG_FILE"
    exit 1
fi
echo "$PIDS" | head -1 > "$PID_FILE"
[ "$(id -u)" = "0" ] && chown fpp:fpp "$PID_FILE" 2>/dev/null
echo "Audience Text worker started (pid $(cat "$PID_FILE"))."
