<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ====== Configuration globale ====== */
const GENERIC_CONSO_TEXT     = 'Pas de cadeau'; // filet si pas de lot en BDD

/* ====== Helpers ====== */
function respond(int $code, array $obj): void
{
    http_response_code($code);
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ====== Mail ====== */
const MAIL_FROM      = 'contact-avenir-communication@by-avcom.com';
const MAIL_FROM_NAME = 'Avenir Communication';
const MAIL_REPLY_TO  = 'contact-avenir-communication@by-avcom.com';

function is_valid_email(string $e): bool
{
    return (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}
function sanitize_header(string $s): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $s));
}

function send_result_mail(string $to, string $prize, int $win): bool
{
    if (!is_valid_email($to)) return false;
    $fromName = sanitize_header(MAIL_FROM_NAME);
    $fromAddr = sanitize_header(MAIL_FROM);
    $replyTo  = sanitize_header(MAIL_REPLY_TO);

    $subjectBase = $win ? 'Votre tirage : Bravo, c’est gagné' : 'Votre tirage : Merci pour votre participation';
    $subject = '=?UTF-8?B?' . base64_encode($subjectBase) . '?=';

    $lines = [];
    $lines[] = $win ? "Félicitations !" : "Merci d’avoir joué !";
    $lines[] = "";
    $lines[] = "Résultat : " . ($prize !== '' ? $prize : '—');
    $lines[] = $win
        ? "Notre équipe reviendra vers vous pour la remise du lot."
        : "Pas de cadeau";
    $lines[] = "";
    $lines[] = "Avenir Communication";
    $body = implode("\r\n", $lines);

    $headers = [];
    $headers[] = 'From: ' . sprintf('"%s" <%s>', $fromName, $fromAddr);
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $params = '-f' . $fromAddr;
    return @mail($to, $subject, $body, implode("\r\n", $headers), $params);
}

/* ====== INPUT ====== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['err' => 'method_not_allowed']);
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== 0) respond(415, ['err' => 'unsupported_media_type']);

$raw = file_get_contents('php://input', false, null, 0, 16384);
if ($raw === false || $raw === '') respond(400, ['err' => 'empty_body']);
$payload = json_decode($raw, true);
if (!is_array($payload)) respond(400, ['err' => 'bad_json']);

$email       = mb_strtolower(trim((string)($payload['email'] ?? '')), 'UTF-8');
$campaignId  = trim((string)($payload['campaign_id'] ?? ''));
$prizeFront  = trim((string)($payload['prize_text'] ?? ''));
$winFront    = !empty($payload['win']) ? 1 : 0; // on ne force plus
$rValue      = (float)($payload['r_value'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(422, ['err' => 'validation', 'field' => 'email']);
if ($campaignId === '') respond(422, ['err' => 'validation', 'field' => 'campaign_id']);
if ($prizeFront === '') respond(422, ['err' => 'validation', 'field' => 'prize_text']);
if (!is_finite($rValue) || $rValue <= 0) respond(422, ['err' => 'validation', 'field' => 'r_value']);
if (mb_strlen($prizeFront, 'UTF-8') > 190) $prizeFront = mb_substr($prizeFront, 0, 190, 'UTF-8');

/* ====== DB ====== */
$DB_HOST = 'localhost';
$DB_NAME = 'nuli4334_jeu_concours_grand_public';
$DB_USER = 'nuli4334_admin_jeu';
$DB_PASS = '[%0Y[6l8oEnV';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (Throwable $e) {
    respond(500, ['err' => 'db_connect']);
}

try {
    $pdo->beginTransaction();

    // 1) Verrouille l’utilisateur
    $sel = $pdo->prepare("SELECT id, email, prize_text, prize_win, spin_r_value, spun_at FROM users WHERE email = :email FOR UPDATE");
    $sel->execute([':email' => $email]);
    $u = $sel->fetch();

    if (!$u) {
        $pdo->rollBack();
        respond(403, ['err' => 'unknown_email']);
    }

    if (!empty($u['spun_at'])) {
        $same = ($u['prize_text'] === $prizeFront)
            && ((int)$u['prize_win'] === (int)$winFront)
            && ((string)$u['spin_r_value'] === (string)$rValue);
        $pdo->commit();
        if ($same) respond(208, ['status' => 'ok_duplicate']);
        respond(409, ['err' => 'already_spun']);
    }

    // 2) Récupère le lot de consolation
    $stmtCons = $pdo->prepare("
    SELECT id, prize_name, stock
      FROM prizes
     WHERE campaign_id = :cid AND is_consolation = 1
     LIMIT 1 FOR UPDATE
  ");
    $stmtCons->execute([':cid' => $campaignId]);
    $consRow = $stmtCons->fetch();
    if (!$consRow) $consRow = ['id' => null, 'prize_name' => GENERIC_CONSO_TEXT, 'stock' => null];

    // 3) Récupère le lot demandé par le front (peut être consolation ou non)
    $stmtPrize = $pdo->prepare("
    SELECT id, prize_name, is_consolation, stock
      FROM prizes
     WHERE campaign_id = :cid AND prize_name = :name
     LIMIT 1 FOR UPDATE
  ");
    $stmtPrize->execute([':cid' => $campaignId, ':name' => $prizeFront]);
    $prizeRow = $stmtPrize->fetch();

    $finalPrizeText = $prizeFront;
    $finalWin = $winFront; // base = verdict front, mais on sécurise avec les stocks

    // 4) Logique d’attribution :
    // - Si le front a tiré un lot gagnant (non consolation) ET qu’il reste du stock → gagnant + décrément
    // - Sinon → consolation (perdant) + (décrément si vous stockez la consolation)
    $remaining = null;

    $isFrontConso = $prizeRow ? ((int)$prizeRow['is_consolation'] === 1) : false;

    if ($prizeRow && !$isFrontConso) {
        // Candidat gagnant : décrémente si stock > 0
        $dec = $pdo->prepare("UPDATE prizes SET stock = stock - 1 WHERE id = :id AND stock > 0");
        $dec->execute([':id' => (int)$prizeRow['id']]);

        if ($dec->rowCount() === 1) {
            // OK gagnant
            $left = $pdo->prepare("SELECT stock FROM prizes WHERE id = :id");
            $left->execute([':id' => (int)$prizeRow['id']]);
            $remaining = (int)($left->fetch()['stock'] ?? 0);
            $finalPrizeText = (string)$prizeRow['prize_name'];
            $finalWin = 1;
        } else {
            // Rupture → consolation (perdu)
            $finalPrizeText = $consRow['prize_name'];
            $finalWin = 0;
            if (!empty($consRow['id']) && $consRow['stock'] !== null) {
                $pdo->prepare("UPDATE prizes SET stock = stock - 1 WHERE id = :id AND stock > 0")
                    ->execute([':id' => (int)$consRow['id']]);
            }
        }
    } else {
        // Front a tiré la consolation OU lot inconnu → consolation (perdu)
        $finalPrizeText = $consRow['prize_name'];
        $finalWin = 0;
        if (!empty($consRow['id']) && $consRow['stock'] !== null) {
            $pdo->prepare("UPDATE prizes SET stock = stock - 1 WHERE id = :id AND stock > 0")
                ->execute([':id' => (int)$consRow['id']]);
        }
    }

    // 5) Enregistre le tirage
    $upd = $pdo->prepare("
    UPDATE users
       SET prize_text   = :prize,
           prize_win    = :win,
           spin_r_value = :r,
           spun_at      = NOW()
     WHERE id = :id AND spun_at IS NULL
     LIMIT 1
  ");
    $upd->execute([
        ':prize' => $finalPrizeText,
        ':win'   => $finalWin,
        ':r'     => $rValue,
        ':id'    => (int)$u['id']
    ]);
    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        respond(409, ['err' => 'already_spun']);
    }

    $pdo->commit();

    // 6) Mail (non bloquant)
    try {
        $mailSent = send_result_mail($email, $finalPrizeText, $finalWin);
    } catch (Throwable $e) {
        $mailSent = false;
    }

    respond(200, [
        'status'           => 'ok',
        'mail'             => $mailSent ? 'sent' : 'skipped',
        'final_prize_text' => $finalPrizeText,
        'final_win'        => $finalWin,     // 0 si consolation, 1 sinon
        'remaining'        => $remaining,    // null si non applicable
        'campaign_id'      => $campaignId
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['err' => 'sql_error']);
}
