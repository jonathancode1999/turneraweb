<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_permission('hours');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$days = [0=>'Domingo',1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado'];

$notice='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_validate_or_die();
    try {
        for ($w=0;$w<=6;$w++) {
            $closed = isset($_POST['closed'][$w]) ? 1 : 0;
            $open = trim($_POST['open'][$w]??'');
            $close = trim($_POST['close'][$w]??'');
            if (!$closed) {
                if (!$open || !$close) throw new RuntimeException('Faltan horarios para ' . $days[$w]);
            } else {
                $open = null; $close = null;
            }
            $pdo->prepare('UPDATE business_hours SET open_time=:o, close_time=:c, is_closed=:cl WHERE business_id=:bid AND weekday=:w')
                ->execute([':o'=>$open,':c'=>$close,':cl'=>$closed,':bid'=>$bid,':w'=>$w]);
        }
        $notice='Horarios guardados.';
    } catch (Throwable $e) {
        $error=$e->getMessage();
    }
}

$stmt=$pdo->prepare('SELECT * FROM business_hours WHERE business_id=:bid AND branch_id=:brid');
$stmt->execute([':bid' => $bid, ':brid' => $branchId]);
$rows=$stmt->fetchAll()?:[];
$byW=[];foreach($rows as $r){$byW[(int)$r['weekday']]=$r;}

page_head('Horarios','admin');
admin_nav('hours');
?>

<div class="card">
  <h1>Horarios de atención</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <table class="table">
      <thead><tr><th>Día</th><th>Cerrado</th><th>Abre</th><th>Cierra</th></tr></thead>
      <tbody>
        <?php for ($w=0;$w<=6;$w++): $r=$byW[$w]??['is_closed'=>1,'open_time'=>'','close_time'=>'']; ?>
          <tr>
            <td><?php echo h($days[$w]); ?></td>
            <td><input type="checkbox" name="closed[<?php echo $w; ?>]" <?php echo ((int)$r['is_closed']===1)?'checked':''; ?>></td>
            <td><input type="time" name="open[<?php echo $w; ?>]" value="<?php echo h($r['open_time']??''); ?>"></td>
            <td><input type="time" name="close[<?php echo $w; ?>]" value="<?php echo h($r['close_time']??''); ?>"></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
    <button class="btn primary" type="submit">Guardar</button>
  </form>

  <p class="muted small">Tip: si cambiás el slot base o duraciones, los horarios disponibles se recalculan automáticamente.</p>
</div>

<?php page_foot(); ?>
