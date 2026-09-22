<?php
/**
 * KETAN M/A B COMPLEX - Session & Role-Based Access Control (RBAC)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['user_username'] ?? '',
        'full_name' => $_SESSION['user_full_name'] ?? '',
        'email'     => $_SESSION['user_email'] ?? '',
        'role'      => $_SESSION['user_role'] ?? '',
        'phone'     => $_SESSION['user_phone'] ?? ''
    ];
}

function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function currentUserRole(): ?string {
    return $_SESSION['user_role'] ?? null;
}

function isAdmin(): bool {
    $role = strtolower(trim((string)currentUserRole()));
    return in_array($role, ['admin', 'headteacher', 'superadmin', 'super admin'], true);
}

function isHeadTeacher(): bool {
    $role = strtolower(trim((string)currentUserRole()));
    return in_array($role, ['admin', 'headteacher', 'superadmin', 'super admin'], true);
}

function isTeacher(): bool {
    $role = strtolower(trim((string)currentUserRole()));
    return $role === 'teacher';
}

function hasRole(array|string $roles): bool {
    $current = currentUserRole();
    if (!$current) return false;
    if (is_string($roles)) {
        $roles = [$roles];
    }
    return in_array($current, $roles, true);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        flash('warning', 'Please log in to access this page.');
        header('Location: ' . url('login.php'));
        exit;
    }
}

function requireRole(array|string $roles): void {
    requireLogin();
    if (!hasRole($roles)) {
        flash('danger', 'Access denied. You do not have permission to view that page.');
        header('Location: ' . url('dashboard.php'));
        exit;
    }
}

/**
 * Record an audit log entry
 */
function logActivity(string $action, string $description, ?string $entityType = null, ?int $entityId = null): void {
    try {
        $db = getDB();
        if (!($db instanceof PDO)) {
            return;
        }
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, username, role, action, entity_type, entity_id, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $user = currentUser();
        $userId = $user['id'] ?? null;
        $username = $user['username'] ?? 'guest';
        $role = $user['role'] ?? 'guest';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $stmt->execute([
            $userId,
            $username,
            $role,
            $action,
            $entityType,
            $entityId,
            $description,
            $ip,
            $agent
        ]);
    } catch (Throwable $e) {
        // Suppress audit log failure so main operation doesn't crash
        error_log("Audit log failed: " . $e->getMessage());
    }
}
