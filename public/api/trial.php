<?php
// POST /api/trial.php — save one trial, idempotent per (session_id, round, trial_index).
// Correctness is computed SERVER-SIDE from the stored session plan; the client
// never declares its own correctness.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

$in = read_json_body();
$sid = (string) ($in['session_id'] ?? '');
$round = in_array($in['round'] ?? '', ['practice', '1', '2'], true) ? $in['round'] : json_out(['ok' => false, 'error' => 'round'], 400);
$idx = (int) ($in['trial_index'] ?? 0);
$imageId = (string) ($in['image_id'] ?? '');
$rt = (int) ($in['rt_ms'] ?? -1);
if (!preg_match('/^[0-9a-f]{24}$/', $sid)) json_out(['ok' => false, 'error' => 'session_id'], 400);
if ($idx < 1 || $idx > 24) json_out(['ok' => false, 'error' => 'trial_index'], 400);
if ($rt < 0 || $rt > 600000) json_out(['ok' => false, 'error' => 'rt_ms'], 400);

try {
    $db = db_research();
    $st = $db->prepare("SELECT plan FROM sessions WHERE session_id = ?");
    $st->execute([$sid]);
    $planRaw = $st->fetchColumn();
    if (!is_string($planRaw)) json_out(['ok' => false, 'error' => 'no_session'], 404);
    $plan = json_decode($planRaw, true);

    // ---- resolve truth from the plan ----
    if ($round === 'practice') {
        $items = $plan['practice'];
        $item = $items[$idx - 1] ?? null;
        if ($item === null) json_out(['ok' => false, 'error' => 'no_trial'], 400);
        if (isset($item['pair'])) {
            $p = $item['pair'];
            $isAi = ($p['ai']['id'] === $imageId);
            $type = $isAi ? 'gan' : 'real';                          // tapped face's type
            $response = $isAi ? 1 : 0;
            $correct = $isAi ? 1 : 0;
            $pair = ($isAi ? $p['real']['id'] : $p['ai']['id']);
        } else {
            $s = $item['single'];
            $response = (int) ($in['response'] ?? -1);
            if ($response !== 0 && $response !== 1) json_out(['ok' => false, 'error' => 'response'], 400);
            $type = $s['type'];
            $correct = (($type !== 'real') === ($response === 1)) ? 1 : 0;
            $pair = null;
            $imageId = $s['id'];
        }
    } elseif ($round === '1') {
        $item = $plan['round1'][$idx - 1] ?? null;
        if ($item === null) json_out(['ok' => false, 'error' => 'no_trial'], 400);
        $p = $item;
        $isAi = ($p['ai']['id'] === $imageId);
        $isReal = ($p['real']['id'] === $imageId);
        if (!$isAi && !$isReal) json_out(['ok' => false, 'error' => 'image_not_in_trial'], 400);
        $type = $isAi ? $p['ai']['type'] : 'real';
        $correct = $isAi ? 1 : 0;                                          // must tap the AI face
        $pair = $isAi ? $p['real']['id'] : $p['ai']['id'];
        $response = $isAi ? 1 : 0;
    } else {
        $item = $plan['round2'][$idx - 1] ?? null;
        if ($item === null) json_out(['ok' => false, 'error' => 'no_trial'], 400);
        $response = (int) ($in['response'] ?? -1);
        if ($response !== 0 && $response !== 1) json_out(['ok' => false, 'error' => 'response'], 400);
        $type = $item['type'];
        $correct = (($type !== 'real') === ($response === 1)) ? 1 : 0;
        $pair = null;
        $imageId = $item['id'];
    }

    $ins = $db->prepare(
        "INSERT INTO trials (session_id, round, trial_index, image_id, pair_image_id, image_type,
                             response, correct, rt_ms, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
           image_id=VALUES(image_id), pair_image_id=VALUES(pair_image_id), image_type=VALUES(image_type),
           response=VALUES(response), correct=VALUES(correct), rt_ms=VALUES(rt_ms)");
    $ins->execute([$sid, $round, $idx, $imageId, $pair, $type, $response, $correct, $rt]);
    json_out(['ok' => true, 'correct' => null]);   // correctness NEVER revealed to client
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
