<?php
require __DIR__.'/_inc.php'; require_login();

$pdo = sa_pdo();

// Ensure meta table exists (should, but safe)
$pdo->exec("CREATE TABLE IF NOT EXISTS meta (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_check();
  $cid = trim((string)($_POST['mp_client_id'] ?? ''));
  $csec = trim((string)($_POST['mp_client_secret'] ?? ''));
  $ruri = trim((string)($_POST['mp_redirect_uri'] ?? ''));

  try {
    $st = $pdo->prepare("REPLACE INTO meta(`key`,`value`) VALUES(:k,:v)");
    $st->execute([':k'=>'mp_client_id', ':v'=>$cid]);
    $st->execute([':k'=>'mp_client_secret', ':v'=>$csec]);
    $st->execute([':k'=>'mp_redirect_uri', ':v'=>$ruri]); // opcional (si queda vacío, se auto-deduce por cliente)
    $notice = 'Guardado.';
  } catch (Throwable $e) {
    $error = 'No se pudo guardar: ' . $e->getMessage();
  }
}

function meta_get_sa(PDO $pdo, string $k): string {
  $st = $pdo->prepare("SELECT `value` FROM meta WHERE `key`=:k LIMIT 1");
  $st->execute([':k'=>$k]);
  $v = $st->fetchColumn();
  return $v===false ? '' : (string)$v;
}

$cid = meta_get_sa($pdo, 'mp_client_id');
$csec = meta_get_sa($pdo, 'mp_client_secret');
$ruri = meta_get_sa($pdo, 'mp_redirect_uri');

header_html('MercadoPago (técnico)');
?>
<div class="card" style="max-width:820px;">
  <h2 style="margin-top:0;">MercadoPago (configuración técnica)</h2>

  <?php if($notice!==''): ?>
    <div class="alert success"><?php echo h($notice); ?></div>
  <?php endif; ?>
  <?php if($error!==''): ?>
    <div class="alert error"><?php echo h($error); ?></div>
  <?php endif; ?>

  <p class="muted">
    Esto se configura una sola vez para el servidor. El cliente solo hace “Conectar MercadoPago”.
    <br>Si dejás <b>Redirect URI</b> vacío, el sistema lo va a intentar deducir automáticamente por cada cliente:
    <code>https://&lt;dominio&gt;/&lt;cliente&gt;/admin/mp_callback.php</code>
  </p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <label>MP_CLIENT_ID</label>
    <input type="text" name="mp_client_id" value="<?php echo h($cid); ?>" placeholder="1234567890">

    <label style="margin-top:10px;">MP_CLIENT_SECRET</label>
    <input type="text" name="mp_client_secret" value="<?php echo h($csec); ?>" placeholder="APP_USR-...">

    <label style="margin-top:10px;">MP_REDIRECT_URI (opcional)</label>
    <input type="text" name="mp_redirect_uri" value="<?php echo h($ruri); ?>" placeholder="https://tudominio.com/cliente/admin/mp_callback.php">

    <div style="margin-top:12px;display:flex;gap:10px;">
      <button class="btn" type="submit">Guardar</button>
      <a class="btn" href="dashboard.php">Volver</a>
    </div>
  </form>
</div>
