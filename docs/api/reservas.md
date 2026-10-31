# API — Reservas del cliente (módulo 4)

Reservas por el canal **WEB** de **CLIENTE** y **EMPRESARIO** (RF06, RF07, RF08 y proceso P1). Todas exigen sesión (`RequiereRol`): sin sesión `401`, otro rol `403`. Siempre actúan sobre **la persona de la cuenta de la sesión como remitente**: nunca se acepta un remitente, canal ni estado en el cuerpo. Formato de respuesta: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Toda petición que modifica datos exige `X-Requested-With: JATSA` (ver `auth.md`).

Una reserva se devuelve así (`fecha` es `AAAA-MM-DD`; `codigoSeguimiento` solo existe cuando la reserva ya está FORMALIZADA):
```json
{ "codigo": "R000000006", "fecha": "2026-10-31", "fechaHora": "2026-10-31 09:15:00", "canal": "WEB",
  "contenido": "Documentos y ropa", "estado": "PENDIENTE", "destinatario": "Luis Alberto Flores Condori",
  "origen":  { "codigo": "MOQ001", "nombre": "Agencia Moquegua", "direccion": "Jr. Ancash 325", "departamento": "Moquegua" },
  "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro", "direccion": "Av. Ejército 512", "departamento": "Arequipa" },
  "codigoSeguimiento": null, "venceEl": "2026-11-03 09:15:00", "plazoCumplido": false }
```
`venceEl` (fecha y hora límite = `fechaHoraReserva` + 72 h) solo viene en reservas `PENDIENTE`; en las demás es `null`. `plazoCumplido` es `true` si la reserva sigue `PENDIENTE` pero ya cumplió las 72 h y todavía no la marcó `VENCIDA` el proceso de vencimiento (ver más abajo).

## POST `/api/reservas`
Crea una reserva `PENDIENTE`. `201 { "data": { "reserva": {...} } }`.

```json
{ "tipoDocumento": "DNI", "numeroDocumento": "70234561", "nombreDestinatario": "", "telefonoDestinatario": "",
  "sucursalOrigen": "MOQ001", "sucursalDestino": "AQP001", "contenido": "Documentos y ropa" }
```

| Regla | Detalle |
|---|---|
| Destinatario | `DNI` (8 dígitos) o `RUC` (11). Se quitan espacios y guiones. Si ya existe se usa **tal cual, sin cambiar sus datos**; si no existe se crea su persona y entonces `nombreDestinatario` (hasta 150) y `telefonoDestinatario` (9 dígitos) son **obligatorios** (`Persona` los exige) |
| Destinatario ≠ remitente | `CK_RESERVA_PERSONAS`: se rechaza con el campo `numeroDocumento` |
| Sucursales | Códigos de sucursales **activas** y distintos entre sí (`CK_RESERVA_SUCURSALES`) |
| Ruta con tarifa | La ruta origen→destino debe tener **al menos una tarifa vigente de cualquier tipo** (SOBRE, PAQUETE o CARGA) hoy y en ese sentido (la inversa no cuenta). Se comprueba con el mismo cotizador que `GET /api/tarifas` y `/api/cotizacion`. Si no, `422` con `campos.sucursalDestino`: «No hay tarifas vigentes entre estas sucursales. Elige otra ruta.» El tipo concreto se decide al pesar, en la formalización (módulo 5) |
| Contenido | Obligatorio, hasta 200 caracteres (`Reserva.descripcion`) |
| Código | `R` + 9 dígitos correlativos (`R000000006`…). Se calcula dentro de la transacción con `UPDLOCK, HOLDLOCK`, de modo que dos reservas simultáneas no obtienen el mismo número |
| Escritura | Persona del destinatario (si es nueva) y reserva en **una transacción**. Estado `PENDIENTE` y fecha son los valores por defecto de la tabla; canal siempre `WEB` |

`422 datos_invalidos` con **todos** los errores por campo (`numeroDocumento`, `nombreDestinatario`, `telefonoDestinatario`, `sucursalOrigen`, `sucursalDestino`, `contenido`). `409 conflicto` si otra operación se cruzó al crear.

## GET `/api/reservas?estado=PENDIENTE`
Reservas del cliente como remitente, de la más reciente a la más antigua. `estado` es opcional (`PENDIENTE`, `FORMALIZADA`, `CANCELADA`, `VENCIDA`, sin distinguir mayúsculas); otro valor da `422` con `campos.estado`. `200 { "data": { "reservas": [...] } }`.

## GET `/api/reservas/{codigo}`
Una reserva **propia**. Si no existe o es de otra persona, el **mismo** `404 reserva_no_encontrada` (no se revela que el código existe); un código con formato inválido también da 404.

## POST `/api/reservas/{codigo}/cancelar`
Pasa una reserva **propia y PENDIENTE y dentro de plazo** a `CANCELADA` (RF08). El `UPDATE` es atómico (`WHERE estado = 'PENDIENTE' AND remitente = … AND fechaHoraReserva > ahora − 72 h`). `200` con la reserva cancelada; `409 reserva_no_cancelable` si ya no está pendiente (formalizada, cancelada o vencida) o si otra operación se adelantó; `409 reserva_vencida` si sigue `PENDIENTE` pero ya cumplió las 72 h (aunque el proceso de vencimiento no la haya marcado todavía); `404` si no es suya.

## Vencimiento a las 72 horas
**Regla (decisión del propietario).** Una reserva `PENDIENTE` pasa a `VENCIDA` cuando transcurren **72 horas desde `Reserva.fechaHoraReserva`**, comparando fecha **y hora**; **en el instante límite exacto ya vence** (`fechaHoraReserva <= ahora − 72 h`; a las 71:59:59 todavía no). Las `FORMALIZADA`, `CANCELADA` y `VENCIDA` no cambian por esta regla. Una vencida no se cancela como pendiente ni se formaliza por el flujo normal. Sin cambios de esquema.

**Dos capas, que no deben confundirse:**
1. **Efecto inmediato en la API (siempre activo).** Aunque nadie haya ejecutado el proceso, una reserva `PENDIENTE` con el plazo cumplido ya no se puede cancelar (`409 reserva_vencida`) y se devuelve con `plazoCumplido: true`; la pantalla ofrece «Plazo cumplido» en lugar de «Cancelar». **Esto no modifica la base de datos.**
2. **Transición persistida `PENDIENTE → VENCIDA` (requiere ejecutar el comando).** La hace `backend/bin/vencer-reservas.php`, con **un único `UPDATE` condicionado** (`WHERE estadoReserva = 'PENDIENTE' AND fechaHoraReserva <= DATEADD(HOUR, -72, SYSDATETIME())`). Es atómico frente a una cancelación o formalización simultánea: cada reserva termina en un solo estado y gana la operación que llega primero. Es idempotente.

**Comando** (solo por consola; nada lo ejecuta ni lo programa por sí solo):
```
php backend/bin/vencer-reservas.php                          # SIMULACIÓN (predeterminado): lista lo que vencería, no escribe
php backend/bin/vencer-reservas.php --ejecutar               # persiste el vencimiento
php backend/bin/vencer-reservas.php --referencia="AAAA-MM-DD HH:MM:SS" [--ejecutar]   # usa ese instante en vez del reloj de SQL Server (comprobaciones)
```
Códigos de salida: `0` correcto · `1` error (queda en `backend/storage/logs`) · `2` argumentos inválidos. Se compara con el reloj de SQL Server, el mismo que escribe `fechaHoraReserva`, así que no depende de la zona horaria de PHP.

**Cómo activarlo (no está activado; requiere decisión del propietario).** Programar la ejecución periódica, por ejemplo cada hora, con el Programador de tareas de Windows (con el PHP del servidor):
```
schtasks /Create /SC HOURLY /TN "JATSA vencer reservas" /TR "C:\php\php.exe C:\Jatsa\backend\bin\vencer-reservas.php --ejecutar"
```
o con `cron` en Linux: `0 * * * * php /ruta/backend/bin/vencer-reservas.php --ejecutar`. Con una ejecución horaria, una reserva puede quedar hasta una hora como `PENDIENTE` con `plazoCumplido: true`; la capa 1 evita que se use mientras tanto. **No se instaló ninguna tarea programada**, y el comando solo escribe en estado `PENDIENTE` y con el plazo cumplido (nunca toca otros estados). Permisos: la cuenta de la aplicación ya tiene `UPDATE` sobre `Reserva`; no hace falta ninguno adicional.

> La ventanilla del Administrador (reservas de su agencia, «Cliente sin reserva» y formalizar) está en `envios.md`.

## GET `/api/reservas/destinatario?tipoDocumento=DNI&numeroDocumento=70234561`
Autocompleta el formulario: `{ "existe": true, "nombreCompleto": "…", "esUsted": false }` o `{ "existe": false }`. **Devuelve solo el nombre**, nunca teléfono, correo ni otros datos. `esUsted` avisa si el documento es el del propio remitente. `422` si el documento no es válido.

## GET `/api/tarifas?origen=MOQ001&destino=AQP001` (público)
Tarifas vigentes de la ruta para los tres tipos de envío, **sin peso**: es el «Precio referencial» de la diapositiva 12 (RF07). Mismo formato de `tarifas` que `/api/cotizacion` (ver `publico.md`). `422` por códigos inválidos o iguales, `404` si una sucursal no existe o está dada de baja.

## Decisiones y riesgos para revisar
1. **Consulta de nombre por documento (privacidad).** Lo pide el prototipo («se autocompleta si existe»), pero cualquier cliente o empresario con sesión puede averiguar el **nombre completo** de la persona que tenga un DNI o RUC registrado. Se limitó a devolver solo el nombre y a exigir sesión; **no hay límite de intentos**, así que podría usarse para enumerar documentos. Mitigaciones posibles, **sin implementar** por ser reglas de negocio: mostrar el nombre enmascarado («Luis A. F. C.»), exigir que coincida el teléfono, o limitar las consultas por cuenta y por tiempo.
2. **Destinatario nuevo.** El prototipo no muestra campos para él, pero `Persona` exige nombre y teléfono, así que el formulario los pide solo cuando el documento no existe.
3. **Vencimiento a las 72 h (decidido).** Ver la sección «Vencimiento a las 72 horas». Queda pendiente que el propietario decida **cuándo y cómo programar** el comando (no se activó) y qué hace el módulo 5 con una reserva que cumplió el plazo pero aún figura `PENDIENTE` (debe rechazarla igual que la API: usar la misma condición).
4. **Rutas sin tarifa: bloqueadas (decidido).** `POST /api/reservas` rechaza la ruta si no tiene **ninguna** tarifa vigente de ningún tipo, y la pantalla avisa y deshabilita «Reservar». La validación por **tipo y peso** se repite al formalizar el envío (módulo 5). Entre la reserva y la formalización una tarifa puede caducar: la reserva ya hecha no se anula por eso.
5. **Código con `MAX + 1`.** No se usa una secuencia (sería un cambio de esquema). El bloqueo `UPDLOCK, HOLDLOCK` serializa las altas; si se cancela una reserva, su número no se reutiliza.
6. **Solo DNI y RUC** para el destinatario, como en el prototipo (`CE` y `PAS` quedan fuera, igual que en el registro).
7. **Reservas por teléfono y ventanilla** (otros canales) pertenecen al módulo 5.
8. **«Mi panel» está incompleto a propósito:** «Envíos en curso», «Saldo por pagar», «Por recoger» y «Próxima salida habitual» necesitan envíos, pagos y viajes (módulos 6 y 9).
9. **Sin límite de intentos** para crear reservas ni personas destinatarias: pendiente antes de exponer a Internet.
10. **Verificado contra SQL Server** (con autorización expresa, solo registros temporales `pm4`, ya eliminados): ver «Verificación contra SQL Server». Queda sin ejecutar en la base real el caso de una reserva `FORMALIZADA` propia (no existe ninguna formalizada de una cuenta de prueba); se probó que una ajena original no se puede ver ni cancelar y que el vencimiento no la toca.

## Verificación contra SQL Server
Ejecutada contra `JATSA_Encomiendas` con las operaciones autorizadas en el PR #5 (`SELECT`, `INSERT`, `UPDATE`, `DELETE` solo sobre filas `pm4`; sin DDL, sin tocar el script ni filas originales). Resumen (el detalle está en el comentario del PR):
- Inicial: 12 tablas (5 filas cada una, `Bulto` 6); sin cuentas ni documentos de prueba. Final: las **12 tablas con las mismas filas y el mismo contenido** (suma de verificación y hash por fila idénticos). Solo avanzaron los contadores `IDENTITY` (Persona 14→16, Usuario 11→12, Reserva 7→17); no se repararon.
- Alta con `INSERT … SELECT` de sucursales activas, código correlativo con `UPDLOCK, HOLDLOCK`, destinatario existente (sin cambiar sus datos) y nuevo, canal `WEB`, estado `PENDIENTE`.
- **Concurrencia:** 8 altas simultáneas (2 rondas × 4 servidores PHP, 4 sesiones, mismo milisegundo): 8 × `201`, códigos distintos y consecutivos `R000000008`–`R000000015`, sin duplicados, sin bloqueos mortales ni errores.
- Rutas sin tarifa y ruta inversa: `422`, sin escribir nada. Destinatario igual al remitente y datos obligatorios del destinatario nuevo: `422`.
- Propiedad: una reserva original ajena da el mismo `404` exacto que una inexistente; cancelarla da `404` y no se modifica.
- Vencimiento (SQL real): con `--referencia` a 71:59:59 no vence, a 72:00:00 y 72:00:01 sí; `--ejecutar` pasó solo `R000000006` a `VENCIDA`, repetirlo dio 0; cancelar una `VENCIDA` → `409`; con el reloj real, una a 73 h → `409 reserva_vencida` (sigue `PENDIENTE` hasta que se ejecuta el comando) y una a 71 h se cancela (`200`).
- **Carrera cancelar ↔ vencer** en el instante límite (reloj real, cancelaciones y comando en paralelo): 6 reservas, cada una terminó en **un único estado** coherente con la respuesta de la API; ganó la cancelación en una (`CANCELADA`) y el vencimiento en las demás.
