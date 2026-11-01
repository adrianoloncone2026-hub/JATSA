# Entorno de desarrollo

## Requisitos
- Node.js y npm (probado con Node 24 y npm 11).
- PHP 8.4 con las extensiones `pdo_sqlsrv` y `sqlsrv`, instalado en `C:\php\php.exe`. No está en el PATH, por eso las tareas de VS Code usan la ruta completa.
- SQL Server local (solo cuando se conecte la base de datos).

## Iniciar desde VS Code
1. Abrir la carpeta `C:\Jatsa` como espacio de trabajo.
2. `Ctrl+Shift+B` ejecuta **Iniciar JATSA (backend + frontend)**. También: `Ctrl+Shift+P` → *Tasks: Run Task* y elegir una de las tres tareas.
3. Abrir <http://localhost:5173>. La prueba de comunicación está en <http://localhost:5173/prueba-conexion>.

## Iniciar desde una terminal
```powershell
# Terminal 1 — backend (desde C:\Jatsa)
C:\php\php.exe -S localhost:8000 -t backend/public

# Terminal 2 — frontend (desde C:\Jatsa\frontend)
npm install     # solo la primera vez
npm run dev
```
Para detener un servidor: `Ctrl+C` en su terminal.

## Cómo se comunican
El navegador llama a `http://localhost:5173/api/...`; Vite reenvía `/api` a `http://localhost:8000` (ver `frontend/vite.config.js`). El backend también acepta CORS desde `http://localhost:5173` por si se llama directamente.

## Configuración
- **Backend:** copiar `backend/.env.example` a `backend/.env` y completar. `DB_NAME` es obligatorio para conectar; con `DB_USER` y `DB_PASSWORD` vacíos se usa autenticación de Windows. `backend/.env` no se versiona.
- **Frontend:** opcional; `frontend/.env.example` → `frontend/.env` para cambiar `VITE_API_URL` (por defecto `/api`).

## Endpoints actuales
| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/salud` | Estado de la API. No usa la base de datos. |
| GET | `/api/salud/bd` | Abre la conexión a SQL Server (sin ejecutar SQL). Responde 503 si no está configurada. |
| GET | `/api/agencias` | Agencias activas con teléfonos. Ver `docs/api/publico.md`. |
| GET | `/api/cotizacion` | Cotizador público (origen, destino, peso). |
| GET | `/api/tarifas` | Tarifas vigentes de una ruta, sin peso (precio referencial). |
| GET · POST | `/api/reservas` | Reservas del cliente: listar (`?estado=`) y crear. Ver `docs/api/reservas.md`. |
| GET | `/api/reservas/{codigo}` | Una reserva propia. |
| POST | `/api/reservas/{codigo}/cancelar` | Cancela una reserva pendiente. |
| GET | `/api/reservas/destinatario` | Autocompleta el nombre del destinatario por documento. |
| GET · POST | `/api/ventanilla/reservas` | ADMINISTRADOR: reservas pendientes de su agencia y «Cliente sin reserva» (canal VENTANILLA). Ver `docs/api/envios.md`. |
| GET | `/api/ventanilla/reservas/{codigo}` · `/api/ventanilla/personas` | ADMINISTRADOR: una reserva de su agencia y autocompletado de persona por documento. |
| POST | `/api/envios/cotizacion` | ADMINISTRADOR: tipo y costo de cada bulto, sin escribir. |
| POST | `/api/envios` | ADMINISTRADOR: formaliza la reserva (envío + bultos) en una transacción. |
| GET | `/api/envios/{codigo}` | ADMINISTRADOR: un envío (etiqueta) de su sucursal de origen o destino. |
| GET · POST | `/api/caja/pagos` | ADMINISTRADOR: pagos cobrados en su caja con totales por método, y registrar un pago. Ver `docs/api/pagos.md`. |
| POST | `/api/caja/pagos/{id}/anular` | ADMINISTRADOR: anula un pago cobrado en su caja (no se borra). |
| GET | `/api/caja/envios/{codigo}` | ADMINISTRADOR: el envío a cobrar con importe, pagado y saldo. |
| GET | `/api/pagos` | CLIENTE / EMPRESARIO: «Mis pagos» y total pagado. |
| POST | `/api/cuenta/registro` | Crea una cuenta CLIENTE (DNI) o EMPRESARIO (RUC). Ver `docs/api/cuenta.md`. |
| GET · PATCH | `/api/cuenta` | Mi cuenta: ver y editar teléfonos y correo (cliente y empresario). |
| POST | `/api/cuenta/contrasena` | Cambia la contraseña y cierra las otras sesiones. |
| POST | `/api/auth/ingresar` | Inicia sesión. Ver `docs/api/auth.md`. |
| POST | `/api/auth/salir` | Cierra la sesión. |
| GET | `/api/auth/yo` | Usuario de la sesión actual. |

## Pruebas del backend
```powershell
C:\php\php.exe backend/tests/ejecutar.php
```
Sin dependencias y sin acceso a la base de datos (usan un repositorio simulado). Salen con código 1 si algo falla.

## Pruebas del frontend en el navegador
```powershell
# Terminal 1, en frontend/:  npm run dev
# Terminal 2, desde C:\Jatsa:
node frontend/tests/e2e/publico.mjs   # Inicio, Agencias y Cotizador (módulo 2)
node frontend/tests/e2e/cuenta.mjs    # Crear cuenta y Mi cuenta (módulo 3)
node frontend/tests/e2e/reservas.mjs  # Nueva reserva, Reserva registrada, Mis reservas y Mi panel (módulo 4)
node frontend/tests/e2e/ventanilla.mjs # Ventanilla, Cliente sin reserva, Formalizar envío y etiqueta (módulo 5)
node frontend/tests/e2e/caja.mjs       # Caja y Mis pagos (módulo 6)
# Con CAPTURAS=<carpeta> guarda capturas (escritorio y móvil) para revisar el diseño contra el prototipo.
```
Abren Edge sin ventana (sin dependencias nuevas) y **interceptan las llamadas a `/api`** con respuestas fijas (arnés común en `frontend/tests/e2e/arnes.mjs`), así que no necesitan PHP ni base de datos. Cubren las pantallas, los errores del servidor y la carrera de respuestas lentas del Cotizador. Salen con código 1 si algo falla. Variables opcionales: `FRONTEND_URL` y `EDGE_PATH`.

Las pruebas del backend bloquean a propósito el acceso a la base de datos (`Config::fijar('db.nombre', '')`): si una prueba intentara conectarse, fallaría con 503 en lugar de ejecutar SQL.

Respuesta correcta: `{ "data": ... }`. Error: `{ "error": { "codigo": "...", "mensaje": "..." } }`. Los detalles técnicos de un error se guardan en `backend/storage/logs/`.

## Cómo añadir rutas de un módulo
Crear `backend/src/Modules/<Modulo>/rutas.php` que devuelva `function (Jatsa\Core\Router $router): void { ... }`. Se carga automáticamente al arrancar la API.
