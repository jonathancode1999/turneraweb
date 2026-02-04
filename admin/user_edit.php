<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';

admin_require_login();
admin_require_permission('system');

$pdo = db();
$cfg = app_config();
$bizId = (int)$cfg['business_id'];
ensure_multibranch_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) redirect('users.php');

$st = $pdo->prepare("SELECT id, username, role, is_active, all_branches,
  can_branches, can_settings, can_appointments, can_barbers, can_services, can_hours, can_blocks, can_system, can_analytics
  FROM users WHERE business_id=:bid AND id=:id LIMIT 1");
$st->execute([':bid'=>$bizId, ':id'=>$id]);
$user = $st->fetch();
if (!$user) redirect('users.php');

$branches = branches_all_active();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require();
  $all = !empty($_POST['all_branches']) ? 1 : 0;
  $sel = $_POST['branches'] ?? [];
  $role = trim($_POST['role'] ?? $user['role']);
  $isActive = !empty($_POST['is_active']) ? 1 : 0;

  $permKeys = ['can_appointments','can_barbers','can_services','can_hours','can_blocks','can_settings','can_branches','can_analytics','can_system'];
  $permVals = [];
  foreach ($permKeys as $k) $permVals[$k] = !empty($_POST[$k]) ? 1 : 0;

  $pdo->prepare("UPDATE users SET role=:r, is_active=:a, all_branches=:all,
      can_branches=:p1, can_settings=:p2, can_appointments=:p3, can_barbers=:p4, can_services=:p5, can_hours=:p6, can_blocks=:p7, can_system=:p8, can_analytics=:p9
    WHERE business_id=:bid AND id=:id")
    ->execute([
      ':r'=>$role,
      ':a'=>$isActive,
      ':all'=>$all,
      ':p1'=>$permVals['can_branches'],
      ':p2'=>$permVals['can_settings'],
      ':p3'=>$permVals['can_appointments'],
      ':p4'=>$permVals['can_barbers'],
      ':p5'=>$permVals['can_services'],
      ':p6'=>$permVals['can_hours'],
      ':p7'=>$permVals['can_blocks'],
      ':p8'=>$permVals['can_system'],
      ':p9'=>$permVals['can_analytics'],
      ':bid'=>$bizId,
      ':id'=>$id,
    ]);

  $pdo->prepare("DELETE FROM user_branch_access WHERE business_id=:bid AND user_id=:uid")
    ->execute([':bid'=>$bizId, ':uid'=>$id]);
  if (!$all) {
    foreach ($sel as $brid) {
      $brid = (int)$brid;
      if ($brid>0) {
        $pdo->prepare("INSERT OR IGNORE INTO user_branch_access (business_id, user_id, branch_id) VALUES (:bid,:uid,:brid)")
          ->execute([':bid'=>$bizId, ':uid'=>$id, ':brid'=>$brid]);
      }
    }
  }

  flash('Usuario actualizado','ok');
  redirect('user_edit.php?id='.$id);
}

// Selected branches
$selected = [];
if ((int)$user['all_branches'] === 0) {
  $st = $pdo->prepare("SELECT branch_id FROM user_branch_access WHERE business_id=:bid AND user_id=:uid");
  $st->execute([':bid'=>$bizId, ':uid'=>$id]);
  $selected = array_map('intval', array_column($st->fetchAll(), 'branch_id'));
}

page_head('Editar usuario', 'admin');
admin_nav('system');

echo '<div class="card" style="max-width:920px">';
echo '<div class="card-title">'.h($user['username']).'</div>';
echo '<form method="post" class="form" style="margin-top:10px">';
csrf_field();

echo '<label>Rol</label><select name="role"><option value="staff"'.(($user['role']==='staff')?' selected':'').'>Staff</option><option value="admin"'.(($user['role']==='admin')?' selected':'').'>Admin</option></select>';
echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1"'.(((int)$user['is_active'])?' checked':'').'> Activo</label>';

echo '<div class="grid2" style="margin-top:10px">';
echo '<div>';
echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="all_branches" value="1"'.(((int)$user['all_branches'])?' checked':'').'> Acceso a todas las sucursales</label>';
echo '<div class="help">Si lo desmarcás, elegís a cuáles sucursales puede entrar.</div>';
echo '</div>';
echo '<div>';
echo '<div class="label">Sucursales habilitadas</div>';
echo '<div class="checklist">';
foreach ($branches as $b) {
  $brid = (int)$b['id'];
  $chk = in_array($brid, $selected, true) ? ' checked' : '';
  echo '<label><input type="checkbox" name="branches[]" value="'.$brid.'"'.$chk.'> '.h($b['name']).'</label>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="label" style="margin-top:12px">Permisos</div>';
echo '<div class="row" style="margin-top:6px">';
echo '<div style="flex:1;min-width:260px">';
echo '<label>Preset rápido</label>';
echo '<select id="rolePreset" class="input">';
echo '<option value="">(sin cambios)</option>';
echo '<option value="owner">Dueño (todo)</option>';
echo '<option value="reception">Recepción (turnos + lectura)</option>';
echo '<option value="staff">Operador (turnos + profesionales)</option>';
echo '</select>';
echo '<p class="muted small">Te arma los checks automáticamente (podés ajustar manualmente después).</p>';
echo '</div>';
echo '</div>';
echo '<div class="checklist">';
$permDefs = [
  'can_appointments' => 'Turnos',
  'can_barbers' => 'Profesionales',
  'can_services' => 'Servicios',
  'can_hours' => 'Horarios',
  'can_blocks' => 'Bloqueos',
  'can_settings' => 'Configuración',
  'can_branches' => 'Sucursales',
  'can_analytics' => 'Analytics',
  'can_system' => 'Sistema (usuarios/backups)',
];
foreach ($permDefs as $k=>$label) {
  $chk = !empty($user[$k]) ? ' checked' : '';
  echo '<label><input type="checkbox" name="'.$k.'" value="1"'.$chk.'> '.h($label).'</label>';
}
echo '</div>';

echo '<script>
  (function(){
    const sel = document.getElementById("rolePreset");
    if (!sel) return;
    const byName = (n)=>document.querySelector("input[name=\""+n+"\"]");
    const set = (n,v)=>{const el=byName(n); if(el) el.checked=!!v;};
    const setAllBranches = (v)=>{const el=byName("all_branches"); if(el) el.checked=!!v;};
    sel.addEventListener("change", ()=>{
      const p = sel.value;
      if(!p) return;
      if(p==="owner"){
        setAllBranches(true);
        ["can_appointments","can_barbers","can_services","can_hours","can_blocks","can_settings","can_branches","can_analytics","can_system"].forEach(k=>set(k,true));
      } else if(p==="reception"){
        setAllBranches(true);
        set("can_appointments",true);
        set("can_barbers",false);
        set("can_services",false);
        set("can_hours",false);
        set("can_blocks",false);
        set("can_settings",false);
        set("can_branches",false);
        set("can_analytics",false);
        set("can_system",false);
      } else if(p==="staff"){
        setAllBranches(true);
        set("can_appointments",true);
        set("can_barbers",true);
        set("can_services",false);
        set("can_hours",false);
        set("can_blocks",false);
        set("can_settings",false);
        set("can_branches",false);
        set("can_analytics",false);
        set("can_system",false);
      }
    });
  })();
</script>';

echo '<div style="display:flex;gap:8px;align-items:center;margin-top:12px">';
echo '<button class="btn primary" type="submit">Guardar</button>';
echo '<a class="btn" href="users.php">Volver</a>';
echo '</div>';

echo '</form>';
echo '</div>';

page_foot();
