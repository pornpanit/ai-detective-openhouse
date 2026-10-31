<?php
// GET /admin.php — researcher dashboard v4 (read-only research DB only).
declare(strict_types=1);
require __DIR__ . '/api/lib.php';

$pw = cfg()['admin_pw'] ?? 'adminlocal';
$cookieOk = ($_COOKIE['aidtect_admin'] ?? '') === hash('sha256', $pw . '|aidtect');
if (!$cookieOk && ($_REQUEST['pw'] ?? '') === $pw) {
    setcookie('aidtect_admin', hash('sha256', $pw . '|aidtect'), ['httponly' => true, 'samesite' => 'Lax']);
    header('Location: admin.php');
    exit;
}
if (!$cookieOk) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Admin</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="css/game.css"></head><body>'
       . '<div class="jspsych-display-element"><div class="jspsych-content"><div class="card">'
       . '<h2>Admin</h2><form method="post"><p><label>Password<br>'
       . '<input type="password" name="pw" autofocus></label></p>'
       . '<button class="jspsych-btn">Sign in</button></form></div></div></div></body></html>';
    exit;
}

$db = db_research();
$one = fn(string $sql) => $db->query($sql)->fetchAll();

$tot = $db->query("SELECT COUNT(*) n,
    SUM(consent=1) consented, SUM(consent=0) declined, SUM(consent IS NULL) unanswered,
    SUM(completed=1) completed, SUM(consent=1 AND completed=0) consented_quitters
    FROM sessions")->fetch();
$trials = $one("SELECT COUNT(*) n, ROUND(AVG(rt_ms)) avg_rt, ROUND(100*AVG(correct),1) pct_correct
    FROM trials WHERE round IN ('1','2')");

/* ---- chart data ---- */
$byCohort = $one("SELECT cohort, COUNT(*) n FROM sessions GROUP BY cohort ORDER BY cohort");
$byGender = $one("SELECT CASE WHEN gender IN ('Female','Male') THEN gender ELSE 'Non-binary/other' END g,
    COUNT(*) n FROM sessions GROUP BY g ORDER BY n DESC");
$byLang = $one("SELECT lang, COUNT(*) n FROM sessions GROUP BY lang ORDER BY lang");
$byHour = $one("SELECT HOUR(created_at) h, COUNT(*) n FROM sessions
    GROUP BY h ORDER BY h");
$durations = $one("SELECT TIMESTAMPDIFF(SECOND, created_at, finished_at) secs
    FROM sessions WHERE finished_at IS NOT NULL AND completed = 1");
$accCohort = $one("SELECT s.cohort, ROUND(100*AVG(t.correct),1) pct, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent = 1 GROUP BY s.cohort ORDER BY s.cohort");

/* R1 = % of AI faces spotted (no real rows by design); R2 = hit / correct-rejection */
$r1 = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND t.round='1' GROUP BY t.image_type ORDER BY t.image_type");
$r2 = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND t.round='2' GROUP BY t.image_type ORDER BY t.image_type");

/* cohort × type matrix (consented; R1+R2 combined, R1 real = none by design) */
$matrix = $one("SELECT s.cohort, t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 GROUP BY s.cohort, t.image_type ORDER BY s.cohort, t.image_type");

/* RT distribution per round (consented) */
$rtHist = $one("SELECT t.round,
    SUM(t.rt_ms < 1000) b1, SUM(t.rt_ms >= 1000 AND t.rt_ms < 2000) b2,
    SUM(t.rt_ms >= 2000 AND t.rt_ms < 3000) b3, SUM(t.rt_ms >= 3000 AND t.rt_ms < 5000) b4,
    SUM(t.rt_ms >= 5000 AND t.rt_ms < 8000) b5, SUM(t.rt_ms >= 8000) b6
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND t.round IN ('1','2') GROUP BY t.round");

/* self-rated AI familiarity (Q8) vs accuracy (consented, answered) */
$famAcc = $one("SELECT s.familiarity_8 fam, ROUND(100*AVG(t.correct),1) pct, COUNT(DISTINCT s.session_id) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.familiarity_8 IS NOT NULL
    GROUP BY s.familiarity_8 ORDER BY s.familiarity_8");

/* AI-image exposure (Q2) vs accuracy */
$expAcc = $one("SELECT s.familiarity_2 expr, ROUND(100*AVG(t.correct),1) pct, COUNT(DISTINCT s.session_id) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.familiarity_2 IS NOT NULL
    GROUP BY s.familiarity_2 ORDER BY s.familiarity_2");

/* quit analysis: quitters by furthest round reached */
$quits = $one("SELECT s.session_id,
    COALESCE(MAX(CASE t.round WHEN 'practice' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 END), -1) furthest
    FROM sessions s LEFT JOIN trials t ON t.session_id = s.session_id
    WHERE s.finished_at IS NOT NULL AND s.completed = 0
    GROUP BY s.session_id");
$quitBuckets = ['never answered' => 0, 'practice' => 0, 'round 1' => 0, 'round 2' => 0];
foreach ($quits as $q) {
    $k = ['never answered', 'practice', 'round 1', 'round 2'][$q['furthest'] + 1] ?? 'never answered';
    $quitBuckets[$k]++;
}

/* funnel */
$funnel = [
    ['started', (int) $tot['n']],
    ['completed', (int) $tot['completed']],
    ['consented', (int) $tot['consented']],
    ['on the board', (int) $db->query("SELECT COUNT(*) FROM sessions WHERE completed=1 AND alias IS NOT NULL")->fetchColumn()],
];

/* R2 response bias per cohort: % of faces labeled AI */
$bias = $one("SELECT s.cohort, ROUND(100*AVG(t.response),1) pct_ai, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round='2'
    WHERE s.consent=1 GROUP BY s.cohort ORDER BY s.cohort");

$recent = $one("SELECT s.session_id, s.lang, s.cohort, s.alias, s.consent, s.completed,
    s.created_at, COUNT(t.id) trials, COALESCE(SUM(t.correct),0) correct
    FROM sessions s LEFT JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    GROUP BY s.session_id ORDER BY s.created_at DESC LIMIT 20");

function buckets(array $rows): array {
    $b = ['<3' => 0, '3-5' => 0, '5-8' => 0, '8-12' => 0, '12+' => 0];
    foreach ($rows as $r) {
        $m = (int) $r['secs'] / 60;
        if ($m < 3) $b['<3']++;
        elseif ($m < 5) $b['3-5']++;
        elseif ($m < 8) $b['5-8']++;
        elseif ($m < 12) $b['8-12']++;
        else $b['12+']++;
    }
    return $b;
}
$durBuckets = buckets($durations);
$secs = array_map(fn($r) => (int) $r['secs'], $durations);
sort($secs);
$durMean = $secs ? round(array_sum($secs) / count($secs) / 60, 1) : null;
$durMedian = $secs ? round($secs[intdiv(count($secs), 2)] / 60, 1) : null;

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function tbl(array $rows, array $cols) {
    if (!$rows) return '<p class="hint">no data yet</p>';
    $out = '<div class="leaderboard"><table><tr>' . implode('', array_map(fn($c) => "<th>{$c[1]}</th>", $cols)) . '</tr>';
    foreach ($rows as $r) {
        $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . h($r[$c[0]] ?? '') . '</td>', $cols)) . '</tr>';
    }
    return $out . '</table></div>';
}
function bars(array $data, string $suffix = ''): string {
    $max = max(1, max(array_map(fn($d) => (float) $d[1], $data) ?: [1]));
    $out = '';
    foreach ($data as [$label, $value]) {
        $w = round(100 * (float) $value / $max, 1);
        $out .= "<div class='bar-row'><div class='bar-label'>" . h($label) . "</div>"
              . "<div class='bar-track'><div class='bar' style='width:{$w}%'></div></div>"
              . "<div class='bar-val'>" . h($value) . h($suffix) . "</div></div>";
    }
    return $out;
}
function pie(array $data): string {
    $total = max(1, array_sum(array_map(fn($d) => (float) $d[1], $data)));
    $colors = ['#8c5b2f', '#3f7cac', '#c98d4b', '#5f8f5e', '#a33b2e', '#7a6ba3'];
    $pos = 0; $stops = [];
    foreach ($data as $i => [$label, $value]) {
        $pct = 100 * (float) $value / $total;
        $stops[] = $colors[$i % count($colors)] . " {$pos}% " . round($pos + $pct, 2) . "%";
        $pos += $pct;
    }
    $legend = '';
    foreach ($data as $i => [$label, $value]) {
        $legend .= "<div class='lg-row'><span class='lg-dot' style='background:{$colors[$i % count($colors)]}'></span>"
                 . h($label) . " <b>" . h($value) . "</b></div>";
    }
    return "<div class='pie-wrap'><div class='pie' style='background:conic-gradient(" . implode(',', $stops) . ")'></div>"
         . "<div class='pie-legend'>{$legend}</div></div>";
}
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Admin — AI Detective</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="css/game.css">
<style>
body{background:#f2efe7}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
.cardv{background:#fffdf7;border:1px solid #e3dcc9;border-radius:12px;padding:12px;text-align:center}
.cardv b{display:block;font-size:1.7rem;color:#8c5b2f}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
.chart{background:#fffdf7;border:1px solid #e3dcc9;border-radius:12px;padding:14px;margin:10px 0}
.chart h3{margin:0 0 10px;font-size:1rem}
.bar-row{display:flex;align-items:center;gap:8px;margin:6px 0}
.bar-label{width:130px;font-size:.85rem;text-align:right;color:#4b4433}
.bar-track{flex:1;background:#eae4d3;border-radius:5px;overflow:hidden}
.bar{background:linear-gradient(90deg,#8c5b2f,#c98d4b);height:20px;border-radius:5px;min-width:2px}
.bar-val{width:70px;font-size:.85rem;font-weight:600;color:#4b4433}
.explain{background:#fdf6e3;border:1px dashed #c9b98a;border-radius:10px;padding:10px 12px;font-size:.9rem;margin:8px 0}
.pie-wrap{display:flex;align-items:center;gap:18px;justify-content:center;flex-wrap:wrap}
.pie{width:150px;height:150px;border-radius:50%;box-shadow:inset 0 0 0 34px #fffdf7;flex:none}
.pie-legend{text-align:left}
.lg-row{margin:4px 0;font-size:.9rem}
.lg-dot{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:8px}
.dl{display:inline-block;margin:4px 6px 0 0;padding:8px 14px;background:#fff;border:2px solid #8c5b2f;
    border-radius:10px;color:#8c5b2f;font-weight:600;text-decoration:none;font-size:.9rem}
.dl:hover{background:#f4e9d2}
</style></head>
<body><div class="jspsych-display-element" style="max-width:1000px"><div class="jspsych-content" style="max-width:1000px">
<div class="card"><div class="case-tag">Admin v4 · auto-refresh 60s</div>
<h2>AI Detective — research dashboard</h2>

<div class="cards">
<div class="cardv"><b><?= h($tot['n']) ?></b>sessions</div>
<div class="cardv"><b><?= h($tot['consented']) ?></b>consented</div>
<div class="cardv"><b><?= h($tot['declined']) ?></b>declined</div>
<div class="cardv"><b><?= h($tot['unanswered']) ?></b>unanswered</div>
<div class="cardv"><b><?= h($tot['completed']) ?></b>completed</div>
<div class="cardv"><b><?= h($tot['consented_quitters']) ?></b>consented quitters</div>
</div>

<p class="hint">All scored trials: <?= h($trials[0]['n'] ?? 0) ?> · overall accuracy
<?= h($trials[0]['pct_correct'] ?? 0) ?>% · mean RT <?= h($trials[0]['avg_rt'] ?? 0) ?> ms</p>

<h2>Who joined</h2>
<div class="grid2">
<div class="chart"><h3>Generation cohort</h3><?= pie(array_map(fn($r) => [$r['cohort'], $r['n']], $byCohort)) ?></div>
<div class="chart"><h3>Participant gender</h3><?= pie(array_map(fn($r) => [$r['g'], $r['n']], $byGender)) ?></div>
<div class="chart"><h3>Language</h3><?= pie(array_map(fn($r) => [$r['lang'], $r['n']], $byLang)) ?></div>
<div class="chart"><h3>Joins by hour of day</h3><?= bars(array_map(fn($r) => [str_pad($r['h'], 2, '0', STR_PAD_LEFT) . ':00', $r['n']], $byHour)) ?></div>
<div class="chart"><h3>Conversion funnel</h3><?= bars($funnel) ?></div>
<div class="chart"><h3>Session length (min, completed)
<div class="hint">mean <?= h($durMean ?? '—') ?> · median <?= h($durMedian ?? '—') ?></div></h3>
<?= bars(array_map(fn($k, $v) => [$k, $v], array_keys($durBuckets), $durBuckets)) ?></div>
<div class="chart"><h3>Where players quit</h3><?= bars(array_map(fn($k, $v) => [$k, $v], array_keys($quitBuckets), $quitBuckets)) ?></div>
</div>

<h2>How well they detected (consented only)</h2>
<div class="explain"><b>How to read this:</b>
<b>Investigation 1</b> showed two faces (one AI, one real) — players tapped the AI one, so the score is
<b>% of AI faces successfully spotted</b> (real faces have no row here by design).
<b>Investigation 2</b> showed one face — for <b>gan/diffusion</b> rows the score is the
<b>hit rate</b> (correctly said "AI"); for the <b>real</b> row it is the <b>correct-rejection rate</b>
(correctly said "Real").</div>
<div class="grid2">
<div class="chart"><h3>Accuracy by cohort (%)</h3><?= bars(array_map(fn($r) => [$r['cohort'] . " (n={$r['n']})", $r['pct']], $accCohort), '%') ?></div>
<div class="chart"><h3>Investigation 1 — % of AI faces spotted</h3>
<?= bars(array_map(fn($r) => ["{$r['image_type']} (n={$r['n']})", $r['pct']], $r1), '%') ?></div>
<div class="chart"><h3>Investigation 2 — hit / correct-rejection (%)</h3>
<?= bars(array_map(fn($r) => ["{$r['image_type']} (n={$r['n']})", $r['pct']], $r2), '%') ?></div>
<div class="chart"><h3>R2 response bias by cohort — % of faces labeled AI</h3>
<?= bars(array_map(fn($r) => [$r['cohort'] . " (n={$r['n']})", $r['pct_ai']], $bias), '%') ?></div>
</div>

<h3>Cohort × image type accuracy matrix (%)</h3>
<?= tbl($matrix, [['cohort','cohort'],['image_type','type'],['n','trials'],['pct','% correct']]) ?>

<h2>Response times &amp; background (consented only)</h2>
<div class="grid2">
<?php foreach ($rtHist as $r): ?>
<div class="chart"><h3>RT distribution — Investigation <?= h($r['round']) ?> (ms)</h3>
<?php $rtRows = [['<1s', $r['b1']], ['1–2s', $r['b2']], ['2–3s', $r['b3']], ['3–5s', $r['b4']], ['5–8s', $r['b5']], ['8s+', $r['b6']]];
   echo bars($rtRows); ?></div>
<?php endforeach; ?>
<div class="chart"><h3>Accuracy by self-rated AI familiarity (Q8)</h3>
<?= bars(array_map(fn($r) => ["level {$r['fam']} (n={$r['n']})", $r['pct']], $famAcc), '%') ?></div>
<div class="chart"><h3>Accuracy by AI-image exposure (Q2)</h3>
<?= bars(array_map(fn($r) => ["exposure {$r['expr']} (n={$r['n']})", $r['pct']], $expAcc), '%') ?></div>
</div>

<h2>Recent sessions (newest 20)</h2>
<?= tbl($recent, [['session_id','session'],['lang','lang'],['alias','codename'],['cohort','cohort'],
    ['trials','trials'],['correct','correct'],['consent','consent'],['completed','completed'],
    ['created_at','created']]) ?>

<h2>Downloads (consented research data)</h2>
<a class="dl" href="api/export.php?type=sessions">⬇ sessions.csv</a>
<a class="dl" href="api/export.php?type=trials">⬇ trials.csv</a>
<a class="dl" href="api/export.php?type=dprime">⬇ dprime_inputs.csv</a>

<p class="hint">Prize contacts live in a separate database (never shown here) ·
Live board: <a href="live.html">live.html</a> (for the booth screen)</p>
</div></div></div></body></html>
