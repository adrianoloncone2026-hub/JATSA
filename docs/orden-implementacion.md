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

- **Módulo 9 (Rastreo, salidas e historial):** Rastreo público (sin nombres, documentos ni teléfonos), Salidas y Detalle de salida (horario habitual calculado con los últimos viajes), Historial de envíos y Mi panel completo (envíos en curso, saldo por pagar, por recoger); también «Salidas desde aquí» en Agencias y «Próxima salida habitual» en Nueva reserva. Todo es de solo lectura. «Exportar lista» queda deshabilitado (módulo 12) y la «Actividad reciente» de Mi cuenta no se incluye. Contrato y decisiones en `docs/api/rastreo-historial.md`.
- **Rastreo y salidas (módulo 9, nota previa):** el rastreo muestra recorrido y llegada estimada, y las salidas se calculan con los viajes reales de cada ruta. Por eso dependen de que ya existan envíos, viajes y entregas. Si hace falta verlos antes, se pueden probar con datos de ejemplo.
- **Módulo 2 (Agencias):** la sección «Salidas desde aquí» de la ficha de agencia necesita viajes reales y se completa en el módulo 9, junto con Salidas y Rastreo.
- **Módulo 8 (Llegadas y entregas):** «Llegadas y entregas» lista los viajes en camino a la agencia y los envíos `EN_DESTINO` listos para entregar; la entrega exige que el documento presentado coincida con el del destinatario registrado y que no haya saldo (se cobra en Caja), y guarda la fecha y hora de entrega (`ENTREGADO`); «Marcar devuelto» cierra el envío como `DEVUELTO`. «Cambiar destino (solo REGISTRADO)» queda deshabilitado (fuera de alcance). Contrato y decisiones en `docs/api/entregas.md`. El rastreo público y el historial siguen en el módulo 9.
- **Módulo 7 (Despacho y viajes):** Despacho programa viajes, carga y quita envíos (con tope de capacidad del vehículo) y lista los que esperan en la agencia; el transportista ve sus viajes y marca salida y llegada. El estado del viaje se deduce de las fechas; al salir, los envíos pasan a `EN_TRANSITO` y al llegar a su destino final, a `EN_DESTINO` (los de transbordo esperan en la agencia intermedia). La entrega (`ENTREGADO`) es del módulo 8. Contrato y decisiones en `docs/api/despacho-viajes.md`. «Próxima salida habitual» de «Nueva reserva» sigue pendiente del módulo 9.
- **Módulo 6 (Pagos y Caja):** Caja registra pagos (también parciales, nunca por encima del saldo), anula los que tienen error (un pago no se borra) y suma lo cobrado por método; «Mis pagos» muestra los pagos que hizo el cliente o empresario. La modalidad «cobrar ahora» / «paga en destino» **no se guarda**: Caja cobra todo envío con saldo y el cajero elige quién paga (decisión pendiente del módulo 5, resuelta). Contrato y decisiones para revisar en `docs/api/pagos.md`. El enlace «Envío» de cada fila de «Mis pagos» apunta a *Historial de envíos* y se completa en el módulo 9; «Saldo por pagar» de «Mi panel» también llega en el 9.
- **Módulo 5 (Ventanilla y envíos):** el cobro (`Pago`) es del módulo 6: «Guardar y cobrar ahora» y «Guardar · paga en destino» guardan el envío igual y «cobrar» lleva a Caja. El código de seguimiento se genera al guardar. «Cliente sin reserva» es una pantalla propia. Detalle y decisiones en `docs/api/envios.md`.
- **Módulo 4 (Reservas):** «Mi panel» queda con la parte de reservas; «Envíos en curso», «Saldo por pagar», «Por recoger» y «Próxima salida habitual» de «Nueva reserva» llegan con los módulos 6 y 9. Vencimiento a las 72 h decidido por el propietario: efecto inmediato en la API y comando `backend/bin/vencer-reservas.php` (no programado; ver `docs/api/reservas.md`). Las rutas sin tarifa vigente no admiten reservas.
- **Módulo 3 (Mi cuenta):** la sección «Actividad reciente» de la diapositiva 17 necesita reservas y envíos y se completa en los módulos 4 y 9.
- **Módulo 10:** es independiente del flujo operativo. Se puede adelantar si hace falta cargar sucursales, rutas, vehículos y personal reales antes de probar los módulos 4 a 8.
- **Módulo 12 bloqueado:** exportar Excel (`.xlsx`), paquete ZIP y manual PDF requiere tecnología aún no autorizada. No se empieza hasta que el usuario decida.
- **Mi panel (11)** se hace en dos pasos: primero reservas (módulo 4) y se completa en el módulo 9 con envíos en curso y saldo por pagar.
- Cada módulo termina con: endpoints documentados en `docs/api/`, pantallas del rol funcionando contra la API y verificación manual de los permisos por rol.
