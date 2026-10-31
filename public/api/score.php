<?php
// GET /api/score.php?session_id=... — authoritative accuracy for the end screen.
// Only aggregated counts are returned; no correctness per trial is ever exposed.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$sid = (string) ($_GET['session_id'] ?? '');
if (!preg_match('/^[0-9a-f]{24}$/', $sid)) json_out(['ok' => false, 'error' => 'session_id'], 400);

try {
    $db = db_research();
    $st = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(correct),0) AS correct
                        FROM trials WHERE session_id = ? AND round IN ('1','2')");
    $st->execute([$sid]);
    $r = $st->fetch();
    json_out(['ok' => true, 'total' => (int) $r['total'], 'correct' => (int) $r['correct']]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
