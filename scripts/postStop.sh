#!/bin/bash
# FPP postStop hook -- stops the Audience Text worker.
#
# SIGTERM, not SIGKILL: the worker handles it at the top of its loop, which
# means it does not get killed holding a message in the 'showing' state. (It
# recovers from that on the next start anyway, but a clean stop leaves nothing
# to recover and nothing alarming in the log.)

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DAEMON="$PLUGIN_DIR/daemon/audiencetextd.py"
PID_FILE="$PLUGIN_DIR/data/daemon.pid"

PIDS=$(pgrep -f "python3? .*${DAEMON}" 2>/dev/null)
if [ -z "$PIDS" ]; then
    rm -f "$PID_FILE"
    exit 0
fi

kill $PIDS 2>/dev/null

# Give it a moment to finish the message it is on, then insist.
for _ in $(seq 1 20); do
    sleep 0.5
    [ -z "$(pgrep -f "python3? .*${DAEMON}" 2>/dev/null)" ] && break
done

STILL=$(pgrep -f "python3? .*${DAEMON}" 2>/dev/null)
if [ -n "$STILL" ]; then
    echo "Audience Text worker did not stop; killing."
    kill -9 $STILL 2>/dev/null
fi

rm -f "$PID_FILE"
echo "Audience Text worker stopped."
