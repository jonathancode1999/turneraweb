<?php
session_start();

// Evitar que proxies/CDN cacheen páginas del Super Admin (esto rompe CSRF y sesiones)
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

function cfg(): array {
  if (!array_key_exists('__turnera_admin_cfg_cache', $GLOBALS) || !is_array($GLOBALS['__turnera_admin_cfg_cache'])) {
    $GLOBALS['__turnera_admin_cfg_cache'] = require __DIR__.'/config.php';
  }
  return $GLOBALS['__turnera_admin_cfg_cache'];
}

// Backward-compatible alias (older code called admin_config()).
function admin_config(): array { return cfg(); }

function sa_pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  $host = $c['mysql_host'] ?? '127.0.0.1';
  $port = (int)($c['mysql_port'] ?? 3306);
  $dbn  = $c['mysql_db'] ?? '';
  $user = $c['mysql_user'] ?? '';
  $pass = $c['mysql_pass'] ?? '';
  $charset = $c['mysql_charset'] ?? 'utf8mb4';
  $dsn = "mysql:host={$host};port={$port};dbname={$dbn};charset={$charset}";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function is_logged(): bool { return !empty($_SESSION['sa_logged']); }
function require_login(): void { if(!is_logged()){ header('Location: login.php'); exit; } }

function super_admin_username(): string {
  return trim((string)(cfg()['super_user'] ?? ''));
}

function super_admin_needs_setup(): bool {
  $c = cfg();
  $user = trim((string)($c['super_user'] ?? ''));
  $email = trim((string)($c['super_email'] ?? ''));
  $question = trim((string)($c['super_security_question'] ?? ''));
  $answerHash = trim((string)($c['super_security_answer_hash'] ?? ''));
  $passHash = trim((string)($c['super_pass_hash'] ?? ''));
  if ($user === '' || $email === '' || $question === '' || $answerHash === '') {
    return true;
  }
  if ($passHash !== '') {
    return false;
  }
  return trim((string)($c['super_pass'] ?? '')) === '';
}

function super_admin_question_label(): string {
  $question = trim((string)(cfg()['super_security_question'] ?? ''));
  $questions = admin_security_questions();
  return $questions[$question] ?? $question;
}

function super_admin_verify_security_answer(string $answer): bool {
  $hash = (string)(cfg()['super_security_answer_hash'] ?? '');
  if ($hash === '') return false;
  return password_verify(admin_normalize_security_answer($answer), $hash);
}

function login_ok(string $u, string $p): bool {
  $c = cfg();
  $su = trim((string)($c['super_user'] ?? ''));
  if ($su === '' || !hash_equals($su, $u)) return false;

  $hash = trim((string)($c['super_pass_hash'] ?? ''));
  if ($hash !== '') {
    return password_verify($p, $hash);
  }

  $sp = (string)($c['super_pass'] ?? '');
  if ($sp === '') return false;
  return hash_equals($sp, $p);
}

function save_admin_config(array $patch): void {
  $current = cfg();
  $config = array_merge($current, $patch);
  $rootDir = "realpath(__DIR__ . '/..')";
  $keys = [
    'super_user',
    'super_pass',
    'super_pass_hash',
    'super_email',
    'super_security_question',
    'super_security_answer_hash',
    'root_dir',
    'mysql_host',
    'mysql_port',
    'mysql_db',
    'mysql_user',
    'mysql_pass',
    'mysql_charset',
  ];

  $lines = ["<?php", "// Super Admin config", "return ["]; 
  foreach ($keys as $key) {
    if ($key === 'root_dir') {
      $lines[] = "  'root_dir' => {$rootDir},";
      continue;
    }
    $value = $config[$key] ?? '';
    $lines[] = '  '.var_export($key, true).' => '.var_export($value, true).',';
  }
  $lines[] = '];';
  $content = implode(PHP_EOL, $lines).PHP_EOL;
  file_put_contents(__DIR__.'/config.php', $content, LOCK_EX);
  $GLOBALS['__turnera_admin_cfg_cache'] = $config;
}

function super_admin_update_credentials(string $username, string $email, string $password, string $securityQuestion, string $securityAnswer): void {
  save_admin_config([
    'super_user' => $username,
    'super_pass' => '',
    'super_pass_hash' => password_hash($password, PASSWORD_DEFAULT),
    'super_email' => $email,
    'super_security_question' => $securityQuestion,
    'super_security_answer_hash' => admin_security_answer_hash($securityAnswer),
  ]);
}

function super_admin_reset_password(string $password): void {
  save_admin_config([
    'super_pass' => '',
    'super_pass_hash' => password_hash($password, PASSWORD_DEFAULT),
  ]);
}

function flash_set(string $k, string $msg): void { $_SESSION['flash'][$k]=$msg; }
function flash_get(string $k): ?string {
  if(!isset($_SESSION['flash'][$k])) return null;
  $m=$_SESSION['flash'][$k]; unset($_SESSION['flash'][$k]); return $m;
}

function csrf_token(): string {
  if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_check(string $redirect='dashboard.php'): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash_set('err', 'Acceso inválido.');
    header('Location: '.$redirect);
    exit;
  }

  $t = (string)($_POST['csrf'] ?? '');

  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    flash_set('err', 'Sesión expirada. Recargá la página y probá de nuevo.');
    header('Location: '.$redirect);
    exit;
  }

  if (!hash_equals((string)$_SESSION['csrf'], $t)) {
    flash_set('err', 'CSRF inválido. Recargá la página y probá de nuevo.');
    header('Location: '.$redirect);
    exit;
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

function admin_normalize_security_answer(string $answer): string {
  $answer = trim(mb_strtolower($answer, 'UTF-8'));
  return preg_replace('/\s+/u', ' ', $answer) ?? '';
}

function admin_security_answer_hash(string $answer): string {
  return password_hash(admin_normalize_security_answer($answer), PASSWORD_DEFAULT);
}

function admin_password_is_strong(string $password): bool {
  if (strlen($password) < 10) return false;
  if (!preg_match('/[A-Z]/', $password)) return false;
  if (!preg_match('/[a-z]/', $password)) return false;
  if (!preg_match('/\d/', $password)) return false;
  return true;
}

function client_slug_valid(string $slug): bool {
  return (bool)preg_match('/^[a-z0-9_\-]+$/', $slug);
}
function client_dir(string $slug): string {
  $root = cfg()['root_dir'];
  return $root . DIRECTORY_SEPARATOR . $slug;
}
function client_business_id(string $slug): int {
  $cfgFile = client_dir($slug) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
  if (!file_exists($cfgFile)) throw new RuntimeException("Config no encontrada para $slug");
  $cfg = require $cfgFile;
  return (int)($cfg['business_id'] ?? 0);
}

function client_pdo(string $slug): PDO {
  return sa_pdo();
}

function list_clients(): array {
  $root = cfg()['root_dir'];
  $items = array_values(array_filter(scandir($root), function($x) use ($root){
    if($x==='.'||$x==='..') return false;
    if(in_array($x, ['admin','_template'])) return false;
    $p=$root.DIRECTORY_SEPARATOR.$x;
    return is_dir($p) && file_exists($p.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php');
  }));
  sort($items);
  return $items;
}

function client_disabled(string $slug): bool {
  return file_exists(client_dir($slug).DIRECTORY_SEPARATOR.'.disabled');
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function header_html(string $title): void {
  $u = '';
  if (is_logged()) {
    $u = '<div style="display:flex;gap:10px;align-items:center">'
       . '<a class="btn" href="dashboard.php">← Panel</a>'
       . '<a class="btn" href="logout.php">Salir</a>'
       . '</div>';
  }
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<link rel="stylesheet" href="assets/style.css"><title>'.h($title).'</title></head><body>';
  echo '<div class="container"><div class="topbar"><div><strong>Turnera • Super Admin</strong><div class="small">'.h($title).'</div></div>'.$u.'</div>';
  $ok = flash_get('ok'); $err = flash_get('err');
  if($ok) echo '<div class="notice ok">'.h($ok).'</div>';
  if($err) echo '<div class="notice err">'.h($err).'</div>';
}
function footer_html(): void { echo '</div></body></html>'; }
