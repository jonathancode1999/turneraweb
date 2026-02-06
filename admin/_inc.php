<?php
declare(strict_types=1);
session_start();

function cfg(): array { static $c=null; if($c===null){ $c=require __DIR__.'/config.php'; } return $c; }

function is_logged(): bool { return !empty($_SESSION['sa_logged']); }
function require_login(): void { if(!is_logged()){ header('Location: login.php'); exit; } }

function login_ok(string $u, string $p): bool {
  $c = cfg();
  return hash_equals($c['super_user'], $u) && hash_equals($c['super_pass'], $p);
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
  $t = $_POST['csrf'] ?? '';
  if(empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)){
    http_response_code(400);
    echo "CSRF inválido";
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
function client_db_path(string $slug): string {
  return client_dir($slug) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite';
}
function client_pdo(string $slug): PDO {
  $path = client_db_path($slug);
  if(!file_exists($path)) throw new RuntimeException("DB no encontrada para $slug");
  $pdo = new PDO('sqlite:' . $path);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA foreign_keys = ON;");
  return $pdo;
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
