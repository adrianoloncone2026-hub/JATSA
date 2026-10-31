# API — Reservas del cliente (módulo 4)

Reservas por el canal **WEB** de **CLIENTE** y **EMPRESARIO** (RF06, RF07, RF08 y proceso P1). Todas exigen sesión (`RequiereRol`): sin sesión `401`, otro rol `403`. Siempre actúan sobre **la persona de la cuenta de la sesión como remitente**: nunca se acepta un remitente, canal ni estado en el cuerpo. Formato de respuesta: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }`. Toda petición que modifica datos exige `X-Requested-With: JATSA` (ver `auth.md`).

Una reserva se devuelve así (`fecha` es `AAAA-MM-DD`; `codigoSeguimiento` solo existe cuando la reserva ya está FORMALIZADA):
```json
{ "codigo": "R000000006", "fecha": "2026-10-31", "fechaHora": "2026-10-31 09:15:00", "canal": "WEB",
  "contenido": "Documentos y ropa", "estado": "PENDIENTE", "destinatario": "Luis Alberto Flores Condori",
  "origen":  { "codigo": "MOQ001", "nombre": "Agencia Moquegua", "direccion": "Jr. Ancash 325", "departamento": "Moquegua" },
  "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro", "direccion": "Av. Ejército 512", "departamento": "Arequipa" },
  "codigoSeguimiento": null }
```

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
| Contenido | Obligatorio, hasta 200 caracteres (`Reserva.descripcion`) |
| Código | `R` + 9 dígitos correlativos (`R000000006`…). Se calcula dentro de la transacción con `UPDLOCK, HOLDLOCK`, de modo que dos reservas simultáneas no obtienen el mismo número |
| Escritura | Persona del destinatario (si es nueva) y reserva en **una transacción**. Estado `PENDIENTE` y fecha son los valores por defecto de la tabla; canal siempre `WEB` |

`422 datos_invalidos` con **todos** los errores por campo (`numeroDocumento`, `nombreDestinatario`, `telefonoDestinatario`, `sucursalOrigen`, `sucursalDestino`, `contenido`). `409 conflicto` si otra operación se cruzó al crear.

## GET `/api/reservas?estado=PENDIENTE`
Reservas del cliente como remitente, de la más reciente a la más antigua. `estado` es opcional (`PENDIENTE`, `FORMALIZADA`, `CANCELADA`, `VENCIDA`, sin distinguir mayúsculas); otro valor da `422` con `campos.estado`. `200 { "data": { "reservas": [...] } }`.

## GET `/api/reservas/{codigo}`
Una reserva **propia**. Si no existe o es de otra persona, el **mismo** `404 reserva_no_encontrada` (no se revela que el código existe); un código con formato inválido también da 404.

## POST `/api/reservas/{codigo}/cancelar`
Pasa una reserva **propia y PENDIENTE** a `CANCELADA` (RF08). El `UPDATE` es atómico (`WHERE estado = 'PENDIENTE' AND remitente = …`). `200` con la reserva cancelada; `409 reserva_no_cancelable` si ya no está pendiente (formalizada, cancelada o vencida) o si otra operación se adelantó; `404` si no es suya.

## GET `/api/reservas/destinatario?tipoDocumento=DNI&numeroDocumento=70234561`
Autocompleta el formulario: `{ "existe": true, "nombreCompleto": "…", "esUsted": false }` o `{ "existe": false }`. **Devuelve solo el nombre**, nunca teléfono, correo ni otros datos. `esUsted` avisa si el documento es el del propio remitente. `422` si el documento no es válido.

## GET `/api/tarifas?origen=MOQ001&destino=AQP001` (público)
Tarifas vigentes de la ruta para los tres tipos de envío, **sin peso**: es el «Precio referencial» de la diapositiva 12 (RF07). Mismo formato de `tarifas` que `/api/cotizacion` (ver `publico.md`). `422` por códigos inválidos o iguales, `404` si una sucursal no existe o está dada de baja.

## Decisiones y riesgos para revisar
1. **Consulta de nombre por documento (privacidad).** Lo pide el prototipo («se autocompleta si existe»), pero cualquier cliente o empresario con sesión puede averiguar el **nombre completo** de la persona que tenga un DNI o RUC registrado. Se limitó a devolver solo el nombre y a exigir sesión; **no hay límite de intentos**, así que podría usarse para enumerar documentos. Mitigaciones posibles, **sin implementar** por ser reglas de negocio: mostrar el nombre enmascarado («Luis A. F. C.»), exigir que coincida el teléfono, o limitar las consultas por cuenta y por tiempo.
2. **Destinatario nuevo.** El prototipo no muestra campos para él, pero `Persona` exige nombre y teléfono, así que el formulario los pide solo cuando el documento no existe.
3. **Las reservas no vencen solas.** El documento de especificaciones deja abierta la pregunta «¿Las reservas vencen solas? ¿Cuántos días?». Sin un plazo definido no hay ningún proceso que pase una reserva a `VENCIDA`: el estado solo se **muestra** (filtro «Vencidas», insignia). El mensaje de la diapositiva 13 («Si no lo llevas, la reserva vence») se conserva tal cual. Hace falta decidir el plazo.
4. **Se permite reservar una ruta sin tarifa vigente.** El proceso P1 no lo prohíbe; el panel avisa «No hay tarifas vigentes para esta ruta: el precio se confirma en la agencia». Quien formalice el envío (módulo 5) no podrá cobrar bultos sin ruta; si se prefiere bloquearlo ya aquí, es un cambio pequeño.
5. **Código con `MAX + 1`.** No se usa una secuencia (sería un cambio de esquema). El bloqueo `UPDLOCK, HOLDLOCK` serializa las altas; si se cancela una reserva, su número no se reutiliza.
6. **Solo DNI y RUC** para el destinatario, como en el prototipo (`CE` y `PAS` quedan fuera, igual que en el registro).
7. **Reservas por teléfono y ventanilla** (otros canales) pertenecen al módulo 5.
8. **«Mi panel» está incompleto a propósito:** «Envíos en curso», «Saldo por pagar», «Por recoger» y «Próxima salida habitual» necesitan envíos, pagos y viajes (módulos 6 y 9).
9. **Sin límite de intentos** para crear reservas ni personas destinatarias: pendiente antes de exponer a Internet.
10. **SQL sin verificar contra la base real:** las consultas del módulo se probaron con repositorios simulados; ejecutarlas exige escribir en `Persona` y `Reserva` y requiere autorización expresa (procedimiento propuesto en el PR).
