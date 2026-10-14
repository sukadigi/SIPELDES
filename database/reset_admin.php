<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/database.php';
load_environment(dirname(__DIR__) . '/.env');
$email = strtolower(trim((string) ($argv[1] ?? 'admin@sukakerta.desa.id')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Format email tidak valid.\n");
    exit(2);
}

$database = database_connection();
$query = $database->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? AND r.name = 'super_admin'");
$query->execute([$email]);
$userId = $query->fetchColumn();
if (!$userId) {
    fwrite(STDERR, "Akun Super Admin tidak ditemukan.\n");
    exit(1);
}

$temporaryPassword = bin2hex(random_bytes(16));
$algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
$database->beginTransaction();
try {
    $database->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?')
        ->execute([password_hash($temporaryPassword, $algorithm), $userId]);
    $database->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details) VALUES (NULL, ?, ?, ?, ?)')
        ->execute(['auth.admin_password_reset_cli', 'user', $userId, json_encode(['method' => 'cli'], JSON_THROW_ON_ERROR)]);
    $database->commit();
} catch (Throwable $error) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    throw $error;
}

fwrite(STDOUT, "Kredensial sementara (tampilkan sekali) untuk {$email}: {$temporaryPassword}\nWajib ganti setelah login.\n");