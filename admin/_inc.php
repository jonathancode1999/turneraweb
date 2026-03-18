<?php
declare(strict_types=1);
session_start();

// Evitar que proxies/CDN cacheen páginas del Super Admin (esto rompe CSRF y sesiones)
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

function cfg(): array { static $c=null; if($c===null){ $c=require __DIR__.'/config.php'; } return $c; }

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

function login_ok(string $u, string $p): bool {
  $c = cfg();
  $su = (string)($c['super_user'] ?? '');
  $sp = (string)($c['super_pass'] ?? '');
  if ($su === '' || $sp === '') return false;
  return hash_equals($su, $u) && hash_equals($sp, $p);
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
function csrf_check(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash_set('err', 'Acceso inválido.');
    header('Location: dashboard.php');
    exit;
  }

  $t = (string)($_POST['csrf'] ?? '');

  // Si por algún motivo el token no existe en sesión (sesión expirada / cache),
  // pedimos recargar y reintentar en lugar de mostrar un 400 críptico.
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    flash_set('err', 'Sesión expirada. Recargá la página y probá de nuevo.');
    header('Location: dashboard.php');
    exit;
  }

  if (!hash_equals((string)$_SESSION['csrf'], $t)) {
    flash_set('err', 'CSRF inválido. Recargá la página y probá de nuevo.');
    header('Location: dashboard.php');
    exit;
  }
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
  // Shared MySQL DB for all clients. Each client points to its business_id in its own config.php
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
