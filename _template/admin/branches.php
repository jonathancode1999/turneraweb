<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
// If user is tied to a specific branch, force selection.
if (!empty($_SESSION['admin_user']) && !empty($_SESSION['admin_user']['branch_id'])) {
    $_SESSION['branch_id'] = (int)$_SESSION['admin_user']['branch_id'];
}

session_start_safe();

$pdo = db();
$cfg = app_config();
$bizId = (int)$cfg['business_id'];

// Business info (for logo fallback)
$biz = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
$biz->execute(array(':id' => $bizId));
$biz = $biz->fetch() ?: array();

// Select branch
if (isset($_GET['select'])) {
    $id = (int)$_GET['select'];
    $allowed = admin_allowed_branch_ids();
    if ($id > 0 && branch_get($id) && in_array($id, $allowed, true)) {
        $_SESSION['branch_id'] = $id;
        redirect('dashboard.php');
    }
}

// Create branch
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_branch') {
    csrf_require();
    $name = trim((string)($_POST['name'] ?? ''));
    $firstBarber = trim((string)($_POST['first_barber_name'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $wa = preg_replace('/\D+/', '', (string)($_POST['whatsapp_phone'] ?? ''));
    $maps = trim((string)($_POST['maps_url'] ?? ''));
    if ($name === '') {
        $error = 'El nombre de la sucursal es obligatorio.';
    } else if ($firstBarber === '') {
        $error = 'Para iniciar la sucursal, ingresá el nombre del primer profesional.';
    } else {
        $copy = !empty($_POST['copy_config']);

        $copyFields = [
            'owner_email' => '',
            'instagram_url' => '',
            'logo_path' => '',
            'cover_path' => '',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
            'smtp_from_email' => '',
            'smtp_from_name' => '',
        ];

        if ($copy) {
            // Copiamos config desde la sucursal principal (id=1). Si no existe, caemos al business.
            $base = $pdo->query("SELECT * FROM branches WHERE id=1 AND business_id=".(int)$bizId)->fetch();
            if (!$base) {
                $base = $pdo->query("SELECT * FROM businesses WHERE id=".(int)$bizId)->fetch();
            }
            if ($base) {
                foreach (array_keys($copyFields) as $k) {
                    if (isset($base[$k])) {
                        $copyFields[$k] = $base[$k];
                    }
                }
            }
        }

        $st = $pdo->prepare("INSERT INTO branches (business_id,name,address,maps_url,whatsapp_phone,owner_email,instagram_url,logo_path,cover_path,
            smtp_host,smtp_port,smtp_user,smtp_pass,smtp_secure,smtp_from_email,smtp_from_name,is_active)
            VALUES (:bid,:n,:a,:m,:w,:oe,:ig,:lp,:cp,:sh,:sp,:su,:pw,:sc,:sfe,:sfn,1)");
        $st->execute(array(
            ':bid'=>$bizId,
            ':n'=>$name,
            ':a'=>$address,
            ':m'=>$maps,
            ':w'=>$wa,
            ':oe'=>(string)$copyFields['owner_email'],
            ':ig'=>(string)$copyFields['instagram_url'],
            ':lp'=>(string)$copyFields['logo_path'],
            ':cp'=>(string)$copyFields['cover_path'],
            ':sh'=>(string)$copyFields['smtp_host'],
            ':sp'=>(int)$copyFields['smtp_port'],
            ':su'=>(string)$copyFields['smtp_user'],
            ':pw'=>(string)$copyFields['smtp_pass'],
            ':sc'=>(string)($copyFields['smtp_secure'] ?: 'tls'),
            ':sfe'=>(string)$copyFields['smtp_from_email'],
            ':sfn'=>(string)$copyFields['smtp_from_name'],
        ));
        $newId = (int)$pdo->lastInsertId();

        // Create first barber/staff for the new branch (minimum setup)
        $st2 = $pdo->prepare("INSERT INTO barbers (business_id, branch_id, name, capacity, is_active, avatar_path, cover_path) VALUES (:bid,:brid,:n,1,1,'','')");
        $st2->execute(array(':bid'=>$bizId, ':brid'=>$newId, ':n'=>$firstBarber));
        $newBarberId = (int)$pdo->lastInsertId();

        // Seed horarios para la nueva sucursal: copiamos los horarios del negocio (sucursal 1) si existen.
        try {
            // Business hours
            $bh = $pdo->prepare('SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=:bid AND branch_id=1 ORDER BY weekday');
            $bh->execute(array(':bid'=>$bizId));
            $baseHours = $bh->fetchAll() ?: [];

            if (count($baseHours) === 0) {
                // Defaults: Lun-Sáb 09:00-18:00, Dom cerrado
                $baseHours = [];
                for ($wd = 0; $wd <= 6; $wd++) {
                    $isClosed = ($wd === 0) ? 1 : 0;
                    $baseHours[] = array(
                        'weekday' => $wd,
                        'open_time' => $isClosed ? null : '09:00',
                        'close_time' => $isClosed ? null : '18:00',
                        'is_closed' => $isClosed,
                    );
                }
            }

            $insBH = $pdo->prepare('INSERT OR REPLACE INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (:bid,:brid,:wd,:o,:c,:cl)');
            foreach ($baseHours as $h) {
                $insBH->execute(array(
                    ':bid'=>$bizId,
                    ':brid'=>$newId,
                    ':wd'=>(int)$h['weekday'],
                    ':o'=>$h['open_time'],
                    ':c'=>$h['close_time'],
                    ':cl'=>(int)$h['is_closed'],
                ));
            }

            // Barber hours: mismo patrón que business_hours
            $insBR = $pdo->prepare('INSERT OR REPLACE INTO barber_hours (business_id, branch_id, barber_id, weekday, open_time, close_time, is_closed) VALUES (:bid,:brid,:bar,:wd,:o,:c,:cl)');
            foreach ($baseHours as $h) {
                $insBR->execute(array(
                    ':bid'=>$bizId,
                    ':brid'=>$newId,
                    ':bar'=>$newBarberId,
                    ':wd'=>(int)$h['weekday'],
                    ':o'=>$h['open_time'],
                    ':c'=>$h['close_time'],
                    ':cl'=>(int)$h['is_closed'],
                ));
            }
        } catch (Throwable $e) {
            // Si falla el seed, igual dejamos creada la sucursal; el admin puede ajustar horarios luego.
        }

        $_SESSION['branch_id'] = $newId;
        redirect('dashboard.php');
    }
}

// Delete branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_branch') {
    csrf_require();
    $id = (int)($_POST['branch_id'] ?? 0);
    // Keep at least one branch.
    $totalBranches = (int)$pdo->query("SELECT COUNT(1) FROM branches WHERE business_id=" . (int)$bizId)->fetchColumn();
    if ($totalBranches <= 1) {
        $error = 'No podés eliminar la única sucursal.';
    } else {
        // No permitir eliminar si hay turnos activos/pedientes.
        $st = $pdo->prepare("SELECT COUNT(1) FROM appointments WHERE business_id=:bid AND branch_id=:brid AND status IN ('PENDIENTE_APROBACION','REPROGRAMACION_PENDIENTE','ACEPTADO','OCUPADO')");
        $st->execute(array(':bid'=>$bizId, ':brid'=>$id));
        $cnt = (int)$st->fetchColumn();
        if ($cnt > 0) {
            $error = 'No se puede eliminar: hay turnos pendientes/activos en esa sucursal.';
        } else {
            // Borramos datos dependientes primero (sin FK cascade garantizado en SQLite).
            $pdo->prepare('DELETE FROM blocks WHERE business_id=:bid AND branch_id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));
            $pdo->prepare('DELETE FROM barber_hours WHERE business_id=:bid AND branch_id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));
            $pdo->prepare('DELETE FROM barber_timeoff WHERE business_id=:bid AND branch_id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));
            $pdo->prepare('DELETE FROM business_hours WHERE business_id=:bid AND branch_id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));
            $pdo->prepare('DELETE FROM barbers WHERE business_id=:bid AND branch_id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));
            $pdo->prepare('DELETE FROM branches WHERE business_id=:bid AND id=:brid')->execute(array(':bid'=>$bizId, ':brid'=>$id));

            if (!empty($_SESSION['branch_id']) && (int)$_SESSION['branch_id'] === $id) {
                unset($_SESSION['branch_id']);
            }
            $notice = 'Sucursal eliminada.';
        }
    }
}

$allowed = admin_allowed_branch_ids();
$branches = branches_all_active();
$canManage = admin_can('branches');

page_head('Sucursales', 'admin', '<div class="brand">Panel Admin</div>');
echo admin_nav('branches');

echo '<h1>Sucursales</h1>';
echo '<p class="muted">Elegí una sucursal para administrar o agregá una nueva.</p>';
if ($notice) echo '<div class="notice ok">'.h($notice).'</div>';
if ($error) echo '<div class="notice danger">'.h($error).'</div>';

echo '<div class="cards-grid">';

// Existing branches
foreach ($branches as $b) {
    $hasAccess = in_array((int)$b['id'], $allowed, true);
    $sel = $hasAccess && (!empty($_SESSION['branch_id']) && (int)$_SESSION['branch_id'] === (int)$b['id']);
    $counts = $pdo->prepare('SELECT COUNT(1) FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1');
    $counts->execute(array(':bid'=>$bizId, ':brid'=>(int)$b['id']));
    $barberCount = (int)$counts->fetchColumn();

    echo '<div class="card branch-card'.($sel?' branch-selected':'').(!$hasAccess?' branch-disabled':'').'">';
    echo '<a class="card-link'.(!$hasAccess?' disabled':'').'" href="'.($hasAccess ? ('branches.php?select='.(int)$b['id']) : '#').'"'.(!$hasAccess?' onclick="return false;" aria-disabled="true"':'').'>';
    // Logo: show branch logo if present, else fallback to business logo.
    $logo = '';
    if (!empty($b['logo_path'])) $logo = (string)$b['logo_path'];
    elseif (!empty($biz['logo_path'])) $logo = (string)$biz['logo_path'];
	    if ($logo !== '') {
	        $src = ltrim((string)$logo, '/');
	        if (strpos($src, 'public/') !== 0) $src = 'public/' . $src;
	        echo '<div class="branch-logo"><img src="../' . h($src) . '" alt="Logo" /></div>';
	    }
	    $title = (string)$b['name'];
    if (trim($title)==='Sucursal Principal' && !empty($biz['name'])) { $title = (string)$biz['name']; }
    echo '<div class="card-title">'.h($title).'</div>';
    if (!$hasAccess) echo '<div class="badge" style="margin-top:8px;display:inline-block;opacity:.8">Sin acceso</div>';

    $addr = trim((string)$b['address']);
    if ($addr !== '') echo '<div class="card-sub">'.h($addr).'</div>';

    $waTxt = trim((string)$b['whatsapp_phone']);
    if ($waTxt !== '') echo '<div class="muted small" style="margin-top:6px">WhatsApp: '.h($waTxt).'</div>';
    echo '<div class="muted small" style="margin-top:4px">Profesionales activos: <b>'.(int)$barberCount.'</b></div>';

    if ($sel) echo '<div class="badge ok" style="margin-top:10px;display:inline-block">Sucursal seleccionada</div>';
    echo '</a>';

	    if ($canManage) {
	        // Allow deleting any branch, but keep at least one branch alive and prevent deletion if it has active/pending items.
	        echo '<form method="post" style="margin-top:10px">';
	        csrf_field();
	        echo '<input type="hidden" name="action" value="delete_branch">';
	        echo '<input type="hidden" name="branch_id" value="'.(int)$b['id'].'">';
	        echo '<button class="btn danger" type="submit" onclick="return confirm(\'¿Eliminar esta sucursal? Solo se permite si no tiene turnos pendientes/activos.\')">Eliminar</button>';
	        echo '</form>';
	    }

    echo '</div>';
}

if ($canManage) {

// Add branch card
echo '<div class="card">';
echo '<div class="card-title">Agregar sucursal</div>';
echo '<form method="post" class="form" style="margin-top:10px">';
echo csrf_field();
echo '<input type="hidden" name="action" value="create_branch">';
echo '<label>Nombre</label><input name="name" required>';
echo '<label>Primer profesional</label><input name="first_barber_name" required placeholder="Ej: Juan Pérez">';
echo '<label>Dirección</label><input name="address" placeholder="Ej: Av. ...">';
echo '<label>WhatsApp</label><input name="whatsapp_phone" placeholder="Ej: 54911...">';
echo '<label>Maps URL</label><input name="maps_url" placeholder="https://...">';
echo '<label style="display:flex;gap:10px;align-items:center;margin-top:10px">'
    .'<input type="checkbox" name="copy_config" value="1" checked> '
    .'<span>Copiar configuración de la sucursal actual (logo/portada/IG/SMTP)</span>'
    .'</label>';
	echo '<button class="btn primary" type="submit">Crear</button>';
	echo '</form>';
	echo '</div>';
}

echo '</div>';

page_foot();
