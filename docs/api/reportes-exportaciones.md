# Reportes y plantillas — exportaciones Excel (módulo 12)

Dos descargas `.xlsx` (Office Open XML generado en PHP puro con zlib; sin dependencias). Cada hoja es una tabla de Excel con filtros y fila de encabezado fija. Solo lectura (`GET`).

| Endpoint | Archivo | Roles |
|---|---|---|
| `GET /api/reportes/exportar/gerencia?sucursal=` | `JATSA_Gerencia.xlsx` | GERENTE, ADMINISTRADOR |
| `GET /api/reportes/exportar/logistica?sucursal=` | `JATSA_Logistica.xlsx` | GERENTE, ADMINISTRADOR |

Respuesta: el archivo (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment`, `Cache-Control: no-store`); el nombre lo fija el servidor. Errores en JSON estándar: 401 sin sesión, 403 otro rol (o Administrador sin sucursal), 422 si `sucursal` no es válida o no existe.

**Ámbito.** ADMINISTRADOR: siempre su sucursal de sesión (se ignora `sucursal`). GERENTE: todo el historial, o una sucursal con `sucursal=CÓDIGO`. «De una sucursal» = envío o viaje con esa sucursal como origen o destino; los pagos son los de esos envíos. En `EnviosCarga` con sucursal se añaden los envíos que viajan en viajes de esa sucursal, para que `EnvioViaje` siempre encuentre su envío. Sin paginación.

**Reglas.** `importeEnvio` = Σ `Bulto.costo`; `cobradoRegistrado` = Σ `Pago.monto` con `estadoPago <> 'ANULADO'`; `saldoActual` = importe − cobrado. Bultos y pagos se agregan en subconsultas aparte (sin duplicar por JOIN). Los pagos anulados se conservan en `Pagos`. `estadoCalculado`: sin salida = PROGRAMADO; con salida y sin llegada = EN_RUTA; con llegada = TERMINADO. `transportista` = `Usuario.nombreUsuario` (sin datos personales). Fechas desconocidas quedan vacías.

**Tipos.** Números como números; `fechaHora*` = fecha y hora (`yyyy-mm-dd hh:mm:ss`); `Fecha*` = fecha (`yyyy-mm-dd`); `activa` = booleano. Los textos que empiezan por `=`, `+`, `-` o `@` se guardan con un apóstrofo inicial.

## JATSA_Gerencia.xlsx
- **EnviosResumen**: idEnvio, codigoSeguimiento, fechaHoraRegistro, FechaRegistro, idSucursalOrigen, idSucursalDestino, estadoEnvio, cantidadBultos, importeEnvio, cobradoRegistrado, saldoActual
- **Pagos**: idPago, idEnvio, fechaHoraPago, FechaPago, idSucursalCobro, monto, metodoPago, pagadoPor, estadoPago
- **Sucursales**: idSucursal, codigoSucursal, nombre, departamento, activa

## JATSA_Logistica.xlsx
- **Viajes**: idViaje, codigoViaje, idSucursalOrigen, idSucursalDestino, fechaHoraSalida, FechaSalida, fechaHoraLlegada, FechaLlegada, estadoCalculado, placa, transportista, capacidadKg
- **EnvioViaje**: idEnvioViaje, idEnvio, idViaje, fechaHoraCarga, FechaCarga
- **EnviosCarga**: idEnvio, codigoSeguimiento, fechaHoraRegistro, FechaRegistro, pesoTotalKg, estadoEnvio
- **Sucursales**: igual que en Gerencia

## Paquetes Power BI (ZIP)

| Endpoint | Archivo | Roles |
|---|---|---|
| `GET /api/reportes/exportar/gerencia-powerbi?sucursal=` | `JATSA_Gerencia.zip` | GERENTE, ADMINISTRADOR |
| `GET /api/reportes/exportar/logistica-powerbi?sucursal=` | `JATSA_Logistica.zip` | GERENTE, ADMINISTRADOR |

Mismo ámbito, filtros y errores que los Excel (`application/zip`, `attachment`, `no-store`). Error 500 `plantilla_no_disponible` si falta un archivo en el servidor.

Cada ZIP contiene **exactamente tres archivos en la raíz** (sin carpetas): `JATSA_<Reporte>.pbix`, `JATSA_<Reporte>.xlsx` y `Manual_<Reporte>.pdf` (`Gerencia` o `Logistica`). El `.xlsx` se genera en el momento con los mismos exportadores de arriba; el `.pbix` y el `.pdf` son archivos fijos en `backend/storage/plantillas/` (fuera de `public/`, solo accesibles por estos endpoints). El ZIP se arma con `EscritorZip` (zlib nativa, sin dependencias). Los `.pbix` se entregan sin modificar.

**Conexión de datos.** Según los proyectos `.pbip` de origen, los reportes leen `RutaCarpeta & "JATSA_<Reporte>.xlsx"` (parámetro `RutaCarpeta`, ruta terminada en `\`); el manual explica cómo cambiarlo. No se pudo comprobar dentro de los `.pbix` (modelo comprimido) ni abrirlos: requiere Power BI Desktop.

## Pendientes (ampliaciones futuras)
Exportación de Encomiendas. La validación contra SQL Server real requiere autorización separada.