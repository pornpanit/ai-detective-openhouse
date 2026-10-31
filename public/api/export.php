<?php
// GET /api/export.php?type=sessions|trials|dprime — CSV download for the researcher.
// Admin-gated with the same cookie as admin.php. Consent-filtered research data only.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$pw = cfg()['admin_pw'] ?? 'adminlocal';
if (($_COOKIE['aidtect_admin'] ?? '') !== hash('sha256', $pw . '|aidtect')) {
    http_response_code(401);
    exit('unauthorized');
}

$type = $_GET['type'] ?? '';
$queries = [
    'sessions' => "SELECT session_id, lang, birth_year, cohort, gender, device, screen, alias,
                          familiarity_1, familiarity_2, familiarity_3, familiarity_4,
                          familiarity_5, familiarity_6, familiarity_7, familiarity_8,
                          completed, created_at, finished_at
                   FROM sessions WHERE consent = 1 ORDER BY created_at",
    'trials' => "SELECT t.session_id, t.round, t.trial_index, t.image_id, t.pair_image_id,
                        t.image_type, t.response, t.correct, t.rt_ms, t.created_at
                 FROM trials t JOIN sessions s ON s.session_id = t.session_id
                 WHERE s.consent = 1 AND t.round IN ('1','2')
                 ORDER BY t.session_id, t.round, t.trial_index",
    'dprime' => "SELECT t.session_id, t.round, t.image_type,
                        SUM(t.correct) AS correct_n, COUNT(*) AS n
                 FROM trials t JOIN sessions s ON s.session_id = t.session_id
                 WHERE s.consent = 1 AND t.round IN ('1','2')
                 GROUP BY t.session_id, t.round, t.image_type
                 ORDER BY t.session_id, t.round, t.image_type",
];
if (!isset($queries[$type])) exit('unknown type');

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"ai_detect_{$type}.csv\"");
$db = db_research();
$out = fopen('php://output', 'w');
$rows = $db->query($queries[$type]);
$first = true;
foreach ($rows as $r) {
    if ($first) { fputcsv($out, array_keys($r)); $first = false; }
    fputcsv($out, $r);
}
fclose($out);
