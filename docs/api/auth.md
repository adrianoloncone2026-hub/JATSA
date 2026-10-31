# API — Autenticación (módulo 1)

Sesión con cookie `JATSA_SESION` (HttpOnly, SameSite=Lax; `Secure` si `SESSION_SECURE=true`). El frontend envía las peticiones con `credentials: 'include'`.

**Protección CSRF:** toda petición distinta de GET/HEAD/OPTIONS debe llevar la cabecera `X-Requested-With: JATSA`; sin ella la API responde `403 solicitud_no_permitida`. `frontend/src/api/cliente.js` la añade siempre.

Formato de respuesta: éxito `{ "data": ... }`; error `{ "error": { "codigo": "...", "mensaje": "..." } }`.

## POST `/api/auth/ingresar`
Cuerpo JSON: `{ "usuario": "mrojas", "contrasena": "...", "recordarme": false }`

| Estado | Código | Cuándo |
|---|---|---|
| 200 | — | Credenciales correctas. Abre sesión y devuelve `{ "data": { "usuario": {...} } }` |
| 400 | `json_invalido` | El cuerpo no es JSON |
| 422 | `datos_invalidos` | Usuario o contraseña vacíos (o con tipo incorrecto) |
| 401 | `credenciales_invalidas` | «Usuario o contraseña incorrectos.» No distingue entre usuario inexistente y contraseña errónea |
| 403 | `cuenta_desactivada` | Contraseña correcta pero `Usuario.activo = 0` |

Objeto `usuario` (nunca incluye el hash): `id`, `nombreUsuario`, `nombreCompleto`, `rol`, `sucursal` (`null` o `{ id, codigo, nombre }`).

Duración de la sesión: sin «Recordarme», cookie de sesión del navegador y cierre tras 8 h de inactividad; con «Recordarme», cookie de 14 días e inactividad de 14 días (`SESSION_HORAS`, `SESSION_DIAS_RECORDAR`).

## POST `/api/auth/salir`
Cierra la sesión y borra la cookie. Siempre `200 { "data": { "sesionCerrada": true } }`, haya o no sesión.

## GET `/api/auth/yo`
Requiere sesión. `200 { "data": { "usuario": {...} } }`.

| Estado | Código | Cuándo |
|---|---|---|
| 401 | `no_autenticado` | No hay sesión o caducó |
| 401 | `sesion_invalida` | La cuenta ya no existe o fue desactivada; se cierra la sesión |

## Cerrar sesión en la interfaz
La interfaz solo muestra la sesión como cerrada cuando `POST /api/auth/salir` responde correctamente. Si la petición falla (sin conexión, error del servidor), se conserva el usuario, se muestra un aviso y se puede reintentar; `salir` es idempotente. **Limitación conocida:** si el usuario cierra el navegador sin haber podido confirmar el cierre, la sesión del servidor sigue válida hasta caducar por inactividad (8 h, o 14 días con «Recordarme»).

## Limitaciones conocidas y pendientes de seguridad
- **Sin protección contra fuerza bruta.** No hay límite de intentos fallidos ni bloqueo temporal: **no está implementado**. Es aceptable en desarrollo local, pero es un **requisito previo a exponer la aplicación a Internet**. Requiere definir la regla (intentos, ventana, bloqueo) y dónde guardar el contador, sin alterar el esquema de la base de datos.
- `password_needs_rehash` no se aplica (exigiría un UPDATE sobre `Usuario`).
- El inicio de sesión exitoso contra una cuenta real de `JATSA_Encomiendas` está pendiente de validar: los hashes de muestra del script no tienen contraseña conocida.

## Proteger rutas de otros módulos
```php
use Jatsa\Middleware\RequiereRol;
use Jatsa\Middleware\RequiereSesion;
use Jatsa\Shared\Roles;

$router->get('/api/algo', [Controlador::class, 'metodo'], [new RequiereSesion()]);
$router->post('/api/caja/pagos', [Pagos::class, 'registrar'], [new RequiereRol([Roles::ADMINISTRADOR])]);
```
`RequiereSesion` comprueba en la base de datos que la cuenta siga activa en cada petición. `RequiereRol` responde `403 acceso_denegado` si el rol no está permitido. El usuario de la sesión se obtiene con `Sesion::usuario()`.
