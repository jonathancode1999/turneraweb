<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';

admin_require_login();
admin_require_permission('services');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();
// Slot is stored in DB (business settings). Fall back to config.php if missing.
$biz = $pdo->query('SELECT slot_minutes FROM businesses WHERE id=' . $bid)->fetch() ?: [];
$slot = (int)($biz['slot_minutes'] ?? $cfg['slot_minutes'] ?? 15);
if ($slot < 10) $slot = 10;

$notice='';$error='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_validate_or_die();
    $act = $_POST['act'] ?? '';
    try {
        if ($act==='create') {
            $name = trim($_POST['name']??'');
            $desc = trim($_POST['description']??'');
            $duration = (int)($_POST['duration']??0);
            $price = (int)($_POST['price']??0);
            $deposit = 0; // Seña deshabilitada
$img = '';
            if ($name==='') throw new RuntimeException('Nombre requerido');
            if ($duration<=0) throw new RuntimeException('Duración inválida');
            $durationRounded = round_duration_to_slot($duration, $slot);
            $pdo->prepare('INSERT INTO services (business_id,name,description,duration_minutes,price_ars,deposit_ars,image_url,is_active,updated_at)
                           VALUES (:bid,:n,:desc,:d,:p,:dep,:img,1,CURRENT_TIMESTAMP)')
                ->execute([':bid'=>$bid,':n'=>$name,':desc'=>$desc,':d'=>$durationRounded,':p'=>$price,':dep'=>$deposit,':img'=>$img]);
            $notice = 'Servicio creado (duración ajustada a ' . $durationRounded . ' min).';
        } elseif ($act==='toggle') {
            $id=(int)($_POST['id']??0);
            $pdo->prepare('UPDATE services SET is_active=CASE WHEN is_active=1 THEN 0 ELSE 1 END, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND id=:id')
                ->execute([':bid'=>$bid,':id'=>$id]);
            $notice='Actualizado.';
        } elseif ($act==='update') {
            $id=(int)($_POST['id']??0);
            $name = trim($_POST['name']??'');
            $desc = trim($_POST['description']??'');
            $duration = (int)($_POST['duration']??0);
            $price = (int)($_POST['price']??0);
            $deposit = 0; // Seña deshabilitada
$img = '';
            if ($id<=0) throw new RuntimeException('ID inválido');
            if ($name==='') throw new RuntimeException('Nombre requerido');
            if ($duration<=0) throw new RuntimeException('Duración inválida');
            $durationRounded = round_duration_to_slot($duration, $slot);
            $pdo->prepare('UPDATE services SET name=:n, description=:desc, duration_minutes=:d, price_ars=:p, deposit_ars=:dep, image_url=:img, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND id=:id')
                ->execute([':bid'=>$bid,':id'=>$id,':n'=>$name,':desc'=>$desc,':d'=>$durationRounded,':p'=>$price,':dep'=>$deposit,':img'=>$img]);
            $notice='Servicio actualizado (duración ajustada a ' . $durationRounded . ' min).';
        }
    } catch (Throwable $e) {
        $error=$e->getMessage();
    }
}

$stmt=$pdo->prepare('SELECT * FROM services WHERE business_id=:bid ORDER BY id');
$stmt->execute([':bid'=>$bid]);
$rows=$stmt->fetchAll()?:[];

page_head('Servicios','admin');
admin_nav('services');
?>

<div class="card">
  <h1>Servicios</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <div class="notice">Slot base: <b><?php echo (int)$slot; ?> min</b>. Si ponés una duración rara (ej: 27), se ajusta hacia arriba al múltiplo más cercano del slot.</div>

  <h2>Agregar</h2>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="create">
    <div style="flex:2">
      <label>Nombre</label>
      <input name="name" placeholder="Ej: Corte + Barba" required>
    </div>
    <div style="flex:3">
      <label>Descripción</label>
      <input name="description" placeholder="Ej: Incluye lavado y terminación">
    </div>
    <div>
      <label>Duración (min)</label>
      <input name="duration" type="number" min="<?php echo (int)$slot; ?>" step="<?php echo (int)$slot; ?>" value="<?php echo (int)max(30,$slot); ?>" required>
    </div>
    <div>
      <label>Precio (ARS)</label>
      <input name="price" type="number" min="0" value="12000" required>
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Crear</button>
    </div>
  </form>

  <div class="hr"></div>

  <h2>Lista</h2>
  <table class="table table-stack">
    <thead><tr><th>Servicio</th><th>Duración</th><th>Precio</th><th>Activo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td data-label="Servicio">
          <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="act" value="update">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <input name="name" value="<?php echo h($r['name']); ?>" style="min-width:180px" required>
            <input name="description" value="<?php echo h($r['description'] ?? ''); ?>" style="min-width:260px" placeholder="Descripción">
        </td>
        <td data-label="Duración"><input name="duration" type="number" min="<?php echo (int)$slot; ?>" step="<?php echo (int)$slot; ?>" value="<?php echo (int)$r['duration_minutes']; ?>" style="width:110px" required></td>
        <td data-label="Precio"><input name="price" type="number" min="0" value="<?php echo (int)($r['price_ars'] ?? 0); ?>" style="width:120px" required></td>
	    <?php // Seña deshabilitada: no se muestra ni se edita. ?>
	    <td data-label="Activo"><?php echo (int)$r['is_active']===1 ? 'Sí' : 'No'; ?></td>
        <td data-label="Acciones">
            <div class="row-actions"><button class="btn" type="submit">Guardar</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="act" value="toggle">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn" type="submit"><?php echo (int)$r['is_active']===1 ? 'Desactivar' : 'Activar'; ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php page_foot(); ?>
