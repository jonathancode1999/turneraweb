# Turnera v1 (PHP + SQLite) — demo lista para XAMPP / Render

Incluye:
- Reserva de turno por servicio
- Horarios disponibles calculados automáticamente (slot base configurable)
- Seña desde día 1 (modo **DEMO**: botón “Pagar seña”)
- Estados: PENDIENTE_DE_PAGO / CONFIRMADO / CANCELADO / VENCIDO / COMPLETADO
- Panel Admin: turnos, servicios, horarios, bloqueos

> Importante: esta v1 está hecha para **probar rápido**. La integración real con Mercado Pago la dejamos lista para acoplar (ver más abajo).

## 1) Correr en XAMPP (Windows)

1. Copiá la carpeta `turnera_v1` a:
   - `C:\xampp\htdocs\turnera_v1`

2. Dale permisos de escritura a `data/` (en Windows normalmente ya anda). La DB se crea sola.

3. Abrí:
   - Cliente: `http://localhost/turnera_v1/public/`
   - Admin: `http://localhost/turnera_v1/admin/login.php`

4. Credenciales demo:
   - Usuario: `admin`
   - Contraseña: `1234`

## 2) Cómo está resuelto el tema “slot base” y duraciones raras

- El sistema usa un **slot base** (configurable desde Admin).
- Cada servicio tiene una duración en minutos.
- Para que el calendario sea consistente, la duración se **redondea hacia arriba** al múltiplo del slot.
  - Ej: slot 15, servicio 27 → se usa 30 min.

Esto evita el quilombo de “se me rompe el slot” y sigue dejando libertad para duraciones reales.

## 3) Pago de seña (modo demo)

Por ahora el pago es un botón “Pagar seña” (demo). Marca el turno como CONFIRMADO.

- Los turnos pendientes se vencen automáticamente pasados `pay_deadline_minutes` (configurable).
- Si vence, se libera el horario.

## 4) Integración Mercado Pago (para producción)

La estructura ya está lista con tabla `payments` y estados.

Cuando lo quieras real:
1. Cambiás `payment_provider` en `includes/config.php` a `mercadopago`.
2. En vez de `public/pay.php` (demo), creamos:
   - creación de preferencia (Checkout)
   - webhook / callback que marque `payments.status=paid` y `appointments.status=CONFIRMADO`

## 5) Deploy en Render (idea)

Render puede correr PHP con Docker.
- Crear `Dockerfile` + `render.yaml` (lo armamos cuando quieras).
- SQLite funciona para demo y para pocos clientes; en producción multi-cliente conviene MySQL.

## 6) Estructura de carpetas

- `public/` sitio cliente
- `admin/` panel administración
- `includes/` helpers (db, auth, disponibilidad)
- `data/` SQLite (se crea `app.sqlite`)
- `cron/expire_pending.php` script para vencer pendientes

---

Si querés, en el próximo paso hacemos:
 - Multi profesional + disponibilidad por profesional
- Políticas de cancelación con reglas (8h, etc.) y reprogramación
- Mercado Pago real con notificación por WhatsApp
