<?php
declare(strict_types=1);

require __DIR__ . '/../app/Http.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['ok' => false, 'error' => 'POST only.']);
}

session_start(['read_and_close' => true]);
$token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    send_json(403, ['ok' => false, 'error' => 'Invalid token. Reload the page and try again.']);
}

// Personal tool: the history belongs to whoever runs it, so any scan can be
// removed. Either one scan (id=123) or everything (all=1).
$id  = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$all = ($_POST['all'] ?? '') === '1';
if ($id === false && !$all) {
    send_json(400, ['ok' => false, 'error' => 'Say which scan to delete.']);
}

try {
    if ($all) {
        $deleted = db()->exec('DELETE FROM scans');
    } else {
        $st = db()->prepare('DELETE FROM scans WHERE id = ?');
        $st->execute([$id]);
        $deleted = $st->rowCount();
    }
} catch (Throwable $e) {
    error_log('delete failed: ' . $e->getMessage());
    send_json(503, ['ok' => false, 'error' => 'Could not delete right now.']);
}

if (!$all && $deleted === 0) {
    send_json(404, ['ok' => false, 'error' => 'That scan no longer exists.']);
}
send_json(200, ['ok' => true, 'deleted' => (int) $deleted]);
