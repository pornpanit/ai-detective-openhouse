<?php
// GET/POST /admin.php — researcher dashboard v5 (read-only data + test-flag controls).
// Flagged sessions (is_test=1) are hidden from the public board and research
// exports but remain fully visible here for review.
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
       . '<title>Admin</title><link rel="icon" href="assets/icon.png" type="image/png">'
       . '<link rel="stylesheet" href="css/game.css"></head><body>'
       . '<div class="jspsych-display-element"><div class="jspsych-content"><div class="card">'
       . '<h2>Admin</h2><form method="post"><p><label>Password<br>'
       . '<input type="password" name="pw" autofocus></label></p>'
       . '<button class="jspsych-btn">Sign in</button></form></div></div></div></body></html>';
    exit;
}

/* ---- test-flag actions (cookie-gated) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = db_research();
    $act = $_POST['action'] ?? '';
    if ($act === 'flag_all') {
        $db->exec("UPDATE sessions SET is_test = 1 WHERE is_test = 0");
    } elseif ($act === 'toggle' && preg_match('/^[0-9a-f]{24}$/', $_POST['session_id'] ?? '')) {
        $st = $db->prepare("UPDATE sessions SET is_test = 1 - is_test WHERE session_id = ?");
        $st->execute([$_POST['session_id']]);
    }
    header('Location: admin.php');
    exit;
}

$db = db_research();
$one = fn(string $sql) => $db->query($sql)->fetchAll();

$tot = $db->query("SELECT COUNT(*) n,
    SUM(consent=1 AND is_test=0) consented, SUM(consent=0 AND is_test=0) declined,
    SUM(consent IS NULL AND is_test=0) unanswered,
    SUM(completed=1 AND is_test=0) completed,
    SUM(consent=1 AND completed=0 AND is_test=0) consented_quitters,
    SUM(is_test=1) test_hidden
    FROM sessions")->fetch();
$trials = $one("SELECT COUNT(*) n, ROUND(AVG(rt_ms)) avg_rt, ROUND(100*AVG(correct),1) pct_correct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.is_test = 0 AND t.round IN ('1','2')");

/* ---- chart data (live = not test-flagged) ---- */
$byCohort = $one("SELECT cohort, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY cohort ORDER BY cohort");
$byGender = $one("SELECT CASE WHEN gender IN ('Female','Male') THEN gender ELSE 'Non-binary/other' END g,
    COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY g ORDER BY n DESC");
$byLang = $one("SELECT lang, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY lang ORDER BY lang");
$byHour = $one("SELECT HOUR(created_at) h, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY h ORDER BY h");
$durations = $one("SELECT TIMESTAMPDIFF(SECOND, s.created_at, s.finished_at) secs
    FROM sessions s WHERE s.finished_at IS NOT NULL AND s.completed = 1 AND s.is_test = 0");
$accCohort = $one("SELECT s.cohort, ROUND(100*AVG(t.correct),1) pct, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent = 1 AND s.is_test = 0 GROUP BY s.cohort ORDER BY s.cohort");
$r1 = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='1' GROUP BY t.image_type ORDER BY t.image_type");
$r2 = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='2' GROUP BY t.image_type ORDER BY t.image_type");
$matrix = $one("SELECT s.cohort, t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.is_test=0 GROUP BY s.cohort, t.image_type ORDER BY s.cohort, t.image_type");
$rtHist = $one("SELECT t.round,
    SUM(t.rt_ms < 1000) b1, SUM(t.rt_ms >= 1000 AND t.rt_ms < 2000) b2,
    SUM(t.rt_ms >= 2000 AND t.rt_ms < 3000) b3, SUM(t.rt_ms >= 3000 AND t.rt_ms < 5000) b4,
    SUM(t.rt_ms >= 5000 AND t.rt_ms < 8000) b5, SUM(t.rt_ms >= 8000) b6
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round IN ('1','2') GROUP BY t.round");
$famAcc = $one("SELECT s.familiarity_8 fam, ROUND(100*AVG(t.correct),1) pct, COUNT(DISTINCT s.session_id) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.is_test=0 AND s.familiarity_8 IS NOT NULL
    GROUP BY s.familiarity_8 ORDER BY s.familiarity_8");
$expAcc = $one("SELECT s.familiarity_2 expr, ROUND(100*AVG(t.correct),1) pct, COUNT(DISTINCT s.session_id) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.is_test=0 AND s.familiarity_2 IS NOT NULL
    GROUP BY s.familiarity_2 ORDER BY s.familiarity_2");
$quits = $one("SELECT s.session_id,
    COALESCE(MAX(CASE t.round WHEN 'practice' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 END), -1) furthest
    FROM sessions s LEFT JOIN trials t ON t.session_id = s.session_id
    WHERE s.finished_at IS NOT NULL AND s.completed = 0 AND s.is_test = 0
    GROUP BY s.session_id");
$quitBuckets = ['never answered' => 0, 'practice' => 0, 'round 1' => 0, 'round 2' => 0];
foreach ($quits as $q) {
    $k = ['never answered', 'practice', 'round 1', 'round 2'][$q['furthest'] + 1] ?? 'never answered';
    $quitBuckets[$k]++;
}
$funnel = [
    ['started', (int) $tot['n'] - (int) $tot['test_hidden']],
    ['completed', (int) $tot['completed']],
    ['consented', (int) $tot['consented']],
    ['on the board', (int) $db->query("SELECT COUNT(*) FROM sessions WHERE completed=1 AND alias IS NOT NULL AND is_test=0")->fetchColumn()],
];
$bias = $one("SELECT s.cohort, ROUND(100*AVG(t.response),1) pct_ai, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round='2'
    WHERE s.consent=1 AND s.is_test=0 GROUP BY s.cohort ORDER BY s.cohort");
$recent = $one("SELECT s.session_id, s.lang, s.cohort, s.alias, s.consent, s.completed, s.is_test,
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
    if (!$rows) return '<div class="empty">waiting for data…</div>';
    $out = '<div class="tbl-wrap"><table><thead><tr>' . implode('', array_map(fn($c) => "<th>{$c[1]}</th>", $cols)) . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . ($r[$c[0]] ?? '') . '</td>', $cols)) . '</tr>';
    }
    return $out . '</tbody></table></div>';
}
function bars(array $data, string $suffix = ''): string {
    if (!array_filter($data, fn($d) => $d[1] > 0)) return '<div class="empty">waiting for data…</div>';
    $max = max(1, max(array_map(fn($d) => (float) $d[1], $data)));
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
    $total = array_sum(array_map(fn($d) => (float) $d[1], $data));
    if ($total < 1) return '<div class="empty">waiting for data…</div>';
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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Admin — AI Detective</title>
<link rel="icon" href="assets/icon.png" type="image/png">
<style>
:root{
  --bg:#f0ede4; --surface:#fffdf8; --line:#e3dcc9; --ink:#2b2a26; --muted:#7a7466;
  --accent:#8c5b2f; --accent2:#3f7cac; --good:#4e8d5b; --warn:#c26b3a; --bad:#a33b2e;
  --radius:14px; --shadow:0 1px 4px rgba(60,45,20,.08);
}
*{box-sizing:border-box;margin:0}
body{background:var(--bg);color:var(--ink);
  font:15px/1.5 "Segoe UI",system-ui,"Noto Sans Thai",sans-serif;padding:0 0 60px}
.wrap{max-width:1060px;margin:0 auto;padding:0 18px}
header.top{background:var(--surface);border-bottom:1px solid var(--line);padding:14px 0 12px;margin-bottom:18px}
.brand{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.brand img{width:40px;height:40px;border-radius:10px}
.brand h1{font-size:1.25rem;font-weight:700}
.brand .sub{color:var(--muted);font-size:.85rem}
.livepill{margin-left:auto;display:inline-flex;align-items:center;gap:7px;font-size:.78rem;font-weight:700;
  letter-spacing:.14em;color:var(--good);border:1.5px solid var(--good);border-radius:999px;padding:5px 13px}
.livepill i{width:8px;height:8px;border-radius:50%;background:var(--good);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
h2.sec{font-size:.85rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;
  color:var(--accent);margin:30px 0 12px;display:flex;align-items:center;gap:10px}
h2.sec::after{content:"";flex:1;height:1px;background:var(--line)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:14px 12px;text-align:center}
.kpi b{display:block;font-size:1.9rem;font-weight:800;color:var(--accent);line-height:1.1}
.kpi span{font-size:.74rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.kpi.warn b{color:var(--warn)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:16px}
.card h3{font-size:.95rem;font-weight:700;margin-bottom:12px}
.card .note{font-size:.8rem;color:var(--muted);margin-top:10px}
.bar-row{display:flex;align-items:center;gap:10px;margin:7px 0}
.bar-label{width:132px;font-size:.82rem;text-align:right;color:var(--muted);flex:none}
.bar-track{flex:1;background:#ece6d6;border-radius:6px;overflow:hidden;height:22px}
.bar{background:linear-gradient(90deg,var(--accent),#c98d4b);height:100%;border-radius:6px;min-width:3px}
.bar-val{width:72px;font-size:.82rem;font-weight:700;flex:none;font-variant-numeric:tabular-nums}
.pie-wrap{display:flex;align-items:center;gap:20px;justify-content:center;flex-wrap:wrap;min-height:150px}
.pie{width:148px;height:148px;border-radius:50%;flex:none;box-shadow:inset 0 0 0 32px var(--surface)}
.lg-row{margin:4px 0;font-size:.88rem}
.lg-dot{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:8px}
.empty{color:var(--muted);font-size:.88rem;text-align:center;padding:34px 10px;
  background:repeating-linear-gradient(45deg,transparent,transparent 8px,rgba(0,0,0,.015) 8px,rgba(0,0,0,.015) 16px);
  border:1.5px dashed var(--line);border-radius:10px}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);
  text-align:left;padding:8px 10px;border-bottom:2px solid var(--line)}
td{padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
tr:hover td{background:#f7f1e3}
.badge{display:inline-block;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px}
.badge.test{background:#f4e3d2;color:var(--warn);border:1px solid var(--warn)}
.badge.cons{background:#e2eee4;color:var(--good);border:1px solid var(--good)}
.badge.nocons{background:#f3e0de;color:var(--bad);border:1px solid var(--bad)}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 0}
button.act,a.act{font:600 .82rem/1 "Segoe UI",system-ui,sans-serif;padding:10px 16px;border-radius:10px;
  cursor:pointer;border:1.5px solid var(--accent);background:var(--accent);color:#fff;text-decoration:none;display:inline-block}
button.act.ghost,a.act.ghost{background:transparent;color:var(--accent)}
button.act:hover{filter:brightness(1.08)}
.mini{font:600 .72rem/1 sans-serif;padding:5px 10px;border-radius:8px;border:1.5px solid var(--line);
  background:transparent;color:var(--muted);cursor:pointer}
.mini:hover{border-color:var(--accent);color:var(--accent)}
.explain{background:#fbf3dd;border:1px solid #e8d9a8;border-radius:10px;padding:10px 14px;
  font-size:.85rem;margin-bottom:14px}
.dl{display:inline-flex;align-items:center;gap:7px;margin:4px 8px 0 0;padding:10px 16px;
  background:var(--surface);border:1.5px solid var(--accent);border-radius:10px;
  color:var(--accent);font-weight:700;font-size:.85rem;text-decoration:none}
.dl:hover{background:#f4e9d2}
footer{margin-top:34px;color:var(--muted);font-size:.78rem;text-align:center}
@media(max-width:640px){ .bar-label{width:92px;font-size:.75rem} .kpi b{font-size:1.5rem} }
</style></head><body>

<header class="top"><div class="wrap brand">
  <img src="assets/icon.png" alt="">
  <div><h1>AI Detective — Research Dashboard</h1><div class="sub">auto-refresh 60s · data excludes 🧪 test sessions</div></div>
  <span class="livepill"><i></i>LIVE EVENT VIEW</span>
</div></header>

<div class="wrap">

<!-- OVERVIEW -->
<div class="kpis">
  <div class="kpi"><b><?= h($tot['n'] - $tot['test_hidden']) ?></b><span>sessions</span></div>
  <div class="kpi"><b><?= h($tot['completed']) ?></b><span>completed</span></div>
  <div class="kpi"><b><?= h($tot['consented']) ?></b><span>consented</span></div>
  <div class="kpi"><b><?= h($trials[0]['pct_correct'] ?? 0) ?>%</b><span>accuracy</span></div>
  <div class="kpi"><b><?= h($trials[0]['avg_rt'] ?? 0) ?></b><span>mean RT ms</span></div>
  <div class="kpi warn"><b><?= h($tot['test_hidden']) ?></b><span>🧪 test (hidden)</span></div>
</div>

<?php if ((int) $tot['n'] > 0): ?>
<div class="card" style="margin-top:14px">
  <h3>Event-day prep</h3>
  <div class="explain" style="margin:0">🧪 <b>Test sessions</b> are hidden from the public board, LIVE stats, and research
  exports — but stay listed below for your review. Flag your testing plays so event day starts clean.</div>
  <div class="actions">
    <form method="post" onsubmit="return confirm('Flag ALL current sessions as test? They will disappear from the public board but stay here.')">
      <input type="hidden" name="action" value="flag_all"><button class="act">🧪 Hide all existing sessions</button>
    </form>
    <a class="act ghost" href="live.html" target="_blank">Open LIVE board</a>
  </div>
</div>
<?php endif; ?>

<!-- AUDIENCE -->
<h2 class="sec">Who joined</h2>
<div class="grid">
  <div class="card"><h3>Generation cohort</h3><?= pie(array_map(fn($r) => [$r['cohort'], $r['n']], $byCohort)) ?></div>
  <div class="card"><h3>Participant gender</h3><?= pie(array_map(fn($r) => [$r['g'], $r['n']], $byGender)) ?></div>
  <div class="card"><h3>Language</h3><?= pie(array_map(fn($r) => [$r['lang'], $r['n']], $byLang)) ?></div>
  <div class="card"><h3>Joins by hour</h3><?= bars(array_map(fn($r) => [str_pad((string) $r['h'], 2, "0", STR_PAD_LEFT) . ":00", $r["n"]], $byHour)) ?></div>
  <div class="card"><h3>Conversion funnel</h3><?= bars($funnel) ?>
    <div class="note">started → completed → consented → on the board</div></div>
  <div class="card"><h3>Session length (min)<?= $durMean !== null ? " · mean {$durMean} · median {$durMedian}" : '' ?></h3>
    <?= bars(array_map(fn($k, $v) => [$k, $v], array_keys($durBuckets), $durBuckets)) ?></div>
  <div class="card"><h3>Where players quit</h3><?= bars(array_map(fn($k, $v) => [$k, $v], array_keys($quitBuckets), $quitBuckets)) ?></div>
</div>

<!-- PERFORMANCE -->
<h2 class="sec">Detection performance <span style="letter-spacing:0;text-transform:none;font-weight:400">(consented only)</span></h2>
<div class="explain"><b>Investigation 1</b> (choose the AI face in a pair) → score = <b>% of AI faces spotted</b>; real faces
have no row by design. <b>Investigation 2</b> (AI or Real?) → for gan/diffusion the score is the <b>hit rate</b>
(said AI), for real it is the <b>correct-rejection rate</b> (said Real).</div>
<div class="grid">
  <div class="card"><h3>Accuracy by cohort</h3><?= bars(array_map(fn($r) => ["{$r['cohort']} (n={$r['n']})", $r['pct']], $accCohort), '%') ?></div>
  <div class="card"><h3>Investigation 1 — AI faces spotted</h3><?= bars(array_map(fn($r) => ["{$r['image_type']} (n={$r['n']})", $r['pct']], $r1), '%') ?></div>
  <div class="card"><h3>Investigation 2 — hit / correct rejection</h3><?= bars(array_map(fn($r) => ["{$r['image_type']} (n={$r['n']})", $r['pct']], $r2), '%') ?></div>
  <div class="card"><h3>R2 response bias — % labeled AI</h3><?= bars(array_map(fn($r) => ["{$r['cohort']} (n={$r['n']})", $r['pct_ai']], $bias), '%') ?>
    <div class="note">50% would be perfectly unbiased across both true types</div></div>
</div>
<div class="card" style="margin-top:14px"><h3>Cohort × image type matrix (%)</h3>
<?= tbl($matrix, [['cohort','cohort'],['image_type','type'],['n','trials'],['pct','% correct']]) ?></div>

<!-- BEHAVIOR -->
<h2 class="sec">Response times &amp; background</h2>
<div class="grid">
<?php foreach ($rtHist as $r): ?>
  <div class="card"><h3>RT distribution — Investigation <?= h($r['round']) ?></h3>
  <?php $rtRows = [['<1s', $r['b1']], ['1–2s', $r['b2']], ['2–3s', $r['b3']], ['3–5s', $r['b4']], ['5–8s', $r['b5']], ['8s+', $r['b6']]];
     echo bars($rtRows); ?></div>
<?php endforeach; ?>
  <div class="card"><h3>Accuracy × self-rated AI familiarity (Q8)</h3><?= bars(array_map(fn($r) => ["level {$r['fam']} (n={$r['n']})", $r['pct']], $famAcc), '%') ?></div>
  <div class="card"><h3>Accuracy × AI-image exposure (Q2)</h3><?= bars(array_map(fn($r) => ["exposure {$r['expr']} (n={$r['n']})", $r['pct']], $expAcc), '%') ?></div>
</div>

<!-- DATA -->
<h2 class="sec">Sessions <span style="letter-spacing:0;text-transform:none;font-weight:400">(newest 20 · 🧪 = hidden from public)</span></h2>
<div class="card">
<?php if (!$recent): ?><div class="empty">waiting for data…</div><?php else: ?>
<div class="tbl-wrap"><table>
<thead><tr><th>session</th><th>lang</th><th>codename</th><th>cohort</th><th>trials</th><th>correct</th><th>consent</th><th>done</th><th>test</th><th>created</th></tr></thead>
<tbody>
<?php foreach ($recent as $r): ?>
<tr>
  <td><?= h(substr($r['session_id'], 0, 10)) ?>…</td>
  <td><?= h($r['lang']) ?></td>
  <td><b><?= h($r['alias']) ?></b></td>
  <td><?= h($r['cohort']) ?></td>
  <td><?= h($r['trials']) ?></td>
  <td><?= h($r['correct']) ?></td>
  <td><?php if ($r['consent'] === '1') echo '<span class="badge cons">yes</span>';
           elseif ($r['consent'] === '0') echo '<span class="badge nocons">no</span>';
           else echo '<span style="color:var(--muted)">—</span>'; ?></td>
  <td><?= $r['completed'] === '1' ? '✓' : 'quit' ?></td>
  <td><?php if ($r['is_test'] === '1') echo '<span class="badge test">🧪 test</span>'; ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="session_id" value="<?= h($r['session_id']) ?>">
        <button class="mini"><?= $r['is_test'] === '1' ? 'un-hide' : '🧪 hide' ?></button>
      </form></td>
  <td><?= h($r['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<h2 class="sec">Research exports</h2>
<div class="card">
  <p style="font-size:.88rem;color:var(--muted);margin-bottom:6px">Consented, non-test sessions only. Quitters included (completion status is a column).</p>
  <a class="dl" href="api/export.php?type=sessions">⬇ sessions.csv</a>
  <a class="dl" href="api/export.php?type=trials">⬇ trials.csv</a>
  <a class="dl" href="api/export.php?type=dprime">⬇ dprime_inputs.csv</a>
</div>

<footer>Prize contacts live in a separate database and never appear here · TEST_MODE lock is managed via Azure app settings</footer>
</div></body></html>
