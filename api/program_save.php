<?php
/**
 * API Endpoint: Direct Program Creation is Disabled (HTTP 403)
 * POST /api/program_save.php
 * Programs are synced automatically from Defect (D / G+D) boards on the Master Board (index.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/programs-data.php';

header('Content-Type: application/json; charset=utf-8');

http_response_code(403);
echo json_encode([
    'success'     => false,
    'status_code' => 403,
    'error'       => PROGRAM_CREATE_FORBIDDEN_ERROR,
    'errors'      => [PROGRAM_CREATE_FORBIDDEN_ERROR],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
