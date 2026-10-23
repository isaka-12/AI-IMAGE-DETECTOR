<?php
declare(strict_types=1);

require __DIR__ . '/../app/Detector.php';
require __DIR__ . '/../app/Http.php';
require __DIR__ . '/../app/Sightengine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['ok' => false, 'error' => 'POST only.']);
}

session_start();
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    send_json(403, ['ok' => false, 'error' => 'Invalid token. Reload the page and try again.']);
}

if (
    !isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK
    || !is_uploaded_file($_FILES['image']['tmp_name'])
) {
    send_json(400, ['ok' => false, 'error' => 'Upload failed. Pick a JPEG, PNG or WebP under 10 MB.']);
}

// Opt-in per scan: the pixel check sends the image to Sightengine
$pixel = ($_POST['pixel'] ?? '') === '1' && Sightengine::configured()
    ? Sightengine::check(...)
    : null;

// Analyze straight from PHP's temp file; the image is never moved or stored here.
$r = Detector::analyze($_FILES['image']['tmp_name'], $pixel);
if (!$r['ok']) {
    send_json(422, $r);
}

/** Client-supplied file name: display only, so strip paths and control chars and cap it at 120 characters */
function clean_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
    if (!preg_match('//u', $name)) {    // not valid UTF-8: keep the printable ASCII part
        $name = (string) preg_replace('/[^\x20-\x7E]+/', '', $name);
    }
    preg_match('/^.{0,120}/us', $name, $m);
    return $m[0] ?? '';
}

try {
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO scans (sha256, mime, bytes, width, height, level, score, score_source, verdict, signals, filename, owner)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $r['sha256'], $r['mime'], $r['bytes'], $r['width'], $r['height'],
        $r['level'], $r['score'], $r['score_source'], $r['verdict'],
        json_encode($r['signals'], JSON_INVALID_UTF8_SUBSTITUTE),
        clean_name((string) $_FILES['image']['name']) ?: null,
        owner_tag(),
    ]);
    // lets the browser match its locally kept thumbnail to this history row
    $r['id'] = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('scan log failed: ' . $e->getMessage()); // result still returned
}

send_json(200, $r);
