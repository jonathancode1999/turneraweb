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
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM business_hours WHERE business_id=:bid AND branch_id=:brid')
            ->execute([':bid' => $bid, ':brid' => $branchId]);
        $stmtUp = $pdo->prepare('INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed)
                                 VALUES (:bid, :brid, :w, :o, :c, :cl)');
        for ($w=0;$w<=6;$w++) {
            $isOpen = isset($_POST['is_open'][$w]) ? 1 : 0;
            $closed = $isOpen ? 0 : 1;
            $open = trim($_POST['open'][$w]??'');
            $close = trim($_POST['close'][$w]??'');
            if ($isOpen) {
                if (!$open || !$close) throw new RuntimeException('Faltan horarios para ' . $days[$w]);
                if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
                    throw new RuntimeException('Formato de horario inválido para ' . $days[$w]);
                }
                $open .= ':00';
                $close .= ':00';
            } else {
                $open = null; $close = null;
            }
            $stmtUp->execute([':o'=>$open,':c'=>$close,':cl'=>$closed,':bid'=>$bid,':brid'=>$branchId,':w'=>$w]);
        }
        $pdo->commit();
        $notice='Horarios guardados.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}

$stmt=$pdo->prepare('SELECT * FROM business_hours WHERE business_id=:bid AND branch_id=:brid');
$stmt->execute([':bid' => $bid, ':brid' => $branchId]);
$rows=$stmt->fetchAll()?:[];
$byW=[];foreach($rows as $r){
    if (!empty($r['open_time'])) $r['open_time'] = substr((string)$r['open_time'], 0, 5);
    if (!empty($r['close_time'])) $r['close_time'] = substr((string)$r['close_time'], 0, 5);
    $byW[(int)$r['weekday']]=$r;
}

page_head('Horarios','admin');
admin_nav('hours');
?>

<div class="card">
  <h1>Horarios de atención</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <table class="table hours-table">
      <thead><tr><th>Día</th><th>Abierto</th><th>Abre</th><th>Cierra</th></tr></thead>
      <tbody>
        <?php for ($w=0;$w<=6;$w++): $r=$byW[$w]??['is_closed'=>1,'open_time'=>'','close_time'=>'']; ?>
          <tr>
            <td><?php echo h($days[$w]); ?></td>
            <td><input type="checkbox" name="is_open[<?php echo $w; ?>]" <?php echo ((int)$r['is_closed']===0)?'checked':''; ?>></td>
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
