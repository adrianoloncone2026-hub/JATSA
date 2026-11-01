# API — Catálogos y cuentas del gerente (módulo 10)

Pantallas: *Catálogos* (diapositiva 29: **Sucursales**, **Rutas y precios**, **Vehículos**) y *Cuentas* (diapositiva 30) del **GERENTE**. Todos los endpoints exigen sesión con rol `GERENTE` (`RequiereRol`): sin sesión `401`, otro rol `403`. Formato: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje", "campos"? } }` (los `422` y algunos `409` traen `campos`). Las peticiones que escriben exigen `X-Requested-With: JATSA` (ver `auth.md`). **Nada se borra**: sucursales, vehículos y cuentas se **desactivan**, y una tarifa se **cierra y se reemplaza**. Por eso no hay rutas `DELETE`.

Tablas del script SQL: `Sucursal`, `Telefono_Sucursal`, `Ruta`, `Vehiculo`, `Usuario`, `Persona` (sin cambios al script). Las reglas de integridad que comprueba el servicio (formatos, rangos) repiten las `CHECK` del script para dar mensajes claros; la base sigue siendo la última barrera.

## Catálogos

### Sucursales
- `GET /api/catalogos/sucursales` → `{ sucursales: [{ codigo, nombre, departamento, provincia, distrito, direccion, telefonos: ["953123456"], activa }] }` (activas e inactivas).
- `POST /api/catalogos/sucursales` → `201 { sucursal }`. Cuerpo: `codigoSucursal` (3 letras + 3 dígitos; se guarda en mayúsculas), `nombre` ≤ 80, `departamento` ≤ 40, `provincia` ≤ 40, `distrito` ≤ 60, `direccion` ≤ 150, `telefonos` (hasta 5, 9 dígitos cada uno, sin repetir; se aceptan espacios y guiones). Código repetido: `409 sucursal_existente` (campo `codigoSucursal`). Alta y teléfonos en **una transacción**.
- `PUT /api/catalogos/sucursales/{codigo}` → `{ sucursal }`. Cambia datos y **reemplaza** los teléfonos (transacción). **El código no cambia** (el del cuerpo se ignora). `404 sucursal_no_encontrada`.
- `POST /api/catalogos/sucursales/{codigo}/estado` con `{ "activa": bool }` → `{ sucursal }`. **Desactivar** exige (con la sucursal bloqueada): sin personal activo (`409 sucursal_con_personal`), sin viajes programados ni en ruta (`409 sucursal_con_viajes`) y sin envíos en curso (`409 sucursal_con_envios`). Reactivar siempre se puede.

### Rutas y precios
- `GET /api/catalogos/rutas` → `{ rutas: [{ id, origen{codigo,nombre}, destino{...}, tipoEnvio, precioBase, precioPorKm, distanciaKm, tiempoHoras, vigenteDesde, vigenteHasta, vigente, costoBulto }] }` con **todas** las filas (vigentes y cerradas) por origen, destino, tipo y vigencia descendente. `costoBulto` = `Tarifas::costoBultoCentimos` (la misma fórmula que usa el cotizador y la formalización): SOBRE 8.00/0.005/230 km → `9.15`, PAQUETE 10.00/0.020/230 → `14.60`, 10.00/0.020/1010 → `30.20`, CARGA 40.00/0.080/1200 → `136.00`, SOBRE 8.00/0.005/100 → `8.50` (los valores de la diapositiva 29).
- `POST /api/catalogos/rutas` («Nueva ruta») → `201 { ruta }`. Cuerpo: `origen`, `destino` (códigos de sucursales **activas**, distintos), `tipoEnvio` (`SOBRE`/`PAQUETE`/`CARGA`), `precioBase` > 0 (hasta 2 decimales), `precioPorKm` ≥ 0 (hasta 3), `distanciaKm` > 0 (hasta 1), `tiempoHoras` entero 1–32767. La tarifa nace **vigente desde hoy** (fecha del servidor). Si ya hay una vigente para ese origen, destino y tipo: `409 tarifa_vigente_existente` (se usa «Nuevo precio»). El conteo se hace con las filas bloqueadas (`UPDLOCK, HOLDLOCK`): dos altas simultáneas no dejan dos vigentes.
- `POST /api/catalogos/rutas/{id}/precio` («Nuevo precio») → `201 { ruta }` (la **fila nueva**). Cuerpo: `precioBase`, `precioPorKm`, `distanciaKm`, `tiempoHoras` (origen, destino y tipo no cambian). En **una transacción** con la fila bloqueada: cierra la vigencia de la actual (`vigenteHasta` = el día anterior a hoy) e inserta otra con `vigenteDesde` = hoy. **Los bultos ya cobrados conservan su tarifa**: `Bulto.costo` ya está guardado y `Bulto.idRuta` sigue apuntando a la fila cerrada, que no se modifica ni se borra. Errores: `404 ruta_no_encontrada`, `409 tarifa_cerrada` (la fila ya no es la vigente) y `409 tarifa_cambiada_hoy` (la fila entró en vigor hoy: cerrarla con «ayer» violaría `CK_RUTA_VIGENCIA`; el cambio se registra desde mañana).

### Vehículos
- `GET /api/catalogos/vehiculos` → `{ vehiculos: [{ placa, tipoVehiculo, capacidadKg, activo, viajesAbiertos }] }` (`viajesAbiertos` = viajes sin llegada).
- `POST /api/catalogos/vehiculos` → `201 { vehiculo }`. `placa` `ABC-123` (mayúsculas), `tipoVehiculo` (`CAMION`/`FURGON`/`CAMIONETA`/`MOTO`), `capacidadKg` > 0 (hasta 2 decimales). Repetida: `409 vehiculo_existente`.
- `PUT /api/catalogos/vehiculos/{placa}` → `{ vehiculo }`. Cambia tipo y capacidad; **la placa no cambia**. La capacidad no puede ser menor que el mayor peso ya cargado en sus viajes sin llegada (`409 capacidad_menor_a_la_carga`, campo `capacidadKg`).
- `POST /api/catalogos/vehiculos/{placa}/estado` con `{ "activo": bool }`. Desactivar con viajes programados o en ruta: `409 vehiculo_con_viajes`.

Los vehículos y sucursales inactivos **dejan de ofrecerse** donde ya se filtraba por `activo`/`activa` (programar un viaje en Despacho, Agencias y Salidas públicas).

## Cuentas

- `GET /api/usuarios?q=&rol=&estado=` → `{ cuentas: [{ usuario, persona, rol, sucursal: {codigo,nombre}|null, activa, esUsted, editable }], hayMas }`. `q` ≤ 50 (usuario o nombre), `rol` uno de los cinco, `estado` `ACTIVA`/`INACTIVA`; inválidos `422`. Hasta 200. **Nunca** devuelve el hash, documentos, teléfonos ni correos.
- `GET /api/usuarios/persona?tipoDocumento=&numeroDocumento=` → `{ persona: { existe, nombreCompleto?, tieneCuenta? } }` (solo el nombre) para el formulario. Se registra **antes** que `/{usuario}`.
- `POST /api/usuarios` («Crear cuenta») → `201 { cuenta }`. Cuerpo: `tipoDocumento` (`DNI`/`CE`/`PAS`), `numeroDocumento`, `rol` (**solo `ADMINISTRADOR` o `TRANSPORTISTA`**), `sucursal` (obligatoria y **activa**: `CK_USUARIO_SUCURSAL`), `usuario` (4–30: letras, números, `.`, `-`, `_`; único sin distinguir mayúsculas), `contrasena` (8–72 bytes, distinta del usuario; se guarda con `password_hash` bcrypt cost 12 y **no se devuelve**). La persona **debe existir y no tener cuenta**: `404 persona_no_encontrada` (campo `numeroDocumento`) o `409 cuenta_existente`; usuario repetido `409 usuario_en_uso`.
- `PUT /api/usuarios/{usuario}` («Editar») con `{ rol, sucursal }` → `{ cuenta }`. Solo personal (`ADMINISTRADOR`/`TRANSPORTISTA`): cualquier otra cuenta `409 cuenta_no_editable`. Un transportista con viajes sin llegada no cambia de puesto (`409 transportista_con_viajes`); guardar sin cambios no es error.
- `POST /api/usuarios/{usuario}/estado` con `{ "activo": bool }` («Desactivar seleccionada»). Desactivar **no** puede ser: la propia cuenta (`409 no_puede_desactivarse`), el **último gerente activo** (`409 ultimo_gerente`; se cuenta con los gerentes bloqueados) ni un transportista con viajes sin llegada (`409 transportista_con_viajes`). Reactivar siempre se puede. `RequiereSesion` comprueba `Usuario.activo` en **cada** petición: la sesión de una cuenta desactivada deja de valer enseguida.

## Decisiones para revisar
1. **«Nueva cuenta de personal» usa una persona que ya existe** (la diapositiva pide solo «Documento de la persona» y `Persona` exige teléfono; `Usuario` es 1:1 con `Persona`). Si no existe se responde `404` con un mensaje claro; **no se crea la persona desde aquí**. Una persona existe, por ejemplo, como destinatario de una reserva. Alternativa: añadir nombre y teléfono al formulario para crearla (cambia la pantalla del prototipo).
2. **«Contraseña temporal»:** el script SQL no tiene un indicador de «debe cambiarla» y no se añade; la cuenta de personal usa la contraseña entregada hasta que se cambie con el flujo existente. Pendiente de decisión si debe forzarse el cambio.
3. **Roles creables:** solo `ADMINISTRADOR` y `TRANSPORTISTA` (personal de agencia). No se crean gerentes ni se cambia el rol de clientes/empresarios; **«Editar»** cambia solo rol y sucursal del personal.
4. **«Nuevo precio» no se repite el mismo día** (ver arriba): la vigencia no admite una fila de cero días.
5. **Sin borrados:** la única sentencia `DELETE` del módulo reemplaza los teléfonos de la sucursal que se está editando (`Telefono_Sucursal`), dentro de la misma transacción.
6. **Desactivar una sucursal** exige que no tenga personal activo, viajes sin llegada ni envíos en curso, para no dejar operaciones huérfanas.
7. **Fuera de este módulo:** *Resumen* y *Viajes y flota* (módulo 11) y *Reportes y plantillas* (módulo 12, bloqueado) siguen pendientes.

## Verificación
- Backend, con repositorios simulados y sin base de datos: `C:\php\php.exe backend/tests/ejecutar.php` (`backend/tests/catalogos.php`).
- Navegador, con la API simulada y con estado: `node frontend/tests/e2e/catalogos.mjs`.
- **Validación real contra SQL Server: NO preparada ni ejecutada.** Todo lo de este módulo **escribe** (INSERT/UPDATE y un DELETE de teléfonos). Una validación real exigiría registros temporales con limpieza exacta y la comparación de las 12 tablas, y necesita una autorización nueva y separada del propietario (procedimiento a proponer en la revisión).