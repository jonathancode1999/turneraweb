<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/branches.php';
require_once __DIR__ . '/utils.php';

function admin_nav(string $active): void {
    $items = [
        'branches' => ['Sucursales', 'branches.php', ''],
        'dashboard' => ['Dashboard', 'dashboard.php', ''],
        'onboarding' => ['Primeros pasos', 'onboarding.php', ''],
        'settings' => ['Configuración', 'settings.php', 'settings'],
'appointments' => ['Turnos', 'appointments.php', 'appointments'],
        'barbers' => ['Profesionales', 'barbers.php', 'barbers'],
        'services' => ['Servicios', 'services.php', 'services'],
        'hours' => ['Horarios', 'hours.php', 'hours'],
        'blocks' => ['Bloqueos', 'blocks.php', ''], 
        'system' => ['Sistema', 'system.php', 'system'],
    ];
    echo '<nav class="nav">';
    // Current branch pill
    $bid = admin_current_branch_id();
    if ($bid > 0) {
        $b = branch_get($bid);
        if ($b) {
            echo '<span class="pill">' . h($b['name']) . '</span>';
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
}
