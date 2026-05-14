<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const REMEMBER_COOKIE = 'barber_remember';
const REMEMBER_LIFETIME = 2592000;

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionLifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (!empty($_SESSION)) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + $sessionLifetime,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function raw_input(): string
{
    static $raw = null;
    if ($raw === null) {
        $raw = file_get_contents('php://input') ?: '';
    }
    return $raw;
}

function input(): array
{
    $json = json_decode(raw_input(), true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return (string)$_SERVER[$serverKey];
    }

    $redirectKey = 'REDIRECT_' . $serverKey;
    if (!empty($_SERVER[$redirectKey])) {
        return (string)$_SERVER[$redirectKey];
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $header => $value) {
            if (strcasecmp((string)$header, $name) === 0) {
                return (string)$value;
            }
        }
    }

    return '';
}

function verify_csrf(): void
{
    $json = json_decode(raw_input(), true);
    $bodyToken = is_array($json) ? ($json['csrf_token'] ?? '') : ($_POST['csrf_token'] ?? '');
    $token = request_header('X-CSRF-Token') ?: request_header('X-CSRFToken') ?: $bodyToken;
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$token)) {
        json_response(['ok' => false, 'message' => 'Token di sicurezza non valido. Aggiorna la pagina e riprova.'], 419);
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        restore_remembered_user();
    }
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, role, first_name, last_name, email, phone, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function remember_user(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()')->execute([$userId]);
    db()->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))')
        ->execute([$userId, $selector, hash('sha256', $token)]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $token, remember_cookie_options(time() + REMEMBER_LIFETIME));
}

function forget_remembered_user(): void
{
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }
    setcookie(REMEMBER_COOKIE, '', remember_cookie_options(time() - 3600));
}

function restore_remembered_user(): void
{
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!str_contains($cookie, ':')) {
        return;
    }
    [$selector, $token] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        forget_remembered_user();
        return;
    }
    $stmt = db()->prepare('SELECT user_id, token_hash FROM remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$selector]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $token))) {
        forget_remembered_user();
        return;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];
    remember_user((int)$row['user_id']);
}

function remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ];
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'message' => 'Accesso richiesto.'], 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        json_response(['ok' => false, 'message' => 'Permessi amministratore richiesti.'], 403);
    }
    return $user;
}

function normalize_phone(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', trim($phone)) ?: '';
}

function minutes_to_time(int $minutes): string
{
    return str_pad((string)intdiv($minutes, 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)($minutes % 60), 2, '0', STR_PAD_LEFT);
}

function time_to_minutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hour * 60) + $minute;
}
