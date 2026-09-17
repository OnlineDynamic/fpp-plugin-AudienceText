<?php
/**
 * Shared plumbing for every PHP entry point in this plugin.
 *
 * This file is deliberately self-contained: the audience-facing pages in
 * public/ are served straight off an Apache Alias, OUTSIDE /opt/fpp/www, and so
 * they never get FPP's common.php bootstrap. Pulling FPP includes in here would
 * make the anonymous pages depend on the very tree we are trying to stay out
 * of. Everything below therefore reads the settings file itself rather than
 * calling LoadPluginSettings().
 */

// ---------------------------------------------------------------------------
// Shared constants.
//
// These same four facts are also spelled out in daemon/audiencetextd.py and in
// scripts/apache-audience.conf. If you change one, change it in all three --
// this exact class of drift is what the house rules call out.
// ---------------------------------------------------------------------------
define('AT_PLUGIN_NAME', 'fpp-plugin-AudienceText');
define('AT_PLUGIN_DIR', dirname(__DIR__));
define('AT_DB_PATH', AT_PLUGIN_DIR . '/data/queue.db');
define('AT_SETTINGS_FILE', '/home/fpp/media/config/plugin.' . AT_PLUGIN_NAME);
// The public page's URL path, as installed by scripts/apache-audience.conf.
define('AT_PUBLIC_PATH', '/audience');
// FPP's image library, where the File Manager uploads to. The logo is
// chosen from here; see at_logo_file().
define('AT_IMAGE_DIR', '/home/fpp/media/images');

/**
 * Defaults must match settings.json. They are repeated here because the
 * audience pages have to answer correctly before the operator has ever visited
 * the setup page and written a config file -- on a fresh install that file does
 * not exist at all.
 */
function at_defaults()
{
    return array(
        'AudienceTextEnabled' => '1',
        'AudienceTextModel' => '',
        'AudienceTextFont' => 'NimbusSans-Bold',
        'AudienceTextFontSize' => '48',
        'AudienceTextDirection' => 'Right to Left',
        'AudienceTextSpeed' => '40',
        'AudienceTextGap' => '2',
        'AudienceTextMaxLength' => '60',
        'AudienceTextRateLimit' => '60',
        'AudienceTextQueueMax' => '20',
        'AudienceTextMaxShowSeconds' => '90',
        'AudienceTextHeading' => 'Put your message on the big screen',
        'AudienceTextBlurb' => "Type something short. It'll scroll across the matrix when it's your turn.",
        'AudienceTextAllowColor' => '1',
        'AudienceTextAllowStyles' => '1',
        'AudienceTextLogo' => '',
        'AudienceTextLogoHeight' => '140',
    );
}

/** Settings as written by FPP's plugin settings UI, over the defaults. */
function at_settings()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = at_defaults();
    if (is_readable(AT_SETTINGS_FILE)) {
        $ini = @parse_ini_file(AT_SETTINGS_FILE);
        if (is_array($ini)) {
            // Only known keys, so a stray line in the config file cannot
            // introduce a setting the rest of the code has never heard of.
            foreach (at_defaults() as $key => $unused) {
                if (isset($ini[$key]) && $ini[$key] !== '') {
                    $cache[$key] = $ini[$key];
                }
            }
        }
    }
    return $cache;
}

function at_setting($key)
{
    $s = at_settings();
    return isset($s[$key]) ? $s[$key] : null;
}

function at_setting_int($key)
{
    return intval(at_setting($key));
}

function at_setting_bool($key)
{
    $v = at_setting($key);
    return ($v === '1' || $v === 1 || $v === 'true' || $v === 'on');
}

/**
 * Open the queue database.
 *
 * Every caller goes through here rather than calling `new PDO('sqlite:...')`
 * directly, because the three pragmas below are not optional:
 *
 *  - WAL, because the audience pages write while the daemon reads, and the
 *    default rollback journal makes those two block each other hard enough to
 *    show up as a spinning submit button.
 *  - busy_timeout, so a concurrent write waits instead of instantly throwing
 *    SQLITE_BUSY at whoever was unlucky.
 *  - foreign_keys, so "delete" means the same thing here as it does anywhere
 *    else that opens this file.
 */
function at_db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dir = dirname(AT_DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . AT_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    at_db_migrate($pdo);
    return $pdo;
}

/**
 * The schema lives here and in daemon/audiencetextd.py's at_db_migrate
 * equivalent; both are idempotent CREATE IF NOT EXISTS, so whichever side
 * touches a fresh install first wins and the other is a no-op.
 */
function at_db_migrate($pdo)
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS messages (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            message       TEXT NOT NULL,
            color         TEXT NOT NULL,
            submitter_ip  TEXT NOT NULL,
            state         TEXT NOT NULL DEFAULT 'pending',
            submitted_at  REAL NOT NULL,
            started_at    REAL,
            finished_at   REAL,
            error         TEXT
        )"
    );
    // The worker's claim query and the audience page's queue-position query are
    // both (state, id); without this they are full scans that get slower all
    // evening as finished messages pile up.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_state_id ON messages (state, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_ip_time ON messages (submitter_ip, submitted_at)");

    // The colour-style columns arrived after the first version, so they are an
    // additive migration rather than part of the CREATE above: an install that
    // already has a messages table would never re-run CREATE TABLE, and would
    // otherwise be left with a schema the worker no longer matches.
    //
    // These four are what the worker replays. The style catalogue itself lives
    // only in at_styles() -- resolving it at submit time and storing the result
    // is what keeps the worker from needing its own copy of that table.
    at_db_add_column($pdo, 'style', "TEXT NOT NULL DEFAULT 'white'");
    at_db_add_column($pdo, 'mode', "TEXT NOT NULL DEFAULT 'Single'");
    at_db_add_column($pdo, 'palette', "TEXT NOT NULL DEFAULT 'Custom'");
    at_db_add_column($pdo, 'color_speed', "INTEGER NOT NULL DEFAULT 0");
}

/** Add a column if it is not already there. SQLite has no ADD COLUMN IF NOT EXISTS. */
function at_db_add_column($pdo, $name, $definition)
{
    foreach ($pdo->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
        if ($col['name'] === $name) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE messages ADD COLUMN " . $name . " " . $definition);
}

/**
 * Reduce whatever the browser sent to something an overlay font can actually
 * render, or return null if nothing usable is left.
 *
 * Backslashes are dropped rather than escaped. fppd's Text effect treats "\n"
 * in the message as a line break and "\\" as a literal backslash, so a guest
 * typing a Windows path would silently get a two-line message on a display
 * that is set up to scroll one. There is no reason an audience message needs a
 * backslash, so the ambiguity is removed at the door.
 */
function at_sanitize($raw, $maxLength)
{
    if (!is_string($raw)) {
        return null;
    }
    // Printable ASCII only. The overlay fonts are Type 1 / URW faces with no
    // useful coverage beyond Latin-1, and an emoji renders as a tofu box that
    // still costs everyone else their place in the queue.
    $out = preg_replace('/[^\x20-\x7E]/', '', $raw);
    // A literal backslash-n becomes a space before bare backslashes are
    // dropped. Dropping the backslash on its own would weld the 'n' onto the
    // next word -- someone typing "hello\nworld" got "hellonworld" on the big
    // screen, which looks like the plugin mangled their message rather than
    // like the escape it was.
    $out = preg_replace('/\\\\[nrt]/', ' ', $out);
    $out = str_replace('\\', '', $out);
    // Collapse runs of whitespace: 60 spaces is 60 characters of nothing
    // scrolling past.
    $out = preg_replace('/\s+/', ' ', $out);
    $out = trim($out);
    if ($out === '') {
        return null;
    }
    // Post-filter every character is one byte, so strlen is the character
    // count -- and the anonymous pages do not pick up a mbstring dependency.
    if (strlen($out) > $maxLength) {
        return null;
    }
    return $out;
}

/**
 * Everything the audience is allowed to choose, as id => definition.
 *
 * Each entry resolves to a complete set of arguments for fppd's Text effect:
 * a base colour, a ColorMode, a Palette and a ColorSpeed. The audience picks
 * one swatch and never sees any of those words.
 *
 * Curated pairs rather than two free dropdowns. fppd offers eleven colour modes
 * and eleven palettes, and most of the 121 combinations read badly on a matrix
 * from the back of a room -- a dim palette in a per-letter mode is mush at
 * twenty metres. So the combinations that do work are picked here once, and the
 * audience gets a visual choice instead of two pieces of jargon.
 *
 * `swatch` is only for drawing the button in the browser; it never reaches
 * fppd. For the named palettes it mirrors what TextColorizer.cpp actually
 * builds, so the button is an honest preview of what will appear.
 *
 * Adding an entry here is all that is needed: submit.php resolves the choice
 * and stores the four fppd arguments on the row, so the worker never has to
 * know this table exists. See at_db_migrate() for those columns.
 */
function at_styles()
{
    return array(
        // --- Single colour. Mode "Single" keeps the original one-fill-colour
        // path through the effect, untouched. ---
        'white'  => array('label' => 'White',  'color' => '#FFFFFF', 'multi' => false),
        'red'    => array('label' => 'Red',    'color' => '#FF0000', 'multi' => false),
        'orange' => array('label' => 'Orange', 'color' => '#FF7F00', 'multi' => false),
        'yellow' => array('label' => 'Yellow', 'color' => '#FFFF00', 'multi' => false),
        'green'  => array('label' => 'Green',  'color' => '#00FF00', 'multi' => false),
        'cyan'   => array('label' => 'Cyan',   'color' => '#00FFFF', 'multi' => false),
        'blue'   => array('label' => 'Blue',   'color' => '#0080FF', 'multi' => false),
        'pink'   => array('label' => 'Pink',   'color' => '#FF00FF', 'multi' => false),

        // --- Multi-colour. ColorSpeed is only honoured by the animated modes,
        // and Sparkle and Chase do nothing at all at speed 0, so those two carry
        // a speed deliberately. ---
        'rainbow' => array(
            'label' => 'Rainbow', 'multi' => true,
            'color' => '#FF0000', 'mode' => 'Gradient Horizontal',
            'palette' => 'Rainbow', 'speed' => 20,
            'swatch' => array('#FF0000', '#FFFF00', '#00FF00', '#00FFFF', '#0000FF', '#FF00FF'),
        ),
        'rainbow_letters' => array(
            'label' => 'Rainbow Letters', 'multi' => true,
            'color' => '#FF0000', 'mode' => 'Per Letter',
            'palette' => 'Rainbow', 'speed' => 0,
            'swatch' => array('#FF0000', '#FFC000', '#00FF00', '#00FFFF', '#8000FF'),
        ),
        'fire' => array(
            'label' => 'Fire', 'multi' => true,
            'color' => '#FF0000', 'mode' => 'Gradient Vertical',
            'palette' => 'Fire', 'speed' => 0,
            'swatch' => array('#FFFF80', '#FFC000', '#FF6000', '#FF0000'),
        ),
        'ocean' => array(
            'label' => 'Ocean', 'multi' => true,
            'color' => '#0020FF', 'mode' => 'Gradient Horizontal',
            'palette' => 'Ocean', 'speed' => 0,
            'swatch' => array('#0020FF', '#00A0FF', '#00FFC0'),
        ),
        'forest' => array(
            'label' => 'Forest', 'multi' => true,
            'color' => '#00A000', 'mode' => 'Per Letter',
            'palette' => 'Forest', 'speed' => 0,
            'swatch' => array('#004000', '#00A000', '#80FF00'),
        ),
        'party' => array(
            'label' => 'Party', 'multi' => true,
            'color' => '#FF00A0', 'mode' => 'Random Letter',
            'palette' => 'Party', 'speed' => 0,
            'swatch' => array('#FF00A0', '#00FFFF', '#FFFF00', '#80FF00', '#FF4000'),
        ),
        'pastel' => array(
            'label' => 'Pastel', 'multi' => true,
            'color' => '#FFB0C0', 'mode' => 'Gradient Diagonal',
            'palette' => 'Pastel', 'speed' => 0,
            'swatch' => array('#FFB0C0', '#B0E0FF', '#FFF0A0', '#B0FFC0'),
        ),
        'glitter' => array(
            'label' => 'Glitter', 'multi' => true,
            'color' => '#FFFFFF', 'mode' => 'Sparkle',
            'palette' => 'Winter', 'speed' => 30,
            'swatch' => array('#FFFFFF', '#80D0FF', '#0040FF'),
        ),
        'chase' => array(
            'label' => 'Chase', 'multi' => true,
            'color' => '#FF0000', 'mode' => 'Chase',
            'palette' => 'Fire', 'speed' => 30,
            'swatch' => array('#FF0000', '#FF6000', '#FFC000', '#FFFF80'),
        ),
    );
}

/**
 * Resolve a style id the browser sent into the arguments the worker replays.
 *
 * Membership of at_styles() is the whole allow-list. The client never sends a
 * mode, a palette or a colour -- only an id -- so there is no path from a
 * request to an arbitrary value being handed to fppd's colour parser.
 *
 * Returns null for anything unknown, and for a multi-colour style when the
 * operator has those switched off.
 */
function at_resolve_style($id)
{
    $styles = at_styles();
    if (!is_string($id) || !isset($styles[$id])) {
        return null;
    }
    $style = $styles[$id];
    if ($style['multi'] && !at_setting_bool('AudienceTextAllowStyles')) {
        return null;
    }
    return array(
        'id' => $id,
        'color' => $style['color'],
        // Single is fppd's default and keeps the original code path through the
        // effect; the worker only sends the colour arguments when it is not.
        'mode' => $style['multi'] ? $style['mode'] : 'Single',
        'palette' => $style['multi'] ? $style['palette'] : 'Custom',
        'speed' => $style['multi'] ? (int) $style['speed'] : 0,
    );
}

/** The styles actually offered right now, honouring the operator's switches. */
function at_offered_styles()
{
    $allowColor = at_setting_bool('AudienceTextAllowColor');
    $allowMulti = $allowColor && at_setting_bool('AudienceTextAllowStyles');
    $out = array();
    foreach (at_styles() as $id => $style) {
        if ($style['multi'] ? !$allowMulti : !$allowColor) {
            continue;
        }
        $out[$id] = $style;
    }
    return $out;
}

/** The style used when the audience is given no choice, or sends nonsense. */
function at_default_style()
{
    return at_resolve_style('white');
}

/**
 * The configured logo, as an absolute path, or null if there is not one.
 *
 * The filename comes from the settings file rather than from a request, so this
 * is not the checkName() case -- but it is still reduced to a basename and
 * confirmed to sit inside the image directory, because "the value came from a
 * trusted file" is exactly the assumption that stops being true the first time
 * something else writes that file.
 *
 * Raster only, deliberately. An SVG is a document that can carry script, and
 * this one would be served from the same origin as the anonymous audience page
 * to every phone in the room. There is nothing to steal there, but serving
 * attacker-supplied markup is not a thing to do casually.
 */
function at_logo_file()
{
    $name = at_setting('AudienceTextLogo');
    if (!is_string($name) || $name === '') {
        return null;
    }
    $name = basename($name);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(at_logo_types()[$ext])) {
        return null;
    }
    $path = realpath(AT_IMAGE_DIR . '/' . $name);
    if ($path === false || !is_file($path)) {
        return null;
    }
    if (strpos($path, rtrim(realpath(AT_IMAGE_DIR), '/') . '/') !== 0) {
        return null;
    }
    return $path;
}

/** Extensions the logo may use, mapped to the type they are served as. */
function at_logo_types()
{
    return array(
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    );
}

/** The submitter's address, used only for the per-device cooldown. */
function at_client_ip()
{
    // No X-Forwarded-For handling on purpose. This page is served directly to
    // devices on the tether; trusting a client-supplied header here would hand
    // anyone a one-line bypass of the cooldown.
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function at_json($payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/**
 * How many messages are ahead of `$id`, counting the one currently on the
 * matrix. 0 means "you are on screen now"; null means the message has already
 * been shown (or was skipped) and no longer has a place in the queue.
 */
function at_queue_position($db, $id)
{
    $stmt = $db->prepare("SELECT state FROM messages WHERE id = :id");
    $stmt->execute(array(':id' => $id));
    $state = $stmt->fetchColumn();
    if ($state === false) {
        return null;
    }
    if ($state === 'showing') {
        return 0;
    }
    if ($state !== 'pending') {
        return null;
    }
    $ahead = (int) $db->query("SELECT COUNT(*) FROM messages WHERE state = 'showing'")->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE state = 'pending' AND id < :id");
    $stmt->execute(array(':id' => $id));
    return $ahead + (int) $stmt->fetchColumn();
}

/**
 * A snapshot of the queue for both the audience page and the operator panel.
 * `$id` is optional and, when given, adds this submitter's own standing.
 */
function at_queue_status($id = null)
{
    $db = at_db();
    $showing = $db->query(
        "SELECT id, message, color, started_at FROM messages WHERE state = 'showing' ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    $status = array(
        'enabled' => at_setting_bool('AudienceTextEnabled'),
        'configured' => at_setting('AudienceTextModel') !== '',
        'queueLength' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE state = 'pending'")->fetchColumn(),
        'nowShowing' => $showing ? $showing['message'] : null,
    );

    if ($id !== null) {
        $stmt = $db->prepare("SELECT state, message, error FROM messages WHERE id = :id");
        $stmt->execute(array(':id' => (int) $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $status['yours'] = array(
                'id' => (int) $id,
                'state' => $row['state'],
                'message' => $row['message'],
                'position' => at_queue_position($db, (int) $id),
                'error' => $row['error'],
            );
        }
    }

    return $status;
}

/** Path to the worker's pid file; written by scripts/postStart.sh. */
define('AT_PID_FILE', AT_PLUGIN_DIR . '/data/daemon.pid');

/**
 * Is the queue worker actually running?
 *
 * The pid file alone is not an answer -- a stale one outlives the process that
 * wrote it -- so the pid is confirmed against /proc, and against the daemon's
 * own path, so that a recycled pid belonging to something else does not read as
 * a healthy worker.
 */
function at_worker_running()
{
    if (!is_readable(AT_PID_FILE)) {
        return false;
    }
    $pid = (int) trim(@file_get_contents(AT_PID_FILE));
    if ($pid <= 0) {
        return false;
    }
    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmdline === false) {
        return false;
    }
    return strpos($cmdline, 'audiencetextd.py') !== false;
}
