<?php
// ============================================================
// TKMI Badminton Tournament Platform — Auth Helpers
// ============================================================

require_once __DIR__ . '/../config/constants.php';

/**
 * Start session if not already started.
 */
function sessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('TKMI_SESSION');
        session_start();
    }
}

/**
 * Log in an admin and store session.
 */
function loginAdmin(array $admin): void {
    sessionStart();
    session_regenerate_id(true);
    $_SESSION[SESSION_ADMIN_KEY] = [
        'id'             => $admin['id'],
        'username'       => $admin['username'],
        'display_name'   => $admin['display_name'],
        'is_super_admin' => (bool) $admin['is_super_admin'],
        'logged_in_at'   => time(),
    ];
}

/**
 * Log out the current admin.
 */
function logoutAdmin(): void {
    sessionStart();
    session_unset();
    session_destroy();
}

/**
 * Check if an admin is currently logged in and session is valid.
 */
function isAdminLoggedIn(): bool {
    sessionStart();
    if (empty($_SESSION[SESSION_ADMIN_KEY])) {
        return false;
    }
    // Session timeout check
    $loggedInAt = $_SESSION[SESSION_ADMIN_KEY]['logged_in_at'] ?? 0;
    if ((time() - $loggedInAt) > SESSION_TIMEOUT) {
        logoutAdmin();
        return false;
    }
    return true;
}

/**
 * Redirect to login page if not logged in.
 */
function requireLogin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: ' . BASE_URL . '/admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

/**
 * Check if the current admin is a super admin.
 */
function isSuperAdmin(): bool {
    sessionStart();
    return !empty($_SESSION[SESSION_ADMIN_KEY]['is_super_admin']);
}

/**
 * Require super admin access, redirect if not.
 */
function requireSuperAdmin(): void {
    requireLogin();
    if (!isSuperAdmin()) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php?error=unauthorized');
        exit;
    }
}

/**
 * Get current admin session data.
 */
function currentAdmin(): ?array {
    sessionStart();
    return $_SESSION[SESSION_ADMIN_KEY] ?? null;
}

/**
 * Attempt to authenticate admin by username + password.
 * Returns admin row on success, null on failure.
 */
function attemptLogin(string $username, string $password): ?array {
    require_once __DIR__ . '/../config/db.php';
    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([trim($username)]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        return $admin;
    }
    return null;
}
