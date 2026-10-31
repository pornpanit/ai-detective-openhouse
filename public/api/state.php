<?php
// GET /api/state.php?session_id=... — resume support.
// Returns the session's saved progress (per-round answered trial indices) and
// its trial plan, so a reload can continue exactly where the player stopped.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$sid = (string) ($_GET['session_id'] ?? '');
if (!preg_match('/^[0-9a-f]{24}$/', $sid)) json_out(['ok' => false, 'error' => 'session_id'], 400);

try {
    $db = db_research();
    $st = $db->prepare("SELECT completed, plan FROM sessions WHERE session_id = ?");
    $st->execute([$sid]);
    $row = $st->fetch();
    if (!$row) json_out(['ok' => true, 'exists' => false]);

    $saved = ['practice' => [], 'r1' => [], 'r2' => []];
    $st = $db->prepare("SELECT round, trial_index FROM trials WHERE session_id = ?");
    $st->execute([$sid]);
    foreach ($st->fetchAll() as $t) {
        $key = $t['round'] === 'practice' ? 'practice' : ($t['round'] === '1' ? 'r1' : 'r2');
        $saved[$key][] = (int) $t['trial_index'];
    }
    json_out(['ok' => true, 'exists' => true, 'completed' => (int) $row['completed'] === 1,
              'plan' => json_decode($row['plan'], true), 'saved' => $saved]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
