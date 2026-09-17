<?php
/**
 * Operator page. Lives inside FPP's password-protected UI, which is the point:
 * everything that can change what the matrix does, or throw someone out of the
 * queue, is behind the login. The audience only ever sees public/index.php.
 */
require_once dirname(__FILE__) . '/lib/common.php';

/**
 * Every address a guest's phone could plausibly use to reach this box.
 *
 * Printed rather than hard-coded because the tether interface does not exist
 * until tethering comes up, and its address differs between FPP versions and
 * platforms. An operator reading a wrong URL out to a room is a bad five
 * minutes, so show them what the machine actually has.
 */
function at_local_urls()
{
    $urls = array();
    $out = array();
    @exec("ip -4 -o addr show scope global 2>/dev/null", $out);
    foreach ($out as $line) {
        if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
            $urls[] = array('iface' => $m[1], 'url' => 'http://' . $m[2] . AT_PUBLIC_PATH);
        }
    }
    return $urls;
}


/**
 * The target model's geometry, straight from fppd, or null if it cannot be had.
 *
 * Used only to sanity-check the font size and speed below. Those two are the
 * settings that look perfectly reasonable as numbers and produce an unreadable
 * display: a size of 10 on a 108-pixel-tall matrix is legal, renders correctly,
 * lights real pixels -- and is invisible from more than a few feet away, which
 * presents as "the plugin is not working".
 */
function at_model_geometry($model)
{
    if ($model === '') {
        return null;
    }
    $url = 'http://127.0.0.1:32322/overlays/model/' . rawurlencode($model);
    $body = @file_get_contents($url, false, stream_context_create(array(
        'http' => array('timeout' => 3),
    )));
    if ($body === false) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['width'], $data['height'])) {
        return null;
    }
    return array('w' => (int) $data['width'], 'h' => (int) $data['height']);
}

$urls = at_local_urls();
$workerRunning = at_worker_running();
$model = at_setting('AudienceTextModel');
$geom = at_model_geometry($model);

// Same rough advance-width factor the worker uses for its estimate.
$fontSize = at_setting_int('AudienceTextFontSize');
$scrollSpeed = max(1, at_setting_int('AudienceTextSpeed'));
$vertical = in_array(at_setting('AudienceTextDirection'), array('Bottom to Top', 'Top to Bottom'), true);
$sizeWarning = ($geom !== null && $fontSize < $geom['h'] / 4);
// The opposite failure, which only shows up on a short panel: the Text effect
// renders the message at its natural size and clips whatever will not fit, so
// on an 8-pixel-tall sign a font size of 20 silently loses the top and bottom
// of every letter. Roughly, a font's cap height is ~0.7em and its full
// ascender-to-descender span is about 1.2em, so anything much over the panel
// height is already losing pixels.
$tooBigWarning = ($geom !== null && $fontSize > $geom['h']);
$crossSeconds = null;
if ($geom !== null) {
    $sample = at_setting_int('AudienceTextMaxLength');
    $travel = $vertical
        ? $geom['h'] + $fontSize
        : $geom['w'] + (int) ($sample * $fontSize * 0.55);
    $crossSeconds = (int) round($travel / $scrollSpeed);
}
$slowWarning = ($crossSeconds !== null && $crossSeconds > 45);
?>

<div id="global" class="settings">

  <div class="callout callout-info" style="margin-bottom:1rem">
    <h4 style="margin-top:0">The address to give the audience</h4>
<?php if (empty($urls)): ?>
    <p>No global IPv4 address found on this device yet.</p>
<?php else: ?>
    <ul style="margin-bottom:.5rem">
<?php foreach ($urls as $u): ?>
      <li><code style="font-size:1.1em"><?= htmlspecialchars($u['url'], ENT_QUOTES, 'UTF-8') ?></code>
          <span class="text-muted">(<?= htmlspecialchars($u['iface'], ENT_QUOTES, 'UTF-8') ?>)</span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <p class="text-muted" style="margin-bottom:0">
      Once tethering is up, the address on the tether interface is the one to put
      on a slide. This page is served outside the FPP web root, so it keeps
      working with the FPP UI password switched on &mdash; that is what the
      plugin installs an Apache config for.
    </p>
  </div>

<?php if (at_setting('AudienceTextLogo') !== '' && at_logo_file() === null): ?>
  <div class="callout callout-warning" style="margin-bottom:1rem">
    <strong>The chosen logo is missing.</strong> A logo is set in the settings
    below, but there is no readable image by that name in the FPP image library.
    The audience page is leaving the space empty. Re-upload it under
    <em>Content Setup &rarr; File Manager &rarr; Images</em>, or clear the setting.
  </div>
<?php endif; ?>

<?php if ($geom !== null && ($sizeWarning || $slowWarning || $tooBigWarning)): ?>
  <div class="callout callout-warning" style="margin-bottom:1rem">
    <h4 style="margin-top:0">Check the size and speed</h4>
    <p style="margin-bottom:.5rem">
      <code><?= htmlspecialchars($model, ENT_QUOTES, 'UTF-8') ?></code> is
      <strong><?= $geom['w'] ?>&times;<?= $geom['h'] ?></strong> pixels.
      Text is drawn at <strong><?= $fontSize ?>px</strong> tall, and the longest
      allowed message takes about <strong><?= $crossSeconds ?>s</strong> to cross
      at <?= $scrollSpeed ?> px/sec.
    </p>
    <ul style="margin-bottom:0">
<?php if ($sizeWarning): ?>
      <li><strong>The font size is small for this model.</strong> At
          <?= $fontSize ?>px on a <?= $geom['h'] ?>px tall display the text fills
          about <?= max(1, (int) round($fontSize * 100 / $geom['h'])) ?>% of the
          height. It will render, and light real pixels, and still be unreadable
          from the back of a room. Something around
          <strong><?= (int) round($geom['h'] * 0.5) ?>&ndash;<?= (int) round($geom['h'] * 0.7) ?>px</strong>
          uses the panel properly.</li>
<?php endif; ?>
<?php if ($tooBigWarning): ?>
      <li><strong>The font size is too big for this model.</strong> At
          <?= $fontSize ?>px on a display only <?= $geom['h'] ?>px tall the text
          is rendered at full size and clipped, so the top and bottom of every
          letter is lost. Try about
          <strong><?= max(4, (int) round($geom['h'] * 1.3)) ?>px</strong>, and
          keep messages UPPERCASE &mdash; at this height lowercase descenders
          (g, p, y) either hang off the bottom or force the letters so small
          that nothing is readable.</li>
<?php endif; ?>
<?php if ($slowWarning): ?>
      <li><strong>That is slow.</strong> One message would hold the queue for
          <?= $crossSeconds ?>s, so a queue of ten is over
          <?= (int) round($crossSeconds * 10 / 60) ?> minutes. 40&ndash;60 px/sec
          reads comfortably and keeps the queue moving.</li>
<?php endif; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($model === ''): ?>
  <div class="callout callout-warning" style="margin-bottom:1rem">
    <strong>No model chosen yet.</strong> Pick one below. Until you do, the
    audience page tells guests the screen is not set up rather than accepting
    messages it cannot show.
  </div>
<?php endif; ?>

<?php if (!$workerRunning): ?>
  <div class="callout callout-danger" style="margin-bottom:1rem">
    <strong>The queue worker is not running.</strong> Submissions will be
    accepted and then sit there. Restart fppd, or run
    <code>scripts/postStart.sh</code> from the plugin directory.
  </div>
<?php endif; ?>

<?php
PrintSettingGroup("AudienceTextTarget", "", "", 1, AT_PLUGIN_NAME);
PrintSettingGroup("AudienceTextLimits", "", "", 1, AT_PLUGIN_NAME);
PrintSettingGroup("AudienceTextPresentation", "", "", 1, AT_PLUGIN_NAME);
?>

  <div class="row">
    <div class="col-md-12">
      <fieldset style="margin-top:1.5rem">
        <legend>Queue</legend>

        <div style="margin-bottom:.75rem">
          <button class="buttons btn-outline-success" id="atTestBtn" type="button">
            <i class="fas fa-vial"></i> Send a test message
          </button>
          <select id="atTestStyle" class="form-select-sm" style="width:auto;display:inline-block">
<?php foreach (at_styles() as $id => $style): ?>
            <option value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?>
          </select>
          <button class="buttons btn-outline-warning" id="atSkipBtn" type="button">
            <i class="fas fa-forward"></i> Skip what's on screen
          </button>
          <button class="buttons btn-outline-danger" id="atClearBtn" type="button">
            <i class="fas fa-trash"></i> Clear the waiting queue
          </button>
          <span id="atActionMsg" style="margin-left:.75rem"></span>
        </div>

        <div id="atQueuePanel">Loading&hellip;</div>
      </fieldset>
    </div>
  </div>
</div>

<script>
(function () {
  "use strict";

  var API = "plugin.php?plugin=<?= rawurlencode(AT_PLUGIN_NAME) ?>&page=api/queue.php&nopage=1";

  var panel  = document.getElementById("atQueuePanel");
  var actMsg = document.getElementById("atActionMsg");

  function esc(s) {
    var d = document.createElement("div");
    d.textContent = s === null || s === undefined ? "" : String(s);
    return d.innerHTML;
  }

  function styleLabel(s, id) {
    return (s.styles && s.styles[id]) ? s.styles[id] : (id || "");
  }

  function when(ts) {
    if (!ts) { return ""; }
    return new Date(ts * 1000).toLocaleTimeString();
  }

  function render(s) {
    var html = "";

    html += '<p><strong>On screen now:</strong> ' +
            (s.nowShowing ? esc(s.nowShowing) : '<span class="text-muted">nothing</span>') + "</p>";

    html += "<p><strong>Waiting:</strong> " + s.queueLength + "</p>";

    if (s.pending.length) {
      html += '<table class="fppTable"><thead><tr><th>#</th><th>Message</th><th>Style</th><th>From</th><th>Sent</th></tr></thead><tbody>';
      s.pending.forEach(function (r, i) {
        html += "<tr><td>" + (i + 1) + "</td><td>" + esc(r.message) + "</td><td>" +
                esc(styleLabel(s, r.style)) + "</td><td>" +
                esc(r.submitter_ip) + "</td><td>" + when(r.submitted_at) + "</td></tr>";
      });
      html += "</tbody></table>";
    }

    if (s.recent.length) {
      html += "<h5 style='margin-top:1rem'>Recently shown</h5>";
      html += '<table class="fppTable"><thead><tr><th>Message</th><th>Style</th><th>Result</th><th>From</th><th>Finished</th></tr></thead><tbody>';
      s.recent.forEach(function (r) {
        html += "<tr><td>" + esc(r.message) + "</td><td>" +
                esc(styleLabel(s, r.style)) + "</td><td>" + esc(r.state) +
                (r.error ? ' <span class="text-muted">(' + esc(r.error) + ")</span>" : "") +
                "</td><td>" + esc(r.submitter_ip) + "</td><td>" + when(r.finished_at) + "</td></tr>";
      });
      html += "</tbody></table>";
    }

    panel.innerHTML = html;
  }

  function refresh() {
    fetch(API, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () { panel.textContent = "Could not read the queue."; });
  }

  function post(fields, done) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
    fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        actMsg.textContent = j.ok ? (done || "Done.") : (j.error || "Failed.");
        actMsg.className = j.ok ? "text-success" : "text-danger";
        refresh();
      })
      .catch(function () {
        actMsg.textContent = "Request failed.";
        actMsg.className = "text-danger";
      });
  }

  document.getElementById("atTestBtn").addEventListener("click", function () {
    var text = prompt("Test message to send through the queue:", "Testing the big screen");
    if (text === null) { return; }
    // Styles are only worth judging on the real matrix, so the test lets you
    // name one rather than always sending plain white.
    var style = document.getElementById("atTestStyle").value;
    post({ action: "test", message: text, style: style }, "Test message queued.");
  });

  document.getElementById("atSkipBtn").addEventListener("click", function () {
    post({ action: "skip" }, "Cleared the screen.");
  });

  document.getElementById("atClearBtn").addEventListener("click", function () {
    if (!confirm("Discard every message still waiting?")) { return; }
    post({ action: "clear_queue" }, "Queue cleared.");
  });

  refresh();
  // 3s: this page is open on one operator laptop, not a room full of phones,
  // so it can afford to be livelier than the audience page's poll.
  setInterval(refresh, 3000);
})();
</script>
