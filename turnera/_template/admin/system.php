<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/admin_nav.php';
admin_require_login();

page_head('Sistema', 'admin');
admin_nav('system');

echo '<div class="cards-grid">';

// Nota: Analytics se muestra como tarjeta (cuadro) más abajo.
echo '<div class="card"><div class="card-title">Usuarios y permisos</div><div class="card-sub">Crear usuarios y limitar por sucursal</div><a class="btn" href="users.php" style="margin-top:10px;display:inline-block">Administrar</a></div>';
echo '<div class="card"><div class="card-title">Backups</div><div class="card-sub">Copias de seguridad diarias de la base</div><a class="btn" href="backups.php" style="margin-top:10px;display:inline-block">Ver backups</a></div>';
echo '<div class="card"><div class="card-title">Analytics</div><div class="card-sub">Turnos y servicios más populares</div><a class="btn" href="analytics.php" style="margin-top:10px;display:inline-block">Abrir</a></div>';
echo '</div>';

page_foot();
