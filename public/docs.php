<?php
declare(strict_types=1);

require __DIR__ . '/../app/Sightengine.php';

// cache-busting: browsers refetch the stylesheet whenever it changes
$v = static fn(string $f): string => $f . '?v=' . filemtime(__DIR__ . '/' . $f);
$pixelReady = Sightengine::configured();
?><!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Docs · AI Image Scanner</title>
  <link rel="stylesheet" href="<?= $v('style.css') ?>">
</head>

<body>
  <div class="shell">
    <?php $page = 'docs';
    require __DIR__ . '/../app/views/sidebar.php'; ?>

    <div class="main">
      <header class="topbar">
        <div class="topbar-title">
          <a class="mark compact" href="./" aria-label="AI Image Scanner">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V7l-8-4Z" /><path d="m9 12 2 2 4-4" /></svg>
          </a>
          <div>
            <span class="eyebrow">Docs</span>
            <span class="counter">How results are reached</span>
          </div>
        </div>
        <a class="btn primary" href="./#scan">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5m6 6-6-6 6-6" /></svg>
          Back to scanner
        </a>
      </header>

      <main class="docs-layout">
        <nav class="card toc" aria-label="On this page">
          <strong>On this page</strong>
          <ol>
            <li><a href="#use">Using it</a></li>
            <li><a href="#checks">What is checked</a></li>
            <li><a href="#scores">Results and scores</a></li>
            <li><a href="#pixel">Pixel analysis</a></li>
            <li><a href="#synthid">Google SynthID</a></li>
            <li><a href="#history">History</a></li>
            <li><a href="#limits">Limits</a></li>
            <li><a href="#setup">Setup</a></li>
          </ol>
        </nav>

        <article class="card docs">
          <section id="use">
            <h1>Docs</h1>
            <ol>
              <li>Upload or drop a JPEG, PNG or WebP image, up to 10&nbsp;MB.</li>
              <li>Optional: turn on <strong>Pixel analysis</strong> first.</li>
              <li>Read the result, the <strong>Evidence</strong> list and the <strong>Metadata</strong> panel, which
                shows every field that was read.</li>
            </ol>
          </section>

          <section id="checks">
            <h2>What is checked</h2>
            <p>Each metadata block is parsed from the file structure. Pixels are never decoded.</p>
            <div class="table-scroll">
              <table class="doc-table">
                <thead>
                  <tr><th scope="col">Evidence</th><th scope="col">Source</th><th scope="col">Strength</th></tr>
                </thead>
                <tbody>
                  <tr><td>Declared AI-generated or AI-edited</td><td>C2PA, XMP <code>DigitalSourceType</code></td><td><span class="tag-doc strong">strong</span></td></tr>
                  <tr><td>Declared AI system or prompt</td><td>XMP <code>AISystemUsed</code>, <code>AIPromptInformation</code></td><td><span class="tag-doc strong">strong</span></td></tr>
                  <tr><td>Generation settings</td><td>PNG text, EXIF comments: AUTOMATIC1111, Forge, ComfyUI, InvokeAI, Fooocus, SwarmUI, NovelAI</td><td><span class="tag-doc strong">strong</span></td></tr>
                  <tr><td>&ldquo;Made with Google AI&rdquo; label</td><td>IPTC, XMP, EXIF</td><td><span class="tag-doc medium">medium</span></td></tr>
                  <tr><td>AI tool named as software, author or credit</td><td>EXIF, IPTC, XMP, C2PA</td><td><span class="tag-doc medium">medium</span></td></tr>
                  <tr><td>Midjourney job ID, &ldquo;algorithmic&rdquo; source</td><td>PNG text, C2PA, XMP</td><td><span class="tag-doc medium">medium</span></td></tr>
                  <tr><td>Typical generator image size</td><td>Header</td><td><span class="tag-doc weak">weak</span></td></tr>
                  <tr><td>Camera make, model and exposure</td><td>EXIF</td><td><span class="tag-doc camera">camera</span></td></tr>
                  <tr><td>Editor used, resized since capture, no metadata</td><td>EXIF, XMP</td><td><span class="tag-doc">info</span></td></tr>
                </tbody>
              </table>
            </div>
            <p>Generator names in captions and descriptions are ignored, so &ldquo;in the style of Midjourney&rdquo;
              does not flag a photo.</p>
          </section>

          <section id="scores">
            <h2>Results and scores</h2>
            <div class="table-scroll">
              <table class="doc-table">
                <thead>
                  <tr><th scope="col">Result</th><th scope="col">When</th><th scope="col">Score</th></tr>
                </thead>
                <tbody>
                  <tr><td><span class="badge ai"><span class="dot"></span>AI declared</span></td><td>Strong evidence</td><td>95%</td></tr>
                  <tr><td><span class="badge likely"><span class="dot"></span>Likely AI</span></td><td>Medium evidence</td><td>75%</td></tr>
                  <tr><td><span class="badge none"><span class="dot"></span>Weak hints</span></td><td>Weak evidence only</td><td>55%</td></tr>
                  <tr><td><span class="badge real"><span class="dot"></span>Camera data</span></td><td>Camera EXIF, no AI evidence</td><td>15%</td></tr>
                  <tr><td><span class="badge none"><span class="dot"></span>Inconclusive</span></td><td>Nothing found</td><td>N/A</td></tr>
                </tbody>
              </table>
            </div>
            <p>These are fixed scores, marked <span class="kbd">RULES</span>. With pixel analysis the score is
              Sightengine's measured probability, marked <span class="kbd">SIGHTENGINE</span>. Declared AI never
              scores below 95%.</p>
          </section>

          <section id="pixel">
            <h2>Pixel analysis</h2>
            <p>Sightengine's model judges the pixels, so it also works on screenshots and stripped files.</p>
            <ul>
              <li>90% and up: very likely AI</li>
              <li>50–89%: possibly AI</li>
              <li>20% and below: looks real</li>
            </ul>
            <p class="callout">The image is sent to Sightengine. Off by default.</p>
          </section>

          <section id="synthid">
            <h2>Google SynthID</h2>
            <p>Google offers no SynthID API, so it is not checked automatically. Use <strong>Check in Gemini</strong>
              on the result and ask &ldquo;Was this created with Google AI?&rdquo;.</p>
          </section>

          <section id="history">
            <h2>History</h2>
            <ul>
              <li>Stored per scan: fingerprint (SHA-256), size, type, result, score, evidence, file name. Never the image.</li>
              <li>Thumbnails stay in your browser.</li>
              <li>Delete one scan with its trash button (click twice), or use <strong>Clear all</strong>.</li>
            </ul>
          </section>

          <section id="limits">
            <h2>Limits</h2>
            <ul>
              <li>Results are evidence, not proof.</li>
              <li>Metadata can be stripped or faked. Screenshots and social media remove it.</li>
              <li>C2PA signatures are not verified.</li>
              <li>Pixel models make mistakes, especially on compressed or new-generator images.</li>
            </ul>
          </section>

          <section id="setup">
            <h2>Setup</h2>
            <p>Pixel analysis reads <code>.env</code>:</p>
            <pre><code>SIGHTENGINE_ENDPOINT=https://api.sightengine.com/1.0/check.json
API_USER=your_api_user
SIGHTENGINE_API_KEY=your_api_secret</code></pre>
            <p>Mount it read-only (keeps keys out of <code>docker inspect</code>) and publish on localhost only:</p>
            <pre><code>chmod 600 .env
docker run -d --name ai-scanner -p 127.0.0.1:8090:8080 \
  -v scanner-db:/var/lib/mysql \
  -v "$PWD/.env:/run/secrets/scanner.env:ro" ai-scanner</code></pre>
            <p class="muted small">Pixel analysis on this server: <strong><?= $pixelReady ? 'set up' : 'not set up' ?></strong>.</p>
          </section>
        </article>
      </main>
    </div>
  </div>
</body>

</html>
