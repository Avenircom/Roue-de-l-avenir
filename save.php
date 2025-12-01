<?php
// save.php — inscription/MAJ contact sans toucher aux champs de tirage,
//            mais bloque si un tirage existe déjà (spun_at non NULL)

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const MAX_JSON_BYTES       = 8 * 1024;
const RATE_LIMIT_MAX       = 5;
const RATE_LIMIT_WINDOW_S  = 600;

function respond(int $code, array $obj): void
{
  http_response_code($code);
  echo json_encode($obj, JSON_UNESCAPED_UNICODE);
  exit;
}

// 1) Méthode & Content-Type
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['err' => 'method_not_allowed']);
$ctype = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ctype, 'application/json') !== 0) respond(415, ['err' => 'unsupported_media_type']);

// 2) Anti cross-origin simple (même host)
$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host    = $_SERVER['HTTP_HOST']    ?? '';
if ($origin && parse_url($origin, PHP_URL_HOST) !== $host)  respond(403, ['err' => 'forbidden_origin']);
if ($referer && parse_url($referer, PHP_URL_HOST) !== $host) respond(403, ['err' => 'forbidden_referer']);

// 3) Lecture JSON bornée
$raw = file_get_contents('php://input', false, null, 0, MAX_JSON_BYTES + 1);
if ($raw === false || $raw === '') respond(400, ['err' => 'empty_body']);
if (strlen($raw) > MAX_JSON_BYTES) respond(413, ['err' => 'payload_too_large']);
try {
  $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
  respond(400, ['err' => 'bad_json']);
}
if (!is_array($data)) respond(400, ['err' => 'bad_json']);

// 4) Normalisation & validations
$prenom     = trim((string)($data['prenom']     ?? ''));
$nom        = trim((string)($data['nom']        ?? ''));
$email      = mb_strtolower(trim((string)($data['email'] ?? '')), 'UTF-8');
$entreprise = trim((string)($data['entreprise'] ?? ''));
$newsletter = !empty($data['newsletter']) ? 1 : 0;

$len = fn($s) => mb_strlen($s, 'UTF-8');
if ($prenom === '' || $nom === '' || $entreprise === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(422, ['err' => 'validation']);
}
if ($len($prenom) > 100 || $len($nom) > 100 || $len($entreprise) > 190 || $len($email) > 190) {
  respond(422, ['err' => 'validation']);
}

// 5) Connexion DB
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

// 6) Tables (à exécuter une fois si pas encore créées)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prenom        VARCHAR(100) NOT NULL,
    nom           VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    entreprise    VARCHAR(190) NOT NULL,
    newsletter    TINYINT(1) NOT NULL DEFAULT 0,
    ip            VARCHAR(100) NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    prize_text    VARCHAR(190) NULL,
    prize_win     TINYINT(1) NOT NULL DEFAULT 0,
    spin_r_value  DOUBLE NULL,
    spun_at       DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_email_spun (email, spun_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_created (ip, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// 7) Rate-limit (best-effort)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip=:ip AND created_at >= (NOW() - INTERVAL :win SECOND)");
  $stmt->execute([':ip' => $ip, ':win' => RATE_LIMIT_WINDOW_S]);
  if ((int)$stmt->fetchColumn() >= RATE_LIMIT_MAX) respond(429, ['err' => 'rate_limited']);
  $pdo->prepare("INSERT INTO rate_limits (ip) VALUES (:ip)")->execute([':ip' => $ip]);
} catch (Throwable $e) { /* non bloquant */
}

// 8) Transaction + verrou anti double participation si déjà tiré
try {
  $pdo->beginTransaction();

  $sel = $pdo->prepare("SELECT id, spun_at FROM users WHERE email = :email FOR UPDATE");
  $sel->execute([':email' => $email]);
  $row = $sel->fetch();

  if ($row && !empty($row['spun_at'])) {
    $pdo->rollBack();
    respond(409, ['err' => 'already_spun']);
  }

  $sql = "
    INSERT INTO users (prenom, nom, email, entreprise, newsletter, ip, user_agent)
    VALUES (:prenom, :nom, :email, :entreprise, :newsletter, :ip, :ua)
    ON DUPLICATE KEY UPDATE
      prenom=VALUES(prenom),
      nom=VALUES(nom),
      entreprise=VALUES(entreprise),
      newsletter=VALUES(newsletter),
      ip=VALUES(ip),
      user_agent=VALUES(user_agent)
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':prenom'     => $prenom,
    ':nom'        => $nom,
    ':email'      => $email,
    ':entreprise' => $entreprise,
    ':newsletter' => $newsletter,
    ':ip'         => $ip,
    ':ua'         => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
  ]);

  $pdo->commit();
  respond(200, ['status' => 'ok']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  respond(500, ['err' => 'server']);
}
