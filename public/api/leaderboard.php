<?php
// GET /api/leaderboard.php — Top Detectives (top 20, completed sessions with alias).
// Response contains ONLY alias + accuracy percent. No demographic or contact data.
declare(strict_types=1);
require __DIR__ . '/lib.php';

try {
    $db = db_research();
    $rows = $db->query(
        "SELECT s.alias AS alias,
                ROUND(100 * SUM(t.correct) / COUNT(t.id)) AS percent
         FROM sessions s
         JOIN trials t ON t.session_id = s.session_id AND t.round IN ('1','2')
         WHERE s.alias IS NOT NULL AND s.completed = 1 AND s.is_test = 0
         GROUP BY s.session_id, s.alias
         HAVING COUNT(t.id) >= 30
         ORDER BY percent DESC, MAX(t.created_at) ASC
         LIMIT 20")->fetchAll();
    $meta = $db->query(
        "SELECT COUNT(*) AS total,
                SUM(created_at >= CURDATE()) AS today,
                SUM(completed = 1) AS completed
         FROM sessions WHERE is_test = 0")->fetch();
    $top = $db->query(
        "SELECT MAX(pct) FROM (
            SELECT ROUND(100 * SUM(correct) / COUNT(*)) AS pct
            FROM trials WHERE round IN ('1','2') GROUP BY session_id
         ) x")->fetchColumn();
    json_out(['ok' => true,
              'board' => array_map(fn($r) => ['alias' => $r['alias'], 'percent' => (int) $r['percent']], $rows),
              'meta' => ['total' => (int) $meta['total'],
                         'today' => (int) ($meta['today'] ?? 0),
                         'completed' => (int) ($meta['completed'] ?? 0),
                         'top_score' => $top === null ? 0 : (int) $top]]);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => 'db'], 500);
}
