<?php
/**
 * The audience-facing page. Anonymous, no FPP chrome, no login.
 *
 * Everything -- CSS, JS, fonts -- is inline and self-hosted. Phones joined to
 * the FPP tether have no route to the internet, so a CDN stylesheet or a web
 * font is not "a slow load", it is a page that never finishes rendering while
 * someone stands there holding it up in front of a room.
 */
require_once dirname(__DIR__) . '/lib/common.php';

$settings = at_settings();
$maxLength = at_setting_int('AudienceTextMaxLength');
$allowColor = at_setting_bool('AudienceTextAllowColor');
$styles = at_offered_styles();
$logo = at_logo_file();
$logoHeight = at_setting_int('AudienceTextLogoHeight');
$status = at_queue_status();

/** The CSS background for a style button: one colour, or a slice of its palette. */
function at_swatch_css($style)
{
    if (empty($style['multi'])) {
        return $style['color'];
    }
    $stops = $style['swatch'];
    // A hard-edged conic for the multi styles: a smooth gradient of five
    // colours on a 2.6rem circle just averages out to grey-brown, which is a
    // dishonest preview of something that will hit the matrix as five distinct
    // colours.
    $n = count($stops);
    $parts = array();
    foreach (array_values($stops) as $i => $hex) {
        $from = round($i * 100 / $n, 2);
        $to = round(($i + 1) * 100 / $n, 2);
        $parts[] = $hex . ' ' . $from . '% ' . $to . '%';
    }
    return 'linear-gradient(135deg, ' . implode(', ', $parts) . ')';
}

function h($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h(at_setting('AudienceTextHeading')) ?></title>
<style>
  :root {
    --bg: #0b0d12;
    --panel: #161a23;
    --panel-2: #1e2430;
    --line: #2b3342;
    --text: #eef1f6;
    --muted: #9aa4b6;
    --accent: #4da3ff;
    --ok: #35c07a;
    --bad: #ff6b6b;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    background: var(--bg);
    color: var(--text);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    /* No web font: see the file header. system-ui is whatever the phone
       already has, which is the only font guaranteed to exist offline. */
    -webkit-text-size-adjust: 100%;
  }
  .wrap {
    max-width: 34rem;
    margin: 0 auto;
    padding: 1.5rem 1rem 3rem;
  }
  .logo {
    display: block;
    margin: 0 auto 1.5rem;
    /* A CAP on both axes, with the aspect ratio left to decide the rest --
       max-height is set inline from the operator's setting.

       This was a fixed `height` plus object-fit: contain, which looked right
       for a square logo and shrank every wide one. A banner logo would hit
       max-width first, and object-fit then letterboxed the picture inside a box
       that was still the full configured height, so it drew visibly smaller
       than the setting asked for with dead space above and below it. Letting
       width and height both be auto means a wide logo now uses the whole page
       width, and a tall one uses the whole allowance. */
    max-width: 100%;
    width: auto;
    height: auto;
  }
  h1 {
    font-size: 1.5rem;
    line-height: 1.25;
    margin: 0 0 .4rem;
  }
  .blurb {
    color: var(--muted);
    font-size: .95rem;
    margin: 0 0 1.5rem;
    line-height: 1.45;
  }
  .card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 1rem;
    margin-bottom: 1rem;
  }
  label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: .5rem; }
  textarea {
    width: 100%;
    background: var(--panel-2);
    border: 1px solid var(--line);
    border-radius: 10px;
    color: var(--text);
    font: inherit;
    font-size: 1.15rem;
    padding: .8rem;
    resize: none;
    min-height: 4.5rem;
  }
  textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
  .counter { text-align: right; font-size: .8rem; color: var(--muted); margin-top: .4rem; }
  .counter.over { color: var(--bad); }
  .swatches { display: flex; flex-wrap: wrap; gap: .55rem; margin-top: .25rem; }
  .swatch {
    width: 2.6rem; height: 2.6rem;
    border-radius: 50%;
    border: 3px solid transparent;
    box-shadow: 0 0 0 1px var(--line);
    cursor: pointer;
    padding: 0;
  }
  .swatch[aria-pressed="true"] { border-color: var(--text); }
  .swatch-group + .swatch-group { margin-top: .8rem; }
  .swatch-group-label {
    font-size: .75rem;
    color: var(--muted);
    margin: 0 0 .4rem;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .style-name {
    font-size: .8rem;
    color: var(--muted);
    margin-top: .5rem;
    min-height: 1.1em;
  }
  button.send {
    width: 100%;
    margin-top: 1rem;
    padding: 1rem;
    font-size: 1.1rem;
    font-weight: 600;
    border: 0;
    border-radius: 12px;
    background: var(--accent);
    color: #05101f;
    cursor: pointer;
  }
  button.send:disabled { opacity: .5; cursor: default; }
  .msg { margin-top: .9rem; font-size: .95rem; line-height: 1.4; min-height: 1.4em; }
  .msg.ok { color: var(--ok); }
  .msg.bad { color: var(--bad); }
  .queue { font-size: .9rem; color: var(--muted); line-height: 1.5; }
  .queue strong { color: var(--text); font-weight: 600; }
  .now { color: var(--text); word-break: break-word; }
  .closed {
    border-color: #6b4a00;
    background: #241c07;
    color: #ffd98a;
  }
  @media (prefers-reduced-motion: no-preference) {
    .pulse { animation: pulse 1.6s ease-in-out infinite; }
    @keyframes pulse { 50% { opacity: .55; } }
  }
</style>
</head>
<body>
<div class="wrap">
<?php if ($logo !== null): ?>
  <img class="logo" src="logo.php" alt=""
       style="max-height: <?= (int) $logoHeight ?>px"
       onerror="this.remove()">
<?php endif; ?>
  <h1><?= h(at_setting('AudienceTextHeading')) ?></h1>
  <p class="blurb"><?= h(at_setting('AudienceTextBlurb')) ?></p>

<?php if (!$status['configured']): ?>
  <div class="card closed">
    This screen isn't set up yet. Grab whoever is running the lights.
  </div>
<?php else: ?>

  <div class="card" id="form-card">
    <label for="message">Your message</label>
    <textarea id="message" maxlength="<?= (int) $maxLength ?>" autocomplete="off"
              autocapitalize="sentences" enterkeyhint="send"
              placeholder="Hello from row 3"></textarea>
    <div class="counter" id="counter">0 / <?= (int) $maxLength ?></div>

<?php if ($allowColor && !empty($styles)): ?>
    <label style="margin-top:.9rem">Colour</label>
    <div id="swatches">
<?php
    $groups = array('' => array(), 'multi' => array());
    foreach ($styles as $id => $style) {
        $groups[$style['multi'] ? 'multi' : ''][$id] = $style;
    }
    $first = true;
    foreach ($groups as $key => $group):
        if (empty($group)) { continue; }
?>
      <div class="swatch-group">
<?php if ($key === 'multi'): ?>
        <p class="swatch-group-label">Multi-colour</p>
<?php endif; ?>
        <div class="swatches">
<?php foreach ($group as $id => $style): ?>
          <button type="button" class="swatch" data-style="<?= h($id) ?>"
                  data-label="<?= h($style['label']) ?>"
                  aria-pressed="<?= $first ? 'true' : 'false' ?>"
                  aria-label="<?= h($style['label']) ?>" title="<?= h($style['label']) ?>"
                  style="background: <?= h(at_swatch_css($style)) ?>"></button>
<?php $first = false; endforeach; ?>
        </div>
      </div>
<?php endforeach; ?>
    </div>
    <div class="style-name" id="styleName"></div>
<?php endif; ?>

    <button class="send" id="send">Send it to the screen</button>
    <div class="msg" id="msg" role="status" aria-live="polite"></div>
  </div>

  <div class="card queue" id="queue-card">
    <div id="queue-line">Loading…</div>
  </div>

<?php endif; ?>
</div>

<script>
(function () {
  "use strict";

  var MAX = <?= (int) $maxLength ?>;

  var message  = document.getElementById("message");
  var counter  = document.getElementById("counter");
  var sendBtn  = document.getElementById("send");
  var msgEl    = document.getElementById("msg");
  var queueEl  = document.getElementById("queue-line");
  var swatches = document.getElementById("swatches");
  var styleName = document.getElementById("styleName");

  if (!message) { return; }   // not-configured state: nothing to wire up

  // The id of a style from at_styles(), never a colour. The server resolves it
  // and is the only thing that decides what actually reaches fppd.
  var style = swatches ? swatches.querySelector(".swatch").dataset.style : "white";

  // The id of this phone's most recent submission, so the queue panel can say
  // "you are 3rd" rather than only "3 waiting". sessionStorage, not
  // localStorage: it is meaningless once the tab is gone, and nobody wants
  // yesterday's conference still tracked on their phone.
  var myId = null;
  try { myId = sessionStorage.getItem("at_id"); } catch (e) { /* private mode */ }

  function setMsg(text, cls) {
    msgEl.textContent = text;
    msgEl.className = "msg" + (cls ? " " + cls : "");
  }

  function updateCounter() {
    var n = message.value.length;
    counter.textContent = n + " / " + MAX;
    counter.classList.toggle("over", n >= MAX);
  }
  message.addEventListener("input", updateCounter);
  updateCounter();

  function selectSwatch(btn) {
    style = btn.dataset.style;
    // querySelectorAll, not children: the buttons are nested one level down
    // inside their solid/multi-colour groups, so walking children would only
    // ever find the group wrappers and no selection would ever clear.
    swatches.querySelectorAll(".swatch").forEach(function (b) {
      b.setAttribute("aria-pressed", String(b === btn));
    });
    if (styleName) { styleName.textContent = btn.dataset.label; }
  }

  if (swatches) {
    swatches.addEventListener("click", function (ev) {
      var btn = ev.target.closest(".swatch");
      if (btn) { selectSwatch(btn); }
    });
    // Name the one that starts selected, so the label is never blank.
    selectSwatch(swatches.querySelector(".swatch"));
  }

  function describe(status) {
    var parts = [];
    if (!status.enabled) {
      return "Submissions are closed right now.";
    }
    if (status.nowShowing) {
      parts.push('On screen now: <span class="now">' + escapeHtml(status.nowShowing) + "</span>");
    } else {
      parts.push("Nothing on screen right now.");
    }

    var yours = status.yours;
    if (yours && yours.state === "showing") {
      parts.push("<strong>That's yours, right now.</strong>");
    } else if (yours && yours.state === "pending") {
      parts.push(yours.position === 0
        ? "<strong>You're next.</strong>"
        : "<strong>You're " + ordinal(yours.position + 1) + " in the queue.</strong>");
    } else if (yours && yours.state === "done") {
      parts.push("Your message has been shown. Send another whenever you like.");
    } else if (yours && (yours.state === "failed" || yours.state === "skipped")) {
      parts.push("Your message didn't make it to the screen. Sorry - try again.");
    } else {
      parts.push(status.queueLength === 0
        ? "Nothing waiting."
        : status.queueLength + (status.queueLength === 1 ? " message waiting." : " messages waiting."));
    }
    return parts.join("<br>");
  }

  function ordinal(n) {
    var s = ["th", "st", "nd", "rd"];
    var v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  }

  function escapeHtml(s) {
    var d = document.createElement("div");
    d.textContent = s;
    return d.innerHTML;
  }

  var pollTimer = null;
  function poll() {
    var url = "status.php" + (myId ? "?id=" + encodeURIComponent(myId) : "");
    fetch(url, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (status) {
        queueEl.innerHTML = describe(status);
        sendBtn.disabled = !status.enabled;
      })
      .catch(function () {
        queueEl.textContent = "Lost touch with the screen. Still connected to the wifi?";
      });
  }

  // 4s, not 1s: this is polled by every phone in the room at once, and the
  // queue simply does not change fast enough to be worth four times the load.
  function startPolling() {
    poll();
    if (pollTimer) { clearInterval(pollTimer); }
    pollTimer = setInterval(poll, 4000);
  }

  // Stop polling while the page is backgrounded. A pocketed phone that keeps
  // hitting status.php is pure load on the Pi for a screen nobody is looking at.
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      clearInterval(pollTimer);
      pollTimer = null;
    } else {
      startPolling();
    }
  });

  function submit() {
    var text = message.value.trim();
    if (!text) {
      setMsg("Type something first.", "bad");
      message.focus();
      return;
    }
    sendBtn.disabled = true;
    setMsg("Sending…", "");

    var body = new URLSearchParams();
    body.set("message", text);
    body.set("style", style);

    fetch("submit.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
      cache: "no-store"
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.body.ok) {
          setMsg(res.body.error || "Couldn't send that. Try again.", "bad");
          return;
        }
        myId = String(res.body.id);
        try { sessionStorage.setItem("at_id", myId); } catch (e) { /* private mode */ }
        message.value = "";
        updateCounter();
        setMsg("Sent. Watch the screen.", "ok");
        poll();
      })
      .catch(function () {
        setMsg("Couldn't reach the screen. Still connected to the wifi?", "bad");
      })
      .then(function () {
        // Re-enabled here rather than in each branch above so a thrown JSON
        // parse can never leave the button dead for the rest of the session.
        sendBtn.disabled = false;
      });
  }

  sendBtn.addEventListener("click", submit);
  message.addEventListener("keydown", function (ev) {
    if (ev.key === "Enter" && !ev.shiftKey) {
      ev.preventDefault();
      submit();
    }
  });

  startPolling();
})();
</script>
</body>
</html>
