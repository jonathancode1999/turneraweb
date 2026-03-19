# Turnera v1 (PHP + MySQL) — demo lista para XAMPP / Render

Incluye:
- Reserva de turno por servicio
- Horarios disponibles calculados automáticamente (slot base configurable)
- Panel Admin: turnos, servicios, horarios, bloqueos

> Importante: esta v1 está hecha para **probar rápido**. La integración real con Mercado Pago la dejamos lista para acoplar (ver más abajo).

## 1) Correr en XAMPP (Windows)

1. Copiá la carpeta `turnera_v1` a:
   - `C:\xampp\htdocs\turnera_v1`

2. Configurá una base MySQL y cargá las credenciales vía variables de entorno (`TURNERA_DB_HOST`, `TURNERA_DB_PORT`, `TURNERA_DB_NAME`, `TURNERA_DB_USER`, `TURNERA_DB_PASS`). `includes/config.php` ya trae ese patrón con aliases legacy para compatibilidad.

3. Abrí:
   - Cliente: `http://localhost/turnera_v1/public/`
   - Admin: `http://localhost/turnera_v1/admin/login.php`

4. Credenciales demo:
   - Usuario: `admin`

## 2) Cómo está resuelto el tema “slot base” y duraciones raras

- El sistema usa un **slot base** (configurable desde Admin).
- Cada servicio tiene una duración en minutos.
- Para que el calendario sea consistente, la duración se **redondea hacia arriba** al múltiplo del slot.
  - Ej: slot 15, servicio 27 → se usa 30 min.

Esto evita el quilombo de “se me rompe el slot” y sigue dejando libertad para duraciones reales.

- Si vence, se libera el horario.

## 4) Integración Mercado Pago (para producción)

Cuando lo quieras real:
2. En vez de `public/pay.php` (demo), creamos:
   - creación de preferencia (Checkout)

## 5) Deploy en Render (idea)

Render puede correr PHP con Docker.
- Crear `Dockerfile` + `render.yaml` (lo armamos cuando quieras).
- La versión actual usa MySQL como base única para demo y producción.

## 6) Estructura de carpetas

- `public/` sitio cliente
- `admin/` panel administración
- `includes/` helpers (db, auth, disponibilidad)
- `data/` backups y archivos generados
- `cron/expire_pending.php` script para vencer pendientes

---

Si querés, en el próximo paso hacemos:
 - Multi profesional + disponibilidad por profesional
- Políticas de cancelación con reglas (8h, etc.) y reprogramación
- Mercado Pago real con notificación por WhatsApp
