<?php
// POST /api/session.php — create a session and return its server-generated trial plan.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

$in = read_json_body();
$lang = in_array($in['lang'] ?? '', ['th', 'en'], true) ? $in['lang'] : json_out(['ok' => false, 'error' => 'lang'], 400);
$birth_year = (int) ($in['birth_year'] ?? 0);
if ($birth_year < 1900 || $birth_year > 2012) json_out(['ok' => false, 'error' => 'birth_year'], 400);
$gender = mb_substr(trim((string) ($in['gender'] ?? '')), 0, 16);
$device = mb_substr(trim((string) ($in['device'] ?? '')), 0, 250);
$screen = mb_substr(trim((string) ($in['screen'] ?? '')), 0, 40);
$repeat = mb_substr(trim((string) ($in['repeat_tag'] ?? '')), 0, 64);

try {
    $db = db_research();
    ensure_images_ingested($db);
    $plan = generate_plan($db);

    $sid = bin2hex(random_bytes(12));
    $st = $db->prepare("INSERT INTO sessions (session_id, lang, birth_year, cohort, gender, device, screen,
                        plan, repeat_tag, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $st->execute([$sid, $lang, $birth_year, cohort_of($birth_year), $gender, $device, $screen,
                  json_encode($plan, JSON_UNESCAPED_UNICODE), $repeat]);
    json_out(['ok' => true, 'session_id' => $sid, 'plan' => $plan]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
