<?php

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function now_tz(): DateTimeImmutable {
    $cfg = app_config();
    return new DateTimeImmutable('now', new DateTimeZone($cfg['timezone']));
}

function parse_local_datetime(string $ymd, string $hm): DateTimeImmutable {
    $cfg = app_config();
    // ymd: YYYY-MM-DD, hm: HH:MM
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $hm, new DateTimeZone($cfg['timezone']));
    if (!$dt) throw new InvalidArgumentException('Fecha/hora inválida');
    return $dt;
}

// Parse datetime stored in DB (Y-m-d H:i:s) using the configured business timezone.
// Important: DateTimeImmutable("$db") would use the server default timezone and can shift times.
function parse_db_datetime(string $db): DateTimeImmutable {
    $cfg = app_config();
    $tz = new DateTimeZone($cfg['timezone']);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $db, $tz);
    if ($dt) return $dt;
    // Fallback: let PHP parse but keep timezone consistent.
    return new DateTimeImmutable($db, $tz);
}


function fmt_datetime(string $dt): string {
    if (trim($dt) === '') return '';
    try {
        $d = parse_db_datetime($dt);
        return $d->format('d/m/Y H:i');
    } catch (Throwable $e) {
        // Fallback: return raw string if parsing fails
        return $dt;
    }
}



function fmt_money_ars(int $ars): string {
    // Stored as integer ARS (no cents) for v1.
    return '$ ' . number_format($ars, 0, ',', '.');
}

function fmt_date_es(string $ymd): string {
    // Input: YYYY-MM-DD
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return $ymd;
    return $dt->format('d/m/Y');
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function base_url(): string {
    // Works for local XAMPP and Render. Not perfect behind proxies, but ok for v1.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // script like /turnera/public/index.php -> base /turnera
    $root = preg_replace('#/public/.*$#', '', $script);
    $root = $root === '' ? '' : $root;
    return $scheme . '://' . $host . $root;
}

function public_url(string $path): string {
    return rtrim(base_url(), '/') . '/public/' . ltrim($path, '/');
}

// --- Flash messages (session) ---
// Usage:
//   flash_set('error','...');
//   $err = flash_get('error');
function flash_set(string $key, string $value): void {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][$key] = $value;
}

function flash_get(string $key): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $val = '';
    if (isset($_SESSION['_flash']) && is_array($_SESSION['_flash']) && isset($_SESSION['_flash'][$key])) {
        $val = (string)$_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
    }
    return $val;
}



function flash(string $message, string $type='ok'): void {
    // type: ok | err | warn
    flash_set('flash_type', $type);
    flash_set('flash_message', $message);
}

function flash_render(): void {
    $msg = flash_get('flash_message');
    if ($msg === '') return;
    $type = flash_get('flash_type');
    $cls = 'notice';
    if ($type === 'err') $cls .= ' danger';
    elseif ($type === 'warn') $cls .= ' warn';
    else $cls .= ' ok';
    echo '<div class="' . $cls . '">' . h($msg) . '</div>';
}
