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

/**
 * CSRF validation helper.
 * Backwards compatible: if no token is provided, reads from POST field "csrf".
 * Some endpoints (e.g. WhatsApp action links) use a CSRF token in GET; pass it as argument.
 */
function csrf_validate_or_die($token = null): void {
    session_start_safe();
    $t = ($token !== null) ? (string)$token : (string)($_POST['csrf'] ?? '');
    if (!$t || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)$t)) {
        http_response_code(403);
        echo 'CSRF inválido';
        exit;
    }
}

// Alias corto usado en algunos endpoints/admin.
function csrf_require($token = null): void {
    csrf_validate_or_die($token);
}

function csrf_field(): void {
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
