<?php
// Profesionales (renombre de "barbers")
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_permission('profesionales');
admin_require_branch_selected();

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$notice = '';
$error = '';

// Helpers
function prof_upload_dir(): string {
    // Keep assets under /public/uploads/profesionales
    return __DIR__ . '/../public/uploads/profesionales';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'create') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($name === '') throw new RuntimeException('Nombre requerido');

            $biz = $pdo->query('SELECT slot_capacity FROM businesses WHERE id=' . $bid)->fetch();
            $defaultCap = max(1, (int)($biz['slot_capacity'] ?? 1));

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO profesionales (business_id, branch_id, name, phone, email, capacity, is_active)
                               VALUES (:bid, :brid, :n, :ph, :em, :c, 1)')
                    ->execute([
                        ':bid' => $bid,
                        ':brid' => $branchId,
                        ':n' => $name,
                        ':ph' => ($phone !== '' ? $phone : null),
                        ':em' => ($email !== '' ? $email : null),
                        ':c' => $defaultCap,
                    ]);
                $profId = (int)$pdo->lastInsertId();

                // Optional avatar upload (hardened)
                $uploadsDir = prof_upload_dir();
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                $rel = upload_image_from_field('avatar_file', $uploadsDir, 'prof_' . $profId . '_avatar', 4 * 1024 * 1024);
                if ($rel) {
                    $pdo->prepare('UPDATE profesionales SET avatar_path=:av, updated_at=CURRENT_TIMESTAMP
                                   WHERE business_id=:bid AND branch_id=:brid AND id=:id')
                        ->execute([':av' => $rel, ':bid' => $bid, ':brid' => $branchId, ':id' => $profId]);
                }

                // Seed professional hours from business hours (if barber_hours table exists)
                // NOTE: We keep table name barber_hours for historical reasons, but it stores professional_id.
                $bh = $pdo->prepare('SELECT weekday, open_time, close_time, is_closed
                                     FROM business_hours
                                     WHERE business_id=:bid AND branch_id=:brid
                                     ORDER BY weekday');
                $bh->execute([':bid' => $bid, ':brid' => $branchId]);
                $rows = $bh->fetchAll() ?: [];

                foreach ($rows as $r) {
                    // MySQL upsert
                    $pdo->prepare('INSERT INTO barber_hours (business_id, branch_id, professional_id, weekday, open_time, close_time, is_closed)
                                   VALUES (:bid, :brid, :pid, :w, :o, :c, :cl)
                                   ON DUPLICATE KEY UPDATE
                                     open_time=VALUES(open_time),
                                     close_time=VALUES(close_time),
                                     is_closed=VALUES(is_closed),
                                     updated_at=CURRENT_TIMESTAMP')
                        ->execute([
                            ':bid' => $bid,
                            ':brid' => $branchId,
                            ':pid' => $profId,
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
            $pdo->prepare('UPDATE profesionales SET is_active=:v, updated_at=CURRENT_TIMESTAMP
                           WHERE business_id=:bid AND branch_id=:brid AND id=:id')
                ->execute([':v' => $val, ':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
            $notice = 'Estado actualizado.';

        } elseif ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ID inválido');

            $cnt = $pdo->prepare('SELECT COUNT(*) FROM profesionales WHERE business_id=:bid AND branch_id=:brid');
            $cnt->execute([':bid' => $bid, ':brid' => $branchId]);
            $total = (int)$cnt->fetchColumn();
            if ($total <= 1) {
                throw new RuntimeException('Se necesita mínimo 1 profesional. Creá otro antes de eliminar este.');
            }

            $pdo->beginTransaction();
            try {
                // Dependent rows
                $pdo->prepare('DELETE FROM barber_hours WHERE business_id=:bid AND branch_id=:brid AND professional_id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM barber_timeoff WHERE business_id=:bid AND branch_id=:brid AND professional_id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM blocks WHERE business_id=:bid AND branch_id=:brid AND professional_id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM appointments WHERE business_id=:bid AND branch_id=:brid AND professional_id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                $pdo->prepare('DELETE FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND professional_id=:id')
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);

                $pdo->prepare('DELETE FROM profesionales WHERE business_id=:bid AND branch_id=:brid AND id=:id')
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

$stmt = $pdo->prepare('SELECT * FROM profesionales WHERE business_id=:bid AND branch_id=:brid ORDER BY id');
$stmt->execute([':bid' => $bid, ':brid' => $branchId]);
$profes = $stmt->fetchAll() ?: [];

page_head('Profesionales', 'admin');
admin_nav('profesionales');
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
      <input name="name" maxlength="80" placeholder="Ej: Nico" required>
    </div>
    <div style="flex:1;min-width:200px">
      <label>Teléfono (opcional)</label>
      <input name="phone" maxlength="40" placeholder="Ej: 11 1234 5678">
    </div>
    <div style="flex:2;min-width:240px">
      <label>Email (opcional)</label>
      <input name="email" type="email" maxlength="120" placeholder="ejemplo@mail.com">
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

  <?php if (!$profes): ?>
    <div class="notice danger">Se necesita mínimo 1 profesional para poder tomar turnos. Agregá uno arriba.</div>
  <?php else: ?>
    <table class="table table-stack">
      <thead><tr><th>Profesional</th><th>Activo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($profes as $p): ?>
          <tr>
            <td data-label="Profesional">
              <div style="display:flex;gap:10px;align-items:center">
                <?php
                  $av = trim((string)($p['avatar_path'] ?? ''));
                  $initials = strtoupper(substr(preg_replace('/\s+/', '', (string)$p['name']), 0, 2));
                ?>
                <?php if ($av !== ''): ?>
                  <img src="../public/<?php echo h($av); ?>" alt="" style="width:34px;height:34px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php else: ?>
                  <div style="width:34px;height:34px;border-radius:999px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:700;color:#111"><?php echo h($initials); ?></div>
                <?php endif; ?>
                <div>
                  <div><?php echo h($p['name']); ?></div>
                  <div class="small muted">ID <?php echo (int)$p['id']; ?><?php echo !empty($p['phone']) ? ' · ' . h($p['phone']) : ''; ?></div>
                </div>
              </div>
            </td>
            <td data-label="Activo">
              <?php echo ((int)$p['is_active']===1) ? '<span class="badge ok">Sí</span>' : '<span class="badge danger">No</span>'; ?>
            </td>
            <td data-label="Acciones">
              <div class="row-actions">
                <a class="btn" href="profesional_edit.php?id=<?php echo (int)$p['id']; ?>">Editar (Avatar, horarios, vacaciones y servicios)</a>

                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                  <input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                  <?php if ((int)$p['is_active']===1): ?>
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
                  <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
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
