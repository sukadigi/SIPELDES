<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $assetRoot = realpath(__DIR__ . '/assets');
    $assetPath = realpath(__DIR__ . $requestedPath);
    if (str_starts_with($requestedPath, '/assets/') && $assetRoot && $assetPath && is_file($assetPath) && str_starts_with($assetPath, $assetRoot . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require __DIR__ . '/includes/database.php';
load_environment(__DIR__ . '/.env');
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/workflow.php';

$secureCookie = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly' => true, 'secure' => $secureCookie, 'samesite' => 'Lax', 'path' => '/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:");
if ($secureCookie && (getenv('APP_ENV') ?: 'local') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function page(string $title, string $view, array $data = [], bool $admin = false): never
{
    $viewPath = __DIR__ . '/views/' . $view . '.php';
    if (!is_file($viewPath)) {
        throw new RuntimeException('Tampilan tidak ditemukan.');
    }
    extract($data, EXTR_SKIP);
    if ($view === 'print') {
        require $viewPath;
        exit;
    }
    $pageTitle = $title . ' | SIPEDES Desa Sukakerta';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';
    $success = flash('success');
    $error = flash('error');
    if ($admin) {
        echo '<div class="admin-shell">';
        require __DIR__ . '/includes/sidebar.php';
        echo '<main class="admin-main">';
    } else {
        echo '<main class="page-wrap">';
    }
    if ($success) {
        echo '<div class="container"><div class="alert alert-success">' . e($success) . '</div></div>';
    }
    if ($error) {
        echo '<div class="container"><div class="alert alert-danger">' . e($error) . '</div></div>';
    }
    require $viewPath;
    echo '</main>';
    if ($admin) {
        echo '</div>';
    }
    require __DIR__ . '/includes/footer.php';
    exit;
}

function status_label(string $status): string
{
    return APPLICATION_STATUSES[$status] ?? $status;
}

function hash_password(string $password): string
{
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}

function application_for_user(int $id, array $user): array
{
    $query = database_connection()->prepare('SELECT a.*, t.name AS type_name, t.code AS type_code, r.full_name, r.user_id, r.nik, r.birth_place, r.birth_date, r.address, r.rt, r.rw, r.verified_at FROM letter_applications a JOIN letter_types t ON t.id = a.letter_type_id JOIN residents r ON r.id = a.resident_id WHERE a.id = ?');
    $query->execute([$id]);
    $application = $query->fetch();
    if (!$application || ((int) $application['user_id'] !== (int) $user['id'] && !can('applications.view'))) {
        http_response_code(404);
        exit('Pengajuan tidak ditemukan.');
    }
    return $application;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
$path = $path === '/' ? '/' : rtrim($path, '/');

$sessionUser = current_user();
if ($sessionUser && (int) $sessionUser['must_change_password'] === 1 && !in_array($path, ['/ubah-kata-sandi', '/logout'], true)) {
    redirect('/ubah-kata-sandi');
}
if (preg_match('#^/lacak/[a-f0-9]{64}$#', $path)) {
    $now = time();
    if (!isset($_SESSION['_tracking_window']) || $now - $_SESSION['_tracking_window'] >= 600) {
        $_SESSION['_tracking_window'] = $now;
        $_SESSION['_tracking_attempts'] = 0;
    }
    if ((int) ($_SESSION['_tracking_attempts'] ?? 0) >= 20) {
        http_response_code(429);
        page('Cek status', 'track_result', ['tracking' => null, 'history' => []]);
    }
    $_SESSION['_tracking_attempts'] = (int) ($_SESSION['_tracking_attempts'] ?? 0) + 1;
}

try {
    if ($path === '/health') {
        database_connection()->query('SELECT 1');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'database' => 'connected'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($path === '/' && $method === 'GET') {
        $types = database_connection()->query('SELECT name, description, code FROM letter_types WHERE is_active = 1 ORDER BY name')->fetchAll();
        page('Pelayanan surat digital', 'home', ['types' => $types]);
    }

    if ($path === '/layanan-surat' && $method === 'GET') {
        $types = database_connection()->query('SELECT name, description, code, requirements, sla_days FROM letter_types WHERE is_active = 1 ORDER BY name')->fetchAll();
        page('Layanan surat', 'services', ['types' => $types]);
    }

    if ($path === '/login') {
        if ($method === 'POST') {
            verify_csrf();
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $statement = database_connection()->prepare('SELECT id, password_hash, failed_logins, locked_until, is_active, must_change_password FROM users WHERE email = ?');
            $statement->execute([$email]);
            $account = $statement->fetch();
            $now = time();
            if ($account && $account['locked_until'] && strtotime($account['locked_until']) > $now) {
                flash('error', 'Akun sementara dikunci. Silakan coba kembali beberapa menit lagi.');
                redirect('/login');
            }
            if (!$account || !(bool) $account['is_active'] || !password_verify($password, $account['password_hash'])) {
                if ($account) {
                    $failures = (int) $account['failed_logins'] + 1;
                    $lockMinutes = $failures >= 5 ? min(30, 2 ** min(5, $failures - 4)) : 0;
                    database_connection()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                        ->execute([$failures, $lockMinutes ? date('Y-m-d H:i:s', $now + $lockMinutes * 60) : null, $account['id']]);
                }
                audit('auth.login_failed', 'user');
                flash('error', 'Email atau kata sandi tidak sesuai.');
                redirect('/login');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            database_connection()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$account['id']]);
            audit('auth.login', 'user', (int) $account['id']);
            if ((int) $account['must_change_password'] === 1) {
                redirect('/ubah-kata-sandi');
            }
            redirect('/dashboard');
        }
        page('Masuk', 'login');
    }

    if ($path === '/daftar') {
        if ($method === 'POST') {
            verify_csrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                flash('error', 'Periksa nama, email, dan kata sandi (minimal 12 karakter).');
                redirect('/daftar');
            }
            $database = database_connection();
            $exists = $database->prepare('SELECT 1 FROM users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetchColumn()) {
                flash('error', 'Email tersebut sudah terdaftar.');
                redirect('/daftar');
            }
            $roleId = $database->query("SELECT id FROM roles WHERE name = 'warga'")->fetchColumn();
            $database->beginTransaction();
            try {
                $database->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')
                    ->execute([$roleId, $name, $email, hash_password($password)]);
                $userId = (int) $database->lastInsertId();
                $database->prepare('INSERT INTO residents (user_id, full_name) VALUES (?, ?)')->execute([$userId, $name]);
                $database->commit();
            } catch (Throwable $error) {
                $database->rollBack();
                throw $error;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            audit('auth.register', 'user', $userId);
            flash('success', 'Akun berhasil dibuat. Lengkapi pengajuan Anda untuk memulai.');
            redirect('/dashboard');
        }
        page('Buat akun warga', 'register');
    }

    if ($path === '/logout' && $method === 'POST') {
        verify_csrf();
        if ($user = current_user()) {
            audit('auth.logout', 'user', (int) $user['id']);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        flash('success', 'Anda telah keluar dari akun.');
        redirect('/');
    }

    if ($path === '/ubah-kata-sandi') {
        $user = require_login();
        if ($method === 'POST') {
            verify_csrf();
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $statement = database_connection()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $statement->execute([$user['id']]);
            $passwordHash = $statement->fetchColumn();
            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash) || strlen($newPassword) < 12) {
                flash('error', 'Kata sandi lama tidak cocok atau kata sandi baru kurang dari 12 karakter.');
                redirect('/ubah-kata-sandi');
            }
            database_connection()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([hash_password($newPassword), $user['id']]);
            session_regenerate_id(true);
            audit('auth.password_changed', 'user', (int) $user['id']);
            flash('success', 'Kata sandi berhasil diganti.');
            redirect('/dashboard');
        }
        page('Ganti kata sandi', 'change_password');
    }

    if ($path === '/dashboard' && $method === 'GET') {
        $user = require_login();
        if ($user['role'] !== 'warga') {
            redirect('/admin');
        }
        $database = database_connection();
        $query = $database->prepare('SELECT a.id, a.status, a.created_at, t.name AS type_name FROM letter_applications a JOIN residents r ON r.id = a.resident_id JOIN letter_types t ON t.id = a.letter_type_id WHERE r.user_id = ? ORDER BY a.created_at DESC');
        $query->execute([$user['id']]);
        $applications = $query->fetchAll();
        $notice = $database->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
        $notice->execute([$user['id']]);
        page('Dashboard warga', 'citizen_dashboard', ['applications' => $applications, 'notifications' => $notice->fetchAll()]);
    }

    if ($path === '/ajukan-surat') {
        $user = require_login();
        if ($user['role'] !== 'warga') {
            http_response_code(403);
            exit('Pengajuan online hanya tersedia untuk akun warga.');
        }
        $database = database_connection();
        if ($method === 'POST') {
            verify_csrf();
            $typeId = filter_input(INPUT_POST, 'letter_type_id', FILTER_VALIDATE_INT);
            $purpose = trim((string) ($_POST['keperluan'] ?? ''));
            if (!$typeId || mb_strlen($purpose) < 5 || mb_strlen($purpose) > 1000) {
                flash('error', 'Pilih jenis surat dan tuliskan keperluan (5–1.000 karakter).');
                redirect('/ajukan-surat');
            }
            $typeQuery = $database->prepare('SELECT id FROM letter_types WHERE id = ? AND is_active = 1');
            $typeQuery->execute([$typeId]);
            if (!$typeQuery->fetchColumn()) {
                flash('error', 'Jenis surat tidak tersedia.');
                redirect('/ajukan-surat');
            }
            $residentQuery = $database->prepare('SELECT id FROM residents WHERE user_id = ?');
            $residentQuery->execute([$user['id']]);
            $residentId = (int) $residentQuery->fetchColumn();
            $duplicate = $database->prepare("SELECT 1 FROM letter_applications WHERE resident_id = ? AND letter_type_id = ? AND status NOT IN ('picked_up', 'rejected', 'cancelled') AND created_at >= ?");
            $duplicate->execute([$residentId, $typeId, date('Y-m-d H:i:s', time() - 900)]);
            if ($duplicate->fetchColumn()) {
                flash('error', 'Pengajuan sejenis masih diproses. Periksa riwayat pengajuan terlebih dahulu.');
                redirect('/dashboard');
            }
            $trackingToken = bin2hex(random_bytes(32));
            $database->prepare('INSERT INTO letter_applications (tracking_token, resident_id, letter_type_id, status, payload) VALUES (?, ?, ?, ?, ?)')
                ->execute([hash('sha256', $trackingToken), $residentId, $typeId, 'pending_review', json_encode(['keperluan' => $purpose], JSON_THROW_ON_ERROR)]);
            $applicationId = (int) $database->lastInsertId();
            $database->prepare('INSERT INTO letter_status_histories (application_id, actor_id, old_status, new_status, note) VALUES (?, ?, NULL, ?, ?)')
                ->execute([$applicationId, $user['id'], 'pending_review', 'Pengajuan diterima secara online. Verifikasi identitas dilakukan langsung di kantor desa sebelum penerbitan.']);
            audit('application.created', 'letter_application', $applicationId);
            flash('success', 'Pengajuan diterima. Kode pelacakan Anda: ' . $trackingToken);
            redirect('/pengajuan/' . $applicationId);
        }
        $types = $database->query('SELECT id, name, description, requirements FROM letter_types WHERE is_active = 1 ORDER BY name')->fetchAll();
        page('Ajukan surat', 'apply', ['types' => $types]);
    }

    if ($path === '/cek-status' && $method === 'GET') {
        page('Cek status pengajuan', 'track_form');
    }
    if ($path === '/cek-status' && $method === 'POST') {
        verify_csrf();
        $token = trim((string) ($_POST['token'] ?? ''));
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            flash('error', 'Kode pelacakan tidak valid.');
            redirect('/cek-status');
        }
        $_SESSION['tracking_hash'] = hash('sha256', $token);
        redirect('/lacak/' . $token);
    }
    if (preg_match('#^/lacak/([a-f0-9]{64})$#', $path, $matches) && $method === 'GET') {
        $tokenHash = hash('sha256', $matches[1]);
        $query = database_connection()->prepare('SELECT a.status, a.created_at, t.name AS type_name FROM letter_applications a JOIN letter_types t ON t.id = a.letter_type_id WHERE a.tracking_token = ?');
        $query->execute([$tokenHash]);
        $tracking = $query->fetch();
        if (!$tracking) {
            http_response_code(404);
            page('Kode tidak ditemukan', 'track_result', ['tracking' => null, 'history' => []]);
        }
        $historyQuery = database_connection()->prepare('SELECT new_status, created_at FROM letter_status_histories WHERE application_id = (SELECT id FROM letter_applications WHERE tracking_token = ?) ORDER BY created_at');
        $historyQuery->execute([$tokenHash]);
        page('Status pengajuan', 'track_result', ['tracking' => $tracking, 'history' => $historyQuery->fetchAll()]);
    }

    if (preg_match('#^/pengajuan/(\d+)$#', $path, $matches) && $method === 'GET') {
        $user = require_login();
        $application = application_for_user((int) $matches[1], $user);
        $history = database_connection()->prepare('SELECT new_status, note, created_at FROM letter_status_histories WHERE application_id = ? ORDER BY created_at');
        $history->execute([$application['id']]);
        $letter = database_connection()->prepare('SELECT id, status, snapshot_html FROM letters WHERE application_id = ?');
        $letter->execute([$application['id']]);
        page('Detail pengajuan', 'application_detail', ['application' => $application, 'history' => $history->fetchAll(), 'letter' => $letter->fetch() ?: null]);
    }

    if (preg_match('#^/surat/(\d+)/cetak$#', $path, $matches) && $method === 'GET') {
        $user = require_login();
        $application = application_for_user((int) $matches[1], $user);
        $query = database_connection()->prepare('SELECT l.*, r.register_number, t.name AS type_name FROM letters l JOIN letter_applications a ON a.id = l.application_id JOIN letter_registers r ON r.application_id = a.id JOIN letter_types t ON t.id = a.letter_type_id WHERE a.id = ? AND l.status = ? AND a.status IN (?, ?, ?)');
        $query->execute([$application['id'], 'issued', 'issued', 'ready_pickup', 'picked_up']);
        $letter = $query->fetch();
        if (!$letter) {
            http_response_code(404);
            exit('Surat belum tersedia.');
        }
        audit('letter.printed', 'letter', (int) $letter['id']);
        page('Pratinjau surat', 'print', ['letter' => $letter]);
    }

    if (preg_match('#^/verifikasi-surat/([a-f0-9]{64})$#', $path, $matches) && $method === 'GET') {
        $query = database_connection()->prepare('SELECT l.status, l.issued_at, r.register_number, t.name AS type_name FROM letter_verification_tokens v JOIN letters l ON l.id = v.letter_id JOIN letter_registers r ON r.application_id = l.application_id JOIN letter_types t ON t.id = (SELECT letter_type_id FROM letter_applications WHERE id = l.application_id) WHERE v.token_hash = ? AND v.revoked_at IS NULL');
        $query->execute([hash('sha256', $matches[1])]);
        $verification = $query->fetch();
        page('Verifikasi surat', 'verification', ['verification' => $verification ?: null]);
    }

    if ($path === '/admin' && $method === 'GET') {
        $user = require_permission('dashboard.view');
        if ($user['role'] === 'warga') {
            redirect('/dashboard');
        }
        $database = database_connection();
        $stats = [
            'total' => (int) $database->query('SELECT COUNT(*) FROM letter_applications')->fetchColumn(),
            'baru' => (int) $database->query("SELECT COUNT(*) FROM letter_applications WHERE status = 'pending_review'")->fetchColumn(),
            'proses' => (int) $database->query("SELECT COUNT(*) FROM letter_applications WHERE status IN ('processing', 'pending_approval')")->fetchColumn(),
            'terbit' => (int) $database->query("SELECT COUNT(*) FROM letter_applications WHERE status IN ('issued', 'ready_pickup', 'picked_up')")->fetchColumn(),
        ];
        $recent = $database->query('SELECT a.id, a.status, a.created_at, t.name AS type_name, r.full_name FROM letter_applications a JOIN letter_types t ON t.id = a.letter_type_id JOIN residents r ON r.id = a.resident_id ORDER BY a.created_at DESC LIMIT 8')->fetchAll();
        page('Dashboard petugas', 'admin_dashboard', ['stats' => $stats, 'recent' => $recent], true);
    }

    if ($path === '/admin/pengajuan' && $method === 'GET') {
        require_permission('applications.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $queryText = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT a.id, a.status, a.created_at, t.name AS type_name, r.full_name FROM letter_applications a JOIN letter_types t ON t.id = a.letter_type_id JOIN residents r ON r.id = a.resident_id WHERE 1 = 1';
        $params = [];
        if (isset(APPLICATION_STATUSES[$status])) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }
        if ($queryText !== '') {
            $sql .= ' AND (r.full_name LIKE ? OR t.name LIKE ?)';
            $params[] = '%' . $queryText . '%';
            $params[] = '%' . $queryText . '%';
        }
        $sql .= ' ORDER BY a.created_at DESC LIMIT 100';
        $statement = database_connection()->prepare($sql);
        $statement->execute($params);
        page('Pengajuan surat', 'admin_applications', ['applications' => $statement->fetchAll(), 'statuses' => APPLICATION_STATUSES, 'selectedStatus' => $status, 'queryText' => $queryText], true);
    }

    if (preg_match('#^/admin/pengajuan/(\d+)$#', $path, $matches)) {
        $user = require_permission('applications.view');
        $application = application_for_user((int) $matches[1], $user);
        if ($method === 'POST') {
            verify_csrf();
            try {
                $nextStatus = (string) ($_POST['status'] ?? '');
                $residentData = [];
                if ($nextStatus === 'identity_verified') {
                    $residentData = [
                        'nik' => trim((string) ($_POST['nik'] ?? '')),
                        'birth_place' => trim((string) ($_POST['birth_place'] ?? '')),
                        'birth_date' => trim((string) ($_POST['birth_date'] ?? '')),
                        'address' => trim((string) ($_POST['address'] ?? '')),
                        'rt' => trim((string) ($_POST['rt'] ?? '')),
                        'rw' => trim((string) ($_POST['rw'] ?? '')),
                    ];
                    [$birthYear, $birthMonth, $birthDay] = array_map('intval', explode('-', $residentData['birth_date'] . '--'));
                    if ($residentData['birth_place'] === '' || $residentData['address'] === '' || mb_strlen($residentData['birth_place']) > 100 || mb_strlen($residentData['address']) > 1000 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $residentData['birth_date']) || !checkdate($birthMonth, $birthDay, $birthYear) || mb_strlen($residentData['rt']) > 5 || mb_strlen($residentData['rw']) > 5) {
                        throw new DomainException('Format data identitas belum benar.');
                    }
                }
                transition_application(database_connection(), (int) $application['id'], $nextStatus, $user, trim((string) ($_POST['note'] ?? '')), $residentData);
                flash('success', 'Tahap pengajuan berhasil diperbarui.');
            } catch (DomainException $error) {
                flash('error', $error->getMessage());
            }
            redirect('/admin/pengajuan/' . $application['id']);
        }
        if ($method === 'GET') {
            $history = database_connection()->prepare('SELECT h.*, u.name AS actor_name FROM letter_status_histories h LEFT JOIN users u ON u.id = h.actor_id WHERE h.application_id = ? ORDER BY h.created_at');
            $history->execute([$application['id']]);
            page('Tinjau pengajuan', 'admin_application_detail', ['application' => $application, 'history' => $history->fetchAll(), 'statuses' => APPLICATION_STATUSES], true);
        }
    }

    if (in_array($path, ['/admin/template', '/admin/generator-surat'], true)) {
        $user = require_permission('templates.manage');
        $database = database_connection();
        if ($method === 'POST') {
            verify_csrf();
            $templateId = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
            $body = sanitize_template_html((string) ($_POST['body_html'] ?? ''));
            if (!$templateId || trim(strip_tags($body)) === '') {
                flash('error', 'Isi template tidak boleh kosong.');
                redirect('/admin/template');
            }
            $statement = $database->prepare('SELECT version, body_html FROM letter_templates WHERE id = ?');
            $statement->execute([$templateId]);
            $template = $statement->fetch();
            if (!$template) {
                http_response_code(404);
                exit('Template tidak ditemukan.');
            }
            $version = (int) $template['version'];
            $database->beginTransaction();
            try {
                $database->prepare('INSERT INTO template_versions (template_id, version, body_html, changed_by) VALUES (?, ?, ?, ?)')->execute([$templateId, $version, $template['body_html'], $user['id']]);
                $database->prepare('UPDATE letter_templates SET body_html = ?, version = ? WHERE id = ?')->execute([$body, $version + 1, $templateId]);
                $database->commit();
            } catch (Throwable $error) {
                $database->rollBack();
                throw $error;
            }
            audit('template.updated', 'letter_template', $templateId, ['version' => $version + 1]);
            flash('success', 'Template tersimpan sebagai versi ' . ($version + 1) . '.');
            redirect('/admin/template');
        }
        $templates = $database->query('SELECT lt.id, lt.name, lt.body_html, lt.version, lt.is_active, ty.name AS type_name FROM letter_templates lt JOIN letter_types ty ON ty.id = lt.letter_type_id ORDER BY ty.name')->fetchAll();
        page('Template surat', 'admin_templates', ['templates' => $templates], true);
    }

    if ($path === '/admin/pengguna') {
        require_permission('users.manage');
        $database = database_connection();
        if ($method === 'POST') {
            verify_csrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $roleId = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);
            if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$roleId) {
                flash('error', 'Nama, email, dan role harus valid.');
                redirect('/admin/pengguna');
            }
            $roleCheck = $database->prepare("SELECT id FROM roles WHERE id = ? AND name <> 'warga'");
            $roleCheck->execute([$roleId]);
            $existing = $database->prepare('SELECT 1 FROM users WHERE email = ?');
            $existing->execute([$email]);
            if (!$roleCheck->fetchColumn() || $existing->fetchColumn()) {
                flash('error', 'Role tidak ditemukan atau email sudah digunakan.');
                redirect('/admin/pengguna');
            }
            $temporaryPassword = bin2hex(random_bytes(12));
            $database->prepare('INSERT INTO users (role_id, name, email, password_hash, must_change_password) VALUES (?, ?, ?, ?, 1)')
                ->execute([$roleId, $name, $email, hash_password($temporaryPassword)]);
            $newUserId = (int) $database->lastInsertId();
            audit('user.created', 'user', $newUserId, ['role_id' => $roleId]);
            flash('success', 'Akun ' . $email . ' dibuat. Kata sandi sementara (tampilkan sekali): ' . $temporaryPassword);
            redirect('/admin/pengguna');
        }
        $roles = $database->query("SELECT id, label FROM roles WHERE name <> 'warga' ORDER BY id")->fetchAll();
        $users = $database->query('SELECT u.id, u.name, u.email, u.is_active, u.must_change_password, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC LIMIT 100')->fetchAll();
        page('Pengguna dan role', 'admin_users', ['roles' => $roles, 'users' => $users], true);
    }

    if ($path === '/admin/laporan' && $method === 'GET') {
        require_permission('reports.view');
        $reports = database_connection()->query('SELECT t.name, COUNT(a.id) AS total FROM letter_types t LEFT JOIN letter_applications a ON a.letter_type_id = t.id GROUP BY t.id, t.name ORDER BY total DESC, t.name')->fetchAll();
        page('Laporan pelayanan', 'admin_reports', ['reports' => $reports], true);
    }

    if ($path === '/admin/audit' && $method === 'GET') {
        require_permission('audit.view');
        $logs = database_connection()->query('SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll();
        page('Audit aktivitas', 'admin_audit', ['logs' => $logs], true);
    }

    http_response_code(404);
    page('Halaman tidak ditemukan', 'not_found');
} catch (Throwable $error) {
    error_log('[SIPELDES] ' . $error->getMessage());
    http_response_code(500);
    page('Gangguan layanan', 'server_error');
}