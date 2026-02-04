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

// Ensure schema pieces exist
ensure_multibranch_schema($pdo);

$branches = branches_all_active();

function read_perm_post(string $k, int $def=0): int {
  return !empty($_POST[$k]) ? 1 : $def;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require();
  $action = $_POST['action'] ?? '';

  if ($action==='create') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');
    $allBranches = !empty($_POST['all_branches']) ? 1 : 0;
    $selBranches = $_POST['branches'] ?? [];

    if ($u==='' || $p==='') {
      flash('Usuario/clave requeridos','err');
      redirect('users.php');
    }

    // Defaults by role
    $defaults = [
      'can_branches' => ($role==='admin')?1:0,
      'can_settings' => ($role==='admin')?1:0,
      'can_appointments' => 1,
      'can_barbers' => ($role==='admin')?1:0,
      'can_services' => ($role==='admin')?1:0,
      'can_hours' => ($role==='admin')?1:0,
      'can_blocks' => ($role==='admin')?1:0,
      'can_system' => ($role==='admin')?1:0,
      'can_analytics' => ($role==='admin')?1:0,
    ];

    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (business_id, username, password_hash, role, is_active, all_branches,
        can_branches, can_settings, can_appointments, can_barbers, can_services, can_hours, can_blocks, can_system, can_analytics)
      VALUES (:bid,:u,:h,:r,1,:all,:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,:p9)");
    $stmt->execute([
      ':bid'=>$bizId,
      ':u'=>$u,
      ':h'=>$hash,
      ':r'=>$role,
      ':all'=>$allBranches,
      ':p1'=>read_perm_post('can_branches',$defaults['can_branches']),
      ':p2'=>read_perm_post('can_settings',$defaults['can_settings']),
      ':p3'=>read_perm_post('can_appointments',$defaults['can_appointments']),
      ':p4'=>read_perm_post('can_barbers',$defaults['can_barbers']),
      ':p5'=>read_perm_post('can_services',$defaults['can_services']),
      ':p6'=>read_perm_post('can_hours',$defaults['can_hours']),
      ':p7'=>read_perm_post('can_blocks',$defaults['can_blocks']),
      ':p8'=>read_perm_post('can_system',$defaults['can_system']),
      ':p9'=>read_perm_post('can_analytics',$defaults['can_analytics']),
    ]);

    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("DELETE FROM user_branch_access WHERE business_id=:bid AND user_id=:uid")
      ->execute([':bid'=>$bizId, ':uid'=>$uid]);
    if (!$allBranches) {
      foreach ($selBranches as $brid) {
        $brid = (int)$brid;
        if ($brid>0) {
          $pdo->prepare("INSERT OR IGNORE INTO user_branch_access (business_id, user_id, branch_id) VALUES (:bid,:uid,:brid)")
            ->execute([':bid'=>$bizId, ':uid'=>$uid, ':brid'=>$brid]);
        }
      }
    }

    flash('Usuario creado','ok');
    redirect('users.php');
  }

  if ($action==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id<=0) redirect('users.php');
    $cur = $pdo->prepare("SELECT is_active FROM users WHERE id=:id AND business_id=:bid");
    $cur->execute([':id'=>$id, ':bid'=>$bizId]);
    $v = (int)$cur->fetchColumn();
    $new = $v?0:1;
    $upd = $pdo->prepare("UPDATE users SET is_active=:a WHERE id=:id AND business_id=:bid");
    $upd->execute([':a'=>$new, ':id'=>$id, ':bid'=>$bizId]);
    flash('Actualizado','ok');
    redirect('users.php');
  }
}

$users = $pdo->prepare("SELECT id, username, role, is_active, all_branches,
  can_branches, can_settings, can_appointments, can_barbers, can_services, can_hours, can_blocks, can_system, can_analytics
  FROM users WHERE business_id=:bid ORDER BY id ASC");
$users->execute([':bid'=>$bizId]);
$rows = $users->fetchAll();

page_head('Usuarios', 'admin');
admin_nav('system');

echo '<div class="cards-grid">';

// Create user
echo '<div class="card">';
echo '<div class="card-title">Crear usuario</div>';
echo '<form method="post" class="form" style="margin-top:10px">';
csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<label>Usuario</label><input name="username" required>';
echo '<label>Contraseña</label><input name="password" type="password" required>';
echo '<label>Rol</label><select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select>';

echo '<div class="grid2" style="margin-top:10px">';
echo '<div>';
echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="all_branches" value="1" checked> Acceso a todas las sucursales</label>';
echo '<div class="help">Si lo desmarcás, elegís a cuáles sucursales puede entrar.</div>';
echo '</div>';
echo '<div>';
echo '<div class="label">Sucursales habilitadas</div>';
echo '<div class="checklist">';
foreach ($branches as $b) {
  echo '<label><input type="checkbox" name="branches[]" value="'.(int)$b['id'].'"> '.h($b['name']).'</label>';
}
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="label" style="margin-top:12px">Permisos</div>';
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
  $checked = ($k==='can_appointments') ? ' checked' : '';
  echo '<label><input type="checkbox" name="'.$k.'" value="1"'.$checked.'> '.h($label).'</label>';
}
echo '</div>';

echo '<button class="btn primary" type="submit">Crear</button>';
echo '</form>';
echo '</div>';

// List users
echo '<div class="card" style="grid-column: span 2">';
echo '<div class="card-title">Usuarios</div>';
?>
<table class="table table-stack" style="margin-top:10px">
  <thead>
    <tr>
      <th>Usuario</th><th>Rol</th><th>Sucursales</th><th>Permisos</th><th>Estado</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $u):
    $uid = (int)$u['id'];
    $branchesLabel = 'Todas';
    if ((int)$u['all_branches'] === 0) {
      $st = $pdo->prepare("SELECT b.name FROM user_branch_access uba JOIN branches b ON b.id=uba.branch_id AND b.business_id=uba.business_id WHERE uba.business_id=:bid AND uba.user_id=:uid ORDER BY b.id ASC");
      $st->execute([':bid'=>$bizId, ':uid'=>$uid]);
      $names = array_column($st->fetchAll(), 'name');
      $branchesLabel = $names ? implode(', ', $names) : '—';
    }
    $perms = [];
    foreach (['appointments','barbers','services','hours','blocks','settings','branches','analytics','system'] as $p) {
      $col = 'can_'.$p;
      if (!empty($u[$col])) $perms[] = $p;
    }
    $permLabel = $perms ? implode(', ', $perms) : '—';
  ?>
    <tr>
      <td data-label="Usuario"><?php echo h($u['username']); ?></td>
      <td data-label="Rol"><?php echo h($u['role']); ?></td>
      <td data-label="Sucursales"><?php echo h($branchesLabel); ?></td>
      <td data-label="Permisos" class="muted"><?php echo h($permLabel); ?></td>
      <td data-label="Estado"><?php echo ((int)$u['is_active']?'<span class="badge ok">Activo</span>':'<span class="badge danger">Inactivo</span>'); ?></td>
      <td data-label="Acciones">
        <div class="row-actions">
          <a class="btn" href="user_edit.php?id=<?php echo $uid; ?>">Editar</a>
          <?php if ($uid !== (int)($_SESSION['admin_user']['id'] ?? 0)): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?php echo $uid; ?>">
              <button class="btn <?php echo ((int)$u['is_active']? 'danger':'ok'); ?>" type="submit"><?php echo ((int)$u['is_active']? 'Desactivar':'Activar'); ?></button>
            </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php

echo '</div>';

echo '</div>';

page_foot();
