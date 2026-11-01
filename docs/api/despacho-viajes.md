# API — Despacho y viajes (módulo 7)

Pantallas *Despacho* (diapositiva 21, **ADMINISTRADOR**), *Mis viajes* (diapositiva 24) y *Detalle del viaje* (diapositiva 25, **TRANSPORTISTA**). Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Las peticiones que escriben exigen `X-Requested-With: JATSA` (ver `auth.md`). Sin sesión `401`; con otro rol `403`.

Tablas del script SQL: `Viaje` (`idVehiculo`, `idUsuarioTransportista`, `idSucursalOrigen`, `idSucursalDestino`, `codigoViaje` `VJ` + 10 dígitos, `fechaHoraSalida`, `fechaHoraLlegada`) y `Envio_Viaje` (`idEnvio`, `idViaje`, `fechaHoraCarga`; un envío no se repite en un mismo viaje). **El estado del viaje no se guarda: se deduce de las fechas** (informe de BD): sin salida = `PROGRAMADO`; con salida y sin llegada = `EN_RUTA`; con llegada = `TERMINADO`. Un vehículo está **ocupado** si tiene un viaje `EN_RUTA`; y, por regla operativa, **un transportista conduce como máximo un viaje `EN_RUTA` a la vez**.

Un viaje se devuelve así (`pesoCargadoKg` y `capacidadKg` con 2 decimales; `llegadaEstimada` = salida + `Ruta.tiempoHoras` de la ruta directa vigente, solo `EN_RUTA` y solo si existe esa ruta):
```json
{ "codigo": "VJ2026101402", "estado": "EN_RUTA",
  "origen": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" }, "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro" },
  "vehiculo": { "placa": "B7K-210", "tipo": "FURGON", "capacidadKg": "6500.00" }, "transportista": "Pedro Mamani Huanca",
  "fechaHoraSalida": "2026-10-14 15:00:00", "fechaHoraLlegada": null, "llegadaEstimada": "2026-10-14 19:00:00",
  "cantidadEnvios": 1, "pesoCargadoKg": "18.50" }
```
El detalle añade `carga`: `[ { "codigoSeguimiento": "ENZ5H0D3V7NJ", "bultos": 1, "pesoKg": "18.50", "destinoFinal": { "codigo": "AQP001", "nombre": "…" }, "cargadoEn": "2026-10-14 14:20:00" } ]`. **No se devuelven documentos ni teléfonos** de nadie.

## Despacho (ADMINISTRADOR)

Todo se limita a **la sucursal de la sesión** (`Usuario.idSucursal`): se toma de la sesión, **nunca del cuerpo ni de la URL**. Un viaje que sale de otra agencia o inexistente da el **mismo** `404 viaje_no_encontrado`.

### GET `/api/despacho/viajes` — «salen de la agencia»
`200 { "data": { "sucursal", "viajes": [...], "hayMasTerminados": false } }`. Todos los `EN_RUTA` y `PROGRAMADO` (en ruta primero, luego programados por código) y los **20 `TERMINADO` más recientes**.

### GET `/api/despacho/envios-en-espera` — «Envíos en espera en esta agencia»
`200 { "data": { "envios": [ { "codigoSeguimiento", "estado", "bultos", "pesoKg", "destino": {…}, "viajeSugerido": "VJ2026103101" | null } ], "hayMas": false } }`. Un envío **espera en la agencia** si (a) está `REGISTRADO`, su reserva salió de esta agencia y **no tiene ningún viaje**, o (b) está `EN_TRANSITO`, su **último viaje llegó aquí** y su destino final es otra agencia (transbordo). Los ya cargados en un viaje que no llegó, los que van en ruta y los de otra agencia **no** aparecen. `viajeSugerido`: el primer viaje programado de la agencia cuyo destino es el destino final del envío.

### GET `/api/despacho/opciones`
Para el formulario de programar: `{ "vehiculos": [ { placa, tipo, capacidadKg, ocupado } ], "transportistas": [ { nombreUsuario, nombreCompleto, sucursal } ], "destinos": [ { codigo, nombre } ] }` (solo activos; los destinos excluyen la propia agencia).

### POST `/api/despacho/viajes` — «Programar viaje»
`201 { "data": { "viaje": {...} } }` (el viaje con `carga: []`). Cuerpo: `{ "placa": "C3M-956", "transportista": "pmamani", "destino": "AQP001" }`.
| Regla | Detalle |
|---|---|
| Origen | **Siempre la agencia de la sesión**; `sucursalOrigen`/`origen` del cuerpo se ignoran |
| Vehículo | Activo. **Se puede programar un vehículo ocupado** (como en la diapositiva 21); lo que no puede es *salir* mientras tenga otro viaje en ruta |
| Transportista | Usuario activo con rol `TRANSPORTISTA` (de cualquier agencia) |
| Destino | Sucursal activa y distinta del origen |
| Código | `VJ` + fecha de la base (`AAAAMMDD`) + número del día (`01` a `99`), calculado en la transacción con el rango del día bloqueado (`UPDLOCK, HOLDLOCK`); `409 limite_de_viajes` si pasa de 99 |
`422 datos_invalidos` con **todos** los campos a la vez (`placa`, `transportista`, `destino`).

### POST `/api/despacho/viajes/{codigo}/carga` — cargar un envío
`201 { "data": { "viaje": {...con carga} } }`. Cuerpo: `{ "codigoSeguimiento": "EN4T7Q0P2LKX" }`. Reglas (se comprueban **dentro de la transacción**, con el viaje y luego el envío bloqueados, siempre en ese orden):
| Regla | Error |
|---|---|
| El viaje sale de esta agencia | `404 viaje_no_encontrado` |
| El viaje sigue `PROGRAMADO` | `409 viaje_no_programado` |
| El envío **espera en esta agencia** (arriba). Si no existe, es de otra agencia o ya no espera: el **mismo** `404 envio_no_encontrado` | `404` |
| Si ya está cargado en un viaje de esta agencia que no llegó | `409 envio_ya_cargado` |
| El destino del viaje no es una agencia por la que ya pasó el envío (su origen o destinos de viajes anteriores): evita vueltas | `409 destino_ya_visitado` |
| **Capacidad:** peso ya cargado + peso del envío ≤ `Vehiculo.capacidadKg` (el peso de un envío es la suma de sus bultos) | `409 capacidad_excedida` (el mensaje dice las cifras) |
Cargar **no cambia el estado del envío**: pasa a `EN_TRANSITO` cuando el viaje sale. Un envío en transbordo puede cargarse en un viaje hacia cualquier otra agencia no visitada (no hay grafo de rutas en el modelo; lo decide el administrador).

### DELETE `/api/despacho/viajes/{codigo}/carga/{seguimiento}` — quitar un envío
`200 { "data": { "viaje": {...} } }`. Solo en un viaje `PROGRAMADO` de esta agencia (`409 viaje_no_programado` si ya salió); un envío que no estaba cargado: `404 envio_no_encontrado`. El envío vuelve a esperar. Es el único `DELETE` del módulo y solo toca la fila de `Envio_Viaje`.

## Viajes (TRANSPORTISTA)

### GET `/api/viajes?estado=EN_RUTA` — «Mis viajes»
`200 { "data": { "viajes": [...], "hayMas": false } }`. Los viajes del transportista de la sesión (`Viaje.idUsuarioTransportista`; **no** depende de su sucursal): en ruta, luego programados por código y luego terminados, del más reciente al más antiguo. `estado` opcional: `PROGRAMADO`, `EN_RUTA` o `TERMINADO` (otro valor: `422`). Hasta 200.

### GET `/api/viajes/{codigo}` — detalle con la carga
El **transportista dueño** o el **administrador** de la agencia de origen o de destino (para «Ver carga» de Despacho). Cualquier otro, o inexistente: el mismo `404`.

### POST `/api/viajes/{codigo}/salida` — «Marcar salida»
Solo el transportista dueño. `200 { "data": { "viaje": {...} } }`. `409 viaje_ya_salio`; `409 vehiculo_ocupado` si el vehículo tiene otro viaje en ruta; `409 transportista_ocupado` si el conductor ya tiene otro viaje en ruta (aunque use otro vehículo). **Transacción** (`ViajeRepository::marcarSalida`): bloquea el viaje, luego el vehículo y luego el transportista (`UPDLOCK, HOLDLOCK` sobre la fila de `Vehiculo` y la de `Usuario`; **orden constante viaje → vehículo → transportista**, sin interbloqueos), lee si hay otro viaje en ruta con ese vehículo y con ese conductor, aplica las reglas, fija `fechaHoraSalida = SYSDATETIME()` y pasa a `EN_TRANSITO` los envíos cargados que estaban `REGISTRADO`. Dos «Marcar salida» simultáneos del mismo viaje: uno gana y el otro recibe `409`; dos salidas simultáneas de viajes distintos **del mismo conductor** (con vehículos distintos) se ponen en fila por su fila de `Usuario`: sale una y la otra recibe `409 transportista_ocupado`.

### POST `/api/viajes/{codigo}/llegada` — «Marcar llegada»
Solo el transportista dueño. `409 viaje_no_salio`, `409 viaje_ya_llego`. Fija `fechaHoraLlegada = SYSDATETIME()` con un `UPDATE` condicional (`salida < ahora`: `CK_VIAJE_FECHAS` exige llegada **posterior** a la salida y las columnas son `DATETIME2(0)`); si no pasó ni un segundo, `409 llegada_muy_pronto`. Los envíos cargados **cuyo destino final es la agencia de llegada** pasan a `EN_DESTINO`; los demás (transbordo) siguen `EN_TRANSITO` y esperan en esa agencia. La entrega (`ENTREGADO`) es del módulo 8.

## Decisiones y riesgos para revisar
1. **Se puede salir sin carga:** se permite marcar la salida con 0 kg (la diapositiva 21 muestra viajes programados sin carga y el informe no lo prohíbe; puede representar un traslado operativo). La pantalla avisa «Sin carga». Si el propietario quiere exigir al menos un envío, es una línea.
2. **Un vehículo y un transportista no pueden tener dos viajes en ruta.** El vehículo lo dice el informe; lo del conductor es regla operativa añadida tras la revisión de ChatGPT y se serializa bloqueando su fila de `Usuario`. Se puede *programar* varios viajes para el mismo vehículo o conductor (como en la diapositiva 24); lo que no puede es *salir* con otro en ruta.
3. **Transbordo sin grafo de rutas:** el administrador elige a qué agencia sigue el envío; solo se impide volver a una agencia ya visitada. **El sistema no garantiza que la cadena de transbordos forme una ruta comercial viable hasta el destino final** (ni que exista tarifa o viaje posterior): es una decisión operativa del administrador. No se comprueba que exista una `Ruta` entre las agencias (tampoco hace falta para programar un viaje). La llegada estimada solo se muestra si hay una ruta directa vigente.
4. **Llegada estimada = salida + `tiempoHoras` máximo** de las tarifas vigentes del par (referencial; coincide con 15:00 → 19:00 de la diapositiva 25).
5. **«Programar viaje» elige vehículo y transportista de cualquier agencia** (el prototipo no muestra el formulario; es un diseño propio con los datos que ofrece `/api/despacho/opciones`). Las altas y bajas de vehículos y cuentas son del módulo 10.
6. **Quitar carga** no está en el prototipo; se añadió porque sin ella un error de carga no tendría salida. Solo antes de la salida.
7. **Un `Envio` anulado o devuelto** no espera ni se carga (solo `REGISTRADO` en origen y `EN_TRANSITO` en tránsito). Nada de este módulo anula envíos.
8. **SQL sin ejecutar contra la base real.** Todo se probó con repositorios simulados (la base queda bloqueada en las pruebas). Ejecutarlo exige `INSERT`/`UPDATE`/`DELETE` en `Viaje`, `Envio_Viaje` y `Envio` y **requiere autorización expresa**; el procedimiento de validación propuesto está en el PR y **no se ha ejecutado**.
