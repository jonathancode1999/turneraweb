<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function admin_require_login(): void {
    session_start_safe();
    if (empty($_SESSION['admin_user'])) {
        redirect('../admin/login.php');
    }

    // Refresh user from DB (permissions / active)
    $pdo = db();
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
    if ($uid <= 0) {
        redirect('../admin/login.php');
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE business_id=:bid AND id=:id');
    $st->execute([':bid'=>$bid, ':id'=>$uid]);
    $u = $st->fetch();
    if (!$u || (isset($u['is_active']) && (int)$u['is_active']===0)) {
        admin_logout();
        redirect('../admin/login.php');
    }
    // Cache essentials
    $_SESSION['admin_user'] = [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'role' => $u['role'],
        'all_branches' => isset($u['all_branches']) ? (int)$u['all_branches'] : 1,
        'perms' => [
            'branches' => (int)($u['can_branches'] ?? 0),
            'settings' => (int)($u['can_settings'] ?? 0),
            'appointments' => (int)($u['can_appointments'] ?? 1),
            'barbers' => (int)($u['can_barbers'] ?? 0),
            'services' => (int)($u['can_services'] ?? 0),
            'hours' => (int)($u['can_hours'] ?? 0),
            'blocks' => (int)($u['can_blocks'] ?? 0),
            'system' => (int)($u['can_system'] ?? 0),
            'analytics' => (int)($u['can_analytics'] ?? 0),
        ],
    ];
}

function admin_login(string $username, string $password): bool {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE business_id=:bid AND username=:u');
    $stmt->execute([':bid' => $bid, ':u' => $username]);
    $u = $stmt->fetch();
    if ($u && isset($u['is_active']) && (int)$u['is_active']===0) return false;
    if (!$u) return false;
    if (!password_verify($password, $u['password_hash'])) return false;

    session_start_safe();
    $_SESSION['admin_user'] = [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'role' => $u['role'],
        'all_branches' => isset($u['all_branches']) ? (int)$u['all_branches'] : 1,
    ];
    return true;
}

function admin_is_admin(): bool {
    session_start_safe();
    return !empty($_SESSION['admin_user']) && ($_SESSION['admin_user']['role'] ?? '') === 'admin';
}

function admin_can(string $permKey): bool {
    session_start_safe();
    if (admin_is_admin()) return true;
    $perms = $_SESSION['admin_user']['perms'] ?? [];
    return !empty($perms[$permKey]);
}

function admin_require_permission(string $permKey): void {
    if (!admin_can($permKey)) {
        http_response_code(403);
        echo '<h2 style="font-family:system-ui">No tenés permiso para acceder a esta sección.</h2>';
        exit;
    }
}

function admin_allowed_branch_ids(): array {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $pdo = db();
    session_start_safe();
    if (admin_is_admin() || (int)($_SESSION['admin_user']['all_branches'] ?? 1) === 1) {
        $st = $pdo->prepare("SELECT id FROM branches WHERE business_id=:bid AND is_active=1 ORDER BY id ASC");
        $st->execute([':bid'=>$bid]);
        return array_map('intval', array_column($st->fetchAll(), 'id'));
    }
    $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
    $st = $pdo->prepare("SELECT branch_id FROM user_branch_access WHERE business_id=:bid AND user_id=:uid ORDER BY branch_id ASC");
    $st->execute([':bid'=>$bid, ':uid'=>$uid]);
    return array_map('intval', array_column($st->fetchAll(), 'branch_id'));
}

function admin_logout(): void {
    session_start_safe();
    $_SESSION = [];
    session_destroy();
}
