<?php
// Shared helpers for the experiment API.
declare(strict_types=1);

function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    foreach ([dirname(__DIR__, 2) . '/config/config.php', __DIR__ . '/../config/config.php'] as $f) {
        if (is_file($f)) { $c = require $f; return $c; }
    }
    // pure-environment configuration (cloud deployment; config/ not shipped)
    $mk = fn(string $p) => ['dsn' => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv("{$p}_HOST"), getenv("{$p}_PORT") ?: '3306', getenv("{$p}_NAME")),
        'user' => getenv("{$p}_USER"), 'pass' => getenv("{$p}_PASS")];
    $c = [
        'research' => $mk('RESEARCH_DB'),
        'prize' => $mk('PRIZE_DB'),
        'manifest' => getenv('MANIFEST_PATH') ?: __DIR__ . '/../assets/pool_manifest.csv',
        'window_minutes' => (int) (getenv('WINDOW_MINUTES') ?: 30),
        'admin_pw' => getenv('ADMIN_PW') ?: 'adminlocal',
    ];
    return $c;
}

function db_research(): PDO {
    $c = cfg()['research'];
    return new PDO($c['dsn'], $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function db_prize(): PDO {
    $c = cfg()['prize'];
    return new PDO($c['dsn'], $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function json_out(array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 200000) json_out(['ok' => false, 'error' => 'payload_too_large'], 413);
    $in = json_decode($raw ?: '', true);
    return is_array($in) ? $in : [];
}

function cohort_of(int $year): string {
    if ($year >= 1965 && $year <= 1980) return 'genx';
    if ($year >= 1981 && $year <= 1996) return 'millennial';
    if ($year >= 1997 && $year <= 2012) return 'genz';
    return 'other';
}

/** Idempotent ingest of the deployed pool manifest into images. */
function ensure_images_ingested(PDO $db): void {
    $n = (int) $db->query("SELECT COUNT(*) FROM images")->fetchColumn();
    if ($n > 0) return;
    $path = cfg()['manifest'];
    if (!is_file($path)) json_out(['ok' => false, 'error' => 'manifest_missing'], 500);
    $st = $db->prepare("INSERT IGNORE INTO images (image_id,file,source,psi,gender,gender_conf,role,phash)
                        VALUES (?,?,?,?,?,?,?,?)");
    $h = fopen($path, 'r');
    fgetcsv($h); // header
    while (($r = fgetcsv($h)) !== false) {
        $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], (float)$r[5], $r[6], $r[7]]);
    }
    fclose($h);
}

/**
 * Claim up to $count pool images from a cell, excluding images used within the
 * rolling window. Race-safe: an image is owned only if our guarded UPDATE
 * succeeds. Returns [['image_id'=>..,'file'=>..,'psi'=>..], ...].
 */
function claim_cell(PDO $db, string $source, string $gender, int $count): array {
    $win = (int) cfg()['window_minutes'];
    $pick = $db->prepare(
        "SELECT image_id, file, psi FROM images
         WHERE source = ? AND gender = ? AND role = 'pool'
           AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL $win MINUTE)
         ORDER BY RAND() LIMIT 8");
    $own = $db->prepare(
        "UPDATE images SET last_used_at = NOW()
         WHERE image_id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL $win MINUTE)");
    $out = [];
    $stages = $source === 'gan' ? ['0.5', '1'] : [''];
    while (count($out) < $count) {
        $stage = $stages[count($out) % count($stages)];          // alternate psi for GAN
        $params = $source === 'gan'
            ? [$source, $gender] : [$source, $gender];
        $pick->execute($params);
        $cands = array_filter($pick->fetchAll(), fn($c) => $stage === '' || $c['psi'] === $stage);
        if (!$cands && $stage !== '') {
            $pick->execute([$source, $gender]);                   // fall back to any psi
            $cands = $pick->fetchAll();
        }
        if (!$cands) json_out(['ok' => false, 'error' => 'pool_exhausted'], 503);
        $got = false;
        foreach ($cands as $c) {
            if (isset($out[$c['image_id']])) continue;
            $own->execute([$c['image_id']]);
            if ($own->rowCount() === 1) { $out[$c['image_id']] = $c; $got = true; break; }
        }
        if (!$got) json_out(['ok' => false, 'error' => 'pool_exhausted'], 503);
    }
    return array_values($out);
}

/**
 * Build a full trial plan (same for storage and for the client; client gets
 * file URLs, truth values live server-side only in the plan stored on the
 * session row — correctness is always computed here at save time).
 */
function generate_plan(PDO $db): array {
    $rnd = fn() => (bool) random_int(0, 1);
    $plan = ['round1' => [], 'round2' => [], 'practice' => []];

    // ---- Round 1: 12 same-gender pairs (6 M + 6 F). Per gender:
    //      real 6, gan 3, sd15 3.
    foreach (['Male', 'Female'] as $g) {
        $real = claim_cell($db, 'real', $g, 6);
        $gan  = claim_cell($db, 'gan', $g, 3);
        $sd   = claim_cell($db, 'sd15', $g, 3);
        $ai = array_merge($gan, $sd);                 // 6 AI faces this gender
        shuffle($ai);
        foreach ($ai as $i => $aimg) {
            $plan['round1'][] = [
                'gender' => $g,
                'ai'    => ['id' => $aimg['image_id'], 'file' => $aimg['file'],
                            'type' => $aimg['psi'] !== '' ? 'gan' : 'diffusion'],
                'real'  => ['id' => $real[$i]['image_id'], 'file' => $real[$i]['file'], 'type' => 'real'],
                'ai_side' => $rnd() ? 'left' : 'right',
            ];
        }
    }
    shuffle($plan['round1']);

    // ---- Round 2: 24 singles mirroring Round-1 allocation: per gender
    //      real 6, gan 3, sd15 3  ->  12 AI + 12 real overall.
    foreach (['Male', 'Female'] as $g) {
        foreach (claim_cell($db, 'real', $g, 6) as $img) {
            $plan['round2'][] = ['id' => $img['image_id'], 'file' => $img['file'], 'type' => 'real'];
        }
        foreach (claim_cell($db, 'gan', $g, 3) as $img) {
            $plan['round2'][] = ['id' => $img['image_id'], 'file' => $img['file'], 'type' => 'gan'];
        }
        foreach (claim_cell($db, 'sd15', $g, 3) as $img) {
            $plan['round2'][] = ['id' => $img['image_id'], 'file' => $img['file'], 'type' => 'diffusion'];
        }
    }
    shuffle($plan['round2']);

    // ---- Practice (role=practice images, never scored): 1 pair + 1 single.
    $pr = $db->query("SELECT image_id, file, source, psi, gender FROM images
                      WHERE role='practice'")->fetchAll();
    $one = fn(string $src, string $g) => array_values(array_filter($pr, fn($r) => $r['source'] === $src && $r['gender'] === $g));
    $pg = $one('gan', 'Male')[0]; $prl = $one('real', 'Male')[0]; $ps = $one('sd15', 'Female')[0];
    $plan['practice'] = [
        ['pair' => [
            'ai'   => ['id' => $pg['image_id'], 'file' => $pg['file'], 'type' => 'gan'],
            'real' => ['id' => $prl['image_id'], 'file' => $prl['file'], 'type' => 'real'],
            'ai_side' => $rnd() ? 'left' : 'right']],
        ['single' => ['id' => $ps['image_id'], 'file' => $ps['file'], 'type' => 'diffusion']],
    ];
    return $plan;
}
