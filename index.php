<?php
$systemPath = 'turnera/p9a7x_control/login.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Turnera | Sistema de turnos online</title>
  <style>
    :root{
      --bg:#f5f7fb;
      --panel:#ffffff;
      --panel-2:#f8fbff;
      --line:#dbe3f0;
      --text:#0f172a;
      --muted:#475569;
      --primary:#2563eb;
      --primary-2:#1d4ed8;
      --ok:#16a34a;
      --shadow:0 18px 50px rgba(15,23,42,.10);
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      color:var(--text);
      background:
        radial-gradient(circle at top left, rgba(37,99,235,.12), transparent 28%),
        radial-gradient(circle at top right, rgba(22,163,74,.10), transparent 22%),
        linear-gradient(180deg, #f8fbff 0%, #f5f7fb 100%);
      min-height:100vh;
    }
    a{color:inherit;text-decoration:none}
    .wrap{max-width:1180px;margin:0 auto;padding:32px 20px 56px}
    .topbar{
      display:flex;justify-content:space-between;align-items:center;gap:16px;
      margin-bottom:36px;
    }
    .brand{font-weight:800;font-size:1.2rem;letter-spacing:.02em}
    .badge{
      display:inline-flex;align-items:center;gap:8px;
      background:#ecfdf3;color:#166534;border:1px solid #bbf7d0;
      border-radius:999px;padding:8px 14px;font-size:.92rem;
    }
    .hero{
      display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:stretch;
    }
    .card{
      background:linear-gradient(180deg, #ffffff, #f8fbff);
      border:1px solid var(--line);
      border-radius:28px;
      box-shadow:var(--shadow);
    }
    .hero-copy{padding:42px}
    .eyebrow{
      color:#2563eb;font-weight:700;letter-spacing:.08em;text-transform:uppercase;font-size:.76rem;
      margin-bottom:14px;
    }
    h1{
      margin:0 0 16px;
      font-size:clamp(2.2rem,5vw,4.4rem);
      line-height:1;
    }
    .lead{
      color:var(--muted);
      font-size:1.08rem;
      line-height:1.7;
      max-width:60ch;
      margin:0 0 28px;
    }
    .actions{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:28px}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      min-height:48px;padding:0 20px;border-radius:14px;font-weight:700;border:1px solid transparent;
      transition:.2s ease;
    }
    .btn.primary{background:linear-gradient(180deg,var(--primary),var(--primary-2));color:white}
    .btn.secondary{border-color:var(--line);background:#fff}
    .btn:hover{transform:translateY(-1px)}
    .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
    .stat{
      padding:16px 18px;border-radius:18px;background:#fff;border:1px solid var(--line);
    }
    .stat strong{display:block;font-size:1.45rem;margin-bottom:4px}
    .stat span{color:var(--muted);font-size:.95rem}
    .hero-panel{padding:24px}
    .mock{
      background:linear-gradient(180deg,var(--panel),var(--panel-2));
      border-radius:24px;border:1px solid var(--line);padding:22px;height:100%;
    }
    .mock h2{margin:0 0 8px;font-size:1.2rem}
    .mock p{margin:0;color:var(--muted);line-height:1.6}
    .list{display:grid;gap:12px;margin-top:22px}
    .item{
      display:flex;gap:12px;align-items:flex-start;
      padding:14px 16px;border-radius:16px;background:#fff;border:1px solid var(--line);
    }
    .dot{
      width:12px;height:12px;border-radius:50%;
      background:linear-gradient(180deg,var(--ok),#17b978);margin-top:5px;flex:0 0 auto;
      box-shadow:0 0 0 6px rgba(61,220,151,.12);
    }
    .grid{
      display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:28px;
    }
    .feature{padding:24px}
    .feature h3{margin:0 0 10px;font-size:1.1rem}
    .feature p{margin:0;color:var(--muted);line-height:1.65}
    .footer-note{
      margin-top:30px;padding:20px 22px;border-radius:20px;
      border:1px dashed #cbd5e1;color:var(--muted);background:#fff;
    }
    code{
      padding:3px 8px;border-radius:10px;background:#eff6ff;color:#1d4ed8;
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;
    }
    @media (max-width: 920px){
      .hero,.grid,.stats{grid-template-columns:1fr}
      .hero-copy,.hero-panel{padding:24px}
      .topbar{flex-direction:column;align-items:flex-start}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <div class="brand">Turnera</div>
      <div class="badge">Sistema de turnos online para negocios y sucursales</div>
    </div>

    <section class="hero">
      <article class="card hero-copy">
        <div class="eyebrow">Landing principal</div>
        <h1>Organizá reservas, horarios y clientes desde un solo lugar.</h1>
        <p class="lead">
          Turnera es una solución para gestionar turnos online, profesionales, servicios, sucursales,
          recordatorios y administración diaria del negocio. Desde acá podés entender para qué sirve
          el sistema y entrar al panel para administrarlo.
        </p>

        <div class="actions">
          <a class="btn primary" href="<?php echo htmlspecialchars($systemPath, ENT_QUOTES, 'UTF-8'); ?>">Entrar al Super Admin</a>
          <a class="btn secondary" href="#como-funciona">Cómo funciona</a>
        </div>

        <div class="stats">
          <div class="stat">
            <strong>24/7</strong>
            <span>Reservas online disponibles para tus clientes.</span>
          </div>
          <div class="stat">
            <strong>Multi-sucursal</strong>
            <span>Soporte para negocios con varias sedes y personal.</span>
          </div>
          <div class="stat">
            <strong>MySQL</strong>
            <span>Base preparada para trabajar con una única BD compartida.</span>
          </div>
        </div>
      </article>

      <aside class="card hero-panel">
        <div class="mock">
          <h2>¿Qué incluye la turnera?</h2>
          <p>Una base lista para gestionar operaciones, automatizar recordatorios y delegar la reserva online.</p>

          <div class="list">
            <div class="item">
              <div class="dot"></div>
              <div>
                <strong>Turnos por servicio y profesional</strong>
                <p>Duraciones, disponibilidad, bloqueos manuales y control por sucursal.</p>
              </div>
            </div>
            <div class="item">
              <div class="dot"></div>
              <div>
                <strong>Panel administrativo</strong>
                <p>Agenda, usuarios, horarios, clientes, backups y configuraciones generales.</p>
              </div>
            </div>
            <div class="item">
              <div class="dot"></div>
              <div>
                <strong>Base para crecer</strong>
                <p>WhatsApp, recordatorios, pagos y estructura pensada para varios clientes.</p>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </section>

    <section id="como-funciona" class="grid">
      <article class="card feature">
        <h3>1. Configurás el negocio</h3>
        <p>Definís servicios, profesionales, horarios, sucursales, branding y reglas del negocio desde el panel.</p>
      </article>
      <article class="card feature">
        <h3>2. Tus clientes reservan</h3>
        <p>El sistema ofrece horarios disponibles y evita superposiciones según la agenda real.</p>
      </article>
      <article class="card feature">
        <h3>3. Administrás todo</h3>
        <p>Seguís la agenda diaria, reasignás turnos, bloqueás huecos y mantenés la operación ordenada.</p>
      </article>
    </section>

    <div class="footer-note">
      <strong>Acceso al sistema:</strong> el panel quedó dentro de la carpeta <code>/turnera</code>.
      Si querés entrar directo al administrador, usá <code>/turnera/p9a7x_control/login.php</code>.
    </div>
  </div>
</body>
</html>
