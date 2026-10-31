# Orden de implementación

Fuente: `Prototipo_navegacion_JATSA (1).pptx` (30 diapositivas). El número entre paréntesis es la diapositiva donde aparece cada pantalla; la diapositiva 1 es el mapa de navegación.

Criterio: primero la base técnica y el acceso, luego los catálogos de los que dependen los demás módulos, y después el flujo del negocio en el orden en que ocurre (reserva → ventanilla → pago → traslado → entrega). Las pantallas que solo muestran lo que ya se registró (rastreo, salidas, historial, resúmenes) van después de los módulos que producen esos datos.

| # | Módulo | Rol | Pantallas | Depende de |
|---|---|---|---|---|
| 0 | Base técnica | — | Router, respuestas JSON, conexión PDO/sqlsrv, manejo de errores, CORS; proyecto Vite, cliente `api/`, layouts y rutas por rol | — |
| 1 | Auth | Todos | Ingresar (8), Acceso con error (9), Cerrar sesión; protección de rutas por rol | 0 |
| 2 | Públicas de catálogo | Visitante | Inicio (2), Agencias (6), Cotizador (7) | 0 |
| 3 | Cuenta | Visitante / Cliente / Empresario | Crear cuenta (10), Mi cuenta (17) | 1 |
| 4 | Reservas | Cliente / Empresario | Nueva reserva (12), Reserva registrada (13), Mis reservas (15), Mi panel — parte de reservas (11) | 2, 3 |
| 5 | Ventanilla y envíos | Administrador | Ventanilla (18), Formalizar envío (19) | 4 |
| 6 | Pagos y Caja | Administrador / Cliente | Caja (20), Mis pagos (16) | 5 |
| 7 | Despacho y viajes | Administrador / Transportista | Despacho (21), Mis viajes (24), Detalle del viaje (25) | 5 |
| 8 | Llegadas y entregas | Administrador | Llegadas y entregas (22) | 7 |
| 9 | Rastreo, salidas e historial | Visitante / Cliente / Empresario | Rastreo (3), Salidas entre sucursales (4), Detalle de salida (5), Historial de envíos (14), Mi panel completo (11) | 6, 7, 8 |
| 10 | Catálogos y cuentas | Gerente | Catálogos: Sucursales, Rutas y precios, Vehículos (29); Cuentas (30) | 1 |
| 11 | Resumen y viajes y flota | Gerente | Resumen (26), Viajes y flota (28) | 6, 7, 8 |
| 12 | Reportes y plantillas | Administrador / Gerente | Reportes y plantillas (23, 27) | 11 — **bloqueado** |

## Notas

- **Rastreo y salidas (módulo 9):** el rastreo muestra recorrido y llegada estimada, y las salidas se calculan con los viajes reales de cada ruta. Por eso dependen de que ya existan envíos, viajes y entregas. Si hace falta verlos antes, se pueden probar con datos de ejemplo.
- **Módulo 2 (Agencias):** la sección «Salidas desde aquí» de la ficha de agencia necesita viajes reales y se completa en el módulo 9, junto con Salidas y Rastreo.
- **Módulo 10:** es independiente del flujo operativo. Se puede adelantar si hace falta cargar sucursales, rutas, vehículos y personal reales antes de probar los módulos 4 a 8.
- **Módulo 12 bloqueado:** exportar Excel (`.xlsx`), paquete ZIP y manual PDF requiere tecnología aún no autorizada. No se empieza hasta que el usuario decida.
- **Mi panel (11)** se hace en dos pasos: primero reservas (módulo 4) y se completa en el módulo 9 con envíos en curso y saldo por pagar.
- Cada módulo termina con: endpoints documentados en `docs/api/`, pantallas del rol funcionando contra la API y verificación manual de los permisos por rol.
