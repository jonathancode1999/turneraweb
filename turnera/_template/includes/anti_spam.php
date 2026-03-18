<?php
// Anti-spam helpers:
//  - Math captcha (session)
//  - Honeypot
//  - File-based rate limiting (per IP / phone / token)
//
// No external keys (no Google reCAPTCHA). Works locally and in production.

require_once __DIR__ . '/utils.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @ini_set('session.use_strict_mode', '1');
    @session_start();
}

function spam_client_ip(): string {
    // Conservative: prefer REMOTE_ADDR. (X-Forwarded-For can be spoofed.)
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') $ip = '0.0.0.0';
    return $ip;
}

function spam_norm_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null) $digits = '';
    // keep last 12 to avoid country code variance
    if (strlen($digits) > 12) $digits = substr($digits, -12);
    return $digits;
}

function spam_store_path(): string {
    // File-based store (shared hosting friendly).
    $dir = sys_get_temp_dir();
    if (!is_dir($dir)) $dir = __DIR__;
    return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'turnera_rate_limits.json';
}

function spam_load_store(): array {
    $path = spam_store_path();
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function spam_save_store(array $data): void {
    $path = spam_store_path();
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    @rename($tmp, $path);
}

function spam_with_lock(callable $fn) {
    $path = spam_store_path();
    $fp = @fopen($path, 'c+'); // create if missing
    if (!$fp) {
        // Fallback: best-effort without lock
        return $fn();
    }
    try {
        @flock($fp, LOCK_EX);
        return $fn();
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

function spam_hit(string $key, int $limit, int $windowSeconds): void {
    $now = time();
    spam_with_lock(function() use ($key, $limit, $windowSeconds, $now) {
        $store = spam_load_store();
        $arr = $store[$key] ?? [];
        if (!is_array($arr)) $arr = [];

        // prune old hits
        $minTs = $now - $windowSeconds;
        $arr = array_values(array_filter($arr, function($ts) use ($minTs) {
            return is_int($ts) && $ts >= $minTs;
        }));

        if (count($arr) >= $limit) {
            throw new RuntimeException('Demasiadas solicitudes. Probá de nuevo en unos minutos.');
        }

        $arr[] = $now;
        $store[$key] = $arr;

        // prune entire store occasionally
        if (count($store) > 5000) {
            $store = array_slice($store, -2500, null, true);
        }

        spam_save_store($store);
        return true;
    });
}

function spam_throttle_ip(string $action, int $limit = 30, int $windowSeconds = 300): void {
    $ip = spam_client_ip();
    spam_hit('ip:' . $action . ':' . $ip, $limit, $windowSeconds);
}

function spam_cooldown_phone(string $action, string $phone, int $limit = 3, int $windowSeconds = 120): void {
    $p = spam_norm_phone($phone);
    if ($p === '') return;
    spam_hit('ph:' . $action . ':' . $p, $limit, $windowSeconds);
}

function spam_throttle_token(string $action, string $token, int $limit = 20, int $windowSeconds = 300): void {
    $t = trim($token);
    if ($t === '') return;
    spam_hit('tok:' . $action . ':' . $t, $limit, $windowSeconds);
}

function spam_honeypot_check(string $field = 'website'): void {
    $val = trim((string)($_POST[$field] ?? ''));
    if ($val !== '') {
        throw new RuntimeException('Solicitud bloqueada.');
    }
}

/**
 * Generates a math captcha question and stores the answer in session.
 * Returns: ['question' => '¿Cuánto es 3 + 7?', 'field' => 'captcha_answer']
 */
function spam_captcha_generate(): array {
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_answer'] = (string)($a + $b);
    $_SESSION['captcha_ts'] = time();
    $q = '¿Cuánto es ' . $a . ' + ' . $b . '?';
    return ['question' => $q, 'field' => 'captcha_answer'];
}

function spam_captcha_require_post(string $field = 'captcha_answer', int $maxAgeSeconds = 3600): void {
    $expected = (string)($_SESSION['captcha_answer'] ?? '');
    $ts = (int)($_SESSION['captcha_ts'] ?? 0);
    $given = trim((string)($_POST[$field] ?? ''));

    // One-time use: clear regardless.
    unset($_SESSION['captcha_answer'], $_SESSION['captcha_ts']);

    if ($expected === '' || $ts <= 0 || (time() - $ts) > $maxAgeSeconds) {
        throw new RuntimeException('Captcha vencido. Volvé a intentar.');
    }
    if ($given === '' || !ctype_digit($given)) {
        throw new RuntimeException('Captcha inválido.');
    }
    if ((string)((int)$given) !== $expected) {
        throw new RuntimeException('Captcha incorrecto.');
    }
}

?>
