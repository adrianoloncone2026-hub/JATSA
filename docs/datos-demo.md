# Datos de demostración y propuesta de Power BI

Carga controlada de información **ficticia** en `JATSA_Encomiendas` para probar todas las pantallas y construir informes. Script: `backend/bin/cargar-datos-demo.php` (+ `backend/bin/datos-demo/generador.php`).

## Cómo se usa
| Orden | Qué hace |
|---|---|
| `php backend/bin/cargar-datos-demo.php` | **Simulación**: verifica el destino, lee lo existente, genera el conjunto y lo valida en memoria. No escribe. |
| `… --ejecutar` | Respaldo verificado → carga en **una transacción** → verificación → archivo local de credenciales. |
| `… --verificar` | Solo lectura: 23 comprobaciones de integridad, cronología, importes y estados + huellas de lo anterior. |
Opciones: `--servidor=localhost --puerto= --salida=<carpeta fuera del repositorio>`.

## Garantías de seguridad
- Solo `INSERT`: nada de UPDATE/DELETE/DROP/TRUNCATE ni cambios de esquema; no toca `docs/JATSA_Envios-SQLQuery1 (1).sql` ni `backend/.env` (autenticación integrada de Windows).
- Destino verificado (`DB_NAME()`, cuenta Windows, autenticación, base de lectura/escritura, reloj de SQL Server ≈ PHP). Se niega si algo no coincide.
- **Respaldo** previo `COPY_ONLY` con `CHECKSUM` (en la carpeta de respaldos de la instancia) comprobado con `RESTORE VERIFYONLY` y `HEADERONLY`; sin respaldo válido no escribe.
- **Repetible**: la marca es la cuenta `gerente.demo1`; si existe, no hace nada (no duplica). Cualquier error revierte toda la transacción.
- **Intacto lo anterior**: se guarda una huella (filas + SHA-256) de las 12 tablas antes y se compara dentro de la transacción y después; la carga confirma solo si son idénticas.
- Credenciales: solo en `Documents\JATSA-demo\credenciales-demo.txt` (fuera del repositorio) junto al manifiesto con los máximos de id previos y la ruta del respaldo. Contraseñas aleatorias (`random_int`), guardadas con `password_hash`.
- Datos ficticios: DNI `9xxxxxxx`, RUC `20999xxxxxx`, correos `@example.com`, teléfonos `99xxxxxxx`, nombres inventados.
- **Revertir**: no hay script de borrado (no se autorizó). La vuelta atrás es restaurar el respaldo `JATSA_Encomiendas_antes-demo_*.bak` (operación del propietario); los ids previos están en el manifiesto.

## Qué se cargó (carga del 2026-11-04)
| Tabla | Antes | Nuevas | Total |
|---|---|---|---|
| Persona | 5 | 181 | 186 |
| Sucursal | 5 | 2 (Cusco, Juliaca) | 7 |
| Telefono_Sucursal | 5 | 7 | 12 |
| Usuario | 5 | 27 | 32 |
| Ruta | 5 | 128 | 133 |
| Vehiculo | 5 | 6 | 11 |
| Reserva | 5 | 658 | 663 |
| Envio | 5 | 564 | 569 |
| Bulto | 6 | 2 824 | 2 830 |
| Viaje | 5 | 549 | 554 |
| Envio_Viaje | 5 | 712 | 717 |
| Pago | 5 | 688 | 693 |
Periodo: noviembre 2025 → 4 de noviembre de 2026 (12 meses completos + el mes actual). Actividad estacional (pico en diciembre y julio), seis agencias con volúmenes distintos, red con transbordos (LIM–AQP–MOQ–ILO, AQP–CUS, AQP–JUL). **Tarifas**: filas históricas (2025-10-01 → 2026-08-31) y las vigentes desde 2026-09-01 (las 5 anteriores no se tocaron); cada bulto usa la tarifa vigente el día del registro. Un viaje **PROGRAMADO** es un viaje sin `fechaHoraSalida` (el esquema no guarda una salida programada); EN_RUTA = con salida y sin llegada.

## Escenarios por rol (cuentas en el archivo local)
| Rol | Cuentas | Qué encuentra |
|---|---|---|
| GERENTE | `gerente.demo1`, `gerente.demo2` | Resumen y Viajes y flota con 13 meses de historia, variaciones mes a mes; Catálogos con 7 sucursales, 11 vehículos y 133 tarifas; Cuentas (32). |
| ADMINISTRADOR | `admin.lim/aqp/moq/ilo/cus/jul` | Ventanilla (reservas pendientes), Caja (cientos de pagos), Despacho (viajes programados, en ruta y terminados con carga), Llegadas y entregas (envíos listos para entregar, p. ej. Moquegua). |
| TRANSPORTISTA | `trans.lim1` … `trans.jul1` (10) | Mis viajes con decenas de viajes terminados y programados; detalle con carga. |
| CLIENTE | `cliente.demo1` … `6` | Reservas (pendientes, formalizadas, canceladas, vencidas), envíos en curso, saldo pendiente y pagos. |
| EMPRESARIO | `empresario.demo1` … `3` | Decenas de envíos con muchos bultos CARGA, pagos por transferencia, saldos. |
Casos incluidos: reservas PENDIENTE (<72 h), VENCIDA (>72 h) y CANCELADA; envíos REGISTRADO, EN_TRANSITO, EN_DESTINO, ENTREGADO (agencia y domicilio), DEVUELTO y ANULADO; pagos completos, en dos cobros, parciales, contra entrega (pagados por el destinatario en destino), pendientes y ANULADOS (cobros mal registrados y repetidos); viajes consolidando varios envíos; envíos con transbordo (≈ 28 %); entregas lentas y envíos retrasados.

## Hallazgos y límites
- Los datos de ejemplo previos tienen dos viajes «en ruta» desde octubre (`VJ2026101101`, `VJ2026101402`) y un transportista (`pmamani`) con dos a la vez; no se tocaron, y el generador evita usar esos vehículos después de su salida.
- La utilización media de la flota es baja (13–30 %): hay vehículos grandes y poco volumen por viaje; es coherente con los datos, no un error.
- Los ingresos mensuales varían mucho porque unos pocos envíos de carga pesada cuestan miles de soles (p. ej. diciembre ≫ noviembre).
- `msdb` no registró el respaldo en el historial; el archivo se verificó directamente con `RESTORE VERIFYONLY`/`HEADERONLY`.
- Verificación de los endpoints del módulo 11 hecha con la rama `modulo-11-resumen-flota` (PR #12).

## Propuesta de informe en Power BI
**Origen**: SQL Server `JATSA_Encomiendas` (importación). Tablas: `Envio`, `Bulto`, `Pago`, `Reserva`, `Viaje`, `Envio_Viaje`, `Ruta`, `Vehiculo`, `Sucursal`, `Usuario`+`Persona` y un `Calendario` (DAX `CALENDARAUTO`).
**Relaciones** (1→*): Calendario→Envio.fechaHoraRegistro (fecha), →Pago.fechaHoraPago, →Viaje.fechaHoraSalida, →Reserva.fechaHoraReserva; Reserva→Envio (1:1); Envio→Bulto; Envio→Pago; Envio→Envio_Viaje←Viaje; Ruta→Bulto; Vehiculo→Viaje; Usuario(Persona)→Viaje (transportista). `Sucursal` con dos roles: origen/destino de `Reserva` (la activa) y de `Viaje`; `Pago.idSucursal` = agencia de cobro (relación inactiva con USERELATIONSHIP).
**Columnas calculadas útiles**: `Envio[Costo]` = suma de `Bulto.costo`; `Envio[Cobrado]` = suma de pagos REGISTRADO; `Envio[Saldo]` = Costo − Cobrado; `Envio[EstadoCobro]` = Pendiente / Parcial / Pagado / Anulado; `Viaje[Estado]` (sin salida = Programado, sin llegada = En ruta); `Viaje[HorasReales]`, `Viaje[HorasEstimadas]` (`Ruta.tiempoHoras` de la ruta origen→destino); `Envio[DiasEntrega]` = entrega − registro.
**Medidas**: Envíos, Bultos, Peso (kg), Ingresos facturados (Σ costo), Cobrado, Saldo por cobrar, % pagos anulados, Ticket medio, Variación vs mes anterior y vs año anterior (`DATEADD`), Viajes terminados, Kg transportados, Uso de capacidad = kg / capacidad del vehículo, % envíos con transbordo (envíos con más de un `Envio_Viaje`), Tiempo medio de entrega, % entregas en ≤ 3 días, % viajes retrasados (`HorasReales` > `HorasEstimadas` × 1,15), Envíos en destino sin recoger > 7 días.
**Páginas**
1. *Resumen ejecutivo*: tarjetas (envíos, ingresos, cobrado, saldo, viajes), tendencia mensual y comparación con el mes/año anterior.
2. *Envíos*: por mes, estado, sucursal de origen/destino y tipo de entrega; matriz origen × destino.
3. *Ingresos y cobranza*: ingresos vs cobrado por mes y agencia de cobro, método de pago, quién paga (remitente/destinatario), pendientes, parciales y anulados, antigüedad del saldo.
4. *Rutas y bultos*: ranking de rutas, mezcla SOBRE/PAQUETE/CARGA, peso y costo por bulto, efecto del cambio de tarifa de septiembre de 2026.
5. *Flota y viajes*: viajes por estado, kg por vehículo, uso de capacidad, horas reales vs estimadas, transbordos.
6. *Transportistas*: viajes, kg, puntualidad y distancia por transportista y sucursal.
7. *Entregas y retrasos*: tiempo registro→entrega, llegada→entrega, devueltos, envíos detenidos en destino, domicilio vs agencia.
8. *Reservas*: embudo (pendiente → formalizada / cancelada / vencida) por canal.
**Recomendaciones**: importar solo las 12 tablas (sin `hashContrasena` ni documentos personales); filtros de página por sucursal y periodo; crear vistas de solo lectura en SQL (con autorización) si se quiere evitar columnas sensibles; fijar el calendario al rango 2025-10 → 2026-12.
