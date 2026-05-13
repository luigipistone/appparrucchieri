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
        'profile_save' => handle_profile_save(),
        'users' => handle_users(),
        'user_save' => handle_user_save(),
        default => json_response(['ok' => false, 'message' => 'Azione non trovata.'], 404),
    };
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['ok' => false, 'message' => 'Errore imprevisto, riprova più tardi.'], 500);
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
    json_response(['ok' => true, 'user' => current_user()]);
}

function handle_logout(): void
{
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
        $ownerSql = $user['role'] === 'admin' ? 'id = ?' : 'id = ? AND user_id = ?';
        $params = [$serviceId, $starts->format('Y-m-d H:i:s'), $ends->format('Y-m-d H:i:s'), $clientId, $id];
        if ($user['role'] !== 'admin') {
            $params[] = (int)$user['id'];
        }
        $stmt = db()->prepare("UPDATE appointments SET service_id=?, starts_at=?, ends_at=?, user_id=? WHERE $ownerSql");
        $stmt->execute($params);
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
