<?php
/**
 * Anonymous submission endpoint. Reachable without a login at
 * /audience/submit.php -- see scripts/apache-audience.conf for why that is
 * deliberate, and lib/common.php for what is done to the text before it is
 * trusted.
 *
 * This endpoint only ever writes a row. It never talks to fppd: the whole point
 * of the queue is that exactly one process drives the overlay, and that process
 * is daemon/audiencetextd.py.
 */
require_once dirname(__DIR__) . '/lib/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    at_json(array('ok' => false, 'error' => 'POST required'), 405);
}

$settings = at_settings();

if (!at_setting_bool('AudienceTextEnabled')) {
    at_json(array('ok' => false, 'error' => 'Submissions are closed right now.'), 503);
}

// A blank model means the operator has not finished setting the plugin up. Say
// so plainly rather than accepting messages into a queue that will never drain
// -- an accepted-then-silently-dropped message is the worst outcome here.
if (at_setting('AudienceTextModel') === '') {
    at_json(array('ok' => false, 'error' => 'The display has not been set up yet.'), 503);
}

$maxLength = at_setting_int('AudienceTextMaxLength');
$raw = isset($_POST['message']) ? $_POST['message'] : '';
$message = at_sanitize($raw, $maxLength);
if ($message === null) {
    $trimmed = trim(preg_replace('/[^\x20-\x7E]/', '', is_string($raw) ? $raw : ''));
    $error = ($trimmed === '')
        ? 'Type something first.'
        : 'Too long - keep it under ' . $maxLength . ' characters.';
    at_json(array('ok' => false, 'error' => $error), 400);
}

// The browser sends a style id and nothing else -- never a colour, a mode or a
// palette. at_resolve_style() is the allow-list: it turns that id into the four
// arguments the worker replays, or refuses it. An unknown id, or a multi-colour
// one while the operator has those switched off, silently falls back to plain
// white rather than failing the submission over a cosmetic choice.
$style = null;
if (at_setting_bool('AudienceTextAllowColor')) {
    $style = at_resolve_style(isset($_POST['style']) ? $_POST['style'] : '');
}
if ($style === null) {
    $style = at_default_style();
}

$ip = at_client_ip();
$db = at_db();

$cooldown = at_setting_int('AudienceTextRateLimit');
if ($cooldown > 0) {
    $stmt = $db->prepare(
        "SELECT submitted_at FROM messages
         WHERE submitter_ip = :ip ORDER BY submitted_at DESC LIMIT 1"
    );
    $stmt->execute(array(':ip' => $ip));
    $last = $stmt->fetchColumn();
    if ($last !== false) {
        $wait = (int) ceil($cooldown - (microtime(true) - (float) $last));
        if ($wait > 0) {
            at_json(array(
                'ok' => false,
                'error' => 'Give someone else a turn - try again in ' . $wait . 's.',
                'retryAfter' => $wait,
            ), 429);
        }
    }
}

$queueMax = at_setting_int('AudienceTextQueueMax');
$pending = (int) $db->query("SELECT COUNT(*) FROM messages WHERE state = 'pending'")->fetchColumn();
if ($pending >= $queueMax) {
    at_json(array('ok' => false, 'error' => 'The queue is full - try again in a few minutes.'), 503);
}

$stmt = $db->prepare(
    "INSERT INTO messages (message, color, style, mode, palette, color_speed,
                           submitter_ip, state, submitted_at)
     VALUES (:m, :c, :st, :mo, :pa, :cs, :ip, 'pending', :t)"
);
$stmt->execute(array(
    ':m' => $message,
    ':c' => $style['color'],
    ':st' => $style['id'],
    ':mo' => $style['mode'],
    ':pa' => $style['palette'],
    ':cs' => $style['speed'],
    ':ip' => $ip,
    ':t' => microtime(true),
));
$id = (int) $db->lastInsertId();

at_json(array(
    'ok' => true,
    'id' => $id,
    'message' => $message,
    'position' => at_queue_position($db, $id),
));
