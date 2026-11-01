# API — Llegadas y entregas (módulo 8)

Pantalla *Llegadas y entregas* (diapositiva 22) del **ADMINISTRADOR** de la agencia de **destino**, y el cierre del proceso «Traslado y entrega» del informe («el proceso termina cuando una persona identificada lo recibe»). Todos los endpoints exigen sesión con rol `ADMINISTRADOR` (`RequiereRol`): sin sesión `401`, otro rol `403`. Todo se limita a **la sucursal de la sesión** (`Usuario.idSucursal`): se toma de la sesión, **nunca del cuerpo ni de la URL**. Un envío cuyo destino es otra agencia, o inexistente, da el **mismo** `404 envio_no_encontrado`. Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Las peticiones que escriben exigen `X-Requested-With: JATSA` (ver `auth.md`).

Tabla `Envio` (script SQL): `estadoEnvio` (`REGISTRADO`, `EN_TRANSITO`, `EN_DESTINO`, `ENTREGADO`, `DEVUELTO`, `ANULADO`) y `fechaHoraEntrega`. `CK_ENVIO_ENTREGA` exige que `fechaHoraEntrega` tenga valor **solo** cuando el estado es `ENTREGADO`: por eso la entrega se guarda en **un único `UPDATE`** y devolver un envío no toca la fecha. El estado `EN_DESTINO` lo deja el módulo 7 al marcar la llegada del viaje a su destino final.

El **documento del destinatario** (tipo, número y nombre) se devuelve **solo al personal de la agencia de destino**, para compararlo con el que presenta quien recoge (la diapositiva lo muestra: «DNI 71889345»). No se devuelven teléfonos. El rastreo público (módulo 9) **no** expone documentos.

## GET `/api/entregas/pendientes`
`200 { "data": { "sucursal", "enCamino": [...], "listos": [...], "hayMas": false } }`.
- `enCamino` — «En camino hacia aquí»: viajes `EN_RUTA` cuyo destino es la agencia, el que salió antes primero: `{ codigo, origen, destino, fechaHoraSalida, llegadaEstimada, cantidadEnvios }`. `llegadaEstimada` = salida + `Ruta.tiempoHoras` (máximo de las tarifas vigentes de la ruta directa) o `null` si no hay ruta directa. Es solo informativo: la llegada la marca el transportista (módulo 7).
- `listos` — «Listos para entregar»: envíos `EN_DESTINO` cuya reserva tiene como destino la agencia, el más antiguo primero (hasta 200): `{ codigoSeguimiento, destinatario (solo el nombre), tipoEntrega, bultos, pesoKg, importe, pagado, saldo }`. **Sin documentos**.

## GET `/api/entregas/envios/{codigo}` — el envío a atender
`200 { "data": { "envio": {...} } }`, en **cualquier estado** (para consultar uno ya entregado). El código se acepta en minúsculas.
```json
{ "codigoSeguimiento": "ENM2P6T8R1WQ", "estado": "EN_DESTINO", "tipoEntrega": "AGENCIA", "direccionEntrega": null, "fechaHoraEntrega": null,
  "origen": { "codigo": "ILO001", "nombre": "Agencia Ilo" }, "destino": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" },
  "destinatario": { "tipoDocumento": "DNI", "numeroDocumento": "71889345", "nombreCompleto": "María Elena Rojas Ccori" },
  "bultos": [ { "numero": 1, "descripcion": "Sobre con documentos", "pesoKg": "0.60" } ], "cantidadBultos": 1, "pesoTotalKg": "0.60",
  "importe": "8.50", "pagado": "8.50", "saldo": "0.00" }
```

## POST `/api/entregas/envios/{codigo}/entrega` — «Confirmar entrega»
`200 { "data": { "envio": {...} } }` con `estado: "ENTREGADO"` y `fechaHoraEntrega` (la hora de la base, no la del navegador). Cuerpo: `{ "tipoDocumento": "DNI", "numeroDocumento": "71889345" }` — el documento que **presenta** quien recoge.
| Regla | Detalle |
|---|---|
| Documento | Debe **coincidir** (tipo y número) con el del destinatario registrado. Se quitan espacios y guiones; `CE` y `PAS` se comparan en mayúsculas. Formato inválido: `422` en `tipoDocumento` / `numeroDocumento`. No coincide: `422` en `numeroDocumento`; el mensaje **no revela** el documento registrado |
| Estado | Debe estar `EN_DESTINO`. `ENTREGADO` → `409 envio_entregado`; `DEVUELTO` → `409 envio_devuelto`; `ANULADO` → `409 envio_anulado`; cualquier otro (aún en camino) → `409 envio_no_esta_en_destino` |
| Saldo | Debe ser `0.00`: con saldo, `409 saldo_pendiente` (el mensaje dice cuánto; se cobra en **Caja**, módulo 6, y luego se entrega) |
| Agencia | El destino de la reserva debe ser la agencia de la sesión; si no, `404` |

**Transacción** (`EntregaRepository`), en este orden: (1) `SELECT … FROM Envio WITH (UPDLOCK, HOLDLOCK)` bloquea el envío: un cobro simultáneo del mismo envío (Caja usa el mismo bloqueo) y otra entrega o devolución **se ponen en fila**; (2) lee importe (`SUM(Bulto.costo)`) y pagado (`SUM(Pago.monto)` de los `REGISTRADO`) con el envío bloqueado; (3) el servicio aplica las reglas y puede abortar (todo se deshace); (4) `UPDATE Envio SET estadoEnvio = 'ENTREGADO', fechaHoraEntrega = SYSDATETIME() WHERE idEnvio = … AND estadoEnvio = 'EN_DESTINO'`; si no cambia ninguna fila, se deshace y se responde `409`. Dos «Confirmar entrega» simultáneos: uno gana y el otro recibe `409 envio_entregado`.

## POST `/api/entregas/envios/{codigo}/devolucion` — «Marcar devuelto»
`200 { "data": { "envio": {...} } }` con `estado: "DEVUELTO"` (sin `fechaHoraEntrega`). Solo desde `EN_DESTINO` y en la agencia de destino; mismos errores de estado y bloqueo que la entrega. **No exige saldo cero ni documento** (el destinatario no recibe el envío). No hay `DELETE`.

## Decisiones y riesgos para revisar
1. **No se entrega con saldo.** La diapositiva muestra «Saldo» y «Cobrar saldo» junto a «Confirmar entrega» y el pago en destino es parte del proceso; se interpreta que el saldo debe quedar en `0.00` antes de entregar. Si el propietario prefiere permitir entregar con saldo, es una condición en `EntregaService::entregar`.
2. **Quien recoge se identifica contra el destinatario registrado.** Para una empresa (RUC) el informe habla de un contacto registrado o una carta firmada, pero el modelo **no registra contactos ni cartas** («Contactos de confianza y clave de recojo: el modelo no los registra»), así que una empresa se entrega presentando el documento registrado. No se guarda quién recogió ni su firma: solo la fecha y hora.
3. **«Cambiar destino (solo REGISTRADO)»** aparece en el prototipo pero se muestra **deshabilitado**: aplica a envíos `REGISTRADO` (no a los de esta pantalla, que están `EN_DESTINO`) y exigiría recalcular tarifas y bultos; queda fuera de este módulo (ver `envios.md`, decisión 8).
4. **Una anulación de pago posterior a la entrega** (`POST /api/caja/pagos/{id}/anular`, módulo 6) no bloquea el envío: podría dejar un saldo en un envío ya `ENTREGADO`. Se propone, si se desea, que Caja rechace anular pagos de envíos `ENTREGADO` o `DEVUELTO` (cambio pequeño en `PagoService::anular`, fuera de este módulo).
5. **«Marcar devuelto» no modela el retorno físico** (no hay viaje de vuelta ni estado intermedio): solo cierra el envío como `DEVUELTO`.
6. **El aviso de llegada al destinatario** (informe, «recibe un aviso de llegada») no se implementa: el modelo no guarda canales de notificación y no hay servicio de mensajería autorizado.
7. **Entrega a domicilio** (`tipoEntrega = 'DOMICILIO'`): la pantalla muestra la dirección y se confirma igual con el documento de quien recibe; no hay reparto ni ruta de reparto.
8. **SQL sin ejecutar contra la base real.** Todo se probó con repositorios simulados (la base queda bloqueada en las pruebas). Ejecutar el módulo exige `UPDATE` de `Envio` y **requiere autorización expresa**; el procedimiento de validación propuesto está en el PR y **no se ha ejecutado**.
