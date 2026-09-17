# Audience Text

An FPP plugin that lets a room full of strangers put short messages on one
matrix, without letting any of them anywhere near the FPP UI.

Guests join the FPP wifi tether, open one page, type a line, pick a colour or a
multi-colour style, and it scrolls across the matrix when their turn comes up.
Nothing is shown to two people at once, and nobody can queue-jump by hammering
the button.

## How the pieces fit

```
  phone ──POST──> public/submit.php ──> queue.db ──> audiencetextd.py ──> fppd
   (anonymous, port 80 /audience)      (SQLite)      (one message at a time)
```

Three things are worth knowing before changing any of it.

**The audience page is not an FPP plugin page.** It is served straight off an
Apache `Alias` from `public/`, which is outside `/opt/fpp/www`. That is the
whole trick: FPP applies its UI password as a `Require` directive inside
`<Directory /opt/fpp/www/>`, so anything served through `plugin.php` inherits
the login — correct for the operator's settings page, useless for a page a
conference audience has to open. Because `public/` lives in a different
`<Directory>` block entirely, toggling the FPP password does not touch it. See
`scripts/apache-audience.conf`.

**Only the worker talks to fppd.** The web pages never do, not even the
operator's Skip button. Every path into the display goes through one process
running one message at a time, because that serialisation is the entire product:
two overlapping Text effects on one matrix is exactly the failure this plugin
exists to prevent. The Skip button marks a row in the database and lets the
worker do the talking.

**The style catalogue lives in one place.** fppd's Text effect takes eleven
colour modes and eleven palettes, and most of the 121 combinations are mush on a
matrix seen from the back of a room. `at_styles()` in `lib/common.php` pairs the
ones that work into named styles, and the audience picks a swatch rather than
two pieces of jargon. `submit.php` resolves the chosen id into the four
arguments fppd needs and stores them on the row — so the worker replays what it
is given and never needs its own copy of that table. Adding a style is a single
entry in that one function.

**Completion is observed, not guessed.** After starting a message the worker
polls `GET /api/overlays/model/{model}` and waits for `effectRunning` to go
false. A scrolling Text effect ends by itself once it has left the screen, so
that flag is a real completion signal rather than a timer that hopes for the
best. The `AudienceTextMaxShowSeconds` watchdog only exists for the case where
that never happens.

## Setup

1. Install the plugin. `scripts/fpp_install.sh` copies the Apache config into
   `/etc/apache2/conf-available/fpp-audience-text.conf`, enables it, and starts
   the worker.
2. Open **Content Setup → Audience Text** and choose the model. Until you do,
   the audience page says the screen is not set up rather than accepting
   messages it cannot show.
3. The setup page prints the URLs the device is actually reachable on. Once
   tethering is up, put the tether one on a slide.

## Settings that matter on the night

| Setting | Why you would touch it |
|---|---|
| `Accept audience submissions` | Closes submissions between sessions. The queue stops draining too, so the matrix goes quiet rather than playing out a backlog. |
| `Scroll Direction` | Right-to-left, left-to-right, bottom-to-top or top-to-bottom. Long messages suit the horizontal ones: a vertical scroll renders the line at full width, so anything wider than the model is clipped at the sides. |
| `Scroll Speed` | The main lever on throughput — it sets how long each message holds the queue. |
| `Offer multi-colour styles` | Adds Rainbow, Fire, Party and the rest to the audience's choices. Off leaves only the plain colours. |
| `Logo Image` | Shown at the top of the audience page. Upload it first through **Content Setup → File Manager → Images**, then pick it here. Blank means the page simply starts at the heading — no empty box in front of a room. |
| `Per-Device Cooldown` | One submission per IP per N seconds. The only thing stopping one person owning the screen. |
| `Max Queue Depth` | Submissions are refused past this, so the wait the page promises stays honest. |

## What the audience cannot do

- Choose the model, the scroll direction or the speed. All fixed in the
  settings, which are behind the login.
- Send an arbitrary colour, mode or palette. The browser only ever sends a style
  id; `at_resolve_style()` is the allow-list, and anything unrecognised falls
  back to plain white rather than reaching fppd's colour parser.
- Send anything but printable ASCII, capped at `Max Message Length`.
- Send a line break. fppd's Text effect reads `\n` in a message as one, which
  would put two lines on a display configured to scroll one, so backslash
  escapes are flattened to spaces at the door.
- Reach anything but `index.php`, `submit.php` and `status.php`. `lib/`,
  `daemon/`, `data/` and the operator pages are not aliased and are not
  reachable from the web at all.

## Files

| Path | What it is |
|---|---|
| `public/` | The only web-reachable, anonymous part |
| `public/logo.php` | Serves the one configured logo. A passthrough, not an alias onto the image library, so only that single file is exposed |
| `lib/common.php` | Settings, database, sanitising — shared by every PHP entry point |
| `daemon/audiencetextd.py` | The worker. Stdlib only, no pip dependencies |
| `api/queue.php` | Operator actions, behind the FPP login |
| `scripts/apache-audience.conf` | The anonymous-access exemption |
| `data/queue.db` | The queue. Created on first use, pruned after 24h |

## Facts that live in more than one file

The plugin name, the database path, the settings file path and the `/audience`
URL are each spelled out in `lib/common.php`, `daemon/audiencetextd.py` and
`scripts/apache-audience.conf`. The queue schema is created by both
`at_db_migrate()` and the worker's `migrate()`; both are idempotent, and new
columns are added by `ALTER TABLE` in both, because an existing install's
`CREATE TABLE IF NOT EXISTS` will never introduce one. The setting defaults appear in `settings.json`,
`lib/common.php` and `audiencetextd.py` — the audience pages and the worker both
have to behave correctly before the operator has ever saved the settings page,
which on a fresh install means before that file exists. Change one, change them
all.

## Logs

`data/daemon.log`, rotated at 1MB with three kept. Every message that goes up
(with the style it used), every one that is skipped, and every settings reload
is in there.
