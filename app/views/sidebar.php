<?php
declare(strict_types=1);

/**
 * Sidebar shared by index.php and docs.php.
 * Expects $page = 'scan' | 'docs' (which link is highlighted).
 */
$home = $page === 'docs' ? './' : '';
$cls = static fn(bool $on): string => $on ? ' class="active" aria-current="page"' : '';
?>
<aside class="sidebar">
  <a class="brand" href="<?= $home ?>#scan">
    <span class="mark" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V7l-8-4Z" /><path d="m9 12 2 2 4-4" /></svg>
    </span>
    <span>AI Image Scanner</span>
  </a>

  <nav aria-label="Sections">
    <a href="<?= $home ?>#scan"<?= $cls($page === 'scan') ?>>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M4 12h16" /></svg>
      Scan
    </a>
    <a href="<?= $home ?>#stats">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9h-9V3Z" /><path d="M15 3.5A9 9 0 0 1 20.5 9H15V3.5Z" /></svg>
      Statistics
    </a>
    <a href="<?= $home ?>#history">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5M12 7v5l3 2" /></svg>
      History
    </a>
    <a href="docs.php"<?= $cls($page === 'docs') ?>>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" /><path d="M14 3v5h5M9 13h6M9 17h6" /></svg>
      Docs
    </a>
  </nav>

  <div class="side-note">
    <strong>How it works</strong>
    <p>Reads C2PA, IPTC, XMP and EXIF metadata, with optional pixel analysis.</p>
    <a class="btn side-btn" href="docs.php">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" /><path d="M14 3v5h5M9 13h6M9 17h6" /></svg>
      Read the docs
    </a>
  </div>
</aside>
