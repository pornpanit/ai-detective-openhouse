<?php
// GET /api/check-alias.php?alias=... — live codename availability check.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$alias = trim((string) ($_GET['alias'] ?? ''));
if (!preg_match('/^[\w\x{0E00}-\x{0E7F} ]{3,16}$/u', $alias)) {
    json_out(['ok' => true, 'available' => false]);
}
try {
    $db = db_research();
    $st = $db->prepare("SELECT COUNT(*) FROM sessions WHERE alias = ?");
    $st->execute([$alias]);
    json_out(['ok' => true, 'available' => (int) $st->fetchColumn() === 0]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'available' => null], 500);
}
