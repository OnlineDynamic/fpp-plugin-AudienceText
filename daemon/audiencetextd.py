#!/usr/bin/env python3
"""
Audience Text worker.

One process, one message at a time. That serialisation is the entire reason
this daemon exists: the audience pages only ever INSERT a row, and nothing but
this loop is allowed to talk to fppd about the target model. Two people hitting
Send at the same moment therefore cannot produce two overlapping Text effects
fighting over the same matrix -- the second one simply waits its turn.

Started and stopped by FPP's plugin hooks (scripts/postStart.sh,
scripts/postStop.sh), so it comes up with fppd and goes down with it.
"""

import json
import logging
import logging.handlers
import os
import signal
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# ---------------------------------------------------------------------------
# Shared constants.
#
# These same facts also live in lib/common.php and scripts/apache-audience.conf.
# Change one, change all three.
# ---------------------------------------------------------------------------
PLUGIN_NAME = "fpp-plugin-AudienceText"
PLUGIN_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.path.join(PLUGIN_DIR, "data", "queue.db")
SETTINGS_FILE = "/home/fpp/media/config/plugin." + PLUGIN_NAME
LOG_PATH = os.path.join(PLUGIN_DIR, "data", "daemon.log")

# fppd's own HTTP API. Bound to 127.0.0.1 only, and deliberately not behind
# Apache: going through the web server would mean going through FPP's UI
# password, which is the thing this plugin is designed to sit outside of.
FPPD_API = "http://127.0.0.1:32322"

# The settings the WORKER uses, with the same defaults settings.json declares.
#
# Deliberately a subset: message length, the cooldown, the queue ceiling, the
# logo and the colour switches are all enforced at submit time and this process
# never reads them. Listing them here anyway would be four more values to keep
# in step with lib/common.php for no reason -- and this dict doubles as the
# allow-list when the settings file is parsed, so anything absent is simply
# ignored rather than misread.
DEFAULTS = {
    "AudienceTextEnabled": "1",
    "AudienceTextModel": "",
    "AudienceTextFont": "NimbusSans-Bold",
    "AudienceTextFontSize": "48",
    "AudienceTextDirection": "Right to Left",
    "AudienceTextSpeed": "40",
    "AudienceTextGap": "2",
    "AudienceTextMaxShowSeconds": "90",
}

# How long to wait for fppd to report the effect as running before deciding it
# never started. Measured at ~1s on a Pi; 5 gives generous headroom.
START_GRACE_SECONDS = 5.0

# Poll interval while watching for the effect to finish. The scroll itself runs
# at tens of frames a second inside fppd, so a 250ms poll adds at most a quarter
# second to each message and costs almost nothing.
POLL_INTERVAL = 0.25

# Finished rows are kept this long so the operator panel can show recent
# history, then pruned. A multi-day event should not end with a queue.db full
# of every message anyone ever sent.
HISTORY_SECONDS = 24 * 3600

log = logging.getLogger("audiencetext")

_running = True


def _handle_signal(signum, _frame):
    """Stop at the top of the loop rather than mid-message."""
    global _running
    log.info("Signal %d received -- finishing up and exiting.", signum)
    _running = False


# ---------------------------------------------------------------------------
# Settings
# ---------------------------------------------------------------------------

class Settings:
    """
    The plugin settings file, re-read when it changes.

    The operator edits these from FPP's UI mid-event -- turning submissions off
    between sessions, slowing the scroll down because the back row cannot keep
    up. Requiring an fppd restart to pick that up would mean restarting the
    show, so the file's mtime is checked on every pass instead.
    """

    def __init__(self):
        self._values = dict(DEFAULTS)
        self._mtime = None
        self.reload()

    def reload(self):
        try:
            mtime = os.path.getmtime(SETTINGS_FILE)
        except OSError:
            # No file yet: the operator has not saved the settings page even
            # once. Defaults are correct, and there is nothing to warn about.
            return
        if mtime == self._mtime:
            return
        values = dict(DEFAULTS)
        try:
            with open(SETTINGS_FILE, "r") as fh:
                for line in fh:
                    line = line.strip()
                    if not line or line.startswith("#") or line.startswith(";"):
                        continue
                    if "=" not in line:
                        continue
                    key, _, raw = line.partition("=")
                    key = key.strip()
                    # FPP writes this file as INI with quoted values.
                    raw = raw.strip().strip('"').strip("'")
                    # Only known keys, so a stray line cannot invent a setting.
                    if key in DEFAULTS and raw != "":
                        values[key] = raw
        except OSError as exc:
            log.warning("Could not read %s: %s -- keeping previous values.", SETTINGS_FILE, exc)
            return
        self._values = values
        self._mtime = mtime
        log.info("Settings reloaded: model=%r direction=%r speed=%s font=%s size=%s enabled=%s",
                 self.model, self.direction, self.speed, self.font, self.font_size, self.enabled)

    def _int(self, key):
        try:
            return int(float(self._values.get(key, DEFAULTS[key])))
        except (TypeError, ValueError):
            return int(float(DEFAULTS[key]))

    @property
    def enabled(self):
        return self._values.get("AudienceTextEnabled") in ("1", "true", "on", "TRUE")

    @property
    def model(self):
        return self._values.get("AudienceTextModel", "")

    @property
    def font(self):
        return self._values.get("AudienceTextFont") or DEFAULTS["AudienceTextFont"]

    @property
    def font_size(self):
        return max(4, self._int("AudienceTextFontSize"))

    @property
    def direction(self):
        """
        Which way a message travels. Only the four scrolling directions are
        offered (settings.json enumerates them), never "Center": this loop
        advances when fppd reports the effect finished, and a centred still has
        no natural end to report -- it would either vanish in a frame or hold
        the queue until the watchdog fired.
        """
        return self._values.get("AudienceTextDirection") or DEFAULTS["AudienceTextDirection"]

    @property
    def speed(self):
        # fppd divides by this to schedule ticks and guards against zero, but a
        # zero here would also make our own duration estimate divide by zero.
        return max(1, self._int("AudienceTextSpeed"))

    @property
    def gap(self):
        return max(0, self._int("AudienceTextGap"))

    @property
    def max_show_seconds(self):
        return max(5, self._int("AudienceTextMaxShowSeconds"))


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

def connect():
    """
    The only way this daemon opens the queue database.

    The pragmas are not optional. WAL because PHP is writing new submissions
    into this same file while we read it; busy_timeout so a collision waits
    instead of raising; foreign_keys so "delete" means here what it means on
    the PHP side.
    """
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=5.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA busy_timeout = 5000")
    conn.execute("PRAGMA foreign_keys = ON")
    migrate(conn)
    return conn


def migrate(conn):
    """Mirror of at_db_migrate() in lib/common.php; both are idempotent, so
    whichever side reaches a fresh install first creates the schema."""
    conn.execute(
        """CREATE TABLE IF NOT EXISTS messages (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               message       TEXT NOT NULL,
               color         TEXT NOT NULL,
               submitter_ip  TEXT NOT NULL,
               state         TEXT NOT NULL DEFAULT 'pending',
               submitted_at  REAL NOT NULL,
               started_at    REAL,
               finished_at   REAL,
               error         TEXT
           )"""
    )
    conn.execute("CREATE INDEX IF NOT EXISTS idx_messages_state_id ON messages (state, id)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_messages_ip_time ON messages (submitter_ip, submitted_at)")

    # Additive, mirroring at_db_add_column() in lib/common.php: an install that
    # predates the colour styles already has a messages table, so CREATE TABLE
    # above would never introduce these.
    existing = {r["name"] for r in conn.execute("PRAGMA table_info(messages)")}
    for name, definition in (("style", "TEXT NOT NULL DEFAULT 'white'"),
                             ("mode", "TEXT NOT NULL DEFAULT 'Single'"),
                             ("palette", "TEXT NOT NULL DEFAULT 'Custom'"),
                             ("color_speed", "INTEGER NOT NULL DEFAULT 0")):
        if name not in existing:
            conn.execute("ALTER TABLE messages ADD COLUMN %s %s" % (name, definition))
    conn.commit()


def recover_orphans(conn):
    """
    Clear any row left mid-show by a previous run.

    A 'showing' row is what makes every other submission wait, so one left
    behind by a kill -9 would wedge the queue for the rest of the event with no
    obvious cause. They are failed rather than re-queued on purpose: if the
    message is what took the daemon down, re-queueing it would do so again on
    every restart.
    """
    cur = conn.execute(
        """UPDATE messages
              SET state = 'failed',
                  finished_at = ?,
                  error = 'daemon restarted while this message was on screen'
            WHERE state = 'showing'""",
        (time.time(),),
    )
    conn.commit()
    if cur.rowcount:
        log.warning("Cleared %d message(s) left mid-show by a previous run.", cur.rowcount)


def claim_next(conn):
    """
    Atomically take the oldest pending message.

    Only one daemon should ever be running (scripts/postStart.sh enforces that),
    but the claim is still done as a conditional UPDATE so that if a second copy
    ever does get started it cannot hand the same message to two workers and put
    two Text effects on the matrix at once -- the exact failure this plugin is
    supposed to make impossible.
    """
    now = time.time()
    cur = conn.execute(
        """UPDATE messages
              SET state = 'showing', started_at = ?
            WHERE id = (SELECT id FROM messages WHERE state = 'pending' ORDER BY id LIMIT 1)
              AND state = 'pending'""",
        (now,),
    )
    conn.commit()
    if cur.rowcount != 1:
        return None
    return conn.execute(
        "SELECT * FROM messages WHERE state = 'showing' ORDER BY id LIMIT 1"
    ).fetchone()


def row_state(conn, row_id):
    """Current state of one row, or None if it has somehow gone away."""
    cur = conn.execute("SELECT state FROM messages WHERE id = ?", (row_id,)).fetchone()
    return cur["state"] if cur else None


def finish(conn, row_id, state, error=None):
    conn.execute(
        "UPDATE messages SET state = ?, finished_at = ?, error = ? WHERE id = ?",
        (state, time.time(), error, row_id),
    )
    conn.commit()


def prune(conn):
    conn.execute(
        "DELETE FROM messages WHERE state NOT IN ('pending', 'showing') AND finished_at < ?",
        (time.time() - HISTORY_SECONDS,),
    )
    conn.commit()


# ---------------------------------------------------------------------------
# fppd
# ---------------------------------------------------------------------------

def fppd_request(path, method="GET", payload=None, timeout=5.0):
    url = FPPD_API + path
    data = None
    headers = {}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read().decode("utf-8", "replace")


def model_status(model):
    """
    (isActive, effectRunning, width, height) for the target model, or None if
    fppd did not answer. A missing model is also None -- fppd 404s, which
    urlopen raises.
    """
    try:
        body = fppd_request("/overlays/model/" + urllib.parse.quote(model))
        data = json.loads(body)
        return (int(data.get("isActive", 0)),
                bool(data.get("effectRunning", False)),
                int(data.get("width", 0)),
                int(data.get("height", 0)))
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log.warning("Could not read model %r state: %s", model, exc)
        return None


def start_text(settings, row):
    """
    Put one message on the matrix.

    Goes through the generic command endpoint rather than the friendlier
    PUT /overlays/model/{m}/text shim, for two reasons. The shim's AutoEnable is
    a plain boolean, so it can only ever enable the model opaquely -- and
    "Transparent" is what we actually want, so the text floats over whatever the
    show is already doing and the blank buffer left after the scroll is
    invisible rather than a black rectangle. The shim also hardcodes the colour
    arguments to their defaults, so the multi-colour styles are unreachable
    through it at all.

    Passing AutoEnable also buys the release for free: fppd only auto-enables a
    model that was Disabled, and it puts such a model back to Disabled when the
    effect ends -- so the matrix returns to the show by itself with no second
    call from us.
    """
    args = [
        settings.model,
        "Transparent",
        "Text",
        row["color"],
        settings.font,
        str(settings.font_size),
        "false",            # AntiAlias: smoothing a 48px glyph onto a 108px
                            # tall matrix just makes the edges muddy.
        settings.direction,
        str(settings.speed),
        "0",                # Duration 0 = scroll until it has left the screen.
                            # The ceiling is our own watchdog instead, so a long
                            # message is never chopped off mid-word.
        row["message"],
    ]

    # The eight colour arguments are positional and optional, and fppd reads
    # them up to the last one supplied -- so sending ColorSpeed means sending
    # all eight. Only for a multi-colour style: a "Single" message sends the
    # original eleven arguments and takes the effect's original one-fill-colour
    # path, byte for byte as before the styles existed.
    #
    # Color2..Color5 and NumColors only matter to the "Custom" palette, which
    # none of the curated styles use; they are sent as the defaults fppd itself
    # would have applied so that the positions line up.
    if row["mode"] != "Single":
        args += [
            row["mode"],            # ColorMode
            row["palette"],         # Palette
            "2",                    # NumColors
            "#0000FF", "#00FF00", "#FFFF00", "#FF00FF",   # Color2..Color5
            str(int(row["color_speed"])),                  # ColorSpeed
        ]

    return fppd_request("/command", method="POST",
                        payload={"command": "Overlay Model Effect", "args": args},
                        timeout=10.0)


def stop_and_release(model):
    """
    Cut a message short and hand the model back to the show.

    All three calls are needed, in this order:

      1. "Stop Effects", because /clear on its own does NOT stop a running
         effect -- it only blanks the buffer, and the scroll repaints over it on
         its very next tick. A skip built on /clear alone is a one-frame
         flicker and nothing more.
      2. /clear, because stopping the effect leaves its last frame sitting in
         the buffer.
      3. State 0 (Disabled), because the auto-release we normally get for free
         belongs to the effect we just deleted, so nothing else is going to put
         the model back and it would stay overlaid on the show.
    """
    quoted = urllib.parse.quote(model)
    try:
        fppd_request("/command", method="POST",
                     payload={"command": "Overlay Model Effect",
                              "args": [model, "false", "Stop Effects"]},
                     timeout=5.0)
        fppd_request("/overlays/model/" + quoted + "/clear")
        # State is an enum int on this endpoint, not a name: 0 == Disabled.
        fppd_request("/overlays/model/" + quoted + "/state",
                     method="PUT", payload={"State": 0})
    except (urllib.error.URLError, OSError) as exc:
        log.error("Could not release model %r: %s", model, exc)


def estimated_seconds(settings, message, model_width, model_height):
    """
    Roughly how long this message should take to scroll clear: it has to travel
    the length of the model plus its own, along whichever axis it is moving.

    Only used to tell "finished before we looked" apart from "never started" --
    both of which present as a message we never once saw running. A short
    message at a high speed really can beat the start grace; a long one cannot,
    so if the estimate is comfortably over the grace and we still saw nothing,
    something actually went wrong.

    The 0.55 is an average advance width for a proportional sans as a fraction
    of point size. It does not need to be accurate, only the right order.
    """
    text_w = max(1, int(len(message) * settings.font_size * 0.55))
    text_h = max(1, settings.font_size)
    if settings.direction in ("Bottom to Top", "Top to Bottom"):
        travel = max(1, model_height) + text_h
    else:
        travel = max(1, model_width) + text_w
    return travel / float(settings.speed)


# ---------------------------------------------------------------------------
# The loop
# ---------------------------------------------------------------------------

def show_message(conn, settings, row):
    """Run one message to completion. Returns when the matrix is free again."""
    model = settings.model
    message = row["message"]

    before = model_status(model)
    if before is None:
        finish(conn, row["id"], "failed", "fppd did not answer")
        return
    model_width, model_height = before[2], before[3]
    if before[0] != 0:
        # Someone or something else already has this overlay enabled. We still
        # show the message, but say so in the log: after the scroll ends fppd
        # will not auto-release a model it did not auto-enable, so the operator
        # may see the matrix stay overlaid afterwards and should know why.
        log.warning("Model %r was already active (state %d) before message %d; "
                    "it will not be auto-released when the message finishes.",
                    model, before[0], row["id"])

    log.info("Showing message %d: %r (style=%s mode=%s palette=%s)",
             row["id"], message, row["style"], row["mode"], row["palette"])
    try:
        start_text(settings, row)
    except (urllib.error.URLError, OSError) as exc:
        log.error("Could not start message %d: %s", row["id"], exc)
        finish(conn, row["id"], "failed", "could not reach the display")
        return

    deadline = time.time() + settings.max_show_seconds
    grace_until = time.time() + START_GRACE_SECONDS
    seen_running = False

    while _running:
        status = model_status(model)
        if status is None:
            # A single unanswered poll is not worth abandoning a message that is
            # probably still scrolling; keep going until the watchdog decides.
            time.sleep(POLL_INTERVAL)
            continue
        # The operator's Skip button marks this row from the web UI rather than
        # touching fppd, so that this loop stays the only thing that ever drives
        # the model. Checking the row we were handed is a primary-key read every
        # quarter second, which is free next to the HTTP poll above it.
        if row_state(conn, row["id"]) != "showing":
            log.info("Message %d was skipped by the operator.", row["id"])
            stop_and_release(model)
            return

        _, effect_running, _, _ = status
        if effect_running:
            seen_running = True
        elif seen_running:
            log.info("Message %d finished after %.1fs.", row["id"], time.time() - row["started_at"])
            finish(conn, row["id"], "done")
            return
        elif time.time() > grace_until:
            break
        if time.time() > deadline:
            log.error("Message %d hit the %ds watchdog -- clearing the model and moving on.",
                      row["id"], settings.max_show_seconds)
            stop_and_release(model)
            finish(conn, row["id"], "failed", "timed out on screen")
            return
        time.sleep(POLL_INTERVAL)

    if not _running:
        # Shutting down mid-message. Leave the row 'showing'; recover_orphans()
        # on the next start will resolve it rather than us guessing now.
        return

    # Never once saw it running. See estimated_seconds() for why the estimate
    # decides which of the two possible causes this was.
    estimate = estimated_seconds(settings, message, model_width, model_height)
    if estimate <= START_GRACE_SECONDS:
        log.info("Message %d finished inside the start grace (estimated %.1fs).", row["id"], estimate)
        finish(conn, row["id"], "done")
    else:
        log.error("Message %d never started (estimated %.1fs of scrolling). "
                  "Check that model %r and font %r exist.",
                  row["id"], estimate, model, settings.font)
        finish(conn, row["id"], "failed", "the display did not accept the message")


def main():
    setup_logging()
    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)

    log.info("Audience Text worker starting (pid %d).", os.getpid())
    settings = Settings()
    conn = connect()
    recover_orphans(conn)

    last_prune = 0.0
    idle_logged = False

    while _running:
        settings.reload()

        if not settings.enabled or not settings.model:
            # Submissions closed, or not configured. Deliberately do NOT drain
            # the queue: an operator who closes submissions between sessions
            # expects the matrix to go quiet, not to keep playing out a backlog.
            if not idle_logged:
                log.info("Idle: %s", "submissions are closed"
                         if not settings.enabled else "no model configured")
                idle_logged = True
            time.sleep(1.0)
            continue
        idle_logged = False

        if time.time() - last_prune > 300:
            prune(conn)
            last_prune = time.time()

        row = claim_next(conn)
        if row is None:
            time.sleep(0.25)
            continue

        show_message(conn, settings, row)

        # The blank gap between messages. Interruptible so a shutdown during it
        # does not have to wait the gap out.
        waited = 0.0
        while _running and waited < settings.gap:
            time.sleep(min(0.25, settings.gap - waited))
            waited += 0.25

    log.info("Audience Text worker stopped.")


def setup_logging():
    """
    Rotating file log at a real level.

    print() to a file that nothing ever truncates is how a plugin log quietly
    becomes the biggest thing on the SD card, and how the one line that explains
    tonight's failure ends up buried in a hundred thousand routine ones.
    """
    os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    handler = logging.handlers.RotatingFileHandler(LOG_PATH, maxBytes=1 * 1024 * 1024, backupCount=3)
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)-7s %(message)s"))
    log.addHandler(handler)
    log.setLevel(logging.INFO)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        log.exception("Audience Text worker died unexpectedly.")
        sys.exit(1)
