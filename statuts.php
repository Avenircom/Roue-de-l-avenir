<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['err' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 4096);
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['err' => 'bad_json']);
    exit;
}

$email      = mb_strtolower(trim((string)($payload['email'] ?? '')), 'UTF-8');
$campaignId = trim((string)($payload['campaign_id'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['err' => 'validation', 'field' => 'email']);
    exit;
}
if ($campaignId === '') {
    http_response_code(422);
    echo json_encode(['err' => 'validation', 'field' => 'campaign_id']);
    exit;
}

// même config DB que dans spin.php
$DB_HOST = 'localhost';
$DB_NAME = 'nuli4334_jeu_concours_grand_public';
$DB_USER = 'nuli4334_admin_jeu';
$DB_PASS = '[%0Y[6l8oEnV';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['err' => 'db_connect']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT prize_text, prize_win, spin_r_value, spun_at
      FROM users
     WHERE email = :email
     LIMIT 1
");
$stmt->execute([':email' => $email]);
$u = $stmt->fetch();

if (!$u) {
    http_response_code(404);
    echo json_encode(['err' => 'unknown_email']);
    exit;
}

if (!empty($u['spun_at'])) {
    echo json_encode([
        'already_spun'    => true,
        'final_prize_text' => $u['prize_text'],
        'final_win'       => (int)$u['prize_win'],
        'r_value'         => (float)$u['spin_r_value'],
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'already_spun' => false,
    ]);
}
