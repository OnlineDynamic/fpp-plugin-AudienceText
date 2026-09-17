<?php
/**
 * Operator-side queue API.
 *
 * Reached through plugin.php, which means it sits INSIDE FPP's
 * password-protected tree -- the opposite of public/, and deliberately so.
 * Nothing here should ever be reachable from the audience page.
 *
 *   plugin.php?plugin=fpp-plugin-AudienceText&page=api/queue.php&nopage=1
 */
require_once dirname(__DIR__) . '/lib/common.php';

$db = at_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'skip') {
        // Mark the row and let the worker do the talking. This page does NOT
        // call fppd itself: the whole design rests on exactly one process
        // driving the model, and a skip issued from here at the moment the
        // worker was starting the next message would be two of us at once.
        //
        // The worker re-reads this row on every poll, sees it is no longer
        // 'showing', and stops the effect and releases the model on our behalf.
        $stmt = $db->prepare(
            "UPDATE messages SET state = 'skipped', finished_at = :t, error = 'skipped by the operator'
              WHERE state = 'showing'"
        );
        $stmt->execute(array(':t' => microtime(true)));
        if ($stmt->rowCount() === 0) {
            at_json(array('ok' => false, 'error' => 'Nothing is on screen.'), 409);
        }
        at_json(array('ok' => true));
    }

    if ($action === 'clear_queue') {
        // Only pending rows. The one on screen is the worker's business.
        $stmt = $db->prepare(
            "UPDATE messages SET state = 'skipped', finished_at = :t, error = 'cleared by the operator'
              WHERE state = 'pending'"
        );
        $stmt->execute(array(':t' => microtime(true)));
        at_json(array('ok' => true, 'cleared' => $stmt->rowCount()));
    }

    if ($action === 'test') {
        // An operator test message goes through the queue like any other, so
        // what it proves is the whole path -- worker, fppd, model, font -- and
        // not just that fppd answers.
        $text = at_sanitize(isset($_POST['message']) ? $_POST['message'] : '',
                            at_setting_int('AudienceTextMaxLength'));
        if ($text === null) {
            at_json(array('ok' => false, 'error' => 'Nothing usable to send.'), 400);
        }
        // The operator can exercise a style too, which is the only way to see
        // what one actually looks like on the real matrix before the audience
        // starts choosing them.
        $style = at_resolve_style(isset($_POST['style']) ? $_POST['style'] : '');
        if ($style === null) {
            $style = at_default_style();
        }
        $stmt = $db->prepare(
            "INSERT INTO messages (message, color, style, mode, palette, color_speed,
                                   submitter_ip, state, submitted_at)
             VALUES (:m, :c, :st, :mo, :pa, :cs, 'operator', 'pending', :t)"
        );
        $stmt->execute(array(
            ':m' => $text,
            ':c' => $style['color'],
            ':st' => $style['id'],
            ':mo' => $style['mode'],
            ':pa' => $style['palette'],
            ':cs' => $style['speed'],
            ':t' => microtime(true),
        ));
        at_json(array('ok' => true, 'id' => (int) $db->lastInsertId()));
    }

    at_json(array('ok' => false, 'error' => 'Unknown action'), 400);
}

// GET: everything the operator panel refreshes.
$status = at_queue_status();
$status['workerRunning'] = at_worker_running();
$status['model'] = at_setting('AudienceTextModel');
$status['styles'] = array();
foreach (at_styles() as $id => $style) {
    $status['styles'][$id] = $style['label'];
}

$status['pending'] = $db->query(
    "SELECT id, message, color, style, submitter_ip, submitted_at
       FROM messages WHERE state = 'pending' ORDER BY id LIMIT 25"
)->fetchAll(PDO::FETCH_ASSOC);

$status['recent'] = $db->query(
    "SELECT id, message, state, style, submitter_ip, finished_at, error
       FROM messages WHERE state NOT IN ('pending', 'showing')
      ORDER BY finished_at DESC LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);

at_json($status);
