# Preparación módulos 11 y 12 (contenido de `docs/preparacion-modulos-11-12.md`)

> Plan Mode: no se pudo guardar en `docs/`. Al aprobar, se escribe tal cual en `docs/preparacion-modulos-11-12.md` (único archivo nuevo). No se toca código, SQL, `.env` ni ramas.

## 1. Estado y arquitectura relevante
- `main` integra módulos 1–10 (último: PR #11, Catálogos y cuentas). Rama actual `modulo-10-catalogos-cuentas`, limpia.
- Ya declaradas en `frontend/src/routes/definicion.js` (sección `gerente`, base `/gerente`, rol GERENTE): `''` Resumen (módulo 11, menú), `reportes` (12, menú), `viajes-flota` (11, **menu:false**), `catalogos`, `cuentas`, `contrasena`. En `routes/index.jsx` las no implementadas caen en `PantallaPendiente`; hay que registrar `'gerente'` (índice) y `'gerente/viajes-flota'` junto a `'gerente/catalogos'`.
- Layout: `layouts/LayoutGerente.jsx` → `LayoutBase idSeccion="gerente"`. Menú sale de `enlacesMenu()`.
- Backend: patrón `rutas.php` por módulo + `RequiereRol([Roles::GERENTE])` (ejemplo: `Modules/Usuarios/rutas.php`) + Controller → Service → Repository con interfaz `Repositorio*` (ejemplo: `Viajes/RepositorioViajes.php`). `Modules/Reportes/` solo tiene `.gitkeep`.

## 2. Requisitos exactos (extraídos de las diapositivas; notas de 23/26/27/28 vacías)
**26 Resumen (GERENTE)** — filtros: Mes (Octubre 2026), Sucursal (Todas). Tarjetas: 167 envíos registrados (+18 % vs mes anterior, con enlace "Reportes"); S/ 5,511 cobrados (+18 %); 46 envíos por entregar ("12 llevan más de 3 días"); 2 viajes en ruta ("6 programados"). Bloque "Dónde están los envíos del mes": Entregados 112, En tránsito 21, En agencia de destino 25, Registrados 9 (suman 167; 46 = 21+25). Todo solo lectura.
**28 Viajes y flota** — "← Reportes" (se llega desde Reportes). Filtros: Año, Mes, Sucursal, Vehículo, Transportista, Tipo de envío. KPIs: 142 viajes terminados, 2 en ruta ahora, 6 programados, 18,640 kg transportados, 37 % uso medio de capacidad, 9 % envíos con transbordo. Tabla "Viajes en ruta": Viaje, Ruta, Vehículo, Transportista, Salió, Llega aprox. (con "+1" de día). Tabla "Transportistas del mes": Transportista, Sucursal, Viajes, Kg. Todo solo lectura; sin acciones.
Los números del prototipo son ejemplo (no copiar como datos). Todo sale de SQL Server; lo demás es diseño.

## 3. Reutilizable (rutas verificadas)
- Estados de viaje se deducen de fechas, no se guardan: PROGRAMADO = `fechaHoraSalida IS NULL`; EN_RUTA = salida NOT NULL y llegada NULL; TERMINADO = ambas (`Viajes/ViajeRepository.php`, `ORDEN` y `listarDeTransportista`; `ViajeRepository` ya calcula `horas` estimadas de la ruta vigente → sirve para "Llega aprox.").
- Front: `components/InsigniaEstado.jsx`, `Moneda.jsx`, `TablaCarga.jsx` (referencia de tabla), `Etiqueta.jsx`, `CampoTexto.jsx`; `api/cliente.js` (`solicitar`), `api/catalogos.js` (listarSucursales; vehículos), `api/usuarios.js` (transportistas), `utils/formato.js` (`fechaHoraBreve`); `modules/gerente/Catalogos*.jsx` como plantilla de pantalla del gerente; `auth/RutaProtegida.jsx`, `auth/roles.js`.
- Backend: `Shared/Roles.php`, `Middleware/RequiereRol.php`, `Shared/Validacion.php`, `Shared/HttpException.php`, `Core/Response.php`. Catálogos de sucursales/vehículos para poblar filtros (`Catalogos/CatalogoRepository.php`).
- **No existe** nada agregado/estadístico (conteos por mes, sumas de Pago, % capacidad, transbordos): hay que construirlo.

## 4. Tablas y columnas SQL (script, solo lectura)
- `Envio`(idEnvio, idReserva, codigoSeguimiento, fechaHoraRegistro, tipoEntrega AGENCIA/…, estadoEnvio ∈ REGISTRADO|EN_TRANSITO|EN_DESTINO|ENTREGADO|DEVUELTO|ANULADO, fechaHoraEntrega). Sucursal del envío vía `Reserva.idSucursalOrigen/Destino`.
- `Pago`(idEnvio, idSucursal [donde se cobra], fechaHoraPago, monto, metodoPago, pagadoPor, estadoPago; anulado = `'ANULADO'`).
- `Viaje`(idVehiculo, idUsuarioTransportista, idSucursalOrigen/Destino, fechaHoraSalida, fechaHoraLlegada) · `Envio_Viaje`(idEnvio, idViaje, fechaHoraCarga) · `Bulto`(idEnvio, pesoKg, idRuta) · `Vehiculo`(placa, tipoVehiculo CAMION|FURGON|CAMIONETA|MOTO, capacidadKg) · `Usuario`(rol, idSucursal NULL salvo ADMIN/TRANSPORTISTA) · `Ruta` (horas, vigencia).
- Relaciones: Viaje→Vehiculo/Usuario/Sucursal; Envio_Viaje une Envio↔Viaje; peso = Σ Bulto.pesoKg por envío.

## 5. Endpoints y componentes previstos (módulo 11, solo GET, solo GERENTE)
- `GET /api/gerente/resumen?mes=YYYY-MM&sucursal=` y `GET /api/gerente/viajes-flota?anio=&mes=&sucursal=&vehiculo=&transportista=&tipoEnvio=` (nombres a confirmar; convención `/api` plural pide decidir `/api/reportes/...` vs `/api/resumen`; el módulo `Reportes` ya está listado en CLAUDE.md → usar `Modules/Reportes`).
- Back: `Reportes/rutas.php`, `ResumenController`/`ViajesFlotaController`, `ResumenService`, `FlotaService` (cálculos, validación de filtros, comparación mes anterior), `ResumenRepository`, `FlotaRepository` + interfaces `Repositorio*`. Documentar en `docs/api/reportes-resumen-flota.md` antes del código.
- Front: `api/reportes.js`; `modules/gerente/Resumen.jsx`, `ViajesFlota.jsx`; componentes nuevos genéricos: `TarjetaIndicador.jsx`, `FiltrosPanel` (selects), tabla simple. Estados carga/error/vacío en cada pantalla.

## 6. Orden de desarrollo (módulo 11)
1. Rama nueva desde `main`; doc de API. 2. Interfaces + repositorios (SQL parametrizado). 3. Services con cálculos puros (testeables). 4. Controllers + rutas + `RequiereRol`. 5. `tests/reportes.php` incluido desde `ejecutar.php`. 6. `api/reportes.js` + componentes. 7. `Resumen.jsx`, luego `ViajesFlota.jsx`; registrar en `routes/index.jsx` y quitar el "pendiente". 8. Actualizar `CLAUDE.md` y `orden-implementacion.md`. 9. Validación real aislada (con autorización).

## 7. Pruebas y riesgos
- Sin SQL Server (`C:\php\php.exe backend/tests/ejecutar.php`): Services con repositorios falsos: variaciones % (mes anterior = 0), uso de capacidad, % transbordo, filtros inválidos (422), mes vacío (ceros, sin división por cero), ruta sin sesión 401 / otro rol 403.
- Con SQL: consultas, rangos de fecha por mes, zona horaria — **solo base aislada y con autorización expresa**; no `JATSA_Encomiendas`.
- Riesgos: no confundir "formalizado" con "pagado"; "cobrados" debe excluir pagos ANULADO; programado ≠ en ruta; el mes/año de un viaje se define por `fechaHoraSalida` (a confirmar).

## 8. Pantallas 23 y 27 (preliminar, módulo 12)
Mismo cuerpo en ambas: filtros Desde/Hasta + Sucursal; 3 plantillas (General, Económica, Viajes y flota) con descarga **Excel .xlsx**, **Power BI .pbit**, o ambos en ZIP; casilla "Incluir en el paquete completo"; botones "Excel completo (.xlsx)" y "Descargar paquete ZIP" (`datos_jatsa.xlsx` + 3 `.pbit` + `Manual_de_uso.pdf`). Hojas: General = Envios·Bultos·Pagos·Reservas·Rutas·Calendario; Económica = Pagos·Envios·Sucursales·Calendario; Viajes y flota = Viajes·Envio_Viaje·Bultos·Rutas·Calendario.
Diferencia: ADMINISTRADOR (23) ve su sucursal fija ("solo envíos con origen o destino en tu agencia", menú Ventanilla/Caja/Despacho/Llegadas/Reportes); GERENTE (27) elige "Todas" o una sucursal y tiene "Ver vista previa →" en Viajes y flota (→ pantalla 28).

## 9. Módulo 12: dependencias y decisiones pendientes
- Sin dependencias nuevas: filtros, selección de plantillas, consultas de datos (reutilizan las del módulo 11), vista previa, y quizá CSV/JSON.
- Necesitan decisión: `.xlsx` (XML+ZIP manual con `ZipArchive` de PHP, si la extensión está habilitada, vs librería), ZIP (`ZipArchive` nativo posible), PDF del manual (PDF estático pregenerado vs generar), `.pbit` (formato propietario de Power BI: ¿se aporta el archivo ya hecho o se genera? **no verificado**).
- Preguntas: ¿se aprueba `ZipArchive`/extensión zip? ¿manual PDF fijo? ¿quién provee los `.pbit`? ¿Admin con rango de fechas libre? ¿permiso del ADMIN a "Viajes y flota"?

## 10. Primeras acciones tras el reinicio
1. Resolver las preguntas abiertas de abajo. 2. Crear rama `modulo-11-resumen-flota` desde `main`. 3. Redactar `docs/api/reportes-resumen-flota.md`. 4. Implementar siguiendo §6.

### Preguntas abiertas del módulo 11 (no verificado)
- "Tipo de envío" (filtro 28): ¿tipoEntrega AGENCIA/DOMICILIO u otro? · "Llevan más de 3 días": ¿desde registro o desde llegada a destino? · "Uso de capacidad": Σ peso carga / capacidadKg por viaje terminado, promediado. · "Transbordo": ¿envío con >1 `Envio_Viaje`? · Sucursal en Resumen/Flota: ¿origen, destino o cualquiera? · Cómo se llega a Viajes y flota (¿botón "Reportes" del Resumen, o pantalla Reportes del módulo 12 aún inexistente?). · "+18 %": vs. mes calendario anterior.

## Verificación de este documento
Rutas y símbolos citados fueron comprobados en esta sesión (definicion.js, index.jsx, rutas.php de Usuarios/Viajes, ViajeRepository, esquema SQL). Sin verificar: formato exacto de `estadoPago` (solo vi 'ANULADO'), `tipoEntrega`, contenido de imágenes de las diapositivas, disponibilidad de `ZipArchive`.
