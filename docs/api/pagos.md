# API — Pagos y Caja (módulo 6)

Pantallas *Caja* (diapositiva 20, **ADMINISTRADOR**) y *Mis pagos* (diapositiva 16, **CLIENTE** y **EMPRESARIO**). Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Las peticiones que escriben exigen `X-Requested-With: JATSA` (ver `auth.md`). Sin sesión `401`; con otro rol `403`.

Tabla `Pago` (script SQL): `idPago`, `idEnvio`, `idSucursal` (donde se cobra), `fechaHoraPago`, `monto` (> 0), `metodoPago` (`EFECTIVO`, `TARJETA`, `BILLETERA`, `TRANSFERENCIA`), `pagadoPor` (`REMITENTE`, `DESTINATARIO`) y `estadoPago` (`REGISTRADO`, `ANULADO`). **Un pago no se borra: se anula.** No hay `DELETE` en ningún endpoint. No se emiten comprobantes (fuera del alcance, informe de BD).

El **importe** de un envío es la suma de sus bultos (no se guarda aparte). **Pagado** = suma de sus pagos `REGISTRADO`. **Saldo** = importe − pagado.

Un pago se devuelve así (`monto` con 2 decimales; `id` es el `idPago`):
```json
{ "id": 7, "fechaHora": "2026-10-10 10:04:00", "codigoSeguimiento": "EN7K4Q2M9X1B", "monto": "23.75",
  "metodoPago": "EFECTIVO", "pagadoPor": "REMITENTE", "estado": "REGISTRADO",
  "sucursal": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" } }
```

## Caja (ADMINISTRADOR)

Todo se limita a **la sucursal de la sesión** (`Usuario.idSucursal`): se toma de la sesión, **nunca del cuerpo ni de la URL**. El personal cobra los envíos de los que su sucursal es **origen o destino** (el pago en destino se registra en la agencia de destino, como indica el informe). Un envío de otra sucursal o inexistente da el **mismo** `404 envio_no_encontrado`.

### GET `/api/caja/envios/{codigo}` — el envío a cobrar
`200 { "data": { "envio": {...} } }` — lo que muestra la tarjeta «Registrar pago» de la diapositiva 20 (el código se acepta en minúsculas):
```json
{ "codigoSeguimiento": "EN4T7Q0P2LKX", "estado": "REGISTRADO",
  "origen": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" }, "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro" },
  "remitente": "María Elena Rojas Ccori", "destinatario": "Luis Alberto Flores Condori",
  "importe": "23.75", "pagado": "0.00", "saldo": "23.75", "pagadoPorSugerido": "REMITENTE", "pagos": [ ... ] }
```
`pagos`: todos los pagos de ese envío (también los anulados), el más reciente primero. `pagadoPorSugerido` es solo el valor inicial del selector: `DESTINATARIO` si el cajero está en la agencia **de destino** (y no en la de origen); si no, `REMITENTE`. Solo nombres: **no** se devuelven documentos ni teléfonos.

### POST `/api/caja/pagos` — registrar un pago
`201 { "data": { "pago": {...}, "envio": {...} } }` (el envío ya con el saldo actualizado).
```json
{ "codigoSeguimiento": "EN4T7Q0P2LKX", "monto": "23.75", "pagadoPor": "REMITENTE", "metodoPago": "EFECTIVO" }
```
| Regla | Detalle |
|---|---|
| Sucursal | La del cajero (sesión). Si el cuerpo trae `idSucursal`, `estado`, `estadoPago` o `fechaHoraPago`, se **ignoran**: el pago nace `REGISTRADO` y la fecha la pone la base |
| Monto | Soles con hasta 2 decimales (se acepta coma y espacios), mayor que 0, hasta 8 enteros. Sin coma flotante: se calcula en céntimos |
| Saldo | El monto **no puede superar el saldo**. Se admiten **pagos parciales** (el campo «Monto» de la pantalla se rellena con el saldo, pero se puede cambiar) |
| Pagado por / método | `REMITENTE` o `DESTINATARIO`; `EFECTIVO`, `TARJETA`, `BILLETERA` o `TRANSFERENCIA` (se aceptan en minúsculas) |
| Estado del envío | Un envío `ANULADO` no admite pagos. Los demás estados sí (el pago en destino ocurre con el envío en curso, en destino o ya entregado) |

Errores: `422 datos_invalidos` con **todos** los campos a la vez (`codigoSeguimiento`, `monto`, `pagadoPor`, `metodoPago`); `422` en `monto` si supera el saldo (el mensaje dice cuánto es); `404 envio_no_encontrado`; `409 envio_pagado` (saldo 0); `409 envio_anulado`.

**Transacción** (`PagoRepository::registrar`), en este orden: (1) `SELECT … FROM Envio WITH (UPDLOCK, HOLDLOCK)` bloquea el envío: dos cobros simultáneos **del mismo envío** se ponen en fila; (2) lee el importe (`SUM(Bulto.costo)`) y lo pagado (`SUM(Pago.monto)` de los `REGISTRADO`) con el envío bloqueado; (3) el servicio vuelve a aplicar sucursal, estado y saldo y puede abortar (todo se deshace); (4) `INSERT INTO Pago … OUTPUT INSERTED.idPago`. Así dos cajeros no pueden sobrepasar el importe. Cobros de envíos distintos no se bloquean entre sí.

### POST `/api/caja/pagos/{id}/anular` — anular un pago con error
`200 { "data": { "pago": {...} } }` con `estado: "ANULADO"`. «Un pago con error se anula, no se borra»: la fila queda, con su monto, y el saldo del envío se recupera (se puede volver a cobrar). Solo se anulan pagos **cobrados en la caja de la sesión**; uno de otra sucursal, inexistente o con un `id` mal formado da el **mismo** `404 pago_no_encontrado`. Ya anulado: `409 pago_anulado`. Es un `UPDATE … WHERE idPago = … AND idSucursal = … AND estadoPago = 'REGISTRADO'`: si otra caja se adelantó no cambia ninguna fila y se responde `409`. No hay motivo de anulación porque la tabla `Pago` no tiene dónde guardarlo.

### GET `/api/caja/pagos` — «Pagos cobrados aquí»
`200` con los pagos de la sucursal de la sesión (más reciente primero, **hasta 200**; `hayMas: true` si hay más) y los totales por método de los pagos `REGISTRADO` (los anulados se listan pero no suman; los totales cuentan **todos** los pagos de la sucursal, aunque la lista se acote):
```json
{ "sucursal": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" }, "hayMas": false,
  "pagos": [ { "id": 5, "fechaHora": "2026-10-14 14:05:00", "codigoSeguimiento": "ENZ5H0D3V7NJ", "monto": "14.60", "metodoPago": "TARJETA", "pagadoPor": "REMITENTE", "estado": "REGISTRADO", "sucursal": {...} } ],
  "totales": { "EFECTIVO": "32.25", "TARJETA": "14.60", "BILLETERA": "0.00", "TRANSFERENCIA": "0.00", "total": "46.85" } }
```

## Mis pagos (CLIENTE / EMPRESARIO)

### GET `/api/pagos`
Los pagos **que hizo** la persona de la sesión: los de envíos donde es **remitente y pagó el `REMITENTE`**, y los de envíos donde es **destinataria y pagó el `DESTINATARIO`**. Lo que pagó la otra parte no aparece (aunque sea un envío suyo). Más reciente primero, hasta 200 (`hayMas`). Incluye los anulados (con su estado) pero `totalPagado` suma solo los `REGISTRADO`:
```json
{ "hayMas": false, "totalPagado": "32.25", "pagos": [ { "id": 4, "fechaHora": "2026-10-13 12:10:00", "codigoSeguimiento": "ENM2P6T8R1WQ", "monto": "8.50", "metodoPago": "EFECTIVO", "pagadoPor": "DESTINATARIO", "estado": "REGISTRADO", "sucursal": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" } } ] }
```
No se devuelven documentos ni teléfonos de nadie. Una cuenta sin persona asociada: `404 cuenta_no_encontrada`.

## Decisiones y riesgos para revisar

1. **Modalidad «cobrar ahora» / «paga en destino» (decisión pendiente del módulo 5, resuelta aquí).** La modalidad **no se guarda** (el esquema no tiene dónde). Caja trata **todo envío con saldo** como pendiente de cobro y el cajero elige `pagadoPor`; el selector arranca con `pagadoPorSugerido` (según la agencia del cajero). «Guardar y cobrar ahora» de *Formalizar envío* lleva a Caja con el envío ya cargado (`/administrador/caja?envio=CÓDIGO`, solo un parámetro de la URL, sin persistir); «Guardar · paga en destino» deja el envío pendiente y se cobra cuando llegue (el cajero de destino escribe el código).
2. **Totales de caja sin filtro por fecha.** La diapositiva 20 suma todos los pagos de la agencia (los de ejemplo son de días distintos) y no muestra ningún filtro, así que no se añadió uno. Con el uso real el total crece sin límite: **propongo un filtro por día** (cierre de caja) si el propietario lo desea.
3. **Pagos parciales permitidos**, con tope en el saldo. El prototipo muestra «Saldo», lo que lo sugiere; si el propietario quiere cobro siempre completo, es una línea en `PagoService::registrar`.
4. **«Mis pagos» = lo que pagué yo** (según `pagadoPor`), no todo pago de mis envíos. Con los datos de ejemplo da el mismo resultado de la diapositiva 16 (S/ 32.25).
5. **El enlace «Envío» de cada fila de la diapositiva 16** apunta a *Historial de envíos* (módulo 9). Aquí la fila muestra «Ver envío», que lleva al rastreo como ya hace *Mis reservas*; ambos destinos llegan con el módulo 9.
6. **Un envío `ANULADO`** no admite pagos, pero nada en este módulo anula envíos (llega con otros módulos). Un pago de un envío que luego se anule se anula a mano desde Caja.
7. **Sin comprobante, sin conciliación, sin reembolso:** fuera del alcance del informe (el pago solo registra cuánto, cómo, quién y dónde).
8. **SQL sin ejecutar contra la base real.** Todo se probó con repositorios simulados (las pruebas bloquean la base). Ejecutar el módulo exige `SELECT` e `INSERT`/`UPDATE` en `Pago` y **requiere autorización expresa**; el procedimiento de validación propuesto (cuentas y filas temporales `pm6_*`, concurrencia de cobros del mismo envío, anulación, limpieza exacta con manifiesto y comparación de las 12 tablas) está descrito en el PR #7 y **no se ha ejecutado**.
