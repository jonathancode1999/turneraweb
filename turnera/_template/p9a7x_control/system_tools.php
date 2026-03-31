<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/admin_nav.php';

admin_require_login();
admin_require_permission('system');

page_head('Sistema', 'admin');
admin_nav('system');

echo '<div class="cards-grid">';
echo '<a class="card card-link" href="users.php">';
echo '<div class="card-title">Usuarios y permisos</div>';
echo '<div class="card-sub">Crear usuarios, roles y acceso por sucursal</div>';
echo '</a>';

echo '<a class="card card-link" href="backups.php">';
echo '<div class="card-title">Backups</div>';
echo '<div class="card-sub">Copias de seguridad y descargas</div>';
echo '</a>';

echo '</div>';

page_foot();
