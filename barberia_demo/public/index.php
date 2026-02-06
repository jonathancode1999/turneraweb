<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/service_barbers.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/anti_spam.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$captcha = spam_captcha_generate();

$bookingError = flash_get('booking_error');
$oldJson = flash_get('booking_old');
$bookingOld = [];
if ($oldJson !== '') {
  $tmp = json_decode($oldJson, true);
  if (is_array($tmp)) $bookingOld = $tmp;
}

$branchId = public_current_branch_id();
$branch = branch_get($branchId);

$business = $pdo->query('SELECT * FROM businesses WHERE id=' . $bid)->fetch();
$branches = branches_all_active();

// Map embed helper
$mapEmbed = '';
if ($branch && !empty($branch['maps_url'])) {
  $m = (string)$branch['maps_url'];
  if (strpos($m, 'google.com/maps/embed') !== false) {
    $mapEmbed = $m;
  }
}
if ($mapEmbed === '' && $branch && !empty($branch['address'])) {
  $mapEmbed = 'https://www.google.com/maps?q=' . urlencode((string)$branch['address']) . '&output=embed';
}

// Fallback for safety (should not happen, but avoid breaking the home)
if (!$branch) {
  $branch = [
    'id' => 1,
    'name' => (string)($business['name'] ?? 'Turnera'),
    'address' => '',
    'maps_url' => '',
    'whatsapp_phone' => '',
  ];
}
$barbersStmt = $pdo->prepare('SELECT * FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbersStmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];
$services = $pdo->prepare('SELECT * FROM services WHERE business_id=:bid AND is_active=1 ORDER BY id');
$services->execute([':bid' => $bid]);
$services = $services->fetchAll();

$coverPath = trim((string)($business['cover_path'] ?? ''));
$instagramUrl = trim((string)($business['instagram_url'] ?? ''));
$introText = trim((string)($business['intro_text'] ?? ''));

// Estado abierto/cerrado (simple)
$openNow = false;
$openText = '';
try {
  $now = now_tz();
  $weekday = (int)$now->format('w'); // 0=Sunday
  $hstmt = $pdo->prepare('SELECT open_time, close_time, is_closed FROM business_hours WHERE business_id=:bid AND weekday=:w');
  $hstmt->execute([':bid' => $bid, ':w' => $weekday]);
  $h = $hstmt->fetch() ?: null;

  if ($h && (int)($h['is_closed'] ?? 0) === 0 && !empty($h['open_time']) && !empty($h['close_time'])) {
    [$oh, $om] = array_map('intval', explode(':', (string)$h['open_time']));
    [$ch, $cm] = array_map('intval', explode(':', (string)$h['close_time']));
    $d = $now->format('Y-m-d');
    $tz = new DateTimeZone($cfg['timezone']);
    $openAt = (new DateTimeImmutable($d, $tz))->setTime($oh, $om);
    $closeAt = (new DateTimeImmutable($d, $tz))->setTime($ch, $cm);
    if ($closeAt <= $openAt) {
      // horario inválido, lo tratamos como cerrado
      $openNow = false;
    } else {
      $openNow = ($now >= $openAt && $now <= $closeAt);
      $openText = $openNow ? ('Abierto ahora · cierra ' . $closeAt->format('H:i')) : ('Cerrado ahora · abre ' . $openAt->format('H:i'));
    }
  } else {
    $openNow = false;
    $openText = 'Cerrado hoy';
  }

  // Bloqueo global vigente
  if ($openNow) {
	  $nst = $pdo->prepare('SELECT COUNT(*) FROM blocks WHERE business_id=:bid AND branch_id=:brid AND barber_id IS NULL AND start_at <= :n AND end_at >= :n');
	  $nst->execute([':bid' => $bid, ':brid' => $branchId, ':n' => $now->format('Y-m-d H:i:s')]);
    if ((int)($nst->fetchColumn() ?: 0) > 0) {
      $openNow = false;
      $openText = 'Cerrado temporalmente';
    }
  }
} catch (Throwable $e) {
  // si falla, no mostramos nada
  $openNow = false;
  $openText = '';
}

$logoPath = trim((string)($business['logo_path'] ?? ''));
$logoHtml = '';
if ($logoPath !== '') {
  $logoHtml = '<div class="public-logo"><img src="' . h($logoPath) . '" alt="Logo"></div>';
}
$subParts = [];
if (!empty($branch['address'])) $subParts[] = h((string)$branch['address']);
if (!empty($branch['maps_url'])) $subParts[] = '<a class="link" href="' . h((string)$branch['maps_url']) . '" target="_blank" rel="noopener">Cómo llegar</a>';
if (!empty($branch['whatsapp_phone'])) {
  $waDigits = preg_replace('/\D+/', '', (string)$branch['whatsapp_phone']);
  if ($waDigits) $subParts[] = '<a class="link" href="https://wa.me/' . h($waDigits) . '" target="_blank" rel="noopener">WhatsApp</a>';
}
$branchSwitch = '';
if (is_array($branches) && count($branches) > 1) {
  $opts = '';
  foreach ($branches as $br) {
    $sel = ((int)$br['id'] === (int)$branchId) ? ' selected' : '';
    $label = trim((string)$br['name'] . ' — ' . (string)$br['address']);
    $opts .= '<option value="' . (int)$br['id'] . '"' . $sel . '>' . h($label) . '</option>';
  }
  $branchSwitch = '<div class="branch-switch"><div class="branch-switch-label">Conocé nuestras demás sucursales</div>'
                . '<select class="branch-select" onchange="location.href=\'?branch=\'+this.value">' . $opts . '</select></div>';
}

$headerHtml = '<div class="public-brand">'
            . '<div class="public-brand-left">'
            . $logoHtml
            . '<div><div class="public-title">' . h($business['name'] ?? 'Turnera') . '</div><div class="public-sub">' . implode(' · ', $subParts) . '</div></div>'
            . '</div>'
            . $branchSwitch
            . '</div>';

page_head('Reservar turno', 'public-light', $headerHtml);
?>
<div class="grid">

  <div class="hero">
    <div class="hero-left">
      <div class="hero-cover">
        <?php if ($coverPath !== ''): ?>
          <img src="<?php echo h($coverPath); ?>" alt="Portada">
        <?php else: ?>
          <div class="hero-cover-placeholder">
            <div class="muted">Subí una portada desde Admin → Configuración</div>
          </div>
        <?php endif; ?>
      </div>
      <div class="hero-info">
        <?php if ($logoPath !== ''): ?>
          <div class="hero-logo"><img src="<?php echo h($logoPath); ?>" alt="Logo"></div>
        <?php endif; ?>
        <div class="hero-text">
          <div class="hero-name"><?php echo h($business['name'] ?? 'Turnera'); ?></div>
            <?php if ($openText !== ''): ?>
              <div style="margin-top:6px">
                <span class="badge <?php echo $openNow ? 'ok' : 'danger'; ?>"><?php echo h($openText); ?></span>
              </div>
            <?php endif; ?>
          <?php if ($introText !== ''): ?>
            <div class="hero-intro muted"><?php echo h($introText); ?></div>
          <?php endif; ?>
          <div class="hero-links">
            <?php if ($instagramUrl !== ''): ?>
              <a class="link icon-link" href="<?php echo h($instagramUrl); ?>" target="_blank" rel="noopener">
                <span class="icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                </span>
                Instagram
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="hero-right">
      <div class="card hero-card">
        <?php if ($mapEmbed !== ''): ?>
          <div class="map-mini" id="mapFrame" data-src="<?php echo h($mapEmbed); ?>">
            <button class="btn" type="button" id="loadMapBtn">Cargar mapa</button>
          </div>
        <?php endif; ?>

        <div class="hero-contact">
          <?php if (!empty($branch['address'])): ?>
            <div class="hero-contact-row"><span class="muted">📍</span> <?php echo h((string)$branch['address']); ?></div>
          <?php endif; ?>
          <?php if (!empty($branch['whatsapp_phone'])):
            $waDigits = preg_replace('/\D+/', '', (string)$branch['whatsapp_phone']);
          ?>
            <div class="hero-contact-row"><span class="muted">📞</span> <?php echo h($waDigits); ?></div>
            <div class="hero-contact-row">
              <a class="link icon-link" href="https://wa.me/<?php echo h($waDigits); ?>" target="_blank" rel="noopener">
                <span class="icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.02 0C5.39 0 .02 5.37.02 12c0 2.11.55 4.17 1.6 5.98L0 24l6.18-1.6A11.9 11.9 0 0 0 12.02 24C18.65 24 24 18.63 24 12c0-3.2-1.25-6.21-3.5-8.52z" stroke="currentColor" fill="none"/><path d="M17.2 14.4c-.3-.16-1.78-.88-2.06-.98-.28-.1-.48-.16-.68.16-.2.32-.78.98-.96 1.18-.18.2-.36.24-.66.08-.3-.16-1.28-.47-2.44-1.5-.9-.8-1.51-1.8-1.69-2.1-.18-.3-.02-.46.14-.62.14-.14.3-.36.46-.54.16-.18.2-.3.3-.5.1-.2.05-.38-.02-.54-.08-.16-.68-1.64-.94-2.25-.25-.6-.5-.52-.68-.53l-.58-.01c-.2 0-.54.08-.82.38-.28.3-1.08 1.06-1.08 2.58 0 1.52 1.1 2.99 1.25 3.2.15.2 2.17 3.32 5.26 4.66.74.32 1.31.51 1.76.65.74.24 1.41.21 1.94.13.59-.09 1.78-.73 2.03-1.43.25-.7.25-1.3.17-1.43-.08-.13-.28-.2-.58-.36z" stroke="currentColor" fill="none"/></svg>
                </span>
                Contactanos por WhatsApp
              </a>
            </div>
          <?php endif; ?>
          <?php if (!empty($branch['maps_url'])): ?>
            <div class="hero-contact-row"><span class="muted">🧭</span> <a class="link" href="<?php echo h((string)$branch['maps_url']); ?>" target="_blank" rel="noopener">Cómo llegar</a></div>
          <?php endif; ?>
        </div>

        <?php if (count($barbers) > 0): ?>
          <div class="hero-pros">
            <div class="section-title" style="margin:10px 0 6px 0">Profesionales</div>
            <?php $prosNav = count($barbers) > 3; ?>
            <div class="pros-wrap">
              <?php if ($prosNav): ?>
                <button class="icon-btn" type="button" id="prosPrev" aria-label="Anterior">‹</button>
              <?php endif; ?>
              <div class="pros-row" id="prosRow" <?php echo $prosNav ? '' : 'style="overflow:visible;width:auto;max-width:none;flex:0 0 auto"'; ?>>
              <?php foreach ($barbers as $b):
                $nm = trim((string)$b['name']);
                $initials = $nm !== '' ? strtoupper(mb_substr(preg_replace('/\s+/', '', $nm), 0, 2, 'UTF-8')) : 'PR';
                $av = trim((string)($b['avatar_path'] ?? ''));
              ?>
                <div class="pro-chip" title="<?php echo h($nm); ?>">
                  <?php if ($av !== ''): ?>
                  <div class="pro-avatar"><img class="pro-avatar-img" src="<?php echo h($av); ?>" alt=""></div>
                  <?php else: ?>
                  <div class="pro-avatar"><?php echo h($initials); ?></div>
                  <?php endif; ?>
                  <div class="pro-name"><?php echo h($nm); ?></div>
                </div>
              <?php endforeach; ?>
              </div>
              <?php if ($prosNav): ?>
                <button class="icon-btn" type="button" id="prosNext" aria-label="Siguiente">›</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h1>Reservá tu turno</h1>

    <div class="stepper" id="stepper">
      <div class="step is-active" data-step="1"><div class="dot">1</div><div class="lbl">Servicio</div></div>
      <div class="step" data-step="2" id="stepPro"><div class="dot">2</div><div class="lbl">Profesional</div></div>
      <div class="step" data-step="3"><div class="dot">3</div><div class="lbl">Día</div></div>
      <div class="step" data-step="4"><div class="dot">4</div><div class="lbl">Hora</div></div>
      <div class="step" data-step="5"><div class="dot">5</div><div class="lbl">Datos</div></div>
      <div class="step" data-step="6"><div class="dot">6</div><div class="lbl">Confirmar</div></div>
    </div>

    <form method="post" action="create_booking.php" id="bookingForm">
      <input type="hidden" name="branch_id" id="branch_id" value="<?php echo (int)$branchId; ?>">
      <input type="hidden" name="barber_id" id="barber_id" required value="<?php echo h((string)($bookingOld['barber_id'] ?? '0')); ?>">
      <input type="hidden" name="service_id" id="service_id" required value="<?php echo h((string)($bookingOld['service_id'] ?? '')); ?>">
      <input type="hidden" name="date" id="date" required value="<?php echo h((string)($bookingOld['date'] ?? '')); ?>">
      <input type="hidden" name="time" id="time" required value="<?php echo h((string)($bookingOld['time'] ?? '')); ?>">

      <div class="step-pane" data-step="1">
        <label>Elegí tu servicio</label>
        <div class="service-grid" id="serviceGrid">
        <?php foreach ($services as $s):
          $desc = trim((string)($s['description'] ?? ''));
          $price = (int)($s['price_ars'] ?? 0);
        ?>
          <div class="service-card" role="button" tabindex="0"
               data-id="<?php echo (int)$s['id']; ?>"
               data-duration="<?php echo (int)$s['duration_minutes']; ?>"
               data-name="<?php echo h($s['name']); ?>">
            <div class="service-meta">
              <div class="service-title"><?php echo h($s['name']); ?></div>
              <?php if ($desc !== ''): ?><p class="service-desc"><?php echo h($desc); ?></p><?php endif; ?>
              <div class="service-badges">
                <span class="badge"><?php echo (int)$s['duration_minutes']; ?> min</span>
                <?php if ($price > 0): ?><span class="badge">Precio <?php echo h(fmt_money_ars($price)); ?></span><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <div class="step-actions">
          <button type="button" class="btn primary" id="nextFromService" disabled>Siguiente</button>
        </div>
      </div>

      <?php $canChoose = ((int)($business['customer_choose_barber'] ?? 1)===1) && (count($barbers) > 0); ?>
      <div class="step-pane" data-step="2" id="proStepPane" style="<?php echo $canChoose ? '' : 'display:none'; ?>">
        <label>Elegí profesional</label>
        <div class="pro-pills" id="proPills">
          <div class="pro-pill selected" role="button" tabindex="0" data-id="0">Primer profesional disponible</div>
          <?php foreach ($barbers as $b): ?>
            <div class="pro-pill" role="button" tabindex="0" data-id="<?php echo (int)$b['id']; ?>"><?php echo h($b['name']); ?></div>
          <?php endforeach; ?>
        </div>
        <div class="step-actions">
          <button type="button" class="btn" data-back="1">Atrás</button>
          <button type="button" class="btn primary" id="nextFromPro">Siguiente</button>
        </div>
      </div>

      <div class="step-pane" data-step="3">
      <label>Elegí una fecha</label>
      <div class="calendar" id="calendar"></div>
      <div class="step-actions">
        <button type="button" class="btn" data-back="<?php echo $canChoose ? 2 : 1; ?>">Atrás</button>
        <button type="button" class="btn primary" id="nextFromDay" disabled>Siguiente</button>
      </div>
      </div>

      <div class="step-pane" data-step="4">
      <div class="row" style="align-items:flex-start">
        <div style="flex:1;min-width:280px">
          <label>Elegí un horario</label>
          <div class="times" id="times"></div>
          <div class="help" id="timesHelp">Elegí servicio y fecha para ver horarios.</div>
        </div>
      </div>
      <div class="step-actions">
        <button type="button" class="btn" data-back="3">Atrás</button>
        <button type="button" class="btn primary" id="nextFromTime" disabled>Siguiente</button>
      </div>
      </div>

      <div class="step-pane" data-step="5">
      <div class="row">
        <div>
          <label>Nombre y apellido</label>
          <input type="text" name="customer_name" required maxlength="60" placeholder="Ej: Juan Pérez" value="<?php echo h((string)($bookingOld['customer_name'] ?? '')); ?>">
        </div>
        <div>
          <label>Celular (WhatsApp)</label>
          <input type="tel" name="customer_phone" required maxlength="30" placeholder="Ej: 11 5555 5555" value="<?php echo h((string)($bookingOld['customer_phone'] ?? '')); ?>">
        </div>
      </div>

      <div class="row">
        <div style="flex:1;min-width:280px">
          <label>Email (opcional)</label>
          <input type="email" name="customer_email" maxlength="120" placeholder="Ej: tuemail@dominio.com" value="<?php echo h((string)($bookingOld['customer_email'] ?? '')); ?>">
          <p class="muted small" style="margin:6px 0 0 0">Recomendado: te enviamos un <b>link único</b> para ver el estado del turno (pendiente / aprobado / cancelado) y poder gestionarlo.</p>
        </div>
      </div>

      <label>Comentario (opcional)</label>
      <textarea name="notes" rows="3" maxlength="300" placeholder="Ej: quiero fade + tijera"><?php echo h((string)($bookingOld['notes'] ?? '')); ?></textarea>

      <div class="step-actions">
        <button type="button" class="btn" data-back="4">Atrás</button>
        <button type="button" class="btn primary" id="nextFromData">Siguiente</button>
      </div>
      </div>

      <div class="step-pane" data-step="6">

      <div class="notice" style="margin:0 0 12px 0">
        <b>Revisá tu turno</b>
        <div class="muted" id="confirmSummary" style="margin-top:6px"></div>
      </div>

      <div class="notice" style="margin:0 0 12px 0">
        <b>Reglas</b>
        <ul class="muted" style="margin:8px 0 0 18px">
          <li>Tu solicitud queda <b>pendiente de aprobación</b> hasta que el negocio la confirme.</li>
          <?php if ((int)($business['cancel_notice_minutes'] ?? 0) > 0): ?>
            <li>Cancelación con al menos <b><?php echo (int)($business['cancel_notice_minutes'] ?? 0); ?> min</b> de anticipación.</li>
          <?php else: ?>
            <li>Podés cancelar desde tu link de turno.</li>
          <?php endif; ?>
          <li>Reprogramación: podés solicitar un nuevo horario desde tu link de turno.</li>
        </ul>
      </div>

      
      <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="position:absolute;left:-9999px;top:-9999px;height:1px;width:1px;opacity:0" aria-hidden="true">

      <div class="row">
        <div style="flex:1;min-width:260px">
          <label>Verificación</label>
          <div class="muted small" style="margin:-6px 0 6px 0"><?php echo h($captcha['question']); ?></div>
          <input type="text" inputmode="numeric" name="captcha_answer" required maxlength="4" placeholder="Respuesta">
          <?php if ($bookingError !== ''): ?><div class="notice danger" style="margin-top:8px"><?php echo h($bookingError); ?></div><?php endif; ?>
        </div>
      </div>

      <div class="step-actions">
        <button type="button" class="btn" data-back="5">Atrás</button>
        <button type="submit" class="btn primary" id="submitBtn" disabled>Solicitar turno</button>
      </div>
      <p class="muted small">Te vamos a avisar cuando sea aceptada o cancelada.</p>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Política</h2>
    <ul class="muted">
      <li>Los turnos se confirman una vez que el negocio los aprueba.</li>
      <li>Si necesitás reprogramar, podés solicitar un nuevo horario desde tu link de turno.</li>
      <li>Este entorno puede ser de prueba (los datos pueden reiniciarse).</li>
    </ul>
    <div class="spacer"></div>
    <a class="link" href="manage_lookup.php">¿Ya reservaste? Ver/Cancelar con tu link</a>
  </div>

</div>

<?php
  $serviceBarbersMap = [];
  foreach ($services as $s) {
    $sid = (int)($s['id'] ?? 0);
    if ($sid > 0) $serviceBarbersMap[$sid] = service_allowed_barber_ids($bid, $branchId, $sid);
  }
?>
<script>
const SERVICE_BARBERS = <?php echo json_encode($serviceBarbersMap, JSON_UNESCAPED_UNICODE); ?>;
(function(){
  const serviceInput = document.getElementById('service_id');
  const barberInput = document.getElementById('barber_id');
  const dateInput = document.getElementById('date');
  const timeInput = document.getElementById('time');
  const submit = document.getElementById('submitBtn');
  const grid = document.getElementById('serviceGrid');
  const proPills = document.getElementById('proPills');
  const timesWrap = document.getElementById('times');
  const timesHelp = document.getElementById('timesHelp');

  // Stepper (single-page wizard)
  const stepper = document.getElementById('stepper');
  const stepItems = stepper ? [...stepper.querySelectorAll('.step')] : [];
  const panes = [...document.querySelectorAll('.step-pane')];
  const proPane = document.getElementById('proStepPane');
  const proStep = document.getElementById('stepPro');
  const hasPro = !!(proPane && proPane.style.display !== 'none');
  if (!hasPro && proStep) proStep.style.display = 'none';

  const nextFromService = document.getElementById('nextFromService');
  const nextFromPro = document.getElementById('nextFromPro');
  const nextFromDay = document.getElementById('nextFromDay');
  const nextFromTime = document.getElementById('nextFromTime');
  const nextFromData = document.getElementById('nextFromData');
  const confirmSummary = document.getElementById('confirmSummary');

  let currentStep = 1;
  function setStep(n){
    currentStep = n;
    panes.forEach(p=>p.classList.toggle('is-active', Number(p.getAttribute('data-step')) === n));
    stepItems.forEach(si=>{
      const sn = Number(si.getAttribute('data-step'));
      si.classList.toggle('is-active', sn === n);
      si.classList.toggle('is-done', sn < n);
    });

    if (n === 6 && confirmSummary) {
      const svc = selectedService ? (selectedService.dataset.name || '') : '';
      const pro = (proPills && barberInput.value !== '' && barberInput.value !== '0')
        ? (proPills.querySelector(`.pro-pill[data-id="${barberInput.value}"]`)?.textContent || '')
        : 'Primer profesional disponible';
      const d = dateInput.value ? dateInput.value.split('-').reverse().join('/') : '';
      const t = timeInput.value || '';
      confirmSummary.innerHTML = `
        <div><b>${svc || 'Servicio'}</b> · <span class="muted">${pro}</span></div>
        <div class="muted" style="margin-top:4px">${d}${t ? ' · ' + t : ''}</div>
        <div class="muted" style="margin-top:4px">Sucursal: <?php echo h((string)$branch['name']); ?><?php echo !empty($branch['address']) ? ' · ' . h((string)$branch['address']) : ''; ?></div>
        <?php if (!empty($branch['whatsapp_phone'])): $waDigits2 = preg_replace('/\D+/', '', (string)$branch['whatsapp_phone']); ?>
          <div style="margin-top:6px"><a class="link" target="_blank" rel="noopener" href="https://wa.me/<?php echo h($waDigits2); ?>">WhatsApp del local</a></div>
        <?php endif; ?>
      `;
    }
  }

  // Back buttons
  document.addEventListener('click', (e)=>{
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    const back = t.closest('[data-back]');
    if (!back) return;
    const to = Number(back.getAttribute('data-back') || '1');
    setStep(to);
  });

  if (nextFromService) nextFromService.addEventListener('click', ()=> setStep(hasPro ? 2 : 3));
  if (nextFromPro) nextFromPro.addEventListener('click', ()=> setStep(3));
  if (nextFromDay) nextFromDay.addEventListener('click', ()=> setStep(4));
  if (nextFromTime) nextFromTime.addEventListener('click', ()=> setStep(5));
  if (nextFromData) nextFromData.addEventListener('click', ()=> setStep(6));

  // Map: lazy load so the home page feels snappier.
  const mapFrame = document.getElementById('mapFrame');
  const loadMapBtn = document.getElementById('loadMapBtn');
  function loadMap(){
    if (!mapFrame || mapFrame.dataset.loaded === '1') return;
    const src = mapFrame.getAttribute('data-src');
    if (!src) return;
    const iframe = document.createElement('iframe');
    iframe.className = 'mapframe';
    iframe.loading = 'lazy';
    iframe.referrerPolicy = 'no-referrer-when-downgrade';
    iframe.src = src;
    iframe.style.border = '0';
    iframe.style.width = '100%';
    iframe.style.height = '280px';
    iframe.style.borderRadius = '14px';
    mapFrame.replaceWith(iframe);
    mapFrame.dataset.loaded = '1';
    try { sessionStorage.setItem('map_loaded', '1'); } catch(e) {}
  }
  if (loadMapBtn) loadMapBtn.addEventListener('click', loadMap);
  // If the user already loaded it in this session, load immediately.
  try { if (sessionStorage.getItem('map_loaded') === '1') loadMap(); } catch(e) {}
  // Otherwise, auto-load when it scrolls into view.
  if (mapFrame && 'IntersectionObserver' in window) {
    const obs = new IntersectionObserver((entries)=>{
      entries.forEach(en=>{ if (en.isIntersecting) { loadMap(); obs.disconnect(); } });
    }, {rootMargin: '120px'});
    obs.observe(mapFrame);
  }
  // And do a gentle background load shortly after the page renders.
  if (mapFrame) {
    if ('requestIdleCallback' in window) {
      requestIdleCallback(()=>loadMap(), {timeout:1500});
    } else {
      setTimeout(()=>loadMap(), 900);
    }
  }

  let selectedService = null;
  let selectedDate = null;
  let selectedTime = null;
  function setPro(id, keepTime=false){
    barberInput.value = String(id);
    if (!keepTime) {
      selectedTime = null;
      timeInput.value = '';
    } else {
      // Keep existing time (used when returning to the form after a captcha error)
      selectedTime = timeInput.value || null;
    }
    if (proPills){
      [...proPills.querySelectorAll('.pro-pill')].forEach(el=>{
        el.classList.toggle('selected', el.dataset.id === String(id));
      });
    }
    loadTimes();
    validateForm();
    if (nextFromPro) nextFromPro.disabled = false;
  }
  if (proPills){
    proPills.addEventListener('click', (e)=>{
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      const pill = t.closest('.pro-pill');
      if (!pill) return;
      setPro(pill.dataset.id || '0');
      // Advance to day selection
      setStep(3);
    });
    proPills.addEventListener('keydown', (e)=>{
      if (e.key !== 'Enter' && e.key !== ' ') return;
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      const pill = t.closest('.pro-pill');
      if (!pill) return;
      e.preventDefault();
      setPro(pill.dataset.id || '0');
      setStep(3);
    });
  }
  // Initial pro selection (restore previous value if we came back after an error)
  const initBarber = barberInput.value !== '' ? barberInput.value : '0';
  setPro(initBarber, true);

  const today = new Date();
  // Restore selected date (if we returned after a captcha error)
  if (dateInput.value) {
    selectedDate = dateInput.value;
    const parts = dateInput.value.split('-');
    if (parts.length === 3) {
      const y = parseInt(parts[0], 10);
      const m = parseInt(parts[1], 10);
      if (!isNaN(y) && !isNaN(m)) {
        view = new Date(y, m-1, 1);
      }
    }
  }
  function toYMD(d){
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  

  function updateProsForService(sid){
    if (!proPills) return;
    const allowed = (SERVICE_BARBERS && SERVICE_BARBERS[sid]) ? SERVICE_BARBERS[sid].map(String) : null;
    const pills = [...proPills.querySelectorAll('.pro-pill')];
    // Keep the first pill (0 = primer disponible) always visible
    pills.forEach((pill, idx)=>{
      const pid = pill.dataset.id || '';
      if (pid === '0' || pid === '') { pill.style.display = ''; return; }
      if (!allowed || allowed.length === 0) { pill.style.display = ''; return; }
      pill.style.display = allowed.includes(pid) ? '' : 'none';
    });
    // If current selected pro is not allowed, reset to 0
    const current = barberInput.value || '0';
    if (current !== '0' && allowed && allowed.length > 0 && !allowed.includes(current)) {
      setPro('0');
    }
  }

  function selectService(card, keepTime=false){
    [...grid.querySelectorAll('.service-card')].forEach(c=>c.classList.remove('selected'));
    card.classList.add('selected');
    selectedService = card;
    serviceInput.value = card.dataset.id;
    updateProsForService(card.dataset.id);
    if (!keepTime) {
      // reset time
      selectedTime = null;
      timeInput.value = '';
    } else {
      selectedTime = timeInput.value || null;
    }
    loadTimes();
    validateForm();
    if (nextFromService) nextFromService.disabled = false;
    // Auto-advance to next step for a smoother flow (only on user selection)
    if (!keepTime && currentStep === 1) setStep(hasPro ? 2 : 3);
  }

  grid.addEventListener('click', (e)=>{
    const card = e.target.closest('.service-card');
    if(!card) return;
    selectService(card);
  });
  grid.addEventListener('keydown', (e)=>{
    if(e.key !== 'Enter' && e.key !== ' ') return;
    const card = e.target.closest('.service-card');
    if(!card) return;
    e.preventDefault();
    selectService(card);
  });

  // Restore selected service (if any)
  if (serviceInput.value) {
    const initCard = grid.querySelector(`.service-card[data-id="${serviceInput.value}"]`);
    if (initCard) {
      selectService(initCard, true);
    }
  }

  async function loadTimes(){
    submit.disabled = true;
    timesWrap.innerHTML = '';
    timesHelp.textContent = 'Cargando horarios...';

    const sid = serviceInput.value;
    const d = dateInput.value;
    const bid = barberInput.value;
    if(bid === '' || !sid || !d){
      timesHelp.textContent = 'Elegí servicio y fecha para ver horarios.';
      return;
    }

    try{
      const res = await fetch(`api.php?action=times&barber_id=${encodeURIComponent(bid)}&service_id=${encodeURIComponent(sid)}&date=${encodeURIComponent(d)}`);
      const data = await res.json();
      if(!data.ok){
        timesHelp.textContent = data.error || 'Sin horarios.';
        return;
      }
      const times = data.times || [];
      if(times.length===0){
        if (data.message) {
          timesHelp.textContent = data.message;
        } else if (bid === '0') {
          timesHelp.textContent = 'No hay horarios disponibles.';
        } else {
          timesHelp.textContent = `No hay horarios disponibles para ${data.barber_name || 'este profesional'}.`;
        }
        return;
      }
      timesHelp.textContent = 'Elegí un horario.';
      times.forEach(t=>{
        const chip = document.createElement('div');
        chip.className='time-chip';
        chip.textContent=t;
        chip.dataset.time=t;
        chip.addEventListener('click', ()=>{
          [...timesWrap.querySelectorAll('.time-chip')].forEach(x=>x.classList.remove('selected'));
          chip.classList.add('selected');
          selectedTime = t;
          timeInput.value = t;
          validateForm();
          if (nextFromTime) nextFromTime.disabled = false;
          setStep(5);
        });
        timesWrap.appendChild(chip);
      });

      // Restore selected time if it exists
      if (timeInput.value) {
        const prev = [...timesWrap.querySelectorAll('.time-chip')].find(x=>x.dataset.time === timeInput.value);
        if (prev) {
          prev.classList.add('selected');
          selectedTime = timeInput.value;
        }
      }
    } catch(e){
      timesHelp.textContent = 'Error al cargar horarios.';
    }
  }

  function validateForm(){
    // barber_id can be "0" (primer profesional disponible)
    submit.disabled = !((barberInput.value !== '') && serviceInput.value && dateInput.value && timeInput.value);
  }

  // Gallery carousel (infinite + autoplay)
  (function initCarousel(){
    const track = document.getElementById('carTrack');
    if(!track) return;
    const prevBtn = document.getElementById('carPrev');
    const nextBtn = document.getElementById('carNext');
    const dotsWrap = document.getElementById('carDots');
    const root = document.getElementById('carousel');
    const slides = [...track.querySelectorAll('.car-item')];
    const len = slides.length;

    if (len <= 1) {
      if (prevBtn) prevBtn.style.display = 'none';
      if (nextBtn) nextBtn.style.display = 'none';
      if (dotsWrap) dotsWrap.style.display = 'none';
      return;
    }

    // Clone ends for seamless loop
    const firstClone = slides[0].cloneNode(true);
    const lastClone = slides[len-1].cloneNode(true);
    track.insertBefore(lastClone, track.firstChild);
    track.appendChild(firstClone);

    let index = 1; // start at the first real slide
    let isAnimating = false;
    let timer = null;

    function setTransform(animate){
      track.style.transition = animate ? 'transform .35s ease' : 'none';
      track.style.transform = `translateX(-${index * 100}%)`;
    }

    function realIndex(){
      // maps [0..len+1] -> [0..len-1]
      if (index === 0) return len - 1;
      if (index === len + 1) return 0;
      return index - 1;
    }

    function renderDots(){
      if(!dotsWrap) return;
      dotsWrap.innerHTML = '';
      for(let i=0;i<len;i++){
        const d = document.createElement('div');
        d.className = 'car-dot' + (i===realIndex() ? ' active' : '');
        dotsWrap.appendChild(d);
      }
    }

    function goNext(){
      if(isAnimating) return;
      isAnimating = true;
      index++;
      setTransform(true);
      renderDots();
    }

    function goPrev(){
      if(isAnimating) return;
      isAnimating = true;
      index--;
      setTransform(true);
      renderDots();
    }

    function startAuto(){
      stopAuto();
      timer = setInterval(goNext, 4000);
    }

    function stopAuto(){
      if(timer) clearInterval(timer);
      timer = null;
    }

    track.addEventListener('transitionend', ()=>{
      // Jump without animation when hitting clones
      if(index === len + 1){
        index = 1;
        setTransform(false);
      } else if(index === 0){
        index = len;
        setTransform(false);
      }
      isAnimating = false;
      renderDots();
    });

    // Swipe/drag (nice on mobile)
    let startX = 0;
    let dragging = false;
    root && root.addEventListener('pointerdown', (e)=>{
      if(e.pointerType === 'mouse') root.style.cursor = 'grabbing';
      dragging = true;
      startX = e.clientX;
      stopAuto();
    });
    window.addEventListener('pointerup', (e)=>{
      if(!dragging) return;
      dragging = false;
      if(root) root.style.cursor = 'grab';
      const dx = e.clientX - startX;
      if (Math.abs(dx) > 50) {
        if (dx < 0) goNext(); else goPrev();
      } else {
        startAuto();
      }
    });

    prevBtn && prevBtn.addEventListener('click', ()=>{ goPrev(); startAuto(); });
    nextBtn && nextBtn.addEventListener('click', ()=>{ goNext(); startAuto(); });
    root && root.addEventListener('mouseenter', stopAuto);
    root && root.addEventListener('mouseleave', startAuto);
    root && root.addEventListener('focusin', stopAuto);
    root && root.addEventListener('focusout', startAuto);

    window.addEventListener('resize', ()=>{ setTransform(false); });

    // Init
    setTransform(false);
    renderDots();
    startAuto();
  })();

  // Calendar
  const calRoot = document.getElementById('calendar');
  let view = new Date(today.getFullYear(), today.getMonth(), 1);

  function renderCalendar(){
    const month = view.getMonth();
    const year = view.getFullYear();
    const first = new Date(year, month, 1);
    const startDow = first.getDay(); // 0 Sun
    const daysInMonth = new Date(year, month+1, 0).getDate();

    const monthName = first.toLocaleString('es-AR',{month:'long', year:'numeric'});
    calRoot.innerHTML = `
      <div class="cal-head">
        <div class="cal-title">${monthName.charAt(0).toUpperCase()+monthName.slice(1)}</div>
        <div class="cal-nav">
          <button type="button" class="cal-btn" id="calPrev">‹</button>
          <button type="button" class="cal-btn" id="calNext">›</button>
        </div>
      </div>
      <div class="cal-grid" id="calGrid"></div>
    `;

    const grid = calRoot.querySelector('#calGrid');
    const dows = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    dows.forEach(d=>{
      const el=document.createElement('div');
      el.className='cal-dow';
      el.textContent=d;
      grid.appendChild(el);
    });

    // empty cells before 1st
    for(let i=0;i<startDow;i++){
      const el=document.createElement('div');
      el.className='cal-day disabled';
      el.textContent='';
      grid.appendChild(el);
    }

    for(let day=1; day<=daysInMonth; day++){
      const d = new Date(year, month, day);
      const ymd = toYMD(d);
      const isPast = d < new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const el=document.createElement('div');
      el.className='cal-day' + (isPast ? ' disabled' : '');
      el.textContent=String(day);
      el.dataset.ymd=ymd;
      if(selectedDate===ymd) el.classList.add('selected');
      el.addEventListener('click', ()=>{
        if(isPast) return;
        selectedDate = ymd;
        dateInput.value = ymd;
        // clear time
        selectedTime = null;
        timeInput.value = '';
        renderCalendar();
        loadTimes();
        validateForm();
        if (nextFromDay) nextFromDay.disabled = false;
        // Go to time selection
        setStep(4);
      });
      grid.appendChild(el);
    }

    const prevBtn = calRoot.querySelector('#calPrev');
    const nextBtn = calRoot.querySelector('#calNext');
    prevBtn.addEventListener('click', ()=>{
      const prev = new Date(year, month-1, 1);
      // don't go to months entirely in the past
      const minMonth = new Date(today.getFullYear(), today.getMonth(), 1);
      if(prev < minMonth) return;
      view = prev;
      renderCalendar();
    });
    nextBtn.addEventListener('click', ()=>{
      view = new Date(year, month+1, 1);
      renderCalendar();
    });
  }

  renderCalendar();

  // Init step based on restored state
  (function initStep(){
    const hasError = <?php echo $bookingError !== '' ? 'true' : 'false'; ?>;
    if (hasError) {
      if (nextFromService) nextFromService.disabled = !serviceInput.value;
      if (nextFromDay) nextFromDay.disabled = !dateInput.value;
      if (nextFromTime) nextFromTime.disabled = !timeInput.value;
      setStep(6);
      return;
    }
    if (timeInput.value) {
      if (nextFromService) nextFromService.disabled = !serviceInput.value;
      if (nextFromDay) nextFromDay.disabled = !dateInput.value;
      if (nextFromTime) nextFromTime.disabled = false;
      setStep(5);
      return;
    }
    if (dateInput.value) {
      if (nextFromService) nextFromService.disabled = !serviceInput.value;
      if (nextFromDay) nextFromDay.disabled = false;
      setStep(4);
      return;
    }
    if (serviceInput.value) {
      if (nextFromService) nextFromService.disabled = false;
      setStep(hasPro ? 2 : 3);
      return;
    }
    setStep(1);
  })();

  // Profesionales: carrusel simple si hay más de 3
  const prosRow = document.getElementById('prosRow');
  const prosPrev = document.getElementById('prosPrev');
  const prosNext = document.getElementById('prosNext');
  if (prosRow && prosPrev && prosNext) {
    const scrollStep = () => {
      const first = prosRow.querySelector('.pro-chip');
      if (!first) return 220;
      const r = first.getBoundingClientRect();
      return Math.max(180, Math.round(r.width + 14));
    };
    prosPrev.addEventListener('click', () => prosRow.scrollBy({ left: -scrollStep(), behavior: 'smooth' }));
    prosNext.addEventListener('click', () => prosRow.scrollBy({ left: scrollStep(), behavior: 'smooth' }));
  }

})();
</script>
<?php page_foot(); ?>