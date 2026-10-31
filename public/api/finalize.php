<?php
// POST /api/finalize.php — end-of-session updates from both exit paths.
//   action=consent : {consent: 0|1}            (natural end OR quit)
//   action=survey  : {f1..f5: 0-5|null}        (natural end only)
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
        case 'alias':
            $alias = trim((string) ($in['alias'] ?? ''));
            if (!preg_match('/^[\w\x{0E00}-\x{0E7F} ]{3,16}$/u', $alias)) json_out(['ok' => false, 'error' => 'alias'], 400);
            $banned = ['fuck','shit','bitch','asshole','dick','cunt','เหี้ย','ควย','หี','สัส','ไอ้เหี้ย','เย็ด'];
            $low = mb_strtolower($alias);
            foreach ($banned as $b) {
                if (str_contains($low, $b)) json_out(['ok' => false, 'error' => 'alias'], 400);
            }
            $db->prepare("UPDATE sessions SET alias = ? WHERE session_id = ?")->execute([$alias, $sid]);
            json_out(['ok' => true]);

        case 'consent':
            $c = ($in['consent'] ?? -1) === 1 ? 1 : (($in['consent'] ?? -1) === 0 ? 0 : json_out(['ok' => false, 'error' => 'consent'], 400));
            if ($row['consent'] !== null) json_out(['ok' => false, 'error' => 'consent_already_set'], 409);
            $db->prepare("UPDATE sessions SET consent = ? WHERE session_id = ?")->execute([$c, $sid]);
            json_out(['ok' => true]);

        case 'survey':
            $vals = [];
            for ($i = 1; $i <= 5; $i++) {
                $v = $in["f$i"] ?? null;
                if ($v !== null && (!is_int($v) || $v < 0 || $v > 5)) json_out(['ok' => false, 'error' => "f$i"], 400);
                $vals[] = $v;
            }
            $db->prepare("UPDATE sessions SET familiarity_1=?, familiarity_2=?, familiarity_3=?,
                          familiarity_4=?, familiarity_5=? WHERE session_id = ?")
               ->execute([...$vals, $sid]);
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
