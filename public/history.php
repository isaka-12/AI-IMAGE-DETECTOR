<?php
declare(strict_types=1);

require __DIR__ . '/../app/Http.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(405, ['ok' => false, 'error' => 'GET only.']);
}

// Only needed to recognise the visitor's own scans; read and release the lock at once
session_start(['read_and_close' => true]);
$owner = owner_tag();

try {
    $pdo = db();
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total,
                SUM(level = 'ai') AS ai,
                SUM(level = 'likely') AS likely,
                SUM(level IN ('camera', 'real')) AS camera,
                SUM(level IN ('hint', 'none', 'legacy')) AS inconclusive,
                SUM(created_at >= NOW() - INTERVAL 1 DAY) AS today
         FROM scans"
    )->fetch(PDO::FETCH_ASSOC);
    $rows = $pdo->query(
        'SELECT id, created_at, mime, bytes, width, height, level, score, score_source, verdict, signals, sha256, filename, owner
         FROM scans ORDER BY id DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE owner = ?');
    $st->execute([$owner]);
    $stats['mine'] = $owner === '' ? 0 : (int) $st->fetchColumn();
} catch (Throwable $e) {
    error_log('history failed: ' . $e->getMessage());
    send_json(503, ['ok' => false, 'error' => 'Scan history is unavailable right now.']);
}

$scans = array_map(function (array $r) use ($owner): array {
    $mine = $owner !== '' && $r['owner'] === $owner;
    $signals = json_decode((string) $r['signals'], true);
    return [
        'id'         => (int) $r['id'],
        'created_at' => str_replace(' ', 'T', (string) $r['created_at']) . 'Z',
        'mime'       => $r['mime'],
        'bytes'      => (int) $r['bytes'],
        'width'      => $r['width'] === null ? null : (int) $r['width'],
        'height'     => $r['height'] === null ? null : (int) $r['height'],
        'level'      => $r['level'],
        'score'      => $r['score'] === null ? null : (int) $r['score'],
        'score_source' => $r['score_source'] ?? 'rules',
        'verdict'    => $r['verdict'],
        'signals'    => is_array($signals) ? $signals : [],
        'sha256'     => $r['sha256'],
        // personal tool: every scan in the history is yours, so names are always shown
        'filename'   => $r['filename'],
        'mine'       => $mine,
    ];
}, $rows);

send_json(200, [
    'ok'    => true,
    'stats' => array_map('intval', $stats),
    'scans' => $scans,
]);
