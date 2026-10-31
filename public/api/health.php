<?php
// GET /api/health.php — connectivity check for uptime monitoring.
// Exposes ONLY status: no schema names, table names, or data.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$ok = true;
try { db_research()->query("SELECT 1"); } catch (Throwable $e) { $ok = false; }
try { db_prize()->query("SELECT 1"); }    catch (Throwable $e) { $ok = false; }

http_response_code($ok ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => $ok]);
