<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_permission('barbers');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Nombre requerido');

            $biz = db()->query('SELECT slot_capacity FROM businesses WHERE id=' . $bid)->fetch();
            $defaultCap = max(1, (int)($biz['slot_capacity'] ?? 1));

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO barbers (business_id, branch_id, name, capacity, is_active) VALUES (:bid, :brid, :n, :c, 1)')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':n' => $name, ':c' => $defaultCap]);
                $barberId = (int)$pdo->lastInsertId();

                // Optional avatar upload (from create form)
                if (isset($_FILES["avatar_file"]) && is_array($_FILES["avatar_file"]) && (($_FILES["avatar_file"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                    $f = $_FILES["avatar_file"];
                    if (($f["error"] ?? 0) !== UPLOAD_ERR_OK) throw new RuntimeException("Error subiendo avatar (código " . (int)($f["error"] ?? 0) . ").");
                    if (($f["size"] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException("El avatar supera 4MB.");
                    $tmp = (string)($f["tmp_name"] ?? "");
                    $nameF = (string)($f["name"] ?? "");
                    $ext = strtolower(pathinfo($nameF, PATHINFO_EXTENSION));
                    if (!in_array($ext, ["jpg","jpeg","png","webp"], true)) throw new RuntimeException("Formato de avatar no permitido (JPG/PNG/WEBP).");
                    $uploadsDir = __DIR__ . "/../public/uploads/barbers";
                    if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
                    @file_put_contents($uploadsDir . "/index.php", "<?php http_response_code(404);");
                    $fname = "barber_" . $barberId . "_avatar." . ($ext === "jpeg" ? "jpg" : $ext);
                    $dest = $uploadsDir . "/" . $fname;
                    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException("No se pudo guardar el avatar.");
                    $pdo->prepare("UPDATE barbers SET avatar_path=:av, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
                        ->execute([":av" => "uploads/barbers/" . $fname, ":bid" => $bid, ":brid" => $branchId, ":id" => $barberId]);
                }

                // Seed barber hours from business hours
                $bh = $pdo->prepare('SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=:bid AND branch_id=:brid ORDER BY weekday');
                $bh->execute([':bid' => $bid, ':brid' => $branchId]);
                $rows = $bh->fetchAll() ?: [];
                foreach ($rows as $r) {
                    $pdo->prepare('INSERT INTO barber_hours (business_id, branch_id, barber_id, weekday, open_time, close_time, is_closed)
                                   VALUES (:bid, :brid, :bar, :w, :o, :c, :cl)
                                   ON CONFLICT(business_id, branch_id, barber_id, weekday) DO UPDATE SET
                                     open_time=excluded.open_time,
                                     close_time=excluded.close_time,
                                     is_closed=excluded.is_closed')
                        ->execute([
                            ':bid' => $bid,
                            ':brid' => $branchId,
                            ':bar' => $barberId,
                            ':w' => (int)$r['weekday'],
                            ':o' => (string)$r['open_time'],
                            ':c' => (string)$r['close_time'],
                            ':cl' => (int)$r['is_closed'],
                        ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $notice = 'Profesional creado.';
        } elseif ($act === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $val = (int)($_POST['val'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID inválido');
            $pdo->prepare('UPDATE barbers SET is_active=:v, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id')
                ->execute([':v' => $val, ':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
            $notice = 'Estado actualizado.';
        } elseif ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID inválido');
            // Hard delete (requested). To keep the system consistent, we also delete
            // related data (hours, vacations, blocks, appointments).
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM barbers WHERE business_id=:bid AND branch_id=:brid');
            $cnt->execute([':bid' => $bid, ':brid' => $branchId]);
            $total = (int)$cnt->fetchColumn();
            if ($total <= 1) {
                throw new RuntimeException('Se necesita mínimo 1 profesional. Creá otro antes de eliminar este.');
            }

            $pdo->beginTransaction();
            try {
                // Delete dependent rows
                $pdo->prepare('DELETE FROM barber_hours WHERE business_id=:bid AND branch_id=:brid AND barber_id=:id')->execute([':bid' => $bid, ':brid'=>$branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM barber_timeoff WHERE business_id=:bid AND branch_id=:brid AND barber_id=:id')->execute([':bid' => $bid, ':brid'=>$branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM blocks WHERE business_id=:bid AND branch_id=:brid AND barber_id=:id')->execute([':bid' => $bid, ':brid'=>$branchId, ':id' => $id]);
                // Delete appointments and payments (payments cascades)
                $pdo->prepare('DELETE FROM appointments WHERE business_id=:bid AND branch_id=:brid AND barber_id=:id')->execute([':bid' => $bid, ':brid'=>$branchId, ':id' => $id]);
                // Finally, delete the professional
                $pdo->prepare('DELETE FROM barbers WHERE business_id=:bid AND branch_id=:brid AND id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $notice = 'Profesional eliminado definitivamente.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare('SELECT * FROM barbers WHERE business_id=:bid AND branch_id=:brid ORDER BY id');
$stmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $stmt->fetchAll() ?: [];

page_head('Profesionales', 'admin');
admin_nav('barbers');
?>

<div class="card">
  <h1>Profesionales</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <h2>Agregar profesional</h2>
  <form method="post" class="row" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="create">
    <div style="flex:2;min-width:240px">
      <label>Nombre</label>
      <input name="name" maxlength="60" placeholder="Ej: Nico" required>
    </div>
    <div style="flex:2;min-width:240px">
      <label>Avatar (opcional)</label>
      <input type="file" name="avatar_file" accept="image/*">
      <p class="muted small">Se muestra en la web y en el listado de profesionales.</p>
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Agregar</button>
    </div>
  </form>

  <div class="hr"></div>
  <h2>Listado</h2>
  <?php if (!$barbers): ?>
    <div class="notice danger">Se necesita mínimo 1 profesional para poder tomar turnos. Agregá uno arriba.</div>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Nombre</th><th>Activo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($barbers as $b): ?>
          <tr>
            <td><div style="display:flex;gap:10px;align-items:center">
              <?php
                $av = trim((string)($b['avatar_path'] ?? ''));
                $initials = strtoupper(substr(preg_replace('/\s+/', '', (string)$b['name']), 0, 2));
              ?>
              <?php if ($av !== ''): ?>
                <img src="../public/<?php echo h($av); ?>" alt="" style="width:34px;height:34px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
              <?php else: ?>
                <div style="width:34px;height:34px;border-radius:999px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:700;color:#111"><?php echo h($initials); ?></div>
              <?php endif; ?>
              <div>
                <div><?php echo h($b['name']); ?></div>
                <div class="small muted">ID <?php echo (int)$b['id']; ?></div>
              </div>
              </div>
            </td>
            <td><?php echo ((int)$b['is_active']===1) ? '<span class="badge ok">Sí</span>' : '<span class="badge danger">No</span>'; ?></td>
            <td><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <a class="btn" href="barber_edit.php?id=<?php echo (int)$b['id']; ?>">Editar (Avatar y horarios)</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                <?php if ((int)$b['is_active']===1): ?>
                  <input type="hidden" name="val" value="0">
                  <button class="btn danger" type="submit" onclick="return confirm('¿Desactivar profesional?');">Desactivar</button>
                <?php else: ?>
                  <input type="hidden" name="val" value="1">
                  <button class="btn ok" type="submit">Activar</button>
                <?php endif; ?>
              </form>

              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                <button class="btn danger" type="submit" onclick="return confirm('¿Eliminar profesional definitivamente? Se borrarán sus horarios, vacaciones, bloqueos y turnos.');">Eliminar</button>
              </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">Tip: si desactivás un profesional, deja de aparecer en la web para reservar.</p>
  <?php endif; ?>
</div>

<?php page_foot(); ?>
