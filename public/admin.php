<?php
// Minimal admin page — isolate 404 issue
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
    echo '<h1>Admin — Login</h1><form method="post"><input type="password" name="pw"><button>Login</button></form>';
    exit;
}

// Authenticated — test each section
header('Content-Type: text/html; charset=utf-8');
echo '<h1>Admin Debug</h1>';

try {
    $db = db_research();
    echo '<p>DB connection: OK</p>';

    $n = $db->query("SELECT COUNT(*) FROM sessions")->fetchColumn();
    echo "<p>Total sessions: $n</p>";

    $n2 = $db->query("SELECT COUNT(*) FROM sessions WHERE is_test=0")->fetchColumn();
    echo "<p>Non-test sessions: $n2</p>";

    $cm = $db->query("SELECT
        SUM(CASE WHEN t.image_type IN ('gan','diffusion') AND t.correct=1 THEN 1 ELSE 0 END) tp,
        SUM(CASE WHEN t.image_type IN ('gan','diffusion') AND t.correct=0 THEN 1 ELSE 0 END) fn,
        SUM(CASE WHEN t.image_type='real' AND t.correct=0 THEN 1 ELSE 0 END) fp,
        SUM(CASE WHEN t.image_type='real' AND t.correct=1 THEN 1 ELSE 0 END) tn
        FROM trials t JOIN sessions s ON s.session_id=t.session_id
        WHERE s.consent=1 AND s.is_test=0 AND t.round='2'")->fetch();
    echo '<p>Confusion matrix: ' . json_encode($cm) . '</p>';

    $r1 = $db->query("SELECT
        CASE WHEN t.image_type IN ('gan','diffusion') THEN t.image_type
        ELSE CASE ai_img.source WHEN 'sd15' THEN 'diffusion' ELSE ai_img.source END
        END as ai_type,
        COUNT(*) n, ROUND(100*SUM(t.correct)/COUNT(*),1) pct
        FROM trials t
        JOIN sessions s ON s.session_id=t.session_id
        LEFT JOIN images ai_img ON ai_img.image_id = t.pair_image_id AND t.image_type = 'real'
        WHERE s.consent=1 AND s.is_test=0 AND t.round='1'
        GROUP BY ai_type ORDER BY ai_type")->fetchAll();
    echo '<p>R1 spotted: ' . json_encode($r1) . '</p>';

    echo '<p style="color:green;font-weight:bold">ALL QUERIES PASS</p>';
    echo '<p><a href="admin.php">← Back to full dashboard</a></p>';

} catch (Exception $e) {
    echo '<p style="color:red;font-weight:bold">ERROR: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>File: ' . htmlspecialchars($e->getFile()) . ' Line: ' . $e->getLine() . '</p>';
    echo '<p><a href="admin.php">← Back to full dashboard</a></p>';
}
