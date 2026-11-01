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

## Concurrencia: orden de bloqueos del sistema
Las reglas «solo recursos ACTIVOS» y «no desactivar lo que está en uso» se deciden **dentro de la transacción, con las filas bloqueadas** (`UPDLOCK, HOLDLOCK`), nunca con una lectura anterior. Orden constante de bloqueos en todo el sistema: **sucursales (por id ascendente) → vehículo → usuario → filas de `Ruta`**.
- **Quien desactiva** (sucursal, vehículo, cuenta) bloquea su fila y, ya bloqueada, cuenta lo que depende de ella (viajes sin llegada, personal activo, envíos en curso, gerentes activos).
- **Quien crea algo que depende de un recurso** también lo bloquea y revalida `activo` antes del INSERT: *programar un viaje* (Despacho: sucursales, vehículo y transportista), *crear una cuenta de personal* (sucursal), *cambiar el puesto* (sucursal de destino y cuenta) y *Nueva ruta* (las dos sucursales).
- Resultado: o la desactivación llega antes y el alta ve el recurso inactivo y se niega, o el alta confirma primero y la desactivación ve el viaje/cuenta nuevos y se niega. No queda un viaje programado con un recurso inactivo.
- `DespachoRepository::crearViaje` ya no recibe ids resueltos fuera de la transacción: recibe placa, usuario y código de destino y llama a `$decidir` con `origenActiva`, `destinoExiste/Activa`, `vehiculoExiste/Activo` y `transportistaExiste/Activo` leídos bloqueados.
- **«Editar» el puesto** calcula si hay cambio comparando rol y sucursal pedidos con los **de la fila bloqueada** (`rolActual`, `sucursalActual`); sin cambios reales guardar no es un error y no exige viajes en cero.
- Si dos transacciones se cruzan y SQL Server elige una víctima (error 1205), la petición falla con un error interno sin dejar nada a medias; reintentar es seguro.
- Pruebas simuladas con **ganchos que reproducen la intercalación** (`backend/tests/catalogos.php`) y revisión estática del orden de bloqueos; **no prueban concurrencia real** (eso requiere la validación real propuesta abajo).

## Decisiones para revisar
1. **«Nueva cuenta de personal» usa una persona que ya existe** (la diapositiva pide solo «Documento de la persona» y `Persona` exige teléfono; `Usuario` es 1:1 con `Persona`). Si no existe se responde `404` con un mensaje claro; **no se crea la persona desde aquí**. Una persona existe, por ejemplo, como destinatario de una reserva. Alternativa: añadir nombre y teléfono al formulario para crearla (cambia la pantalla del prototipo).
2. **«Contraseña temporal» — riesgo pendiente, con un hallazgo.** El script SQL no tiene un indicador de «debe cambiarla» y no se añade. El único flujo de cambio de contraseña que existe (`POST /api/cuenta/contrasena`, pantalla *Mi cuenta*, módulo 3) está limitado por el backend a **CLIENTE y EMPRESARIO** (`RequiereRol` en `Cuenta/rutas.php`): **un ADMINISTRADOR o TRANSPORTISTA no puede cambiar hoy su contraseña**. Procedimiento actual y provisional: el gerente entrega la contraseña temporal **en persona** (no por chat ni por correo) y la cuenta debe cambiarse a una propia en cuanto exista el flujo; mientras tanto, la contraseña entregada es la definitiva y, si se sospecha de ella, el gerente **desactiva** la cuenta. **Propuesta (necesita autorización, queda fuera de este módulo):** ampliar `/api/cuenta/contrasena` a todos los roles y mostrar *Mi cuenta* en los marcos de personal (misma validación: contraseña actual, 8–72 caracteres, distinta del usuario y de la actual; al cambiar, la huella invalida las demás sesiones). Forzar el cambio al primer ingreso exigiría una columna nueva y está descartado sin permiso.
3. **Roles creables:** solo `ADMINISTRADOR` y `TRANSPORTISTA` (personal de agencia). No se crean gerentes ni se cambia el rol de clientes/empresarios; **«Editar»** cambia solo rol y sucursal del personal.
4. **«Nuevo precio» no se repite el mismo día** (ver arriba): la vigencia no admite una fila de cero días (`CK_RUTA_VIGENCIA`). Se mantiene.
5. **Sin borrados:** la única sentencia `DELETE` del módulo reemplaza los teléfonos de la sucursal que se está editando (`Telefono_Sucursal`), dentro de la misma transacción.
6. **Desactivar una sucursal** exige que no tenga personal activo, viajes sin llegada ni envíos en curso, para no dejar operaciones huérfanas.
7. **Fuera de este módulo:** *Resumen* y *Viajes y flota* (módulo 11) y *Reportes y plantillas* (módulo 12, bloqueado) siguen pendientes.

## Verificación
- Backend, con repositorios simulados y sin base de datos: `C:\php\php.exe backend/tests/ejecutar.php` (`backend/tests/catalogos.php`).
- Navegador, con la API simulada y con estado: `node frontend/tests/e2e/catalogos.mjs`.
- **Validación real contra SQL Server: NO preparada ni ejecutada.** Todo lo de este módulo **escribe**. Propuesta abajo; necesita una autorización nueva y separada del propietario.

## Propuesta de validación real del módulo 10 (NO preparada, NO ejecutada)
Script futuro `backend/tests/integracion/validar-pm10.ps1` (+ `escritura_pm10.php`), con `-AutorizadoPorElPropietario`, siguiendo el patrón de pm5–pm8. **Nada se ejecuta hasta que el propietario lo autorice expresamente.**

**Alcance y garantías**
1. Solo registros **temporales nuevos** con prefijo `pm10`/`PMX9xx`: sucursales `PMX901` y `PMX902`, vehículo `PM1-901`, personas con documentos `90000101`–`90000106`, cuentas `pm10_*`, rutas entre las dos sucursales temporales. **Ninguna fila original se lee para modificarla ni se cierra una tarifa original.** Sin DDL, sin tocar el script SQL, sin `DBCC CHECKIDENT`.
2. Precondiciones de solo lectura: las 12 tablas, que no existan restos `pm10`, puertos libres, fecha del servidor = fecha de la máquina.
3. Manifiesto en disco tras cada anotación (ids y códigos creados); limpieza **exacta** solo de lo anotado y, si algo no coincide con el manifiesto, **no borra nada** y pide revisión.
4. Sesiones propias (ids anotados) y servidores temporales detenidos al final.

**Qué se comprobaría (cada punto con su GET de verificación y su SELECT)**
- Sucursales: alta con teléfonos, edición, 409 por código repetido, desactivar con personal/viajes/envíos (409) y sin nada (200), reactivar. Teléfonos reemplazados en una transacción.
- Rutas: «Nueva ruta» entre las dos sucursales temporales; 409 por tarifa vigente repetida; **«Nuevo precio»**: como la fila nace con `vigenteDesde` = hoy y se rechaza el mismo día (`tarifa_cambiada_hoy`, comprobado), el script inserta **una fila temporal con `vigenteDesde` = anteayer** para poder cerrarla (el servicio la cierra con `vigenteHasta` = ayer) y comprobar que la fila vieja conserva su precio, que la nueva queda vigente, que hay una sola vigente y que un `Bulto.costo` temporal ligado a la fila cerrada no cambia.
- Vehículos: alta, edición (capacidad no menor a la carga), desactivar con y sin viajes.
- Cuentas: el gerente de prueba (`pm10_gerente`, creado por el script con su `Persona` y un hash en memoria; no se reutiliza ninguna cuenta original); alta de personal para una **persona temporal existente**, 404 si no existe, 409 si ya tiene cuenta, edición de puesto, desactivación (no a uno mismo, no al último gerente, no con viajes) y que la sesión de la cuenta desactivada devuelva 401 en la petición siguiente.
- **Concurrencia real** (peticiones simultáneas con trabajos en paralelo, como pm7/pm8): (a) desactivar el vehículo temporal **a la vez** que se programa un viaje con él: exactamente uno gana y **nunca** queda un viaje abierto con un vehículo inactivo; (b) lo mismo con el transportista y con la sucursal de destino; (c) crear una cuenta en una sucursal **a la vez** que se desactiva la sucursal; (d) dos gerentes temporales que se desactivan mutuamente a la vez: **queda al menos uno activo**; (e) dos «Nueva ruta» idénticas a la vez: **una sola** vigente; (f) dos ediciones de puesto cruzadas. Se acepta un 1205 (víctima de interbloqueo) como resultado válido siempre que el estado final sea consistente.

**Comparación y limpieza**
- Instantánea de las 12 tablas antes y después (filas, checksum, hashes por fila). Tras la limpieza, **filas, checksum y hashes deben ser idénticos**.
- **`IDENT_CURRENT` no se restaura al borrar**: sin `DBCC CHECKIDENT` (prohibido) los contadores de `Sucursal`, `Telefono_Sucursal`, `Ruta`, `Vehiculo`, `Persona`, `Usuario`, `Viaje` (y `Bulto`/`Reserva`/`Envio` si se usan) quedarán **más altos que antes**. El informe debe listar el avance exacto por tabla (= número de INSERT del manifiesto) y el propietario debe **aceptarlo de antemano**; cualquier avance distinto del anotado es un fallo.
- Orden de borrado respetando claves foráneas: `Bulto` → `Envio_Viaje` → `Viaje` → `Envio`/`Reserva` → `Ruta` → `Telefono_Sucursal` → `Usuario` → `Persona` → `Vehiculo` → `Sucursal`.
- `exit 0` solo si no hubo fallo, el manifiesto coincidió y las tablas quedaron idénticas; ante un fallo se detiene, limpia solo lo anotado y reporta, sin reintentar.

**Cobertura:** completa solo si se ejecutan todas las secciones; si falla o se omite una, el informe lo dice (`PARCIAL` / `NO CONCLUYÓ`).