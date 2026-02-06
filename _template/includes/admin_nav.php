<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branches.php';
require_once __DIR__ . '/utils.php';

function admin_nav(string $active): void {
        $items = [
        'dashboard' => ['Dashboard', 'dashboard.php', ''],
        'calendar' => ['Agenda', 'calendar.php', ''],
        'appointments' => ['Turnos', 'appointments.php', ''],
        'barbers' => ['Profesionales', 'barbers.php', ''],
        'services' => ['Servicios', 'services.php', ''],
        'branches' => ['Sucursales', 'branches.php', ''],
        'hours' => ['Horarios', 'hours.php', ''],
        'blocks' => ['Bloqueos', 'blocks.php', ''],
        'analytics' => ['Analytics', 'analytics.php', ''],
        'settings' => ['Sucursal', 'settings.php', 'settings'],
    ];

    // Desktop / tablet top nav
    echo '<nav class="nav nav-top">';
    $bid = admin_current_branch_id();
    if ($bid > 0) {
        $b = branch_get($bid);
        if ($b) {
            echo '<span class="pill pill-branch">' . h($b['name']) . '</span>';
        }
    }
    foreach ($items as $key => $it) {
        $perm = $it[2] ?? '';
        if ($perm !== '' && !admin_can($perm)) continue;
        $cls = $key === $active ? 'navlink active' : 'navlink';
        echo '<a class="' . $cls . '" href="' . h($it[1]) . '">' . h($it[0]) . '</a>';
    }
    echo '<div class="navspacer"></div>';
    echo '<a class="navlink" href="logout.php">Salir</a>';
    echo '</nav>';

    // Mobile bottom bar (app-like)
    // Primary shortcuts
    $short = [
        'dashboard' => ['Inicio', 'dashboard.php'],
        'calendar' => ['Agenda', 'calendar.php'],
        'barbers' => ['Profes.', 'barbers.php'],
        'services' => ['Serv.', 'services.php'],
    ];

    echo '<nav class="bottom-nav" aria-label="Navegación">';
    foreach ($short as $key => $it) {
        $isActive = ($key === $active);
        $cls = $isActive ? 'bnav-item active' : 'bnav-item';
                $icon = '•';
        if ($key==='dashboard') $icon='🏠';
        elseif ($key==='appointments' || $key==='calendar') $icon='📅';
        elseif ($key==='barbers') $icon='👤';
        elseif ($key==='services') $icon='✂️';
        echo '<a class="' . $cls . '" href="' . h($it[1]) . '"><span class="bnav-icon" aria-hidden="true">' . $icon . '</span><span class="bnav-label">' . h($it[0]) . '</span></a>';
    }
    echo '<button type="button" class="bnav-item" id="btnMore" aria-haspopup="dialog" aria-controls="mobileMenu"><span class="bnav-icon" aria-hidden="true">≡</span><span class="bnav-label">Más</span></button>';
    echo '</nav>';

    // Mobile sheet menu (all sections)
    echo '<div class="mobile-sheet" id="mobileMenu" role="dialog" aria-modal="true" aria-hidden="true">';
    echo '<div class="sheet-backdrop" data-close="1"></div>';
    echo '<div class="sheet-panel">';
    echo '<div class="sheet-head">';
    echo '<div class="sheet-title">Menú</div>';
    if ($bid > 0) {
        $b = branch_get($bid);
        if ($b) {
            echo '<div class="sheet-sub">Sucursal: <b>' . h($b['name']) . '</b></div>';
        }
    }
    echo '<button type="button" class="sheet-close" data-close="1" aria-label="Cerrar">×</button>';
    echo '</div>';
    echo '<div class="sheet-links">';
    foreach ($items as $key => $it) {
        $perm = $it[2] ?? '';
        if ($perm !== '' && !admin_can($perm)) continue;
        $cls = $key === $active ? 'sheet-link active' : 'sheet-link';
        echo '<a class="' . $cls . '" href="' . h($it[1]) . '">' . h($it[0]) . '</a>';
    }
    echo '<a class="sheet-link danger" href="logout.php">Salir</a>';
    echo '</div></div></div>';
}

