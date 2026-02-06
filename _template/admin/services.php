<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/service_barbers.php';

admin_require_login();
admin_require_permission('services');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

// Professionals in this branch (for per-service assignment)
$barbersStmt = $pdo->prepare('SELECT id, name, is_active FROM barbers WHERE business_id=:bid AND branch_id=:brid ORDER BY name');
$barbersStmt->execute([':bid'=>$bid, ':brid'=>$branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];

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
            $img = '';
            if ($name==='') throw new RuntimeException('Nombre requerido');
            if ($duration<=0) throw new RuntimeException('Duración inválida');
            $durationRounded = round_duration_to_slot($duration, $slot);
            $pdo->prepare('INSERT INTO services (business_id,name,description,duration_minutes,price_ars,image_url,is_active,updated_at)
                           VALUES (:bid,:n,:desc,:d,:p,:img,1,CURRENT_TIMESTAMP)')
                ->execute([':bid'=>$bid,':n'=>$name,':desc'=>$desc,':d'=>$durationRounded,':p'=>$price,':img'=>$img]);
            $newId = (int)$pdo->lastInsertId();
            $sel = $_POST['barber_ids'] ?? [];
            if (!is_array($sel)) $sel = [];
            if (count(array_filter(array_map('intval',$sel))) < 1) throw new RuntimeException('El servicio debe tener al menos 1 profesional.');
            if ($newId > 0) service_set_allowed_barbers($bid, $branchId, $newId, $sel);
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
            $img = '';
            if ($id<=0) throw new RuntimeException('ID inválido');
            if ($name==='') throw new RuntimeException('Nombre requerido');
            if ($duration<=0) throw new RuntimeException('Duración inválida');
            $durationRounded = round_duration_to_slot($duration, $slot);
            $pdo->prepare('UPDATE services SET name=:n, description=:desc, duration_minutes=:d, price_ars=:p, image_url=:img, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND id=:id')
                ->execute([':bid'=>$bid,':id'=>$id,':n'=>$name,':desc'=>$desc,':d'=>$durationRounded,':p'=>$price,':img'=>$img]);
            $sel = $_POST['barber_ids'] ?? [];
            if (!is_array($sel)) $sel = [];
            if (count(array_filter(array_map('intval',$sel))) < 1) throw new RuntimeException('El servicio debe tener al menos 1 profesional.');
            service_set_allowed_barbers($bid, $branchId, $id, $sel);
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
    <div style="flex:2">
      <label>Profesionales (para este servicio)</label>
      <div class="chips" data-chips-for="create_barber_ids"></div>
      <select id="create_barber_ids" class="barber-multiselect" name="barber_ids[]" multiple size="3" style="min-width:220px">
        <?php foreach ($barbers as $b): ?>
          <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$b['is_active']===1 ? '' : 'disabled'); ?>><?php echo h($b['name']); ?><?php echo ((int)$b['is_active']===1 ? '' : ' (inactivo)'); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="muted" style="font-size:12px;margin-top:4px">Seleccioná al menos 1 profesional para este servicio.</div>
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Crear</button>
    </div>
  </form>

  <div class="hr"></div>

  <h2>Lista</h2>
  <table class="table table-stack">
    <thead><tr><th>Servicio</th><th>Profesionales</th><th>Duración</th><th>Precio</th><th>Activo</th><th></th></tr></thead>
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
        <td data-label="Profesionales">
          <?php $selIds = service_allowed_barber_ids($bid, $branchId, (int)$r['id']); $selSet = array_flip(array_map('intval',$selIds)); ?>
          <div class="chips" data-chips-for="svc_barber_ids_<?php echo (int)$r['id']; ?>"></div>
          <select id="svc_barber_ids_<?php echo (int)$r['id']; ?>" class="barber-multiselect" name="barber_ids[]" multiple size="3" style="min-width:220px">
            <?php foreach ($barbers as $b): $bid2=(int)$b['id']; ?>
              <option value="<?php echo $bid2; ?>" <?php echo isset($selSet[$bid2]) ? 'selected' : ''; ?> <?php echo ((int)$b['is_active']===1 ? '' : 'disabled'); ?>><?php echo h($b['name']); ?><?php echo ((int)$b['is_active']===1 ? '' : ' (inactivo)'); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td data-label="Duración"><input name="duration" type="number" min="<?php echo (int)$slot; ?>" step="<?php echo (int)$slot; ?>" value="<?php echo (int)$r['duration_minutes']; ?>" style="width:110px" required></td>
        <td data-label="Precio"><input name="price" type="number" min="0" value="<?php echo (int)($r['price_ars'] ?? 0); ?>" style="width:120px" required></td>
	    
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

<style>
  .chips{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 6px 0}
  .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:999px;padding:4px 10px;font-size:12px;background:#fff}
  .chip button{border:0;background:transparent;cursor:pointer;font-weight:700;line-height:1;color:#6b7280}
  .chip button:hover{color:#111}
</style>

<script>
  (function(){
    function refreshChips(selectEl){
      var id = selectEl.id;
      var wrap = document.querySelector('.chips[data-chips-for="'+CSS.escape(id)+'"]');
      if(!wrap) return;
      wrap.innerHTML = '';
      var selected = Array.prototype.filter.call(selectEl.options, function(o){ return o.selected; });
      selected.forEach(function(opt){
        var chip = document.createElement('span');
        chip.className = 'chip';
        chip.textContent = opt.text.replace(' (inactivo)','');

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label','Quitar');
        btn.textContent = '×';
        btn.addEventListener('click', function(){
          // No permitimos dejarlo en 0 desde UI (el backend también valida)
          var count = Array.prototype.filter.call(selectEl.options, function(o){ return o.selected; }).length;
          if(count <= 1){
            alert('El servicio debe tener al menos 1 profesional.');
            return;
          }
          opt.selected = false;
          refreshChips(selectEl);
        });
        chip.appendChild(btn);
        wrap.appendChild(chip);
      });
    }

    function init(){
      var selects = document.querySelectorAll('select.barber-multiselect');
      selects.forEach(function(sel){
        refreshChips(sel);
        sel.addEventListener('change', function(){ refreshChips(sel); });
      });
    }
    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
  })();
</script>

<?php page_foot(); ?>
