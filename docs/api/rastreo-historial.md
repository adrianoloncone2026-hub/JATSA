# API — Rastreo, salidas e historial de envíos (módulo 9)

Pantallas: *Rastreo* (diapositiva 3), *Salidas entre sucursales* (4), *Detalle de salida* (5), *Historial de envíos* (14), *Mi panel* completo (11), «Salidas desde aquí» de *Agencias* (6) y «Próxima salida habitual» de *Nueva reserva* (12). Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. **Todos los endpoints de este módulo son `GET` de solo lectura** (no escriben en la base de datos).

## Parte pública (sin sesión)

Regla 6 de `CLAUDE.md`: **no se exponen documentos de identidad, teléfonos ni nombres de personas**. Solo códigos, estados, agencias, fechas, pesos, importes y datos del viaje.

### GET `/api/rastreo/{codigo}`
Código de seguimiento `EN` + 10 caracteres alfanuméricos (`CK_ENVIO_CODIGO`); se acepta en minúsculas. Un código con formato inválido o inexistente da el **mismo** `404 envio_no_encontrado` (no permite distinguir «no existe» de «mal escrito»).

`200 { "data": { "envio": {...} } }`:
```json
{ "codigoSeguimiento": "ENZ5H0D3V7NJ", "estado": "EN_TRANSITO",
  "etapas": [ { "estado": "REGISTRADO", "cumplida": true, "actual": false }, { "estado": "EN_TRANSITO", "cumplida": true, "actual": true }, "..." ],
  "origen": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" }, "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro" },
  "cantidadBultos": 1, "pesoTotalKg": "18.50", "saldo": "0.00",
  "recorrido": [ { "fechaHora": "2026-10-14 14:00:00", "tipo": "registro", "texto": "Recibido en Agencia Moquegua (MOQ001)" }, "..." ],
  "viajeActual": { "codigo": "VJ2026101402", "vehiculo": { "tipo": "FURGON", "placa": "B7K-210" }, "fechaHoraSalida": "...", "origen": {}, "destino": {} },
  "rutaId": "MOQ001-AQP001" }
```
- `etapas`: las cuatro del prototipo (`REGISTRADO`, `EN_TRANSITO`, `EN_DESTINO`, `ENTREGADO`). En `DEVUELTO` y `ANULADO` ninguna se marca (la pantalla muestra un aviso).
- `recorrido`: ordenado por fecha. Tipos: `registro`, `carga`, `salida`, `llegada`, `entrega`, `estimada` (la llegada estimada, siempre al final).
- La llegada estimada es el hito `estimada` del `recorrido` (salida del viaje en curso + `Ruta.tiempoHoras`); no existe si no hay viaje en curso o la ruta no tiene tiempo. Es solo informativa.
- **Minimización (revisión del PR #10):** la respuesta tiene exactamente las claves de arriba. Se quitaron `llegadaEstimada` y `horasRuta` (repetían el hito `estimada`) y `viajeActual.estado` (siempre `EN_RUTA`). Del vehículo solo `tipo` y `placa`. **`saldo` y `placa` se mantienen porque la diapositiva 3 los muestra expresamente** («Saldo S/ 0.00», «FURGON B7K-210»); si el propietario decide no publicarlos, basta quitarlos de `RastreoService` y de `Rastreo.jsx` (las claves quedan fijadas por una prueba).
- `rutaId` apunta al tramo en curso, o a origen→destino de la reserva si no hay viaje.

### GET `/api/salidas?origen=&destino=&tipo=`
Filtros opcionales (códigos de sucursal y `SOBRE`/`PAQUETE`/`CARGA`); inválidos → `422` con `campos`. `200 { "data": { "salidas": [...] } }`, una por ruta con tarifa vigente:
`{ id: "MOQ001-AQP001", origen, destino, horarios: ["14:00","15:00"], programado: false, horas: 4, desde: "9.15" }`.
- `horarios`: las horas `HH:00` más frecuentes entre las **salidas reales de los últimos 10 viajes** de la ruta (hasta 3, ordenadas). No existe una tabla de horarios en el script SQL, por eso se calcula.
- `programado`: `true` si no hay salidas reales pero sí viajes programados.
- `horas` = máximo de `tiempoHoras` de las tarifas vigentes. `desde` = precio mínimo vigente, o el del `tipo` pedido.

### GET `/api/salidas/{id}`
`id` = `ORIGEN-DESTINO` (códigos de sucursal). `200 { "data": { "ruta": {...} } }` con `origen`, `destino`, `distanciaKm`, `horas`, `horarios`, `programado`, `llegadaAproximada { desde, hasta }` (primera salida habitual + `horas`), `tarifas [{ tipo, precioPorBulto }]` y `recientes [{ codigo, fechaHoraSalida, fechaHoraLlegada, estado }]`. Inexistente → `404 ruta_no_encontrada`.

## Parte del cliente (sesión `CLIENTE` o `EMPRESARIO`)

Sin sesión `401`, otro rol `403`. Todo se limita a los envíos **de la persona de la sesión** como remitente (la de la reserva) o destinataria. Un envío ajeno o inexistente da el **mismo** `404 envio_no_encontrado`. De la otra parte solo se devuelve el **nombre**.

### GET `/api/mis-envios?q=&rol=&estado=&desde=&hasta=`
`q` ≤ 50 caracteres (código o nombre de la otra parte), `rol` `ENVIE`|`RECIBI`, `estado` uno del enum, `desde`/`hasta` `AAAA-MM-DD` (inclusivos; `desde` ≤ `hasta`). Inválidos → `422` con `campos`. `200 { "data": { "envios": [...], "hayMas": false } }`, más recientes primero, hasta 200: `{ codigoSeguimiento, estado, rol, fecha, otraParte, importe }`.

### GET `/api/mis-envios/{codigo}`
`200 { "data": { "envio": {...} } }`: lo anterior más `origen`, `destino`, `fechaHoraRegistro`, `fechaHoraEntrega`, `viaje { codigo, fechaHoraSalida, fechaHoraLlegada }`, `bultos [{ numero, descripcion, pesoKg, tipo, costo }]`, `cantidadBultos`, `pesoTotalKg`, `importe`, `pagado`, `saldo` y `pagos [{ monto, metodoPago, estado, sucursal, fechaHora }]` (los anulados se devuelven con su estado).

### GET `/api/mis-envios/resumen`
Para *Mi panel*: `{ enCurso, saldoPendiente, porRecoger: [{ codigoSeguimiento, estado, de, agencia: { nombre, direccion } }], hayMasPorRecoger }`. La ruta `/resumen` se registra **antes** que `/{codigo}`.
- `enCurso` y `saldoPendiente` se calculan **en SQL sobre todos** los envíos en curso de la persona (`COUNT(*)` y `SUM` por envío de lo que falta, sin `TOP`; filtrados por la persona y por `REGISTRADO`/`EN_TRANSITO`/`EN_DESTINO`). El tope de 200 ya **no** afecta a los totales.
- Solo `porRecoger` se acota (50, el más reciente primero); `hayMasPorRecoger` avisa si hay más.
- `saldoPendiente` es el **saldo pendiente de los envíos**, no una deuda personal: el sistema no sabe quién pagará hasta que se registra `Pago.pagadoPor`. La pantalla lo rotula «Saldo pendiente de tus envíos».

## Decisiones para revisar
1. **Saldo pendiente** = lo que falta de los envíos *en curso* de la persona, sea remitente o destinataria (ver arriba); no se presenta como deuda personal.
2. **Horarios = referencia histórica, no una promesa.** Se calculan por hora (`HH:00`) con los últimos 10 viajes de la ruta; no aseguran que haya una salida hoy ni que se admita un paquete. Las pantallas dicen «salidas frecuentes» / «referencia histórica» y «confirma la salida en la agencia». Se distinguen: horas frecuentes (hay historial), «viaje programado, sin historial» (`programado: true`) y «sin historial de salidas».
3. **Tiempo de la ruta**: máximo de las tarifas vigentes (misma regla que *Llegadas y entregas*).
4. **Rastreo público**: sin nombres, documentos ni teléfonos; 404 uniforme. **Pendiente:** límite de consultas por IP (no hay dónde guardarlo sin añadir infraestructura).
5. **«Exportar lista»** del historial queda **deshabilitado**: exporta a Excel y ese módulo (12) espera autorización de tecnología.
6. **«Actividad reciente» de *Mi cuenta*** (diapositiva 17) no se incluye: no figura en el alcance del módulo 9 del orden de implementación.

## Validación real de solo lectura (preparada, NO ejecutada)
Script: `backend/tests/integracion/validar-pm9-lectura.ps1` (exige `-AutorizadoPorElPropietario`; sin él no hace nada).
- **Solo SELECT.** No crea cuentas, personas, reservas ni envíos; no hay INSERT/UPDATE/DELETE/DDL/DBCC. Los endpoints del módulo son GET. El ingreso no escribe en la base (solo un archivo de sesión local, que el script borra al terminar).
- **Parte pública** (sin cuenta): rastreo de hasta 6 envíos reales contra la base (estado, bultos, peso, saldo), claves públicas permitidas, ausencia de nombres/documentos/teléfonos, orden del recorrido y 404 uniforme; salidas y detalle de ruta contra `Ruta`/`Viaje` (una por ruta vigente, tarifas, horarios que salen de los últimos 10 viajes, sin inventar).
- **Parte del cliente** (opcional): con una cuenta CLIENTE/EMPRESARIO **ya existente** que indique el propietario (`-UsuarioCliente` y contraseña en `JATSA_PM9_CLAVE`, solo en memoria): totales de *Mi panel* comparados con el SQL equivalente, historial, detalle y 404 uniforme de un envío ajeno.
- Compara las 12 tablas antes y después (filas, checksum, `IDENT_CURRENT`, hash por fila): deben quedar idénticas; `exit 1` ante cualquier fallo.
