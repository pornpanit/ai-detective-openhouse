<?php
// POST /api/contact.php — prize draw contact. Writes ONLY to the prize database
// (separate credentials); no key is shared with research data.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method'], 405);

$in = read_json_body();
$alias = trim((string) ($in['alias'] ?? ''));
$contact = trim((string) ($in['contact'] ?? ''));
if (!preg_match('/^[\w\x{0E00}-\x{0E7F} ]{3,16}$/u', $alias) || $contact === "" || mb_strlen($contact) > 250) {
    json_out(['ok' => false, 'error' => 'input'], 400);
}

try {
    $db = db_prize();          // prize credentials CANNOT reach ai_detect_research
    $st = $db->prepare("INSERT INTO prize_contacts (alias, contact, created_at)
                        VALUES (?,?,NOW())
                        ON DUPLICATE KEY UPDATE contact = VALUES(contact)");
    $st->execute([$alias, $contact]);
    json_out(['ok' => true]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
