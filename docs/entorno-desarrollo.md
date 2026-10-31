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
node frontend/tests/e2e/publico.mjs
```
Abre Edge sin ventana (sin dependencias nuevas) y **intercepta las llamadas a `/api`** con respuestas fijas, así que no necesita PHP ni base de datos. Cubre las pantallas públicas, incluida la carrera de respuestas lentas del Cotizador. Salen con código 1 si algo falla. Variables opcionales: `FRONTEND_URL` y `EDGE_PATH`.

Respuesta correcta: `{ "data": ... }`. Error: `{ "error": { "codigo": "...", "mensaje": "..." } }`. Los detalles técnicos de un error se guardan en `backend/storage/logs/`.

## Cómo añadir rutas de un módulo
Crear `backend/src/Modules/<Modulo>/rutas.php` que devuelva `function (Jatsa\Core\Router $router): void { ... }`. Se carga automáticamente al arrancar la API.
