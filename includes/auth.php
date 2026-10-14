<?php
declare(strict_types=1);

function current_user(): ?array
{
    static $loadedUserId = null;
    static $user = null;
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($userId === null) {
        $loadedUserId = null;
        $user = null;
        return null;
    }
    if ($loadedUserId === $userId) {
        return $user;
    }
    $loadedUserId = $userId;

    $statement = database_connection()->prepare(
        'SELECT users.id, users.name, users.email, users.must_change_password, roles.name AS role, roles.label AS role_label '
        . 'FROM users JOIN roles ON roles.id = users.role_id WHERE users.id = ? AND users.is_active = 1'
    );
    $statement->execute([$userId]);
    $user = $statement->fetch() ?: null;
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Masuk terlebih dahulu untuk melanjutkan.');
        redirect('/login');
    }
    return $user;
}

function can(string $permission): bool
{
    $user = current_user();
    if ($user === null) {
        return false;
    }
    if ($user['role'] === 'super_admin' && !in_array($permission, ['applications.approve', 'letters.issue'], true)) {
        return true;
    }
    $statement = database_connection()->prepare(
        'SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id '
        . 'JOIN users u ON u.role_id = rp.role_id WHERE u.id = ? AND p.name = ? LIMIT 1'
    );
    $statement->execute([$user['id'], $permission]);
    return (bool) $statement->fetchColumn();
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!can($permission)) {
        http_response_code(403);
        exit('Akses tidak diizinkan.');
    }
    return $user;
}

function audit(string $action, string $entity, ?int $entityId = null, array $details = []): void
{
    $user = current_user();
    $statement = database_connection()->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $user['id'] ?? null,
        $action,
        $entity,
        $entityId,
        json_encode($details, JSON_THROW_ON_ERROR),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}