<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        verify_csrf();
    }

    match ($action) {
        'csrf' => json_response(['ok' => true, 'csrf' => csrf_token()]),
        'me' => handle_me(),
        'login' => handle_login(),
        'register' => handle_register(),
        'logout' => handle_logout(),
        'forgot_password' => handle_forgot_password(),
        'reset_password' => handle_reset_password(),
        'app_settings' => handle_app_settings(),
        'app_settings_save' => handle_app_settings_save(),
        'services' => handle_services(),
        'service_save' => handle_service_save(),
        'service_delete' => handle_service_delete(),
        'closure_settings' => handle_closure_settings(),
        'closure_save' => handle_closure_save(),
        'availability' => handle_availability(),
        'appointments' => handle_appointments(),
        'appointment_save' => handle_appointment_save(),
        'appointment_delete' => handle_appointment_delete(),
        'notifications' => handle_notifications(),
        'notifications_read' => handle_notifications_read(),
        'push_public_key' => handle_push_public_key(),
        'push_subscribe' => handle_push_subscribe(),
        'push_unsubscribe' => handle_push_unsubscribe(),
        'profile_save' => handle_profile_save(),
        'users' => handle_users(),
        'user_save' => handle_user_save(),
        default => json_response(['ok' => false, 'message' => 'Azione non trovata.'], 404),
    };
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'message' => 'Errore imprevisto, riprova più tardi.'], 500);
}


function default_app_settings(): array
{
    return [
        'business_name' => 'Barber',
        'business_subtitle' => 'booking',
        'logo_path' => '',
        'app_icon_path' => 'assets/app-icon.svg',
        'primary_color' => '#335eac',
        'accent_color' => '#f42539',
        'background_color' => '#ffffff',
    ];
}

function app_settings(): array
{
    $settings = default_app_settings();
    $rows = db()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    foreach ($rows as $row) {
        if (array_key_exists($row['setting_key'], $settings)) {
            $settings[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $settings;
}

function handle_app_settings(): void
{
    json_response(['ok' => true, 'settings' => app_settings()]);
}

function handle_app_settings_save(): void
{
    require_admin();
    $data = input();
    $settings = app_settings();
    $name = trim((string)($data['business_name'] ?? $settings['business_name']));
    $subtitle = trim((string)($data['business_subtitle'] ?? $settings['business_subtitle']));
    $colors = [
        'primary_color' => normalize_hex_color((string)($data['primary_color'] ?? $settings['primary_color']), $settings['primary_color']),
        'accent_color' => normalize_hex_color((string)($data['accent_color'] ?? $settings['accent_color']), $settings['accent_color']),
        'background_color' => normalize_hex_color((string)($data['background_color'] ?? $settings['background_color']), $settings['background_color']),
    ];

    if ($name === '' || strlen($name) > 80 || strlen($subtitle) > 80) {
        json_response(['ok' => false, 'message' => 'Nome attività non valido.'], 422);
    }

    $logoPath = $settings['logo_path'];
    if (!empty($_FILES['logo']['tmp_name'])) {
        $logoPath = save_app_logo($_FILES['logo']);
    }
    if ($logoPath !== '') {
        generate_app_icon($logoPath, $colors['primary_color']);
    }

    $values = [
        'business_name' => $name,
        'business_subtitle' => $subtitle,
        'logo_path' => $logoPath,
        'primary_color' => $colors['primary_color'],
        'accent_color' => $colors['accent_color'],
        'background_color' => $colors['background_color'],
    ];
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($values as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    json_response(['ok' => true, 'settings' => app_settings()]);
}

function normalize_hex_color(string $value, string $fallback): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}

function save_app_logo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'message' => 'Caricamento logo non riuscito.'], 422);
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => 'Logo troppo grande. Massimo 2 MB.'], 422);
    }
    $mime = mime_content_type((string)$file['tmp_name']) ?: '';
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        json_response(['ok' => false, 'message' => 'Formato logo non supportato. Usa PNG, JPG o WEBP.'], 422);
    }
    $dir = __DIR__ . '/uploads/app';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = 'uploads/app/logo-' . time() . '.' . $extensions[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], __DIR__ . '/' . $path)) {
        json_response(['ok' => false, 'message' => 'Impossibile salvare il logo.'], 500);
    }
    return $path;
}

function generate_app_icon(string $logoPath, string $backgroundColor): void
{
    $absoluteLogo = __DIR__ . '/' . $logoPath;
    $data = file_get_contents($absoluteLogo);
    if ($data === false) {
        return;
    }
    $mime = mime_content_type($absoluteLogo) ?: 'image/png';
    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($data);
    $safeBg = htmlspecialchars($backgroundColor, ENT_QUOTES, 'UTF-8');
    $safeImage = htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
        . '<rect width="512" height="512" rx="112" fill="' . $safeBg . '"/>'
        . '<image href="' . $safeImage . '" x="96" y="96" width="320" height="320" preserveAspectRatio="xMidYMid meet"/>'
        . '</svg>';
    file_put_contents(__DIR__ . '/assets/app-icon.svg', $svg);
}

function handle_notifications(): void
{
    $user = require_auth();
    $archived = (string)($_GET['archive'] ?? '') === '1';
    $readFilter = $archived ? 'read_at IS NOT NULL' : 'read_at IS NULL';
    $stmt = db()->prepare("SELECT id, appointment_id, type, title, body, read_at, created_at FROM notifications WHERE user_id = ? AND $readFilter ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([(int)$user['id']]);
    $items = $stmt->fetchAll();
    $countStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $countStmt->execute([(int)$user['id']]);
    json_response(['ok' => true, 'notifications' => $items, 'unread' => (int)$countStmt->fetchColumn(), 'archive' => $archived]);
}

function handle_notifications_read(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'message' => 'Metodo non consentito.'], 405);
    }
    $user = require_auth();
    $data = input();
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?')->execute([$id, (int)$user['id']]);
    } else {
        db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([(int)$user['id']]);
    }
    json_response(['ok' => true]);
}

function create_notification(int $userId, string $type, string $title, string $body = '', ?int $appointmentId = null): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, appointment_id, type, title, body) VALUES (?,?,?,?,?)');
    $stmt->execute([$userId, $appointmentId, $type, $title, $body]);
    send_web_push_to_user($userId, [
        'id' => (int)db()->lastInsertId(),
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'appointment_id' => $appointmentId,
    ]);
}

function handle_push_public_key(): void
{
    require_auth();
    json_response(['ok' => true, 'publicKey' => VAPID_PUBLIC_KEY]);
}

function handle_push_subscribe(): void
{
    $user = require_auth();
    $data = input();
    $endpoint = trim((string)($data['endpoint'] ?? ''));
    $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        json_response(['ok' => false, 'message' => 'Sottoscrizione push non valida.'], 422);
    }

    $stmt = db()->prepare("INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth=VALUES(auth), user_agent=VALUES(user_agent), updated_at=NOW()");
    $stmt->execute([(int)$user['id'], $endpoint, hash('sha256', $endpoint), $p256dh, $auth, substr(request_header('User-Agent'), 0, 255)]);
    json_response(['ok' => true]);
}

function handle_push_unsubscribe(): void
{
    $user = require_auth();
    $endpoint = trim((string)(input()['endpoint'] ?? ''));
    if ($endpoint !== '') {
        db()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?')->execute([hash('sha256', $endpoint), (int)$user['id']]);
    }
    json_response(['ok' => true]);
}

function send_web_push_to_user(int $userId, array $notification): void
{
    if (VAPID_PUBLIC_KEY === '' || VAPID_PRIVATE_KEY === '') {
        return;
    }
    try {
        $stmt = db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $subscription) {
            $status = send_web_push($subscription, $notification);
            if (in_array($status, [404, 410], true)) {
                db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([(int)$subscription['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('Web Push error: ' . $e->getMessage());
    }
}

function send_web_push(array $subscription, array $notification): int
{
    $endpoint = (string)$subscription['endpoint'];
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $jwt = vapid_jwt($audience);
    $payload = json_encode($notification + ['url' => './'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    [$body, $contentEncoding] = encrypt_web_push_payload($payload, (string)$subscription['p256dh'], (string)$subscription['auth']);
    $headers = [
        'TTL: 3600',
        'Content-Encoding: ' . $contentEncoding,
        'Content-Type: application/octet-stream',
        'Content-Length: ' . strlen($body),
        'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
        'Crypto-Key: p256ecdsa=' . VAPID_PUBLIC_KEY,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HEADER => false,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($endpoint, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    return preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;
}

function encrypt_web_push_payload(string $payload, string $receiverPublicKey, string $authSecret): array
{
    $receiverPublic = base64url_decode($receiverPublicKey);
    $auth = base64url_decode($authSecret);
    $salt = random_bytes(16);
    $localKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$localKey) {
        throw new RuntimeException('Impossibile generare chiave ECDH Web Push.');
    }
    $localDetails = openssl_pkey_get_details($localKey);
    $senderPublic = "\x04" . $localDetails['ec']['x'] . $localDetails['ec']['y'];
    $peerKey = openssl_pkey_get_public(public_key_pem_from_raw($receiverPublic));
    if (!$peerKey) {
        throw new RuntimeException('Chiave pubblica Push API non valida.');
    }
    $sharedSecret = openssl_pkey_derive($peerKey, $localKey, 32);
    if ($sharedSecret === false) {
        throw new RuntimeException('Derivazione chiave Web Push non riuscita.');
    }

    $keyInfo = "WebPush: info\x00" . $receiverPublic . $senderPublic;
    $ikm = hkdf_expand(hash_hmac('sha256', $sharedSecret, $auth, true), $keyInfo, 32);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);
    $record = $payload . "\x02";
    $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Cifratura Web Push non riuscita.');
    }

    $rs = pack('N', 4096);
    return [$salt . $rs . chr(strlen($senderPublic)) . $senderPublic . $ciphertext . $tag, 'aes128gcm'];
}

function hkdf_expand(string $prk, string $info, int $length): string
{
    $output = '';
    $block = '';
    for ($i = 1; strlen($output) < $length; $i++) {
        $block = hash_hmac('sha256', $block . $info . chr($i), $prk, true);
        $output .= $block;
    }
    return substr($output, 0, $length);
}

function public_key_pem_from_raw(string $rawKey): string
{
    $der = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200') . $rawKey;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function vapid_jwt(string $audience): string
{
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $payload = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 3600,
        'sub' => VAPID_SUBJECT,
    ], JSON_UNESCAPED_SLASHES));
    $data = $header . '.' . $payload;
    $privateKey = openssl_pkey_get_private(VAPID_PRIVATE_KEY);
    if (!$privateKey || !openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Chiave privata VAPID non valida.');
    }
    return $data . '.' . base64url_encode(der_to_jose_signature($signature, 64));
}

function der_to_jose_signature(string $der, int $partLength): string
{
    $offset = 2;
    if (ord($der[1]) & 0x80) {
        $offset += ord($der[1]) & 0x7f;
    }
    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Firma VAPID non valida.');
    }
    $rLength = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLength);
    $offset += 2 + $rLength;
    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Firma VAPID non valida.');
    }
    $sLength = ord($der[$offset + 1]);
    $s = substr($der, $offset + 2, $sLength);
    return str_pad(ltrim($r, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), $partLength / 2, "\x00", STR_PAD_LEFT);
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4)) ?: '';
}

function admin_user_ids(): array
{
    return array_map('intval', db()->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function notify_admins(string $type, string $title, string $body = '', ?int $appointmentId = null): void
{
    foreach (admin_user_ids() as $adminId) {
        create_notification($adminId, $type, $title, $body, $appointmentId);
    }
}

function appointment_notification_text(array $appointment, string $fallbackService = ''): string
{
    $service = $appointment['service_name'] ?? $fallbackService ?: 'Servizio';
    $customer = trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')) ?: 'Cliente';
    $starts = new DateTimeImmutable((string)$appointment['starts_at']);
    return $service . ' · ' . $customer . ' · ' . $starts->format('d/m/Y H:i');
}

function fetch_appointment_details(int $id): ?array
{
    $stmt = db()->prepare("SELECT a.*, s.name service_name, u.first_name, u.last_name, u.email, u.phone
        FROM appointments a
        JOIN services s ON s.id = a.service_id
        JOIN users u ON u.id = a.user_id
        WHERE a.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function handle_me(): void
{
    json_response(['ok' => true, 'user' => current_user(), 'csrf' => csrf_token()]);
}

function handle_login(): void
{
    $data = input();
    $login = trim((string)($data['login'] ?? ''));
    $password = (string)($data['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->execute([$login, normalize_phone($login)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_response(['ok' => false, 'message' => 'Credenziali non valide.'], 422);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    remember_user((int)$user['id']);
    json_response(['ok' => true, 'user' => current_user()]);
}

function handle_register(): void
{
    $data = input();
    $first = trim((string)($data['first_name'] ?? ''));
    $last = trim((string)($data['last_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = normalize_phone((string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 8 || strlen($password) < 8) {
        json_response(['ok' => false, 'message' => 'Compila tutti i campi con dati validi. Password minima 8 caratteri.'], 422);
    }

    $stmt = db()->prepare('INSERT INTO users (role, first_name, last_name, email, phone, password_hash) VALUES (?,?,?,?,?,?)');
    try {
        $stmt->execute(['cliente', $first, $last, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        json_response(['ok' => false, 'message' => 'Email o telefono già registrati.'], 422);
    }

    $_SESSION['user_id'] = (int)db()->lastInsertId();
    remember_user((int)$_SESSION['user_id']);
    json_response(['ok' => true, 'user' => current_user()]);
}

function handle_logout(): void
{
    forget_remembered_user();
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true]);
}

function handle_forgot_password(): void
{
    $data = input();
    $identifier = trim((string)($data['identifier'] ?? ''));
    $channel = ($data['channel'] ?? 'email') === 'telefono' ? 'telefono' : 'email';
    $stmt = db()->prepare('SELECT id, email, phone FROM users WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->execute([$identifier, normalize_phone($identifier)]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['ok' => true, 'message' => 'Se il contatto esiste, riceverai le istruzioni di recupero.']);
    }

    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO password_resets (user_id, token, channel, expires_at) VALUES (?,?,?,?)');
    $stmt->execute([(int)$user['id'], $token, $channel, $expires]);

    $base = APP_URL ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $resetUrl = rtrim($base, '/') . '/index.php?reset=' . urlencode($token);
    if ($channel === 'email') {
        @mail($user['email'], 'Recupero password ' . APP_NAME, "Apri questo link per impostare una nuova password: $resetUrl");
    }

    json_response(['ok' => true, 'message' => 'Se il contatto esiste, riceverai le istruzioni di recupero.', 'reset_url_demo' => $resetUrl]);
}

function handle_reset_password(): void
{
    $data = input();
    $token = (string)($data['token'] ?? '');
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < 8) {
        json_response(['ok' => false, 'message' => 'La password deve contenere almeno 8 caratteri.'], 422);
    }

    $stmt = db()->prepare('SELECT * FROM password_resets WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    if (!$reset) {
        json_response(['ok' => false, 'message' => 'Link non valido o scaduto.'], 422);
    }

    db()->beginTransaction();
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$reset['user_id']]);
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([(int)$reset['id']]);
    db()->commit();
    json_response(['ok' => true, 'message' => 'Password aggiornata. Ora puoi accedere.']);
}

function handle_services(): void
{
    $user = current_user();
    $where = ($user && $user['role'] === 'admin') ? '' : 'WHERE active = 1';
    $rows = db()->query("SELECT * FROM services $where ORDER BY name")->fetchAll();
    json_response(['ok' => true, 'services' => $rows]);
}

function handle_service_save(): void
{
    require_admin();
    $data = $_POST ?: input();
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $price = (float)($data['price'] ?? 0);
    $duration = max(DEFAULT_SLOT_MINUTES, (int)($data['duration_minutes'] ?? DEFAULT_SLOT_MINUTES));
    $description = trim((string)($data['description'] ?? ''));
    $active = !empty($data['active']) ? 1 : 0;
    if ($name === '') {
        json_response(['ok' => false, 'message' => 'Nome servizio obbligatorio.'], 422);
    }

    $imagePath = $data['existing_image'] ?? null;
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $info = getimagesize($_FILES['image']['tmp_name']);
        if (!$info) {
            json_response(['ok' => false, 'message' => 'Immagine non valida.'], 422);
        }
        $ext = image_type_to_extension($info[2], false) ?: 'jpg';
        $file = 'uploads/services/' . bin2hex(random_bytes(10)) . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $file);
        $imagePath = $file;
    }

    if ($id > 0) {
        $stmt = db()->prepare('UPDATE services SET name=?, description=?, price=?, duration_minutes=?, image_path=?, active=? WHERE id=?');
        $stmt->execute([$name, $description, $price, $duration, $imagePath, $active, $id]);
    } else {
        $stmt = db()->prepare('INSERT INTO services (name, description, price, duration_minutes, image_path, active) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$name, $description, $price, $duration, $imagePath, $active]);
    }
    json_response(['ok' => true]);
}

function handle_service_delete(): void
{
    require_admin();
    $id = (int)(input()['id'] ?? 0);
    db()->prepare('UPDATE services SET active = 0 WHERE id = ?')->execute([$id]);
    json_response(['ok' => true]);
}


function handle_closure_settings(): void
{
    require_admin();
    $weekly = array_map('intval', db()->query('SELECT weekday FROM weekly_closures ORDER BY weekday')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $special = db()->query('SELECT closure_date, label FROM special_closures ORDER BY closure_date')->fetchAll();
    json_response(['ok' => true, 'weekly' => $weekly, 'special' => $special]);
}

function handle_closure_save(): void
{
    require_admin();
    $data = input();
    $weekly = array_values(array_unique(array_filter(array_map('intval', $data['weekly'] ?? []), fn($day) => $day >= 1 && $day <= 7)));
    $special = is_array($data['special'] ?? null) ? $data['special'] : [];

    db()->beginTransaction();
    db()->exec('DELETE FROM weekly_closures');
    $weeklyStmt = db()->prepare('INSERT INTO weekly_closures (weekday) VALUES (?)');
    foreach ($weekly as $day) {
        $weeklyStmt->execute([$day]);
    }

    db()->exec('DELETE FROM special_closures');
    $specialStmt = db()->prepare('INSERT INTO special_closures (closure_date, label) VALUES (?, ?)');
    foreach ($special as $item) {
        $date = (string)($item['date'] ?? $item['closure_date'] ?? '');
        $label = trim((string)($item['label'] ?? '')) ?: null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $specialStmt->execute([$date, $label]);
        }
    }
    db()->commit();

    json_response(['ok' => true]);
}

function month_bounds(string $month): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable('first day of this month');
    return [$start->format('Y-m-01 00:00:00'), $start->modify('last day of this month')->format('Y-m-d 23:59:59')];
}

function handle_availability(): void
{
    require_auth();
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $month = (string)($_GET['month'] ?? date('Y-m'));
    $excludeId = (int)($_GET['exclude_appointment_id'] ?? 0);
    $service = fetch_service($serviceId);
    if (!$service) {
        json_response(['ok' => false, 'message' => 'Servizio non disponibile.'], 404);
    }
    [$start, $end] = month_bounds($month);
    $stmt = db()->prepare("SELECT starts_at, ends_at FROM appointments WHERE status='confermato' AND id <> ? AND starts_at BETWEEN ? AND ?");
    $stmt->execute([$excludeId, $start, $end]);
    $busy = $stmt->fetchAll();

    $days = [];
    $cursor = new DateTimeImmutable(substr($start, 0, 10));
    $last = new DateTimeImmutable(substr($end, 0, 10));
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        $slots = day_slots($date, (int)$service['duration_minutes'], $busy);
        $days[$date] = ['available' => count($slots), 'slots' => $slots];
        $cursor = $cursor->modify('+1 day');
    }
    json_response(['ok' => true, 'days' => $days]);
}

function day_slots(string $date, int $duration, array $busy): array
{
    if (is_closed_day($date)) {
        return [];
    }
    $weekday = (int)(new DateTimeImmutable($date))->format('N');
    $slots = [];
    foreach (OPENING_HOURS[$weekday] ?? [] as [$from, $to]) {
        for ($m = time_to_minutes($from); $m + $duration <= time_to_minutes($to); $m += DEFAULT_SLOT_MINUTES) {
            $slotStart = new DateTimeImmutable($date . ' ' . minutes_to_time($m));
            if ($slotStart < new DateTimeImmutable('-5 minutes')) {
                continue;
            }
            $slotEnd = $slotStart->modify('+' . $duration . ' minutes');
            if (!is_busy($slotStart, $slotEnd, $busy)) {
                $slots[] = $slotStart->format('H:i');
            }
        }
    }
    return $slots;
}

function is_closed_day(string $date): bool
{
    $weekday = (int)(new DateTimeImmutable($date))->format('N');
    $stmt = db()->prepare('SELECT 1 FROM weekly_closures WHERE weekday = ? LIMIT 1');
    $stmt->execute([$weekday]);
    if ($stmt->fetchColumn()) {
        return true;
    }

    $stmt = db()->prepare('SELECT 1 FROM special_closures WHERE closure_date = ? LIMIT 1');
    $stmt->execute([$date]);
    return (bool)$stmt->fetchColumn();
}

function is_busy(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
{
    foreach ($busy as $item) {
        $busyStart = new DateTimeImmutable($item['starts_at']);
        $busyEnd = new DateTimeImmutable($item['ends_at']);
        if ($start < $busyEnd && $end > $busyStart) {
            return true;
        }
    }
    return false;
}

function fetch_service(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$id]);
    $service = $stmt->fetch();
    return $service ?: null;
}

function handle_appointments(): void
{
    $user = require_auth();
    $month = (string)($_GET['month'] ?? date('Y-m'));
    [$start, $end] = month_bounds($month);
    $sql = "SELECT a.*, s.name service_name, s.duration_minutes, s.price, u.first_name, u.last_name, u.phone, u.email
            FROM appointments a
            JOIN services s ON s.id = a.service_id
            JOIN users u ON u.id = a.user_id
            WHERE a.status='confermato' AND a.starts_at BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($user['role'] !== 'admin') {
        $sql .= ' AND a.user_id = ?';
        $params[] = (int)$user['id'];
    }
    $sql .= ' ORDER BY a.starts_at';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true, 'appointments' => $stmt->fetchAll()]);
}

function handle_appointment_save(): void
{
    $user = require_auth();
    $data = input();
    $id = (int)($data['id'] ?? 0);
    $serviceId = (int)($data['service_id'] ?? 0);
    $clientId = $user['role'] === 'admin' ? (int)($data['user_id'] ?? $user['id']) : (int)$user['id'];
    $date = (string)($data['date'] ?? '');
    $time = (string)($data['time'] ?? '');
    $service = fetch_service($serviceId);
    if (!$service || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        json_response(['ok' => false, 'message' => 'Dati appuntamento non validi.'], 422);
    }

    $starts = new DateTimeImmutable($date . ' ' . $time);
    $ends = $starts->modify('+' . (int)$service['duration_minutes'] . ' minutes');
    if ($starts < new DateTimeImmutable('-5 minutes')) {
        json_response(['ok' => false, 'message' => 'Non puoi prenotare nel passato.'], 422);
    }
    if (!in_array($starts->format('H:i'), day_slots($date, (int)$service['duration_minutes'], []), true)) {
        json_response(['ok' => false, 'message' => 'Il posto scelto non è prenotabile in questa data.'], 422);
    }

    db()->beginTransaction();
    $stmt = db()->prepare("SELECT id FROM appointments WHERE status='confermato' AND id <> ? AND starts_at < ? AND ends_at > ? FOR UPDATE");
    $stmt->execute([$id, $ends->format('Y-m-d H:i:s'), $starts->format('Y-m-d H:i:s')]);
    if ($stmt->fetch()) {
        db()->rollBack();
        json_response(['ok' => false, 'message' => 'Slot già occupato.'], 409);
    }

    if ($id > 0) {
        $previousDetails = fetch_appointment_details($id);
        if (!$previousDetails || $previousDetails['status'] !== 'confermato') {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'Appuntamento non modificabile.'], 422);
        }
        if ($user['role'] !== 'admin' && (int)$previousDetails['user_id'] !== (int)$user['id']) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'Non puoi modificare questo appuntamento.'], 403);
        }

        $ownerSql = $user['role'] === 'admin' ? "id = ? AND status='confermato'" : "id = ? AND user_id = ? AND status='confermato'";
        $params = [$serviceId, $starts->format('Y-m-d H:i:s'), $ends->format('Y-m-d H:i:s'), $clientId, $id];
        if ($user['role'] !== 'admin') {
            $params[] = (int)$user['id'];
        }
        $stmt = db()->prepare("UPDATE appointments SET service_id=?, starts_at=?, ends_at=?, user_id=? WHERE $ownerSql");
        $stmt->execute($params);
        if ($stmt->rowCount() < 1) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'Appuntamento non modificabile.'], 422);
        }

        $details = fetch_appointment_details($id) ?: [
            'starts_at' => $starts->format('Y-m-d H:i:s'),
            'service_name' => $service['name'],
            'first_name' => $previousDetails['first_name'],
            'last_name' => $previousDetails['last_name'],
            'user_id' => $clientId,
        ];
        $text = appointment_notification_text($details, (string)$service['name']);
        notify_admins('appointment_updated', 'Appuntamento modificato', $text, $id);
        create_notification((int)$details['user_id'], 'appointment_updated', 'Prenotazione modificata', $text, $id);
        if ((int)$previousDetails['user_id'] !== (int)$details['user_id']) {
            create_notification((int)$previousDetails['user_id'], 'appointment_updated', 'Prenotazione riassegnata', appointment_notification_text($previousDetails), $id);
        }
    } else {
        $stmt = db()->prepare("INSERT INTO appointments (user_id, service_id, starts_at, ends_at, status) VALUES (?,?,?,?, 'confermato')");
        $stmt->execute([$clientId, $serviceId, $starts->format('Y-m-d H:i:s'), $ends->format('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        $details = fetch_appointment_details($id) ?: [
            'starts_at' => $starts->format('Y-m-d H:i:s'),
            'service_name' => $service['name'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
        ];
        $text = appointment_notification_text($details, (string)$service['name']);
        notify_admins('appointment_created', 'Nuovo appuntamento', $text, $id);
        create_notification($clientId, 'appointment_created', 'Prenotazione confermata', $text, $id);
    }
    db()->commit();
    json_response(['ok' => true]);
}

function handle_appointment_delete(): void
{
    $user = require_auth();
    $id = (int)(input()['id'] ?? 0);
    $details = fetch_appointment_details($id);
    if (!$details) {
        json_response(['ok' => false, 'message' => 'Appuntamento non trovato.'], 404);
    }

    db()->beginTransaction();
    if ($user['role'] === 'admin') {
        $update = db()->prepare("UPDATE appointments SET status='annullato' WHERE id=? AND status='confermato'");
        $update->execute([$id]);
    } else {
        $update = db()->prepare("UPDATE appointments SET status='annullato' WHERE id=? AND user_id=? AND status='confermato'");
        $update->execute([$id, (int)$user['id']]);
    }
    if ($update->rowCount() < 1) {
        db()->rollBack();
        json_response(['ok' => false, 'message' => 'Appuntamento non cancellabile.'], 422);
    }

    $text = appointment_notification_text($details);
    notify_admins('appointment_cancelled', 'Appuntamento eliminato', $text, $id);
    $customerTitle = (int)$details['user_id'] === (int)$user['id'] ? 'Hai eliminato la prenotazione' : 'Prenotazione eliminata';
    create_notification((int)$details['user_id'], 'appointment_cancelled', $customerTitle, $text, $id);
    db()->commit();
    json_response(['ok' => true]);
}

function handle_profile_save(): void
{
    $user = require_auth();
    $data = input();
    save_user_data((int)$user['id'], $data, $user['role'] === 'admin');
    json_response(['ok' => true, 'user' => current_user()]);
}

function handle_users(): void
{
    require_admin();
    $rows = db()->query("SELECT id, role, first_name, last_name, email, phone, created_at FROM users ORDER BY role, last_name, first_name")->fetchAll();
    json_response(['ok' => true, 'users' => $rows]);
}

function handle_user_save(): void
{
    require_admin();
    $data = input();
    $id = (int)($data['id'] ?? 0);
    if ($id < 1) {
        json_response(['ok' => false, 'message' => 'Utente non valido.'], 422);
    }
    save_user_data($id, $data, true);
    json_response(['ok' => true]);
}

function save_user_data(int $id, array $data, bool $allowRole): void
{
    $first = trim((string)($data['first_name'] ?? ''));
    $last = trim((string)($data['last_name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $phone = normalize_phone((string)($data['phone'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $role = $allowRole && ($data['role'] ?? '') === 'admin' ? 'admin' : 'cliente';
    if ($first === '' || $last === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 8) {
        json_response(['ok' => false, 'message' => 'Dati profilo non validi.'], 422);
    }
    $sql = 'UPDATE users SET first_name=?, last_name=?, email=?, phone=?' . ($allowRole ? ', role=?' : '') . ($password !== '' ? ', password_hash=?' : '') . ' WHERE id=?';
    $params = [$first, $last, $email, $phone];
    if ($allowRole) {
        $params[] = $role;
    }
    if ($password !== '') {
        if (strlen($password) < 8) {
            json_response(['ok' => false, 'message' => 'Password minima 8 caratteri.'], 422);
        }
        $params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    $params[] = $id;
    try {
        db()->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        json_response(['ok' => false, 'message' => 'Email o telefono già usati.'], 422);
    }
}
