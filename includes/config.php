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

    // Payment deadline (minutes). Pending bookings older than this become VENCIDO.
    'pay_deadline_minutes' => 15,

    // Payment provider: 'demo' or 'mercadopago' (v1 ships with 'demo')
    'payment_provider' => 'demo',

    // Mercado Pago (optional; not used in demo provider)
    'mp' => [
        'access_token' => '',
        'public_key' => '',
        'webhook_secret' => '',
    ],

    // Admin seed credentials (created on first run)
    'seed_admin' => [
        'username' => 'admin',
        'password' => '1234',
    ],
];
