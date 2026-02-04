<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';

admin_require_login();

$pdo = db();
$cfg = app_config();
$bizId = (int)($cfg['business_id'] ?? 1);

$branchId = admin_current_branch_id();

$biz = $pdo->query('SELECT * FROM businesses WHERE id=' . $bizId)->fetch();
if (!$biz) {
  http_response_code(500);
  echo 'Business no encontrado.';
  exit;
}

// Helpers
function countq(PDO $pdo, string $sql, array $params): int {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$st->fetchColumn();
}

$branchesCount = countq($pdo, 'SELECT COUNT(*) FROM branches WHERE business_id=?', [$bizId]);
$servicesCount = countq($pdo, 'SELECT COUNT(*) FROM services WHERE business_id=? AND is_active=1', [$bizId]);
$prosCount = countq($pdo, 'SELECT COUNT(*) FROM barbers WHERE business_id=? AND is_active=1', [$bizId]);

$hasBrand = (trim((string)$biz['name']) !== '');
$hasLogo  = trim((string)$biz['logo_path']) !== '';

$hoursCount = 0;
if ($branchId > 0) {
  $hoursCount = countq($pdo, 'SELECT COUNT(*) FROM business_hours WHERE business_id=? AND branch_id=?', [$bizId, $branchId]);
} else {
  $hoursCount = countq($pdo, 'SELECT COUNT(*) FROM business_hours WHERE business_id=?', [$bizId]);
}

$appointmentsCount = 0;
if ($branchId > 0) {
  $appointmentsCount = countq($pdo, 'SELECT COUNT(*) FROM appointments WHERE business_id=? AND branch_id=?', [$bizId, $branchId]);
} else {
  $appointmentsCount = countq($pdo, 'SELECT COUNT(*) FROM appointments WHERE business_id=?', [$bizId]);
}

$templatesCount = countq($pdo, 'SELECT COUNT(*) FROM message_templates WHERE business_id=?', [$bizId]);

$reminderMode = (int)($biz['reminder_mode'] ?? 0); // 0 off, 1=24h, 2=2h

$items = [];
$items[] = [
  'ok' => $hasBrand,
  'title' => 'Nombre del negocio',
  'desc' => 'El nombre aparece en la web y en los mensajes.',
  'link' => 'settings.php',
  'btn' => 'Ir a Configuración'
];
$items[] = [
  'ok' => $hasLogo,
  'title' => 'Logo y portada',
  'desc' => 'Mejora la confianza y hace que el sistema se vea “pro”.',
  'link' => 'settings.php',
  'btn' => 'Cargar logo'
];
$items[] = [
  'ok' => $branchesCount > 0,
  'title' => 'Sucursales',
  'desc' => 'Creá al menos una sucursal para organizar la agenda.',
  'link' => 'branches.php',
  'btn' => 'Administrar sucursales'
];
$items[] = [
  'ok' => $servicesCount > 0,
  'title' => 'Servicios',
  'desc' => 'Ej: Corte, Color, Manicura, etc.',
  'link' => 'services.php',
  'btn' => 'Cargar servicios'
];
$items[] = [
  'ok' => $prosCount > 0,
  'title' => 'Profesionales',
  'desc' => 'Cargá tus profesionales y asignales sucursal.',
  'link' => 'barbers.php',
  'btn' => 'Cargar profesionales'
];
$items[] = [
  'ok' => $hoursCount > 0,
  'title' => 'Horarios',
  'desc' => 'Definí los horarios de atención para que haya turnos disponibles.',
  'link' => 'hours.php',
  'btn' => 'Configurar horarios'
];
$items[] = [
  'ok' => $templatesCount > 0,
  'title' => '',
  'desc' => 'Personalizá los emails y los textos para WhatsApp.',
  'link' => '',
  'btn' => 'Configurar mensajes'
];
$items[] = [
  'ok' => $appointmentsCount > 0,
  'title' => 'Turno de prueba',
  'desc' => 'Generá un turno y probá aprobar/cancelar/mensajes.',
  'link' => 'appointments.php',
  'btn' => 'Ver turnos'
];
$items[] = [
  'ok' => $reminderMode > 0,
  'title' => 'Recordatorios automáticos (opcional)',
  'desc' => 'Podés activar recordatorios por email (24h o 2h).',
  'link' => 'settings.php#reminders',
  'btn' => 'Configurar recordatorios'
];

$done = 0;
foreach ($items as $it) if ($it['ok']) $done++;
$total = count($items);
$pct = $total > 0 ? (int)round(($done * 100) / $total) : 0;

page_head('Primeros pasos', 'admin');
admin_nav('onboarding');
?>

<style>
.ob-wrap{max-width:1100px;margin:0 auto;}
.ob-head{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:12px 0 18px}
.ob-title{font-size:22px;font-weight:700;margin:0}
.ob-sub{color:#556;max-width:70ch;margin:2px 0 0}
.ob-progress{min-width:240px;background:#eef2ff;border:1px solid #d7ddff;border-radius:999px;overflow:hidden;height:12px}
.ob-progress > div{height:12px;background:var(--primary);width:0}
.ob-badge{font-size:12px;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #d7ddff}
.ob-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
@media (max-width:900px){.ob-grid{grid-template-columns:1fr}}
.ob-item{background:#fff;border:1px solid #e6e6ef;border-radius:14px;padding:14px;display:flex;gap:12px;align-items:flex-start}
.ob-icon{width:26px;height:26px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800}
.ob-ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.ob-no{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.ob-meta{flex:1}
.ob-item h3{margin:0 0 4px;font-size:15px}
.ob-item p{margin:0;color:#556;font-size:13px;line-height:1.35}
.ob-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn.small{padding:7px 10px;font-size:12px;border-radius:10px}
.ob-tip{margin:14px 0 0;color:#445;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px}
</style>

<div class="ob-wrap">
  <div class="ob-head">
    <div>
      <h1 class="ob-title">Primeros pasos</h1>
      <p class="ob-sub">Checklist rápido para dejar la turnera lista en producción. Hecho: <strong><?=h($done)?>/<?=h($total)?></strong>.</p>
      <?php if ($branchId <= 0): ?>
        <p class="ob-sub" style="margin-top:6px;"><strong>Tip:</strong> elegí una sucursal arriba para que el checklist sea más preciso.</p>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <span class="ob-badge"><?=h($pct)?>%</span>
      <div class="ob-progress" aria-label="Progreso"><div style="width:<?=h((string)$pct)?>%"></div></div>
    </div>
  </div>

  <div class="ob-grid">
    <?php foreach ($items as $it): ?>
      <div class="ob-item">
        <div class="ob-icon <?= $it['ok'] ? 'ob-ok' : 'ob-no' ?>"><?= $it['ok'] ? '✓' : '!' ?></div>
        <div class="ob-meta">
          <h3><?=h($it['title'])?></h3>
          <p><?=h($it['desc'])?></p>
        </div>
        <div class="ob-actions">
          <a class="btn small" href="<?=h($it['link'])?>"><?=h($it['btn'])?></a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ob-tip">
    <strong>Producción:</strong> cuando lo subas a Hostinger, acordate de configurar el Cron para los recordatorios (si los activás).
  </div>
</div>

<?php page_foot(); ?>
