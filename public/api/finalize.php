<?php
// POST /api/finalize.php — end-of-session updates from both exit paths.
//   action=consent : {consent: 0|1}            (natural end OR quit)
//   action=survey  : {f1..f8} 8-item branching  (natural end only)
//   action=finish  : completed=1, finished_at  (natural end)
//   action=quit    : completed=0, finished_at  (quit path)
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

$in = read_json_body();
$sid = (string) ($in['session_id'] ?? '');
$action = (string) ($in['action'] ?? '');
if (!preg_match('/^[0-9a-f]{24}$/', $sid)) json_out(['ok' => false, 'error' => 'session_id'], 400);

try {
    $db = db_research();
    $st = $db->prepare("SELECT consent, completed FROM sessions WHERE session_id = ?");
    $st->execute([$sid]);
    $row = $st->fetch();
    if (!$row) json_out(['ok' => false, 'error' => 'no_session'], 404);

    switch ($action) {
        case 'consent':
            $c = ($in['consent'] ?? -1) === 1 ? 1 : (($in['consent'] ?? -1) === 0 ? 0 : json_out(['ok' => false, 'error' => 'consent'], 400));
            if ($row['consent'] !== null) json_out(['ok' => false, 'error' => 'consent_already_set'], 409);
            $db->prepare("UPDATE sessions SET consent = ? WHERE session_id = ?")->execute([$c, $sid]);
            json_out(['ok' => true]);

        case 'survey':
            /* v2: 8-item branching survey.
               yes/no items (f1,f3,f5,f6): 1/0/null — freq follow-ups (f2,f4,f7): 0-4, only when parent = 1
               familiarity (f8): 1-5 */
            $yn = fn($k) => in_array($in[$k] ?? null, [0, 1], true) ? (int) $in[$k]
                            : (is_null($in[$k] ?? null) ? null : json_out(['ok' => false, 'error' => $k], 400));
            $fr = fn($k, $parent) => (function () use ($in, $k, $parent, $yn) {
                $p = $yn($parent);
                $v = $in[$k] ?? null;
                if ($p === 1) {
                    if (!is_int($v) || $v < 0 || $v > 4) json_out(['ok' => false, 'error' => $k], 400);
                    return [$p, $v];
                }
                if ($v !== null) json_out(['ok' => false, 'error' => $k . '_inconsistent'], 400);  // answered without Yes
                return [$p, null];
            })();
            [$f1, $f2] = $fr('f2', 'f1');
            [$f3, $f4] = $fr('f4', 'f3');
            $f5 = $yn('f5');
            [$f6, $f7] = $fr('f7', 'f6');
            $f8v = $in['f8'] ?? null;
            if ($f8v !== null && (!is_int($f8v) || $f8v < 1 || $f8v > 5)) json_out(['ok' => false, 'error' => 'f8'], 400);
            $db->prepare("UPDATE sessions SET familiarity_1=?, familiarity_2=?, familiarity_3=?, familiarity_4=?,
                          familiarity_5=?, familiarity_6=?, familiarity_7=?, familiarity_8=?
                          WHERE session_id = ?")
               ->execute([$f1, $f2, $f3, $f4, $f5, $f6, $f7, $f8v, $sid]);
            json_out(['ok' => true]);

        case 'finish':
            $db->prepare("UPDATE sessions SET completed = 1, finished_at = NOW() WHERE session_id = ?")->execute([$sid]);
            json_out(['ok' => true]);

        case 'quit':
            $db->prepare("UPDATE sessions SET completed = 0, finished_at = NOW() WHERE session_id = ?")->execute([$sid]);
            json_out(['ok' => true]);

        default:
            json_out(['ok' => false, 'error' => 'action'], 400);
    }
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
