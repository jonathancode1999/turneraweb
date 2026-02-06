<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/utils.php';

function branches_all_active(): array {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $st = db()->prepare("SELECT * FROM branches WHERE business_id=:bid AND is_active=1 ORDER BY id ASC");
    $st->execute([':bid'=>$bid]);
    return $st->fetchAll();
}

function branch_get(int $branchId): ?array {
    $cfg = app_config();
    $bid = (int)$cfg['business_id'];
    $st = db()->prepare("SELECT * FROM branches WHERE business_id=:bid AND id=:id AND is_active=1 LIMIT 1");
    $st->execute([':bid'=>$bid, ':id'=>$branchId]);
    $b = $st->fetch();
    return $b ?: null;
}

// PUBLIC: determine current branch from ?branch=ID or cookie branch_id
function public_current_branch_id(): int {
    $cfg = app_config();
    $default = 1;

    $branchId = 0;
    if (isset($_GET['branch'])) {
        $branchId = (int)$_GET['branch'];
        if ($branchId > 0 && branch_get($branchId)) {
            @setcookie('branch_id', (string)$branchId, time() + 86400 * 30, '/');
            return $branchId;
        }
    }

    if (isset($_COOKIE['branch_id'])) {
        $branchId = (int)$_COOKIE['branch_id'];
        if ($branchId > 0 && branch_get($branchId)) return $branchId;
    }

    // fallback: first active branch
    $st = db()->prepare("SELECT id FROM branches WHERE business_id=:bid AND is_active=1 ORDER BY id ASC LIMIT 1");
    $st->execute([':bid'=>(int)$cfg['business_id']]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    return $default;
}

// ADMIN: require selected branch in session; if not selected, redirect to branches selector
function admin_current_branch_id(): int {
    session_start_safe();
    $allowed = admin_allowed_branch_ids();
    if (empty($allowed)) return 0;
    $cur = (int)($_SESSION['branch_id'] ?? 0);
    if ($cur <= 0 || !in_array($cur, $allowed, true)) {
        $cur = (int)$allowed[0];
        $_SESSION['branch_id'] = $cur;
    }
    return $cur;
}

function admin_require_branch_selected(): void {
    session_start_safe();
    // Ensure a valid branch is selected for this user. If none, pick the first allowed.
    if (empty($_SESSION["branch_id"])) {
        $ids = admin_allowed_branch_ids();
        if (!empty($ids)) {
            $_SESSION["branch_id"] = (int)$ids[0];
        }
    }
}
