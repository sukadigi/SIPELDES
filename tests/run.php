<?php
declare(strict_types=1);

$databasePath = 'storage/test-' . bin2hex(random_bytes(6)) . '.sqlite';
register_shutdown_function(static function () use ($databasePath): void {
    $path = dirname(__DIR__) . '/' . $databasePath;
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $databasePath);
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/workflow.php';

$database = database_connection();
$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql');
$database->exec($schema);

$roles = ['kepala_desa' => 'Kepala Desa', 'warga' => 'Warga', 'admin_desa' => 'Admin Desa', 'super_admin' => 'Super Admin'];
foreach ($roles as $name => $label) {
    $database->prepare('INSERT INTO roles (name, label) VALUES (?, ?)')->execute([$name, $label]);
}
$headRole = (int) $database->query("SELECT id FROM roles WHERE name = 'kepala_desa'")->fetchColumn();
$database->prepare('INSERT INTO permissions (name, label) VALUES (?, ?)')->execute(['applications.approve', 'Approve']);
$database->prepare('INSERT INTO permissions (name, label) VALUES (?, ?)')->execute(['letters.issue', 'Issue']);
$database->exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ' . $headRole . ', id FROM permissions');
$residentRole = (int) $database->query("SELECT id FROM roles WHERE name = 'warga'")->fetchColumn();
$database->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')
    ->execute([$headRole, 'Pejabat Test', 'pejabat@test.invalid', password_hash('TestPassword2026Secure', PASSWORD_DEFAULT)]);
$headId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')
    ->execute([$residentRole, 'Warga Test', 'warga@test.invalid', password_hash('TestPassword2026Secure', PASSWORD_DEFAULT)]);
$residentUserId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO residents (user_id, nik, full_name, birth_place, birth_date, address, rt, rw, verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)')
    ->execute([$residentUserId, '3200000000000001', 'Warga Test', 'Karawang', '1990-01-01', 'Alamat Uji', '001', '002']);
$residentId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO letter_types (name, code, description, requirements) VALUES (?, ?, ?, ?)')
    ->execute(['Surat Uji', 'UJI', 'Jenis surat untuk tes.', '[]']);
$typeId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO letter_templates (letter_type_id, name, body_html) VALUES (?, ?, ?)')
    ->execute([$typeId, 'Template Uji', '<p>{{nama_lengkap}}</p><p>{{nomor_register}}</p><script>alert(1)</script>']);
$templateId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO letter_applications (tracking_token, resident_id, letter_type_id, status, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute([hash('sha256', random_bytes(32)), $residentId, $typeId, 'approved', json_encode(['keperluan' => 'uji'], JSON_THROW_ON_ERROR)]);
$applicationId = (int) $database->lastInsertId();
$database->prepare('INSERT INTO letter_approvals (application_id, approver_id, decision) VALUES (?, ?, ?)')->execute([$applicationId, $headId, 'approved']);
$_SESSION['user_id'] = $headId;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$tables = $database->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'roles', 'permissions', 'residents', 'letter_types', 'letter_templates', 'letter_applications', 'letter_registers', 'letters', 'letter_versions', 'letter_approvals', 'letter_status_histories', 'notifications', 'audit_logs'] as $table) {
    $assert(in_array($table, $tables, true), 'Tabel wajib tidak ada: ' . $table);
}

$unsafe = sanitize_template_html('<p onclick="alert(1)">aman<script>alert(2)</script></p>');
$assert(!str_contains($unsafe, 'onclick') && !str_contains($unsafe, '<script'), 'Sanitizer membiarkan konten aktif.');

$application = $database->query('SELECT * FROM letter_applications WHERE id = ' . $applicationId)->fetch();
$actor = current_user();
transition_application($database, $applicationId, 'issued', $actor, 'Uji penerbitan');
$register = $database->query('SELECT register_number FROM letter_registers WHERE application_id = ' . $applicationId)->fetchColumn();
$letter = $database->query('SELECT * FROM letters WHERE application_id = ' . $applicationId)->fetch();
$assert(is_string($register) && str_contains($register, '/UJI/'), 'Nomor register tidak dibuat.');
$assert($letter && $letter['status'] === 'issued', 'Snapshot surat tidak dibuat.');
$assert(!str_contains($letter['snapshot_html'], '<script'), 'Script masuk ke snapshot surat.');
$assert((int) $database->query('SELECT COUNT(*) FROM letter_versions WHERE letter_id = ' . $letter['id'])->fetchColumn() === 1, 'Versi penerbitan awal tidak tercatat.');
$assert((int) $database->query('SELECT COUNT(*) FROM letter_verification_tokens WHERE letter_id = ' . $letter['id'])->fetchColumn() === 1, 'Token verifikasi tidak dibuat.');

$database->prepare('UPDATE letter_applications SET status = ? WHERE id = ?')->execute(['revision', $applicationId]);
issue_letter($database, $application, $actor);
$assert($database->query('SELECT register_number FROM letter_registers WHERE application_id = ' . $applicationId)->fetchColumn() === $register, 'Nomor register berubah saat revisi.');
$assert((int) $database->query('SELECT COUNT(*) FROM letter_versions WHERE letter_id = ' . $letter['id'])->fetchColumn() === 2, 'Versi koreksi tidak diarsipkan.');
$assert((int) $database->query('SELECT COUNT(*) FROM letter_verification_tokens WHERE letter_id = ' . $letter['id'] . ' AND revoked_at IS NOT NULL')->fetchColumn() === 1, 'Token versi lama tidak dicabut.');

$database->prepare('INSERT INTO letter_applications (tracking_token, resident_id, letter_type_id, status, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute([hash('sha256', random_bytes(32)), $residentId, $typeId, 'approved', '{}']);
$unapprovedId = (int) $database->lastInsertId();
$blocked = false;
try {
    transition_application($database, $unapprovedId, 'issued', $actor);
} catch (DomainException $error) {
    $blocked = str_contains($error->getMessage(), 'Persetujuan pejabat');
}
$assert($blocked, 'Surat tanpa persetujuan pejabat berhasil diterbitkan.');

$database->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')
    ->execute([(int) $database->query("SELECT id FROM roles WHERE name = 'admin_desa'")->fetchColumn(), 'Admin Uji', 'admin-uji@test.invalid', password_hash('TestPassword2026Secure', PASSWORD_DEFAULT)]);
$_SESSION['user_id'] = (int) $database->lastInsertId();
$assert(!can('letters.issue') && !can('applications.approve'), 'Admin Desa memperoleh kewenangan pejabat.');
$database->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')
    ->execute([(int) $database->query("SELECT id FROM roles WHERE name = 'super_admin'")->fetchColumn(), 'Super Admin Uji', 'super@test.invalid', password_hash('TestPassword2026Secure', PASSWORD_DEFAULT)]);
$_SESSION['user_id'] = (int) $database->lastInsertId();
$assert(!can('letters.issue') && !can('applications.approve') && can('users.manage'), 'Super Admin melewati pemisahan persetujuan pejabat.');

printf("OK: %d assertions passed.\n", $assertions);