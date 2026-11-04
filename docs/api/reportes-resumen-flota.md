# API — Resumen y Viajes y flota (módulo 11)

Solo el **GERENTE** (401 sin sesión, 403 para otros roles). Solo lectura (`GET`). Respuesta `{ "data": ... }`; filtros inválidos → 422 con `campos`.
Pantallas 26 (Resumen) y 28 (Viajes y flota) del prototipo. Código en `backend/src/Modules/Reportes/`.

## GET /api/reportes/resumen?mes=AAAA-MM&sucursal=CÓDIGO
`mes` por defecto el actual (años 2000 a 9998: más allá, 422, para no salir del rango `datetime2`); `sucursal` vacío = todas.

```json
{ "mes": "2026-10", "sucursal": null,
  "enviosRegistrados": { "total": 167, "variacionPct": 18 },
  "cobrado": { "total": "5511.00", "variacionPct": 18 },
  "porEntregar": { "total": 46, "masDeTresDias": 12 },
  "viajes": { "enRuta": 2, "programados": 6 },
  "dondeEstan": { "ENTREGADO": 112, "EN_TRANSITO": 21, "EN_DESTINO": 25, "REGISTRADO": 9 } }
```
Reglas:
- **Envíos registrados**: envíos con `Envio.fechaHoraRegistro` en el mes que están REGISTRADO, EN_TRANSITO, EN_DESTINO o ENTREGADO (los cuatro grupos de `dondeEstan`; no se cuentan ANULADO ni DEVUELTO).
- **Por entregar**: EN_TRANSITO + EN_DESTINO del mes. **Más de 3 días**: de esos, `fechaHoraRegistro` anterior a «ahora − 3 días».
- **Cobrados**: suma de `Pago.monto` con `estadoPago = 'REGISTRADO'` (excluye ANULADO) y `fechaHoraPago` en el mes; la sucursal es **`Pago.idSucursal`** (donde se registró el pago).
- **Sucursal en envíos y viajes**: la sucursal es origen **o** destino (`Reserva` / `Viaje`), sin duplicar filas.
- **Variación**: contra el mes calendario anterior, entero; `null` si el mes anterior fue 0.
- **Viajes**: en ruta = con salida y sin llegada; programados = sin salida (estado actual, no depende del mes).

## GET /api/reportes/viajes-flota?anio=&mes=&sucursal=&vehiculo=&transportista=&tipoEnvio=
`anio` por defecto el actual (2000 a 9998, si no 422); `mes` 1–12 (vacío = todo el año); `vehiculo` = placa; `transportista` = `nombreUsuario`; `tipoEnvio` = `SOBRE`|`PAQUETE`|`CARGA`.

```json
{ "anio": 2026, "mes": 10, "filtros": { "sucursal": null, "placa": null, "transportista": null, "tipoEnvio": null },
  "indicadores": { "viajesTerminados": 142, "enRutaAhora": 2, "programados": 6, "pesoKg": "18640.00",
                   "usoMedioCapacidadPct": 37, "enviosConTransbordoPct": 9 },
  "viajesEnRuta": [ { "codigo": "VJ2026101402", "origen": {"codigo":"MOQ001","nombre":"…"}, "destino": {…}, "placa": "B7K-210",
                      "transportista": "…", "salida": "2026-10-14 15:00:00", "horas": 4, "llegadaAprox": "2026-10-14 19:00:00", "diasDespues": 0 } ],
  "transportistas": [ { "transportista": "…", "sucursalCodigo": "AQP001", "viajes": 22, "pesoKg": "4980.00" } ] }
```
Reglas:
- **Periodo** sobre `fechaHoraSalida` de los viajes **terminados** (con llegada). «En ruta ahora» y «Programados» son el estado actual, afectados solo por los filtros no temporales.
- **Tipo de envío**: `Ruta.tipoEnvio` vía `Bulto.idRuta`. Se filtran los viajes que llevan al menos un bulto de ese tipo (`EXISTS`, sin duplicar viajes). No es `AGENCIA`/`DOMICILIO`. El peso de un viaje es el de **todos** sus envíos cargados.
- **Sucursal / vehículo / transportista**: origen o destino del viaje; `Vehiculo.placa`; `Usuario.nombreUsuario`.
- **Peso transportado**: Σ `Bulto.pesoKg` de los envíos cargados (`Envio_Viaje`) en los viajes terminados del periodo.
- **Uso medio de capacidad**: promedio por viaje de peso cargado / `Vehiculo.capacidadKg` (supuesto del documento de preparación; `null` sin viajes).
- **Envíos con transbordo**: envíos distintos cargados en los viajes del periodo que figuran en más de un `Envio_Viaje`, sobre el total de esos envíos (`null` si no hay).
- **Llega aprox.**: `salida` + `Ruta.tiempoHoras` de la ruta vigente directa; `diasDespues` = días de diferencia (el «+1» del prototipo); `null` si no hay ruta vigente.
- Un código de sucursal/placa/usuario con formato válido pero inexistente devuelve ceros y listas vacías (no 404).
