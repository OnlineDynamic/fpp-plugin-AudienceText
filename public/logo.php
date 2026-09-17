<?php
/**
 * Serves the one configured logo image to the anonymous audience page.
 *
 * A passthrough rather than an Apache Alias onto /home/fpp/media/images,
 * because aliasing that directory would publish every image the operator has
 * ever uploaded to anyone on the tether. This exposes exactly the one file the
 * settings name, and takes no parameters at all -- there is nothing in the
 * request that reaches the filesystem.
 */
require_once dirname(__DIR__) . '/lib/common.php';

$path = at_logo_file();
if ($path === null) {
    // No logo configured, or it has been deleted from under us. 404 rather than
    // a broken-looking empty 200; the page omits the <img> entirely in this
    // case anyway, so nothing should be asking.
    http_response_code(404);
    exit;
}

$types = at_logo_types();
$type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))];
$mtime = filemtime($path);
$etag = '"' . md5($path . $mtime) . '"';

// Worth caching -- it is the one genuinely static thing on the page, and it is
// fetched by every phone in the room. Keyed on the file's mtime so swapping the
// logo mid-event still takes effect.
header('Content-Type: ' . $type);
header('Content-Security-Policy: default-src \'none\'');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : '';
if ($ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . filesize($path));
readfile($path);
