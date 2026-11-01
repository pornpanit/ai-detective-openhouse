<?php
// GET/POST /admin.php — researcher dashboard v6 (read-only data + test-flag controls).
// Separate Investigation 1 / 2 sections, confusion matrix, speed-accuracy scatter,
// accuracy distribution by generation, survey panel, JS toggle fix.
declare(strict_types=1);
require __DIR__ . '/api/lib.php';

$pw = cfg()['admin_pw'] ?? 'adminlocal';
$cookieOk = ($_COOKIE['aidtect_admin'] ?? '') === hash('sha256', $pw . '|aidtect');
if (!$cookieOk && ($_REQUEST['pw'] ?? '') === $pw) {
    setcookie('aidtect_admin', hash('sha256', $pw . '|aidtect'), ['httponly' => true, 'samesite' => 'Lax']);
    header('Location: admin.php'); exit;
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

/* ---- test-flag actions (JS fetch calls these) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['api'] ?? '') === '1') {
    header('Content-Type: application/json');
    $db = db_research();
    $in = json_decode(file_get_contents('php://input'), true) ?? [];
    $act = $in['action'] ?? '';
    if ($act === 'flag_all') {
        $db->exec("UPDATE sessions SET is_test = 1 WHERE is_test = 0");
    } elseif ($act === 'toggle' && preg_match('/^[0-9a-f]{24}$/', $in['session_id'] ?? '')) {
        $st = $db->prepare("UPDATE sessions SET is_test = 1 - is_test WHERE session_id = ?");
        $st->execute([$in['session_id']]);
    }
    echo json_encode(['ok' => true]); exit;
}

$db = db_research();
$one = fn(string $sql) => $db->query($sql)->fetchAll();

/* ═══ OVERVIEW ═══ */
$tot = $db->query("SELECT COUNT(*) n,
    SUM(consent=1 AND is_test=0) consented, SUM(consent=0 AND is_test=0) declined,
    SUM(consent IS NULL AND is_test=0) unanswered,
    SUM(completed=1 AND is_test=0) completed,
    SUM(consent=1 AND completed=0 AND is_test=0) consented_quitters,
    SUM(is_test=1) test_hidden FROM sessions")->fetch();
$trialsAll = $one("SELECT COUNT(*) n, ROUND(AVG(rt_ms)) avg_rt, ROUND(100*AVG(correct),1) pct_correct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.is_test=0 AND t.round IN ('1','2')");

/* ═══ AUDIENCE ═══ */
$byCohort = $one("SELECT cohort, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY cohort ORDER BY cohort");
$byGender = $one("SELECT CASE WHEN gender IN ('Female','Male') THEN gender ELSE 'Non-binary/other' END g,
    COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY g ORDER BY n DESC");
$byLang = $one("SELECT lang, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY lang ORDER BY lang");
$byHour = $one("SELECT HOUR(created_at) h, COUNT(*) n FROM sessions WHERE is_test=0 GROUP BY h ORDER BY h");
$durations = $one("SELECT TIMESTAMPDIFF(SECOND, s.created_at, s.finished_at) secs
    FROM sessions s WHERE s.finished_at IS NOT NULL AND s.completed=1 AND s.is_test=0");
$quits = $one("SELECT s.session_id,
    COALESCE(MAX(CASE t.round WHEN 'practice' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 END), -1) furthest
    FROM sessions s LEFT JOIN trials t ON t.session_id=s.session_id
    WHERE s.finished_at IS NOT NULL AND s.completed=0 AND s.is_test=0 GROUP BY s.session_id");
$quitBuckets = ['never answered'=>0,'practice'=>0,'round 1'=>0,'round 2'=>0];
foreach ($quits as $q) { $k = ['never answered','practice','round 1','round 2'][$q['furthest']+1] ?? 'never'; $quitBuckets[$k]++; }
$funnel = [
    ['started', (int)$tot['n'] - (int)$tot['test_hidden']],
    ['completed', (int)$tot['completed']],
    ['consented', (int)$tot['consented']],
    ['on the board', (int)$db->query("SELECT COUNT(*) FROM sessions WHERE completed=1 AND alias IS NOT NULL AND is_test=0")->fetchColumn()],
];

/* ═══ INVESTIGATION 1 (2AFC) ═══ */
// AI faces spotted per type (gan/diffusion ONLY — "real" rows in R1 = wrong answers, excluded by design)
$r1Spotted = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='1' AND t.image_type IN ('gan','diffusion')
    GROUP BY t.image_type ORDER BY t.image_type");
$r1RtHist = $one("SELECT
    SUM(t.rt_ms<1000) b1, SUM(t.rt_ms>=1000 AND t.rt_ms<2000) b2,
    SUM(t.rt_ms>=2000 AND t.rt_ms<3000) b3, SUM(t.rt_ms>=3000 AND t.rt_ms<5000) b4,
    SUM(t.rt_ms>=5000 AND t.rt_ms<8000) b5, SUM(t.rt_ms>=8000) b6
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='1'");
$r1RtByType = $one("SELECT t.image_type, ROUND(AVG(t.rt_ms)) avg_rt, COUNT(*) n
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='1' GROUP BY t.image_type ORDER BY t.image_type");
$r1AccByCohort = $one("SELECT s.cohort, ROUND(100*AVG(t.correct),1) pct, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round='1'
    WHERE s.consent=1 AND s.is_test=0 GROUP BY s.cohort ORDER BY s.cohort");

/* ═══ INVESTIGATION 2 (AI/Real judgment) ═══ */
$r2HitCR = $one("SELECT t.image_type, COUNT(*) n, ROUND(100*AVG(t.correct),1) pct
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='2' GROUP BY t.image_type ORDER BY t.image_type");
$r2RtHist = $one("SELECT
    SUM(t.rt_ms<1000) b1, SUM(t.rt_ms>=1000 AND t.rt_ms<2000) b2,
    SUM(t.rt_ms>=2000 AND t.rt_ms<3000) b3, SUM(t.rt_ms>=3000 AND t.rt_ms<5000) b4,
    SUM(t.rt_ms>=5000 AND t.rt_ms<8000) b5, SUM(t.rt_ms>=8000) b6
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='2'");
$r2RtByType = $one("SELECT t.image_type, ROUND(AVG(t.rt_ms)) avg_rt, COUNT(*) n
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='2' GROUP BY t.image_type ORDER BY t.image_type");
$r2AccByCohort = $one("SELECT s.cohort, ROUND(100*AVG(t.correct),1) pct, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round='2'
    WHERE s.consent=1 AND s.is_test=0 GROUP BY s.cohort ORDER BY s.cohort");
$bias = $one("SELECT s.cohort, ROUND(100*AVG(t.response),1) pct_ai, COUNT(*) n
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round='2'
    WHERE s.consent=1 AND s.is_test=0 GROUP BY s.cohort ORDER BY s.cohort");
// Confusion matrix (R2 only): TP=AI said AI, FN=AI said Real, FP=Real said AI, TN=Real said Real
$cm = $db->query("SELECT
    SUM(CASE WHEN t.image_type IN ('gan','diffusion') AND t.correct=1 THEN 1 ELSE 0 END) tp,
    SUM(CASE WHEN t.image_type IN ('gan','diffusion') AND t.correct=0 THEN 1 ELSE 0 END) fn,
    SUM(CASE WHEN t.image_type='real' AND t.correct=0 THEN 1 ELSE 0 END) fp,
    SUM(CASE WHEN t.image_type='real' AND t.correct=1 THEN 1 ELSE 0 END) tn
    FROM trials t JOIN sessions s ON s.session_id=t.session_id
    WHERE s.consent=1 AND s.is_test=0 AND t.round='2'")->fetch();

/* ═══ PERFORMANCE ANALYSIS ═══ */
// Speed-accuracy scatter (per participant)
$scatter = $one("SELECT s.session_id, s.cohort, s.alias,
    ROUND(100*AVG(t.correct),1) acc, ROUND(AVG(t.rt_ms)) rt
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.is_test=0 AND s.completed=1
    GROUP BY s.session_id, s.cohort, s.alias");
// Accuracy distribution by generation (bins)
$accDist = $one("SELECT s.cohort,
    ROUND(100*SUM(t.correct)/COUNT(*),0) acc
    FROM sessions s JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    WHERE s.consent=1 AND s.is_test=0 AND s.completed=1
    GROUP BY s.session_id, s.cohort");
$accBins = ['genx'=>['0-40'=>0,'40-60'=>0,'60-80'=>0,'80-100'=>0],
            'millennial'=>['0-40'=>0,'40-60'=>0,'60-80'=>0,'80-100'=>0],
            'genz'=>['0-40'=>0,'40-60'=>0,'60-80'=>0,'80-100'=>0]];
foreach ($accDist as $r) {
    $c = $r['cohort'] ?? 'other';
    if (!isset($accBins[$c])) continue;
    $a = (int)$r['acc'];
    $bin = $a < 40 ? '0-40' : ($a < 60 ? '40-60' : ($a < 80 ? '60-80' : '80-100'));
    $accBins[$c][$bin]++;
}

/* ═══ SURVEY DATA ═══ */
$surveyQs = [
    1 => ['label' => 'Encountered AI images', 'options' => ['No','Yes']],
    2 => ['label' => 'AI image frequency', 'options' => ['—','Rarely','Monthly','Weekly','Daily','Always']],
    3 => ['label' => 'Used AI to create', 'options' => ['No','Yes']],
    4 => ['label' => 'AI creation frequency', 'options' => ['—','Rarely','Monthly','Weekly','Daily','Always']],
    5 => ['label' => 'Work/study AI-related', 'options' => ['No','Yes']],
    6 => ['label' => 'AI for work/study', 'options' => ['No','Yes']],
    7 => ['label' => 'Work AI frequency', 'options' => ['—','Rarely','Monthly','Weekly','Daily','Always']],
    8 => ['label' => 'Self-rated AI familiarity', 'options' => ['—','1 Not','2 Slight','3 Mod','4 Very','5 Extreme']],
];
$surveyData = [];
for ($q = 1; $q <= 8; $q++) {
    $rows = $one("SELECT familiarity_$q as val, COUNT(*) n FROM sessions
        WHERE consent=1 AND is_test=0 AND familiarity_$q IS NOT NULL GROUP BY familiarity_$q ORDER BY familiarity_$q");
    $surveyData[$q] = $rows;
}

/* ═══ RECENT SESSIONS ═══ */
$recent = $one("SELECT s.session_id, s.lang, s.cohort, s.alias, s.consent, s.completed, s.is_test,
    s.created_at, COUNT(t.id) trials, COALESCE(SUM(t.correct),0) correct
    FROM sessions s LEFT JOIN trials t ON t.session_id=s.session_id AND t.round IN ('1','2')
    GROUP BY s.session_id ORDER BY s.created_at DESC LIMIT 20");

/* ═══ HELPERS ═══ */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function emptyState() { return '<div class="empty">waiting for data…</div>'; }
function bars(array $data, string $suffix=''): string {
    if (!array_filter($data, fn($d)=>$d[1]>0)) return emptyState();
    $max = max(1, max(array_map(fn($d)=>(float)$d[1], $data)));
    $out = '';
    foreach ($data as [$label,$value]) {
        $w = round(100*(float)$value/$max,1);
        $out .= "<div class='bar-row'><div class='bar-label'>".h((string)$label)."</div>"
              . "<div class='bar-track'><div class='bar' style='width:{$w}%'></div></div>"
              . "<div class='bar-val'>".h((string)$value).h($suffix)."</div></div>";
    }
    return $out;
}
function pie(array $data): string {
    $total = array_sum(array_map(fn($d)=>(float)$d[1], $data));
    if ($total < 1) return emptyState();
    $colors = ['#8c5b2f','#3f7cac','#c98d4b','#5f8f5e','#a33b2e','#7a6ba3'];
    $pos=0; $stops=[];
    foreach ($data as $i=>[$label,$value]) {
        $pct = 100*(float)$value/$total;
        $stops[] = $colors[$i%count($colors)]." {$pos}% ".round($pos+$pct,2)."%";
        $pos += $pct;
    }
    $legend='';
    foreach ($data as $i=>[$label,$value]) {
        $legend .= "<div class='lg-row'><span class='lg-dot' style='background:{$colors[$i%count($colors)]}'></span>".h((string)$label)." <b>".h((string)$value)."</b></div>";
    }
    return "<div class='pie-wrap'><div class='pie' style='background:conic-gradient(".implode(',',$stops).")'></div><div class='pie-legend'>{$legend}</div></div>";
}
header('Content-Type: text/html; charset=utf-8');

// scatter plot bounds
$scatterMaxRt = max(500, ...array_map(fn($r)=>(int)$r['rt'], $scatter));
$cohortColors = ['genx'=>'#a33b2e','millennial'=>'#3f7cac','genz'=>'#5f8f5e','other'=>'#7a6ba3'];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="60">
<title>Admin — AI Detective</title>
<link rel="icon" href="assets/icon.png" type="image/png">
<style>
:root{--bg:#f0ede4;--surface:#fffdf8;--line:#e3dcc9;--ink:#2b2a26;--muted:#7a7466;
--accent:#8c5b2f;--good:#4e8d5b;--bad:#a33b2e;--cyan:#3f7cac;--gold:#f5c542}
*{box-sizing:border-box;margin:0}
body{background:var(--bg);color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,"Noto Sans Thai",sans-serif;padding:0 0 60px}
.wrap{max-width:1100px;margin:0 auto;padding:0 18px}
header.top{background:var(--surface);border-bottom:1px solid var(--line);padding:14px 0 12px;margin-bottom:18px}
.brand{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.brand img{width:40px;height:40px;border-radius:10px}
.brand h1{font-size:1.2rem;font-weight:700}
.brand .sub{color:var(--muted);font-size:.82rem}
.livepill{margin-left:auto;display:inline-flex;align-items:center;gap:7px;font-size:.75rem;font-weight:700;letter-spacing:.14em;color:var(--good);border:1.5px solid var(--good);border-radius:999px;padding:5px 13px}
.livepill i{width:8px;height:8px;border-radius:50%;background:var(--good);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
h2.sec{font-size:.82rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--accent);margin:32px 0 12px;display:flex;align-items:center;gap:10px}
h2.sec::after{content:"";flex:1;height:1px;background:var(--line)}
h2.sec .tag{font-size:.68rem;letter-spacing:0;text-transform:none;font-weight:400;color:var(--muted)}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
.kpi{background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:0 1px 4px rgba(60,45,20,.08);padding:14px 12px;text-align:center}
.kpi b{display:block;font-size:1.8rem;font-weight:800;color:var(--accent)}
.kpi span{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.kpi.warn b{color:#c26b3a}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;box-shadow:0 1px 4px rgba(60,45,20,.08);padding:16px}
.card h3{font-size:.92rem;font-weight:700;margin-bottom:12px}
.card .note{font-size:.78rem;color:var(--muted);margin-top:10px}
.bar-row{display:flex;align-items:center;gap:10px;margin:7px 0}
.bar-label{width:130px;font-size:.8rem;text-align:right;color:var(--muted);flex:none}
.bar-track{flex:1;background:#ece6d6;border-radius:6px;overflow:hidden;height:22px}
.bar{background:linear-gradient(90deg,var(--accent),#c98d4b);height:100%;border-radius:6px;min-width:3px}
.bar-val{width:70px;font-size:.8rem;font-weight:700;flex:none;font-variant-numeric:tabular-nums}
.pie-wrap{display:flex;align-items:center;gap:20px;justify-content:center;flex-wrap:wrap;min-height:150px}
.pie{width:140px;height:140px;border-radius:50%;flex:none;box-shadow:inset 0 0 0 30px var(--surface)}
.lg-row{margin:4px 0;font-size:.85rem}
.lg-dot{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:8px}
.empty{color:var(--muted);font-size:.85rem;text-align:center;padding:34px 10px;border:1.5px dashed var(--line);border-radius:10px}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.83rem}
th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;padding:8px 10px;border-bottom:2px solid var(--line)}
td{padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
tr:hover td{background:#f7f1e3}
.badge{display:inline-block;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:999px}
.badge.test{background:#f4e3d2;color:#c26b3a;border:1px solid #c26b3a}
.badge.cons{background:#e2eee4;color:var(--good);border:1px solid var(--good)}
.badge.nocons{background:#f3e0de;color:var(--bad);border:1px solid var(--bad)}
.explain{background:#fbf3dd;border:1px solid #e8d9a8;border-radius:10px;padding:10px 14px;font-size:.83rem;margin-bottom:14px}
.dl{display:inline-flex;align-items:center;gap:7px;margin:4px 8px 0 0;padding:10px 16px;background:var(--surface);border:1.5px solid var(--accent);border-radius:10px;color:var(--accent);font-weight:700;font-size:.83rem;text-decoration:none}
.dl:hover{background:#f4e9d2}
.mini{font:600 .7rem/1 sans-serif;padding:5px 10px;border-radius:8px;border:1.5px solid var(--line);background:transparent;color:var(--muted);cursor:pointer}
.mini:hover{border-color:var(--accent);color:var(--accent)}
/* scatter plot */
.scatter{position:relative;height:280px;background:var(--surface);border:1px solid var(--line);border-radius:10px;margin:10px 0;overflow:hidden}
.scatter-dot{position:absolute;width:12px;height:12px;border-radius:50%;transform:translate(-50%,50%);opacity:.75;cursor:pointer;transition:opacity .2s}
.scatter-dot:hover{opacity:1;transform:translate(-50%,50%) scale(1.4)}
.scatter-axis{position:absolute;font-size:.68rem;color:var(--muted)}
/* confusion matrix */
.cm-grid{display:grid;grid-template-columns:auto 1fr 1fr;gap:2px;margin:10px 0}
.cm-cell{padding:14px 10px;text-align:center;border-radius:8px}
.cm-head{background:var(--bg);font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;padding:10px}
.cm-label{background:var(--bg);font-size:.72rem;font-weight:700;color:var(--muted);display:flex;align-items:center;justify-content:flex-end;padding:0 12px 0 0;text-transform:uppercase}
.cm-good{background:#e2eee4;border:1px solid var(--good)}
.cm-bad{background:#f3e0de;border:1px solid var(--bad)}
.cm-cell b{display:block;font-size:1.6rem;font-weight:800}
.cm-cell span{font-size:.7rem;color:var(--muted)}
/* grouped bar chart */
.gbar-group{display:flex;gap:4px;align-items:flex-end;height:80px;margin:0 auto}
.gbar{width:28px;border-radius:4px 4px 0 0;min-height:2px;position:relative}
.gbar span{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:.68rem;font-weight:700;color:var(--muted)}
.gbar-legend{display:flex;gap:14px;justify-content:center;margin-top:14px;font-size:.78rem}
/* survey grid */
.survey-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
.survey-q{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px}
.survey-q h4{font-size:.8rem;font-weight:700;margin-bottom:8px;color:var(--ink)}
footer{margin-top:34px;color:var(--muted);font-size:.75rem;text-align:center}
@media(max-width:640px){.bar-label{width:90px;font-size:.72rem}.kpi b{font-size:1.4rem}}
</style></head><body>

<header class="top"><div class="wrap brand">
  <img src="assets/icon.png" alt="">
  <div><h1>AI Detective — Research Dashboard</h1><div class="sub">auto-refresh 60s · excludes 🧪 test sessions</div></div>
  <span class="livepill"><i></i>LIVE EVENT VIEW</span>
</div></header>

<div class="wrap">

<!-- ═══ OVERVIEW ═══ -->
<h2 class="sec">Overview</h2>
<div class="kpis">
  <div class="kpi"><b><?= h($tot['n']-$tot['test_hidden']) ?></b><span>sessions</span></div>
  <div class="kpi"><b><?= h($tot['completed']) ?></b><span>completed</span></div>
  <div class="kpi"><b><?= h($tot['consented']) ?></b><span>consented</span></div>
  <div class="kpi"><b><?= h($trialsAll[0]['pct_correct'] ?? 0) ?>%</b><span>accuracy</span></div>
  <div class="kpi"><b><?= h($trialsAll[0]['avg_rt'] ?? 0) ?>ms</b><span>mean RT</span></div>
  <div class="kpi warn"><b><?= h($tot['test_hidden']) ?></b><span>🧪 hidden</span></div>
</div>

<?php if ((int)$tot['n'] > 0): ?>
<div class="card" style="margin-top:14px">
  <h3>Event-day prep</h3>
  <div class="explain" style="margin:0">🧪 <b>Test sessions</b> hidden from board & exports — visible here only.</div>
  <div style="margin-top:10px">
    <button class="dl" onclick="apiAction('flag_all')">🧪 Hide all existing sessions</button>
    <a class="dl" href="live.html" target="_blank">📺 Open LIVE board</a>
  </div>
</div>
<?php endif; ?>

<!-- ═══ AUDIENCE ═══ -->
<h2 class="sec">Who joined</h2>
<div class="grid">
  <div class="card"><h3>Generation cohort</h3><?= pie(array_map(fn($r)=>[$r['cohort'],$r['n']],$byCohort)) ?></div>
  <div class="card"><h3>Participant gender</h3><?= pie(array_map(fn($r)=>[$r['g'],$r['n']],$byGender)) ?></div>
  <div class="card"><h3>Language</h3><?= pie(array_map(fn($r)=>[$r['lang'],$r['n']],$byLang)) ?></div>
  <div class="card"><h3>Joins by hour</h3><?= bars(array_map(fn($r)=>[str_pad((string)$r['h'],2,"0",STR_PAD_LEFT).":00",$r['n']],$byHour)) ?></div>
  <div class="card"><h3>Conversion funnel</h3><?= bars($funnel) ?><div class="note">started → completed → consented → board</div></div>
  <div class="card"><h3>Where players quit</h3><?= bars(array_map(fn($k,$v)=>[$k,$v],array_keys($quitBuckets),$quitBuckets)) ?></div>
</div>

<!-- ═══ INVESTIGATION 1 ═══ -->
<h2 class="sec">Investigation 1 <span class="tag">2AFC — "Which face is AI?" (tap one of two)</span></h2>
<div class="explain"><b>How to read:</b> each trial shows 2 faces (1 AI + 1 real). Players tap the AI one.
<b>% spotted</b> = how often they correctly identified the AI face. Wrong answers (tapping the real face)
are implicit (100% − spotted%). No "real accuracy" exists in this round by design.</div>
<div class="grid">
  <div class="card"><h3>AI faces spotted by type (%)</h3>
    <?= bars(array_map(fn($r)=>["{$r['image_type']} (n={$r['n']})",$r['pct']],$r1Spotted),'%') ?>
    <div class="note">gan = StyleGAN2 · diffusion = Stable Diffusion 1.5</div></div>
  <div class="card"><h3>Accuracy by cohort (%)</h3>
    <?= bars(array_map(fn($r)=>["{$r['cohort']} (n={$r['n']})",$r['pct']],$r1AccByCohort),'%') ?></div>
  <?php if ($r1RtHist): $r=$r1RtHist[0]; ?>
  <div class="card"><h3>RT distribution (ms)</h3>
    <?php $rtRows=[['<1s',$r['b1']],['1–2s',$r['b2']],['2–3s',$r['b3']],['3–5s',$r['b4']],['5–8s',$r['b5']],['8s+',$r['b6']]];
    echo bars($rtRows); ?></div>
  <?php else: ?><div class="card"><h3>RT distribution</h3><?= emptyState() ?></div><?php endif; ?>
  <div class="card"><h3>Mean RT by image type (ms)</h3>
    <?= bars(array_map(fn($r)=>["{$r['image_type']} (n={$r['n']})",$r['avg_rt']],$r1RtByType)) ?></div>
</div>

<!-- ═══ INVESTIGATION 2 ═══ -->
<h2 class="sec">Investigation 2 <span class="tag">Single face — "AI or Real?" (binary judgment)</span></h2>
<div class="explain"><b>How to read:</b> each trial shows 1 face. Players answer AI or Real.
<b>Hit rate</b> = correctly said "AI" on AI faces. <b>Correct rejection</b> = correctly said "Real" on real faces.</div>
<div class="grid">
  <div class="card"><h3>Hit rate & correct rejection (%)</h3>
    <?= bars(array_map(fn($r)=>["{$r['image_type']} (n={$r['n']})",$r['pct']],$r2HitCR),'%') ?>
    <div class="note">gan/diffusion = hit rate (AI→"AI") · real = correct rejection (real→"Real")</div></div>
  <div class="card"><h3>Accuracy by cohort (%)</h3>
    <?= bars(array_map(fn($r)=>["{$r['cohort']} (n={$r['n']})",$r['pct']],$r2AccByCohort),'%') ?></div>
  <div class="card"><h3>Response bias — % labeled AI</h3>
    <?= bars(array_map(fn($r)=>["{$r['cohort']} (n={$r['n']})",$r['pct_ai']],$bias),'%') ?>
    <div class="note">50% = perfectly unbiased</div></div>
  <?php if ($r2RtHist): $r=$r2RtHist[0]; ?>
  <div class="card"><h3>RT distribution (ms)</h3>
    <?php $rtRows2=[['<1s',$r['b1']],['1–2s',$r['b2']],['2–3s',$r['b3']],['3–5s',$r['b4']],['5–8s',$r['b5']],['8s+',$r['b6']]];
    echo bars($rtRows2); ?></div>
  <?php else: ?><div class="card"><h3>RT distribution</h3><?= emptyState() ?></div><?php endif; ?>
  <div class="card"><h3>Mean RT by image type (ms)</h3>
    <?= bars(array_map(fn($r)=>["{$r['image_type']} (n={$r['n']})",$r['avg_rt']],$r2RtByType)) ?></div>
</div>

<!-- Confusion Matrix -->
<div class="card" style="margin-top:14px">
  <h3>Confusion Matrix — Investigation 2</h3>
  <?php $cmTotal = max(1, (int)$cm['tp'] + (int)$cm['fn'] + (int)$cm['fp'] + (int)$cm['tn']); ?>
  <div class="cm-grid">
    <div class="cm-head"></div>
    <div class="cm-head">Said "AI"</div>
    <div class="cm-head">Said "Real"</div>
    <div class="cm-label">AI face<br><small>(truth)</small></div>
    <div class="cm-cell cm-good"><b><?= round(100*(int)$cm['tp']/$cmTotal) ?>%</b><span>Hit ✓ (TP)<br><?= (int)$cm['tp'] ?> trials</span></div>
    <div class="cm-cell cm-bad"><b><?= round(100*(int)$cm['fn']/$cmTotal) ?>%</b><span>Miss ✗ (FN)<br><?= (int)$cm['fn'] ?> trials</span></div>
    <div class="cm-label">Real face<br><small>(truth)</small></div>
    <div class="cm-cell cm-bad"><b><?= round(100*(int)$cm['fp']/$cmTotal) ?>%</b><span>False alarm ✗ (FP)<br><?= (int)$cm['fp'] ?> trials</span></div>
    <div class="cm-cell cm-good"><b><?= round(100*(int)$cm['tn']/$cmTotal) ?>%</b><span>Correct reject ✓ (TN)<br><?= (int)$cm['tn'] ?> trials</span></div>
  </div>
</div>

<!-- ═══ PERFORMANCE ANALYSIS ═══ -->
<h2 class="sec">Performance Analysis</h2>
<div class="grid">
  <div class="card" style="grid-column:1/-1">
    <h3>Speed–Accuracy Tradeoff <span class="tag" style="font-size:.7rem;color:var(--muted)">(each dot = 1 participant)</span></h3>
    <?php if (count($scatter) < 2): ?>
      <?= emptyState() ?>
    <?php else: ?>
    <div class="scatter" id="scatter">
      <?php foreach ($scatter as $s): ?>
        <?php $x = min(96, max(2, round(100*(int)$s['rt']/$scatterMaxRt)));
              $y = min(95, max(2, 100-(int)$s['acc']));
              $color = $cohortColors[$s['cohort']] ?? '#7a6ba3'; ?>
        <div class="scatter-dot" style="left:<?= $x ?>%;bottom:<?= 100-$y ?>%;background:<?= $color ?>"
             title="<?= h($s['alias']) ?> · <?= h($s['cohort']) ?> · <?= h($s['acc']) ?>% · <?= h($s['rt']) ?>ms"></div>
      <?php endforeach; ?>
      <div class="scatter-axis" style="bottom:4px;left:50%">← faster &nbsp;&nbsp; Mean RT (ms) &nbsp;&nbsp; slower →</div>
      <div class="scatter-axis" style="left:4px;top:50%;transform:rotate(-90deg) translateX(50%);transform-origin:left">↑ more accurate</div>
    </div>
    <div class="gbar-legend">
      <?php foreach ($cohortColors as $c=>$col): if ($c==='other') continue; ?>
        <span><span class="lg-dot" style="background:<?= $col ?>"></span><?= ucfirst($c) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>Accuracy distribution by generation</h3>
    <?php
    $binLabels = ['0-40','40-60','60-80','80-100'];
    $genColors = ['genx'=>'#a33b2e','millennial'=>'#3f7cac','genz'=>'#5f8f5e'];
    $maxCount = 1;
    foreach ($accBins as $g=>$bins) foreach ($bins as $c) $maxCount = max($maxCount,$c);
    ?>
    <?php if ($maxCount <= 1 && array_sum(array_map('array_sum',$accBins)) < 2): ?>
      <?= emptyState() ?>
    <?php else: ?>
    <div style="display:flex;gap:16px;align-items:flex-end;height:100px;padding:0 10px">
      <?php foreach ($binLabels as $bin): ?>
      <div style="flex:1;text-align:center">
        <div class="gbar-group">
          <?php foreach ($genColors as $g=>$col): ?>
          <div class="gbar" style="height:<?= max(2,round(70*($accBins[$g][$bin]??0)/$maxCount)) ?>px;background:<?= $col ?>">
            <span><?= $accBins[$g][$bin] ?? 0 ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:.7rem;color:var(--muted);margin-top:4px"><?= $bin ?>%</div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="gbar-legend">
      <?php foreach ($genColors as $g=>$col): ?>
        <span><span class="lg-dot" style="background:<?= $col ?>"></span><?= ucfirst($g) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ SURVEY ═══ -->
<h2 class="sec">Background Survey <span class="tag">(consented participants)</span></h2>
<div class="survey-grid">
  <?php foreach ($surveyQs as $qNum=>$qInfo): ?>
  <div class="survey-q">
    <h4>Q<?= $qNum ?>. <?= h($qInfo['label']) ?></h4>
    <?php
    $qData = $surveyData[$qNum] ?? [];
    if (empty($qData)) { echo emptyState(); }
    else {
      $total = array_sum(array_map(fn($r)=>(int)$r['n'],$qData));
      foreach ($qData as $r) {
        $optIdx = (int)$r['val'];
        $optLabel = $qInfo['options'][$optIdx] ?? "val {$optIdx}";
        $pct = round(100*(int)$r['n']/$total);
        echo "<div style='display:flex;align-items:center;gap:8px;margin:4px 0;font-size:.78rem'>";
        echo "<span style='width:70px;text-align:right;color:var(--muted)'>{$optLabel}</span>";
        echo "<div style='flex:1;background:#ece6d6;border-radius:4px;height:14px;overflow:hidden'>";
        echo "<div style='width:{$pct}%;height:100%;background:var(--accent);border-radius:4px'></div></div>";
        echo "<span style='width:45px;font-weight:700'>{$pct}%</span></div>";
      }
    }
    ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ SESSIONS ═══ -->
<h2 class="sec">Sessions <span class="tag">(newest 20 · 🧪 = hidden from public)</span></h2>
<div class="card">
<?php if (!$recent): ?><?= emptyState() ?><?php else: ?>
<div class="tbl-wrap"><table>
<thead><tr><th>session</th><th>lang</th><th>codename</th><th>cohort</th><th>trials</th><th>correct</th><th>consent</th><th>done</th><th>test</th><th>created</th></tr></thead>
<tbody>
<?php foreach ($recent as $r): ?>
<tr>
  <td><?= h(substr($r['session_id'],0,10)) ?>…</td>
  <td><?= h($r['lang']) ?></td>
  <td><b><?= h($r['alias']) ?></b></td>
  <td><?= h($r['cohort']) ?></td>
  <td><?= h($r['trials']) ?></td>
  <td><?= h($r['correct']) ?></td>
  <td><?php if ($r['consent']==='1') echo '<span class="badge cons">yes</span>';
           elseif ($r['consent']==='0') echo '<span class="badge nocons">no</span>';
           else echo '—'; ?></td>
  <td><?= $r['completed']==='1' ? '✓' : 'quit' ?></td>
  <td>
    <?php if ($r['is_test']==='1') echo '<span class="badge test">🧪</span> '; ?>
    <button class="mini" onclick="toggleTest('<?= h($r['session_id']) ?>',this)"><?= $r['is_test']==='1'?'un-hide':'hide' ?></button>
  </td>
  <td><?= h($r['created_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</div>

<!-- ═══ DOWNLOADS ═══ -->
<h2 class="sec">Research exports</h2>
<div class="card">
  <p style="font-size:.85rem;color:var(--muted);margin-bottom:6px">Consented, non-test sessions only.</p>
  <a class="dl" href="api/export.php?type=sessions">⬇ sessions.csv</a>
  <a class="dl" href="api/export.php?type=trials">⬇ trials.csv</a>
  <a class="dl" href="api/export.php?type=dprime">⬇ dprime_inputs.csv</a>
</div>

<footer>Prize contacts live in a separate database · TEST_MODE managed via Azure settings</footer>
</div>

<script>
async function apiAction(action, sessionId) {
  const body = sessionId ? {action, session_id: sessionId} : {action};
  try {
    await fetch('admin.php?api=1', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
    location.reload();
  } catch(e) { alert('Action failed — please refresh'); }
}
async function toggleTest(sessionId, btn) {
  await apiAction('toggle', sessionId);
}
</script>
</body></html>
