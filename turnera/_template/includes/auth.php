<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $cfg = app_config();
        $sessionName = trim((string)($cfg['session_name'] ?? ''));
        if ($sessionName !== '') {
            session_name($sessionName);
        }
        session_start();
    }
}

function admin_security_questions(): array {
    return [
        'first_pet' => '¿Cómo se llamaba tu primera mascota?',
        'childhood_street' => '¿Cuál es el nombre de la calle donde creciste?',
        'first_school' => '¿Cómo se llamaba tu primera escuela?',
        'mother_middle_name' => '¿Cuál es el segundo nombre de tu mamá?',
        'favorite_teacher' => '¿Cómo se llamaba tu profesor/a favorito/a?',
    ];
}

function admin_security_question_label(?string $key): string {
    $key = trim((string)$key);
    $options = admin_security_questions();
    return $options[$key] ?? '';
}

function admin_normalize_security_answer(string $answer): string {
    $answer = trim(mb_strtolower($answer, 'UTF-8'));
    $answer = preg_replace('/\s+/u', ' ', $answer);
    return (string)$answer;
}

function admin_password_is_strong(string $password): bool {
    if (strlen($password) < 10) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/\d/', $password)) return false;
    return true;
}

function admin_create_security_answer_hash(string $answer): string {
    return password_hash(admin_normalize_security_answer($answer), PASSWORD_DEFAULT);
}

function admin_verify_security_answer(string $answer, string $hash): bool {
    if (trim($hash) === '') return false;
    return password_verify(admin_normalize_security_answer($answer), $hash);
}

function admin_find_user_for_password_reset(string $username, string $email): ?array {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE business_id=:bid AND username=:u AND lower(email)=lower(:e) LIMIT 1');
    $stmt->execute([
        ':bid' => $bid,
        ':u' => trim($username),
        ':e' => trim($email),
    ]);
    $user = $stmt->fetch();
    return $user ?: null;
}



function admin_needs_setup(): bool {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $pdo = db();
    try {
        $st = $pdo->prepare('SELECT COUNT(1) AS c FROM users WHERE business_id=:bid');
        $st->execute([':bid' => $bid]);
        $c = (int)($st->fetchColumn() ?: 0);
        return $c <= 0;
    } catch (Throwable $e) {
        return false;
    }
}

function admin_require_login(): void {
    session_start_safe();
    if (empty($_SESSION['admin_user'])) {
        redirect('../p9a7x_control/login.php');
    }

    // Refresh user from DB (permissions / active)
    $pdo = db();
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
    if ($uid <= 0) {
        redirect('../p9a7x_control/login.php');
    }
    $st = $pdo->prepare('SELECT * FROM users WHERE business_id=:bid AND id=:id');
    $st->execute([':bid'=>$bid, ':id'=>$uid]);
    $u = $st->fetch();
    if (!$u || (isset($u['is_active']) && (int)$u['is_active']===0)) {
        admin_logout();
        redirect('../p9a7x_control/login.php');
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
            'profesionales' => (int)($u['can_barbers'] ?? 0),
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

// -----------------------------------------------------------------------------
// Compatibility aliases (older admin pages)
// -----------------------------------------------------------------------------
// Some older client pages may still call these helpers. Keep thin wrappers so
// we don't 500 on renamed/legacy scripts.
function require_admin(): void {
    admin_require_login();
}

function require_login(): void {
    admin_require_login();
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
