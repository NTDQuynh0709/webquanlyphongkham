<?php
// auth.php (GUARD)
if (session_status() === PHP_SESSION_NONE) {

    // 24 tiếng
    ini_set('session.gc_maxlifetime', 24 * 60 * 60);
    ini_set('session.cookie_lifetime', 24 * 60 * 60);

    // giảm khả năng bị dọn session quá sớm
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);

    session_start();
}

function require_login(): void {
    if (empty($_SESSION['role'])) {
        header('Location: /login.php');
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        // role sai thì vẫn chặn (đúng bảo mật)
        http_response_code(403);
        exit('Forbidden');
    }
}

function require_any_role(array $roles): void {
    require_login();
    $r = $_SESSION['role'] ?? '';
    if (!in_array($r, $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}