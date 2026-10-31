# API — Pantallas públicas (módulo 2)

Endpoints sin sesión. Solo leen la base de datos (`SELECT`). Formato de respuesta: éxito `{ "data": ... }`; error `{ "error": { "codigo", "mensaje" } }`.

## GET `/api/agencias`
Sucursales **activas** (`Sucursal.activa = 1`) con sus teléfonos (`Telefono_Sucursal`), ordenadas por nombre.

```json
{ "data": { "agencias": [
  { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro", "departamento": "Arequipa",
    "provincia": "Arequipa", "distrito": "Cercado", "direccion": "Av. Ejército 512",
    "telefonos": ["054201450", "054201451"] }
] } }
```
El filtro por departamento se hace en el frontend (son pocas agencias). No expone ids internos: el identificador público es `codigo`.

## GET `/api/cotizacion?origen=MOQ001&destino=AQP001&peso=8.80`
Calcula el tipo de envío, el precio por bulto y las tarifas de la ruta (dirección origen → destino).

| Parámetro | Regla |
|---|---|
| `origen`, `destino` | Código de sucursal activa (3 letras + 3 dígitos, sin distinguir mayúsculas). Deben ser distintos |
| `peso` | Kilogramos, hasta 2 decimales, mayor que 0. Se acepta coma decimal (`8,80`) |

**Reglas** (informe de BD, sección 8.5, y diapositiva 7 del prototipo):
- El peso decide el tipo: `SOBRE` hasta 1 kg, `PAQUETE` hasta 30 kg, `CARGA` hasta 300 kg.
- Costo del bulto = `precioBase + precioPorKm × distanciaKm`, calculado con enteros (céntimos) y redondeo medio hacia arriba, como `DECIMAL`. Implementado en `Jatsa\Shared\Tarifas`, que reutilizarán Ventanilla y los envíos.
- Solo cuentan las tarifas **vigentes hoy**: `vigenteDesde <= hoy` y (`vigenteHasta` es NULL o `>= hoy`). Si hubiera dos vigentes del mismo tipo, se usa la de `vigenteDesde` más reciente.

Respuesta `200`: los importes son **cadenas** con sus decimales.
```json
{ "data": {
  "origen": { "codigo": "MOQ001", "nombre": "Agencia Moquegua" },
  "destino": { "codigo": "AQP001", "nombre": "Agencia Arequipa Centro" },
  "pesoKg": "8.80", "tipo": "PAQUETE",
  "cotizacion": { "tipo": "PAQUETE", "pesoMaximoKg": 30, "disponible": true, "precioPorBulto": "14.60",
                  "tiempoHoras": 4, "precioBase": "10.00", "precioPorKm": "0.020", "distanciaKm": "230" },
  "tarifas": [ { "tipo": "SOBRE", "...": "..." }, { "tipo": "PAQUETE", "...": "..." },
               { "tipo": "CARGA", "pesoMaximoKg": 300, "disponible": false, "precioPorBulto": null, "tiempoHoras": null } ]
} }
```
`disponible: false` significa que no hay una ruta vigente de ese tipo entre las dos sucursales (la ruta es direccional).

| Estado | Código | Cuándo |
|---|---|---|
| 422 | `datos_invalidos` | Origen o destino faltante o con formato incorrecto, origen = destino, peso inválido o ≤ 0 |
| 422 | `peso_excedido` | Peso mayor que 300 kg |
| 404 | `sucursal_no_encontrada` | Una de las sucursales no existe o está dada de baja |

## Pendiente de otros módulos
- **Salidas desde aquí** (ficha de agencia, diapositiva 6) y **Salidas** / **Rastreo**: dependen de viajes y envíos reales; se implementan en el módulo 9. Hoy los botones «Ver salidas» y el buscador de rastreo del inicio llevan a las rutas `/salidas` y `/rastreo?codigo=…`, que siguen siendo marcadores.
- **Reservar ahora** lleva a `/cliente/nueva-reserva` (módulo 4); sin sesión, la protección de rutas pide ingresar.
