<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/utils.php';

function csrf_token(): string {
    session_start_safe();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(16);
    }
    return $_SESSION['csrf'];
}

function csrf_validate_or_die(): void {
    session_start_safe();
    $t = $_POST['csrf'] ?? '';
    if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(403);
        echo 'CSRF inválido';
        exit;
    }
}

// Alias corto usado en algunos endpoints/admin.
function csrf_require(): void {
    csrf_validate_or_die();
}

function csrf_field(): void {
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
