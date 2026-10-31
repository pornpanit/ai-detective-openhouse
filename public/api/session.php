<?php
// POST /api/session.php — create a session (with unique alias) and return its trial plan.
// One-play lock: when TEST_MODE is off, a repeat_tag (browser cookie id) that
// already has a session is rejected with 'already_played'.
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
$alias = trim((string) ($in['alias'] ?? ''));
if (!preg_match('/^[\w\x{0E00}-\x{0E7F} ]{3,16}$/u', $alias)) json_out(['ok' => false, 'error' => 'alias'], 400);

try {
    $db = db_research();

    // one play per browser (cookie) unless TEST_MODE
    $testMode = getenv('TEST_MODE') !== '0';           // default ON while testing
    if (!$testMode && $repeat !== '') {
        $st = $db->prepare("SELECT COUNT(*) FROM sessions WHERE repeat_tag = ?");
        $st->execute([$repeat]);
        if ((int) $st->fetchColumn() > 0) json_out(['ok' => false, 'error' => 'already_played'], 409);
    }

    ensure_images_ingested($db);
    $plan = generate_plan($db);

    $sid = bin2hex(random_bytes(12));
    $st = $db->prepare("INSERT INTO sessions (session_id, lang, birth_year, cohort, gender, device, screen,
                        plan, alias, repeat_tag, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
    try {
        $st->execute([$sid, $lang, $birth_year, cohort_of($birth_year), $gender, $device, $screen,
                      json_encode($plan, JSON_UNESCAPED_UNICODE), $alias, $repeat]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(['ok' => false, 'error' => 'alias_taken'], 409);
        throw $e;
    }
    json_out(['ok' => true, 'session_id' => $sid, 'plan' => $plan]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
