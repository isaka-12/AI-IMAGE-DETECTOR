<?php
declare(strict_types=1);

require __DIR__ . '/../app/Sightengine.php';

session_start();
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
// cache-busting: browsers refetch the assets whenever they change
$v = static fn(string $f): string => $f . '?v=' . filemtime(__DIR__ . '/' . $f);
$pixelReady = Sightengine::configured();
?><!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <title>AI Image Scanner</title>
  <link rel="stylesheet" href="<?= $v('style.css') ?>">
  <script src="<?= $v('app.js') ?>" defer></script>
</head>

<body>
  <div class="shell">
    <?php $page = 'scan';
    require __DIR__ . '/../app/views/sidebar.php'; ?>

    <div class="main">
      <header class="topbar">
        <div class="topbar-title">
          <a class="mark compact" href="#scan" aria-label="AI Image Scanner">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V7l-8-4Z" /><path d="m9 12 2 2 4-4" /></svg>
          </a>
          <div>
            <span class="eyebrow">Dashboard</span>
            <span class="counter"><b id="t-total">&ndash;</b> images scanned</span>
          </div>
        </div>
        <div class="topbar-actions">
          <a class="btn mobile-only" href="docs.php">Docs</a>
          <button class="btn primary" type="button" data-action="upload">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" /></svg>
            Upload image
          </button>
        </div>
      </header>

      <main>
        <noscript>
          <p class="alert">This page needs JavaScript enabled.</p>
        </noscript>
        <p id="error" class="alert" role="alert" hidden></p>

        <!-- Upload + result -->
        <section id="scan" class="hero">
          <div class="card uploader">
            <h1>Check an image for AI</h1>

            <button id="dropzone" class="dropzone" type="button" data-action="upload">
              <!-- empty: invitation to upload -->
              <span id="drop-empty" class="drop-empty">
                <span class="drop-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" /></svg>
                </span>
                <span class="drop-title"><span class="accent">Click to upload</span> or drag &amp; drop</span>
                <span class="drop-hint">JPEG, PNG or WebP &middot; up to 10 MB</span>
              </span>
              <!-- filled: the uploaded image stays here -->
              <span id="drop-filled" class="drop-filled" hidden>
                <img id="drop-img" alt="">
                <span class="drop-bar">
                  <span class="drop-file">
                    <strong id="drop-name"></strong>
                    <span id="drop-size"></span>
                  </span>
                  <span class="drop-change">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" /></svg>
                    Replace image
                  </span>
                </span>
                <span id="drop-busy" class="drop-busy" hidden>Scanning&hellip;</span>
              </span>
            </button>

            <label class="toggle<?= $pixelReady ? '' : ' disabled' ?>">
              <input id="pixel" type="checkbox"<?= $pixelReady ? '' : ' disabled' ?>>
              <span class="switch" aria-hidden="true"></span>
              <span class="toggle-text">
                <strong>Pixel analysis (Sightengine)</strong>
                <small><?= $pixelReady ? 'Sends the image to Sightengine.' : 'Not set up. See Docs.' ?></small>
              </span>
            </label>
          </div>

          <div id="current" class="card result-card" aria-live="polite">
            <div id="idle" class="idle">
              <div id="idle-gauge"></div>
              <h2>Ready</h2>
              <p class="muted">Upload an image to see the result.</p>
            </div>

            <div id="skeleton" class="loading" role="status" hidden>
              <span class="spinner" aria-hidden="true"></span>
              <h2>Analyzing image</h2>
              <ul class="steps">
                <li>Reading metadata</li>
                <li>Checking generator settings</li>
                <li id="step-pixel" hidden>Asking Sightengine</li>
              </ul>
            </div>

            <div id="result" hidden>
              <div class="file-row">
                <div class="file-meta">
                  <strong id="fname"></strong>
                  <span id="r-file" class="muted"></span>
                </div>
                <div id="badge"></div>
              </div>

              <div id="r-gauge"></div>
              <h2 id="verdict" tabindex="-1"></h2>

              <h3 class="section-label">Evidence</h3>
              <ul id="r-evidence" class="evidence"></ul>

              <details class="more meta" open>
                <summary>Metadata <span id="r-meta-count" class="count"></span></summary>
                <div id="r-meta" class="meta-groups"></div>
              </details>

              <div class="synthid">
                <div>
                  <strong>Google SynthID</strong>
                  <small>No API. Ask Gemini: &ldquo;Was this created with Google AI?&rdquo;</small>
                </div>
                <a class="btn" href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer">
                  Check in Gemini
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
                </a>
              </div>

              <button class="btn wide" type="button" data-action="upload">Check another image</button>
            </div>
          </div>
        </section>

        <!-- Statistics -->
        <section id="stats" class="stats-grid" aria-labelledby="stats-title">
          <div class="card breakdown">
            <div class="card-head">
              <h2 id="stats-title">Results breakdown</h2>
              <span id="s-today" class="chip"></span>
            </div>
            <div class="breakdown-body">
              <div class="donut-wrap">
                <div id="donut"></div>
                <div class="donut-center">
                  <b id="s-total">&ndash;</b>
                  <span>scans</span>
                </div>
              </div>
              <ul id="legend" class="legend"></ul>
            </div>
          </div>

          <div class="stat-cards">
            <div class="card stat">
              <div>
                <span class="stat-label">AI declared</span>
                <b id="s-ai" class="stat-value">&ndash;</b>
              </div>
              <div class="mini" id="m-ai"></div>
            </div>
            <div class="card stat">
              <div>
                <span class="stat-label">Likely AI</span>
                <b id="s-likely" class="stat-value">&ndash;</b>
              </div>
              <div class="mini" id="m-likely"></div>
            </div>
            <div class="card stat">
              <div>
                <span class="stat-label">Real / camera</span>
                <b id="s-camera" class="stat-value">&ndash;</b>
              </div>
              <div class="mini" id="m-camera"></div>
            </div>
            <div class="card stat">
              <div>
                <span class="stat-label">Inconclusive</span>
                <b id="s-inconclusive" class="stat-value">&ndash;</b>
              </div>
              <div class="mini" id="m-inconclusive"></div>
            </div>
          </div>
        </section>

        <!-- History -->
        <section id="history" class="card" aria-labelledby="history-title">
          <div class="card-head">
            <div>
              <h2 id="history-title">Recent scans</h2>
            </div>
            <div id="clear-box" class="clear-box" hidden>
              <button id="clear" class="btn danger-ghost" type="button">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>
                Clear all
              </button>
              <div id="clear-confirm" class="clear-confirm" hidden>
                <span id="clear-question"></span>
                <button id="clear-yes" class="btn danger" type="button">Delete</button>
                <button id="clear-no" class="btn" type="button">Cancel</button>
              </div>
            </div>
          </div>

          <div id="filters" class="tabs" role="group" aria-label="Filter scans"></div>
          <p id="status" class="status" role="status" hidden></p>
          <p id="history-error" class="alert" hidden></p>

          <div id="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Image</th>
                  <th scope="col">Result</th>
                  <th scope="col">AI score</th>
                  <th scope="col">Evidence</th>
                  <th scope="col">When</th>
                  <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody id="history-body"></tbody>
            </table>
            <p id="filter-empty" class="empty" hidden>No scans in this category.</p>
          </div>

          <div id="empty" class="empty" hidden>
            <p>No scans yet.</p>
            <button class="btn primary" type="button" data-action="upload">Upload your first image</button>
          </div>
        </section>

        <p class="footnote">Results are evidence, not proof. <a href="docs.php#limits">How to read them</a></p>
      </main>
    </div>
  </div>

  <input id="file" type="file" accept="image/jpeg,image/png,image/webp" hidden>
  <div id="dropmask" class="dropmask" hidden>
    <div>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" /></svg>
      Drop the image to scan it
    </div>
  </div>
</body>

</html>
