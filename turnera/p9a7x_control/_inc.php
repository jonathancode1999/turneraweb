<?php
$__turneraBootConfig = require __DIR__.'/config.php';
if (session_status() === PHP_SESSION_NONE) {
  $sessionName = trim((string)($__turneraBootConfig['session_name'] ?? ''));
  if ($sessionName !== '') {
    session_name($sessionName);
  }
  session_start();
}

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

function admin_config(): array { return cfg(); }

function admin_demo_slug(): string { return 'turnera_demo'; }

function admin_app_dir(): string {
  return cfg()['app_dir'] ?? realpath(__DIR__ . '/..');
}

function admin_template_dir(): string {
  return admin_app_dir() . DIRECTORY_SEPARATOR . '_template';
}

function admin_app_slug(): string {
  return basename(admin_app_dir());
}

function admin_install_base_path(): string {
  $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
  $appSegment = '/' . trim(admin_app_slug(), '/') . '/';
  $pos = strpos($script, $appSegment);
  if ($pos === false) {
    return '';
  }
  $base = rtrim(substr($script, 0, $pos), '/');
  return $base === '/' ? '' : $base;
}

function client_web_path(string $slug, string $suffix = ''): string {
  $path = admin_install_base_path() . '/' . trim($slug, '/');
  if ($suffix !== '') {
    $path .= '/' . ltrim($suffix, '/');
  }
  return $path;
}

function admin_client_control_dir(): string {
  return 'p9a7x_control';
}

function admin_client_legacy_control_dir(): string {
  return 'admin';
}

function admin_recursive_copy(string $src, string $dst): void {
  if (!is_dir($dst)) {
    mkdir($dst, 0777, true);
  }
  $dir = opendir($src);
  if ($dir === false) {
    throw new RuntimeException('No se pudo abrir el template base.');
  }
  while (false !== ($file = readdir($dir))) {
    if ($file === '.' || $file === '..') continue;
    $s = $src . DIRECTORY_SEPARATOR . $file;
    $d = $dst . DIRECTORY_SEPARATOR . $file;
    if (is_dir($s)) {
      admin_recursive_copy($s, $d);
    } else {
      copy($s, $d);
    }
  }
  closedir($dir);
}

function admin_recursive_move_contents(string $src, string $dst): void {
  if (!is_dir($src)) {
    return;
  }
  if (!is_dir($dst)) {
    mkdir($dst, 0777, true);
  }
  $items = scandir($src);
  if ($items === false) {
    return;
  }
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $from = $src . DIRECTORY_SEPARATOR . $item;
    $to = $dst . DIRECTORY_SEPARATOR . $item;
    if (is_dir($from)) {
      if (is_dir($to)) {
        admin_recursive_move_contents($from, $to);
        @rmdir($from);
      } else {
        @rename($from, $to);
      }
      continue;
    }
    if (file_exists($to)) {
      @unlink($to);
    }
    @rename($from, $to);
  }
  @rmdir($src);
}

function admin_client_runtime_files(): array {
  return [
    'index.php',
    'api.php',
    'create_booking.php',
    'manage.php',
    'manage_lookup.php',
    'ics.php',
    'pay.php',
    'success.php',
    'mp_return.php',
    'mp_webhook.php',
    'includes/auth.php',
    'includes/config.php',
    'includes/layout.php',
    'includes/mercadopago.php',
    'includes/notifications.php',
    'includes/uploads.php',
    'includes/utils.php',
    'includes/whatsapp.php',
    'p9a7x_control/index.php',
    'p9a7x_control/login.php',
    'p9a7x_control/dashboard.php',
    'p9a7x_control/profesionales.php',
    'p9a7x_control/profesional_edit.php',
    'p9a7x_control/settings.php',
    'p9a7x_control/reschedule.php',
    'p9a7x_control/wa_action.php',
  ];
}

function admin_client_force_refresh_files(): array {
  return [
    'includes/auth.php',
    'includes/config.php',
    'includes/layout.php',
    'includes/mercadopago.php',
    'includes/notifications.php',
    'includes/uploads.php',
    'includes/utils.php',
    'includes/whatsapp.php',
    'p9a7x_control/login.php',
  ];
}

function admin_remove_client_legacy_auth_files(string $target): void {
  foreach (['setup.php', 'forgot_password.php'] as $legacy) {
    $path = $target . DIRECTORY_SEPARATOR . admin_client_control_dir() . DIRECTORY_SEPARATOR . $legacy;
    if (is_file($path)) {
      @unlink($path);
    }
  }
}

function admin_client_file_looks_legacy(string $path): bool {
  if (!is_file($path)) {
    return false;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return false;
  }
  foreach (["__DIR__ . '/../includes/'", "../includes/", "/public/", "/admin/"] as $needle) {
    if (strpos($content, $needle) !== false) {
      return true;
    }
  }
  return false;
}

function admin_refresh_client_runtime_files(string $target): void {
  admin_remove_client_legacy_auth_files($target);
  $template = admin_template_dir();
  $forceRefresh = array_flip(admin_client_force_refresh_files());
  foreach (admin_client_runtime_files() as $rel) {
    $src = $template . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dst = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $needsRefresh = isset($forceRefresh[$rel]) || admin_client_file_looks_legacy($dst);
    if (!is_file($src) || !$needsRefresh) {
      continue;
    }
    if (!is_dir(dirname($dst))) {
      mkdir(dirname($dst), 0777, true);
    }
    copy($src, $dst);
  }
}

function admin_normalize_client_layout(string $target): void {
  if (!is_dir($target)) {
    return;
  }

  $legacyPublicDir = $target . DIRECTORY_SEPARATOR . 'public';
  if (is_dir($legacyPublicDir)) {
    admin_recursive_move_contents($legacyPublicDir, $target);
  }

  $legacyAdminDir = $target . DIRECTORY_SEPARATOR . admin_client_legacy_control_dir();
  $clientControlDir = $target . DIRECTORY_SEPARATOR . admin_client_control_dir();
  if (is_dir($legacyAdminDir)) {
    if (is_dir($clientControlDir)) {
      admin_recursive_move_contents($legacyAdminDir, $clientControlDir);
    } else {
      @rename($legacyAdminDir, $clientControlDir);
    }
  }
}

function admin_client_ready(string $target): bool {
  return is_dir($target)
    && is_file($target . DIRECTORY_SEPARATOR . 'index.php')
    && is_dir($target . DIRECTORY_SEPARATOR . 'includes')
    && is_file($target . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php')
    && is_dir($target . DIRECTORY_SEPARATOR . admin_client_control_dir())
    && is_file($target . DIRECTORY_SEPARATOR . admin_client_control_dir() . DIRECTORY_SEPARATOR . 'index.php');
}

function ensure_demo_client_exists(): void {
  $demoSlug = admin_demo_slug();
  $target = client_dir($demoSlug);
  if (is_dir($target)) {
    admin_normalize_client_layout($target);
    admin_refresh_client_runtime_files($target);
    if (admin_client_ready($target)) {
      return;
    }
  }

  $templateDir = admin_template_dir();
  if (!is_dir($templateDir)) {
    return;
  }

  admin_recursive_copy($templateDir, $target);
  admin_normalize_client_layout($target);
  admin_refresh_client_runtime_files($target);
}

function sa_schema_candidate_paths(): array {
  $appDir = admin_app_dir();
  return array_values(array_unique([
    $appDir . DIRECTORY_SEPARATOR . 'schema_mysql.sql',
    $appDir . DIRECTORY_SEPARATOR . '_template' . DIRECTORY_SEPARATOR . 'schema_mysql.sql',
  ]));
}

function sa_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
  $st->execute([':t' => $table]);
  return ((int)$st->fetchColumn()) > 0;
}

function sa_apply_sql_batch(PDO $pdo, string $raw): void {
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $raw = preg_replace('#/\*.*?\*/#s', '', $raw) ?? $raw;
  foreach (explode(';', $raw) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || preg_match('/^(--|#)/', $stmt)) continue;
    $pdo->exec($stmt);
  }
}

function sa_bootstrap_schema_if_needed(PDO $pdo): void {
  if (sa_table_exists($pdo, 'businesses') && sa_table_exists($pdo, 'users') && sa_table_exists($pdo, 'branches')) {
    return;
  }

  $imported = false;
  foreach (sa_schema_candidate_paths() as $path) {
    if (!is_file($path)) continue;
    $raw = file_get_contents($path);
    if ($raw === false) {
      throw new RuntimeException('No se pudo leer el schema inicial: ' . $path);
    }
    sa_apply_sql_batch($pdo, $raw);
    $imported = true;
    break;
  }

  if ($imported) {
    foreach (sa_schema_candidate_paths() as $path) {
      if (is_file($path)) {
        @unlink($path);
      }
    }
  }
}

function sa_pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  $host = $c['db_host'] ?? ($c['mysql_host'] ?? 'localhost');
  $port = (int)($c['db_port'] ?? ($c['mysql_port'] ?? 3306));
  $dbn  = $c['db_name'] ?? ($c['mysql_db'] ?? '');
  $user = $c['db_user'] ?? ($c['mysql_user'] ?? '');
  $pass = $c['db_pass'] ?? ($c['mysql_pass'] ?? '');
  $charset = $c['db_charset'] ?? ($c['mysql_charset'] ?? 'utf8mb4');

  if (!empty($c['require_env_secrets']) && trim((string)$pass) === '') {
    throw new RuntimeException('Falta TURNERA_DB_PASS y require_env_secrets está activo.');
  }

  $dsn = "mysql:host={$host};port={$port};dbname={$dbn};charset={$charset}";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  sa_bootstrap_schema_if_needed($pdo);
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

  $content = "<?php\n";
  $content .= "$" . "requireEnvSecrets = in_array(strtolower((string)getenv('TURNERA_REQUIRE_ENV_SECRETS')), ['1', 'true', 'yes', 'on'], true);\n";
  $content .= "$" . "dbHost = getenv('TURNERA_DB_HOST') ?: 'localhost';\n";
  $content .= "$" . "dbPort = (int)(getenv('TURNERA_DB_PORT') ?: 3306);\n";
  $content .= "$" . "dbName = getenv('TURNERA_DB_NAME') ?: 'turnera_db';\n";
  $content .= "$" . "dbUser = getenv('TURNERA_DB_USER') ?: 'turnera_user';\n";
  $content .= "$" . "dbPass = getenv('TURNERA_DB_PASS');\n";
  $content .= '$dbPass = ($dbPass !== false && $dbPass !== \'\') ? $dbPass : \'!2000jo1900lb!\';' . "\n";
  $content .= "$" . "dbCharset = getenv('TURNERA_DB_CHARSET') ?: 'utf8mb4';\n\n";
  $content .= "return [\n";
  $content .= "  'base_path' => '',\n\n";
  foreach (['super_user','super_pass','super_pass_hash','super_email','super_security_question','super_security_answer_hash'] as $key) {
    $content .= '  '.var_export($key, true).' => '.var_export($config[$key] ?? '', true).",\n";
  }
  $content .= "\n  'root_dir' => realpath(__DIR__ . '/../..'),\n";
  $content .= "  'app_dir' => realpath(__DIR__ . '/..'),\n\n";
  $content .= "  'db_host' => $" . "dbHost,\n";
  $content .= "  'db_port' => $" . "dbPort,\n";
  $content .= "  'db_name' => $" . "dbName,\n";
  $content .= "  'db_user' => $" . "dbUser,\n";
  $content .= "  'db_pass' => $" . "dbPass,\n";
  $content .= "  'db_charset' => $" . "dbCharset,\n\n";
  $content .= "  'mysql_host' => $" . "dbHost,\n";
  $content .= "  'mysql_port' => $" . "dbPort,\n";
  $content .= "  'mysql_db' => $" . "dbName,\n";
  $content .= "  'mysql_user' => $" . "dbUser,\n";
  $content .= "  'mysql_pass' => $" . "dbPass,\n";
  $content .= "  'mysql_charset' => $" . "dbCharset,\n\n";
  $content .= "  'auth_secret' => getenv('TURNERA_AUTH_SECRET') ?: '',\n";
  $content .= "  'require_env_secrets' => $" . "requireEnvSecrets,\n";
  $content .= "  'session_name' => getenv('TURNERA_SESSION_NAME') ?: 'TURNERA_SUPERADMIN_SESSID',\n";
  $content .= "  'admin_gate_key' => getenv('TURNERA_ADMIN_GATE_KEY') ?: '',\n";
  $content .= "];\n";

  file_put_contents(__DIR__.'/config.php', $content, LOCK_EX);
  $GLOBALS['__turnera_admin_cfg_cache'] = require __DIR__.'/config.php';
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

function admin_password_requirements(): array {
  return [
    'min_length' => 'Mínimo 10 caracteres.',
    'uppercase' => 'Al menos una letra mayúscula.',
    'lowercase' => 'Al menos una letra minúscula.',
    'number' => 'Al menos un número.',
    'special' => 'Al menos un caracter especial.',
    'no_sequence' => 'Sin números consecutivos de 3 dígitos o más.',
  ];
}

function admin_password_validation_state(string $password): array {
  $checks = [
    'min_length' => mb_strlen($password, 'UTF-8') >= 10,
    'uppercase' => (bool)preg_match('/\p{Lu}/u', $password),
    'lowercase' => (bool)preg_match('/\p{Ll}/u', $password),
    'number' => (bool)preg_match('/\d/', $password),
    'special' => (bool)preg_match('/[^\p{L}\d\s]/u', $password),
    'no_sequence' => !admin_password_has_consecutive_numbers($password),
  ];
  return $checks;
}

function admin_password_has_consecutive_numbers(string $password): bool {
  preg_match_all('/\d+/', $password, $matches);
  foreach ($matches[0] as $group) {
    $digits = array_map('intval', str_split($group));
    $runLength = 1;
    for ($i = 1, $len = count($digits); $i < $len; $i++) {
      $diff = $digits[$i] - $digits[$i - 1];
      if ($diff === 1 || $diff === -1) {
        $runLength++;
        if ($runLength >= 3) return true;
      } else {
        $runLength = 1;
      }
    }
  }
  return false;
}

function admin_password_errors(string $password): array {
  $labels = admin_password_requirements();
  $state = admin_password_validation_state($password);
  $errors = [];
  foreach ($state as $key => $ok) {
    if (!$ok && isset($labels[$key])) {
      $errors[] = $labels[$key];
    }
  }
  return $errors;
}

function admin_password_is_strong(string $password): bool {
  return admin_password_errors($password) === [];
}

function admin_password_error_message(string $password): string {
  $errors = admin_password_errors($password);
  if ($errors === []) {
    return '';
  }
  return 'La contraseña no cumple los requisitos: '.implode(' ', $errors);
}

function render_password_toggle_button(string $targetId): string {
  return '<button class="btn toggle-password" type="button" data-toggle-password="'.h($targetId).'" aria-label="Mostrar contraseña" aria-pressed="false">'
    . '<span class="toggle-password__icon toggle-password__icon--show" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M1.5 12s3.8-6.5 10.5-6.5S22.5 12 22.5 12s-3.8 6.5-10.5 6.5S1.5 12 1.5 12Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg></span>'
    . '<span class="toggle-password__icon toggle-password__icon--hide" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 3l18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M10.7 5.7A11.8 11.8 0 0 1 12 5.5C18.7 5.5 22.5 12 22.5 12a19.5 19.5 0 0 1-4.3 4.9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.1 6.1A19.1 19.1 0 0 0 1.5 12S5.3 18.5 12 18.5c1.7 0 3.2-.4 4.6-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.9 9.9A3.2 3.2 0 0 0 12 15.2c.8 0 1.6-.3 2.1-.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
    . '<span class="toggle-password__text">Mostrar</span>'
    . '</button>';
}

function render_password_requirements_block(): string {
  $items = [];
  foreach (admin_password_requirements() as $key => $label) {
    $items[] = '<li class="password-rule" data-rule="'.h($key).'"><span class="password-rule__status" aria-hidden="true"></span><span class="password-rule__text">'.h($label).'</span></li>';
  }
  return '<div class="password-requirements" data-password-requirements hidden>'
    . '<div class="password-requirements__title">La contraseña debe cumplir con todo esto</div>'
    . '<ul class="password-requirements__list">'.implode('', $items).'</ul>'
    . '</div>';
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
  ensure_demo_client_exists();
  $root = cfg()['root_dir'];
  $appBase = basename(admin_app_dir());
  $items = [];
  $scan = scandir($root);
  if ($scan === false) {
    return $items;
  }
  foreach ($scan as $x) {
    if ($x === '.' || $x === '..') continue;
    if ($x === $appBase) continue;
    $p = $root . DIRECTORY_SEPARATOR . $x;
    if (!is_dir($p)) continue;
    if (!is_file($p . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php')) continue;
    admin_normalize_client_layout($p);
    admin_refresh_client_runtime_files($p);
    if (admin_client_ready($p)) {
      $items[] = $x;
    }
  }
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
