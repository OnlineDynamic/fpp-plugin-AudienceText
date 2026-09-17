<?php
/**
 * Anonymous queue poll for the audience page: "what is on screen, how many are
 * waiting, and where am I".
 *
 * Kept as a tiny read-only endpoint on purpose. The page polls it every few
 * seconds from every phone in the room, so it touches nothing but two indexed
 * COUNTs and never opens a connection to fppd.
 */
require_once dirname(__DIR__) . '/lib/common.php';

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;
at_json(at_queue_status($id));
