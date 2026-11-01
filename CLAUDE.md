# JATSA — Sistema de encomiendas

Sistema web para una red de agencias de encomiendas: reservas, registro de envíos, pagos, traslados entre sucursales, entregas y reportes.

## Documentos de referencia

Los tres documentos base están en `C:\Jatsa\docs`. Usar siempre estos nombres exactos (con espacios y paréntesis):

| Documento | Ruta | Para qué sirve |
|---|---|---|
| Prototipo de navegación | `docs/Prototipo_navegacion_JATSA (1).pptx` | 30 diapositivas con notas: mapa de navegación y pantallas de cada rol. **Manda sobre pantallas y navegación.** |
| Informe de base de datos | `docs/Informe_BD_Encomiendas (3) (2).docx` | Diseño de la base de datos. |
| Script SQL definitivo | `docs/JATSA_Envios-SQLQuery1 (1).sql` | Estructura real de la base de datos. De aquí se toman los nombres de tablas y columnas. |

Estos tres archivos no se mueven, renombran ni modifican sin que el usuario lo pida.

## Tecnologías (cerradas)

| Capa | Tecnología |
|---|---|
| Frontend | React + Vite (JavaScript) |
| Backend | PHP puro (sin Laravel, Symfony ni otro framework) |
| Base de datos | Microsoft SQL Server |
| Comunicación | API REST, HTTP + JSON |

**No se añade ninguna tecnología, librería o herramienta nueva sin autorización expresa del usuario.** Esto incluye paquetes npm, paquetes Composer, ORMs, frameworks CSS y herramientas de build. Si algo parece necesario, se pregunta antes.

Dependencias del frontend autorizadas hasta ahora: `react`, `react-dom`, `react-router-dom` (enrutado por rol) y, de desarrollo, `vite` y `@vitejs/plugin-react`. El backend no tiene dependencias. Cómo arrancar el entorno: `docs/entorno-desarrollo.md`.

**Pendiente de autorización:** el prototipo exige exportar Excel (`.xlsx`), paquetes ZIP y un manual PDF (pantalla *Reportes y plantillas*). Hasta que el usuario decida con qué tecnología, ese módulo no se implementa ni se instala nada para él.

## Estructura y responsabilidades

```
C:\Jatsa
├── CLAUDE.md
├── frontend/   Aplicación React + Vite
├── backend/    API REST en PHP puro
├── database/   Reservada (vacía por ahora)
└── docs/       Documentos de referencia y documentación propia
```

- `frontend/src/api/` — cliente HTTP único (fetch) y una función por endpoint. Ningún componente llama a `fetch` directamente.
- `frontend/src/auth/` — sesión, rol actual y protección de rutas.
- `frontend/src/layouts/` — marcos de página por rol (público, cliente, personal).
- `frontend/src/modules/<rol>/` — pantallas de cada rol: `publico`, `cuenta`, `cliente`, `administrador`, `transportista`, `gerente`.
- `frontend/src/components/` — componentes reutilizables sin lógica de negocio.
- `frontend/src/routes/` — definición de rutas y su rol permitido.
- `backend/public/` — único directorio expuesto; contiene el front controller (`index.php`).
- `backend/config/` — configuración (BD, CORS, sesión). Los secretos van en un archivo local fuera del control de versiones.
- `backend/src/Core/` — router, request/response JSON, conexión a la base de datos, manejo de errores.
- `backend/src/Middleware/` — autenticación, autorización por rol, CORS.
- `backend/src/Shared/` — validación, utilidades y excepciones comunes.
- `backend/src/Modules/<Modulo>/` — un módulo por área funcional, cada uno con su `Controller`, `Service` y `Repository` (y rutas propias).
- `backend/bin/` — comandos de consola (PHP CLI), p. ej. `vencer-reservas.php`. Escriben solo con una opción explícita (`--ejecutar`) y no se programan sin autorización.
- `backend/storage/logs/` — registros de la aplicación.
- `backend/tests/` — pruebas del backend (`C:\php\php.exe backend/tests/ejecutar.php`, sin dependencias ni base de datos; `envios.php` es la parte del módulo 5 y `pagos.php` la del módulo 6 y `viajes.php` la del módulo 7 y `entregas.php` la del módulo 8, incluidas desde `ejecutar.php`).
- `backend/storage/sessions/` — archivos de sesión (no se versionan).
- `database/script/` y `database/notas/` — reservadas y vacías. El script SQL **no** está aquí: está en `docs/` (ver Documentos de referencia).
- `docs/prototipo/`, `docs/informe-bd/` — reservadas y vacías. `docs/api/` — contrato de la API (se llena módulo a módulo). `docs/orden-implementacion.md` — orden de módulos.

Módulos del backend:
- **Auth:** ingreso, cierre de sesión y roles.
- **Publico:** rastreo, salidas entre sucursales, agencias y cotizador.
- **Cuenta:** registro y datos de la propia cuenta.
- **Reservas** (incluye la ventanilla del Administrador), **Envios** (incluye formalizar envío y bultos), **Pagos**, **Despacho**, **Viajes**, **Entregas**.
- **Catalogos:** sucursales, rutas con precios y vigencia, vehículos.
- **Usuarios:** cuentas de usuario (gestión del Gerente).
- **Reportes:** resumen, vista de viajes y flota, reportes y plantillas.

## Roles

Visitante (sin sesión), CLIENTE, EMPRESARIO, ADMINISTRADOR (con sucursal obligatoria), TRANSPORTISTA (con sucursal obligatoria) y GERENTE. CLIENTE y EMPRESARIO comparten pantallas. Pantallas por rol según el prototipo:

- **Público:** Inicio, Rastreo, Salidas entre sucursales, Detalle de salida, Agencias, Cotizador, Ingresar, Crear cuenta.
- **Cliente / Empresario:** Mi panel, Nueva reserva, Reserva registrada, Historial de envíos, Mis reservas, Mis pagos, Mi cuenta.
- **Administrador:** Ventanilla, Formalizar envío, Caja, Despacho, Llegadas y entregas, Reportes y plantillas.
- **Transportista:** Mis viajes, Detalle del viaje.
- **Gerente:** Resumen, Reportes y plantillas, Viajes y flota, Catálogos, Cuentas.

La autorización se aplica **siempre en el backend**; ocultar una opción en el frontend nunca sustituye esa comprobación. El prototipo incluye usuarios de prueba y datos de ejemplo: no se copian al repositorio como datos reales ni credenciales.

## Convenciones

**General**
- Código (variables, funciones, clases, archivos) y mensajes de commit en español, coherentes con el dominio (reserva, envío, bulto, sucursal). Textos de interfaz en español.
- Los nombres de tablas y columnas se toman tal cual del script SQL. No se renombran ni se traducen.

**Backend (PHP)**
- PHP puro, `declare(strict_types=1);`, PSR-4 manual (autoloader propio, sin Composer salvo autorización), un archivo por clase.
- Clases en PascalCase; métodos y variables en camelCase.
- Flujo: `Controller` (HTTP) → `Service` (reglas de negocio) → `Repository` (acceso a datos). Los controladores no contienen SQL; los repositorios no contienen reglas de negocio.
- Acceso a SQL Server mediante PDO (driver `sqlsrv`) con **consultas parametrizadas siempre**. Nunca concatenar entrada del usuario en SQL.
- Contraseñas con `password_hash` / `password_verify`.
- Las operaciones que tocan varias tablas van dentro de una transacción.

**API REST**
- Prefijo `/api`, recursos en plural y minúsculas (`/api/reservas`, `/api/envios/{id}`).
- Siempre JSON, UTF-8. Códigos HTTP correctos (200, 201, 204, 400, 401, 403, 404, 409, 422, 500).
- Respuesta correcta: `{ "data": ... }`. Error: `{ "error": { "codigo": "...", "mensaje": "..." } }`; los errores de validación de formularios añaden `"campos": { "campo": "mensaje" }`.
- Nunca exponer trazas, SQL ni datos internos en un error.
- Cada endpoint se documenta en `docs/api/` antes o junto con su implementación.

**Frontend (React)**
- Componentes funcionales con hooks, un componente por archivo, nombres en PascalCase.
- Las pantallas viven en su módulo de rol; lo compartido va en `components/`.
- Todo acceso al servidor pasa por `src/api/`. La URL base sale de variables de entorno de Vite.
- Manejar siempre los estados de carga, error y vacío.

## Reglas de desarrollo

1. Implementar por módulos, en el orden definido en `docs/orden-implementacion.md`. Cada módulo se entrega completo (API + pantallas + verificación) antes de pasar al siguiente.
2. Antes de implementar un módulo, revisar sus diapositivas del prototipo (texto, notas e imágenes) y la parte correspondiente del informe de BD.
3. Cambios pequeños y enfocados; no reestructurar código ajeno a la tarea.
4. Validar toda entrada en el backend, aunque el frontend ya valide.
5. Sin datos sensibles en el repositorio: credenciales, `.env` y archivos de configuración local quedan fuera.
6. En rastreo público no se exponen documentos de identidad ni teléfonos.
7. Si algo es ambiguo o falta información, preguntar al usuario en lugar de suponer.
8. Antes de afirmar que un archivo o documento no existe, comprobarlo con dos métodos distintos (por ejemplo, búsqueda por patrón y listado de la carpeta).

## Restricciones obligatorias

- **El script SQL `docs/JATSA_Envios-SQLQuery1 (1).sql` es definitivo e inalterable.** No se modifica, reestructura, corrige, divide ni se le añaden migraciones. Si parece haber un problema, se informa al usuario y no se toca.
- No ejecutar instrucciones SQL ni crear o eliminar bases de datos sin que el usuario lo autorice expresamente en cada ocasión.
- No revisar ni comparar la coherencia del SQL con otros documentos mientras el usuario no lo pida.
- No usar Laravel ni ningún otro framework de PHP.
- No introducir tecnologías adicionales sin autorización (ver pendiente de exportación de reportes).
- No implementar funcionalidades fuera del módulo en curso.
