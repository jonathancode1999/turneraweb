<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_permission('analytics');

$pdo = db();
$cfg = app_config();
$bizId = (int)$cfg['business_id'];

$branches = branches_all_active();

$tab = $_GET['tab'] ?? 'turnos';
if (!in_array($tab, array('turnos','finanzas'), true)) $tab = 'turnos';

$branchId = (int)($_GET['branch_id'] ?? 0); // 0=General (todas)
$period = $_GET['period'] ?? 'month';
if (!in_array($period, array('day','week','month','year'), true)) $period = 'month';

$today = date('Y-m-d');
$month = $_GET['month'] ?? date('Y-m');
$year  = (int)($_GET['year'] ?? (int)date('Y'));
$day   = $_GET['day'] ?? $today;
$week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));

if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) $week_start = date('Y-m-d', strtotime('monday this week'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

function range_for_period(string $period, string $day, string $week_start, string $month, int $year): array {
    if ($period === 'day') {
        $s = $day;
        $e = date('Y-m-d', strtotime($day . ' +1 day'));
        return array($s, $e);
    }
    if ($period === 'week') {
        $s = $week_start;
        $e = date('Y-m-d', strtotime($week_start . ' +7 day'));
        return array($s, $e);
    }
    if ($period === 'year') {
        $s = sprintf('%04d-01-01', $year);
        $e = sprintf('%04d-01-01', $year + 1);
        return array($s, $e);
    }
    // month
    $s = $month . '-01';
    $dt = new DateTime($s);
    $e = $dt->modify('first day of next month')->format('Y-m-d');
    return array($s, $e);
}

list($startDate, $endDate) = range_for_period($period, $day, $week_start, $month, $year);

// Shared WHERE for appointments
$params = array(':bid'=>$bizId, ':s'=>$startDate, ':e'=>$endDate);
$where = "a.business_id=:bid AND a.start_at>=:s AND a.start_at<:e";

if ($branchId > 0) {
    $where .= " AND a.branch_id=:brid";
    $params[':brid'] = $branchId;
}

function scope_label(int $branchId, array $branches): string {
    if ($branchId <= 0) return 'General';
    foreach ($branches as $b) {
        if ((int)$b['id'] === $branchId) return (string)$b['name'];
    }
    return 'Sucursal';
}

function appt_income_sql(): string {
    // Prefer price_snapshot_ars when available, else services.price_ars
    return "SUM(CASE WHEN a.price_snapshot_ars IS NOT NULL AND a.price_snapshot_ars>0 THEN a.price_snapshot_ars ELSE IFNULL(s.price_ars,0) END)";
}

function parse_ars(string $raw): int {
    // Accept "$ 12.345" / "12345" / "12,345" etc.
    $n = preg_replace('/[^0-9]/', '', $raw);
    if ($n === '' || $n === null) return 0;
    $v = (int)$n;
    return $v < 0 ? 0 : $v;
}


// --- Actions (POST/CSV) ---
// (Cobros masivos eliminado: se maneja desde Turnos)

// CSV export
if (isset($_GET['export']) && $tab === 'finanzas') {
    $what = $_GET['export']; // 'gastos' | 'ingresos'
    $filename = 'export_' . $what . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');

    if ($what === 'gastos') {
        fputcsv($out, array('fecha','categoria','descripcion','monto_ars','sucursal_id'));
        $expParams = array(':bid'=>$bizId, ':s'=>$startDate, ':e'=>$endDate);
        $expWhere = "business_id=:bid AND expense_date>=:s AND expense_date<:e";
        if ($branchId > 0) { $expWhere .= " AND (branch_id=0 OR branch_id=:brid)"; $expParams[':brid']=$branchId; }
        $q = $pdo->prepare("SELECT expense_date, category, description, amount_ars, branch_id FROM expenses WHERE $expWhere ORDER BY expense_date ASC, id ASC");
        $q->execute($expParams);
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, array($r['expense_date'], $r['category'], $r['description'], (int)$r['amount_ars'], (int)$r['branch_id']));
        }
    } else { // ingresos
        fputcsv($out, array('inicio','servicio','profesional','cliente','monto_ars'));
        $w = $where . " AND a.status IN ('ACEPTADO','OCUPADO')";
	        $q = $pdo->prepare("SELECT a.start_at, s.name AS service_name, IFNULL(b.name,'') AS barber_name, IFNULL(a.customer_name,'') AS customer_name,
	                                   (CASE WHEN a.price_snapshot_ars IS NOT NULL AND a.price_snapshot_ars>0 THEN a.price_snapshot_ars ELSE IFNULL(s.price_ars,0) END) AS amount_ars
                            FROM appointments a
                            JOIN services s ON s.id=a.service_id
                            LEFT JOIN barbers b ON b.id=a.barber_id
                            WHERE $w
                            ORDER BY a.start_at ASC, a.created_at ASC");
        $q->execute($params);
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, array($r['start_at'], $r['service_name'], $r['barber_name'], $r['customer_name'], (int)$r['amount_ars']));
        }
    }
    fclose($out);
    exit;
}

page_head('Analytics', 'admin');
admin_nav('system');

// UI helpers (tooltips)
echo '<style>.tip{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:999px;border:1px solid rgba(45,123,209,.35);font-size:12px;margin-left:6px;cursor:help;user-select:none}.tip:hover{background:rgba(45,123,209,.08)}</style>';

// Tabs
echo '<div class="tabs" style="margin-bottom:12px">';
$qs = $_GET; $qs['tab']='turnos';
echo '<a class="tab'.($tab==='turnos'?' active':'').'" href="?'.http_build_query($qs).'">Turnos</a>';
$qs['tab']='finanzas';
echo '<a class="tab'.($tab==='finanzas'?' active':'').'" href="?'.http_build_query($qs).'">Finanzas</a>';
echo '</div>';

// Filters
echo '<div class="card">';
echo '<div class="card-title">Filtros</div>';
echo '<form method="get" class="form" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:flex-end">';
echo '<input type="hidden" name="tab" value="'.h($tab).'">';

echo '<div><label>Sucursal</label><select name="branch_id"><option value="0">General</option>';
foreach ($branches as $b) {
    $sel = ($branchId===(int)$b['id'])?' selected':'';
    echo '<option value="'.(int)$b['id'].'"'.$sel.'>'.h($b['name']).'</option>';
}
echo '</select></div>';

echo '<div><label>Ver por</label><select name="period" onchange="this.form.submit()">';
$opts = array('day'=>'Día','week'=>'Semana','month'=>'Mes','year'=>'Año');
foreach ($opts as $k=>$v) {
    $sel = ($period===$k)?' selected':'';
    echo '<option value="'.h($k).'"'.$sel.'>'.h($v).'</option>';
}
echo '</select></div>';

if ($period === 'day') {
    echo '<div><label>Día</label><input type="date" name="day" value="'.h($day).'"></div>';
} elseif ($period === 'week') {
    echo '<div><label>Semana (lunes)</label><input type="date" name="week_start" value="'.h($week_start).'"></div>';
} elseif ($period === 'year') {
    echo '<div><label>Año</label><input type="number" min="2000" max="2100" name="year" value="'.(int)$year.'"></div>';
} else {
    echo '<div><label>Mes</label><input type="month" name="month" value="'.h($month).'"></div>';
}

echo '<div><button class="btn primary" type="submit">Ver</button></div>';
echo '</form>';
echo '<div class="muted" style="margin-top:8px">Mostrando: <b>'.h(scope_label($branchId,$branches)).'</b> · del <b>'.h($startDate).'</b> al <b>'.h(date('Y-m-d', strtotime($endDate.' -1 day'))).'</b></div>';
echo '</div>';

if ($tab === 'turnos') {
    // --- Turnos: Por periodo ---
    $groupExpr = "substr(a.start_at,1,10)";
    $groupLabel = "Día";
    if ($period === 'week') {
        $groupExpr = "substr(a.start_at,1,10)";
        $groupLabel = "Día";
    } elseif ($period === 'month') {
        $groupExpr = "substr(a.start_at,1,10)";
        $groupLabel = "Día";
    } elseif ($period === 'year') {
        $groupExpr = "substr(a.start_at,1,7)"; // YYYY-MM
        $groupLabel = "Mes";
    } elseif ($period === 'day') {
        $groupExpr = "substr(a.start_at,12,5)"; // HH:MM
        $groupLabel = "Hora";
    }

    $whereOk = $where . " AND a.status IN ('ACEPTADO','OCUPADO','PENDIENTE_APROBACION','REPROGRAMACION_PENDIENTE')";
    $stmt = $pdo->prepare("SELECT $groupExpr as k, COUNT(1) cnt
                           FROM appointments a
                           WHERE $whereOk
                           GROUP BY k
                           ORDER BY k ASC");
    $stmt->execute($params);
    $series = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Heatmap by weekday x hour (for week/month/year) - for day it is pointless
    $heat = array();
    if ($period !== 'day') {
        $hstmt = $pdo->prepare("SELECT CAST(strftime('%w', a.start_at) AS INTEGER) as wd,
                                       CAST(strftime('%H', a.start_at) AS INTEGER) as hh,
                                       COUNT(1) cnt
                                FROM appointments a
                                WHERE $whereOk
                                GROUP BY wd, hh");
        $hstmt->execute($params);
        foreach ($hstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $wd = (int)$r['wd']; $hh = (int)$r['hh']; $cnt = (int)$r['cnt'];
            if (!isset($heat[$wd])) $heat[$wd] = array();
            $heat[$wd][$hh] = $cnt;
        }
    }

    // Top services
    $topServices = $pdo->prepare("SELECT s.name, COUNT(1) cnt
                                  FROM appointments a
                                  JOIN services s ON s.id=a.service_id
                                  WHERE $whereOk
                                  GROUP BY s.id
                                  ORDER BY cnt DESC
                                  LIMIT 10");
    $topServices->execute($params);
    $services = $topServices->fetchAll(PDO::FETCH_ASSOC);

    // Top barbers
    $topBarbers = $pdo->prepare("SELECT b.name, COUNT(1) cnt
                                 FROM appointments a
                                 LEFT JOIN barbers b ON b.id=a.barber_id
                                 WHERE $whereOk
                                 GROUP BY a.barber_id
                                 ORDER BY cnt DESC
                                 LIMIT 10");
    $topBarbers->execute($params);
    $barbers = $topBarbers->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="cards-grid" style="margin-top:12px;grid-template-columns: 2fr 1fr;">';

    echo '<div class="card">';
	    echo '<div class="card-title">Turnos por '.$groupLabel.'<span class="tip" title="Cantidad de turnos confirmados en el período seleccionado.">i</span></div>';
    if ($series) {
        $lbls = array(); $vals = array();
        foreach ($series as $r) { $lbls[] = (string)$r['k']; $vals[] = (int)$r['cnt']; }
        echo '<canvas id="chart_turnos_period" height="120" style="margin-top:10px"></canvas>';
        echo '<script>window.__ANALYTICS__=window.__ANALYTICS__||{};window.__ANALYTICS__.turnosPeriod='.json_encode(array('labels'=>$lbls,'values'=>$vals)).';</script>';
    }
    if (!$series) {
        echo '<div class="muted" style="margin-top:10px">Sin datos.</div>';
    } else {
        echo '<table class="table" style="margin-top:10px"><tr><th>'.$groupLabel.'</th><th>Cantidad</th></tr>';
        foreach ($series as $r) {
            echo '<tr><td>'.h($r['k']).'</td><td><b>'.(int)$r['cnt'].'</b></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card">';
	    echo '<div class="card-title">Top servicios<span class="tip" title="Servicios más elegidos (según turnos confirmados en el período).">i</span></div>';
    if ($services) {
        $lbls = array(); $vals = array();
        foreach ($services as $r) { $lbls[] = (string)$r['name']; $vals[] = (int)$r['cnt']; }
        echo '<canvas id="chart_top_services" height="160" style="margin-top:10px"></canvas>';
        echo '<script>window.__ANALYTICS__=window.__ANALYTICS__||{};window.__ANALYTICS__.topServices='.json_encode(array('labels'=>$lbls,'values'=>$vals)).';</script>';
    }
    if (!$services) {
        echo '<div class="muted" style="margin-top:10px">Sin datos.</div>';
    } else {
        echo '<table class="table" style="margin-top:10px"><tr><th>Servicio</th><th>Cant.</th></tr>';
        foreach ($services as $r) echo '<tr><td>'.h($r['name']).'</td><td><b>'.(int)$r['cnt'].'</b></td></tr>';
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card" style="grid-column: span 2">';
	    echo '<div class="card-title">Turnos por horario (heatmap)<span class="tip" title="Muestra en qué franjas horarias se concentran los turnos. Elegí Semana/Mes/Año.">i</span></div>';
    if ($period === 'day') {
        echo '<div class="muted" style="margin-top:10px">Elegí Semana/Mes/Año para ver el heatmap.</div>';
    } else {
        // compute max for intensity
        $max = 0;
        foreach ($heat as $wd=>$hours) foreach ($hours as $hh=>$cnt) { if ($cnt>$max) $max=$cnt; }
        $days = array('Dom','Lun','Mar','Mié','Jue','Vie','Sáb');
        echo '<div style="overflow:auto;margin-top:10px">';
        echo '<table class="heatmap"><tr><th>Hora</th>';
        foreach ($days as $d) echo '<th>'.h($d).'</th>';
        echo '</tr>';
        for ($hh=0;$hh<24;$hh++) {
            echo '<tr><td class="hm-hour">'.sprintf('%02d:00',$hh).'</td>';
            for ($wd=0;$wd<=6;$wd++) {
                $cnt = isset($heat[$wd][$hh]) ? (int)$heat[$wd][$hh] : 0;
                $alpha = ($max>0) ? ($cnt/$max) : 0;
                $alpha = min(1, max(0, $alpha));
                // Use rgba with a neutral blue-ish tone, no hard-coded? We'll keep subtle gray with alpha.
                $style = 'style="background:rgba(45,123,209,'.(0.08 + 0.55*$alpha).')"';
                echo '<td class="hm-cell" '.$style.'>'.($cnt>0?'<b>'.$cnt.'</b>':'').'</td>';
            }
            echo '</tr>';
        }
        echo '</table></div>';
        echo '<div class="muted" style="margin-top:8px">Cuanto más oscuro, más turnos en esa franja.</div>';
    }
    echo '</div>';

    echo '<div class="card">';
	    echo '<div class="card-title">Top profesionales<span class="tip" title="Profesionales con más turnos confirmados en el período.">i</span></div>';
    if ($barbers) {
        $lbls = array(); $vals = array();
        foreach ($barbers as $r) { $lbls[] = (string)($r['name'] ?: '—'); $vals[] = (int)$r['cnt']; }
        echo '<canvas id="chart_top_barbers" height="180" style="margin-top:10px"></canvas>';
        echo '<script>window.__ANALYTICS__=window.__ANALYTICS__||{};window.__ANALYTICS__.topBarbers='.json_encode(array('labels'=>$lbls,'values'=>$vals)).';</script>';
    }
    if (!$barbers) {
        echo '<div class="muted" style="margin-top:10px">Sin datos.</div>';
    } else {
        echo '<table class="table" style="margin-top:10px"><tr><th>Profesional</th><th>Cant.</th></tr>';
        foreach ($barbers as $r) echo '<tr><td>'.h($r['name'] ?: '—').'</td><td><b>'.(int)$r['cnt'].'</b></td></tr>';
        echo '</table>';
    }
    echo '</div>';

    echo '</div>';

} else {
    // --- Finanzas ---
    // Expense CRUD
    $action = $_POST['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_require();
        if ($action === 'add_expense') {
            $edate = trim($_POST['expense_date'] ?? '');
            $cat = trim($_POST['category'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $amt = parse_ars((string)($_POST['amount_ars'] ?? '0'));
            $expBranch = (int)($_POST['expense_branch_id'] ?? 0);
            $isRecurring = !empty($_POST['is_recurring']) ? 1 : 0;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $edate)) $edate = $today;
            if ($cat === '') $cat = 'Otros';
            if ($amt < 0) $amt = 0;

            $pdo->prepare("INSERT INTO expenses (business_id, branch_id, expense_date, category, description, amount_ars, is_recurring)
                           VALUES (:bid,:brid,:d,:c,:desc,:a,:r)")
                ->execute(array(':bid'=>$bizId, ':brid'=>$expBranch, ':d'=>$edate, ':c'=>$cat, ':desc'=>$desc, ':a'=>$amt, ':r'=>$isRecurring));
            flash('Gasto agregado.', 'success');
            header('Location: ?'.http_build_query(array_merge($_GET, array('tab'=>'finanzas'))));
            exit;
        }
        if ($action === 'update_expense') {
            $eid = (int)($_POST['expense_id'] ?? 0);
            $edate = trim($_POST['expense_date'] ?? '');
            $cat = trim($_POST['category'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $amt = parse_ars((string)($_POST['amount_ars'] ?? '0'));
            $expBranch = (int)($_POST['expense_branch_id'] ?? 0);
            $isRecurring = !empty($_POST['is_recurring']) ? 1 : 0;

            if ($eid <= 0) {
                flash('Gasto inválido.', 'error');
                header('Location: ?'.http_build_query(array_merge($_GET, array('tab'=>'finanzas'))));
                exit;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $edate)) $edate = $today;
            if ($cat === '') $cat = 'Otros';
            if ($amt < 0) $amt = 0;

            $pdo->prepare("UPDATE expenses
                           SET branch_id=:brid, expense_date=:d, category=:c, description=:desc, amount_ars=:a, is_recurring=:r
                           WHERE id=:id AND business_id=:bid")
                ->execute(array(':brid'=>$expBranch, ':d'=>$edate, ':c'=>$cat, ':desc'=>$desc, ':a'=>$amt, ':r'=>$isRecurring, ':id'=>$eid, ':bid'=>$bizId));

            flash('Gasto actualizado.', 'success');
            $qs2 = $_GET; unset($qs2['edit_expense']);
            header('Location: ?'.http_build_query(array_merge($qs2, array('tab'=>'finanzas'))));
            exit;
        }
        if ($action === 'delete_expense') {
            $eid = (int)($_POST['expense_id'] ?? 0);
            if ($eid>0) {
                $pdo->prepare("DELETE FROM expenses WHERE id=:id AND business_id=:bid")->execute(array(':id'=>$eid, ':bid'=>$bizId));
                flash('Gasto eliminado.', 'success');
            }
            header('Location: ?'.http_build_query(array_merge($_GET, array('tab'=>'finanzas'))));
            exit;
        }
    }

    $statusOk = "a.status IN ('ACEPTADO','OCUPADO')";
    $incStmt = $pdo->prepare("SELECT ".appt_income_sql()." as total
                              FROM appointments a
                              JOIN services s ON s.id=a.service_id
                              WHERE $where AND $statusOk");
    $incStmt->execute($params);
    $income_est = (int)($incStmt->fetchColumn() ?: 0);

    // Expenses: for branch view include global (0) + branchId, for general include all.
    $expParams = array(':bid'=>$bizId, ':s'=>$startDate, ':e'=>$endDate);
    $expWhere = "business_id=:bid AND expense_date>=:s AND expense_date<:e";
    if ($branchId > 0) {
        $expWhere .= " AND (branch_id=0 OR branch_id=:brid)";
        $expParams[':brid'] = $branchId;
    }
    // Expenses (non-recurring in range)
    $expSum = $pdo->prepare("SELECT SUM(amount_ars) FROM expenses WHERE $expWhere AND IFNULL(is_recurring,0)=0");
    $expSum->execute($expParams);
    $expenses_total = (int)($expSum->fetchColumn() ?: 0);

    // Recurring monthly expenses: counted once per month, starting from expense_date month.
    // We only apply recurring when the view is month/year (makes sense for monthly bills like rent).
    $recurring_total = 0;
    if ($period === 'month' || $period === 'year') {
        $rParams = array(':bid'=>$bizId, ':e'=>$endDate);
        $rWhere = "business_id=:bid AND IFNULL(is_recurring,0)=1 AND expense_date<:e";
        if ($branchId > 0) { $rWhere .= " AND (branch_id=0 OR branch_id=:brid)"; $rParams[':brid']=$branchId; }
        $r = $pdo->prepare("SELECT expense_date, amount_ars FROM expenses WHERE $rWhere");
        $r->execute($rParams);
        $recs = $r->fetchAll(PDO::FETCH_ASSOC);

        // count months in range
        $rangeStart = new DateTime($startDate);
        $rangeStart->modify('first day of this month');
        $rangeEnd = new DateTime($endDate);
        $rangeEnd->modify('first day of this month');

        foreach ($recs as $row) {
            $sd = $row['expense_date'] ?: $startDate;
            $startM = new DateTime(substr($sd,0,7).'-01');
            $from = $startM > $rangeStart ? $startM : $rangeStart;
            $to = $rangeEnd;
            if ($from >= $to) continue;
            $months = ((int)$to->format('Y') - (int)$from->format('Y'))*12 + ((int)$to->format('n') - (int)$from->format('n'));
            if ($months <= 0) continue;
            $recurring_total += (int)$row['amount_ars'] * $months;
        }
    }
    $expenses_total += $recurring_total;

	$income = $income_est; // Ingresos reales: tomamos todos los turnos confirmados/ocupados del período
	$margin = $income - $expenses_total;

    // Expense list
    $expList = $pdo->prepare("SELECT * FROM expenses WHERE $expWhere ORDER BY expense_date DESC, id DESC LIMIT 200");
    $expList->execute($expParams);
    $expenses = $expList->fetchAll(PDO::FETCH_ASSOC);

    
    // Toolbar: export
    echo '<div class="card" style="margin-top:12px">';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between">';
    echo '<div>';
    echo '<div class="card-title" style="margin:0">Acciones</div>';
    echo '<div class="muted" style="margin-top:6px">Exportá CSV de ingresos y gastos del período seleccionado.</div>';
    echo '</div>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
    $expQs = $_GET;
    $expQs['tab'] = 'finanzas';
    $expQs['export'] = 'ingresos';
    echo '<a class="btn" href="?' . http_build_query($expQs) . '">Exportar ingresos CSV</a>';
    $expQs['export'] = 'gastos';
    echo '<a class="btn" href="?' . http_build_query($expQs) . '">Exportar gastos CSV</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

echo '<div class="cards-grid" style="margin-top:12px;grid-template-columns: 1fr 1fr;">';

    echo '<div class="card">';
    echo '<div class="card-title">Resumen<span class="tip" title="Ingresos y margen se calculan automáticamente a partir de los turnos confirmados/ocupados del período.">i</span></div>';
    echo '<div class="kpis">';
	    echo '<div class="kpi"><div class="kpi-label">Ingresos</div><div class="kpi-value">$ '.number_format($income, 0, ',', '.').'</div></div>';
    echo '<div class="kpi"><div class="kpi-label">Gastos</div><div class="kpi-value">$ '.number_format($expenses_total, 0, ',', '.').'</div></div>';
	    echo '<div class="kpi"><div class="kpi-label">Margen</div><div class="kpi-value">$ '.number_format($margin, 0, ',', '.').'</div></div>';
    echo '</div>';
	    echo '<div class="muted" style="margin-top:10px">Datos calculados a partir de los últimos <b>6</b> meses (y del rango seleccionado).</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<div class="card-title">Agregar gasto</div>';
    echo '<form method="post" class="form" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px">';
    csrf_field();
    echo '<input type="hidden" name="action" value="add_expense">';
    echo '<div><label>Fecha</label><input type="date" name="expense_date" value="'.h($today).'"></div>';
    echo '<div><label>Categoría</label><input type="text" name="category" placeholder="Alquiler, insumos..." value=""></div>';
    echo '<div style="grid-column: span 2"><label>Descripción</label><input type="text" name="description" placeholder="Detalle (opcional)"></div>';
    echo '<div><label>Monto (ARS)</label>';
    echo '<input type="text" name="amount_ars" class="money" inputmode="numeric" placeholder="$ 0" value="">';
    echo '<div class="muted" style="margin-top:4px">Escribí el monto. Se formatea como moneda.</div>';
    echo '</div>';
    echo '<div><label>Aplicar a</label><select name="expense_branch_id">';
    echo '<option value="0">General</option>';
    foreach ($branches as $b) {
        echo '<option value="'.(int)$b['id'].'">'.h($b['name']).'</option>';
    }
    echo '</select></div>';
    echo '<div style="grid-column: span 2">';
    echo '<label style="display:flex;gap:8px;align-items:center">';
    echo '<input type="checkbox" name="is_recurring" value="1">';
    echo 'Gasto mensual (impacta todos los meses a partir de esta fecha)';
    echo '</label>';
    echo '</div>';
    echo '<div style="grid-column: span 2"><button class="btn primary" type="submit">Guardar gasto</button></div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="card" style="grid-column: span 2">';
    echo '<div class="card-title">Gastos ('.$startDate.' → '.date('Y-m-d', strtotime($endDate.' -1 day')).')</div>';
    if (!$expenses) {
        echo '<div class="muted" style="margin-top:10px">Sin gastos cargados en este período.</div>';
    } else {
        $editId = (int)($_GET['edit_expense'] ?? 0);
        echo '<table class="table" style="margin-top:10px"><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Monto</th><th style="width:220px"></th></tr>';
        foreach ($expenses as $e) {
            $amt = (int)$e['amount_ars'];
            $isRec = (int)($e['is_recurring'] ?? 0) === 1;
            if ($editId === (int)$e['id']) {
                echo '<tr>';
                echo '<td colspan="5">';
                echo '<form method="post" class="form" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;align-items:end">';
                csrf_field();
                echo '<input type="hidden" name="action" value="update_expense">';
                echo '<input type="hidden" name="expense_id" value="'.(int)$e['id'].'">';
                echo '<div><label>Fecha</label><input type="date" name="expense_date" value="'.h($e['expense_date']).'"></div>';
                echo '<div><label>Categoría</label><input type="text" name="category" value="'.h($e['category']).'"></div>';
                echo '<div><label>Aplicar a</label><select name="expense_branch_id">';
                echo '<option value="0"'.(((int)$e['branch_id']===0)?' selected':'').'>General</option>';
                foreach ($branches as $b) {
                    $sel = ((int)$e['branch_id']===(int)$b['id'])?' selected':'';
                    echo '<option value="'.(int)$b['id'].'"'.$sel.'>'.h($b['name']).'</option>';
                }
                echo '</select></div>';
                echo '<div style="grid-column: span 3"><label>Descripción</label><input type="text" name="description" value="'.h($e['description']).'"></div>';
                echo '<div><label>Monto (ARS)</label><input type="text" name="amount_ars" class="money" inputmode="numeric" value="'.h('$ '.number_format($amt,0,',','.')).'"></div>';
                echo '<div style="grid-column: span 2">';
                echo '<label style="display:flex;gap:8px;align-items:center">';
                echo '<input type="checkbox" name="is_recurring" value="1"'.($isRec?' checked':'').'> Gasto mensual (se repite cada mes)</label>';
                echo '</div>';
                $qsCancel = $_GET; unset($qsCancel['edit_expense']);
                echo '<div style="grid-column: span 3;display:flex;gap:8px;justify-content:flex-end">';
                echo '<a class="btn" href="?'.http_build_query($qsCancel).'">Cancelar</a>';
                echo '<button class="btn primary" type="submit">Guardar cambios</button>';
                echo '</div>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
                continue;
            }

            echo '<tr>';
            echo '<td>'.h($e['expense_date']).'</td>';
            echo '<td>'.h($e['category']).($isRec ? ' <span class="badge" style="margin-left:6px">Mensual</span>' : '').'</td>';
            echo '<td>'.h($e['description']).'</td>';
            echo '<td><b>$ '.number_format($amt,0,',','.').'</b></td>';
            echo '<td style="text-align:right">';
            $qsEdit = $_GET; $qsEdit['edit_expense'] = (int)$e['id'];
            echo '<a class="btn" href="?'.http_build_query($qsEdit).'">Editar</a> ';
            echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Eliminar gasto?\')">';
            csrf_field();
            echo '<input type="hidden" name="action" value="delete_expense">';
            echo '<input type="hidden" name="expense_id" value="'.(int)$e['id'].'">';
            echo '<button class="btn" type="submit">Eliminar</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    // Comparativa mes a mes (últimos 6 meses)
    $months = array();
    $cur = new DateTime(date('Y-m-01'));
    for ($i=0;$i<6;$i++) {
        $m = $cur->format('Y-m');
        $months[] = $m;
        $cur->modify('-1 month');
    }
    $months = array_reverse($months);

    echo '<div class="card" style="grid-column: span 2">';
    echo '<div class="card-title">Comparativa mes a mes<span class="tip" title="Comparación de los últimos 6 meses. Ingresos = suma de turnos confirmados/ocupados. Gastos incluye recurrentes mensuales.">i</span></div>';
	$mLabels = array(); $mIncome = array(); $mExpenses = array(); $mMargin = array();
    echo '<canvas id="chart_fin_months" height="120" style="margin-top:10px"></canvas>';
	echo '<table class="table" style="margin-top:10px"><tr><th>Mes</th><th>Ingresos</th><th>Gastos</th><th>Margen</th></tr>';
    foreach ($months as $m) {
        list($ms, $me) = range_for_period('month', $today, $week_start, $m, $year);
        $p = array(':bid'=>$bizId, ':s'=>$ms, ':e'=>$me);
        $w = "a.business_id=:bid AND a.start_at>=:s AND a.start_at<:e AND $statusOk";
        if ($branchId>0) { $w .= " AND a.branch_id=:brid"; $p[':brid']=$branchId; }

        $inc = $pdo->prepare("SELECT ".appt_income_sql()." FROM appointments a JOIN services s ON s.id=a.service_id WHERE $w");
        $inc->execute($p);
        $inc_est_m = (int)($inc->fetchColumn() ?: 0);

	    $income_m = $inc_est_m;

        $ep = array(':bid'=>$bizId, ':s'=>$ms, ':e'=>$me);
        $ew = "business_id=:bid AND expense_date>=:s AND expense_date<:e";
        if ($branchId>0) { $ew .= " AND (branch_id=0 OR branch_id=:brid)"; $ep[':brid']=$branchId; }
        $es = $pdo->prepare("SELECT SUM(amount_ars) FROM expenses WHERE $ew AND IFNULL(is_recurring,0)=0");
        $es->execute($ep);
        $exp_m = (int)($es->fetchColumn() ?: 0);

        // recurring monthly expenses for this month
        $rec_m = 0;
        $rParamsM = array(':bid'=>$bizId, ':e'=>$me);
        $rWhereM = "business_id=:bid AND IFNULL(is_recurring,0)=1 AND expense_date<:e";
        if ($branchId>0) { $rWhereM .= " AND (branch_id=0 OR branch_id=:brid)"; $rParamsM[':brid']=$branchId; }
        $rq = $pdo->prepare("SELECT amount_ars FROM expenses WHERE $rWhereM");
        $rq->execute($rParamsM);
        while ($row = $rq->fetch(PDO::FETCH_ASSOC)) { $rec_m += (int)$row['amount_ars']; }
        $exp_m += $rec_m;

	$mar = $income_m - $exp_m;

        $mLabels[] = $m;
	$mIncome[] = $income_m;
        $mExpenses[] = $exp_m;
        $mMargin[] = $mar;

        echo '<tr>';
        echo '<td><b>'.h($m).'</b></td>';
	echo '<td>$ '.number_format($income_m,0,',','.').'</td>';
	echo '<td>$ '.number_format($exp_m,0,',','.').'</td>';
	echo '<td><b>$ '.number_format($mar,0,',','.').'</b></td>';
        echo '</tr>';
    }
    echo '</table>';
	echo '<script>window.__ANALYTICS__=window.__ANALYTICS__||{};window.__ANALYTICS__.financeMonths='.json_encode(array('labels'=>$mLabels,'income'=>$mIncome,'expenses'=>$mExpenses,'margin'=>$mMargin)).';</script>';
    echo '</div>';

    echo '</div>';
}

// Page scripts (charts + money input)
echo "\n<script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>\n";
echo "<script>\n";
echo "(function(){\n";
echo "  function formatMoneyInput(el){\n";
echo "    var raw = (el.value||'').toString();\n";
echo "    var digits = raw.replace(/[^0-9]/g,'');\n";
echo "    if (!digits) { el.value = ''; return; }\n";
echo "    var n = parseInt(digits,10) || 0;\n";
echo "    var t = new Intl.NumberFormat('es-AR').format(n);\n";
echo "    el.value = '$ ' + t;\n";
echo "  }\n";
echo "  document.addEventListener('blur', function(e){ if(e.target && e.target.classList && e.target.classList.contains('money')){ formatMoneyInput(e.target); } }, true);\n";

echo "  function mkChart(id, cfg){\n";
echo "    var c = document.getElementById(id);\n";
echo "    if(!c || !window.Chart) return;\n";
echo "    try { new Chart(c.getContext('2d'), cfg); } catch(e) { /* ignore */ }\n";
echo "  }\n";
echo "  document.addEventListener('DOMContentLoaded', function(){\n";
echo "    var A = window.__ANALYTICS__ || {};\n";
echo "    if (A.turnosPeriod) {\n";
echo "      mkChart('chart_turnos_period', {type:'line', data:{labels:A.turnosPeriod.labels, datasets:[{label:'Turnos', data:A.turnosPeriod.values, tension:0.25}]}, options:{responsive:true, plugins:{legend:{display:false}}}});\n";
echo "    }\n";
echo "    if (A.topServices) {\n";
echo "      mkChart('chart_top_services', {type:'bar', data:{labels:A.topServices.labels, datasets:[{label:'Turnos', data:A.topServices.values}]}, options:{indexAxis:'y', plugins:{legend:{display:false}}}});\n";
echo "    }\n";
echo "    if (A.topBarbers) {\n";
echo "      mkChart('chart_top_barbers', {type:'bar', data:{labels:A.topBarbers.labels, datasets:[{label:'Turnos', data:A.topBarbers.values}]}, options:{indexAxis:'y', plugins:{legend:{display:false}}}});\n";
echo "    }\n";
echo "    if (A.financeMonths) {\n";
echo "      mkChart('chart_fin_months', {type:'bar', data:{labels:A.financeMonths.labels, datasets:[{label:'Ingresos', data:A.financeMonths.income},{label:'Gastos', data:A.financeMonths.expenses},{label:'Margen', data:A.financeMonths.margin}]}, options:{responsive:true}});\n";
echo "    }\n";
echo "  });\n";
echo "})();\n";
echo "</script>\n";

page_foot();
