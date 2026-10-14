<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/database.php';
load_environment(dirname(__DIR__) . '/.env');
$database = database_connection();
$driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
$schemaPath = __DIR__ . '/schema.' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    throw new RuntimeException('Berkas skema database tidak ditemukan.');
}
$database->exec($schema);
$migration = $database->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
$migration->execute(['2026-10-01-initial']);
if (!$migration->fetchColumn()) {
    $database->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute(['2026-10-01-initial']);
}

$roles = [
    'super_admin' => 'Super Admin',
    'admin_desa' => 'Admin Desa',
    'operator' => 'Operator Desa',
    'verifikator' => 'Verifikator',
    'kepala_desa' => 'Kepala Desa / Pejabat Berwenang',
    'warga' => 'Pengguna / Warga',
    'auditor' => 'Auditor',
];
foreach ($roles as $name => $label) {
    $statement = $database->prepare('SELECT id FROM roles WHERE name = ?');
    $statement->execute([$name]);
    if (!$statement->fetch()) {
        $database->prepare('INSERT INTO roles (name, label) VALUES (?, ?)')->execute([$name, $label]);
    }
}

$permissions = ['dashboard.view', 'applications.view', 'applications.manage', 'applications.verify', 'applications.approve', 'letters.issue', 'templates.manage', 'residents.view', 'settings.manage', 'audit.view', 'reports.view', 'users.manage'];
foreach ($permissions as $permission) {
    $statement = $database->prepare('SELECT id FROM permissions WHERE name = ?');
    $statement->execute([$permission]);
    if (!$statement->fetch()) {
        $database->prepare('INSERT INTO permissions (name, label) VALUES (?, ?)')->execute([$permission, ucfirst(str_replace('.', ' ', $permission))]);
    }
}

$rolePermissions = [
    'admin_desa' => ['dashboard.view', 'applications.view', 'applications.manage', 'templates.manage', 'residents.view', 'settings.manage', 'audit.view', 'reports.view'],
    'operator' => ['dashboard.view', 'applications.view', 'applications.manage', 'reports.view'],
    'verifikator' => ['dashboard.view', 'applications.view', 'applications.verify', 'residents.view'],
    'kepala_desa' => ['dashboard.view', 'applications.view', 'applications.approve', 'letters.issue', 'reports.view'],
    'warga' => ['dashboard.view'],
    'auditor' => ['dashboard.view', 'audit.view', 'reports.view'],
];
foreach ($rolePermissions as $roleName => $allowedPermissions) {
    $roleQuery = $database->prepare('SELECT id FROM roles WHERE name = ?');
    $roleQuery->execute([$roleName]);
    $roleId = (int) $roleQuery->fetchColumn();
    foreach ($allowedPermissions as $permissionName) {
        $permissionQuery = $database->prepare('SELECT id FROM permissions WHERE name = ?');
        $permissionQuery->execute([$permissionName]);
        $permissionId = (int) $permissionQuery->fetchColumn();
        $linkQuery = $database->prepare('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?');
        $linkQuery->execute([$roleId, $permissionId]);
        if (!$linkQuery->fetchColumn()) {
            $database->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $permissionId]);
        }
    }
}

$adminRoleId = (int) $database->query("SELECT id FROM roles WHERE name = 'admin_desa'")->fetchColumn();
foreach (['applications.verify', 'applications.approve', 'letters.issue', 'users.manage'] as $restrictedPermission) {
    $database->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)')
        ->execute([$adminRoleId, $restrictedPermission]);
}

$adminRole = $database->query("SELECT id FROM roles WHERE name = 'super_admin'")->fetchColumn();
$email = 'admin@sukakerta.desa.id';
$adminExists = $database->prepare('SELECT id FROM users WHERE email = ?');
$adminExists->execute([$email]);
if (!$adminExists->fetch()) {
    $password = bin2hex(random_bytes(8));
    $database->prepare('INSERT INTO users (role_id, name, email, password_hash, must_change_password) VALUES (?, ?, ?, ?, 1)')
        ->execute([$adminRole, 'Administrator Desa', $email, password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Admin awal dibuat\nEmail: {$email}\nKata sandi sementara: {$password}\nGanti kata sandi segera setelah masuk.\n");
} else {
    fwrite(STDOUT, "Skema telah diterapkan. Admin awal sudah tersedia: {$email}\n");
}

$letterTypes = [
    ['Surat Keterangan Domisili', 'DOM', 'Keterangan tempat tinggal warga.'],
    ['Surat Keterangan Usaha', 'USH', 'Keterangan kegiatan usaha warga.'],
    ['Surat Keterangan Tidak Mampu', 'SKTM', 'Pengantar keterangan kondisi sosial warga.'],
    ['Surat Keterangan Belum Menikah', 'BMK', 'Keterangan status belum menikah.'],
    ['Surat Keterangan Umum', 'UMM', 'Surat keterangan umum sesuai kebutuhan.'],
];
foreach ($letterTypes as [$name, $code, $description]) {
    $statement = $database->prepare('SELECT id FROM letter_types WHERE code = ?');
    $statement->execute([$code]);
    if (!$statement->fetch()) {
        $database->prepare('INSERT INTO letter_types (name, code, description, requirements) VALUES (?, ?, ?, ?)')
            ->execute([$name, $code, $description, json_encode(['KTP', 'Kartu Keluarga'], JSON_THROW_ON_ERROR)]);
    }
    $typeQuery = $database->prepare('SELECT id FROM letter_types WHERE code = ?');
    $typeQuery->execute([$code]);
    $typeId = (int) $typeQuery->fetchColumn();
    $templateQuery = $database->prepare('SELECT id FROM letter_templates WHERE letter_type_id = ? LIMIT 1');
    $templateQuery->execute([$typeId]);
    if (!$templateQuery->fetchColumn()) {
        $body = '<div><strong>PEMERINTAH KABUPATEN KARAWANG</strong><br><strong>KECAMATAN CILAMAYA WETAN</strong><br><strong>DESA SUKAKERTA</strong><hr><h2>' . strtoupper($name) . '</h2></div><p>Yang bertanda tangan di bawah ini, Kepala Desa Sukakerta, menerangkan bahwa:</p><table><tr><td>Nama</td><td>{{nama_lengkap}}</td></tr><tr><td>Tempat/Tanggal Lahir</td><td>{{tempat_lahir}}, {{tanggal_lahir}}</td></tr><tr><td>Alamat</td><td>{{alamat}} RT {{rt}} / RW {{rw}}</td></tr><tr><td>Keperluan</td><td>{{keperluan}}</td></tr></table><p>Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p><p>Sukakerta, {{tanggal_surat}}<br>Kepala Desa Sukakerta,<br><br><br><strong>{{nama_pejabat}}</strong></p><p>Verifikasi: {{tautan_verifikasi}}</p>';
        $database->prepare('INSERT INTO letter_templates (letter_type_id, name, body_html) VALUES (?, ?, ?)')->execute([$typeId, $name . ' - Standar', $body]);
        $database->prepare('INSERT INTO letter_fields (letter_type_id, field_name, label, is_required, position) VALUES (?, ?, ?, ?, ?)')
            ->execute([$typeId, 'keperluan', 'Keperluan surat', 1, 1]);
    }
}

fwrite(STDOUT, "Instalasi database selesai ({$driver}).\n");