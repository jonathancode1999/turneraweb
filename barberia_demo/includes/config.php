<?php
// Basic config for Turnera (multi-rubro: Peluquería / Uñas / Estética)
// Copy this project into XAMPP htdocs (e.g., C:\xampp\htdocs\turnera) and open http://localhost/turnera/public/

return [
    'app_name' => 'Turnera',

    // Single-business demo (multi-tenant ready). Keep as 1 for now.
    'business_id' => 1,

    // SQLite DB file (ensure /data is writable by PHP)
    'sqlite_path' => __DIR__ . '/../data/app.sqlite',

    // Timezone for display and parsing
    'timezone' => 'America/Argentina/Buenos_Aires',

    // Slot base in minutes (recommended 15)
    'slot_minutes' => 15,

    // Pending deadline (minutes). Pending bookings older than this become VENCIDO.
    // Nota: aunque antes se llamaba "pay_deadline", ahora se usa como vencimiento de pendientes.
];
