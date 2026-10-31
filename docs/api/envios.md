# API — Ventanilla y envíos (módulo 5)

Pantallas *Ventanilla* (diapositiva 18) y *Formalizar envío* (diapositiva 19) del **ADMINISTRADOR**, y el proceso P2 del informe («Registro del envío»). Todos los endpoints exigen sesión con rol `ADMINISTRADOR` (`RequiereRol`): sin sesión `401`, otro rol `403`. Todo se limita a **la sucursal de la sesión** (`Usuario.idSucursal`, obligatoria para este rol): se toma de la sesión, **nunca del cuerpo ni de la URL**. Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Las peticiones que escriben exigen `X-Requested-With: JATSA` (ver `auth.md`).

Los documentos de remitente y destinatario que aparecen aquí son de **uso interno del personal**; el rastreo público (módulo 9) no los expone. Los teléfonos no se devuelven en ningún endpoint de este módulo.

Una reserva de ventanilla se devuelve así (`venceEl` y `plazoCumplido` como en `reservas.md`):
```json
{ "codigo": "R000000006", "fecha": "2026-10-31", "fechaHora": "2026-10-31 09:15:00", "canal": "WEB",
  "contenido": "Documentos y ropa", "estado": "PENDIENTE",
  "remitente":    { "tipoDocumento": "DNI", "numeroDocumento": "71889345", "nombreCompleto": "María Elena Rojas Ccori" },
  "destinatario": { "tipoDocumento": "DNI", "numeroDocumento": "70234561", "nombreCompleto": "Luis Alberto Flores Condori" },
  "origen":  { "codigo": "MOQ001", "nombre": "Agencia Moquegua", "direccion": "Jr. Ancash 325", "departamento": "Moquegua" },
  "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro", "direccion": "Av. Ejército 512", "departamento": "Arequipa" },
  "codigoSeguimiento": null, "venceEl": "2026-11-03 09:15:00", "plazoCumplido": false }
```

## Ventanilla (módulo `Reservas`)

### GET `/api/ventanilla/reservas`
Reservas **PENDIENTES** cuyo origen es la agencia de la sesión y que **todavía están dentro de las 72 h** (las que ya cumplieron el plazo no se pueden formalizar y no aparecen). De la más antigua a la más reciente. `200 { "data": { "reservas": [...] } }`.

### GET `/api/ventanilla/reservas/{codigo}`
Una reserva de la agencia, **en cualquier estado** (para buscar por código). Si no existe o su origen es **otra agencia**, el **mismo** `404 reserva_no_encontrada` (no se revela que existe). El código se acepta en minúsculas; un formato inválido también da 404.

### GET `/api/ventanilla/personas?tipoDocumento=DNI&numeroDocumento=70234561`
Autocompleta el formulario de «Cliente sin reserva»: `{ "existe": true, "nombreCompleto": "…" }` o `{ "existe": false }`. Solo el nombre. Acepta `DNI`, `RUC`, `CE` y `PAS`. `422` si el documento no es válido.

### POST `/api/ventanilla/reservas` — «Cliente sin reserva»
Crea una reserva `PENDIENTE` con canal **`VENTANILLA`** y origen en la agencia de la sesión. `201 { "data": { "reserva": {...} } }`.
```json
{ "remitenteTipoDocumento": "DNI", "remitenteNumeroDocumento": "71889345", "remitenteNombre": "", "remitenteTelefono": "",
  "destinatarioTipoDocumento": "DNI", "destinatarioNumeroDocumento": "70234561", "destinatarioNombre": "", "destinatarioTelefono": "",
  "sucursalDestino": "AQP001", "contenido": "Cajas con herramientas" }
```
| Regla | Detalle |
|---|---|
| Documentos | `DNI` (8 dígitos), `RUC` (11), `CE`/`PAS` (6 a 12 letras o dígitos, se guardan en mayúsculas). Se quitan espacios y guiones |
| Personas | Si la persona **ya existe** se usa tal cual y **no se le cambia ningún dato** (se ignora el nombre/teléfono enviado). Si es **nueva** se crea y `…Nombre` (hasta 150) y `…Telefono` (9 dígitos) son obligatorios (`Persona` los exige) |
| Remitente ≠ destinatario | `CK_RESERVA_PERSONAS`: `422` en `destinatarioNumeroDocumento` |
| Origen | **Siempre la agencia de la sesión.** Si el cuerpo trae `sucursalOrigen`, `canal` o `estado`, se ignoran |
| Destino | Sucursal activa, distinta del origen, y con **al menos una tarifa vigente** de cualquier tipo (mismo criterio que `reservas.md`) |
| Contenido | Obligatorio, hasta 200 caracteres |

`422 datos_invalidos` con **todos** los errores por campo (`remitenteNumeroDocumento`, `remitenteNombre`, `remitenteTelefono`, `destinatario…`, `sucursalDestino`, `contenido`). Persona nueva y reserva se guardan en **una transacción**; el código `R` + 9 dígitos se calcula en ella con `UPDLOCK, HOLDLOCK`.

## Envíos (módulo `Envios`)

### POST `/api/envios/cotizacion` — cotizar bultos (no escribe nada)
Lo que ve la pantalla mientras se llena la tabla de bultos. Cuerpo: `{ "codigoReserva": "R000000006", "bultos": [ { "descripcion": "Sobre con documentos", "pesoKg": "0.40" }, … ] }`.
```json
{ "data": {
  "reserva": { "codigo": "R000000006", "origen": {…}, "destino": {…} },
  "distanciaKm": "230",
  "bultos": [ { "numero": 1, "descripcion": "Sobre con documentos", "pesoKg": "0.40", "tipo": "SOBRE", "costo": "9.15", "error": null, "errorDescripcion": null },
              { "numero": 3, "descripcion": "Caja con herramientas", "pesoKg": "31.00", "tipo": "CARGA", "costo": null, "error": "CARGA: sin ruta vigente MOQ→AQP", "errorDescripcion": null } ],
  "cantidadBultos": 3, "pesoTotalKg": "9.20", "importe": "23.75", "valido": false } }
```
`importe` y `pesoTotalKg` suman **solo los bultos sin error**. `valido` es `true` solo si hay al menos un bulto y ninguno tiene error. Aplica las mismas comprobaciones de estado que formalizar (404 / 409).

### POST `/api/envios` — formalizar
`201 { "data": { "envio": {...} } }`. Cuerpo: `{ "codigoReserva", "tipoEntrega": "AGENCIA"|"DOMICILIO", "direccionEntrega"?, "bultos": [ { "descripcion", "pesoKg" } ] }`.

**Reglas** (las comprueba el servicio para dar mensajes claros y **se vuelven a comprobar dentro de la transacción SQL**):
| Regla | Detalle |
|---|---|
| Sucursal | La reserva debe tener como **origen la agencia de la sesión**; si no, `404 reserva_no_encontrada` (igual que una inexistente) |
| Estado | Debe estar `PENDIENTE`. `FORMALIZADA` → `409 reserva_formalizada` (indica el envío); `CANCELADA` → `409 reserva_no_formalizable`; `VENCIDA` → `409 reserva_vencida` |
| Plazo de 72 h | Una `PENDIENTE` que ya cumplió las 72 h (`fechaHoraReserva <= ahora − 72 h`; a las 71:59:59 todavía no) → `409 reserva_vencida`, **aunque siga almacenada como `PENDIENTE`** porque aún no corrió el comando de vencimiento |
| Entrega | `AGENCIA` (dirección ignorada, se guarda `NULL`) o `DOMICILIO` (dirección obligatoria, hasta 150; `CK_ENVIO_DOMICILIO`) |
| Bultos | De 1 a 50. `descripcion` obligatoria (hasta 150); `pesoKg` > 0 con hasta 2 decimales (se acepta coma) y hasta 300 kg |
| Tipo por peso | `SOBRE` ≤ 1 kg, `PAQUETE` ≤ 30 kg, `CARGA` ≤ 300 kg (`Shared\Tarifas`, igual que el cotizador) |
| Tarifa | Cada bulto se cobra con la tarifa **vigente de su tipo** para origen→destino (la de `vigenteDesde` más reciente). Si no existe: error en ese bulto, «**CARGA: sin ruta vigente MOQ→AQP**», y **no se guarda nada** |
| Costo | `precioBase + precioPorKm × distanciaKm`, en céntimos enteros con redondeo medio hacia arriba. **Lo calcula el servidor**: el cuerpo no puede traer costo, tipo, ruta, estado ni importe (se ignoran) |

**Transacción** (`EnvioRepository::formalizar`), en este orden:
1. `UPDATE Reserva SET estadoReserva = 'FORMALIZADA' WHERE codigoReserva = … AND idSucursalOrigen = … AND estadoReserva = 'PENDIENTE' AND fechaHoraReserva > DATEADD(HOUR, -72, SYSDATETIME())`. Si no cambia ninguna fila (otra ventanilla, una cancelación o el vencimiento se adelantaron) se deshace y se responde `409`. Esto **reclama la reserva**: de dos operaciones simultáneas gana una sola.
2. Lee las tarifas vigentes con `HOLDLOCK` **dentro** de la transacción y calcula el costo de cada bulto con ellas.
3. Genera el **código de seguimiento** `EN` + 10 caracteres (`A-Z` y `2-9`, sin `I`, `O`, `0`, `1`) comprobando con `UPDLOCK, HOLDLOCK` que no esté en uso; `UQ_ENVIO_CODIGO` es la red de seguridad.
4. `INSERT Envio` (estado `REGISTRADO` y fecha por defecto) y un `INSERT Bulto` por bulto con su `idRuta` y `costo`.
Cualquier error en el medio **deshace todo** (la reserva vuelve a `PENDIENTE`).

`422 datos_invalidos` con **todos** los errores por campo: `tipoEntrega`, `direccionEntrega`, `bultos` (lista vacía o más de 50) y, por bulto, `bultos.N.descripcion` y `bultos.N.pesoKg` (N desde 1).

El envío devuelto:
```json
{ "codigoSeguimiento": "ENK7Q2M9X1BT", "fechaHoraRegistro": "2026-10-31 09:20:00", "estado": "REGISTRADO",
  "tipoEntrega": "AGENCIA", "direccionEntrega": null,
  "reserva": { "codigo": "R000000006", "contenido": "…", "canal": "WEB", "remitente": {…}, "destinatario": {…}, "origen": {…}, "destino": {…} },
  "bultos": [ { "numero": 1, "descripcion": "Sobre con documentos", "pesoKg": "0.40", "tipo": "SOBRE", "costo": "9.15" }, … ],
  "cantidadBultos": 2, "pesoTotalKg": "9.20", "importe": "23.75" }
```
El importe del envío es la suma de los costos de sus bultos (no se guarda aparte, como indica el informe).

### GET `/api/envios/{codigo}`
Un envío (para reabrir o reimprimir la etiqueta), visible para el personal de la sucursal de **origen o de destino**. Otro personal, o un código inexistente o mal formado: el **mismo** `404 envio_no_encontrado`.

## Etiqueta
La etiqueta se imprime desde el navegador (`window.print()` con estilos de impresión): código de seguimiento, ruta, destinatario, cantidad de bultos y peso. No se añadió ninguna librería (ni códigos de barras ni QR).

## Decisiones y riesgos para revisar
1. **«Guardar y cobrar ahora» / «Guardar · paga en destino».** El cobro (`Pago`) pertenece al módulo 6. En este módulo **ambos botones guardan el envío igual** (sin crear ningún `Pago`) y «cobrar ahora» lleva a *Caja* (marcador hasta el módulo 6). «Paga en destino» no registra nada todavía: el pago a cargo del destinatario (`Pago.pagadoPor = 'DESTINATARIO'`) se registra al entregar (módulos 6 y 8).
2. **El código de seguimiento se genera al guardar**, no antes. El prototipo muestra un código de ejemplo en la pantalla; aquí aparece en la confirmación tras guardar, junto al botón «Imprimir etiqueta».
3. **«Cliente sin reserva»** es una pantalla propia (el prototipo solo muestra el botón). Crea la reserva con canal `VENTANILLA` y lleva a *Formalizar*; si el cliente se va sin formalizar, esa reserva vence a las 72 h como cualquier otra.
4. **Documentos CE y PAS** se aceptan en ventanilla (la tabla `Persona` los admite); en la web (`reservas.md`) siguen siendo solo DNI/RUC.
5. **Tope de 50 bultos** por envío: el modelo no lo fija; es una defensa contra cuerpos desmesurados. Se puede cambiar (`EnvioService::MAX_BULTOS`).
6. **«Hoy» para la vigencia de la tarifa** es la fecha de PHP (`America/Lima`), la misma que usan `/api/tarifas` y el cotizador, y se pasa como parámetro a la transacción.
7. **Reservas que cumplieron el plazo y siguen `PENDIENTE`** (porque el comando de vencimiento no está programado, ver `reservas.md`) **se rechazan al formalizar** y no aparecen en la lista de ventanilla.
8. **No se puede cambiar el destino ni los datos de una reserva** desde ventanilla (el informe lo menciona como posibilidad, mientras el envío está `REGISTRADO`); queda fuera del alcance de este módulo.
9. **SQL sin ejecutar contra la base real.** Todo el módulo se probó con repositorios simulados (la base queda bloqueada en las pruebas). Ejecutar la formalización exige `INSERT`/`UPDATE` en `Envio`, `Bulto` y `Reserva` y **requiere autorización expresa**; el procedimiento propuesto está en el PR.
10. Siguen pendientes antes de exponer a Internet: límite de intentos y la política de privacidad de la consulta por documento (ver `reservas.md`).
